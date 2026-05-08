-- NOTE: This migration uses MariaDB-compatible IF NOT EXISTS clauses for column and index adds.
-- It is intended for MariaDB deployments.
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS public_id VARCHAR(64) NULL AFTER id,
  ADD UNIQUE INDEX IF NOT EXISTS uniq_users_public_id (public_id);

UPDATE users
SET public_id = CONCAT('usr_', REPLACE(UUID(), '-', ''))
WHERE public_id IS NULL OR public_id = '';

ALTER TABLE entities
  ADD COLUMN IF NOT EXISTS public_id VARCHAR(64) NULL AFTER id,
  ADD COLUMN IF NOT EXISTS avatar_path VARCHAR(255) NULL AFTER description,
  ADD COLUMN IF NOT EXISTS avatar_mime_type VARCHAR(120) NULL AFTER avatar_path,
  ADD COLUMN IF NOT EXISTS avatar_original_name VARCHAR(190) NULL AFTER avatar_mime_type,
  ADD UNIQUE INDEX IF NOT EXISTS uniq_entities_public_id (public_id);

UPDATE entities
SET public_id = CONCAT('ent_', REPLACE(UUID(), '-', ''))
WHERE public_id IS NULL OR public_id = '';

ALTER TABLE endeavours
  ADD COLUMN IF NOT EXISTS public_id VARCHAR(64) NULL AFTER id,
  ADD UNIQUE INDEX IF NOT EXISTS uniq_endeavours_public_id (public_id);

UPDATE endeavours
SET public_id = CONCAT('end_', REPLACE(UUID(), '-', ''))
WHERE public_id IS NULL OR public_id = '';

ALTER TABLE file_drive_items
  ADD COLUMN IF NOT EXISTS public_id VARCHAR(64) NULL AFTER id,
  ADD UNIQUE INDEX IF NOT EXISTS uniq_file_drive_items_public_id (public_id);

UPDATE file_drive_items
SET public_id = CONCAT('drv_', REPLACE(UUID(), '-', ''))
WHERE public_id IS NULL OR public_id = '';

ALTER TABLE calendar_events
  ADD COLUMN IF NOT EXISTS public_id VARCHAR(64) NULL AFTER id,
  ADD UNIQUE INDEX IF NOT EXISTS uniq_calendar_events_public_id (public_id);

UPDATE calendar_events
SET public_id = CONCAT('cal_', REPLACE(UUID(), '-', ''))
WHERE public_id IS NULL OR public_id = '';

ALTER TABLE dashboard_announcements
  ADD COLUMN IF NOT EXISTS public_id VARCHAR(64) NULL AFTER id,
  ADD UNIQUE INDEX IF NOT EXISTS uniq_dashboard_announcements_public_id (public_id);

UPDATE dashboard_announcements
SET public_id = CONCAT('ann_', REPLACE(UUID(), '-', ''))
WHERE public_id IS NULL OR public_id = '';

ALTER TABLE social_posts
  ADD COLUMN IF NOT EXISTS public_id VARCHAR(64) NULL AFTER id,
  ADD UNIQUE INDEX IF NOT EXISTS uniq_social_posts_public_id (public_id);

UPDATE social_posts
SET public_id = CONCAT('pst_', REPLACE(UUID(), '-', ''))
WHERE public_id IS NULL OR public_id = '';

ALTER TABLE social_comments
  ADD COLUMN IF NOT EXISTS public_id VARCHAR(64) NULL AFTER id,
  ADD COLUMN IF NOT EXISTS parent_comment_id INT NULL AFTER post_id,
  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT NULL AFTER created_at,
  ADD UNIQUE INDEX IF NOT EXISTS uniq_social_comments_public_id (public_id),
  ADD INDEX IF NOT EXISTS idx_social_comments_parent (parent_comment_id),
  ADD CONSTRAINT IF NOT EXISTS fk_social_comments_parent FOREIGN KEY (parent_comment_id) REFERENCES social_comments(id) ON DELETE CASCADE;

UPDATE social_comments
SET public_id = CONCAT('cmt_', REPLACE(UUID(), '-', ''))
WHERE public_id IS NULL OR public_id = '';

ALTER TABLE social_post_images
  ADD COLUMN IF NOT EXISTS public_id VARCHAR(64) NULL AFTER id,
  ADD UNIQUE INDEX IF NOT EXISTS uniq_social_post_images_public_id (public_id);

UPDATE social_post_images
SET public_id = CONCAT('img_', REPLACE(UUID(), '-', ''))
WHERE public_id IS NULL OR public_id = '';

CREATE TABLE IF NOT EXISTS push_device_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  platform ENUM('ios','android','web') NOT NULL,
  token VARCHAR(512) NOT NULL,
  device_id VARCHAR(190) NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_seen_at TIMESTAMP NULL,
  UNIQUE KEY uniq_push_token (token),
  KEY idx_push_user_enabled (user_id, enabled),
  KEY idx_push_device (user_id, device_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_notification_deliveries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  notification_id INT NOT NULL,
  device_token_id INT NOT NULL,
  status ENUM('queued','sent','failed','skipped') NOT NULL DEFAULT 'queued',
  error_message VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_push_delivery (notification_id, device_token_id),
  KEY idx_push_delivery_notification (notification_id),
  FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
  FOREIGN KEY (device_token_id) REFERENCES push_device_tokens(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
