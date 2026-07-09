CREATE TABLE IF NOT EXISTS connect_google_identities (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  google_sub VARCHAR(190) NULL,
  email VARCHAR(190) NOT NULL,
  display_name VARCHAR(190) NOT NULL,
  matrix_user_id VARCHAR(255) NULL,
  is_allowed TINYINT(1) NOT NULL DEFAULT 0,
  is_school_admin TINYINT(1) NOT NULL DEFAULT 0,
  is_approved_developer TINYINT(1) NOT NULL DEFAULT 0,
  developer_permissions TEXT NOT NULL,
  owned_developer_app_ids TEXT NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE KEY uniq_connect_google_identities_email (email),
  UNIQUE KEY uniq_connect_google_identities_google_sub (google_sub),
  KEY idx_connect_google_identities_user (user_id),
  KEY idx_connect_google_identities_allowed (is_allowed),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS connect_user_memberships (
  id INT AUTO_INCREMENT PRIMARY KEY,
  identity_id INT NOT NULL,
  server_public_id VARCHAR(190) NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'member',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE KEY uniq_connect_user_membership_server (identity_id, server_public_id),
  KEY idx_connect_user_memberships_identity (identity_id),
  KEY idx_connect_user_memberships_server (server_public_id),
  FOREIGN KEY (identity_id) REFERENCES connect_google_identities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO rbac_permissions (code, description) VALUES
('admin.manage_connect', 'Manage Nixor Connect Google SSO entitlements');

INSERT IGNORE INTO rbac_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM rbac_roles r
JOIN rbac_permissions p ON p.code = 'admin.manage_connect'
WHERE r.code = 'site_admin';
