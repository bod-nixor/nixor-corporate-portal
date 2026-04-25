-- Add atomic entity-name duplicate protection and persist optional calendar event end dates.
-- If the unique index fails on an existing database, review duplicate entity names before retrying.

ALTER TABLE entities
  MODIFY COLUMN name VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  ADD UNIQUE INDEX IF NOT EXISTS idx_entities_name_unique (name);

ALTER TABLE calendar_events
  ADD COLUMN IF NOT EXISTS end_date DATETIME NULL AFTER event_date;
