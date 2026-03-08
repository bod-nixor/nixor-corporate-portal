# Entity Drive Notes

This document captures the current Entity Drive architecture, expected user flows, and the pitfalls that surfaced during the production-readiness repair pass.

## Surface Area

### Frontend
- `public/entity_drive.html`
- `public/assets/entity_drive.js`
- Shared shell: `public/assets/sidebar.js`
- Shared fetch/CSRF: `public/assets/app.js`

### Backend
- `api/routes/drive.php`
- `api/lib/drive.php`
- `api/lib/uploads.php`
- `api/routes/files.php`

### Database
- `sql/migrations/202602230001_drive_sharing_links.sql`
- Core tables:
  - `file_drive_items`
  - `drive_item_shares`

## API Endpoints Used By The Page
- `GET /api/auth/me`
  - Loads the current user and entity memberships for the entity switcher.
- `GET /api/drive/list?entity_id={id}[&parent_id={id}]`
  - Returns the current folder contents and breadcrumb metadata.
- `GET /api/drive/item?id={id}`
  - Returns full item details for the inspector, including parent chain and sharing targets.
- `GET /api/drive/preview?id={id}`
  - Returns preview/open/download URLs for the inspector.
- `GET /api/drive/share_targets?entity_id={id}`
  - Returns departments and entity members for the sharing modal.
- `POST /api/drive/folder`
  - Creates a folder in the current parent folder.
- `POST /api/drive/link`
  - Creates a saved external link in the current parent folder.
- `POST /api/drive/upload`
  - Uploads a file into the current parent folder.
- `POST /api/drive/rename`
  - Renames a file, folder, or link.
- `POST /api/drive/share`
  - Updates sharing scope and share targets.
- `POST /api/drive/delete`
  - Deletes a file, link, or a folder tree.
- `GET /api/files/download?type=drive&id={id}`
  - Streams drive file downloads.
- `GET /api/drive/content?id={id}`
  - Streams inline file content for preview/open-external behavior.

## Expected Folder Navigation Flow
1. The page boots at root for the selected entity.
2. Clicking a folder name or folder card moves into that folder.
3. Breadcrumbs show the current path and allow jumping back to an ancestor.
4. The `Up one level` button moves to the immediate parent folder.
5. Search and sort operate within the current folder only.
6. Rename/share/delete refresh the current folder without dropping the current path.

## Rename, Delete, And Share Behavior
- Rename uses an in-page modal instead of `prompt()`.
- Delete uses an in-page confirmation modal instead of `confirm()`.
- Sharing uses an in-page modal with:
  - scope selector
  - department checklist
  - entity-member checklist
  - optional extra email entry for active users outside the current membership list
- The backend now returns `can_manage` so the UI can show management actions only when allowed.
- Parent-folder writes now require manage access to that parent folder for:
  - folder creation
  - link creation
  - file uploads

## Root Causes Fixed
- Folder opening was broken because row selection re-rendered the table before the original double-click/open flow could complete.
- The page had drifted away from the shared portal shell, so navigation appeared to disappear compared with the rest of the product.
- Drive actions relied too heavily on raw prompt/context flows, which made the page feel incomplete and fragile.
- Uploads emitted PHP warnings because MIME detection ran after the temporary file had already been moved.
- Download safety checks were not cross-platform safe for Windows path separators.
- Inspector/mobile overlay state relied on CSS utilities alone, which left controls or backdrops clickable in the wrong contexts.
- Lower-row action menus could render off-screen.

## Common Pitfalls
- Do not remove the shared portal sidebar from `public/entity_drive.html`.
- Do not reintroduce `prompt`, `confirm`, or `alert` for core Drive actions.
- If you add or hide inspector controls, keep the explicit hidden-state sync in `public/assets/entity_drive.js`.
- If you change folder write behavior, preserve the backend manage-access check on the parent folder.
- When touching uploads or downloads, test on Windows-style paths as well as Linux-style paths.

## Browser QA Baseline
- Open a folder and return with both breadcrumbs and `Up one level`.
- Rename one folder and one file.
- Delete one folder and one file.
- Change sharing scope and verify it persists after reload.
- Verify search, list/grid toggle, and entity switching.
- Verify the mobile menu button still exposes the portal sidebar.
