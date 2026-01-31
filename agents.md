# Nixor Corporate Portal — Agent Guidance

## 1) Project Overview
- Repository hosts the Nixor Corporate Portal with a PHP 8 API and a static HTML/JS frontend.
- Backend lives under `/api` and serves the API consumed by the frontend in `/public`.
- Treat this document as the source of truth for agent behavior and assumptions.

## 2) Tech Stack & Deployment Constraints
- Backend: PHP 8 API located in `/api`.
  - Routes are defined in `api/routes/*`.
  - Shared logic lives in `api/lib/*`.
- Frontend: static HTML/JS under `/public`.
  - `public/assets/app.js` is responsible for API calls and CSRF headers.
- Database: MariaDB/MySQL ONLY.
  - Schema and migrations live in `/sql`.
- Deployment: cPanel shared hosting.
  - Environment variables are loaded via `.env` parsing.
  - Secrets must NEVER be committed.

## 3) Database Rules
- Schema and migrations belong in `/sql`.
- Dev-only seeds are allowed only when explicitly required for development and must be segregated from production migrations.
- DO NOT auto-seed production data at runtime.
- DO NOT add mock/sample data to production flows.
- DO NOT introduce any database other than MariaDB/MySQL.

## 4) Security Principles
- Security is first-class and must be preserved in all changes.
- Sessions
  - Use session-based authentication where applicable.
  - Preserve existing session handling; do not bypass or weaken session checks.
- CSRF
  - CSRF is session-based and bootstrapped via `/auth/csrf`.
  - Frontend must continue to use `public/assets/app.js` to set CSRF headers.
  - DO NOT remove, disable, or bypass CSRF protections.
- Authentication & authorization
  - Enforce least-privilege access rules.
  - DO NOT add routes or handlers without explicit auth/authorization requirements.
  - DO NOT leak sensitive data in responses or logs.

## 5) How Agents Should Investigate Issues
- Investigation-first rule: read and confirm relevant code paths before proposing changes.
- Use targeted searches and file reads; avoid broad speculative edits.
- No speculative fixes: if evidence is missing, continue investigating until grounded.

## 6) How Agents Should Make Changes
- Keep changes small and reviewable.
- Use clear, descriptive commit messages.
- Prefer minimal diffs that preserve existing behavior unless explicitly required to change.
- DO NOT refactor or rename without a verified need and explicit scope.

## 7) Testing Expectations
- Run relevant tests or checks when feasible.
- If no tests exist for the change, explain in the final report.
- DO NOT introduce test data seeding into production pathways.

## 8) Common Pitfalls (based on this codebase)
- Assuming a SPA framework or build step exists (it is static HTML/JS).
- Adding non-MySQL database dependencies.
- Bypassing or removing CSRF session flows.
- Introducing auto-seeding of production data.
- Committing secrets or relying on non-cPanel deployment assumptions.

## 9) How to Prompt an Agent (examples)
- Investigation prompt
  - "Investigate how CSRF headers are applied in the frontend and summarize the flow with file references. Do not propose changes."
- Fix/implementation prompt
  - "Fix the API route in `api/routes/*` to return the correct status code for unauthorized access. Update only what is necessary and include tests or explain why none were run."
