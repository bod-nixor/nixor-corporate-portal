-- NOTE: This migration uses MariaDB-compatible IF NOT EXISTS clauses for column/index adds.
-- For MySQL deployments, run equivalent guarded ALTER statements manually before applying.

-- Normalize legacy value before enum change to avoid invalid enum coercion.
UPDATE file_drive_items
SET sharing_scope = 'users'
WHERE sharing_scope = 'public';

ALTER TABLE file_drive_items
  MODIFY COLUMN item_type ENUM('folder','file','link') NOT NULL,
  ADD COLUMN IF NOT EXISTS url VARCHAR(1024) NULL AFTER file_path,
  ADD COLUMN IF NOT EXISTS mime_type VARCHAR(190) NULL AFTER url,
  MODIFY COLUMN sharing_scope ENUM('private','entity','department','users') DEFAULT 'entity',
  ADD INDEX IF NOT EXISTS idx_entity_parent (entity_id, parent_id),
  ADD INDEX IF NOT EXISTS idx_entity_type_parent (entity_id, item_type, parent_id),
  ADD INDEX IF NOT EXISTS idx_created_by (created_by);

CREATE TABLE IF NOT EXISTS drive_item_shares (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  share_type ENUM('department','user') NOT NULL,
  department ENUM('operations','finance','hr','communications','management','other') NULL,
  user_id INT NULL,
  user_email VARCHAR(190) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  KEY idx_item_share (item_id, share_type),
  KEY idx_share_user_id (user_id),
  KEY idx_share_user_email (user_email),

  CONSTRAINT fk_drive_item_shares_item
    FOREIGN KEY (item_id) REFERENCES file_drive_items(id) ON DELETE CASCADE,

  CONSTRAINT fk_drive_item_shares_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

DELIMITER //

CREATE TRIGGER drive_item_shares_bi
BEFORE INSERT ON drive_item_shares
FOR EACH ROW
BEGIN
  IF NEW.share_type = 'department' THEN
    IF NEW.department IS NULL OR NEW.user_id IS NOT NULL OR NEW.user_email IS NOT NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid department share row';
    END IF;
  ELSEIF NEW.share_type = 'user' THEN
    IF NEW.department IS NOT NULL OR (NEW.user_id IS NULL AND NEW.user_email IS NULL) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid user share row';
    END IF;
  END IF;
END//

CREATE TRIGGER drive_item_shares_bu
BEFORE UPDATE ON drive_item_shares
FOR EACH ROW
BEGIN
  IF NEW.share_type = 'department' THEN
    IF NEW.department IS NULL OR NEW.user_id IS NOT NULL OR NEW.user_email IS NOT NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid department share row';
    END IF;
  ELSEIF NEW.share_type = 'user' THEN
    IF NEW.department IS NOT NULL OR (NEW.user_id IS NULL AND NEW.user_email IS NULL) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid user share row';
    END IF;
  END IF;
END//

DELIMITER ;
