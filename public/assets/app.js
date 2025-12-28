/**
 * API base URL resolution:
 * - Uses window.API_BASE if set (e.g., for custom deployments)
 * - Falls back to DEFAULT_API_BASE (/api)
 * - Automatic fallback to /api/index.php only applies when using DEFAULT_API_BASE
 */
const DEFAULT_API_BASE = '/api';
const FALLBACK_API_BASE = '/api/index.php';
const API_BASE = window.API_BASE || DEFAULT_API_BASE;
let preferredBase = API_BASE;
const WS_URL = window.WS_URL || 'ws://localhost:8765';
const WS_TOKEN = window.WS_TOKEN || '';

export async function apiFetch(path, options = {}) {
  const { skipFallback, ...fetchOptions } = options;
  const method = (fetchOptions.method || 'GET').toUpperCase();
  const csrfMatch = document.cookie.match(/(?:^|; )csrf_token=([^;]+)/);
  const csrfToken = csrfMatch ? decodeURIComponent(csrfMatch[1]) : '';
  const headers = {
    ...(fetchOptions.headers || {})
  };
  if (['POST', 'PUT', 'PATCH'].includes(method) && !headers['Content-Type']) {
    headers['Content-Type'] = 'application/json';
  }
  if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method) && csrfToken) {
    headers['X-CSRF-Token'] = csrfToken;
  }
  const request = async (base) => {
    const res = await fetch(`${base}${path}`, {
      ...fetchOptions,
      headers,
      credentials: 'include',
      method
    });
    const data = await res.json().catch((err) => {
      console.warn(`Failed to parse JSON response from ${base}${path}:`, err);
      return {};
    });
    return { res, data };
  };

  let { res, data } = await request(preferredBase);
  const shouldFallback = !skipFallback
    && preferredBase === DEFAULT_API_BASE
    && !res.ok
    && res.status === 404;
  if (shouldFallback) {
    console.warn(`API base fallback triggered for ${path}; retrying ${FALLBACK_API_BASE}`);
    const initialError = data.error;
    const initialStatus = res.status;
    const fallbackResponse = await request(FALLBACK_API_BASE);
    res = fallbackResponse.res;
    data = fallbackResponse.data;
    if (res.ok) {
      preferredBase = FALLBACK_API_BASE;
    } else {
      console.warn(
        `API fallback failed for ${path}; initial status ${initialStatus}, fallback status ${res.status}.`
      );
      throw new Error(initialError || `HTTP ${initialStatus}`);
    }
  }
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
