"""Background task executed by the RQ worker process."""
import logging
import uuid
from datetime import datetime, timezone
from pathlib import Path

from rq import get_current_job

from app import transcriber
from app.config import get_settings
from app.db import SessionLocal
from app.models import JobStatus, TranscriptionJob

log = logging.getLogger(__name__)

# Errors that will fail identically on every attempt, so retrying only wastes worker time.
# ValueError covers invalid parameters and corrupt/unsupported audio (PyAV's InvalidDataError subclasses it).
PERMANENT_ERRORS = (ValueError, FileNotFoundError)


def _will_retry() -> bool:
    rq_job = get_current_job()
    return bool(rq_job and rq_job.retries_left)


def _cleanup_audio(job: TranscriptionJob) -> None:
    """Uploaded audio is personal data: drop it once the job reaches a terminal state."""
    if get_settings().delete_audio_after_processing and job.audio_path:
        Path(job.audio_path).unlink(missing_ok=True)
        job.audio_path = None


def transcribe_job(job_id: str) -> None:
    with SessionLocal() as db:
        job = db.get(TranscriptionJob, uuid.UUID(job_id))
        if job is None:
            log.warning("Job %s no longer exists (deleted?), skipping", job_id)
            return
        if job.status == JobStatus.completed:
            log.info("Job %s already completed, skipping", job_id)
            return

        job.status = JobStatus.processing
        job.attempts += 1
        job.started_at = datetime.now(timezone.utc)
        job.error = None
        db.commit()

        try:
            result = transcriber.transcribe(job.audio_path, job.model, job.requested_language)
        except Exception as exc:
            log.exception("Job %s failed on attempt %d", job_id, job.attempts)
            job.error = f"{type(exc).__name__}: {exc}"
            permanent = isinstance(exc, PERMANENT_ERRORS)
            job.status = JobStatus.queued if not permanent and _will_retry() else JobStatus.failed
            if job.status == JobStatus.failed:
                job.finished_at = datetime.now(timezone.utc)
                _cleanup_audio(job)
            db.commit()
            if permanent:
                # RQ has no per-exception retry policy, so swallow the error to stop it retrying.
                # The failure is already recorded on the job, which is the source of truth for clients.
                return
            raise  # let RQ record the failure and schedule the retry

        job.detected_language = result.language
        job.language_probability = result.language_probability
        job.duration_seconds = result.duration
        job.segments = result.segments
        job.text = result.text
        job.status = JobStatus.completed
        job.finished_at = datetime.now(timezone.utc)
        _cleanup_audio(job)
        db.commit()
        log.info("Job %s completed (%d segments)", job_id, len(result.segments))
