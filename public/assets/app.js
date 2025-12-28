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
let portalConfig = {
  ws_url: window.WS_URL || '',
  ws_token: window.WS_TOKEN || '',
  poll_interval: 8
};
let csrfToken = '';

export function setCsrfToken(token) {
  csrfToken = token || '';
}

export function getCsrfToken() {
  const csrfMatch = document.cookie.match(/(?:^|; )csrf_token=([^;]+)/);
  const cookieToken = csrfMatch ? decodeURIComponent(csrfMatch[1]) : '';
  return cookieToken || csrfToken;
}

export async function apiFetch(path, options = {}) {
  const { skipFallback, ...fetchOptions } = options;
  const method = (fetchOptions.method || 'GET').toUpperCase();
  const resolvedCsrf = getCsrfToken();
  const headers = {
    ...(fetchOptions.headers || {})
  };
  if (['POST', 'PUT', 'PATCH'].includes(method) && !headers['Content-Type']) {
    headers['Content-Type'] = 'application/json';
  }
  if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method) && resolvedCsrf) {
    headers['X-CSRF-Token'] = resolvedCsrf;
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
  const wsUrl = portalConfig.ws_url;
  const wsToken = portalConfig.ws_token || '';
  if (!wsUrl) {
    return null;
  }
  let socket;
  let retries = 0;
  let shouldReconnect = true;
  const wsEndpoint = wsToken ? `${wsUrl}?token=${encodeURIComponent(wsToken)}` : wsUrl;

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

export async function loadConfig() {
  try {
    const response = await apiFetch('/config', { skipFallback: true });
    portalConfig = {
      ...portalConfig,
      ...(response?.data || {})
    };
  } catch (err) {
    console.warn('Failed to load config', err);
  }
  return portalConfig;
}

export function getConfig() {
  return portalConfig;
}

export async function subscribeUpdates(onEvent) {
  await loadConfig();
  const pollInterval = Math.max(4, parseInt(portalConfig.poll_interval, 10) || 8);
  let lastEventId = 0;
  let pollingTimer;
  const poll = async () => {
    try {
      const response = await apiFetch(`/updates?since=${lastEventId}`);
      const events = response?.data?.events || [];
      events.forEach((event) => onEvent?.(event, response?.data?.related || {}));
      lastEventId = response?.data?.last_event_id || lastEventId;
    } catch (err) {
      console.warn('Polling updates failed', err);
    } finally {
      pollingTimer = setTimeout(poll, pollInterval * 1000);
    }
  };

  const socket = connectWebsocket((data) => {
    onEvent?.(data, {});
  });

  if (!socket) {
    poll();
    return () => clearTimeout(pollingTimer);
  }

  const fallbackTimer = setTimeout(() => {
    if (socket.readyState !== WebSocket.OPEN) {
      poll();
    }
  }, 3000);

  socket.addEventListener('open', () => clearTimeout(fallbackTimer));
  socket.addEventListener('close', () => poll());
  socket.addEventListener('error', () => poll());
  return () => {
    clearTimeout(fallbackTimer);
    clearTimeout(pollingTimer);
    socket.close();
  };
}
