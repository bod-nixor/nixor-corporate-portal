# Nixor Entities Endeavour Dashboard

A refactored cPanel-friendly stack for the Nixor Endeavour platform. The application is split into:

- **Frontend**: Static HTML, CSS, and vanilla JS served from `public/` with Google Identity Services for login.
- **Backend API**: Lightweight PHP 8 endpoints under `public/api/` using PDO, PSR-12 style, JWT issuance, and CSRF protection.
- **Realtime Service**: Python WebSocket + HTTP publish server broadcasting endeavour/registration events.
- **Database**: MariaDB/MySQL with `sql/schema.sql` and `sql/seed.sql` for bootstrapping.

## Directory Layout

```
assets/                # CSS & JS
public/                # Static pages + .htaccess CSP
public/api/            # PHP endpoints (REST)
src/                   # PHP domain logic & services
python/                # WebSocket server + tests
scripts/               # Maintenance utilities (seed runner)
sql/                   # Schema and sample seed data
```

## Environment Variables

All legacy keys are preserved. Append-only additions are marked with (*).

| Purpose | Key |
| --- | --- |
| Google Identity | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` |
| Email domain allowlist | `ALLOWED_EMAIL_DOMAIN` |
| Database | `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` |
| SMTP | `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM` |
| App URL | `APP_URL` |
| JWT secret (*) | `JWT_SECRET` |
| Visibility mode (*) | `VISIBILITY_MODE` (`RESTRICTED` or `OPEN`) |
| WebSocket URL (*) | `WS_URL` (e.g. `wss://portal.nixorcollege.edu.pk/ws`) |
| Optional WS host/ports | `WS_HOST`, `WS_PORT`, `WS_HTTP_PORT` |

> Keep existing keys unchanged in `.env`; append new keys if missing.

## Database Setup

1. Create the schema via `sql/schema.sql`.
2. (Optional) Seed baseline entities: `php scripts/seed.php`.

### Troubleshooting: Database connection errors

- **`SQLSTATE[HY000] [1045] Access denied for user ''@'localhost' (using password: NO)`** – The application is trying to connect without credentials. Populate `DB_USER` and `DB_PASS` in your `.env` file (and ensure `DB_HOST`, `DB_PORT`, and `DB_NAME` are correct) so PDO can authenticate against MySQL.

### ERD Notes (textual)

- `User` has global `role` and can join entities via `EntityMembership` (role-scoped per entity).
- `Endeavour` belongs to an `Entity`, has tags via `EndeavourTag`, and registrations recorded in `Registration`.
- `HRNote`, `Participation`, `ConsentForm`, `Payment`, `ParentContact`, and `EmailLog` provide HR and compliance context.
- `RateLimit` tracks publish attempts for rolling windows; `AuditLog` tracks all sensitive actions.

## PHP API Highlights

- Automatic autoload + env bootstrap (`src/bootstrap.php`).
- `AuthService` verifies Google tokens, issues signed JWT for the WS server, and manages sessions.
- `RateLimitService` enforces publish quotas per entity (rolling 7-day window).
- `MailService` renders simple PHP templates and logs outbound emails.
- Every state-changing endpoint validates CSRF via double-submit header.

## Realtime Service

- Located at `python/ws_server.py` (Python 3.10+).
- Uses `websockets` for client connections and a lightweight HTTP publish endpoint.
- Verifies the same JWT secret issued by PHP.
- Rooms: `entity:{entityId}`, `endeavour:{endeavourId}`, `user:{userId}`.
- PHP can broadcast by POSTing to `http://WS_HOST:WS_HTTP_PORT/publish` with `Authorization: Bearer <JWT>` and payload `{"channels":[],"event":{}}`.

### Running the WS service

```
cd python
python3 -m venv .venv
source .venv/bin/activate
pip install websockets
python ws_server.py
```

Expose `WS_URL` (e.g. `wss://portal.example.com:8765/ws`). PHP will use this URL when returning session metadata.

### systemd Unit (example)

```
[Unit]
Description=Nixor Endeavour WS Server
After=network.target

[Service]
Type=simple
WorkingDirectory=/var/www/nixor-corporate-portal/python
EnvironmentFile=/var/www/nixor-corporate-portal/.env
ExecStart=/usr/bin/python3 ws_server.py
Restart=on-failure

[Install]
WantedBy=multi-user.target
```

## Frontend Pages

- `public/index.html` &mdash; Volunteer dashboard
- `public/hr.html` &mdash; HR shortlist management
- `public/admin.html` &mdash; Admin settings (visibility + quotas)
- `public/consent.html` &mdash; Consent capture flow

All pages load `/assets/js/app.js`, which handles session bootstrap, Google login, and API calls.

## Security Notes

- Content Security Policy enforced via `public/.htaccess`.
- HTTP-only SameSite=Lax session cookies.
- CSRF double-submit header (`X-CSRF-Token`).
- All DB access via prepared statements; JSON responses only.
- JWT expiry 30 minutes; WebSocket server rejects expired tokens.

## Testing

- PHP: manual via curl/Postman.
- Python: `pytest python/test_jwt_utils.py` to validate JWT verification logic.

## Deployment Checklist

1. Upload repository to cPanel (ensure PHP 8+).
2. Configure `.env` with database, Google, SMTP, JWT, and WS settings.
3. Import `sql/schema.sql`, then optionally run `php scripts/seed.php`.
4. Start the Python WS server (systemd or `nohup`).
5. Ensure `WS_URL` points to the running websocket endpoint.
6. Test login via Google One Tap or button; confirm realtime updates fire when creating registrations/endeavours.
