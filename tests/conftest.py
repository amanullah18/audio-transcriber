import os
import tempfile
from pathlib import Path

# Must be set before any `app` module is imported, since the engine is created at import time.
_tmp = Path(tempfile.mkdtemp(prefix="transcriber-tests-"))
os.environ["DATABASE_URL"] = f"sqlite:///{(_tmp / 'test.db').as_posix()}"
os.environ["UPLOAD_DIR"] = str(_tmp / "uploads")
os.environ["MAX_UPLOAD_MB"] = "1"

import pytest  # noqa: E402
from fastapi.testclient import TestClient  # noqa: E402

from app.db import Base, SessionLocal, engine  # noqa: E402
from app.main import app, get_enqueuer  # noqa: E402


@pytest.fixture(autouse=True)
def clean_db():
    Base.metadata.create_all(bind=engine)
    yield
    Base.metadata.drop_all(bind=engine)


@pytest.fixture
def enqueued():
    """Replaces the Redis queue with a list that records enqueued job ids."""
    calls: list[str] = []
    app.dependency_overrides[get_enqueuer] = lambda: calls.append
    yield calls
    app.dependency_overrides.pop(get_enqueuer, None)


@pytest.fixture
def client(enqueued):
    with TestClient(app) as c:
        yield c


@pytest.fixture
def db():
    with SessionLocal() as session:
        yield session
