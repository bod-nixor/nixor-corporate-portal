# Nixor Corporate Portal

Nixor Corporate Portal is a PHP 8.1+ and static-JS internal portal for entity operations, approvals, volunteering, shared drive content, calendar events, social updates, and admin/user management.

## What the product includes
- Session-based authentication with CSRF bootstrapped from `GET /api/auth/csrf`.
- Email/password login for Nixor and non-Nixor accounts, with Google SSO restricted to `@nixorcollege.edu.pk`.
- Password reset and first-login setup links backed by hashed, expiring, single-use tokens.
- Entity dashboard with announcements, meetings, deadlines, and documentation progress.
- Entity endeavour lifecycle management with approvals, volunteer registration, and document attachment flows.
- Entity drive with folders, files, links, sharing scopes, previews, and bulk actions.
- Calendar and social feed modules scoped to entities.
- Admin tools for entities, users, memberships, and high-level operational metrics.

## Architecture Overview

### Backend
- `api/index.php`: API entrypoint and route dispatcher.
- `api/routes/*.php`: route handlers grouped by domain.
- `api/lib/*.php`: shared infrastructure for auth, DB, env loading, migrations, uploads, drive access, security headers, mail, and websocket events.
- All API responses are JSON.

### Frontend
- `public/*.html`: page entrypoints.
- `public/assets/app.js`: shared fetch wrapper, CSRF bootstrap, config loading, websocket/polling helpers, and error normalization.
- `public/assets/sidebar.js`: shared authenticated shell/sidebar renderer.
- `public/assets/*.js`: module/page behavior.
- `public/entity_drive.html` + `public/assets/entity_drive.js`: Google-Drive-inspired entity document browser with breadcrumbs, inspector, sharing modal, and list/grid controls.
- `public/assets/global.css` -> `public/assets/app.css`: shared design tokens and generated Tailwind output.

### Database
- MariaDB/MySQL only.
- `sql/migrations/*.sql`: ordered schema migrations.
- `sql/dev/*.sql`: dev-only reference/sample seeds.
- `scripts/migrate.php`: migration runner.
- `scripts/seed_dev.php`: guarded dev seed entrypoint.

### Realtime / async
- `ws/server.py`: optional websocket broadcaster.
- `cron/run.php`: cron-safe maintenance entrypoint; automated digest/reminder email jobs are disabled.

## Repo Layout
- `api/`
- `public/`
- `sql/`
- `scripts/`
- `tests/`
- `ws/`
- `cron/`
- `config/`

## Requirements
- PHP 8.1 or newer.
- MariaDB or MySQL.
- Node.js/npm only if you need to rebuild CSS.
- Composer only if you need PHP dependencies such as Google auth verification or the PHPUnit test suite.

## Local Setup

### 1. Configure environment
Copy one of the example env files:

```bash
cp .env.example .env
```

You can also use `config/.env`. The app will read either root `.env`, `config/.env`, or a file pointed to by `ENV_FILE_PATH`.

Important values:
- `APP_ENV`
- `BASE_URL`
- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
- `UPLOAD_PATH`
- `LOG_PATH`
- `SESSION_COOKIE_PATH`

Recommended local defaults:
- `APP_ENV=development`
- `BASE_URL=http://127.0.0.1:8000`
- `SESSION_COOKIE_PATH=/`

### 2. Create the database
Create an empty MariaDB/MySQL database before running migrations.

### 3. Apply migrations

```bash
php scripts/migrate.php
```

If you are using a non-default env file:

```bash
ENV_FILE_PATH=.env php scripts/migrate.php
```

### 4. Optional dev seed
Dev-only seed data is guarded behind `APP_ENV=development` and `ALLOW_DEV_SEED=true`.

```bash
APP_ENV=development ALLOW_DEV_SEED=true php scripts/seed_dev.php
```

Seed notes:
- `sql/dev/seed_reference_data.sql` creates base entity/type data.
- `sql/dev/seed_sample_data.sql` creates example users and sample records.
- Seeded sample user password: `Password123!`

Admin-created users do not receive admin-set temporary passwords. New accounts are created with password setup required and receive a setup link through the configured mail helper.

### 5. Create the first admin
If you are not using dev seeds, create the first admin through `POST /api/admin/setup`.

Because setup is still CSRF-protected, first request `GET /api/auth/csrf` to obtain:
- a session cookie
- `data.csrfToken`

