USE nixor_portal;

START TRANSACTION;

INSERT INTO entities (name, description) VALUES
('Nixor Community Entity', 'Primary entity for seed data');

-- Example users (replace with secure, unique passwords before use)
INSERT INTO users (email, password_hash, full_name, global_role)
VALUES
('board@nixor.io', 'REPLACE_BEFORE_USE', 'Board Member', 'board'),
('ceo@nixor.io', 'REPLACE_BEFORE_USE', 'Entity CEO', 'ceo'),
('hr@nixor.io', 'REPLACE_BEFORE_USE', 'HR Lead', 'staff');

INSERT INTO students (user_id, student_id, parent_email, phone)
VALUES
(3, 'NX-2024-001', 'parent1@nixor.io', '+123456789');

INSERT INTO entity_memberships (entity_id, user_id, department, role)
VALUES
(1, 2, 'management', 'executive'),
(1, 3, 'hr', 'manager');

INSERT INTO endeavours (entity_id, created_by, name, description, venue, schedule, start_date, end_date, transport_payment_required, status)
VALUES
(1, 2, 'Community Health Camp', 'Multi-day health outreach with onsite clinics.', 'City Hall Auditorium', 'Sat 9AM-2PM', '2024-04-12', '2024-04-14', 500.00, 'pending_board_approval');

INSERT INTO dashboard_announcements (entity_id, title, message, created_by)
VALUES
(1, 'Welcome to the Nixor Portal', 'Announcements posted here are visible to your entity members.', 2);

INSERT INTO calendar_events (entity_id, title, description, event_date, location, created_by)
VALUES
(1, 'Board Sync', 'Monthly alignment with leadership.', DATE_ADD(NOW(), INTERVAL 3 DAY), 'Virtual', 2);

INSERT INTO volunteer_posts (endeavour_id, description, eligibility_notes, venue, schedule, transport_payment, questionnaire_mode, published, published_at, created_by)
VALUES
(1, 'Join us for on-site support during the health camp.', 'Medical students preferred.', 'City Hall Auditorium', 'Sat 9AM-2PM', 500.00, 0, 1, NOW(), 2);

COMMIT;
