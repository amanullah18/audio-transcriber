import uuid
from datetime import datetime, timedelta, timezone
from pathlib import Path

from app.main import app, get_enqueuer
from app.models import JobStatus, TranscriptionJob

AUDIO = ("voice.ogg", b"fake-ogg-bytes", "audio/ogg")


def upload(client, file=AUDIO, **form):
    return client.post("/transcriptions", files={"file": file}, data=form)


def test_create_transcription_saves_file_and_enqueues(client, enqueued, db):
    resp = upload(client, language="hindi", model="tiny")

    assert resp.status_code == 202
    body = resp.json()
    assert body["status"] == "queued"
    assert resp.headers["Location"] == f"/transcriptions/{body['id']}"
    assert enqueued == [body["id"]]

    job = db.get(TranscriptionJob, uuid.UUID(body["id"]))
    assert job.rq_job_id == f"rq-{body['id']}"
    assert job.model == "tiny"
    assert job.requested_language == "hi"
    assert Path(job.audio_path).read_bytes() == b"fake-ogg-bytes"


def test_default_model_is_used_when_not_given(client, db):
    job_id = upload(client).json()["id"]
    assert db.get(TranscriptionJob, uuid.UUID(job_id)).model == "small"


def test_rejects_unsupported_extension(client, enqueued):
    resp = upload(client, file=("notes.txt", b"hello", "text/plain"))
    assert resp.status_code == 415
    assert enqueued == []


def test_rejects_file_over_size_limit(client, enqueued):
    resp = upload(client, file=("big.mp3", b"x" * (1024 * 1024 + 1), "audio/mpeg"))
    assert resp.status_code == 413
    assert enqueued == []


def test_rejects_empty_file(client):
    assert upload(client, file=("empty.wav", b"", "audio/wav")).status_code == 400


def test_rejects_invalid_language_and_model(client, enqueued):
    assert upload(client, language="yu").status_code == 422
    assert upload(client, language="klingon").status_code == 422
    assert upload(client, model="gigantic").status_code == 422
    assert enqueued == []


def test_language_accepts_name_code_or_any_casing(client, db):
    for value in ("urdu", "ur", "Urdu"):
        job_id = upload(client, language=value).json()["id"]
        assert db.get(TranscriptionJob, uuid.UUID(job_id)).requested_language == "ur"


def test_job_detail_includes_language_names(client, db):
    job_id = upload(client, language="urdu").json()["id"]
    job = db.get(TranscriptionJob, uuid.UUID(job_id))
    job.detected_language = "hi"
    db.commit()

    body = client.get(f"/transcriptions/{job_id}").json()
    assert (body["requested_language"], body["requested_language_name"]) == ("ur", "urdu")
    assert (body["detected_language"], body["detected_language_name"]) == ("hi", "hindi")


def test_list_languages(client):
    languages = client.get("/languages").json()
    assert {"code": "ur", "name": "urdu"} in languages
    assert [l["name"] for l in languages] == sorted(l["name"] for l in languages)


def test_queue_outage_returns_503_and_marks_job_failed(client, db):
    def broken_enqueue(_job_id):
        raise ConnectionError("redis down")

    app.dependency_overrides[get_enqueuer] = lambda: broken_enqueue
    resp = upload(client)

    assert resp.status_code == 503
    job = db.query(TranscriptionJob).one()
    assert job.status == JobStatus.failed
    assert job.audio_path is None


def test_get_transcription(client):
    job_id = upload(client).json()["id"]

    resp = client.get(f"/transcriptions/{job_id}")
    assert resp.status_code == 200
    assert resp.json()["original_filename"] == "voice.ogg"
    assert resp.json()["text"] is None


def test_get_unknown_transcription_returns_404(client):
    assert client.get(f"/transcriptions/{uuid.uuid4()}").status_code == 404


def test_text_endpoint_returns_409_until_completed(client, db):
    job_id = upload(client).json()["id"]
    assert client.get(f"/transcriptions/{job_id}/text").status_code == 409

    job = db.get(TranscriptionJob, uuid.UUID(job_id))
    job.status, job.text = JobStatus.completed, "hello world"
    db.commit()

    resp = client.get(f"/transcriptions/{job_id}/text")
    assert resp.status_code == 200
    assert resp.text == "hello world"
    assert resp.headers["content-type"].startswith("text/plain")


def test_list_supports_status_filter_and_pagination(client, db):
    ids = [upload(client).json()["id"] for _ in range(3)]
    job = db.get(TranscriptionJob, uuid.UUID(ids[0]))
    job.status = JobStatus.completed
    db.commit()

    page = client.get("/transcriptions", params={"limit": 2}).json()
    assert page["total"] == 3
    assert len(page["items"]) == 2

    completed = client.get("/transcriptions", params={"status": "completed"}).json()
    assert [i["id"] for i in completed["items"]] == [ids[0]]


def test_delete_removes_job_and_audio(client, db):
    job_id = upload(client).json()["id"]
    audio_path = Path(db.get(TranscriptionJob, uuid.UUID(job_id)).audio_path)

    assert client.delete(f"/transcriptions/{job_id}").status_code == 204
    assert client.get(f"/transcriptions/{job_id}").status_code == 404
    assert not audio_path.exists()


def test_cannot_delete_while_processing(client, db):
    job_id = upload(client).json()["id"]
    job = db.get(TranscriptionJob, uuid.UUID(job_id))
    job.status = JobStatus.processing
    job.started_at = datetime.now(timezone.utc)
    db.commit()

    assert client.delete(f"/transcriptions/{job_id}").status_code == 409


def test_can_delete_job_stuck_processing_after_worker_crash(client, db):
    job_id = upload(client).json()["id"]
    job = db.get(TranscriptionJob, uuid.UUID(job_id))
    job.status = JobStatus.processing
    job.started_at = datetime.now(timezone.utc) - timedelta(days=1)  # far beyond timeout + grace
    db.commit()

    assert client.delete(f"/transcriptions/{job_id}").status_code == 204
