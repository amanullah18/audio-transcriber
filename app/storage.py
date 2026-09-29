import uuid
from pathlib import Path

from fastapi import HTTPException, UploadFile, status

from app.config import Settings
from app.models import TranscriptionJob

CHUNK_SIZE = 1024 * 1024


def delete_job_audio(job: TranscriptionJob, settings: Settings) -> None:
    """Uploaded audio is personal data: drop it once the job reaches a terminal state."""
    if settings.delete_audio_after_processing and job.audio_path:
        Path(job.audio_path).unlink(missing_ok=True)
        job.audio_path = None


def validate_extension(filename: str, settings: Settings) -> str:
    suffix = Path(filename).suffix.lower()
    if suffix not in settings.allowed_extensions:
        raise HTTPException(
            status.HTTP_415_UNSUPPORTED_MEDIA_TYPE,
            f"Unsupported file type '{suffix or '(none)'}'. Allowed: {', '.join(sorted(settings.allowed_extensions))}",
        )
    return suffix


async def save_upload(upload: UploadFile, job_id: uuid.UUID, suffix: str, settings: Settings) -> Path:
    """Stream the upload to disk in chunks, rejecting it as soon as it exceeds the size limit."""
    settings.upload_dir.mkdir(parents=True, exist_ok=True)
    dest = settings.upload_dir / f"{job_id}{suffix}"
    written = 0
    try:
        with dest.open("wb") as out:
            while chunk := await upload.read(CHUNK_SIZE):
                written += len(chunk)
                if written > settings.max_upload_bytes:
                    raise HTTPException(
                        status.HTTP_413_CONTENT_TOO_LARGE, f"File exceeds the {settings.max_upload_mb} MB limit"
                    )
                out.write(chunk)
    except BaseException:
        dest.unlink(missing_ok=True)
        raise
    if written == 0:
        dest.unlink(missing_ok=True)
        raise HTTPException(status.HTTP_400_BAD_REQUEST, "Uploaded file is empty")
    return dest
