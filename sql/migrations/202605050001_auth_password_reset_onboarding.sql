ALTER TABLE users
  ADD COLUMN IF NOT EXISTS force_password_reset TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN IF NOT EXISTS password_setup_required TINYINT(1) NOT NULL DEFAULT 0 AFTER force_password_reset,
  ADD COLUMN IF NOT EXISTS password_changed_at DATETIME NULL AFTER password_setup_required,
  ADD COLUMN IF NOT EXISTS password_reset_forced_at DATETIME NULL AFTER password_changed_at,
  ADD COLUMN IF NOT EXISTS password_reset_forced_by INT NULL AFTER password_reset_forced_at,
  ADD COLUMN IF NOT EXISTS session_version INT NOT NULL DEFAULT 0 AFTER last_login_at,
  ADD INDEX IF NOT EXISTS idx_users_password_reset_flags (password_setup_required, force_password_reset),
  ADD INDEX IF NOT EXISTS idx_users_session_version (id, session_version),
  ADD INDEX IF NOT EXISTS idx_users_password_reset_forced_by (password_reset_forced_by),
  ADD CONSTRAINT IF NOT EXISTS fk_users_password_reset_forced_by FOREIGN KEY (password_reset_forced_by) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS auth_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_type ENUM('password_reset','password_setup') NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_ip VARCHAR(45) NULL,
  created_user_agent VARCHAR(255) NULL,
  created_by INT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY uniq_auth_tokens_hash (token_hash),
  KEY idx_auth_tokens_user_type_active (user_id, token_type, used_at, expires_at),
  KEY idx_auth_tokens_expiry (expires_at, used_at),
  KEY idx_auth_tokens_created_by (created_by),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