Then call `POST /api/admin/setup` with:

```json
{
  "email": "admin@example.com",
  "full_name": "Portal Admin",
  "password": "YourSecurePassword123!"
}
```

In production, include the configured setup token as documented in [DEPLOYMENT.md](DEPLOYMENT.md).

### 6. Run the local app
Use the built-in router from repo root:

```bash
php -S 127.0.0.1:8000 router.php
```

Then open:
- UI: `http://127.0.0.1:8000/login.html`
- API: `http://127.0.0.1:8000/api`

### 7. Rebuild CSS if you changed `public/assets/global.css`

```bash
npm install
npm run build:css
```

## Development Workflow
- Make backend changes in `api/routes/*` or `api/lib/*`.
- Make page-specific UI changes in `public/*.html` and `public/assets/*.js`.
- Keep shared layout/navigation behavior in `public/assets/sidebar.js`.
- Keep shared fetch/auth/CSRF behavior in `public/assets/app.js`.
- Keep Drive-specific navigation, inspector, and action-state behavior in `public/assets/entity_drive.js`; do not duplicate it into other modules.
- Add schema changes only through new files in `sql/migrations/`.
- Run targeted browser QA after every user-facing change.
- Run PHP linting and whatever tests are available in your environment.

## Testing

### PHP / route checks
- Lint changed PHP files:

```bash
php -l path/to/file.php
```

### Automated tests
If Composer dependencies are installed:

```bash
composer install
composer test
```

### Browser QA
Use the checklist in [QA_CHECKLIST.md](QA_CHECKLIST.md) for manual regression coverage.

## Migration Workflow
- Add a new timestamped file to `sql/migrations/`.
- Do not silently mutate schema in runtime code.
- Do not edit already-applied migrations unless you are intentionally coordinating checksum resets across environments.
- Prefer additive, guarded changes.
- Smoke-test migrations on a fresh empty database before shipping.

See [MIGRATION_GUIDE.md](MIGRATION_GUIDE.md) for the detailed rules and patterns used in this repo.

For Nixor Connect production rollout, use the targeted migration, configuration, worker, monitoring, and rollback procedure in [CONNECT_OPERATIONS.md](CONNECT_OPERATIONS.md). Do not run the full historical NCP migration set in production until its ledger has been independently baselined.

## Deployment Assumptions
- Shared hosting / cPanel friendly.
- Apache routes public traffic into `public/`.
- Secrets live in env/config, never in git.
- Uploads and logs should live outside the public web root.
- Websockets are optional; the app can fall back to polling.

See [DEPLOYMENT.md](DEPLOYMENT.md) for the cPanel-oriented deployment flow.

## Troubleshooting

### Local server returns 404s for `/login.html` or `/assets/*`
Run the app with:

```bash
php -S 127.0.0.1:8000 router.php
```

### Login succeeds but other authenticated pages look logged out
Check `SESSION_COOKIE_PATH`. It should resolve to `/`, not a route-specific path.

### Migrations fail on guarded `ALTER TABLE` statements
Use the current migration runner and smoke-test on a clean DB. The repo now supports guarded `ADD COLUMN IF NOT EXISTS` and `ADD INDEX IF NOT EXISTS` patterns during migration execution.

### Dev seed data loads but logins still fail
Make sure you ran the current `sql/dev/seed_sample_data.sql`; the seeded sample users now share the dev password `Password123!`.

### Mobile pages lose navigation
Authenticated pages should render the shared shell from `public/assets/sidebar.js`. Avoid standalone sidebars that disappear on small screens.

### Entity Drive actions appear hidden or an overlay blocks clicks
`public/assets/entity_drive.js` now manages the Drive inspector and action-menu visibility with explicit hidden-state toggles. If you change those controls, verify both desktop and mobile widths in the browser.

## Related Docs
- [agents.md](agents.md)
- [CONNECT_OPERATIONS.md](CONNECT_OPERATIONS.md)
- [DEPLOYMENT.md](DEPLOYMENT.md)
- [ENTITY_DRIVE_NOTES.md](ENTITY_DRIVE_NOTES.md)
- [MIGRATION_GUIDE.md](MIGRATION_GUIDE.md)
- [QA_CHECKLIST.md](QA_CHECKLIST.md)
- [PRODUCTION_READINESS_REPORT.md](PRODUCTION_READINESS_REPORT.md)
- [docs/mobile-capacitor.md](docs/mobile-capacitor.md)
