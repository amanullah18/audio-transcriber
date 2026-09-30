"""Background task executed by the RQ worker process."""
import logging
import uuid
from datetime import datetime, timezone

from rq import get_current_job
from rq.timeouts import JobTimeoutException
from sqlalchemy import update

from app import transcriber, webhooks
from app.config import get_settings
from app.db import SessionLocal
from app.models import JobStatus, TranscriptionJob
from app.storage import delete_job_audio

log = logging.getLogger(__name__)

# Errors that will fail identically on every attempt, so retrying only wastes worker time.
# ValueError covers invalid parameters and corrupt/unsupported audio (PyAV's InvalidDataError subclasses it).
# A timeout means the audio is too long for the configured limit, and would just time out again.
PERMANENT_ERRORS = (ValueError, FileNotFoundError, JobTimeoutException)


def _will_retry() -> bool:
    rq_job = get_current_job()
    return bool(rq_job and rq_job.retries_left)


def _claim(db, job_id: uuid.UUID) -> bool:
    """Atomically move a queued job to processing.

    Only one worker can win this, so a job delivered twice (e.g. re-queued by the reaper while RQ also
    retried it) is never transcribed twice at the same time.
    """
    result = db.execute(
        update(TranscriptionJob)
        .where(TranscriptionJob.id == job_id, TranscriptionJob.status == JobStatus.queued)
        .values(
            status=JobStatus.processing,
            attempts=TranscriptionJob.attempts + 1,
            started_at=datetime.now(timezone.utc),
            error=None,
        )
    )
    db.commit()
    return result.rowcount == 1


def transcribe_job(job_id: str) -> None:
    settings = get_settings()
    with SessionLocal() as db:
        if not _claim(db, uuid.UUID(job_id)):
            log.info("Job %s is not queued (deleted, finished or claimed by another worker), skipping", job_id)
            return
        job = db.get(TranscriptionJob, uuid.UUID(job_id))

        try:
            result = transcriber.transcribe(job.audio_path, job.model, job.requested_language)
        except Exception as exc:
            log.exception("Job %s failed on attempt %d", job_id, job.attempts)
            job.error = f"{type(exc).__name__}: {exc}"
            permanent = isinstance(exc, PERMANENT_ERRORS)
            job.status = JobStatus.queued if not permanent and _will_retry() else JobStatus.failed
            if job.status == JobStatus.failed:
                job.finished_at = datetime.now(timezone.utc)
                delete_job_audio(job, settings)
            db.commit()
            if job.status == JobStatus.failed:
                webhooks.notify(job)
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
        delete_job_audio(job, settings)
        db.commit()
        log.info("Job %s completed (%d segments)", job_id, len(result.segments))
        webhooks.notify(job)
