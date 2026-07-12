# Nixor Portal – cPanel Deployment Guide

This guide assumes a shared hosting environment with Apache + PHP, MariaDB, and cPanel cron.

## 1) Upload files to `public_html`
1. Upload the repository contents into `/public_html/portal` (or directly into `/public_html`).
2. Ensure the document root points to the `/public` folder (the `.htaccess` already routes traffic to `/public`).
3. If Apache serves from the repository root, keep the root `.htaccess` enabled so clean `/api/*` requests dispatch to `api/index.php`. If Apache serves from a parent folder, ensure `AllowOverride FileInfo Options` is enabled for the portal directory so both the root and `api/.htaccess` rewrite rules are honored.

## 2) Create database + user in cPanel
1. Create a new MariaDB database (e.g., `nixor_portal`).
2. Create a database user and grant **ALL** privileges to the new database.
3. Note the database host (often `localhost`), DB name, username, and password.

## 3) Apply migrations
1. Ensure `config/.env` or the repository root `.env` (or exported `DB_*` env vars) is set before running migrations. The project resolves `config/.env` with higher precedence.
2. Upload the repo and run the migration script from SSH:
   ```bash
   /usr/bin/php -q /home/<cpanel_user>/public_html/portal/scripts/migrate.php
   ```
3. (Dev-only) Seed reference/sample data (requires APP_ENV=development and ALLOW_DEV_SEED=true):
   ```bash
   APP_ENV=development ALLOW_DEV_SEED=true /usr/bin/php -q /home/<cpanel_user>/public_html/portal/scripts/seed_dev.php
   ```

## 4) Configure `.env`
1. Copy `config/.env.example` to `.env` (repository root) or `config/.env`.
2. Set required values:
   - `BASE_URL` (production URL)
   - `PUBLIC_BASE_URL=https://ncp.nixorcorporate.com` (public link generation for copied/shared URLs)
   - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
   - `MAIL_TRANSPORT=smtp`, `AUTOMATED_EMAILS_ENABLED=false`, and `SMTP_*` if explicit transactional email is configured. Automated cron/digest/reminder email is disabled in code and must remain off.
   - `UPLOAD_PATH` (absolute path outside web root, e.g. `/home/<cpanel_user>/portal_uploads`)
   - `LOG_PATH` (absolute path, e.g. `/home/<cpanel_user>/portal_logs`)
   - `TRUSTED_PROXIES` (Cloudflare or other proxies if used)
   - `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`
   - `GOOGLE_ALLOWED_DOMAIN=nixorcollege.edu.pk` and, only if Nixor-domain self-provisioning is intended, `GOOGLE_AUTO_PROVISION=true`
   - `OAUTH_STATE_SECRET` (dedicated random HMAC key for Google OAuth state signing; falls back to `APP_KEY` and then `GOOGLE_CLIENT_SECRET` if unset)
   - `MOBILE_AUTH_REDIRECT_URI` (defaults to `ncp://auth/callback`; set explicitly if changing the app scheme or using Universal/App Links)
   - `MOBILE_SESSION_TTL_DAYS` (optional; defaults to 30 days for native bearer sessions)
   - `APP_DEEP_LINK_SCHEME`, `APP_UNIVERSAL_LINK_BASE`, `IOS_APP_STORE_URL`, `ANDROID_PLAY_STORE_URL`, and `SHOW_OPEN_APP_BANNER` for mobile web handoff
   - `PUSH_REGISTRATION_ENABLED` plus `PUSH_PROVIDER` and provider webhook/FCM settings if device push delivery is enabled. Leave `PUSH_PROVIDER=none` until credentials are ready.
   - `SETUP_TOKEN` (required in production to call `/api/admin/setup`)

## 5) Run the setup endpoint
1. Send a `POST` request to `/api/admin/setup` with JSON:
   ```json
   {
     "email": "admin@example.com",
     "full_name": "Portal Admin",
     "password": "YourSecurePassword123!",
     "setup_token": "your-setup-token"
   }
   ```
2. The setup endpoint creates tables (if missing) and the first admin user.
3. After setup, a lock file is written to `config/setup.lock` and the endpoint disables itself.

## 6) Configure Google OAuth
1. In Google Cloud Console, set the **Authorized JavaScript origins** to your production `BASE_URL`.
2. Set the **Authorized redirect URI** to `GOOGLE_REDIRECT_URI` in your `.env`.
   - Production mobile/web OAuth callback: `https://ncp.nixorcorporate.com/api/auth/google/callback`
   - Capacitor deep link after backend verification: `MOBILE_AUTH_REDIRECT_URI=ncp://auth/callback`
   - Set `OAUTH_STATE_SECRET` to a long random value so `google_state_secret_or_fail()` does not rely on `GOOGLE_CLIENT_SECRET` for state signing.
   - Keep `GOOGLE_AUTO_PROVISION=false` unless `GOOGLE_ALLOWED_DOMAIN=nixorcollege.edu.pk` is set and verified Nixor College Google users should be created automatically with the default `volunteer` role.

## 7) Configure cron jobs
Add a cPanel cron entry for non-email maintenance that calls:
```bash
/usr/bin/php -q /home/<cpanel_user>/public_html/portal/cron/run.php
```
If CLI is unavailable, call the HTTP endpoint:
```bash
https://your-domain.com/portal/cron/run.php?token=<CRON_TOKEN>
```
This cron entry does not send digest, reminder, summary, assignment, or bulk email. If an older cPanel job exists only to send `Nixor Portal daily digest` or reminder email, remove that job; the code is safe even if the generic cron above remains configured.

## 8) File permissions
Ensure the following paths are writable by the PHP user:
- `UPLOAD_PATH` (e.g., `/home/<cpanel_user>/portal_uploads`)
- `LOG_PATH` (e.g., `/home/<cpanel_user>/portal_logs`)
- `ws/` (if the optional websocket server is used)

## 9) Optional websocket server
The portal falls back to polling automatically, but if you enable websockets:
1. Deploy `/ws/server.py` via a supported Python process (if available).
2. Set `WS_URL` and `WS_TOKEN` in `.env`.

## 10) Testing checklist
- Login (password + Google)
- Entity dashboard loads announcements/meetings/deadlines
- Create announcement
- Upload docs and download via the file endpoint
- Volunteer post request + publish
- Applications, shortlist, consent request + signing
- Payment mark + attendance
- Entity drive upload + download
- Calendar event creation
- Social posts + comments
- Cron runs without sending digest or reminder email

## 11) Manual QA data cleanup
The repo includes `scripts/cleanup_bad_calendar_and_qa_data.sql` for reviewing and removing QA leftovers such as the `sfef` entity, year-1111 calendar events, and `QA_TEST_DO_NOT_USE_*` records. The script runs inside a transaction and ends with `ROLLBACK` by default; review the SELECT previews and row counts before changing it to `COMMIT` in production.
