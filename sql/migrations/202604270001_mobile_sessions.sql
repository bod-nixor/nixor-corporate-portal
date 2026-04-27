ALTER TABLE users ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mobile_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  device_label VARCHAR(190) NULL,
  platform ENUM('ios','android','unknown') NOT NULL DEFAULT 'unknown',
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  last_used_at DATETIME NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY uniq_mobile_sessions_token_hash (token_hash),
  KEY idx_mobile_sessions_user (user_id),
  KEY idx_mobile_sessions_user_active (user_id, revoked_at, expires_at),
  KEY idx_mobile_sessions_expiry (expires_at, revoked_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
