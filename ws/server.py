import asyncio
import json
import logging
import os
from pathlib import Path
from urllib.parse import urlparse, parse_qs

import websockets

QUEUE_FILE = os.getenv("WS_QUEUE_FILE", str(Path(__file__).parent / "events.queue"))
HOST = os.getenv("WS_HOST", "127.0.0.1")
_raw_port = os.getenv("WS_PORT", "8765")
try:
    PORT = int(_raw_port)
except (TypeError, ValueError):
    logging.error("Invalid WS_PORT value: %s", _raw_port)
    raise SystemExit(1)
WS_TOKEN = os.getenv("WS_TOKEN", "")

clients = set()

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")

async def register(websocket):
    clients.add(websocket)
    logging.info("Client connected (%s total)", len(clients))
    await websocket.send(json.dumps({"event": "connected", "payload": {"clients": len(clients)}}))

async def unregister(websocket):
    clients.discard(websocket)
    logging.info("Client disconnected (%s total)", len(clients))

async def broadcast(message: str):
    if not clients:
        return
    await asyncio.gather(*[client.send(message) for client in clients], return_exceptions=True)

async def tail_queue():
    file = None
    inode = None
    while True:
        try:
            Path(QUEUE_FILE).parent.mkdir(parents=True, exist_ok=True)
            Path(QUEUE_FILE).touch(exist_ok=True)
            stat = Path(QUEUE_FILE).stat()
            if file is None or inode != stat.st_ino:
                if file:
                    file.close()
                file = open(QUEUE_FILE, "r", encoding="utf-8")
                file.seek(0, os.SEEK_END)
                inode = stat.st_ino
                logging.info("Opened queue file: %s", QUEUE_FILE)

            line = file.readline()
            if not line:
                await asyncio.sleep(0.5)
                continue
            await broadcast(line.strip())
        except Exception as exc:
            logging.error("Error tailing queue: %s", exc)
            await asyncio.sleep(1.0)

async def handler(websocket):
    if WS_TOKEN:
        params = parse_qs(urlparse(websocket.path).query)
        token = params.get("token", [""])[0]
        if token != WS_TOKEN:
            await websocket.close(code=1008, reason="Unauthorized")
            return
    await register(websocket)
    try:
        async for _ in websocket:
            # Broadcast-only server: client messages are ignored.
            continue
    finally:
        await unregister(websocket)

async def main():
    logging.info("WebSocket server starting on %s:%s", HOST, PORT)
    async with websockets.serve(handler, HOST, PORT):
        await tail_queue()

if __name__ == "__main__":
    asyncio.run(main())
