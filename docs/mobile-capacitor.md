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

For staging or QA native shells, set `window.NATIVE_API_BASE` before loading `public/assets/app.js`, or provide `import.meta.env.VITE_NATIVE_API_BASE` when using a bundler that injects `import.meta.env`. If neither override is present, native builds use the production API above.

The PHP API allows credentialed CORS only for trusted origins:
- `https://ncp.nixorcorporate.com`
- `capacitor://localhost`
- `http://localhost` and loopback development ports
- `BASE_URL` and optional comma-separated `CORS_ALLOWED_ORIGINS`

Do not use wildcard CORS with credentials. Mutating API calls still bootstrap CSRF through `GET /api/auth/csrf` and send `X-CSRF-Token`.

## Auth Notes
The portal still treats the PHP session cookie as the source of truth. For trusted cross-origin Capacitor requests, the API marks the session cookie `SameSite=None; Secure` so WebViews can send it back to the hosted API.

Remaining limitation: WebView cookie behavior can vary by OS version, embedded browser engine, and privacy settings. If production device testing shows unreliable cookie persistence, add a dedicated token-based mobile session design with revocation, expiry, secure storage, CSRF-equivalent protections, and backend RBAC checks. Do not bypass existing permission checks or CSRF requirements.

Google login in native Capacitor does not use Google Identity Services inside the local WebView. The native button opens `https://ncp.nixorcorporate.com/api/auth/google/start?platform=mobile` with `@capacitor/browser`, the PHP callback creates a short-lived one-time code, and the app receives `ncp://auth/callback?code=...` through `@capacitor/app`. The WebView exchanges that code at `/api/auth/mobile/exchange`, which creates the normal PHP session cookie for subsequent API requests.

Production hardening note: custom URL schemes can be claimed by another app on some platforms. Prefer adding an HTTPS callback bridge with Android App Links and iOS Universal Links (`assetlinks.json` and `apple-app-site-association`) that validates the request and 302s to the app callback when the platform setup is ready.

Configure Google Cloud with the web OAuth redirect URI used by the backend:
```text
https://ncp.nixorcorporate.com/api/auth/google/callback
```

Set the matching environment values:
```text
GOOGLE_REDIRECT_URI=https://ncp.nixorcorporate.com/api/auth/google/callback
OAUTH_STATE_SECRET=<long-random-secret>
MOBILE_AUTH_REDIRECT_URI=ncp://auth/callback
```

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
