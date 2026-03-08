# Production Readiness Report

## Summary
This pass covered code review, live browser QA, responsive checks, security review, and documentation cleanup across the portal. The highest-impact fixes were in local boot reliability, session continuity, migration compatibility, UI flow stability, responsive navigation, and agent/developer guidance.

## Key Findings and Fixes

### 1. Session continuity was broken outside `/api/auth/*`
- Issue:
  - Empty runtime values from `$_SERVER` were overriding defaults in `api/lib/env.php`.
  - That caused the session cookie path to collapse to route-local paths such as `/api/auth/`.
  - Result: login succeeded, but other authenticated pages quietly behaved as logged-out.
- Fix:
  - `api/lib/env.php` now ignores empty runtime fallbacks and correctly preserves defaults and file-loaded values.
  - Windows absolute paths are also resolved correctly now.

### 2. Fresh installs could fail on current MySQL
- Issue:
  - `202602230001_drive_sharing_links.sql` used guarded `ALTER TABLE` clauses that failed on the tested MySQL 8.0 setup.
- Fix:
  - `api/lib/migrations.php` now handles guarded `ADD COLUMN IF NOT EXISTS` and `ADD INDEX IF NOT EXISTS` operations during migration execution.
  - Clean-database migration was re-run successfully after the patch.

### 3. The documented local PHP server flow was incomplete
- Issue:
  - The repo had no built-in server router for serving `/public/*` and `/api/*` together under `php -S`.
- Fix:
  - Added `router.php`.
  - Updated docs to use `php -S 127.0.0.1:8000 router.php`.

### 4. The entity endeavour list was broken by a frontend runtime error
- Issue:
  - `public/assets/entity_endeavours.js` referenced `driveFilesForRequest` before it existed, causing the page to fall into a generic failure state.
- Fix:
  - The page now loads drive files and endeavours together and renders active endeavours correctly.

### 5. Successful modal actions left stale overlays behind
- Issue:
  - Dashboard announcement creation and admin entity creation left the modal open after success.
  - The lingering overlay could block navigation and pointer events.
- Fix:
  - Both flows now close cleanly after success and refresh the underlying page state.

### 6. Calendar and social pages drifted from the shared shell
- Issue:
  - They used standalone sidebars instead of the shared responsive shell.
  - On mobile widths, navigation disappeared.
- Fix:
  - Both pages now use `public/assets/sidebar.js`.
  - Mobile navigation is available again.
  - Calendar event fields are now explicitly labeled.

### 7. Rate limiting trusted spoofable forwarded IP headers
- Issue:
  - `api/lib/rate_limit.php` accepted `X-Forwarded-For` and `X-Real-IP` from any request.
- Fix:
  - Forwarded headers are now only trusted when the request comes from a configured trusted proxy.

### 8. Dev sample seed data was not useful for real local QA
- Issue:
  - The sample seed used placeholder password hashes and duplicated the reference entity.
- Fix:
  - Sample users now have a documented dev-only password and no longer duplicate the reference entity insert.

## Browser QA Coverage
- Login and session continuity
- Dashboard metrics and announcement publishing
- Admin entity/user/membership flows
- Entity endeavours creation and list rendering
- Drive root listing and folder creation
- Calendar event creation
- Social post and comment creation
- Mobile shell/navigation on dashboard, calendar, and social

## Docs Updated
- `README.md`
- `agents.md`
- `QA_CHECKLIST.md`
- `MIGRATION_GUIDE.md`

## Residual Notes
- Composer-based tests were not run in this environment because Composer/PHPUnit dependencies were not installed locally.
- The drive module still uses prompt-based creation flows, which are functional but less polished than the rest of the UI.
