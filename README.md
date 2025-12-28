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
1. Create the database and tables:
   ```bash
   mysql -u root -p < sql/schema.sql
   ```
2. (Optional) Seed sample data:
   ```bash
   mysql -u root -p < sql/seed.sql
   ```

### 2) Environment
Copy and edit the environment file:
```bash
cp .env.example .env
```
Optionally set `ENV_FILE_PATH` if the `.env` file is stored outside the repo root.

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
