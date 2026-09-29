import os
import tempfile
from pathlib import Path

# Must be set before any `app` module is imported, since the engine is created at import time.
# Tests run on SQLite by default; set TEST_DATABASE_URL to run them against Postgres (CI does).
_tmp = Path(tempfile.mkdtemp(prefix="transcriber-tests-"))
os.environ["DATABASE_URL"] = os.environ.get("TEST_DATABASE_URL") or f"sqlite:///{(_tmp / 'test.db').as_posix()}"
os.environ["UPLOAD_DIR"] = str(_tmp / "uploads")
os.environ["MAX_UPLOAD_MB"] = "1"
os.environ["API_KEYS"] = "test-key,other-key"
os.environ["REAPER_INTERVAL_SECONDS"] = "0"  # tests call the reaper directly

import pytest  # noqa: E402
from fastapi.testclient import TestClient  # noqa: E402

from app.db import Base, SessionLocal, engine  # noqa: E402
from app.main import app, get_enqueuer  # noqa: E402

API_KEY = "test-key"
OTHER_API_KEY = "other-key"


@pytest.fixture(scope="session", autouse=True)
def schema():
    # Created once: dropping and recreating per test would also recreate Postgres enum types, whose new
    # OIDs break type caches on pooled connections.
    Base.metadata.drop_all(bind=engine)
    Base.metadata.create_all(bind=engine)
    yield
    Base.metadata.drop_all(bind=engine)


@pytest.fixture(autouse=True)
def clean_db():
    yield
    with engine.begin() as conn:
        for table in reversed(Base.metadata.sorted_tables):
            conn.execute(table.delete())


@pytest.fixture
def enqueued():
    """Replaces the Redis queue with a list that records enqueued job ids."""
    calls: list[str] = []

    def fake_enqueue(job_id: str) -> str:
        calls.append(job_id)
        return f"rq-{job_id}"

    app.dependency_overrides[get_enqueuer] = lambda: fake_enqueue
    yield calls
    app.dependency_overrides.pop(get_enqueuer, None)


@pytest.fixture
def client(enqueued):
    with TestClient(app, headers={"X-API-Key": API_KEY}) as c:
        yield c


@pytest.fixture
def db():
    with SessionLocal() as session:
        yield session
