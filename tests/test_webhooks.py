import hashlib
import hmac
import json
import uuid
from types import SimpleNamespace

import httpx
import pytest

from app import queue, webhooks, worker
from app.config import get_settings
from app.models import JobStatus, TranscriptionJob
from app.transcriber import TranscriptionResult
from app.webhooks import InvalidCallbackURL, deliver_webhook, validate_callback_url

PUBLIC_URL = "https://93.184.216.34/hooks/transcriber"  # a public IP literal, so tests need no DNS
SECRET = "s" * 32


@pytest.fixture
def allow_private(monkeypatch):
    monkeypatch.setattr(get_settings(), "allow_private_callbacks", True)


@pytest.fixture
def enqueued_webhooks(monkeypatch):
    calls: list[str] = []
    monkeypatch.setattr(queue, "enqueue_webhook", calls.append)
    return calls


@pytest.fixture
def sent(monkeypatch):
    """Captures outgoing webhook requests; set `sent.response` to control the reply."""
    calls = []

    def fake_send(url, body, headers, timeout):
        calls.append(SimpleNamespace(url=url, body=body, headers=headers))
        if isinstance(fake_send.response, Exception):
            raise fake_send.response
        return fake_send.response

    fake_send.response = httpx.Response(200)
    fake_send.calls = calls
    monkeypatch.setattr(webhooks, "_send", fake_send)
    return fake_send


def make_job(db, status=JobStatus.completed, callback_url=PUBLIC_URL):
    job = TranscriptionJob(
        owner="owner", original_filename="a.ogg", model="tiny", status=status,
        callback_url=callback_url, callback_secret=SECRET,
    )
    db.add(job)
    db.commit()
    return job


def reload(db, job):
    db.expire_all()
    return db.get(TranscriptionJob, job.id)


# --- URL validation (SSRF protection) ---

@pytest.mark.parametrize(
    "url",
    [
        "ftp://93.184.216.34/x",
        "not a url",
        "http://localhost/hook",
        "http://127.0.0.1:8080/hook",
        "http://10.0.0.5/hook",
        "http://192.168.1.10/hook",
        "http://169.254.169.254/latest/meta-data/",  # cloud metadata endpoint
        "http://[::1]/hook",
    ],
)
def test_rejects_unsafe_callback_urls(url):
    with pytest.raises(InvalidCallbackURL):
        validate_callback_url(url, get_settings())


def test_accepts_public_callback_url():
    validate_callback_url(PUBLIC_URL, get_settings())


def test_private_callbacks_can_be_allowed_for_local_dev(allow_private):
    validate_callback_url("http://wordpress/wp-json/audio-transcriber/v1/webhook", get_settings())


# --- API ---

def upload(client, **form):
    return client.post("/transcriptions", files={"file": ("a.ogg", b"x", "audio/ogg")}, data=form)


def test_callback_is_stored_and_secret_never_returned(client, db):
    job_id = upload(client, callback_url=PUBLIC_URL, callback_secret=SECRET).json()["id"]

    job = db.get(TranscriptionJob, uuid.UUID(job_id))
    assert (job.callback_url, job.callback_secret) == (PUBLIC_URL, SECRET)
    body = client.get(f"/transcriptions/{job_id}").json()
    assert body["callback_url"] == PUBLIC_URL
    assert SECRET not in json.dumps(body)


def test_callback_requires_a_long_enough_secret(client, enqueued):
    assert upload(client, callback_url=PUBLIC_URL).status_code == 422
    assert upload(client, callback_url=PUBLIC_URL, callback_secret="short").status_code == 422
    assert enqueued == []


def test_private_callback_url_rejected_by_api(client, enqueued):
    resp = upload(client, callback_url="http://127.0.0.1/hook", callback_secret=SECRET)
    assert resp.status_code == 422
    assert "private" in resp.json()["detail"]
    assert enqueued == []


# --- Delivery ---

def test_successful_delivery_is_signed_and_recorded(db, sent):
    job = make_job(db)

    deliver_webhook(str(job.id))

    (req,) = sent.calls
    assert req.url == PUBLIC_URL
    assert json.loads(req.body) == {"event": "transcription.completed", "id": str(job.id), "status": "completed"}
    ts = req.headers["X-Transcriber-Timestamp"]
    expected = hmac.new(SECRET.encode(), ts.encode() + b"." + req.body, hashlib.sha256).hexdigest()
    assert req.headers["X-Transcriber-Signature"] == f"sha256={expected}"

    job = reload(db, job)
    assert job.webhook_delivered_at is not None
    assert job.webhook_error is None


def test_failed_job_sends_failed_event(db, sent):
    deliver_webhook(str(make_job(db, status=JobStatus.failed).id))
    assert json.loads(sent.calls[0].body)["event"] == "transcription.failed"


def test_server_error_raises_so_rq_retries(db, sent):
    job = make_job(db)
    sent.response = httpx.Response(503)

    with pytest.raises(RuntimeError):
        deliver_webhook(str(job.id))
    assert reload(db, job).webhook_error == "Callback returned HTTP 503"


def test_network_error_raises_so_rq_retries(db, sent):
    job = make_job(db)
    sent.response = httpx.ConnectTimeout("timed out")

    with pytest.raises(httpx.ConnectTimeout):
        deliver_webhook(str(job.id))
    assert reload(db, job).webhook_error.startswith("ConnectTimeout")


def test_client_rejection_is_not_retried(db, sent):
    job = make_job(db)
    sent.response = httpx.Response(401)

    deliver_webhook(str(job.id))  # no exception, so no retry

    assert reload(db, job).webhook_error == "Callback returned HTTP 401"


def test_url_that_became_private_is_not_called(db, sent):
    job = make_job(db, callback_url="http://127.0.0.1/hook")

    deliver_webhook(str(job.id))

    assert sent.calls == []
    assert "private" in reload(db, job).webhook_error


def test_unfinished_job_is_not_delivered(db, sent):
    deliver_webhook(str(make_job(db, status=JobStatus.processing).id))
    assert sent.calls == []


# --- Triggering ---

def test_worker_notifies_only_jobs_with_a_callback(db, tmp_path, monkeypatch, enqueued_webhooks):
    result = TranscriptionResult(language="en", language_probability=1.0, duration=1.0, segments=[])
    monkeypatch.setattr(worker.transcriber, "transcribe", lambda *a, **kw: result)
    with_cb = TranscriptionJob(owner="o", original_filename="a", model="tiny", callback_url=PUBLIC_URL, callback_secret=SECRET)
    without_cb = TranscriptionJob(owner="o", original_filename="b", model="tiny")
    db.add_all([with_cb, without_cb])
    db.commit()

    worker.transcribe_job(str(with_cb.id))
    worker.transcribe_job(str(without_cb.id))

    assert enqueued_webhooks == [str(with_cb.id)]


def test_worker_notifies_on_final_failure(db, monkeypatch, enqueued_webhooks):
    def corrupt(*_a, **_kw):
        raise ValueError("bad audio")

    monkeypatch.setattr(worker.transcriber, "transcribe", corrupt)
    job = make_job(db, status=JobStatus.queued)

    worker.transcribe_job(str(job.id))

    assert enqueued_webhooks == [str(job.id)]


def test_enqueue_failure_does_not_break_the_job(db, monkeypatch):
    def redis_down(_job_id):
        raise ConnectionError("redis down")

    monkeypatch.setattr(queue, "enqueue_webhook", redis_down)
    webhooks.notify(make_job(db))  # logged, not raised
