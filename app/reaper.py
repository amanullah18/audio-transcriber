"""Recovers jobs that would otherwise be stuck forever.

Two ways a job can get lost:
- Its worker died mid-job (crash, OOM kill, redeploy). The job stays "processing" with nobody working on it.
  RQ's timeout can't help, because it's enforced by the worker that died.
- Its queue entry disappeared (Redis restarted without persistence, or enqueueing failed). The job stays
  "queued" but no worker will ever pick it up.

The API runs `recover_orphaned_jobs` periodically. It's safe to run from several API replicas at once:
batches are selected with SKIP LOCKED to avoid most overlap, and in the worst case a job gets a duplicate
queue entry, which is harmless because workers claim jobs atomically (see `worker._claim`).
"""
import logging
from collections.abc import Callable
from datetime import datetime, timedelta, timezone

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.config import Settings
from app.models import JobStatus, TranscriptionJob
from app.storage import delete_job_audio

log = logging.getLogger(__name__)

BATCH_SIZE = 100
# A freshly created job is committed just before it's enqueued; don't mistake that gap for a lost entry.
QUEUED_MIN_AGE = timedelta(minutes=1)
WORKER_LOST_ERROR = "Worker stopped responding while processing this job (crashed or was restarted)"


def _as_utc(dt: datetime) -> datetime:
    # SQLite drops timezone info; everything is stored in UTC.
    return dt if dt.tzinfo else dt.replace(tzinfo=timezone.utc)


def is_stale(job: TranscriptionJob, settings: Settings) -> bool:
    """True if a processing job has outlived its timeout by more than the grace period, so its worker is gone."""
    if job.status != JobStatus.processing or job.started_at is None:
        return False
    return _as_utc(job.started_at) < datetime.now(timezone.utc) - timedelta(seconds=settings.stale_after_seconds)


def _requeue(db: Session, job: TranscriptionJob, enqueue: Callable[[str], str]) -> bool:
    job.status = JobStatus.queued
    try:
        job.rq_job_id = enqueue(str(job.id))
    except Exception:
        log.exception("Could not re-enqueue job %s, will try again on the next pass", job.id)
        db.rollback()
        return False
    db.commit()
    return True


def recover_orphaned_jobs(
    db: Session, settings: Settings, enqueue: Callable[[str], str], is_pending: Callable[[str], bool]
) -> int:
    now = datetime.now(timezone.utc)
    recovered = 0
    handled: set = set()

    stale = db.scalars(
        select(TranscriptionJob)
        .where(
            TranscriptionJob.status == JobStatus.processing,
            TranscriptionJob.started_at < now - timedelta(seconds=settings.stale_after_seconds),
        )
        .limit(BATCH_SIZE)
        .with_for_update(skip_locked=True)
    ).all()
    for job in stale:
        handled.add(job.id)
        job.error = WORKER_LOST_ERROR
        if job.attempts > settings.job_max_retries:
            log.warning("Job %s lost its worker and has no retries left, marking failed", job.id)
            job.status = JobStatus.failed
            job.finished_at = now
            delete_job_audio(job, settings)
            db.commit()
            recovered += 1
        elif _requeue(db, job, enqueue):
            log.warning("Job %s lost its worker, re-queued (attempt %d so far)", job.id, job.attempts)
            recovered += 1

    queued = db.scalars(
        select(TranscriptionJob)
        .where(TranscriptionJob.status == JobStatus.queued, TranscriptionJob.created_at < now - QUEUED_MIN_AGE)
        .limit(BATCH_SIZE)
        .with_for_update(skip_locked=True)
    ).all()
    for job in queued:
        if job.id in handled or (job.rq_job_id and is_pending(job.rq_job_id)):
            continue
        if _requeue(db, job, enqueue):
            log.warning("Job %s was queued but missing from the queue, re-enqueued", job.id)
            recovered += 1
    db.commit()  # release locks on the rows that were still pending

    return recovered

