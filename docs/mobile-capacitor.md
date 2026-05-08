# Capacitor Mobile Build Guide

Nixor Corporate Portal uses Capacitor Option A: a bundled app shell. The native apps bundle the same static frontend that the website serves from `public/`, while the PHP/MariaDB API remains hosted remotely.

## Architecture
- Website frontend: `public/*.html` and `public/assets/*`.
- Mobile frontend: the same `public/` files copied into Capacitor Android and iOS projects.
- Backend API: `https://ncp.nixorcorporate.com/api`.
- Capacitor config: `capacitor.config.ts`.
- Native app id: `com.nixorcorporate.portal`.
- Production builds must not use Capacitor `server.url`; the app ships bundled web assets.

## Setup
```bash
npm install
npm run build
npm run cap:sync
```

Useful scripts:
```bash
npm run cap:android
npm run cap:ios
```

`cap:ios` requires macOS with Xcode. `cap:android` requires Android Studio and an Android SDK.

## Android
1. Run `npm install`.
2. Run `npm run build`.
3. Run `npm run cap:sync`.
4. Run `npm run cap:android`.
5. Build, sign, and distribute from Android Studio.

The Android project is in `android/`. Generated copied web assets under `android/app/src/main/assets/public` are intentionally ignored and are refreshed by `npx cap sync`.

`android/app/src/main/AndroidManifest.xml` must keep the mobile auth intent filter for `ncp://auth/callback` with scheme `ncp`, host `auth`, and path prefix `/callback`.

## iOS
1. Use macOS with Xcode installed.
2. Run `npm install`.
3. Run `npm run build`.
4. Run `npm run cap:sync`.
5. Run `npm run cap:ios`.
6. Configure signing, build, archive, and distribute from Xcode.

The iOS project is in `ios/`. Generated copied web assets under `ios/App/App/public` are intentionally ignored and are refreshed by `npx cap sync`.

For the Google mobile login callback, add an iOS URL scheme before shipping iOS:
- In Xcode, open the app target Info settings.
- Add a URL Type with identifier `com.nixorcorporate.portal`.
- Add the URL scheme `ncp`.
- Verify `ncp://auth/callback?code=...` wakes the app and reaches the shared Capacitor `App.addListener('appUrlOpen', ...)` handler.

## API And CORS
The shared frontend helper in `public/assets/app.js` selects the API base at runtime:
- Browser website: `/api`.
- Capacitor native runtime: `https://ncp.nixorcorporate.com/api`.
- Copied portal links use `PUBLIC_BASE_URL` from `/api/config`, so mobile/local webviews share production web URLs instead of `localhost`.

For staging or QA native shells, set `window.NATIVE_API_BASE` before loading `public/assets/app.js`, or provide `import.meta.env.VITE_NATIVE_API_BASE` when using a bundler that injects `import.meta.env`. If neither override is present, native builds use the production API above.

The PHP API allows credentialed CORS only for trusted origins:
- `https://ncp.nixorcorporate.com`
- `capacitor://localhost`
- `http://localhost` and loopback development ports
- `BASE_URL` and optional comma-separated `CORS_ALLOWED_ORIGINS`

Do not use wildcard CORS with credentials. Mutating API calls still bootstrap CSRF through `GET /api/auth/csrf` and send `X-CSRF-Token`.

## Auth Notes
Browser auth still uses PHP session cookies and CSRF bootstrapped from `GET /api/auth/csrf`. Do not remove CSRF from `/api/auth/login`, `/api/auth/google_callback`, or other browser-cookie session flows.

Native Capacitor auth uses revocable mobile bearer sessions because iOS WKWebView does not always preserve the PHP session cookie across the CSRF, login, exchange, and `/auth/me` requests. The backend stores only `SHA-256` hashes in `mobile_sessions`; the raw bearer token is returned once to the app and sent as `Authorization: Bearer <token>` on later API calls. Native API fetches omit cookies so a stale WebView cookie cannot become the mobile source of truth. Token lifetime defaults to 30 days and can be changed with `MOBILE_SESSION_TTL_DAYS`.

The shared frontend stores native tokens with `@capacitor/preferences` under `ncp_mobile_token` and `ncp_mobile_token_expires_at`, with an in-memory/local fallback for constrained WebView contexts. Do not log tokens, place them in URLs, or expose them in page markup.

Google login in native Capacitor does not use Google Identity Services inside the local WebView. The native button opens `https://ncp.nixorcorporate.com/api/auth/google/start?platform=mobile` with `@capacitor/browser`, the PHP callback creates a short-lived one-time code, and the app receives `ncp://auth/callback?code=...` through `@capacitor/app`. The WebView exchanges that code at `/api/auth/mobile/exchange`, which consumes the one-time code and returns a mobile bearer token. The endpoint may also establish a PHP session cookie for compatibility, but native app auth must depend on the bearer token.

