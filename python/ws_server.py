import asyncio
import hmac
import json
import os
import time
from typing import Dict, Set
from urllib.parse import urlparse, parse_qs

import websockets
from websockets.server import WebSocketServerProtocol

JWT_SECRET = os.environ.get("JWT_SECRET", "changeme")
WS_HOST = os.environ.get("WS_HOST", "0.0.0.0")
WS_PORT = int(os.environ.get("WS_PORT", "8765"))
HTTP_PORT = int(os.environ.get("WS_HTTP_PORT", "8790"))
APP_URL = os.environ.get("APP_URL", "http://localhost")
PUBLISH_TOKEN = os.environ.get("WS_PUBLISH_TOKEN")

active_channels: Dict[str, Set[WebSocketServerProtocol]] = {}


def _b64_decode(data: str) -> bytes:
    import base64

    rem = len(data) % 4
    if rem:
        data += "=" * (4 - rem)
    return base64.urlsafe_b64decode(data.encode("utf-8"))


def verify_jwt(token: str) -> dict:
    import hashlib
    import hmac

    try:
        header_b64, payload_b64, signature_b64 = token.split(".")
    except ValueError as exc:
        raise ValueError("Malformed JWT") from exc

    signature = _b64_decode(signature_b64)
    message = f"{header_b64}.{payload_b64}".encode("utf-8")
    expected = hmac.new(JWT_SECRET.encode("utf-8"), message, hashlib.sha256).digest()
    if not hmac.compare_digest(signature, expected):
        raise ValueError("Invalid signature")

    header = json.loads(_b64_decode(header_b64))
    if header.get("alg") != "HS256":
        raise ValueError("Unsupported algorithm")
    payload = json.loads(_b64_decode(payload_b64))
    if payload.get("exp") and int(payload["exp"]) < int(time.time()):
        raise ValueError("Token expired")
    return payload


def _normalize_identifier(value: object) -> str:
    return str(value)


def is_channel_authorized(ws: WebSocketServerProtocol, channel: str) -> bool:
    claims = getattr(ws, "claims", {})
    role = claims.get("role")
    elevated_roles = {"ADMIN", "HR"}

    if channel.startswith("public:"):
        return True

    if channel.startswith("user:"):
        target = channel.split(":", 1)[1]
        if target == _normalize_identifier(claims.get("sub")):
            return True
        return role in elevated_roles

    if channel.startswith("entity:"):
        target = channel.split(":", 1)[1]
        allowed_entities = {
            _normalize_identifier(entity_id) for entity_id in claims.get("entityIds", [])
        }
        if target in allowed_entities:
            return True
        return role in elevated_roles

    return False


async def register_connection(ws: WebSocketServerProtocol, claims: dict) -> None:
    ws.claims = claims
    await subscribe(ws, f"user:{claims['sub']}")
    for entity_id in claims.get("entityIds", []):
        await subscribe(ws, f"entity:{entity_id}")


async def subscribe(ws: WebSocketServerProtocol, channel: str) -> None:
    if not is_channel_authorized(ws, channel):
        if ws.open:
            await ws.send(
                json.dumps(
                    {
                        "type": "error",
                        "error": "unauthorized_channel",
                        "channel": channel,
                    }
                )
            )
        return
    bucket = active_channels.setdefault(channel, set())
    bucket.add(ws)
    ws.subscriptions = getattr(ws, "subscriptions", set())
    ws.subscriptions.add(channel)


async def unsubscribe(ws: WebSocketServerProtocol) -> None:
    for channel in getattr(ws, "subscriptions", set()):
        subscribers = active_channels.get(channel)
        if subscribers and ws in subscribers:
            subscribers.remove(ws)
            if not subscribers:
                active_channels.pop(channel, None)


async def handle_socket(ws: WebSocketServerProtocol) -> None:
    token = None
    parsed = urlparse(ws.path)
    query = parse_qs(parsed.query)
    if "token" in query:
        token = query["token"][0]
    if not token:
        token = ws.request_headers.get("Authorization", "").replace("Bearer ", "")

    if not token:
        await ws.close(code=4401, reason="Missing token")
        return

    try:
        claims = verify_jwt(token)
    except ValueError:
        await ws.close(code=4401, reason="Invalid token")
        return

    await register_connection(ws, claims)

    try:
        async for message in ws:
            try:
                payload = json.loads(message)
            except json.JSONDecodeError:
                continue
            if payload.get("type") == "subscribe" and "channel" in payload:
                await subscribe(ws, payload["channel"])
    finally:
        await unsubscribe(ws)


async def broadcast(channel: str, event: dict) -> None:
    subscribers = active_channels.get(channel, set())
    if not subscribers:
        return
    message = json.dumps(event)
    await asyncio.gather(*[ws.send(message) for ws in subscribers if ws.open], return_exceptions=True)


async def publish_event(data: dict) -> None:
    channels = data.get("channels") or []
    event = data.get("event") or {}
    for channel in channels:
        await broadcast(channel, event)


async def start_ws_server() -> None:
    async with websockets.serve(handle_socket, WS_HOST, WS_PORT, process_request=None):
        await asyncio.Future()


async def http_handler(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
    request = await reader.readline()
    if not request:
        writer.close()
        await writer.wait_closed()
        return
    method, path, _ = request.decode().split(" ")
    headers = {}
    while True:
        line = await reader.readline()
        if line in (b"\r\n", b"\n", b""):
            break
        key, value = line.decode().split(":", 1)
        headers[key.strip().lower()] = value.strip()

    body = b""
    if "content-length" in headers:
        length = int(headers["content-length"])
        body = await reader.readexactly(length)

    if method != "POST" or path != "/publish":
        writer.write(b"HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\n\r\n")
        await writer.drain()
        writer.close()
        await writer.wait_closed()
        return

    auth_header = headers.get("authorization", "")
    token = auth_header.replace("Bearer ", "")
    if not token:
        writer.write(b"HTTP/1.1 401 Unauthorized\r\nContent-Length: 0\r\n\r\n")
        await writer.drain()
        writer.close()
        await writer.wait_closed()
        return

    publish_token = headers.get("x-publish-token", "")
    if not PUBLISH_TOKEN or not publish_token or not hmac.compare_digest(
        publish_token, PUBLISH_TOKEN
    ):
        writer.write(b"HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\n\r\n")
        await writer.drain()
        writer.close()
        await writer.wait_closed()
        return

    try:
        verify_jwt(token)
        payload = json.loads(body.decode())
    except Exception:
        writer.write(b"HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\n\r\n")
        await writer.drain()
        writer.close()
        await writer.wait_closed()
        return

    await publish_event(payload)
    writer.write(b"HTTP/1.1 202 Accepted\r\nContent-Length: 2\r\n\r\nOK")
    await writer.drain()
    writer.close()
    await writer.wait_closed()


async def main() -> None:
    ws_task = asyncio.create_task(start_ws_server())
    http_server = await asyncio.start_server(http_handler, WS_HOST, HTTP_PORT)
    print(f"WebSocket listening on ws://{WS_HOST}:{WS_PORT}")
    print(f"Publish endpoint listening on http://{WS_HOST}:{HTTP_PORT}/publish")
    async with http_server:
        await asyncio.gather(ws_task, http_server.serve_forever())


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        pass
