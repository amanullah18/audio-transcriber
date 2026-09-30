import uuid
from functools import lru_cache

from redis import Redis
from rq import Queue, Retry
from rq.exceptions import NoSuchJobError
from rq.job import Job, JobStatus

from app.config import get_settings


@lru_cache
def get_redis() -> Redis:
    settings = get_settings()
    # Without timeouts, a hung Redis would block API requests indefinitely.
    return Redis.from_url(
        settings.redis_url,
        socket_connect_timeout=settings.redis_connect_timeout_seconds,
        socket_timeout=settings.redis_socket_timeout_seconds,
        health_check_interval=30,
    )


def get_queue() -> Queue:
    return Queue(get_settings().queue_name, connection=get_redis())


PENDING_STATUSES = {JobStatus.QUEUED, JobStatus.SCHEDULED, JobStatus.DEFERRED, JobStatus.STARTED}


def enqueue_transcription(job_id: str) -> str:
    """Enqueue a transcription and return the RQ job id."""
    settings = get_settings()
    rq_job = get_queue().enqueue(
        "app.worker.transcribe_job",
        job_id,
        # Prefixed with our DB id for easy correlation, but unique per enqueue: re-queueing an orphaned
        # job must not reuse the id of the dead RQ job, which RQ's own registry cleanup may still touch.
        job_id=f"{job_id}-{uuid.uuid4().hex[:8]}",
        job_timeout=settings.job_timeout_seconds,
        retry=Retry(max=settings.job_max_retries, interval=[10, 60]),
    )
    return rq_job.id


def enqueue_webhook(job_id: str) -> None:
    settings = get_settings()
    # A separate queue, listed first by the worker, so notifications aren't stuck behind a backlog of transcriptions.
    Queue(settings.webhook_queue_name, connection=get_redis()).enqueue(
        "app.webhooks.deliver_webhook",
        job_id,
        job_timeout=60,
        retry=Retry(max=5, interval=[10, 30, 120, 600, 1800]),
    )


def is_pending(rq_job_id: str) -> bool:
    """True if RQ still intends to run this job (waiting, scheduled for retry, or running)."""
    try:
        return Job.fetch(rq_job_id, connection=get_redis()).get_status() in PENDING_STATUSES
    except NoSuchJobError:
        return False
