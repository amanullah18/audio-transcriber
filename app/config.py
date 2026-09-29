from functools import lru_cache
from pathlib import Path

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """Runtime configuration, read from environment variables (or a .env file)."""

    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    # Comma-separated. Each key only sees the jobs it created. Required: the API refuses to start without one.
    api_keys: str = ""

    database_url: str = "postgresql+psycopg://transcriber:transcriber@localhost:5432/transcriber"
    db_connect_timeout_seconds: int = 5
    redis_url: str = "redis://localhost:6379/0"
    redis_connect_timeout_seconds: float = 2
    redis_socket_timeout_seconds: float = 5
    queue_name: str = "transcriptions"

    upload_dir: Path = Path("data/uploads")
    max_upload_mb: int = 50
    allowed_extensions: set[str] = {".ogg", ".opus", ".mp3", ".m4a", ".wav", ".flac", ".webm", ".mp4"}

    default_model: str = "small"
    job_timeout_seconds: int = 3600
    job_max_retries: int = 2
    delete_audio_after_processing: bool = True

    # A job still "processing" this long after its timeout has lost its worker (crash, OOM kill, redeploy).
    stale_job_grace_seconds: int = 300
    # How often the API scans for orphaned jobs. 0 disables the scan.
    reaper_interval_seconds: int = 60

    @property
    def api_key_list(self) -> list[str]:
        return [k.strip() for k in self.api_keys.split(",") if k.strip()]

    @property
    def stale_after_seconds(self) -> int:
        return self.job_timeout_seconds + self.stale_job_grace_seconds

    @property
    def max_upload_bytes(self) -> int:
        return self.max_upload_mb * 1024 * 1024


@lru_cache
def get_settings() -> Settings:
    return Settings()
