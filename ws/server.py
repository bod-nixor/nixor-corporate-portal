import asyncio
import json
import os
from pathlib import Path

import websockets

QUEUE_FILE = os.getenv("WS_QUEUE_FILE", str(Path(__file__).parent / "events.queue"))
HOST = os.getenv("WS_HOST", "0.0.0.0")
PORT = int(os.getenv("WS_PORT", "8765"))

clients = set()

async def register(websocket):
    clients.add(websocket)
    await websocket.send(json.dumps({"event": "connected", "payload": {"clients": len(clients)}}))

async def unregister(websocket):
    clients.discard(websocket)

async def broadcast(message: str):
    if not clients:
        return
    await asyncio.gather(*[client.send(message) for client in clients], return_exceptions=True)

async def tail_queue():
    Path(QUEUE_FILE).parent.mkdir(parents=True, exist_ok=True)
    open(QUEUE_FILE, "a").close()
    with open(QUEUE_FILE, "r", encoding="utf-8") as file:
        file.seek(0, os.SEEK_END)
        while True:
            line = file.readline()
            if not line:
                await asyncio.sleep(0.5)
                continue
            await broadcast(line.strip())

async def handler(websocket):
    await register(websocket)
    try:
        async for _ in websocket:
            pass
    finally:
        await unregister(websocket)

async def main():
    async with websockets.serve(handler, HOST, PORT):
        await tail_queue()

if __name__ == "__main__":
    asyncio.run(main())
