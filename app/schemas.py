import uuid
from datetime import datetime
from enum import Enum

from pydantic import BaseModel, ConfigDict, computed_field

from app.languages import language_name
from app.models import JobStatus


class WhisperModelName(str, Enum):
    tiny = "tiny"
    base = "base"
    small = "small"
    medium = "medium"
    large_v3 = "large-v3"


class Segment(BaseModel):
    start: float
    end: float
    text: str


class JobCreated(BaseModel):
    id: uuid.UUID
    status: JobStatus


class JobSummary(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: uuid.UUID
    status: JobStatus
    original_filename: str
    model: str
    created_at: datetime
    finished_at: datetime | None


class LanguageInfo(BaseModel):
    code: str
    name: str


class JobDetail(JobSummary):
    requested_language: str | None
    detected_language: str | None
    language_probability: float | None

    @computed_field
    @property
    def requested_language_name(self) -> str | None:
        return language_name(self.requested_language)

    @computed_field
    @property
    def detected_language_name(self) -> str | None:
        return language_name(self.detected_language)

    duration_seconds: float | None
    text: str | None
    segments: list[Segment] | None
    error: str | None
    attempts: int
    started_at: datetime | None


class JobList(BaseModel):
    items: list[JobSummary]
    total: int
    limit: int
    offset: int
