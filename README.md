# Audio Transcriber API

[![CI](https://github.com/amanullah18/audio-transcriber/actions/workflows/ci.yml/badge.svg)](https://github.com/amanullah18/audio-transcriber/actions/workflows/ci.yml)
[![Python 3.12](https://img.shields.io/badge/python-3.12-blue.svg)](https://www.python.org/downloads/)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

An asynchronous speech-to-text service. You upload an audio file (voice notes, meetings, podcasts) and get back a job ID. A background worker transcribes the audio with [faster-whisper](https://github.com/SYSTRAN/faster-whisper), and you fetch the transcript with timestamps once it's ready.

Transcription runs on your own machine on CPU, so no audio is sent to a third-party API.

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
- **API-key authentication.** Every job endpoint requires an `X-API-Key` header, and each key only sees the jobs it created. Another client's job returns `404`, so job ids can't be probed. Keys are stored only as hashes on jobs. The API refuses to start without a key, or with the placeholder from `.env.example`.
- **Retries with backoff, only when useful.** Temporary failures are retried up to 2 times (after 10s, then 60s). Permanent ones fail straight away, because retrying would give the same result: corrupt audio, invalid parameters, and timeouts (audio too long for the limit would just time out again). The attempt count and last error are stored on the job.
- **Timeouts everywhere.** Each job has a hard time limit (`JOB_TIMEOUT_SECONDS`). Redis and Postgres connections have short timeouts, so a hung dependency makes requests fail fast with `503` instead of hanging.
- **Crash recovery.** If a worker dies mid-job (crash, OOM kill, redeploy), the job would stay `processing` forever, because the timeout is enforced by the worker that died. The API runs a background check (see [app/reaper.py](app/reaper.py)) that finds such jobs once they've outlived their timeout plus a grace period, then re-queues them, or fails them if they have no retries left. It also re-enqueues `queued` jobs whose queue entry was lost, e.g. after a Redis restart. Workers claim jobs with an atomic `UPDATE ... WHERE status = 'queued'`, so a job delivered twice is never transcribed twice. A job stuck this way can also be deleted.
- **Upload validation.** Only allowed file extensions are accepted. Uploads are streamed to disk in chunks and rejected with `413` as soon as they pass the size limit, so the whole file is never held in memory.
- **Data minimisation.** Uploaded audio is deleted once a job completes or finally fails. Transcripts stay in Postgres until the client deletes them.
- **Model caching.** The worker loads each Whisper model once and reuses it across jobs, instead of loading hundreds of MB per request.
- **Graceful degradation.** If Redis is down, uploads return `503` rather than leaving jobs stranded, and `/health` reports each dependency separately.
- **100 languages by name.** Pass `language=urdu` (or the ISO code `ur`), or leave it out to auto-detect. Unsupported languages are rejected with `422` before any work is queued.

## Quick start

Requires Docker.

```bash
git clone https://github.com/amanullah18/audio-transcriber.git
cd audio-transcriber
cp .env.example .env
# Edit .env and set API_KEYS to a random secret, e.g. the output of:
#   python -c "import secrets; print(secrets.token_urlsafe(32))"
docker compose up -d --build
```

Interactive API docs: **http://localhost:8000/docs** (click **Authorize** and enter your API key).

```bash
# 1. Submit audio
curl -H "X-API-Key: $API_KEY" -F "file=@meeting.mp3" -F "model=small" -F "language=english"   http://localhost:8000/transcriptions
# {"id":"4d03e536-7165-431e-8789-e57dcab579d5","status":"queued"}

# 2. Poll for the result
curl -H "X-API-Key: $API_KEY" http://localhost:8000/transcriptions/4d03e536-7165-431e-8789-e57dcab579d5
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

All `/transcriptions` endpoints require the `X-API-Key` header and return `401` without a valid key. `/languages` and `/health` are public.

| Method   | Endpoint                         | Description                                                                 |
|----------|----------------------------------|-----------------------------------------------------------------------------|
| `POST`   | `/transcriptions`                | Upload audio (`file`, optional `model`, `language`). Returns `202` + `Location` header. |
| `GET`    | `/languages`                     | Supported languages as `{code, name}` pairs.                                |
| `GET`    | `/transcriptions`                | List jobs, newest first. Query: `status`, `limit` (≤100), `offset`.         |
| `GET`    | `/transcriptions/{id}`           | Job status, and the transcript with segments once completed.                |
| `GET`    | `/transcriptions/{id}/text`      | Plain-text transcript. `409` if not completed yet.                          |
| `DELETE` | `/transcriptions/{id}`           | Delete the job and its audio. `409` while processing, unless the job's worker is gone. |
| `GET`    | `/health`                        | Database and Redis connectivity. `503` if either is down.                   |

**Models:** `tiny`, `base`, `small` (default), `medium`, `large-v3`. Bigger models are more accurate and slower.
**Formats:** `.ogg .opus .mp3 .m4a .wav .flac .webm .mp4`

## Configuration

Set these as environment variables (see [.env.example](.env.example)):

| Variable                        | Default        | Description                                |
|---------------------------------|----------------|--------------------------------------------|
| `API_KEYS`                      | *(required)*   | Comma-separated API keys; each key only sees its own jobs |
| `DATABASE_URL`                  | local Postgres | SQLAlchemy connection URL                  |
| `REDIS_URL`                     | local Redis    | Queue backend                              |
| `MAX_UPLOAD_MB`                 | `50`           | Upload size limit                          |
| `DEFAULT_MODEL`                 | `small`        | Model used when the request doesn't specify one |
| `JOB_TIMEOUT_SECONDS`           | `3600`         | Hard limit per job                         |
| `JOB_MAX_RETRIES`               | `2`            | Retries after the first failed attempt     |
| `DELETE_AUDIO_AFTER_PROCESSING` | `true`         | Remove uploaded audio when a job finishes  |
| `STALE_JOB_GRACE_SECONDS`       | `300`          | How long past its timeout a `processing` job may go before it's treated as orphaned |
| `REAPER_INTERVAL_SECONDS`       | `60`           | How often the API checks for orphaned jobs (`0` disables) |

## Security notes

- This service does not terminate TLS. Put it behind a reverse proxy (nginx, Caddy, a cloud load balancer) with HTTPS, or API keys and audio travel in plain text.
- API keys are shared secrets configured by environment variable. There's no key management UI or per-key rate limiting yet (see roadmap).
- The Postgres credentials in `docker-compose.yml` are for local use. Change them for any real deployment.

## Development

```bash
python -m venv venv
source venv/bin/activate          # Windows: venv\Scripts\activate
pip install -r requirements-dev.txt
pytest -v
```

The Redis queue and Whisper model are replaced with fakes, so the tests run in about a second. Locally they use SQLite by default, so no services are needed. **CI runs them against a real PostgreSQL**, the same engine as production, along with a Docker build. To do the same locally:

```bash
docker run -d --rm --name test-pg -e POSTGRES_USER=test -e POSTGRES_PASSWORD=test -e POSTGRES_DB=test -p 55432:5432 postgres:17-alpine
TEST_DATABASE_URL=postgresql+psycopg://test:test@localhost:55432/test pytest -v
docker stop test-pg
```

### CLI (no server needed)

For a quick one-off transcription without the API:

```bash
python transcribe.py voice-note.ogg --model small --language en
```

## Project structure

```
app/
  main.py         FastAPI routes
  worker.py       Background task: atomic claiming, retries, cleanup
  reaper.py       Recovery of jobs orphaned by worker crashes or lost queue entries
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
- Per-client rate limiting and quotas
- Object storage (S3/MinIO) for uploads, so API and workers don't need a shared volume
- Configurable transcript retention (auto-delete after N days)
- GPU worker image (CUDA) for faster large-model transcription
- Export as SRT/VTT subtitles

## License

[MIT](LICENSE)
