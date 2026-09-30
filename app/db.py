from collections.abc import Iterator

from sqlalchemy import create_engine
from sqlalchemy.orm import DeclarativeBase, Session, sessionmaker

from app.config import get_settings


class Base(DeclarativeBase):
    pass


def _make_engine(url: str, connect_timeout: int):
    if url.startswith("sqlite"):
        connect_args = {"check_same_thread": False}
    else:
        # Fail fast instead of hanging requests when Postgres is unreachable.
        connect_args = {"connect_timeout": connect_timeout}
    return create_engine(url, pool_pre_ping=True, connect_args=connect_args)


engine = _make_engine(get_settings().database_url, get_settings().db_connect_timeout_seconds)
SessionLocal = sessionmaker(bind=engine, expire_on_commit=False)


def get_db() -> Iterator[Session]:
    """FastAPI dependency: one session per request."""
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()

