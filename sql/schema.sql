CREATE DATABASE IF NOT EXISTS nixor_portal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE nixor_portal;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255),
  full_name VARCHAR(190) NOT NULL,
  google_id VARCHAR(190),
  global_role ENUM('admin','board','ceo','staff','volunteer') DEFAULT 'volunteer',
  status ENUM('active','suspended','deleted') DEFAULT 'active',
  email_verified_at TIMESTAMP NULL,
  last_login_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT,
  student_id VARCHAR(50) UNIQUE,
  parent_email VARCHAR(190),
  parent_email_secondary VARCHAR(190),
  phone VARCHAR(50),
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE entities (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  description TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE entity_memberships (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entity_id INT NOT NULL,
  user_id INT NOT NULL,
  department ENUM('operations','finance','hr','communications','management','other') DEFAULT 'other',
  role ENUM('manager','executive','member','volunteer') DEFAULT 'member',
  start_date DATE,
  end_date DATE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_membership (entity_id, user_id, department),
  KEY idx_user_entity (user_id, entity_id),
  FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE endeavour_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  category VARCHAR(120) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE endeavours (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entity_id INT NOT NULL,
  created_by INT NOT NULL,
  name VARCHAR(190) NOT NULL,
  type_id INT,
  description TEXT,
  venue VARCHAR(190),
  schedule TEXT,
  start_date DATE,
  end_date DATE,
  transport_payment_required DECIMAL(10,2) DEFAULT 0.00,
  status ENUM(
    'draft',
    'pending_board_approval',
    'board_approved_ops_plan_required',
    'ops_plan_pending_board_approval',
    'ops_plan_approved_mou_optional',
    'mou_pending_board_approval',
    'mou_approved_pre_financial_required',
    'pre_financial_pending_board_approval',
    'finance_approved_hr_posting_optional',
    'volunteer_posting_pending_board_approval',
    'volunteer_posting_approved_hr_publish',
    'live_volunteer_posting',
    'post_financial_pending_board_approval',
    'closed_ops_epilogue_required',
    'completed',
    'rejected'
  ) DEFAULT 'draft',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_entity_status (entity_id, status),
  KEY idx_created_by (created_by),
  KEY idx_dates (start_date, end_date),
  FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (type_id) REFERENCES endeavour_types(id) ON DELETE SET NULL
);

CREATE TABLE endeavour_documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  endeavour_id INT NOT NULL,
  doc_type ENUM('ops_plan','mou','pre_financial','post_financial','epilogue') NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  original_name VARCHAR(190),
  uploaded_by INT NOT NULL,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (endeavour_id) REFERENCES endeavours(id) ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE approvals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  endeavour_id INT NOT NULL,
  stage ENUM(
    'pending_board_approval',
    'ops_plan_pending_board_approval',
    'mou_pending_board_approval',
    'pre_financial_pending_board_approval',
    'volunteer_posting_pending_board_approval',
    'post_financial_pending_board_approval',
    'board_approved_ops_plan_required',
    'ops_plan_approved_mou_optional',
    'mou_approved_pre_financial_required',
    'finance_approved_hr_posting_optional',
    'volunteer_posting_approved_hr_publish',
    'closed_ops_epilogue_required'
  ) NOT NULL,
  role_required ENUM('board','admin','hr') NOT NULL,
  decision ENUM('approved','rejected') NOT NULL,
  notes TEXT,
  approved_by INT NOT NULL,
  approved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_endeavour_stage (endeavour_id, stage),
  KEY idx_approved_by (approved_by),
  FOREIGN KEY (endeavour_id) REFERENCES endeavours(id) ON DELETE CASCADE,
  FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE volunteer_posts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  endeavour_id INT NOT NULL,
  description TEXT,
  eligibility_notes TEXT,
  venue VARCHAR(190),
  schedule TEXT,
  transport_payment DECIMAL(10,2) DEFAULT 0.00,
  questionnaire_mode TINYINT(1) DEFAULT 0,
  published TINYINT(1) DEFAULT 0,
  published_at TIMESTAMP NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (endeavour_id) REFERENCES endeavours(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE volunteer_applications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  volunteer_post_id INT NOT NULL,
  student_id INT NOT NULL,
  answers_json JSON,
  status ENUM('submitted','shortlisted','rejected') DEFAULT 'submitted',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_volunteer_post (volunteer_post_id, status),
  KEY idx_student (student_id),
  FOREIGN KEY (volunteer_post_id) REFERENCES volunteer_posts(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

CREATE TABLE shortlists (
  id INT AUTO_INCREMENT PRIMARY KEY,
  volunteer_application_id INT NOT NULL,
  shortlisted_by INT NOT NULL,
  shortlisted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_application (volunteer_application_id),
  FOREIGN KEY (volunteer_application_id) REFERENCES volunteer_applications(id) ON DELETE CASCADE,
  FOREIGN KEY (shortlisted_by) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE consents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  volunteer_application_id INT NOT NULL,
  parent_email VARCHAR(190),
  token VARCHAR(190) NOT NULL UNIQUE,
  status ENUM('pending','signed','rejected') DEFAULT 'pending',
  signed_at TIMESTAMP NULL,
  signature_name VARCHAR(190),
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_token (token),
  KEY idx_status (status, volunteer_application_id),
  FOREIGN KEY (volunteer_application_id) REFERENCES volunteer_applications(id) ON DELETE CASCADE
);

CREATE TABLE payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  volunteer_application_id INT NOT NULL,
  transport_payment_due DECIMAL(10,2) DEFAULT 0.00,
  paid_flag TINYINT(1) DEFAULT 0,
  paid_by INT,
  paid_at TIMESTAMP NULL,
  receipt_ref VARCHAR(190),
  KEY idx_application_paid (volunteer_application_id, paid_flag),
  FOREIGN KEY (volunteer_application_id) REFERENCES volunteer_applications(id) ON DELETE CASCADE,
  FOREIGN KEY (paid_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  volunteer_application_id INT NOT NULL,
  attendance_date DATE,
  status ENUM('pending','present','absent') DEFAULT 'pending',
  marked_by INT,
  marked_at TIMESTAMP NULL,
  KEY idx_application_date (volunteer_application_id, attendance_date),
  FOREIGN KEY (volunteer_application_id) REFERENCES volunteer_applications(id) ON DELETE CASCADE,
  FOREIGN KEY (marked_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE activity_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  actor_id INT,
  entity_type VARCHAR(120) NOT NULL,
  entity_id INT NOT NULL,
  action VARCHAR(120) NOT NULL,
  notes TEXT,
  metadata JSON,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_entity (entity_type, entity_id),
  KEY idx_actor (actor_id, created_at),
  KEY idx_created (created_at),
  FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE file_drive_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entity_id INT NOT NULL,
  parent_id INT NULL,
  item_type ENUM('folder','file') NOT NULL,
  name VARCHAR(190) NOT NULL,
  file_path VARCHAR(255),
  size_bytes INT DEFAULT 0,
  tags VARCHAR(255),
  sharing_scope ENUM('private','entity','department','public') DEFAULT 'entity',
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_parent (parent_id, entity_id),
  KEY idx_entity_type (entity_id, item_type),
  FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE,
  FOREIGN KEY (parent_id) REFERENCES file_drive_items(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE drive_comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  drive_item_id INT NOT NULL,
  user_id INT NOT NULL,
  comment TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (drive_item_id) REFERENCES file_drive_items(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE drive_versions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  drive_item_id INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  version_label VARCHAR(120),
  uploaded_by INT NOT NULL,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (drive_item_id) REFERENCES file_drive_items(id) ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token VARCHAR(190) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NULL,
  KEY idx_expires (expires_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE calendar_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entity_id INT NOT NULL,
  title VARCHAR(190) NOT NULL,
  description TEXT,
  event_date DATETIME NOT NULL,
  location VARCHAR(190),
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE social_posts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  endeavour_id INT,
  entity_id INT NOT NULL,
  user_id INT NOT NULL,
  content TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_entity_created (entity_id, created_at),
  KEY idx_endeavour (endeavour_id),
  FOREIGN KEY (endeavour_id) REFERENCES endeavours(id) ON DELETE SET NULL,
  FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE social_comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  post_id INT NOT NULL,
  user_id INT NOT NULL,
  comment TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_post_created (post_id, created_at),
  FOREIGN KEY (post_id) REFERENCES social_posts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE dashboard_announcements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entity_id INT NOT NULL,
  title VARCHAR(190) NOT NULL,
  message TEXT NOT NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_entity_created (entity_id, created_at),
  FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE interest_submissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(60),
  message TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_email (email)
);

CREATE TABLE reminder_notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  notification_type VARCHAR(120) NOT NULL,
  entity_type VARCHAR(120),
  entity_id INT,
  recipient VARCHAR(190) NOT NULL,
  sent_on DATE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_notification (notification_type, entity_type, entity_id, recipient, sent_on)
);

INSERT INTO entities (name, description) VALUES
('Nixor Community Entity', 'Primary entity for Nixor corporate initiatives');

INSERT INTO endeavour_types (name, category) VALUES
('External Outreach', 'External'),
('Internal Training', 'Internal');
