/**
 * API base URL resolution:
 * - Uses window.API_BASE if set (e.g., for custom deployments)
 * - Uses the hosted API when running inside a bundled Capacitor app
 * - Falls back to WEBSITE_API_BASE (/api) for the normal website
 * - Automatic fallback to /api/index.php applies when API_BASE ends with /api
 */
const WEBSITE_API_BASE = '/api';
const NATIVE_API_BASE = 'https://ncp.nixorcorporate.com/api';
const NATIVE_PLATFORMS = new Set(['ios', 'android']);

export function isNativeRuntime() {
  const capacitor = window.Capacitor;
  if (!capacitor) {
    return window.location.protocol === 'capacitor:';
  }
  if (typeof capacitor.isNativePlatform === 'function') {
    return capacitor.isNativePlatform();
  }
  if (typeof capacitor.getPlatform === 'function') {
    return NATIVE_PLATFORMS.has(capacitor.getPlatform());
  }
  return window.location.protocol === 'capacitor:';
}

function normalizeApiBase(base) {
  return String(base || WEBSITE_API_BASE).replace(/\/+$/, '');
}

const API_BASE = normalizeApiBase(window.API_BASE || (isNativeRuntime() ? NATIVE_API_BASE : WEBSITE_API_BASE));
let preferredBase = API_BASE;
let portalConfig = {
  ws_url: window.WS_URL || '',
  ws_token: window.WS_TOKEN || '',
  poll_interval: 8
};
let csrfToken = '';
let csrfBootstrapPromise = null;

export function setCsrfToken(token) {
  csrfToken = token || '';
}

export function getCsrfToken() {
  return csrfToken;
}

export function setTheme(themeName) {
  if (!themeName || !themeName.startsWith('theme-')) {
    return false;
  }
  try {
    const docEl = document.documentElement;
    const currentTheme = Array.from(docEl.classList).find(c => c.startsWith('theme-') && c !== 'theme-default');
    if (currentTheme) {
      docEl.classList.remove(currentTheme);
    }
    if (themeName !== 'theme-default') {
      docEl.classList.add(themeName);
    }
    localStorage.setItem('nixor_theme', themeName);
    return true;
  } catch (err) {
    console.warn('Failed to set theme:', err);
    return false;
  }
}

function resolveFallbackBase(base) {
  const trimmed = base.replace(/\/+$/, '');
  if (trimmed.endsWith('/api')) {
    return `${trimmed}/index.php`;
  }
  return null;
}

function isAbsoluteUrl(value) {
  return /^[a-z][a-z\d+\-.]*:/i.test(value);
}

function normalizeApiPath(path) {
  const value = String(path || '');
  if (!value || value === '/') {
    return '';
  }
  if (value === '/api') {
    return '';
  }
  if (value.startsWith('/api/')) {
    return value.slice(4);
  }
  if (value.startsWith('api/')) {
    return `/${value.slice(4)}`;
  }
  return value.startsWith('/') ? value : `/${value}`;
}

export function getApiBase() {
  return preferredBase;
}

export function buildApiUrl(path, base = preferredBase) {
  const value = String(path || '');
  if (isAbsoluteUrl(value)) {
    return value;
  }
  return `${base}${normalizeApiPath(value)}`;
}

