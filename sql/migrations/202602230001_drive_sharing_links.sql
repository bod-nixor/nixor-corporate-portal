ALTER TABLE file_drive_items
  MODIFY COLUMN item_type ENUM('folder','file','link') NOT NULL,
  ADD COLUMN IF NOT EXISTS url VARCHAR(1024) NULL AFTER file_path,
  ADD COLUMN IF NOT EXISTS mime_type VARCHAR(190) NULL AFTER url,
  MODIFY COLUMN sharing_scope ENUM('private','entity','department','users') DEFAULT 'entity',
  ADD INDEX IF NOT EXISTS idx_entity_parent (entity_id, parent_id),
  ADD INDEX IF NOT EXISTS idx_type (item_type),
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
  KEY idx_share_user (user_id),
  FOREIGN KEY (item_id) REFERENCES file_drive_items(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);
