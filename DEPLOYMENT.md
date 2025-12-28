# Nixor Portal – cPanel Deployment Guide

This guide assumes a shared hosting environment with Apache + PHP, MariaDB, and cPanel cron.

## 1) Upload files to `public_html`
1. Upload the repository contents into `/public_html/portal` (or directly into `/public_html`).
2. Ensure the document root points to the `/public` folder (the `.htaccess` already routes traffic to `/public`).

## 2) Create database + user in cPanel
1. Create a new MariaDB database (e.g., `nixor_portal`).
2. Create a database user and grant **ALL** privileges to the new database.
3. Note the database host (often `localhost`), DB name, username, and password.

## 3) Import the schema
1. Import `/sql/schema.sql` using phpMyAdmin or the cPanel MySQL tool.
2. Optionally import `/sql/seed.sql` for sample data.

## 4) Configure `.env`
1. Copy `config/.env.example` to `config/.env`.
2. Set required values:
   - `BASE_URL` (production URL)
   - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
   - `SMTP_*` (if SMTP is configured)
   - `UPLOAD_PATH` (absolute path outside web root, e.g. `/home/<cpanel_user>/portal_uploads`)
   - `LOG_PATH` (absolute path, e.g. `/home/<cpanel_user>/portal_logs`)
   - `TRUSTED_PROXIES` (Cloudflare or other proxies if used)
   - `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`

## 5) Run the setup endpoint
1. Send a `POST` request to `/api/admin/setup` with JSON:
   ```json
   {
     "email": "admin@example.com",
     "full_name": "Portal Admin",
     "password": "YourSecurePassword123"
   }
   ```
2. The setup endpoint creates tables (if missing) and the first admin user.
3. After setup, a lock file is written to `config/setup.lock` and the endpoint disables itself.

## 6) Configure Google OAuth
1. In Google Cloud Console, set the **Authorized JavaScript origins** to your production `BASE_URL`.
2. Set the **Authorized redirect URI** to `GOOGLE_REDIRECT_URI` in your `.env`.

## 7) Configure cron jobs
Add a cPanel cron entry that calls:
```
/usr/bin/php -q /home/<cpanel_user>/public_html/portal/cron/run.php
```
If CLI is unavailable, call the HTTP endpoint:
```
https://your-domain.com/portal/cron/run.php?token=<CRON_TOKEN>
```

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
- Cron reminders send/log correctly
