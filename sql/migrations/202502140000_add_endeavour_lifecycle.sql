ALTER TABLE users
  MODIFY global_role ENUM('admin','board','ceo','staff','student_affairs','volunteer') DEFAULT 'volunteer';

ALTER TABLE endeavours
  ADD COLUMN phase ENUM(
    'PRE_EVENT',
    'PRE_FINANCIAL',
    'VOLUNTEER_REGISTRATION',
    'VOLUNTEER_SHORTLISTING',
    'ON_DAY',
    'POST_EVENT',
    'COMPLETED'
  ) DEFAULT 'PRE_EVENT',
  ADD COLUMN volunteering_enabled TINYINT(1) DEFAULT 0,
  ADD COLUMN transport_fee_required TINYINT(1) DEFAULT 0,
  ADD COLUMN operational_plan_file_id INT NULL,
  ADD COLUMN budget_plan_file_id INT NULL,
  ADD COLUMN pre_financial_file_id INT NULL,
  ADD COLUMN post_financial_file_id INT NULL,
  ADD COLUMN epilogue_file_id INT NULL,
  ADD COLUMN volunteer_registration_deadline DATETIME NULL,
  ADD COLUMN pre_financial_deadline DATETIME NULL,
  ADD COLUMN post_financial_deadline DATETIME NULL,
  ADD COLUMN event_start_at DATETIME NULL,
  ADD COLUMN event_end_at DATETIME NULL,
  ADD KEY idx_phase (phase),
  ADD KEY idx_event_dates (event_start_at, event_end_at),
  ADD KEY idx_registration_deadline (volunteer_registration_deadline),
  ADD CONSTRAINT fk_endeavours_ops_plan_file FOREIGN KEY (operational_plan_file_id) REFERENCES file_drive_items(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_endeavours_budget_plan_file FOREIGN KEY (budget_plan_file_id) REFERENCES file_drive_items(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_endeavours_pre_financial_file FOREIGN KEY (pre_financial_file_id) REFERENCES file_drive_items(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_endeavours_post_financial_file FOREIGN KEY (post_financial_file_id) REFERENCES file_drive_items(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_endeavours_epilogue_file FOREIGN KEY (epilogue_file_id) REFERENCES file_drive_items(id) ON DELETE SET NULL;

CREATE TABLE endeavour_doc_approvals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  endeavour_id INT NOT NULL,
  doc_type ENUM('operational_plan','budget_plan','pre_financial','post_financial','epilogue') NOT NULL,
  approver_group ENUM('bod','student_affairs') NOT NULL,
  status ENUM('pending','approved','rejected') DEFAULT 'pending',
  approver_user_id INT NULL,
  comment TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_doc_approver (endeavour_id, doc_type, approver_group),
  KEY idx_endeavour_status (endeavour_id, status),
  FOREIGN KEY (endeavour_id) REFERENCES endeavours(id) ON DELETE CASCADE,
  FOREIGN KEY (approver_user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE volunteer_registrations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  endeavour_id INT NOT NULL,
  entity_id INT NOT NULL,
  user_id INT NOT NULL,
  status ENUM('pending','shortlisted','rejected') DEFAULT 'pending',
  registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  attendance_status ENUM('present','absent') NULL,
  transport_fee_paid TINYINT(1) NULL,
  paid_at TIMESTAMP NULL,
  UNIQUE KEY uniq_volunteer_registration (endeavour_id, user_id),
  KEY idx_endeavour_status (endeavour_id, status),
  KEY idx_user (user_id),
  FOREIGN KEY (endeavour_id) REFERENCES endeavours(id) ON DELETE CASCADE,
  FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  type VARCHAR(120) NOT NULL,
  payload_json JSON,
  is_read TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_read (user_id, is_read, created_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