export async function apiFetch(path, options = {}) {
  const { skipFallback, ...fetchOptions } = options;
  const method = (fetchOptions.method || 'GET').toUpperCase();
  if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method) && !getCsrfToken()) {
    await bootstrapCsrf();
  }
  const resolvedCsrf = getCsrfToken();
  const headers = {
    ...(fetchOptions.headers || {})
  };
  const isFormData = fetchOptions.body instanceof FormData;
  if (['POST', 'PUT', 'PATCH'].includes(method) && !headers['Content-Type'] && !isFormData) {
    headers['Content-Type'] = 'application/json';
  }
  if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method) && resolvedCsrf) {
    headers['X-CSRF-Token'] = resolvedCsrf;
  }
  const request = async (base) => {
    const url = buildApiUrl(path, base);
    try {
      const res = await fetch(url, {
        ...fetchOptions,
        headers,
        credentials: 'include',
        method
      });
      const text = await res.text();
      let data = null;
      if (text) {
        try {
          data = JSON.parse(text);
        } catch (err) {
          console.warn(`Failed to parse JSON response from ${url}:`, err);
        }
      }
      if (!res.ok) {
        console.warn(`API request failed ${method} ${url} -> ${res.status}`, {
          status: res.status,
          body: text.slice(0, 200)
        });
      }
      return { res, data, text, url };
    } catch (err) {
      console.warn(`API request error ${method} ${url}`, err);
      throw err;
    }
  };

  let { res, data, text, url } = await request(preferredBase);
  const fallbackBase = resolveFallbackBase(preferredBase);
  const contentType = res.headers.get('content-type') || '';
  const hasJson = contentType.includes('application/json');
  const shouldFallback = !skipFallback
    && fallbackBase
    && !res.ok
    && (
      res.status === 404
      || res.status === 405
      || (!hasJson && res.status >= 400 && res.status < 500)
    );
  if (shouldFallback) {
    console.warn(`API base fallback triggered for ${path}; retrying ${fallbackBase}`);
    const initialError = data?.error;
    const initialStatus = res.status;
    const fallbackResponse = await request(fallbackBase);
    res = fallbackResponse.res;
    data = fallbackResponse.data;
    text = fallbackResponse.text;
    url = fallbackResponse.url;
    if (res.ok) {
      preferredBase = fallbackBase;
    } else {
      console.warn(
        `API fallback failed for ${path}; initial status ${initialStatus}, fallback status ${res.status}.`
      );
      throw buildApiError(initialError || `HTTP ${initialStatus}`, initialStatus, url, text);
    }
  }
  if (!res.ok) {
    const message = data?.error || `HTTP ${res.status}`;
    throw buildApiError(message, res.status, url, text);
  }
  return data;
}

function buildApiError(message, status, url, body) {
  const error = new Error(message || `HTTP ${status}`);
  error.status = status;
  error.url = url;
  error.bodySnippet = (body || '').slice(0, 200);
  return error;
}

export const normalizeError = (err) => {
  const message = err?.message || '';
  if (err?.status >= 500 || /^HTTP 5\d\d$/.test(message)) {
    return 'Something went wrong. Please try again.';
  }
  return message === 'Forbidden' ? 'You do not have permission.' : (message || 'Action failed.');
};

export async function bootstrapCsrf() {
  if (csrfBootstrapPromise) {
    return csrfBootstrapPromise;
  }
  const request = async (base) => {
    const res = await fetch(`${base}/auth/csrf`, {
      credentials: 'include',
      headers: { 'Accept': 'application/json' }
    });
    const data = await res.json().catch(() => ({}));
    return { res, data };
  };
  csrfBootstrapPromise = (async () => {
    try {
      let { res, data } = await request(preferredBase);
      const fallbackBase = resolveFallbackBase(preferredBase);
      const shouldFallback = fallbackBase && !res.ok && res.status === 404;
      if (shouldFallback) {
        const fallbackResponse = await request(fallbackBase);
        res = fallbackResponse.res;
        data = fallbackResponse.data;
        if (res.ok) {
          preferredBase = fallbackBase;
        }
      }
      if (!res.ok) {
        throw buildApiError(data?.error || `HTTP ${res.status}`, res.status, `${preferredBase}/auth/csrf`);
      }
      setCsrfToken(data?.data?.csrfToken || data?.data?.token || '');
      return getCsrfToken();
    } finally {
      csrfBootstrapPromise = null;
    }
  })();
  return csrfBootstrapPromise;
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
  let isPolling = false;
  const poll = async () => {
    if (isPolling) {
      return;
    }
    isPolling = true;
    try {
      const response = await apiFetch(`/updates?since=${lastEventId}`);
      const events = response?.data?.events || [];
      events.forEach((event) => onEvent?.(event, response?.data?.related || {}));
      lastEventId = response?.data?.last_event_id || lastEventId;
    } catch (err) {
      console.warn('Polling updates failed', err);
    } finally {
      isPolling = false;
      pollingTimer = setTimeout(poll, pollInterval * 1000);
    }
  };

  const socket = connectWebsocket((data) => {
    onEvent?.(data, {});
  });

  if (!socket) {
    poll();
    return () => {
      clearTimeout(pollingTimer);
      isPolling = false;
    };
  }

  const fallbackTimer = setTimeout(() => {
    if (socket.readyState !== WebSocket.OPEN) {
      poll();
    }
  }, 3000);

  socket.addEventListener('open', () => {
    clearTimeout(fallbackTimer);
    isPolling = false;
    clearTimeout(pollingTimer);
  });
  socket.addEventListener('close', () => {
    clearTimeout(pollingTimer);
    poll();
  });
  socket.addEventListener('error', () => {
    clearTimeout(pollingTimer);
    poll();
  });
  return () => {
    clearTimeout(fallbackTimer);
    clearTimeout(pollingTimer);
    isPolling = false;
    socket.close();
  };
}
