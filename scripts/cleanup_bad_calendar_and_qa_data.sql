-- NCP QA cleanup review script.
-- This script is intentionally NOT a migration and is not executed automatically.
-- It previews exact QA/bad-data records, performs scoped deletes inside a transaction,
-- and rolls back by default. Review the SELECT output first; change ROLLBACK to COMMIT
-- only after confirming the records are safe to remove in the target environment.

START TRANSACTION;

-- Preview garbage/test entities called exactly "sfef".
SELECT id, name, description, created_at
FROM entities
WHERE name = 'sfef';

-- Preview impossible calendar events and QA-created calendar events.
SELECT id, entity_id, title, event_date, created_by
FROM calendar_events
WHERE DATE(event_date) = '1111-11-01'
   OR LEFT(title, 19) = 'QA_TEST_DO_NOT_USE_';

-- Preview QA endeavour records.
SELECT id, entity_id, name, phase, created_at
FROM endeavours
WHERE LEFT(name, 19) = 'QA_TEST_DO_NOT_USE_';

-- Preview QA Drive records.
SELECT id, entity_id, parent_id, item_type, name, created_at
FROM file_drive_items
WHERE LEFT(name, 19) = 'QA_TEST_DO_NOT_USE_';

-- Preview QA social posts. XSS test posts should be reviewed manually before deletion
-- unless they also use the QA_TEST_DO_NOT_USE_ prefix.
SELECT id, entity_id, user_id, LEFT(content, 200) AS content_preview, created_at
FROM social_posts
WHERE LEFT(content, 19) = 'QA_TEST_DO_NOT_USE_';

-- Preview QA dashboard announcements.
SELECT id, entity_id, title, LEFT(message, 200) AS message_preview, created_by, created_at
FROM dashboard_announcements
WHERE LEFT(title, 19) = 'QA_TEST_DO_NOT_USE_'
   OR LEFT(message, 19) = 'QA_TEST_DO_NOT_USE_'
   OR title = 'QA Test Announcement';

-- Scoped cleanup deletes. Child rows are handled by existing foreign keys where defined.
DELETE FROM dashboard_announcements
WHERE LEFT(title, 19) = 'QA_TEST_DO_NOT_USE_'
   OR LEFT(message, 19) = 'QA_TEST_DO_NOT_USE_'
   OR title = 'QA Test Announcement';
SELECT ROW_COUNT() AS dashboard_announcements_deleted;

DELETE FROM social_posts
WHERE LEFT(content, 19) = 'QA_TEST_DO_NOT_USE_';
SELECT ROW_COUNT() AS social_posts_deleted;

DELETE FROM calendar_events
WHERE DATE(event_date) = '1111-11-01'
   OR LEFT(title, 19) = 'QA_TEST_DO_NOT_USE_';
SELECT ROW_COUNT() AS calendar_events_deleted;

DELETE FROM file_drive_items
WHERE LEFT(name, 19) = 'QA_TEST_DO_NOT_USE_';
SELECT ROW_COUNT() AS file_drive_items_deleted;

DELETE FROM endeavours
WHERE LEFT(name, 19) = 'QA_TEST_DO_NOT_USE_';
SELECT ROW_COUNT() AS endeavours_deleted;

DELETE FROM entities
WHERE name = 'sfef';
SELECT ROW_COUNT() AS entities_deleted;

-- Manual review helper for very short announcement/post content mentioned in the QA report.
-- This is preview-only to avoid deleting legitimate terse posts.
SELECT id, entity_id, title, message, created_at
FROM dashboard_announcements
WHERE title IN ('asd', 'a', 'adwd', 'awdwd')
   OR message IN ('asd', 'a', 'adwd', 'awdwd');

SELECT id, entity_id, LEFT(content, 200) AS content_preview, created_at
FROM social_posts
WHERE content IN ('asd', 'a', 'adwd', 'awdwd')
   OR LOWER(content) REGEXP '<[[:space:]]*/?[[:space:]]*script([[:space:]>]|$)';

ROLLBACK;
-- COMMIT;
