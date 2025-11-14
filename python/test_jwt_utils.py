import base64
import hashlib
import hmac
import json
import os
import time

from ws_server import verify_jwt


def encode(payload, secret):
    header = {"alg": "HS256", "typ": "JWT"}
    header_b64 = base64.urlsafe_b64encode(json.dumps(header).encode()).rstrip(b"=").decode()
    payload_b64 = base64.urlsafe_b64encode(json.dumps(payload).encode()).rstrip(b"=").decode()
    signature = hmac.new(secret.encode(), f"{header_b64}.{payload_b64}".encode(), hashlib.sha256).digest()
    signature_b64 = base64.urlsafe_b64encode(signature).rstrip(b"=").decode()
    return f"{header_b64}.{payload_b64}.{signature_b64}"


def test_verify_jwt_roundtrip(monkeypatch):
    secret = "unit-test-secret"
    monkeypatch.setenv("JWT_SECRET", secret)
    from importlib import reload
    import ws_server

    reload(ws_server)
    token = encode({"sub": "user123", "exp": int(time.time()) + 60}, secret)
    claims = ws_server.verify_jwt(token)
    assert claims["sub"] == "user123"


def test_verify_jwt_expired(monkeypatch):
    secret = "unit-test-secret"
    monkeypatch.setenv("JWT_SECRET", secret)
    from importlib import reload
    import ws_server

    reload(ws_server)
    token = encode({"sub": "user123", "exp": int(time.time()) - 60}, secret)
    try:
        ws_server.verify_jwt(token)
    except ValueError as exc:
        assert "expired" in str(exc)
    else:
        raise AssertionError("Expected ValueError for expired token")
