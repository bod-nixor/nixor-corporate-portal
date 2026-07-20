ALTER TABLE connect_google_identities
  ADD COLUMN IF NOT EXISTS profile_image_url VARCHAR(1000) NULL AFTER display_name,
  ADD COLUMN IF NOT EXISTS google_verified_at DATETIME(6) NULL AFTER profile_image_url,
  ADD COLUMN IF NOT EXISTS last_google_login_at DATETIME(6) NULL AFTER google_verified_at;

CREATE TABLE IF NOT EXISTS connect_matrix_id_mappings (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  subject_key VARCHAR(190) NOT NULL,
  user_id INT NULL,
  identity_id INT NULL,
  matrix_user_id VARCHAR(255) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE KEY uniq_connect_matrix_subject (subject_key),
  UNIQUE KEY uniq_connect_matrix_user_id (matrix_user_id),
  UNIQUE KEY uniq_connect_matrix_user (user_id),
  UNIQUE KEY uniq_connect_matrix_identity (identity_id),
  CONSTRAINT fk_connect_matrix_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_connect_matrix_identity FOREIGN KEY (identity_id) REFERENCES connect_google_identities(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS connect_entitlement_versions (
  ncp_user_id VARCHAR(190) PRIMARY KEY,
  user_id INT NULL,
  entitlement_version VARCHAR(80) NOT NULL,
  state_json JSON NOT NULL,
  changed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  last_enqueued_version VARCHAR(80) NULL,
  last_enqueued_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE KEY uniq_connect_entitlement_version_user (user_id),
  KEY idx_connect_entitlement_versions_changed (changed_at),
  CONSTRAINT fk_connect_entitlement_version_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS connect_entitlement_reconciliation_state (
  id TINYINT PRIMARY KEY,
  last_user_id INT NOT NULL DEFAULT 0,
  last_started_at DATETIME(6) NULL,
  last_completed_at DATETIME(6) NULL,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO connect_entitlement_reconciliation_state (id, last_user_id)
VALUES (1, 0)
ON DUPLICATE KEY UPDATE id = VALUES(id);

ALTER TABLE connect_entitlement_outbox
  MODIFY COLUMN status ENUM('queued','sending','sent','failed','dead_letter') NOT NULL DEFAULT 'queued',
  ADD COLUMN IF NOT EXISTS entitlement_version VARCHAR(80) NULL AFTER reason,
  ADD COLUMN IF NOT EXISTS claim_token CHAR(32) NULL AFTER attempts,
  ADD COLUMN IF NOT EXISTS claimed_at DATETIME(6) NULL AFTER claim_token,
  ADD COLUMN IF NOT EXISTS last_attempt_at DATETIME(6) NULL AFTER claimed_at,
  ADD COLUMN IF NOT EXISTS dead_lettered_at DATETIME(6) NULL AFTER sent_at,
  ADD INDEX IF NOT EXISTS idx_connect_entitlement_outbox_claim (claim_token),
  ADD INDEX IF NOT EXISTS idx_connect_entitlement_outbox_lease (status, claimed_at);
