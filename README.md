# Nixor Corporate Portal

A centralized, lightweight portal for entity documentation, endeavours, approvals, and volunteer management.

## Repo Layout
- `/api` PHP API (REST-ish JSON endpoints)
- `/public` HTML/CSS/JS front-end pages
- `/ws` Python websocket broadcaster (reads an events queue)
- `/sql` MariaDB schema + seed data
- `/uploads` stored documents (gitignored)

## Setup

### 1) Database
1. Create the database in MariaDB (via CLI or cPanel).
2. Ensure `.env` is configured (or export `DB_*` variables) so `scripts/migrate.php` can connect.
3. Apply migrations:
   ```bash
   php scripts/migrate.php
   ```
4. (Dev-only) Seed reference/sample data (requires `APP_ENV=development` + `ALLOW_DEV_SEED=true`):
   ```bash
   APP_ENV=development ALLOW_DEV_SEED=true php scripts/seed_dev.php
   ```
5. Create an admin account via the setup endpoint (see DEPLOYMENT.md).

### 2) Environment
Copy and edit the environment file:
```bash
cp .env.example .env
```
Optionally set `ENV_FILE_PATH` if the `.env` file is stored outside the repo root.

### 2.1) Google Login (Optional)
To enable Google sign-in:
1. Create a Google OAuth client (Web application) in the Google Cloud Console.
2. Set the authorized JavaScript origin to your app URL (e.g. `http://localhost:8000`).
3. The backend callback endpoint is `http://localhost:8000/api/auth/google_callback` (the frontend posts the Google ID token here).
4. Add the client ID to your `.env`:
   ```bash
   GOOGLE_CLIENT_ID=your-client-id.apps.googleusercontent.com
   ```
5. Optionally restrict logins to your organization domain:
   ```bash
   GOOGLE_ALLOWED_DOMAIN=nixor.io
   ```

### 2.2) PHP Dependencies
Install PHP dependencies (Google API client for ID token verification):
```bash
composer install
```

### 2.3) Running Tests
Configure a test database and run:
```bash
composer test
```

### 3) PHP API + Frontend
Minimum PHP version: **8.0**.

Run the PHP dev server from the repo root:
```bash
php -S localhost:8000
```
The API will be available at `http://localhost:8000/api` and the UI at `http://localhost:8000/login.html`.

### 4) Websocket Server
The websocket server broadcasts events that PHP appends to a queue file.

Install the dependency:
```bash
pip install -r ws/requirements.txt
```

Run the server:
```bash
python ws/server.py
```

By default the websocket server binds to `127.0.0.1`. Set `WS_HOST`/`WS_PORT` to change this and optionally set `WS_TOKEN` to require a `?token=` query parameter for clients.
If you set `WS_TOKEN`, expose it on the frontend (for example `window.WS_TOKEN = '...';`) before calling `connectWebsocket`.

## API Overview
All endpoints return JSON:
```json
{ "ok": true, "data": {}, "error": null, "meta": {} }
```

Sample endpoints:
- `POST /api/auth/login`
- `POST /api/auth/logout`
- `GET /api/auth/me`
- `GET /api/endeavours`
- `POST /api/endeavours/{id}/approve`
- `POST /api/endeavours/{id}/submit_ops_plan`
- `POST /api/endeavours/{id}/request_post_to_feed`
- `POST /api/endeavours/{id}/publish_post`


## Entity Drive
- Supports folder tree navigation using `parent_id` with breadcrumb metadata from `GET /api/drive/list`.
- Item types: `folder`, `file`, and `link` (URL-backed document entries).
- Sharing scopes:
  - `private`: creator-only plus entity CEO and global elevated roles.
  - `entity`: all members of the item's entity.
  - `department`: selected departments in the same entity.
  - `users`: explicit users by id/email (including users outside the entity).
- Elevated visibility (inherent): `admin`, `board`, `student_affairs` always view all entity drive content.
- Entity CEO access: manager in `management` department (or global `ceo` with membership) can view/manage all content in that entity.
- Link previews support YouTube and direct PDF URLs; file previews support inline PDFs via `GET /api/drive/content?id=`.
- API endpoints:
  - `GET /api/drive/list?entity_id=&parent_id=`
  - `GET /api/drive/item?id=`
  - `GET /api/drive/preview?id=`
  - `POST /api/drive/folder`
  - `POST /api/drive/upload`
  - `POST /api/drive/link`
  - `POST /api/drive/rename`
  - `POST /api/drive/delete` (recursive for folders)
  - `POST /api/drive/share`

## Frontend Pages
- `/login.html`
- `/home.html`
- `/dashboard.html`
- `/entity_drive.html`
- `/endeavours.html`
- `/endeavour_view.html`
- `/admin.html`

## Notes
- Uploaded documents are stored in `/uploads/{endeavour_id}/{doc_type}`
- The websocket server reads from the queue file configured in `.env` (default: `/ws/events.queue`).
- Consider cleaning expired session rows via a periodic job (based on `sessions.expires_at`).
- Tailwind is loaded via CDN for rapid prototyping; for production, consider a build step with purged CSS.
