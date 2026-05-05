ALTER TABLE users
  ADD COLUMN IF NOT EXISTS google_picture_url VARCHAR(1024) NULL AFTER google_id;

CREATE TABLE IF NOT EXISTS rbac_permissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_rbac_permission_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rbac_roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(120) NOT NULL,
  name VARCHAR(190) NOT NULL,
  scope ENUM('global','entity','both') NOT NULL DEFAULT 'entity',
  description TEXT NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_rbac_role_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rbac_role_permissions (
  role_id INT NOT NULL,
  permission_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (role_id, permission_id),
  FOREIGN KEY (role_id) REFERENCES rbac_roles(id) ON DELETE CASCADE,
  FOREIGN KEY (permission_id) REFERENCES rbac_permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rbac_user_roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  role_id INT NOT NULL,
  entity_id INT NULL,
  assigned_by INT NULL,
  assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NULL,
  KEY idx_rbac_user_roles_user (user_id, entity_id),
  KEY idx_rbac_user_roles_entity (entity_id),
  KEY idx_rbac_user_roles_role (role_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (role_id) REFERENCES rbac_roles(id) ON DELETE CASCADE,
  FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_notification_preferences (
  user_id INT PRIMARY KEY,
  platform_enabled TINYINT(1) NOT NULL DEFAULT 1,
  email_enabled TINYINT(1) NOT NULL DEFAULT 1,
  push_enabled TINYINT(1) NOT NULL DEFAULT 1,
  approvals_enabled TINYINT(1) NOT NULL DEFAULT 1,
  volunteering_enabled TINYINT(1) NOT NULL DEFAULT 1,
  social_enabled TINYINT(1) NOT NULL DEFAULT 1,
  calendar_enabled TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS corporate_periods (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_corporate_period_dates (starts_at, ends_at),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS corporate_period_plan_deadlines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  corporate_period_id INT NOT NULL,
  doc_type ENUM('operational_plan','budget_plan') NOT NULL,
  due_at DATETIME NOT NULL,
  is_tentative TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_period_doc_due (corporate_period_id, doc_type, due_at),
  FOREIGN KEY (corporate_period_id) REFERENCES corporate_periods(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS entity_mob_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entity_id INT NOT NULL,
  user_id INT NOT NULL,
  assigned_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_entity_mob_assignment (entity_id, user_id),
  KEY idx_entity_mob_user (user_id),
  FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE endeavours
  ADD COLUMN IF NOT EXISTS long_description TEXT NULL AFTER description,
  ADD COLUMN IF NOT EXISTS transport_fee_amount DECIMAL(10,2) NULL AFTER transport_fee_required,
  ADD COLUMN IF NOT EXISTS edit_approval_status ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none' AFTER status,
  ADD COLUMN IF NOT EXISTS edit_pending_payload JSON NULL AFTER edit_approval_status,
  ADD COLUMN IF NOT EXISTS edit_requested_by INT NULL AFTER edit_pending_payload,
  ADD COLUMN IF NOT EXISTS edit_requested_at DATETIME NULL AFTER edit_requested_by,
  ADD COLUMN IF NOT EXISTS completed_at DATETIME NULL AFTER edit_requested_at;

CREATE TABLE IF NOT EXISTS endeavour_submissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  endeavour_id INT NOT NULL,
  doc_type ENUM('operational_plan','budget_plan','pre_financial','post_financial','epilogue') NOT NULL,
  file_drive_item_id INT NOT NULL,
  version_no INT NOT NULL DEFAULT 1,
  submitted_by INT NOT NULL,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  due_at DATETIME NULL,
  is_overdue TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('submitted','mob_approved','sa_approved','approved','rejected','needs_resubmission','no_approval_required') NOT NULL DEFAULT 'submitted',
  rejection_comment TEXT NULL,
  resubmission_of_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_endeavour_doc (endeavour_id, doc_type, submitted_at),
  KEY idx_submission_status (status, is_overdue),
  KEY idx_submission_file (file_drive_item_id),
  FOREIGN KEY (endeavour_id) REFERENCES endeavours(id) ON DELETE CASCADE,
  FOREIGN KEY (file_drive_item_id) REFERENCES file_drive_items(id) ON DELETE RESTRICT,
  FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (resubmission_of_id) REFERENCES endeavour_submissions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS endeavour_submission_approvals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  submission_id INT NOT NULL,
  approver_group ENUM('mob','student_affairs') NOT NULL,
  decision ENUM('approved','rejected') NOT NULL,
  comment TEXT NULL,
  decided_by INT NOT NULL,
  decided_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_submission_group_decided (submission_id, approver_group, decided_at),
  KEY idx_decider (decided_by),
  FOREIGN KEY (submission_id) REFERENCES endeavour_submissions(id) ON DELETE CASCADE,
  FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS endeavour_edit_approvals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  endeavour_id INT NOT NULL,
  requested_by INT NOT NULL,
  requested_payload JSON NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  comment TEXT NULL,
  decided_by INT NULL,
  decided_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_edit_approval_status (endeavour_id, status),
  FOREIGN KEY (endeavour_id) REFERENCES endeavours(id) ON DELETE CASCADE,
  FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS endeavour_workflow_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  endeavour_id INT NOT NULL,
  actor_id INT NULL,
  event_type VARCHAR(120) NOT NULL,
  payload_json JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_endeavour_workflow_events (endeavour_id, created_at),
  FOREIGN KEY (endeavour_id) REFERENCES endeavours(id) ON DELETE CASCADE,
  FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS drive_labels (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entity_id INT NOT NULL,
  name VARCHAR(80) NOT NULL,
  color VARCHAR(40) NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_drive_label_entity_name (entity_id, name),
  FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS drive_item_labels (
  item_id INT NOT NULL,
  label_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (item_id, label_id),
  FOREIGN KEY (item_id) REFERENCES file_drive_items(id) ON DELETE CASCADE,
  FOREIGN KEY (label_id) REFERENCES drive_labels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendar_event_entities (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  entity_id INT NOT NULL,
  added_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_calendar_event_entity (event_id, entity_id),
  FOREIGN KEY (event_id) REFERENCES calendar_events(id) ON DELETE CASCADE,
  FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE,
  FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendar_rsvps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  entity_id INT NOT NULL,
  user_id INT NOT NULL,
  status ENUM('attending','absent','tentative') NOT NULL,
  absence_comment VARCHAR(500) NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_calendar_rsvp (event_id, user_id),
  KEY idx_calendar_rsvp_entity (event_id, entity_id),
  FOREIGN KEY (event_id) REFERENCES calendar_events(id) ON DELETE CASCADE,
  FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendar_meeting_minutes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  entity_id INT NOT NULL,
  file_drive_item_id INT NOT NULL,
  submitted_by INT NOT NULL,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  due_at DATETIME NULL,
  is_overdue TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_calendar_minutes_entity (event_id, entity_id),
  KEY idx_calendar_minutes_file (file_drive_item_id),
  FOREIGN KEY (event_id) REFERENCES calendar_events(id) ON DELETE CASCADE,
  FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE,
  FOREIGN KEY (file_drive_item_id) REFERENCES file_drive_items(id) ON DELETE RESTRICT,
  FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE social_posts
  MODIFY COLUMN entity_id INT NULL,
  ADD COLUMN IF NOT EXISTS feed_scope ENUM('entity','global') NOT NULL DEFAULT 'entity' AFTER entity_id,
  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT NULL AFTER created_at;

CREATE TABLE IF NOT EXISTS social_post_images (
  id INT AUTO_INCREMENT PRIMARY KEY,
  post_id INT NOT NULL,
  file_drive_item_id INT NULL,
  image_url VARCHAR(1024) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_social_post_images_post (post_id, sort_order),
  FOREIGN KEY (post_id) REFERENCES social_posts(id) ON DELETE CASCADE,
  FOREIGN KEY (file_drive_item_id) REFERENCES file_drive_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS social_likes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  target_type ENUM('post','comment') NOT NULL,
  target_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_social_like (user_id, target_type, target_id),
  KEY idx_social_like_target (target_type, target_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS social_mentions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  post_id INT NULL,
  comment_id INT NULL,
  mentioned_user_id INT NULL,
  mentioned_entity_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_social_mentions_post (post_id),
  KEY idx_social_mentions_comment (comment_id),
  FOREIGN KEY (post_id) REFERENCES social_posts(id) ON DELETE CASCADE,
  FOREIGN KEY (comment_id) REFERENCES social_comments(id) ON DELETE CASCADE,
  FOREIGN KEY (mentioned_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (mentioned_entity_id) REFERENCES entities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO rbac_permissions (code, description) VALUES
('nav.dashboard', 'View dashboard navigation'),
('nav.entity_endeavours', 'View entity endeavours navigation'),
('nav.entity_drive', 'View entity drive navigation'),
('nav.calendar', 'View calendar navigation'),
('nav.social', 'View social navigation'),
('nav.volunteering', 'View volunteering navigation'),
('nav.volunteering_ops', 'View volunteering operations navigation'),
('nav.settings', 'View settings navigation'),
('nav.admin', 'View admin navigation'),
('admin.manage_entities', 'Create, update, and delete entities'),
('admin.manage_users', 'Create, update, and delete users'),
('admin.manage_roles', 'Create and update roles and permissions'),
('admin.assign_roles', 'Assign roles to users'),
('entity.view', 'View entity data'),
('entity.announce', 'Publish entity announcements'),
('drive.view', 'View drive items'),
('drive.manage', 'Create and manage drive items'),
('drive.label', 'Manage drive labels'),
('endeavour.view', 'View endeavours'),
('endeavour.create', 'Create endeavours'),
('endeavour.edit', 'Edit endeavours'),
('endeavour.submit_docs', 'Submit endeavour documents'),
('endeavour.approve_mob', 'Approve as Member of Board'),
('endeavour.approve_sa', 'Approve as Student Affairs'),
('endeavour.approve_edit', 'Approve endeavour edit requests'),
('endeavour.view_confidential', 'View confidential endeavour submissions'),
('endeavour.manage_periods', 'Manage corporate periods and plan deadlines'),
('volunteering.view', 'View volunteering opportunities'),
('volunteering.register', 'Register for volunteering'),
('volunteering.shortlist', 'Shortlist volunteers'),
('volunteering.ops', 'Mark volunteer attendance and transport payments'),
('calendar.view', 'View calendar events'),
('calendar.create', 'Create calendar events'),
('calendar.manage', 'Manage calendar events'),
('calendar.rsvp', 'RSVP to calendar events'),
('calendar.minutes.submit', 'Submit meeting minutes'),
('calendar.minutes.view', 'View meeting minutes'),
('social.view', 'View entity social feeds'),
('social.global.view', 'View public global social feed'),
('social.create', 'Create social posts and replies'),
('social.moderate', 'Moderate social posts and replies'),
('social.like', 'Like social posts and replies'),
('settings.view', 'View settings'),
('notifications.manage_preferences', 'Manage notification preferences');

INSERT IGNORE INTO rbac_roles (code, name, scope, description, is_system) VALUES
('site_admin', 'Site Admin', 'global', 'Full platform administration.', 1),
('member_board', 'Member of Board', 'global', 'Board-level approvals and oversight.', 1),
('student_affairs', 'Student Affairs', 'global', 'Student Affairs approvals and volunteering operations.', 1),
('ceo', 'CEO', 'entity', 'Entity chief executive.', 1),
('cco', 'CCO', 'entity', 'Entity communications executive.', 1),
('cm', 'CM', 'entity', 'Entity communications manager/member.', 1),
('chro', 'CHRO', 'entity', 'Entity HR executive.', 1),
('hrm', 'HRM', 'entity', 'Entity HR manager/member.', 1),
('entity_executive', 'Entity Executive', 'entity', 'Entity executive with document submission rights.', 1),
('entity_member', 'Entity Member', 'entity', 'Standard entity member.', 1),
('volunteer', 'Volunteer', 'global', 'Volunteer access.', 1);

INSERT IGNORE INTO rbac_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM rbac_roles r CROSS JOIN rbac_permissions p WHERE r.code = 'site_admin';

INSERT IGNORE INTO rbac_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM rbac_roles r JOIN rbac_permissions p ON p.code IN (
  'nav.dashboard','nav.entity_endeavours','nav.entity_drive','nav.calendar','nav.social','nav.volunteering','nav.settings',
  'entity.view','drive.view','drive.manage','endeavour.view','endeavour.create','endeavour.edit','endeavour.submit_docs',
  'endeavour.approve_mob','endeavour.approve_edit','endeavour.view_confidential','endeavour.manage_periods',
  'calendar.view','calendar.rsvp','calendar.minutes.submit','calendar.minutes.view',
  'social.view','social.global.view','social.create','social.like','settings.view','notifications.manage_preferences'
) WHERE r.code = 'member_board';

INSERT IGNORE INTO rbac_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM rbac_roles r JOIN rbac_permissions p ON p.code IN (
  'nav.dashboard','nav.entity_endeavours','nav.entity_drive','nav.calendar','nav.social','nav.volunteering','nav.volunteering_ops','nav.settings',
  'entity.view','drive.view','drive.manage','endeavour.view','endeavour.create','endeavour.edit','endeavour.submit_docs',
  'endeavour.approve_sa','endeavour.approve_edit','endeavour.view_confidential','endeavour.manage_periods',
  'volunteering.ops','calendar.view','calendar.rsvp','calendar.minutes.submit','calendar.minutes.view',
  'social.view','social.global.view','social.create','social.like','settings.view','notifications.manage_preferences'
) WHERE r.code = 'student_affairs';

INSERT IGNORE INTO rbac_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM rbac_roles r JOIN rbac_permissions p ON p.code IN (
  'nav.dashboard','nav.entity_endeavours','nav.entity_drive','nav.calendar','nav.social','nav.volunteering','nav.settings',
  'entity.view','entity.announce','drive.view','drive.manage','drive.label','endeavour.view','endeavour.create','endeavour.edit',
  'endeavour.submit_docs','endeavour.view_confidential','volunteering.view','volunteering.shortlist',
  'calendar.view','calendar.create','calendar.manage','calendar.rsvp','calendar.minutes.submit','calendar.minutes.view',
  'social.view','social.global.view','social.create','social.moderate','social.like','settings.view','notifications.manage_preferences'
) WHERE r.code = 'ceo';

INSERT IGNORE INTO rbac_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM rbac_roles r JOIN rbac_permissions p ON p.code IN (
  'nav.dashboard','nav.entity_endeavours','nav.entity_drive','nav.calendar','nav.social','nav.volunteering','nav.settings',
  'entity.view','entity.announce','drive.view','drive.manage','endeavour.view','endeavour.create','endeavour.edit','endeavour.submit_docs',
  'calendar.view','calendar.create','calendar.manage','calendar.rsvp','calendar.minutes.submit','calendar.minutes.view',
  'social.view','social.global.view','social.create','social.moderate','social.like','settings.view','notifications.manage_preferences'
) WHERE r.code IN ('cco','chro');

INSERT IGNORE INTO rbac_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM rbac_roles r JOIN rbac_permissions p ON p.code IN (
  'nav.dashboard','nav.entity_endeavours','nav.entity_drive','nav.calendar','nav.social','nav.volunteering','nav.settings',
  'entity.view','entity.announce','drive.view','endeavour.view','endeavour.edit','endeavour.submit_docs',
  'calendar.view','calendar.create','calendar.rsvp','calendar.minutes.submit','calendar.minutes.view',
  'social.view','social.global.view','social.create','social.like','settings.view','notifications.manage_preferences'
) WHERE r.code = 'cm';

INSERT IGNORE INTO rbac_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM rbac_roles r JOIN rbac_permissions p ON p.code IN (
  'nav.dashboard','nav.entity_endeavours','nav.entity_drive','nav.calendar','nav.social','nav.volunteering','nav.settings',
  'entity.view','drive.view','endeavour.view','endeavour.edit','endeavour.submit_docs','volunteering.view','volunteering.shortlist',
  'calendar.view','calendar.rsvp','calendar.minutes.submit','calendar.minutes.view',
  'social.view','social.global.view','social.create','social.like','settings.view','notifications.manage_preferences'
) WHERE r.code = 'hrm';

INSERT IGNORE INTO rbac_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM rbac_roles r JOIN rbac_permissions p ON p.code IN (
  'nav.dashboard','nav.entity_endeavours','nav.entity_drive','nav.calendar','nav.social','nav.volunteering','nav.settings',
  'entity.view','drive.view','endeavour.view','endeavour.edit','endeavour.submit_docs',
  'calendar.view','calendar.rsvp','calendar.minutes.submit','calendar.minutes.view',
  'social.view','social.global.view','social.create','social.like','settings.view','notifications.manage_preferences'
) WHERE r.code = 'entity_executive';

INSERT IGNORE INTO rbac_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM rbac_roles r JOIN rbac_permissions p ON p.code IN (
  'nav.dashboard','nav.entity_endeavours','nav.entity_drive','nav.calendar','nav.social','nav.volunteering','nav.settings',
  'entity.view','drive.view','endeavour.view','calendar.view','calendar.rsvp','calendar.minutes.view',
  'social.view','social.global.view','social.create','social.like','settings.view','notifications.manage_preferences'
) WHERE r.code = 'entity_member';

INSERT IGNORE INTO rbac_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM rbac_roles r JOIN rbac_permissions p ON p.code IN (
  'nav.volunteering','nav.settings','volunteering.view','volunteering.register','social.global.view','social.create','social.like',
  'settings.view','notifications.manage_preferences'
) WHERE r.code = 'volunteer';

INSERT IGNORE INTO rbac_user_roles (user_id, role_id, entity_id)
SELECT u.id, r.id, NULL FROM users u JOIN rbac_roles r ON r.code = 'site_admin' WHERE u.global_role = 'admin';

INSERT IGNORE INTO rbac_user_roles (user_id, role_id, entity_id)
SELECT u.id, r.id, NULL FROM users u JOIN rbac_roles r ON r.code = 'member_board' WHERE u.global_role = 'board';

INSERT IGNORE INTO rbac_user_roles (user_id, role_id, entity_id)
SELECT u.id, r.id, NULL FROM users u JOIN rbac_roles r ON r.code = 'student_affairs' WHERE u.global_role = 'student_affairs';

INSERT IGNORE INTO rbac_user_roles (user_id, role_id, entity_id)
SELECT em.user_id, r.id, em.entity_id
FROM entity_memberships em
JOIN users u ON u.id = em.user_id
JOIN rbac_roles r ON r.code = 'ceo'
WHERE u.global_role = 'ceo';

INSERT IGNORE INTO rbac_user_roles (user_id, role_id, entity_id)
SELECT em.user_id, r.id, em.entity_id
FROM entity_memberships em
JOIN rbac_roles r ON r.code = CASE
  WHEN em.department = 'communications' AND em.role = 'manager' THEN 'cco'
  WHEN em.department = 'communications' AND em.role = 'executive' THEN 'cm'
  WHEN em.department = 'hr' AND em.role = 'manager' THEN 'chro'
  WHEN em.department = 'hr' AND em.role = 'executive' THEN 'hrm'
  WHEN em.role IN ('manager','executive') THEN 'entity_executive'
  WHEN em.role = 'volunteer' THEN 'volunteer'
  ELSE 'entity_member'
END;
