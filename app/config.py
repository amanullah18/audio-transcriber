from functools import lru_cache
from pathlib import Path

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """Runtime configuration, read from environment variables (or a .env file)."""

    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    database_url: str = "postgresql+psycopg://transcriber:transcriber@localhost:5432/transcriber"
    redis_url: str = "redis://localhost:6379/0"
    queue_name: str = "transcriptions"

    upload_dir: Path = Path("data/uploads")
    max_upload_mb: int = 50
    allowed_extensions: set[str] = {".ogg", ".opus", ".mp3", ".m4a", ".wav", ".flac", ".webm", ".mp4"}

    default_model: str = "small"
    job_timeout_seconds: int = 3600
    job_max_retries: int = 2
    delete_audio_after_processing: bool = True

    @property
    def max_upload_bytes(self) -> int:
        return self.max_upload_mb * 1024 * 1024


@lru_cache
def get_settings() -> Settings:
    return Settings()
