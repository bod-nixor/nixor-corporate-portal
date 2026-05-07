ALTER TABLE social_post_images
  ADD COLUMN IF NOT EXISTS storage_path VARCHAR(255) NULL AFTER image_url,
  ADD COLUMN IF NOT EXISTS original_name VARCHAR(190) NULL AFTER storage_path,
  ADD COLUMN IF NOT EXISTS mime_type VARCHAR(100) NULL AFTER original_name,
  ADD COLUMN IF NOT EXISTS size_bytes INT NOT NULL DEFAULT 0 AFTER mime_type;

