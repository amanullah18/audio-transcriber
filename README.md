# Audio Transcriber API

An asynchronous speech-to-text service. You upload an audio file (voice notes, meetings, podcasts) and get back a job ID. A background worker transcribes the audio with [faster-whisper](https://github.com/SYSTRAN/faster-whisper), and you fetch the transcript with timestamps once it's ready.

It runs entirely on your own machine on CPU. No audio is sent to a third-party API.

**Stack:** FastAPI · PostgreSQL · Redis + RQ · SQLAlchemy 2 · faster-whisper · Docker Compose · pytest · GitHub Actions

## Architecture

```
            POST /transcriptions                         ┌──────────────┐
 client ───────────────────────────►  ┌─────────┐  ──►   │  PostgreSQL  │  job status, transcript, segments
        ◄─── 202 {id, "queued"}       │   API   │        └──────────────┘
                                      │ FastAPI │               ▲
            GET /transcriptions/{id}  └─────────┘               │ updates status / result
 client ───────────────────────────►    │    │                  │
        ◄─── status / transcript        │    │ enqueue(id) ┌──────────┐   pulls jobs   ┌──────────────────┐
                                        │    └───────────► │  Redis   │ ◄───────────── │   RQ worker(s)   │
                                        │                  │  queue   │                │  faster-whisper  │
                                        ▼                  └──────────┘                └──────────────────┘
                                 shared uploads volume  ◄──────── reads audio, deletes it when done ─┘
```

**Why a queue?** Transcription is CPU-heavy and takes anywhere from seconds to many minutes. Doing it inside the HTTP request would tie up API workers and hit client and proxy timeouts. Instead the API does only fast work (validate, store, enqueue) and returns `202 Accepted` right away. Workers can then be scaled on their own (`WORKER_REPLICAS=3 docker compose up`).

## Features

- **Async job processing.** Jobs move through `queued → processing → completed | failed`.
- **Retries with backoff, only when useful.** Temporary failures are retried up to 2 times (after 10s, then 60s). Permanent ones, like corrupt audio, fail straight away, because retrying would give the same result. The attempt count and last error are stored on the job.
- **Timeouts.** Each job has a hard time limit (`JOB_TIMEOUT_SECONDS`), so a stuck job can't hold a worker forever.
- **Upload validation.** Only allowed file extensions are accepted. Uploads are streamed to disk in chunks and rejected with `413` as soon as they pass the size limit, so the whole file is never held in memory.
- **Privacy by default.** Uploaded audio is deleted once a job completes or finally fails.
- **Model caching.** The worker loads each Whisper model once and reuses it across jobs, instead of loading hundreds of MB per request.
- **Graceful degradation.** If Redis is down, uploads return `503` rather than leaving jobs stranded, and `/health` reports each dependency separately.
- **100 languages by name.** Pass `language=urdu` (or the ISO code `ur`), or leave it out to auto-detect. Unsupported languages are rejected with `422` before any work is queued.

## Quick start

Requires Docker.

```bash
git clone https://github.com/amanullah18/audio-transcriber.git
cd audio-transcriber
docker compose up -d --build
```

Interactive API docs: **http://localhost:8000/docs**

```bash
# 1. Submit audio
curl -F "file=@meeting.mp3" -F "model=small" -F "language=english" http://localhost:8000/transcriptions
# {"id":"4d03e536-7165-431e-8789-e57dcab579d5","status":"queued"}

# 2. Poll for the result
curl http://localhost:8000/transcriptions/4d03e536-7165-431e-8789-e57dcab579d5
```

```json
{
  "id": "4d03e536-7165-431e-8789-e57dcab579d5",
  "status": "completed",
  "model": "small",
  "requested_language": "en",
  "requested_language_name": "english",
  "detected_language": "en",
  "detected_language_name": "english",
  "language_probability": 0.996,
  "duration_seconds": 7.5,
  "text": "Hello, this is a test of the audio transcription service. The worker should turn the speech into text.",
  "segments": [
    { "start": 0.0, "end": 4.0, "text": "Hello, this is a test of the audio transcription service." },
    { "start": 4.0, "end": 7.0, "text": "The worker should turn the speech into text." }
  ],
  "attempts": 1,
  "error": null
}
```

The first job for each model downloads its weights (≈75 MB for `tiny`, ≈500 MB for `small`). They are cached in a Docker volume after that.

## API

| Method   | Endpoint                         | Description                                                                 |
|----------|----------------------------------|-----------------------------------------------------------------------------|
| `POST`   | `/transcriptions`                | Upload audio (`file`, optional `model`, `language`). Returns `202` + `Location` header. |
| `GET`    | `/languages`                     | Supported languages as `{code, name}` pairs.                                |
| `GET`    | `/transcriptions`                | List jobs, newest first. Query: `status`, `limit` (≤100), `offset`.         |
| `GET`    | `/transcriptions/{id}`           | Job status, and the transcript with segments once completed.                |
| `GET`    | `/transcriptions/{id}/text`      | Plain-text transcript. `409` if not completed yet.                          |
| `DELETE` | `/transcriptions/{id}`           | Delete the job and its audio. `409` while processing.                       |
| `GET`    | `/health`                        | Database and Redis connectivity. `503` if either is down.                   |

**Models:** `tiny`, `base`, `small` (default), `medium`, `large-v3`. Bigger models are more accurate and slower.
**Formats:** `.ogg .opus .mp3 .m4a .wav .flac .webm .mp4`

## Configuration

Set these as environment variables (see [.env.example](.env.example)):

| Variable                        | Default        | Description                                |
|---------------------------------|----------------|--------------------------------------------|
| `DATABASE_URL`                  | local Postgres | SQLAlchemy connection URL                  |
| `REDIS_URL`                     | local Redis    | Queue backend                              |
| `MAX_UPLOAD_MB`                 | `50`           | Upload size limit                          |
| `DEFAULT_MODEL`                 | `small`        | Model used when the request doesn't specify one |
| `JOB_TIMEOUT_SECONDS`           | `3600`         | Hard limit per job                         |
| `JOB_MAX_RETRIES`               | `2`            | Retries after the first failed attempt     |
| `DELETE_AUDIO_AFTER_PROCESSING` | `true`         | Remove uploaded audio when a job finishes  |

## Development

```bash
python -m venv venv
source venv/bin/activate          # Windows: venv\Scripts\activate
pip install -r requirements-dev.txt
pytest -v
```

The tests use SQLite and replace the Redis queue and Whisper model with fakes, so they run in about a second with no services running. CI runs them on every push, along with a Docker build.

### CLI (no server needed)

For a quick one-off transcription without the API:

```bash
python transcribe.py voice-note.ogg --model small --language en
```

## Project structure

```
app/
  main.py         FastAPI routes
  worker.py       Background task: status transitions, retries, cleanup
  transcriber.py  faster-whisper wrapper with model caching
  languages.py    Supported languages (name <-> ISO code)
  queue.py        Redis/RQ setup and job enqueueing
  storage.py      Streaming upload validation and persistence
  models.py       SQLAlchemy models
  schemas.py      Pydantic request/response schemas
  config.py       Environment-based settings
  db.py           Engine and session management
tests/            API and worker tests
transcribe.py     Standalone CLI
```

## Roadmap

- Alembic migrations (tables are currently created on startup)
- Webhook callback when a job finishes, as an alternative to polling
- API-key authentication and per-client rate limiting
- Object storage (S3/MinIO) for uploads, so API and workers don't need a shared volume
- Detect jobs orphaned by a worker crash and re-queue them
- GPU worker image (CUDA) for faster large-model transcription
- Export as SRT/VTT subtitles
