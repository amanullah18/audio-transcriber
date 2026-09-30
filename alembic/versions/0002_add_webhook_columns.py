"""Add webhook columns

Revision ID: 0002
Revises: 0001
Create Date: 2026-09-29
"""
from collections.abc import Sequence

import sqlalchemy as sa
from alembic import op

revision: str = "0002"
down_revision: str | None = "0001"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def upgrade() -> None:
    with op.batch_alter_table("transcription_jobs") as batch:
        batch.add_column(sa.Column("callback_url", sa.String(2048), nullable=True))
        batch.add_column(sa.Column("callback_secret", sa.String(255), nullable=True))
        batch.add_column(sa.Column("webhook_delivered_at", sa.DateTime(timezone=True), nullable=True))
        batch.add_column(sa.Column("webhook_error", sa.Text(), nullable=True))


def downgrade() -> None:
    with op.batch_alter_table("transcription_jobs") as batch:
        batch.drop_column("webhook_error")
        batch.drop_column("webhook_delivered_at")
        batch.drop_column("callback_secret")
        batch.drop_column("callback_url")
