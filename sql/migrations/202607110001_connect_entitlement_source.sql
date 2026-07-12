CREATE TABLE IF NOT EXISTS connect_resource_mappings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  source_type ENUM('root','announcements','entity','department','board','leadership','project') NOT NULL,
  source_id VARCHAR(190) NOT NULL,
  resource_key VARCHAR(190) NOT NULL,
  resource_type ENUM('space','channel') NOT NULL DEFAULT 'channel',
  display_name VARCHAR(190) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE KEY uniq_connect_resource_source (source_type, source_id),
  UNIQUE KEY uniq_connect_resource_key (resource_key),
  KEY idx_connect_resource_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO connect_resource_mappings (source_type, source_id, resource_key, resource_type, display_name)
VALUES
('root', 'root', 'root', 'space', 'Nixor Connect'),
('announcements', 'announcements', 'announcements', 'channel', 'Announcements'),
('board', 'board', 'board', 'channel', 'Board'),
('leadership', 'leadership', 'leadership', 'channel', 'Leadership')
ON DUPLICATE KEY UPDATE
  resource_key = VALUES(resource_key),
  resource_type = VALUES(resource_type),
  display_name = VALUES(display_name),
  active = 1;

CREATE TABLE IF NOT EXISTS connect_entitlement_outbox (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  event_id VARCHAR(80) NOT NULL,
  ncp_user_id VARCHAR(190) NOT NULL,
  reason VARCHAR(120) NOT NULL,
  payload_json JSON NOT NULL,
  status ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
  attempts INT NOT NULL DEFAULT 0,
  next_attempt_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  last_error TEXT NULL,
  occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  sent_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE KEY uniq_connect_entitlement_outbox_event (event_id),
  KEY idx_connect_entitlement_outbox_pending (status, next_attempt_at, id),
  KEY idx_connect_entitlement_outbox_user (ncp_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
