"""Completion webhooks: validate callback URLs, and deliver signed notifications from the worker.

A delivery is its own RQ job with its own retries, so a client whose endpoint is down never causes the
(expensive) transcription to run again.

The payload is deliberately minimal (`{event, id, status}`): the client fetches the result with its API
key. So even a leaked callback secret only lets an attacker trigger a fetch, never inject a transcript.
"""
import hashlib
import hmac
import ipaddress
import json
import logging
import socket
import time
import uuid
from datetime import datetime, timezone
from urllib.parse import urlsplit

import httpx

from app import queue
from app.config import Settings, get_settings
from app.db import SessionLocal
from app.models import JobStatus, TranscriptionJob

log = logging.getLogger(__name__)

SIGNATURE_HEADER = "X-Transcriber-Signature"
TIMESTAMP_HEADER = "X-Transcriber-Timestamp"
# 4xx responses mean the client rejected the request (e.g. bad signature); retrying won't change that.
RETRYABLE_4XX = {408, 425, 429}


class InvalidCallbackURL(ValueError):
    pass


def validate_callback_url(url: str, settings: Settings) -> None:
    """Reject URLs that could be used to make the server call internal services (SSRF)."""
    parts = urlsplit(url)
    if parts.scheme not in ("http", "https") or not parts.hostname:
        raise InvalidCallbackURL("callback_url must be an absolute http(s) URL")
    if settings.allow_private_callbacks:
        return
    try:
        infos = socket.getaddrinfo(parts.hostname, parts.port or 443, proto=socket.IPPROTO_TCP)
    except socket.gaierror:
        raise InvalidCallbackURL(f"callback_url host '{parts.hostname}' could not be resolved") from None
    for info in infos:
        ip = ipaddress.ip_address(info[4][0])
        if not ip.is_global:
            raise InvalidCallbackURL("callback_url must not point to a private, loopback or reserved address")


def sign(secret: str, timestamp: str, body: bytes) -> str:
    """Signature over "<timestamp>.<body>", so a captured request can't be replayed with a new timestamp."""
    digest = hmac.new(secret.encode(), timestamp.encode() + b"." + body, hashlib.sha256).hexdigest()
    return f"sha256={digest}"


def notify(job: TranscriptionJob) -> None:
    """Schedule a webhook delivery for a job that just reached a terminal state (if it asked for one)."""
    if not job.callback_url:
        return
    try:
        queue.enqueue_webhook(str(job.id))
    except Exception:
        # Not fatal: the job result is saved, and clients can still poll for it.
        log.exception("Could not enqueue webhook for job %s", job.id)


def _send(url: str, body: bytes, headers: dict[str, str], timeout: float) -> httpx.Response:
    # Redirects are not followed: a redirect to an internal address would bypass the SSRF check.
    return httpx.post(url, content=body, headers=headers, timeout=timeout, follow_redirects=False)


def deliver_webhook(job_id: str) -> None:
    """RQ task. Raises on retryable failures so RQ retries with backoff."""
    settings = get_settings()
    with SessionLocal() as db:
        job = db.get(TranscriptionJob, uuid.UUID(job_id))
        if job is None or not job.callback_url or job.status not in (JobStatus.completed, JobStatus.failed):
            return

        event = "transcription.completed" if job.status == JobStatus.completed else "transcription.failed"
        body = json.dumps({"event": event, "id": str(job.id), "status": job.status.value}).encode()
        timestamp = str(int(time.time()))
        headers = {
            "Content-Type": "application/json",
            "User-Agent": "audio-transcriber-webhook/1.0",
            TIMESTAMP_HEADER: timestamp,
            SIGNATURE_HEADER: sign(job.callback_secret or "", timestamp, body),
        }

        try:
            # Checked again at delivery time: DNS may now resolve differently than when the job was created.
            validate_callback_url(job.callback_url, settings)
            response = _send(job.callback_url, body, headers, settings.webhook_timeout_seconds)
        except InvalidCallbackURL as exc:
            job.webhook_error = str(exc)
            db.commit()
            return
        except httpx.HTTPError as exc:
            job.webhook_error = f"{type(exc).__name__}: {exc}"
            db.commit()
            raise

        if response.is_success:
            job.webhook_delivered_at = datetime.now(timezone.utc)
            job.webhook_error = None
            db.commit()
            log.info("Webhook for job %s delivered", job_id)
            return

        job.webhook_error = f"Callback returned HTTP {response.status_code}"
        db.commit()
        if 400 <= response.status_code < 500 and response.status_code not in RETRYABLE_4XX:
            log.warning("Webhook for job %s rejected with HTTP %d, not retrying", job_id, response.status_code)
            return
        raise RuntimeError(job.webhook_error)
