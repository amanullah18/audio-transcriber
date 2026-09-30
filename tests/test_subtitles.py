import uuid

from app.models import JobStatus, TranscriptionJob
from app.subtitles import to_srt, to_vtt

SEGMENTS = [
    {"start": 0.0, "end": 2.5, "text": "Hello & welcome"},
    {"start": 3661.042, "end": 3663.9, "text": "<b>not markup</b>"},
]


def test_vtt_format_and_escaping():
    assert to_vtt(SEGMENTS) == (
        "WEBVTT\n\n"
        "00:00:00.000 --> 00:00:02.500\nHello &amp; welcome\n\n"
        "01:01:01.042 --> 01:01:03.900\n&lt;b&gt;not markup&lt;/b&gt;\n"
    )


def test_srt_format():
    assert to_srt(SEGMENTS) == (
        "1\n00:00:00,000 --> 00:00:02,500\nHello & welcome\n\n"
        "2\n01:01:01,042 --> 01:01:03,900\n<b>not markup</b>\n"
    )


def _completed_job(client, db):
    job_id = client.post("/transcriptions", files={"file": ("talk.mp3", b"x", "audio/mpeg")}).json()["id"]
    job = db.get(TranscriptionJob, uuid.UUID(job_id))
    job.status, job.segments = JobStatus.completed, SEGMENTS
    db.commit()
    return job_id


def test_subtitles_endpoint_defaults_to_vtt(client, db):
    resp = client.get(f"/transcriptions/{_completed_job(client, db)}/subtitles")

    assert resp.status_code == 200
    assert resp.headers["content-type"].startswith("text/vtt")
    assert resp.headers["content-disposition"] == 'inline; filename="talk.vtt"'
    assert resp.text.startswith("WEBVTT")


def test_subtitles_endpoint_srt(client, db):
    resp = client.get(f"/transcriptions/{_completed_job(client, db)}/subtitles", params={"format": "srt"})

    assert resp.headers["content-type"].startswith("application/x-subrip")
    assert resp.text.startswith("1\n00:00:00,000")


def test_subtitles_not_ready_returns_409(client):
    job_id = client.post("/transcriptions", files={"file": ("talk.mp3", b"x", "audio/mpeg")}).json()["id"]
    assert client.get(f"/transcriptions/{job_id}/subtitles").status_code == 409
