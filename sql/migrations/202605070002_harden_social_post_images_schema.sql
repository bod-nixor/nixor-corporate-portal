CREATE TABLE IF NOT EXISTS social_post_images (
  id INT AUTO_INCREMENT PRIMARY KEY,
  post_id INT NOT NULL,
  file_drive_item_id INT NULL,
  image_url VARCHAR(1024) NULL,
  storage_path VARCHAR(255) NULL,
  original_name VARCHAR(190) NULL,
  mime_type VARCHAR(100) NULL,
  size_bytes INT NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_social_post_images_post (post_id, sort_order),
  CONSTRAINT fk_social_post_images_post FOREIGN KEY (post_id) REFERENCES social_posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_social_post_images_file_drive_item FOREIGN KEY (file_drive_item_id) REFERENCES file_drive_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE social_post_images
  ADD COLUMN IF NOT EXISTS file_drive_item_id INT NULL AFTER post_id,
  ADD COLUMN IF NOT EXISTS image_url VARCHAR(1024) NULL AFTER file_drive_item_id,
  ADD COLUMN IF NOT EXISTS storage_path VARCHAR(255) NULL AFTER image_url,
  ADD COLUMN IF NOT EXISTS original_name VARCHAR(190) NULL AFTER storage_path,
  ADD COLUMN IF NOT EXISTS mime_type VARCHAR(100) NULL AFTER original_name,
  ADD COLUMN IF NOT EXISTS size_bytes INT NOT NULL DEFAULT 0 AFTER mime_type,
  ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0 AFTER size_bytes,
  ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER sort_order,
  ADD INDEX IF NOT EXISTS idx_social_post_images_post (post_id, sort_order),
  ADD CONSTRAINT IF NOT EXISTS fk_social_post_images_post FOREIGN KEY (post_id) REFERENCES social_posts(id) ON DELETE CASCADE,
  ADD CONSTRAINT IF NOT EXISTS fk_social_post_images_file_drive_item FOREIGN KEY (file_drive_item_id) REFERENCES file_drive_items(id) ON DELETE SET NULL;
