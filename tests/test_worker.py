import uuid
from pathlib import Path
from types import SimpleNamespace

import pytest

from app import worker
from app.models import JobStatus, TranscriptionJob
from app.transcriber import TranscriptionResult


@pytest.fixture
def job(db, tmp_path):
    audio = tmp_path / "clip.ogg"
    audio.write_bytes(b"audio")
    job = TranscriptionJob(original_filename="clip.ogg", audio_path=str(audio), model="tiny")
    db.add(job)
    db.commit()
    return job


def reload(db, job):
    db.expire_all()
    return db.get(TranscriptionJob, job.id)


def _boom(*_a, **_kw):
    raise RuntimeError("decoder exploded")


def test_successful_job_stores_result_and_deletes_audio(db, job, monkeypatch):
    audio_path = Path(job.audio_path)
    result = TranscriptionResult(
        language="en",
        language_probability=0.98,
        duration=4.2,
        segments=[{"start": 0.0, "end": 2.0, "text": "Hello"}, {"start": 2.0, "end": 4.2, "text": "world"}],
    )
    monkeypatch.setattr(worker.transcriber, "transcribe", lambda *a, **kw: result)

    worker.transcribe_job(str(job.id))

    job = reload(db, job)
    assert job.status == JobStatus.completed
    assert job.text == "Hello world"
    assert job.detected_language == "en"
    assert len(job.segments) == 2
    assert job.attempts == 1
    assert job.audio_path is None
    assert not audio_path.exists()


def test_final_failure_marks_job_failed(db, job, monkeypatch):
    monkeypatch.setattr(worker.transcriber, "transcribe", _boom)
    monkeypatch.setattr(worker, "get_current_job", lambda: SimpleNamespace(retries_left=0))

    with pytest.raises(RuntimeError):
        worker.transcribe_job(str(job.id))

    audio_path = Path(job.audio_path)
    job = reload(db, job)
    assert job.status == JobStatus.failed
    assert job.error == "RuntimeError: decoder exploded"
    assert job.finished_at is not None
    assert job.audio_path is None
    assert not audio_path.exists()


def test_failure_with_retries_left_goes_back_to_queued(db, job, monkeypatch):
    monkeypatch.setattr(worker.transcriber, "transcribe", _boom)
    monkeypatch.setattr(worker, "get_current_job", lambda: SimpleNamespace(retries_left=1))

    with pytest.raises(RuntimeError):
        worker.transcribe_job(str(job.id))

    job = reload(db, job)
    assert job.status == JobStatus.queued
    assert job.attempts == 1
    assert Path(job.audio_path).exists()  # kept for the next attempt


def test_permanent_error_fails_immediately_without_retry(db, job, monkeypatch):
    def corrupt_audio(*_a, **_kw):
        raise ValueError("Invalid data found when processing input")

    monkeypatch.setattr(worker.transcriber, "transcribe", corrupt_audio)
    monkeypatch.setattr(worker, "get_current_job", lambda: SimpleNamespace(retries_left=2))

    worker.transcribe_job(str(job.id))  # does not raise, so RQ won't schedule a retry

    job = reload(db, job)
    assert job.status == JobStatus.failed
    assert job.attempts == 1
    assert job.error.startswith("ValueError")
    assert job.audio_path is None


def test_missing_job_is_skipped(monkeypatch):
    monkeypatch.setattr(worker.transcriber, "transcribe", _boom)
    worker.transcribe_job(str(uuid.uuid4()))  # should not raise
