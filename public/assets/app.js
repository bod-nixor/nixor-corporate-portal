const API_BASE = '/api';
const WS_URL = window.WS_URL || 'ws://localhost:8765';
const WS_TOKEN = window.WS_TOKEN || '';

export async function apiFetch(path, options = {}) {
  const method = (options.method || 'GET').toUpperCase();
  const csrfMatch = document.cookie.match(/(?:^|; )csrf_token=([^;]+)/);
  const csrfToken = csrfMatch ? decodeURIComponent(csrfMatch[1]) : '';
  const headers = {
    ...(options.headers || {})
  };
  if (['POST', 'PUT', 'PATCH'].includes(method) && !headers['Content-Type']) {
    headers['Content-Type'] = 'application/json';
  }
  if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method) && csrfToken) {
    headers['X-CSRF-Token'] = csrfToken;
  }
  const res = await fetch(`${API_BASE}${path}`, {
    ...options,
    headers,
    credentials: 'include',
    method
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new Error(data.error || `HTTP ${res.status}`);
  }
  return data;
}

export function connectWebsocket(onMessage) {
  let socket;
  let retries = 0;
  let shouldReconnect = true;
  const wsEndpoint = WS_TOKEN ? `${WS_URL}?token=${encodeURIComponent(WS_TOKEN)}` : WS_URL;

  const connect = () => {
    try {
      socket = new WebSocket(wsEndpoint);
      socket.addEventListener('open', () => {
        retries = 0;
      });
      socket.addEventListener('message', (event) => {
        try {
          const data = JSON.parse(event.data);
          onMessage?.(data);
        } catch (err) {
          console.warn('WS parse error', err);
        }
      });
      socket.addEventListener('close', () => {
        if (!shouldReconnect) return;
        const delay = Math.min(10000, 500 * 2 ** retries);
        retries += 1;
        setTimeout(connect, delay);
      });
    } catch (err) {
      console.warn('WS connection failed', err);
    }
  };

  connect();
  return socket;
}
