# Nixor Corporate Portal - Agent Guidance

This file is the repo-specific contract for future agents and maintainers.

## Mandatory first steps
- Read `README.md`.
- Read this file.
- Search for other relevant docs before changing code:
  - `DEPLOYMENT.md`
  - `MIGRATION_GUIDE.md`
  - `QA_CHECKLIST.md`
  - `PRODUCTION_READINESS_REPORT.md`
- Build a concrete map of the feature area before proposing or making changes.

## Architecture Summary
- Backend: PHP API in `api/`.
- Frontend: static HTML/JS in `public/`.
- Shared frontend fetch/auth/CSRF logic: `public/assets/app.js`.
- Shared authenticated shell/navigation: `public/assets/sidebar.js`.
- Shared backend helpers: `api/lib/*.php`.
- Routes: `api/routes/*.php`.
- SQL schema and migrations: `sql/`.
- Optional websocket broadcaster: `ws/`.

## Non-negotiable constraints
- MariaDB/MySQL only.
- Do not introduce runtime schema mutations.
- Do not weaken sessions, CSRF, or backend RBAC.
- Do not commit secrets.
- Do not add dev/sample data to production pathways.

## Security Rules
- Treat session auth as the source of truth.
- CSRF must continue to be bootstrapped from `GET /api/auth/csrf`.
- Keep backend authorization checks in routes/helpers, not only in the UI.
- Only trust forwarded IP headers when the request comes from a trusted proxy.
- Do not expose raw stack traces or sensitive internals in API responses.

## Frontend Conventions
- Authenticated pages should use the shared shell from `public/assets/sidebar.js`.
- Do not introduce one-off sidebars or nav layouts for authenticated pages unless there is a strong product reason.
- Keep shared fetch logic inside `public/assets/app.js`.
- Reuse shared CSS tokens and component classes from `public/assets/global.css` / `public/assets/app.css`.
- Prefer explicit labels, keyboard-safe controls, and sane autocomplete attributes.
- Modals must be closable, must not trap the user under stale overlays, and should close after a successful create/update action unless the product clearly benefits from staying open.
- Avoid raw `prompt` / `confirm` / `alert` for polished flows when a real in-page control or modal is practical.

## Backend / API Conventions
- Keep response shapes consistent: `{ ok, data, error, meta }`.
- If a route enforces permissions, verify the route handler and any helper it delegates to.
- Keep route changes targeted. Do not refactor unrelated handlers while fixing one flow.
- Validate request data before writing to the DB or filesystem.

## Database / Migration Rules
- Add new migration files; do not quietly patch runtime schema.
- Prefer additive, guarded migrations.
- Do not edit already-applied migrations casually because the runner stores checksums.
- Fresh-install compatibility matters. If a migration pattern is DB-version-sensitive, prove it on a clean database.
- Keep dev-only seed behavior in `sql/dev/` and `scripts/seed_dev.php`.

## Local Development Rules
- Local built-in server entrypoint is:
  - `php -S 127.0.0.1:8000 router.php`
- The repo expects env values from root `.env`, `config/.env`, or `ENV_FILE_PATH`.
- If you use `ENV_FILE_PATH`, validate that the path resolution works on the current OS.

## Browser QA Expectations
For user-facing work, browser QA is required when feasible. Cover:
- Login and session continuity across multiple pages.
- Dashboard entity switching, announcements, and loading states.
- Admin entities/users/memberships flows.
- Entity endeavours lifecycle screens.
- Drive navigation, preview, and create/share actions.
- Calendar and social pages.
- Mobile width behavior, especially nav/sidebar access.

Use `QA_CHECKLIST.md` as the baseline.

## Common Pitfalls In This Repo
- Assuming there is a SPA framework. There is not.
- Forgetting that `public/assets/app.js` owns CSRF header behavior.
- Duplicating sidebars instead of using `public/assets/sidebar.js`.
- Shipping flows that succeed but leave blocking overlays or stale dropdowns open.
- Treating browser prompt/confirm flows as “finished” UX.
- Editing applied migration files and causing checksum mismatches later.
- Forgetting to test on a fresh DB after migration changes.
- Relying on spoofable forwarded headers for security-sensitive logic.

## What Good Changes Look Like
- Small, reviewable diffs.
- Evidence-driven fixes tied to reproduced issues.
- Browser-verified user-flow improvements.
- Documentation updated when the mental model changes.
- Clear handoff notes in the final response: what changed, what was verified, and what still needs attention.