Native email/password login posts JSON credentials to `/api/auth/mobile/login`. That endpoint is rate-limited and performs the same password and account-status checks as browser login, but it does not require CSRF because it returns a bearer token rather than relying on an ambient browser cookie.

Native logout posts to `/api/auth/mobile/logout` with the bearer token. The backend hashes the presented token, marks the matching row revoked, and the app clears local token storage.

## Open In App And Push Notifications
Mobile browsers show a closable bottom banner when `SHOW_OPEN_APP_BANNER=true`. `Open app` attempts to open the same path/query with `APP_DEEP_LINK_SCHEME` and falls back to the platform store URL after a short timeout. Dismissal is stored locally for 30 days.

Native push registration is config-driven. The app registers iOS/Android tokens at `/api/notifications/push-token` when notification permission is granted. The backend stores tokens but sends no device push unless `PUSH_PROVIDER` and its credentials/webhook are configured. Platform notifications continue to be created when push is not configured.

For production push, sync the native projects after installing dependencies:
```bash
npm install
npm run cap:sync
```
Android already checks for `google-services.json` before applying Google Services. Keep provider secrets out of the repository and configure them through `.env` or native platform secret management.

Production hardening note: custom URL schemes can be claimed by another app on some platforms. Prefer adding an HTTPS callback bridge with Android App Links and iOS Universal Links (`assetlinks.json` and `apple-app-site-association`) that validates the request and 302s to the app callback when the platform setup is ready.

Note: The `FCM_SERVER_KEY` used by the code path that forwards a legacy
server key (when `FCM_WEBHOOK_URL` is set) is the legacy FCM HTTP key. Google
has removed legacy HTTP/XMPP APIs; do not point `FCM_WEBHOOK_URL` directly at
`fcm.googleapis.com` (you will receive 401 responses). Either host a relay
that accepts the legacy `key=` header or migrate to the FCM HTTP v1 API with
OAuth2 Bearer tokens for direct Google integration.

Configure Google Cloud with the web OAuth redirect URI used by the backend:
```text
https://ncp.nixorcorporate.com/api/auth/google/callback
```

Set the matching environment values:
```text
GOOGLE_REDIRECT_URI=https://ncp.nixorcorporate.com/api/auth/google/callback
OAUTH_STATE_SECRET=<long-random-secret>
MOBILE_AUTH_REDIRECT_URI=ncp://auth/callback
PUBLIC_BASE_URL=https://ncp.nixorcorporate.com
APP_DEEP_LINK_SCHEME=ncp
APP_UNIVERSAL_LINK_BASE=https://ncp.nixorcorporate.com
IOS_APP_STORE_URL=
ANDROID_PLAY_STORE_URL=
SHOW_OPEN_APP_BANNER=false
PUSH_REGISTRATION_ENABLED=true
PUSH_PROVIDER=webhook
PUSH_WEBHOOK_URL=
PUSH_WEBHOOK_SECRET=
PUSH_ENABLED=true
PUSH_VAPID_PUBLIC_KEY=
PUSH_VAPID_PRIVATE_KEY=
FCM_SERVER_KEY=
FCM_WEBHOOK_URL=
APNS_KEY=
APNS_KEY_ID=
APNS_TEAM_ID=
```

If mobile Google login should create users on first verified-domain sign-in, set `GOOGLE_ALLOWED_DOMAIN` and explicitly enable `GOOGLE_AUTO_PROVISION=true`. Without that flag, Google login links to existing portal users only.

## Deployment Process
1. Deploy the PHP API and website normally to `https://ncp.nixorcorporate.com`.
2. Verify `/api/auth/csrf` responds with JSON and CORS headers for trusted origins.
3. Build CSS with `npm run build`.
4. Sync native projects with `npm run cap:sync`.
5. Open the platform project and build signed native artifacts.

## Syncing Frontend Changes
Every change under `public/` should be treated as both a website and native-app change.

After frontend edits:
```bash
npm run build
npm run cap:sync
```

`public/assets/app.css` is a committed generated artifact for static hosting. Regenerate it from `public/assets/global.css` with `npm run build:css` before committing visual changes.

Then smoke-test:
- Website root `/` routes logged-in users to `dashboard.html` and logged-out users to `login.html`.
- Native app loads the bundled `index.html`.
- Native API requests target `https://ncp.nixorcorporate.com/api`, not `/api`.
- Login, dashboard, Drive, calendar, social, volunteering, settings, and admin pages have no horizontal overflow around 390px and 430px widths.
