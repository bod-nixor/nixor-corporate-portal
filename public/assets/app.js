/**
 * API base URL resolution:
 * - Uses window.API_BASE if set (e.g., for custom deployments)
 * - Uses window.NATIVE_API_BASE/import.meta.env.VITE_NATIVE_API_BASE, then the hosted API,
 *   when running inside a bundled Capacitor app
 * - Falls back to WEBSITE_API_BASE (/api) for the normal website
 * - Automatic fallback to /api/index.php applies when API_BASE ends with /api
 */
const WEBSITE_API_BASE = '/api';
const DEFAULT_NATIVE_API_BASE = 'https://ncp.nixorcorporate.com/api';
const NATIVE_API_BASE = window.NATIVE_API_BASE || import.meta.env?.VITE_NATIVE_API_BASE || DEFAULT_NATIVE_API_BASE;
const NATIVE_PLATFORMS = new Set(['ios', 'android']);
const MOBILE_AUTH_CALLBACK_PROTOCOL = 'ncp:';
const MOBILE_AUTH_CALLBACK_HOST = 'auth';
const MOBILE_AUTH_CALLBACK_PATH = '/callback';
const MOBILE_AUTH_CALLBACK_SESSION_PREFIX = 'ncp_mobile_callback_';
const MOBILE_AUTH_CALLBACK_STATE_TTL_MS = 15 * 60 * 1000;
const MOBILE_TOKEN_KEY = 'ncp_mobile_token';
const MOBILE_TOKEN_EXPIRES_AT_KEY = 'ncp_mobile_token_expires_at';

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

export function getNativePlatform() {
  try {
    const platform = window.Capacitor?.getPlatform?.();
    return NATIVE_PLATFORMS.has(platform) ? platform : 'unknown';
  } catch (err) {
    return 'unknown';
  }
}

function normalizeApiBase(base) {
  return String(base || WEBSITE_API_BASE).replace(/\/+$/, '');
}

const API_BASE = normalizeApiBase(window.API_BASE || (isNativeRuntime() ? NATIVE_API_BASE : WEBSITE_API_BASE));
let preferredBase = API_BASE;
let portalConfig = {
  public_base_url: 'https://ncp.nixorcorporate.com',
  ws_url: window.WS_URL || '',
  ws_token: window.WS_TOKEN || '',
  poll_interval: 8,
  open_app_banner: {
    enabled: true,
    deep_link_scheme: 'ncp',
    universal_link_base: 'https://ncp.nixorcorporate.com',
    ios_store_url: 'https://example.com',
    android_store_url: 'https://example.com'
  },
  push: {
    registration_enabled: true,
    provider_configured: false
  }
};
let csrfToken = '';
let csrfBootstrapPromise = null;
let csrfSessionVersion = 0;
let mobileAuthListenerPromise = null;
let mobileAuthListenerHandle = null;
let mobileAuthCleanupRegistered = false;
let mobileAuthLaunchUrlChecked = false;
let mobileToken = '';
let mobileTokenExpiresAt = '';
let mobileTokenLoaded = false;
let mobileTokenLoadPromise = null;
let mobileTokenLoadGen = 0;
const mobileAuthCallbackKeysSeen = new Set();
const mobileAuthCallbackStates = new Map();
const mobileAuthExchangePromises = new Map();
const OPEN_APP_DISMISSED_KEY = 'ncp_open_app_banner_dismissed_at';
const OPEN_APP_DISMISS_MS = 30 * 24 * 60 * 60 * 1000;
const PUSH_TOKEN_KEY = 'ncp_push_token';
const PUSH_DEVICE_ID_KEY = 'ncp_push_device_id';
let pushRegistrationStarted = false;

export function setCsrfToken(token) {
  csrfToken = token || '';
}

export function getCsrfToken() {
  return csrfToken;
}

function authDebugEnabled() {
  try {
    return window.NCP_AUTH_DEBUG === true
      || window.Capacitor?.DEBUG === true
      || window.Capacitor?.isLoggingEnabled === true
      || localStorage.getItem('ncp_auth_debug') === '1'
      || new URLSearchParams(window.location.search).get('auth_debug') === '1'
      || ['localhost', '127.0.0.1'].includes(window.location.hostname);
  } catch (err) {
    return false;
  }
}

