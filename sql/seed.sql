INSERT INTO Entity (id, name, slug, publishQuotaPer7d, createdAt, updatedAt)
VALUES
    ('11111111-1111-1111-1111-111111111111', 'Nixor Community Service', 'ncs', 5, NOW(3), NOW(3))
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO User (id, googleId, email, name, role, createdAt, updatedAt)
VALUES
    ('22222222-2222-2222-2222-222222222222', 'google-admin', 'admin@nixorcollege.edu.pk', 'Dashboard Admin', 'ADMIN', NOW(3), NOW(3))
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO EntityMembership (id, userId, entityId, role, createdAt)
VALUES
    ('33333333-3333-3333-3333-333333333333', '22222222-2222-2222-2222-222222222222', '11111111-1111-1111-1111-111111111111', 'ENTITY_MANAGER', NOW(3))
ON DUPLICATE KEY UPDATE role = VALUES(role);
