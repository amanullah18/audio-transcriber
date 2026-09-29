import logging
import uuid
from collections.abc import Callable
from contextlib import asynccontextmanager
from pathlib import Path
from typing import Annotated

from fastapi import Depends, FastAPI, File, Form, HTTPException, Query, Response, UploadFile, status
from fastapi.responses import JSONResponse
from sqlalchemy import func, select, text
from sqlalchemy.orm import Session

from app import queue
from app.config import Settings, get_settings
from app.db import get_db, init_db
from app.languages import LANGUAGES, Language
from app.models import JobStatus, TranscriptionJob
from app.schemas import JobCreated, JobDetail, JobList, LanguageInfo, WhisperModelName
from app.storage import save_upload, validate_extension

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")
log = logging.getLogger(__name__)


@asynccontextmanager
async def lifespan(_: FastAPI):
    init_db()
    yield


app = FastAPI(
    title="Audio Transcriber API",
    description="Upload audio, get a transcript back. Transcription runs asynchronously on a worker using faster-whisper.",
    version="1.0.0",
    lifespan=lifespan,
)

DbSession = Annotated[Session, Depends(get_db)]
AppSettings = Annotated[Settings, Depends(get_settings)]


def get_enqueuer() -> Callable[[str], None]:
    return queue.enqueue_transcription


def _get_job_or_404(db: Session, job_id: uuid.UUID) -> TranscriptionJob:
    job = db.get(TranscriptionJob, job_id)
    if job is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, "Transcription job not found")
    return job


@app.post("/transcriptions", response_model=JobCreated, status_code=status.HTTP_202_ACCEPTED)
async def create_transcription(
    db: DbSession,
    settings: AppSettings,
    enqueue: Annotated[Callable[[str], None], Depends(get_enqueuer)],
    response: Response,
    file: Annotated[UploadFile, File(description="Audio file to transcribe")],
    model: Annotated[WhisperModelName | None, Form()] = None,
    language: Annotated[
        Language | None, Form(description="Spoken language, e.g. urdu, hindi, english (ISO codes like `ur` also work). Omit to auto-detect.")
    ] = None,
):
    suffix = validate_extension(file.filename or "", settings)
    job_id = uuid.uuid4()
    audio_path = await save_upload(file, job_id, suffix, settings)

    job = TranscriptionJob(
        id=job_id,
        original_filename=(file.filename or "")[:255],
        audio_path=str(audio_path),
        model=model.value if model else settings.default_model,
        requested_language=language.code if language else None,
    )
    db.add(job)
    db.commit()

    try:
        enqueue(str(job_id))
    except Exception:
        log.exception("Failed to enqueue job %s", job_id)
        job.status = JobStatus.failed
        job.error = "Could not enqueue job: queue unavailable"
        audio_path.unlink(missing_ok=True)
        job.audio_path = None
        db.commit()
        raise HTTPException(status.HTTP_503_SERVICE_UNAVAILABLE, "Job queue is unavailable, try again later")

    response.headers["Location"] = f"/transcriptions/{job_id}"
    return JobCreated(id=job.id, status=job.status)


@app.get("/transcriptions", response_model=JobList)
def list_transcriptions(
    db: DbSession,
    status_filter: Annotated[JobStatus | None, Query(alias="status")] = None,
    limit: Annotated[int, Query(ge=1, le=100)] = 20,
    offset: Annotated[int, Query(ge=0)] = 0,
):
    query = select(TranscriptionJob)
    count_query = select(func.count()).select_from(TranscriptionJob)
    if status_filter:
        query = query.where(TranscriptionJob.status == status_filter)
        count_query = count_query.where(TranscriptionJob.status == status_filter)

    items = db.scalars(query.order_by(TranscriptionJob.created_at.desc()).limit(limit).offset(offset)).all()
    return JobList(items=items, total=db.scalar(count_query), limit=limit, offset=offset)


@app.get("/transcriptions/{job_id}", response_model=JobDetail)
def get_transcription(job_id: uuid.UUID, db: DbSession):
    return _get_job_or_404(db, job_id)


@app.get("/transcriptions/{job_id}/text", response_class=Response, responses={200: {"content": {"text/plain": {}}}})
def get_transcription_text(job_id: uuid.UUID, db: DbSession):
    job = _get_job_or_404(db, job_id)
    if job.status != JobStatus.completed:
        raise HTTPException(status.HTTP_409_CONFLICT, f"Transcript not ready (status: {job.status.value})")
    return Response(job.text or "", media_type="text/plain; charset=utf-8")


@app.delete("/transcriptions/{job_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_transcription(job_id: uuid.UUID, db: DbSession):
    job = _get_job_or_404(db, job_id)
    if job.status == JobStatus.processing:
        raise HTTPException(status.HTTP_409_CONFLICT, "Job is currently processing, try again once it finishes")
    if job.audio_path:
        Path(job.audio_path).unlink(missing_ok=True)
    db.delete(job)
    db.commit()


@app.get("/languages", response_model=list[LanguageInfo])
def list_languages():
    """Languages that can be passed as `language` when creating a transcription."""
    return sorted((LanguageInfo(code=code, name=name) for code, name in LANGUAGES.items()), key=lambda l: l.name)


@app.get("/health")
def health(db: DbSession):
    checks = {}
    try:
        db.execute(text("SELECT 1"))
        checks["database"] = "ok"
    except Exception:
        checks["database"] = "unavailable"
    try:
        queue.get_redis().ping()
        checks["redis"] = "ok"
    except Exception:
        checks["redis"] = "unavailable"

    healthy = all(v == "ok" for v in checks.values())
    return JSONResponse(
        {"status": "ok" if healthy else "degraded", **checks},
        status_code=status.HTTP_200_OK if healthy else status.HTTP_503_SERVICE_UNAVAILABLE,
    )
