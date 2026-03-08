# Migration Guide

This repo uses manual SQL migrations for MariaDB/MySQL deployments.

## Principles
- Add a new file in `sql/migrations/` for every schema change.
- Never hide schema mutations inside request handlers.
- Keep migrations compatible with MariaDB/MySQL and friendly to cPanel-style deployments.
- Treat fresh-install success as a release requirement.

## Naming
- Use sortable timestamps:
  - `YYYYMMDDHHMM_description.sql`

## Authoring Rules
- Prefer additive changes.
- Use guarded patterns where practical.
- Keep statements simple and explicit.
- Avoid editing previously applied migrations unless you are intentionally coordinating checksum resets.

## Runner Behavior
- The PHP migration runner stores a checksum for each applied file.
- The runner now supports guarded `ALTER TABLE` operations such as:
  - `ADD COLUMN IF NOT EXISTS`
  - `ADD INDEX IF NOT EXISTS`
- Even with that support, prefer straightforward statements over clever multi-purpose SQL when you can.

## Safe Workflow
1. Add the new migration file.
2. Run migrations against an empty database.
3. Run migrations against a database that already has prior migrations applied.
4. Verify the affected user flow in the browser.
5. Document any manual rollout notes in `README.md` or `DEPLOYMENT.md` if the change is operationally meaningful.

## Rollback Guidance
- Where feasible, document the reverse SQL in the pull request or release notes.
- Do not assume shared-hosting environments support complex transactional DDL rollbacks.
- Favor changes that can be reversed with a clean follow-up migration.

## Dev Seeds
- Keep dev-only data in `sql/dev/`.
- Do not mix dev seed data into production migrations.
- If sample credentials exist, label them clearly as development-only.
