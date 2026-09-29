from datetime import datetime, timedelta, timezone
from pathlib import Path

import pytest

from app.config import get_settings
from app.models import JobStatus, TranscriptionJob
from app.reaper import WORKER_LOST_ERROR, recover_orphaned_jobs

LONG_AGO = timedelta(days=1)  # far beyond job timeout + grace period


@pytest.fixture
def enqueue():
    calls: list[str] = []

    def fake(job_id: str) -> str:
        calls.append(job_id)
        return f"rq-new-{job_id}"

    fake.calls = calls
    return fake


def make_job(db, tmp_path, *, status, attempts=0, started_ago=None, created_ago=LONG_AGO, rq_job_id="rq-old"):
    audio = tmp_path / f"{status.value}-{attempts}.ogg"
    audio.write_bytes(b"audio")
    now = datetime.now(timezone.utc)
    job = TranscriptionJob(
        owner="owner",
        original_filename="clip.ogg",
        audio_path=str(audio),
        model="tiny",
        status=status,
        attempts=attempts,
        rq_job_id=rq_job_id,
        created_at=now - created_ago,
        started_at=now - started_ago if started_ago else None,
    )
    db.add(job)
    db.commit()
    return job


def run(db, enqueue, pending=frozenset()):
    return recover_orphaned_jobs(db, get_settings(), enqueue, lambda rq_id: rq_id in pending)


def reload(db, job):
    db.expire_all()
    return db.get(TranscriptionJob, job.id)


def test_crashed_job_with_retries_left_is_requeued(db, tmp_path, enqueue):
    job = make_job(db, tmp_path, status=JobStatus.processing, attempts=1, started_ago=LONG_AGO)

    assert run(db, enqueue) == 1

    job = reload(db, job)
    assert job.status == JobStatus.queued
    assert job.error == WORKER_LOST_ERROR
    assert job.rq_job_id == f"rq-new-{job.id}"
    assert enqueue.calls == [str(job.id)]


def test_crashed_job_without_retries_left_is_failed_and_audio_removed(db, tmp_path, enqueue):
    job = make_job(db, tmp_path, status=JobStatus.processing, attempts=3, started_ago=LONG_AGO)
    audio_path = job.audio_path

    assert run(db, enqueue) == 1

    job = reload(db, job)
    assert job.status == JobStatus.failed
    assert job.finished_at is not None
    assert job.audio_path is None
    assert not Path(audio_path).exists()
    assert enqueue.calls == []


def test_job_processing_within_its_timeout_is_left_alone(db, tmp_path, enqueue):
    job = make_job(db, tmp_path, status=JobStatus.processing, attempts=1, started_ago=timedelta(minutes=5))

    assert run(db, enqueue) == 0
    assert reload(db, job).status == JobStatus.processing


def test_queued_job_missing_from_redis_is_reenqueued(db, tmp_path, enqueue):
    job = make_job(db, tmp_path, status=JobStatus.queued)

    assert run(db, enqueue, pending=set()) == 1
    assert reload(db, job).rq_job_id == f"rq-new-{job.id}"


def test_queued_job_still_in_redis_is_left_alone(db, tmp_path, enqueue):
    make_job(db, tmp_path, status=JobStatus.queued, rq_job_id="rq-old")

    assert run(db, enqueue, pending={"rq-old"}) == 0
    assert enqueue.calls == []


def test_just_created_job_is_left_alone(db, tmp_path, enqueue):
    # Committed but not yet enqueued: the reaper must not race the API.
    make_job(db, tmp_path, status=JobStatus.queued, rq_job_id=None, created_ago=timedelta(seconds=5))

    assert run(db, enqueue) == 0


def test_enqueue_failure_leaves_job_for_next_pass(db, tmp_path):
    job = make_job(db, tmp_path, status=JobStatus.processing, attempts=1, started_ago=LONG_AGO)

    def redis_down(_job_id):
        raise ConnectionError("redis down")

    assert run(db, redis_down) == 0
    assert reload(db, job).status == JobStatus.processing