function authDebugLog(message, detail) {
  if (!authDebugEnabled()) {
    return;
  }
  if (detail === undefined) {
    console.log(message);
  } else {
    console.log(message, detail);
  }
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

function withQueryParams(url, params) {
  const absoluteUrl = new URL(url, window.location.origin);
  Object.entries(params).forEach(([key, value]) => {
    absoluteUrl.searchParams.set(key, value);
  });
  if (isAbsoluteUrl(url)) {
    return absoluteUrl.href;
  }
  return `${absoluteUrl.pathname}${absoluteUrl.search}${absoluteUrl.hash}`;
}

function getCapacitorPlugin(name) {
  const capacitor = window.Capacitor;
  if (!capacitor) {
    return null;
  }
  if (capacitor.Plugins?.[name]) {
    return capacitor.Plugins[name];
  }
  if (typeof capacitor.registerPlugin === 'function' && typeof capacitor.isPluginAvailable === 'function' && capacitor.isPluginAvailable(name)) {
    return capacitor.registerPlugin(name);
  }
  // getCapacitorPlugin intentionally falls back for known native plugins when
  // capacitor.isPluginAvailable is missing or conservative but isNativeRuntime is true.
  if (typeof capacitor.registerPlugin === 'function' && isNativeRuntime()) {
    return capacitor.registerPlugin(name);
  }
  return null;
}

function mobileTokenIsExpired(expiresAt) {
  const raw = String(expiresAt || '').trim();
  const normalized = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(raw)
    ? `${raw.replace(' ', 'T')}Z`
    : raw;
  const expiresMs = Date.parse(normalized);
  if (!Number.isFinite(expiresMs)) {
    return true;
  }
  return expiresMs <= Date.now() + 60 * 1000;
}

async function readNativePreference(key) {
  const Preferences = getCapacitorPlugin('Preferences');
  if (Preferences?.get) {
    try {
      const result = await Preferences.get({ key });
      return result?.value || '';
    } catch (err) {
      console.warn('[NCP Mobile Auth] Native preference read failed.');
    }
  }
  try {
    return localStorage.getItem(key) || '';
  } catch (err) {
    return '';
  }
}

async function writeNativePreference(key, value) {
  const Preferences = getCapacitorPlugin('Preferences');
  if (Preferences?.set) {
    try {
      await Preferences.set({ key, value });
      return;
    } catch (err) {
      console.warn('[NCP Mobile Auth] Native preference write failed.');
    }
  }
  try {
    localStorage.setItem(key, value);
  } catch (err) {
    // Storage can fail in constrained WebView contexts.
  }
}

async function removeNativePreference(key) {
  const Preferences = getCapacitorPlugin('Preferences');
  if (Preferences?.remove) {
    try {
      await Preferences.remove({ key });
      return;
    } catch (err) {
      console.warn('[NCP Mobile Auth] Native preference removal failed.');
    }
  }
  try {
    localStorage.removeItem(key);
  } catch (err) {
    // Storage cleanup is best-effort.
  }
}

export async function clearMobileAuthToken() {
  mobileTokenLoadGen += 1;
  mobileToken = '';
  mobileTokenExpiresAt = '';
  mobileTokenLoaded = true;
  await Promise.all([
    removeNativePreference(MOBILE_TOKEN_KEY),
    removeNativePreference(MOBILE_TOKEN_EXPIRES_AT_KEY)
  ]);
}

export async function setMobileAuthToken(token, expiresAt) {
  if (!isNativeRuntime()) {
    return;
  }
  const normalizedToken = String(token || '').trim();
  const normalizedExpiresAt = String(expiresAt || '').trim();
  if (!normalizedToken || mobileTokenIsExpired(normalizedExpiresAt)) {
    await clearMobileAuthToken();
    throw new Error('Mobile sign-in response did not include a valid session token.');
  }
  mobileTokenLoadGen += 1;
  mobileToken = normalizedToken;
  mobileTokenExpiresAt = normalizedExpiresAt;
  mobileTokenLoaded = true;
  await Promise.all([
    writeNativePreference(MOBILE_TOKEN_KEY, mobileToken),
    writeNativePreference(MOBILE_TOKEN_EXPIRES_AT_KEY, mobileTokenExpiresAt)
  ]);
}

export async function persistMobileAuthSession(data) {
  await setMobileAuthToken(data?.token, data?.expiresAt || data?.expires_at);
}

export async function getMobileAuthToken() {
  if (!isNativeRuntime()) {
    return '';
  }
  if (mobileTokenLoaded) {
    if (mobileToken && !mobileTokenIsExpired(mobileTokenExpiresAt)) {
      return mobileToken;
    }
    if (mobileToken) {
      await clearMobileAuthToken();
    }
    return '';
  }
  if (mobileTokenLoadPromise) {
    return mobileTokenLoadPromise;
  }
  const loadGen = mobileTokenLoadGen;
  const loadPromise = (async () => {
    try {
      const [storedToken, storedExpiresAt] = await Promise.all([
        readNativePreference(MOBILE_TOKEN_KEY),
        readNativePreference(MOBILE_TOKEN_EXPIRES_AT_KEY)
      ]);
      if (loadGen !== mobileTokenLoadGen) {
        return mobileToken && !mobileTokenIsExpired(mobileTokenExpiresAt) ? mobileToken : '';
      }
      const loadedToken = String(storedToken || '').trim();
      const loadedExpiresAt = String(storedExpiresAt || '').trim();
      mobileToken = loadedToken;
      mobileTokenExpiresAt = loadedExpiresAt;
      mobileTokenLoaded = true;
      if (!mobileToken || mobileTokenIsExpired(mobileTokenExpiresAt)) {
        mobileToken = '';
        mobileTokenExpiresAt = '';
        await Promise.all([
          removeNativePreference(MOBILE_TOKEN_KEY),
          removeNativePreference(MOBILE_TOKEN_EXPIRES_AT_KEY)
        ]);
        return '';
      }
      return mobileToken;
    } finally {
      if (loadGen === mobileTokenLoadGen) {
        mobileTokenLoadPromise = null;
      }
    }
  })();
  mobileTokenLoadPromise = loadPromise;
  return mobileTokenLoadPromise;
}

function cleanupMobileAuthListener() {
  const handle = mobileAuthListenerHandle;
  mobileAuthListenerHandle = null;
  mobileAuthListenerPromise = null;
  if (!handle?.remove) {
    return;
  }
  try {
    const removed = handle.remove();
    if (removed?.catch) {
      removed.catch(() => {});
    }
  } catch (err) {
    // Listener removal is best-effort during page unload.
  }
}

function registerMobileAuthListenerCleanup() {
  if (mobileAuthCleanupRegistered) {
    return;
  }
  mobileAuthCleanupRegistered = true;
  window.addEventListener('pagehide', cleanupMobileAuthListener);
  window.addEventListener('beforeunload', cleanupMobileAuthListener);
}

function emitMobileAuthError(message) {
  window.NCP_MOBILE_AUTH_ERROR = message;
  window.dispatchEvent(new CustomEvent('ncp:mobile-auth-error', {
    detail: { message }
  }));
}

function normalizeMobileAuthCallbackUrl(url) {
  const value = String(url || '').trim();
  return value ? value.replace(/#+$/, '') : '';
}

function parseMobileAuthCallbackUrl(url) {
  const normalized = normalizeMobileAuthCallbackUrl(url);
  if (!normalized) {
    return null;
  }
  try {
    const parsed = new URL(normalized);
    const normalizedPath = parsed.pathname.replace(/\/+$/, '') || '/';
    if (parsed.protocol === MOBILE_AUTH_CALLBACK_PROTOCOL
      && parsed.hostname === MOBILE_AUTH_CALLBACK_HOST
      && normalizedPath === MOBILE_AUTH_CALLBACK_PATH) {
      return parsed;
    }
  } catch (err) {
    // Non-URL app open events are ignored.
  }
  return null;
}

function isMobileAuthCallback(url) {
  return Boolean(parseMobileAuthCallbackUrl(url));
}

function safeMobileAuthFingerprint(value) {
  const input = String(value || '');
  let hashA = 0x811c9dc5;
  let hashB = 0x9e3779b9;
  for (let i = 0; i < input.length; i += 1) {
    const codePoint = input.charCodeAt(i);
    hashA ^= codePoint;
    hashA = Math.imul(hashA, 0x01000193);
    hashB ^= codePoint + i;
    hashB = Math.imul(hashB, 0x85ebca6b);
  }
  return `${(hashA >>> 0).toString(36)}${(hashB >>> 0).toString(36)}`;
}

function mobileAuthCallbackKeyForCode(code) {
  return `${MOBILE_AUTH_CALLBACK_SESSION_PREFIX}${safeMobileAuthFingerprint(code)}`;
}

function mobileAuthCallbackKeyForError(error) {
  return `${MOBILE_AUTH_CALLBACK_SESSION_PREFIX}error_${safeMobileAuthFingerprint(error || 'callback_error')}`;
}

function readMobileAuthCallbackState(key) {
  if (mobileAuthCallbackStates.has(key)) {
    return mobileAuthCallbackStates.get(key);
  }
  try {
    const raw = sessionStorage.getItem(key);
    if (!raw) {
      return '';
    }
    const parsed = JSON.parse(raw);
    const updatedAt = Number(parsed?.updatedAt || 0);
    if (updatedAt && Date.now() - updatedAt > MOBILE_AUTH_CALLBACK_STATE_TTL_MS) {
      sessionStorage.removeItem(key);
      return '';
    }
    return typeof parsed?.state === 'string' ? parsed.state : '';
  } catch (err) {
    return '';
  }
}

function writeMobileAuthCallbackState(key, state) {
  try {
    sessionStorage.setItem(key, JSON.stringify({ state, updatedAt: Date.now() }));
  } catch (err) {
    // Private browsing/storage restrictions should not break login.
  }
}

function clearMobileAuthCallbackState(key) {
  mobileAuthCallbackKeysSeen.delete(key);
  mobileAuthCallbackStates.delete(key);
  try {
    sessionStorage.removeItem(key);
  } catch (err) {
    // Storage cleanup is best-effort.
  }
}

function markMobileAuthCallbackState(key, state) {
  mobileAuthCallbackKeysSeen.add(key);
  mobileAuthCallbackStates.set(key, state);
  writeMobileAuthCallbackState(key, state);
}

function isMobileAuthCodeAlreadyUsed(err) {
  const message = `${err?.message || ''} ${err?.bodySnippet || ''}`;
  return err?.status === 401 && /Mobile auth code already used/i.test(message);
}

function currentPageIsDashboard() {
  return window.location.pathname.replace(/\/+$/, '') === '/dashboard.html';
}

async function verifyMobileAuthSession() {
  const response = await apiFetch('/auth/me');
  const userPresent = Boolean(response?.data?.user);
  authDebugLog(`[NCP Auth] /auth/me user present ${userPresent ? 'yes' : 'no'}`);
  if (!userPresent) {
    throw new Error('Google sign-in completed, but the mobile session could not be verified. Please sign in again.');
  }
  return response;
}

async function continueAfterVerifiedMobileAuth(callbackKey) {
  await verifyMobileAuthSession();
  markMobileAuthCallbackState(callbackKey, 'completed');
  if (!currentPageIsDashboard()) {
    window.location.replace('/dashboard.html');
  }
}

async function handleDuplicateMobileAuthCallback(callbackKey, state) {
  console.log('[NCP Mobile Auth] Duplicate callback ignored', { state: state || 'seen' });
  if (state === 'completed') {
    try {
      await continueAfterVerifiedMobileAuth(callbackKey);
    } catch (err) {
      if (!currentPageIsDashboard()) {
        emitMobileAuthError(normalizeError(err) || 'Google sign-in could not be verified. Please sign in again.');
      }
    }
  }
}

async function closeNativeBrowser() {
  const Browser = getCapacitorPlugin('Browser');
  if (!Browser?.close) {
    return;
  }
  try {
    await Browser.close();
  } catch (err) {
    const message = err?.message || '';
    if (!/No active window to close/i.test(message)) {
      console.warn('[NCP Mobile Auth] Native browser close was not available.');
    }
  }
}

async function handleMobileAuthUrl(url) {
  const parsed = parseMobileAuthCallbackUrl(url);
  if (!parsed) {
    return false;
  }
  console.log('[NCP Mobile Auth] Handling callback URL');
  const callbackError = parsed.searchParams.get('error');
  if (callbackError) {
    const safeError = callbackError.replace(/[^A-Za-z0-9_.:-]/g, '_').slice(0, 80);
    const callbackKey = mobileAuthCallbackKeyForError(safeError);
    if (mobileAuthCallbackKeysSeen.has(callbackKey) || readMobileAuthCallbackState(callbackKey)) {
      await closeNativeBrowser();
      return true;
    }
    markMobileAuthCallbackState(callbackKey, 'completed');
    await closeNativeBrowser();
    console.warn('[NCP Mobile Auth] Callback returned error:', safeError);
    emitMobileAuthError('Google sign-in could not be completed. Please try again.');
    return true;
  }

  const code = parsed.searchParams.get('code') || '';
  if (!code) {
    await closeNativeBrowser();
    emitMobileAuthError('Google sign-in returned without an authorization code. Please try again.');
    return true;
  }

  const callbackKey = mobileAuthCallbackKeyForCode(code);
  const storedState = readMobileAuthCallbackState(callbackKey);
  if (mobileAuthExchangePromises.has(callbackKey)) {
    await closeNativeBrowser();
    return true;
  }
  if (storedState === 'processing') {
    await closeNativeBrowser();
    try {
      await continueAfterVerifiedMobileAuth(callbackKey);
      return true;
    } catch (err) {
      clearMobileAuthCallbackState(callbackKey);
    }
  } else if (storedState === 'completed') {
    await closeNativeBrowser();
    await handleDuplicateMobileAuthCallback(callbackKey, storedState);
    return true;
  } else if (mobileAuthCallbackKeysSeen.has(callbackKey)) {
    clearMobileAuthCallbackState(callbackKey);
  }

  markMobileAuthCallbackState(callbackKey, 'processing');
  await closeNativeBrowser();

  const exchangePromise = (async () => {
    try {
      const exchangeResponse = await apiFetch('/auth/mobile/exchange', {
        method: 'POST',
        skipCsrf: true,
        body: JSON.stringify({ code, platform: getNativePlatform() })
      });
      await persistMobileAuthSession(exchangeResponse?.data);
      markMobileAuthCallbackState(callbackKey, 'completed');
      await continueAfterVerifiedMobileAuth(callbackKey);
    } catch (err) {
      if (isMobileAuthCodeAlreadyUsed(err)) {
        try {
          await continueAfterVerifiedMobileAuth(callbackKey);
          return;
        } catch (sessionErr) {
          emitMobileAuthError(normalizeError(sessionErr) || 'Google sign-in could not be verified. Please sign in again.');
          return;
        }
      }
      clearMobileAuthCallbackState(callbackKey);
      emitMobileAuthError(normalizeError(err) || 'Google sign-in failed. Please try again.');
    } finally {
      mobileAuthExchangePromises.delete(callbackKey);
    }
  })();
  mobileAuthExchangePromises.set(callbackKey, exchangePromise);

  return true;
}

function portalDeepLinkUrlForCurrentPage() {
  const banner = portalConfig.open_app_banner || {};
  const scheme = String(banner.deep_link_scheme || 'ncp').replace(/[^a-z0-9+.-]/gi, '') || 'ncp';
  const path = `${window.location.pathname}${window.location.search}${window.location.hash}`;
  return `${scheme}://open?path=${encodeURIComponent(path)}`;
}

function handlePortalDeepLink(url) {
  const raw = String(url || '').trim();
  if (!raw || isMobileAuthCallback(raw)) {
    return false;
  }
  try {
    const parsed = new URL(raw);
    const scheme = String(portalConfig.open_app_banner?.deep_link_scheme || 'ncp').toLowerCase();
    const configuredBase = new URL(portalConfig.open_app_banner?.universal_link_base || portalConfig.public_base_url || window.location.origin);
    let destination = '';
    if (parsed.protocol.toLowerCase() === `${scheme}:` && parsed.hostname === 'open') {
      destination = parsed.searchParams.get('path') || '';
    } else if (parsed.protocol === configuredBase.protocol && parsed.hostname === configuredBase.hostname) {
      destination = `${parsed.pathname}${parsed.search}${parsed.hash}`;
    }
    if (!destination || !destination.startsWith('/') || destination.startsWith('//')) {
      return false;
    }
    window.location.href = destination;
    return true;
  } catch (err) {
    return false;
  }
}

export async function initMobileAuthListener() {
  if (!isNativeRuntime()) {
    return false;
  }
  if (mobileAuthListenerPromise) {
    return mobileAuthListenerPromise;
  }

  mobileAuthListenerPromise = (async () => {
    const App = getCapacitorPlugin('App');
    if (!App?.addListener) {
      mobileAuthListenerPromise = null;
      return false;
    }

    try {
      const handle = await App.addListener('appUrlOpen', (event) => {
        console.log('[NCP Mobile Auth] appUrlOpen event received');
        if (!handlePortalDeepLink(event?.url)) {
          handleMobileAuthUrl(event?.url);
        }
      });
      mobileAuthListenerHandle = handle;
      console.log('[NCP Mobile Auth] Listener registered successfully');
      registerMobileAuthListenerCleanup();

      if (!mobileAuthLaunchUrlChecked && typeof App.getLaunchUrl === 'function') {
        mobileAuthLaunchUrlChecked = true;
        try {
          const launch = await App.getLaunchUrl();
          if (launch?.url) {
            if (!handlePortalDeepLink(launch.url)) {
              await handleMobileAuthUrl(launch.url);
            }
          }
        } catch (err) {
          // Launch URL is best-effort; the appUrlOpen listener handles active sessions.
        }
      }

      return true;
    } catch (err) {
      mobileAuthListenerPromise = null;
      return false;
    }
  })();

  return mobileAuthListenerPromise;
}

export async function startNativeGoogleLogin() {
  if (!isNativeRuntime()) {
    return false;
  }

  await initMobileAuthListener();
  const Browser = getCapacitorPlugin('Browser');
  if (!Browser?.open) {
    throw new Error('Native browser is unavailable. Please update the app and try again.');
  }

  const url = withQueryParams(buildApiUrl('/auth/google/start'), { platform: 'mobile' });
  await Browser.open({ url });
  return true;
}

function currentPageRequiresAuth() {
  const path = (window.location.pathname.replace(/\/+$/, '') || '/').toLowerCase();
  if (path === '/social.html' && new URLSearchParams(window.location.search).get('feed') === 'global') {
    return false;
  }
  const authenticatedPaths = [
    '/admin.html',
    '/calendar.html',
    '/dashboard.html',
    '/endeavour_view.html',
    '/endeavours.html',
    '/entity_drive.html',
    '/entity_endeavours.html',
    '/volunteering_ops.html',
    '/settings.html',
    '/social.html'
  ];
  return authenticatedPaths.includes(path);
}

const pagePermissionMap = {
  '/admin.html': 'nav.admin',
  '/calendar.html': 'nav.calendar',
  '/dashboard.html': 'nav.dashboard',
  '/endeavour_view.html': 'nav.entity_endeavours',
  '/endeavours.html': 'nav.volunteering',
  '/entity_drive.html': 'nav.entity_drive',
  '/entity_endeavours.html': 'nav.entity_endeavours',
  '/volunteering_ops.html': 'nav.volunteering_ops',
  '/settings.html': 'nav.settings',
  '/social.html': 'nav.social'
};

function intendedDestination() {
  return `${window.location.pathname}${window.location.search}${window.location.hash}`;
}

export function loginUrlForCurrentPage() {
  return `/login.html?next=${encodeURIComponent(intendedDestination())}`;
}

function authHomeFor(me) {
  const navigation = me?.data?.navigation || [];
  const dashboard = navigation.find(link => link.href === '/dashboard.html');
  if (dashboard) {
    return '/dashboard.html';
  }
  const first = navigation.find(link => link.href && link.href !== window.location.pathname);
  return first?.href || '/home.html';
}

function userHasPagePermission(me, permission) {
  if (!permission) {
    return true;
  }
  const permissions = me?.data?.permissions || [];
  if (permissions.includes(permission)) {
    return true;
  }
  return (me?.data?.navigation || []).some(link => link.permission === permission);
}

function userRequiresPasswordSetup(user) {
  return Number(user?.force_password_reset || 0) === 1 || Number(user?.password_setup_required || 0) === 1;
}

export async function requireAuthPage(requiredPermission = null) {
  const response = await apiFetch('/auth/me', { skipFallback: true });
  const user = response?.data?.user;
  if (!user) {
    window.location.replace(loginUrlForCurrentPage());
    return null;
  }
  if (userRequiresPasswordSetup(user)) {
    window.location.replace('/reset_password.html?mode=session');
    return null;
  }
  const permission = requiredPermission || pagePermissionMap[(window.location.pathname.replace(/\/+$/, '') || '/').toLowerCase()];
  if (!userHasPagePermission(response, permission)) {
    window.location.replace(authHomeFor(response));
    return null;
  }
  return response;
}

let globalAuthGuardStarted = false;
function initGlobalAuthGuard() {
  if (globalAuthGuardStarted || typeof window === 'undefined' || !currentPageRequiresAuth()) {
    return;
  }
  globalAuthGuardStarted = true;
  requireAuthPage().catch((err) => {
    if (err?.status === 401) {
      window.location.replace(loginUrlForCurrentPage());
      return;
    }
    if (err?.status === 403) {
      window.location.replace('/home.html');
    }
  });
}

export async function apiFetch(path, options = {}) {
  const { skipFallback, skipCsrf, onResponse, ...fetchOptions } = options;
  const method = (fetchOptions.method || 'GET').toUpperCase();
  const normalizedPath = normalizeApiPath(path);
  const mutates = ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method);
  const nativeRuntime = isNativeRuntime();
  const bearerToken = nativeRuntime ? await getMobileAuthToken() : '';
  const needsCsrf = !skipCsrf && mutates && !bearerToken;
  if (needsCsrf && !getCsrfToken()) {
    await ensureCsrfToken();
  }
  const resolvedCsrf = getCsrfToken();
  const headers = {
    ...(fetchOptions.headers || {})
  };
  const hasAuthorizationHeader = Object.keys(headers).some((key) => key.toLowerCase() === 'authorization');
  if (bearerToken && !hasAuthorizationHeader) {
    headers.Authorization = `Bearer ${bearerToken}`;
  }
  const isFormData = fetchOptions.body instanceof FormData;
  if (['POST', 'PUT', 'PATCH'].includes(method) && !headers['Content-Type'] && !isFormData) {
    headers['Content-Type'] = 'application/json';
  }
  if (needsCsrf && resolvedCsrf) {
    headers['X-CSRF-Token'] = resolvedCsrf;
  }
  const request = async (base) => {
    const url = buildApiUrl(path, base);
    try {
      const res = await fetch(url, {
        ...fetchOptions,
        headers,
        credentials: nativeRuntime ? 'omit' : 'include',
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
      authDebugLog('[NCP API] endpoint/status', {
        endpoint: normalizedPath || '/',
        status: res.status
      });
      if (typeof onResponse === 'function') {
        try {
          onResponse({ ok: res.ok, status: res.status, method, path: normalizedPath || '/' });
        } catch (callbackErr) {
          // Diagnostic callbacks must not affect API behavior.
        }
      }
      return { res, data, text, url };
    } catch (err) {
      console.warn(`API request error ${method} ${url}`, err);
      throw err;
    }
  };

  let { res, data, text, url } = await request(preferredBase);
  const isLoginRequest = method === 'POST' && normalizedPath === '/auth/login';
  let retriedCsrfMismatch = false;
  if (needsCsrf && isLoginRequest && isInvalidCsrfResponse(res, data)) {
    authDebugLog('[NCP CSRF] login mismatch detected; refreshing once');
    retriedCsrfMismatch = true;
    setCsrfToken('');
    await bootstrapCsrf({ forceRefresh: true });
    headers['X-CSRF-Token'] = getCsrfToken();
    ({ res, data, text, url } = await request(preferredBase));
  }
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
    const bearerFailureCanInvalidateToken = !['/auth/mobile/login', '/auth/mobile/exchange'].includes(normalizedPath);
    const bearerAuthFailed = nativeRuntime
      && bearerToken
      && bearerFailureCanInvalidateToken
      && (res.status === 401 || isInvalidCsrfResponse(res, data));
    if (bearerAuthFailed) {
      await clearMobileAuthToken();
      if (currentPageRequiresAuth()) {
        window.location.replace('/login.html');
      }
      throw buildApiError('Session expired. Please sign in again.', res.status, url, text);
    }
    if (retriedCsrfMismatch && isInvalidCsrfResponse(res, data)) {
      throw buildApiError('Session expired. Please try again.', res.status, url, text);
    }
    const message = data?.error || `HTTP ${res.status}`;
    throw buildApiError(message, res.status, url, text);
  }
  if (normalizedPath === '/auth/logout' || normalizedPath === '/auth/mobile/logout') {
    setCsrfToken('');
    csrfSessionVersion += 1;
    if (normalizedPath === '/auth/mobile/logout') {
      await clearMobileAuthToken();
    }
  }
  if (nativeRuntime && bearerToken && normalizedPath === '/auth/me' && !data?.data?.user) {
    await clearMobileAuthToken();
    if (currentPageRequiresAuth()) {
      window.location.replace('/login.html');
    }
  }
  return data;
}

function isInvalidCsrfResponse(res, data) {
  return res?.status === 403 && data?.error === 'Invalid CSRF token';
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
  if (
    err?.status >= 500
    || /^HTTP 5\d\d$/.test(message)
    || /SQLSTATE|PDOException|Integrity constraint/i.test(message)
  ) {
    return 'Something went wrong. Please try again.';
  }
  return message === 'Forbidden' ? 'You do not have permission.' : (message || 'Action failed.');
};

export async function bootstrapCsrf(options = {}) {
  const forceRefresh = Boolean(options.forceRefresh);
  if (!forceRefresh && getCsrfToken()) {
    authDebugLog('[NCP CSRF] token present yes');
    return getCsrfToken();
  }
  if (csrfBootstrapPromise) {
    return csrfBootstrapPromise;
  }
  const bootstrapVersion = csrfSessionVersion;
  const request = async (base) => {
    const url = buildApiUrl('/auth/csrf', base);
    authDebugLog('[NCP CSRF] bootstrap start', { base });
    const res = await fetch(url, {
      cache: 'no-store',
      credentials: 'include',
      headers: { 'Accept': 'application/json' }
    });
    const data = await res.json().catch(() => ({}));
    return { res, data, url };
  };
  csrfBootstrapPromise = (async () => {
    try {
      let { res, data, url } = await request(preferredBase);
      const fallbackBase = resolveFallbackBase(preferredBase);
      const shouldFallback = fallbackBase && !res.ok && res.status === 404;
      if (shouldFallback) {
        const fallbackResponse = await request(fallbackBase);
        res = fallbackResponse.res;
        data = fallbackResponse.data;
        url = fallbackResponse.url;
        if (res.ok) {
          preferredBase = fallbackBase;
        }
      }
      if (!res.ok) {
        throw buildApiError(data?.error || `HTTP ${res.status}`, res.status, url || buildApiUrl('/auth/csrf', preferredBase));
      }
      if (bootstrapVersion === csrfSessionVersion) {
        setCsrfToken(data?.data?.csrfToken || data?.data?.token || '');
      }
      authDebugLog('[NCP CSRF] bootstrap status', {
        status: res.status,
        tokenPresent: getCsrfToken() ? 'yes' : 'no'
      });
      return getCsrfToken();
    } finally {
      csrfBootstrapPromise = null;
    }
  })();
  return csrfBootstrapPromise;
}

export async function ensureCsrfToken() {
  const token = getCsrfToken() || await bootstrapCsrf();
  authDebugLog(`[NCP CSRF] token present ${token ? 'yes' : 'no'}`);
  if (!token) {
    throw new Error('Unable to start a secure session. Please try again.');
  }
  return token;
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

export function getPublicBaseUrl() {
  const configured = String(portalConfig.public_base_url || window.PUBLIC_BASE_URL || 'https://ncp.nixorcorporate.com').replace(/\/+$/, '');
  try {
    const parsed = new URL(configured);
    if (['localhost', '127.0.0.1', '::1'].includes(parsed.hostname)) {
      return 'https://ncp.nixorcorporate.com';
    }
    return parsed.origin + parsed.pathname.replace(/\/+$/, '');
  } catch (err) {
    return 'https://ncp.nixorcorporate.com';
  }
}

function isMobileBrowserRuntime() {
  if (isNativeRuntime()) {
    return false;
  }
  return window.matchMedia?.('(max-width: 767px)').matches
    && /Android|iPhone|iPad|iPod/i.test(navigator.userAgent || '');
}

function openAppDismissed() {
  try {
    const dismissedAt = Number(localStorage.getItem(OPEN_APP_DISMISSED_KEY) || 0);
    return dismissedAt > 0 && Date.now() - dismissedAt < OPEN_APP_DISMISS_MS;
  } catch (err) {
    return false;
  }
}

function dismissOpenAppBanner() {
  try {
    localStorage.setItem(OPEN_APP_DISMISSED_KEY, String(Date.now()));
  } catch (err) {
  }
  document.getElementById('ncp-open-app-banner')?.remove();
}

function platformStoreUrl() {
  const banner = portalConfig.open_app_banner || {};
  const ua = navigator.userAgent || '';
  if (/Android/i.test(ua)) {
    return banner.android_store_url || 'https://example.com';
  }
  return banner.ios_store_url || 'https://example.com';
}

function initOpenAppBanner() {
  const banner = portalConfig.open_app_banner || {};
  if (!banner.enabled || !isMobileBrowserRuntime() || openAppDismissed() || document.getElementById('ncp-open-app-banner')) {
    return;
  }
  const wrap = document.createElement('div');
  wrap.id = 'ncp-open-app-banner';
  wrap.className = 'open-app-banner';
  wrap.innerHTML = `
    <div class="open-app-banner__text">Open Nixor Portal in the app</div>
    <div class="open-app-banner__actions">
      <button type="button" class="btn btn-primary btn-sm" data-open-app>Open app</button>
      <button type="button" class="btn btn-ghost btn-sm" data-dismiss-open-app>Not now</button>
    </div>
  `;
  wrap.querySelector('[data-dismiss-open-app]')?.addEventListener('click', dismissOpenAppBanner);
  wrap.querySelector('[data-open-app]')?.addEventListener('click', () => {
    const storeUrl = platformStoreUrl();
    let didHide = false;
    const onVisibility = () => {
      if (document.hidden) {
        didHide = true;
      }
    };
    document.addEventListener('visibilitychange', onVisibility, { once: true });
    window.location.href = portalDeepLinkUrlForCurrentPage();
    window.setTimeout(() => {
      document.removeEventListener('visibilitychange', onVisibility);
      if (!didHide && storeUrl) {
        window.location.href = storeUrl;
      }
    }, 1200);
  });
  document.body.appendChild(wrap);
}

async function nativeDeviceId() {
  let id = await readNativePreference(PUSH_DEVICE_ID_KEY);
  if (!id) {
    id = `dev_${globalThis.crypto?.randomUUID?.() || Math.random().toString(36).slice(2)}_${Date.now().toString(36)}`;
    await writeNativePreference(PUSH_DEVICE_ID_KEY, id);
  }
  return id;
}

async function registerNativePushToken(token) {
  if (!token || !portalConfig.push?.registration_enabled) {
    return;
  }
  await apiFetch('/notifications/push-token', {
    method: 'POST',
    body: JSON.stringify({
      token,
      platform: getNativePlatform(),
      device_id: await nativeDeviceId()
    })
  });
  await writeNativePreference(PUSH_TOKEN_KEY, token);
}

export async function unregisterNativePushToken() {
  const token = await readNativePreference(PUSH_TOKEN_KEY);
  if (!token) {
    return;
  }
  try {
    await apiFetch('/notifications/push-token', {
      method: 'DELETE',
      body: JSON.stringify({
        token,
        platform: getNativePlatform(),
        device_id: await nativeDeviceId()
      })
    });
  } finally {
    await removeNativePreference(PUSH_TOKEN_KEY);
  }
}

export async function initPushNotifications() {
  if (pushRegistrationStarted || !isNativeRuntime() || !portalConfig.push?.registration_enabled) {
    return false;
  }
  const PushNotifications = getCapacitorPlugin('PushNotifications');
  if (!PushNotifications?.requestPermissions || !PushNotifications?.register || !PushNotifications?.addListener) {
    return false;
  }
  pushRegistrationStarted = true;
  try {
    const permission = await PushNotifications.requestPermissions();
    if (permission?.receive !== 'granted') {
      return false;
    }
    await PushNotifications.addListener('registration', async (token) => {
      try {
        await registerNativePushToken(token?.value || token?.token || '');
      } catch (err) {
        console.warn('Push token registration failed', err);
      }
    });
    await PushNotifications.addListener('registrationError', (error) => {
      console.warn('Push registration failed', error);
    });
    await PushNotifications.addListener('pushNotificationActionPerformed', (event) => {
      const targetUrl = event?.notification?.data?.target_url || '';
      if (targetUrl && targetUrl.startsWith('/') && !targetUrl.startsWith('//')) {
        window.location.href = targetUrl;
      }
    });
    await PushNotifications.register();
    return true;
  } catch (err) {
    pushRegistrationStarted = false;
    console.warn('Push notifications unavailable', err);
    return false;
  }
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

initMobileAuthListener();
initGlobalAuthGuard();
loadConfig().then(() => {
  initOpenAppBanner();
  initPushNotifications();
});
