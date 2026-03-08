# QA Checklist

Use this checklist for release candidates, major feature work, and any agent-driven polish pass.

## Environment
- App boots with `php -S 127.0.0.1:8000 router.php`.
- API responds at `/api/auth/csrf`.
- Authenticated session persists across multiple pages, not only `/api/auth/*`.
- Fresh DB migrations complete without manual patching.

## Authentication
- Login works with valid credentials.
- Invalid credentials show a clean inline error.
- Logout clears the active session.
- Protected routes return `401` or `403` cleanly without fatal output.

## Dashboard
- Entity selector loads.
- Metrics, meetings, deadlines, and announcements render for a valid entity.
- Announcement creation succeeds.
- Announcement modal closes after success and does not leave a blocking overlay behind.
- Empty states render cleanly when there is no data.

## Admin
- Summary metrics load for an admin.
- Entity creation works and closes the modal after success.
- User creation works.
- Membership assignment works.
- Duplicate/invalid submissions show useful errors.

## Entity Endeavours
- Entity selector loads.
- Existing endeavours render.
- Create endeavour works.
- Document/approval controls render without JS errors.
- Volunteer-management controls load without breaking the page.

## Volunteering
- Opportunities list loads.
- Search and entity filters work.
- Register flow works when an endeavour is in volunteer-registration phase.
- Empty state is accurate when nothing is available.

## Drive
- Root list loads.
- Breadcrumbs update when entering folders.
- Folder and link creation work.
- Upload works.
- Inspector preview updates on selection.
- Share modal opens and saves.
- Bulk selection state is accurate.

## Calendar
- Events load for the selected entity.
- Create event works.
- Date/time field is labeled and usable.
- Mobile navigation remains accessible.

## Social
- Posts load.
- New post works.
- Comment creation works.
- Mobile navigation remains accessible.

## Responsive
- Dashboard is usable at ~390px wide.
- Calendar and social pages retain navigation at mobile widths.
- Sidebar toggle is reachable and labeled.
- Tables or grids do not become impossible to use on small screens.

## Security Smoke
- CSRF token is required for protected mutating routes.
- Rate limiting does not trust spoofed forwarded IP headers unless a trusted proxy is configured.
- Backend authorization still blocks unauthorized actions even if the frontend is manipulated.
