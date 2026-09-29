import pytest
from fastapi.testclient import TestClient

from app.main import app
from tests.conftest import OTHER_API_KEY

AUDIO = {"file": ("voice.ogg", b"fake-ogg-bytes", "audio/ogg")}


@pytest.fixture
def anonymous(enqueued):
    with TestClient(app) as c:
        yield c


@pytest.mark.parametrize(
    "method, path",
    [
        ("post", "/transcriptions"),
        ("get", "/transcriptions"),
        ("get", "/transcriptions/00000000-0000-0000-0000-000000000000"),
        ("get", "/transcriptions/00000000-0000-0000-0000-000000000000/text"),
        ("delete", "/transcriptions/00000000-0000-0000-0000-000000000000"),
    ],
)
def test_job_endpoints_require_api_key(anonymous, method, path):
    resp = getattr(anonymous, method)(path)
    assert resp.status_code == 401
    assert resp.headers["WWW-Authenticate"] == "ApiKey"


def test_invalid_api_key_is_rejected(anonymous):
    assert anonymous.get("/transcriptions", headers={"X-API-Key": "wrong"}).status_code == 401


def test_public_endpoints_do_not_require_api_key(anonymous):
    assert anonymous.get("/languages").status_code == 200
    assert anonymous.get("/health").status_code in (200, 503)  # 503 here just means no local Redis


def test_clients_only_see_their_own_jobs(client):
    job_id = client.post("/transcriptions", files=AUDIO).json()["id"]
    other = {"X-API-Key": OTHER_API_KEY}

    assert client.get(f"/transcriptions/{job_id}", headers=other).status_code == 404
    assert client.delete(f"/transcriptions/{job_id}", headers=other).status_code == 404
    assert client.get("/transcriptions", headers=other).json()["total"] == 0

    assert client.get(f"/transcriptions/{job_id}").status_code == 200
    assert client.get("/transcriptions").json()["total"] == 1


def test_api_refuses_to_start_without_api_keys(monkeypatch):
    from app.config import get_settings

    monkeypatch.setattr(get_settings(), "api_keys", "")
    with pytest.raises(RuntimeError, match="API_KEYS"):
        with TestClient(app):
            pass


def test_api_refuses_to_start_with_placeholder_key(monkeypatch):
    from app.config import get_settings

    monkeypatch.setattr(get_settings(), "api_keys", "real-key,change-me")
    with pytest.raises(RuntimeError, match="placeholder"):
        with TestClient(app):
            pass
