"""Create transcription_jobs table

Revision ID: 0001
Revises:
Create Date: 2026-09-29
"""
from collections.abc import Sequence

import sqlalchemy as sa
from alembic import op

revision: str = "0001"
down_revision: str | None = None
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

job_status = sa.Enum("queued", "processing", "completed", "failed", name="job_status")


def upgrade() -> None:
    op.create_table(
        "transcription_jobs",
        sa.Column("id", sa.Uuid(), primary_key=True),
        sa.Column("status", job_status, nullable=False),
        sa.Column("owner", sa.String(64), nullable=False),
        sa.Column("rq_job_id", sa.String(64), nullable=True),
        sa.Column("original_filename", sa.String(255), nullable=False),
        sa.Column("audio_path", sa.String(512), nullable=True),
        sa.Column("model", sa.String(32), nullable=False),
        sa.Column("requested_language", sa.String(8), nullable=True),
        sa.Column("detected_language", sa.String(8), nullable=True),
        sa.Column("language_probability", sa.Float(), nullable=True),
        sa.Column("duration_seconds", sa.Float(), nullable=True),
        sa.Column("text", sa.Text(), nullable=True),
        sa.Column("segments", sa.JSON(), nullable=True),
        sa.Column("error", sa.Text(), nullable=True),
        sa.Column("attempts", sa.Integer(), nullable=False),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("started_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("finished_at", sa.DateTime(timezone=True), nullable=True),
    )
    op.create_index("ix_transcription_jobs_status", "transcription_jobs", ["status"])
    op.create_index("ix_transcription_jobs_owner", "transcription_jobs", ["owner"])
    op.create_index("ix_transcription_jobs_created_at", "transcription_jobs", ["created_at"])


def downgrade() -> None:
    op.drop_table("transcription_jobs")
    job_status.drop(op.get_bind(), checkfirst=True)
