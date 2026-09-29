from functools import lru_cache

from redis import Redis
from rq import Queue, Retry

from app.config import get_settings


@lru_cache
def get_redis() -> Redis:
    return Redis.from_url(get_settings().redis_url)


def get_queue() -> Queue:
    return Queue(get_settings().queue_name, connection=get_redis())


def enqueue_transcription(job_id: str) -> None:
    settings = get_settings()
    get_queue().enqueue(
        "app.worker.transcribe_job",
        job_id,
        job_id=job_id,  # reuse our DB id so RQ and Postgres records are easy to correlate
        job_timeout=settings.job_timeout_seconds,
        retry=Retry(max=settings.job_max_retries, interval=[10, 60]),
    )
