const API_BASE = '/api';
const WS_URL = window.WS_URL || 'ws://localhost:8765';

export async function apiFetch(path, options = {}) {
  const res = await fetch(`${API_BASE}${path}`, {
    headers: {
      'Content-Type': 'application/json',
      ...(options.headers || {})
    },
    credentials: 'include',
    ...options
  });
  return res.json();
}

export function connectWebsocket(onMessage) {
  try {
    const socket = new WebSocket(WS_URL);
    socket.addEventListener('message', (event) => {
      try {
        const data = JSON.parse(event.data);
        onMessage?.(data);
      } catch (err) {
        console.warn('WS parse error', err);
      }
    });
    return socket;
  } catch (err) {
    console.warn('WS connection failed', err);
    return null;
  }
}
