-- Nixor Entities Endeavour Dashboard Schema
-- Engine: InnoDB, Charset: utf8mb4

CREATE TABLE IF NOT EXISTS User (
    id CHAR(36) PRIMARY KEY COMMENT 'UUID v4',
    googleId VARCHAR(64) NOT NULL COMMENT 'Google subject identifier',
    email VARCHAR(191) NOT NULL UNIQUE,
    name VARCHAR(191) NOT NULL,
    studentId VARCHAR(32) UNIQUE NULL,
    role ENUM('ADMIN','HR','ENTITY_MANAGER','VOLUNTEER') NOT NULL DEFAULT 'VOLUNTEER',
    createdAt DATETIME(3) NOT NULL,
    updatedAt DATETIME(3) NOT NULL,
    INDEX idx_user_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS Entity (
    id CHAR(36) PRIMARY KEY,
    name VARCHAR(191) NOT NULL UNIQUE,
    slug VARCHAR(191) NOT NULL UNIQUE,
    publishQuotaPer7d INT NOT NULL DEFAULT 5 COMMENT 'Maximum endeavours per rolling 7 day window',
    createdAt DATETIME(3) NOT NULL,
    updatedAt DATETIME(3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS EntityMembership (
    id CHAR(36) PRIMARY KEY,
    userId CHAR(36) NOT NULL,
    entityId CHAR(36) NOT NULL,
    role ENUM('ENTITY_MANAGER','VOLUNTEER') NOT NULL DEFAULT 'VOLUNTEER',
    createdAt DATETIME(3) NOT NULL,
    UNIQUE KEY uniq_membership (userId, entityId),
    CONSTRAINT fk_membership_user FOREIGN KEY (userId) REFERENCES User(id) ON DELETE RESTRICT,
    CONSTRAINT fk_membership_entity FOREIGN KEY (entityId) REFERENCES Entity(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS Endeavour (
    id CHAR(36) PRIMARY KEY,
    entityId CHAR(36) NOT NULL,
    title VARCHAR(191) NOT NULL,
    description TEXT NOT NULL,
    venue VARCHAR(191) NOT NULL,
    startAt DATETIME(3) NOT NULL,
    endAt DATETIME(3) NOT NULL,
    maxVolunteers INT NULL,
    requiresTransportPayment TINYINT(1) NOT NULL DEFAULT 0,
    createdByUserId CHAR(36) NOT NULL,
    createdAt DATETIME(3) NOT NULL,
    updatedAt DATETIME(3) NOT NULL,
    INDEX idx_endeavour_entity (entityId, startAt),
    CONSTRAINT fk_endeavour_entity FOREIGN KEY (entityId) REFERENCES Entity(id) ON DELETE RESTRICT,
    CONSTRAINT fk_endeavour_creator FOREIGN KEY (createdByUserId) REFERENCES User(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS Tag (
    id CHAR(36) PRIMARY KEY,
    name VARCHAR(191) NOT NULL UNIQUE,
    slug VARCHAR(191) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS EndeavourTag (
    endeavourId CHAR(36) NOT NULL,
    tagId CHAR(36) NOT NULL,
    PRIMARY KEY (endeavourId, tagId),
    CONSTRAINT fk_endtag_endeavour FOREIGN KEY (endeavourId) REFERENCES Endeavour(id) ON DELETE CASCADE,
    CONSTRAINT fk_endtag_tag FOREIGN KEY (tagId) REFERENCES Tag(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS Registration (
    id CHAR(36) PRIMARY KEY,
    endeavourId CHAR(36) NOT NULL,
    volunteerId CHAR(36) NOT NULL,
    status ENUM('REGISTERED','SHORTLISTED','CONSENT_PENDING','PAYMENT_PENDING','CONFIRMED','REJECTED','WITHDRAWN') NOT NULL DEFAULT 'REGISTERED',
    registeredAt DATETIME(3) NOT NULL,
    updatedAt DATETIME(3) NOT NULL,
    UNIQUE KEY uniq_registration (endeavourId, volunteerId),
    INDEX idx_registration_status (status),
    INDEX idx_registration_composite (endeavourId, status),
    CONSTRAINT fk_registration_endeavour FOREIGN KEY (endeavourId) REFERENCES Endeavour(id) ON DELETE RESTRICT,
    CONSTRAINT fk_registration_volunteer FOREIGN KEY (volunteerId) REFERENCES User(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS Participation (
    id CHAR(36) PRIMARY KEY,
    volunteerId CHAR(36) NOT NULL,
    entityId CHAR(36) NOT NULL,
    endeavourId CHAR(36) NULL,
    participatedAt DATETIME(3) NOT NULL,
    INDEX idx_participation_volunteer_entity (volunteerId, entityId),
    INDEX idx_participation_entity_date (entityId, participatedAt),
    CONSTRAINT fk_participation_volunteer FOREIGN KEY (volunteerId) REFERENCES User(id) ON DELETE RESTRICT,
    CONSTRAINT fk_participation_entity FOREIGN KEY (entityId) REFERENCES Entity(id) ON DELETE RESTRICT,
    CONSTRAINT fk_participation_endeavour FOREIGN KEY (endeavourId) REFERENCES Endeavour(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS HRNote (
    id CHAR(36) PRIMARY KEY,
    volunteerId CHAR(36) NOT NULL,
    entityId CHAR(36) NOT NULL,
    authorId CHAR(36) NOT NULL,
    note TEXT NOT NULL,
    createdAt DATETIME(3) NOT NULL,
    INDEX idx_hrnote_volunteer (volunteerId, entityId),
    CONSTRAINT fk_hrnote_volunteer FOREIGN KEY (volunteerId) REFERENCES User(id) ON DELETE RESTRICT,
    CONSTRAINT fk_hrnote_entity FOREIGN KEY (entityId) REFERENCES Entity(id) ON DELETE RESTRICT,
    CONSTRAINT fk_hrnote_author FOREIGN KEY (authorId) REFERENCES User(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ParentContact (
    id CHAR(36) PRIMARY KEY,
    volunteerId CHAR(36) NOT NULL,
    name VARCHAR(191) NOT NULL,
    relationship VARCHAR(64) NOT NULL,
    email VARCHAR(191) NOT NULL,
    phone VARCHAR(32) NULL,
    UNIQUE KEY uniq_parent_contact (volunteerId, email),
    CONSTRAINT fk_parent_contact_volunteer FOREIGN KEY (volunteerId) REFERENCES User(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ConsentForm (
    id CHAR(36) PRIMARY KEY,
    registrationId CHAR(36) NOT NULL UNIQUE,
    formSnapshotJson JSON NOT NULL,
    submittedAt DATETIME(3) NOT NULL,
    CONSTRAINT fk_consent_registration FOREIGN KEY (registrationId) REFERENCES Registration(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS Payment (
    id CHAR(36) PRIMARY KEY,
    registrationId CHAR(36) NOT NULL,
    amountCents INT NOT NULL,
    currency VARCHAR(8) NOT NULL DEFAULT 'PKR',
    status ENUM('INITIATED','PENDING','PAID','FAILED','REFUNDED') NOT NULL DEFAULT 'INITIATED',
    providerRef VARCHAR(191) NULL,
    createdAt DATETIME(3) NOT NULL,
    updatedAt DATETIME(3) NOT NULL,
    CONSTRAINT fk_payment_registration FOREIGN KEY (registrationId) REFERENCES Registration(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS EmailLog (
    id CHAR(36) PRIMARY KEY,
    toEmail VARCHAR(191) NOT NULL,
    template VARCHAR(64) NOT NULL,
    contextJson JSON NOT NULL,
    sentAt DATETIME(3) NOT NULL,
    error TEXT NULL,
    INDEX idx_email_log_recipient (toEmail, sentAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS AuditLog (
    id CHAR(36) PRIMARY KEY,
    actorUserId CHAR(36) NOT NULL,
    action VARCHAR(64) NOT NULL,
    entityId CHAR(36) NULL,
    targetUserId CHAR(36) NULL,
    subjectId CHAR(36) NULL,
    metadataJson JSON NULL,
    createdAt DATETIME(3) NOT NULL,
    INDEX idx_audit_subject (subjectId),
    INDEX idx_audit_action_date (action, createdAt),
    CONSTRAINT fk_audit_actor FOREIGN KEY (actorUserId) REFERENCES User(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS RateLimit (
    id CHAR(36) PRIMARY KEY,
    entityId CHAR(36) NOT NULL,
    action VARCHAR(32) NOT NULL,
    occurredAt DATETIME(3) NOT NULL,
    INDEX idx_ratelimit_entity (entityId, action, occurredAt),
    CONSTRAINT fk_ratelimit_entity FOREIGN KEY (entityId) REFERENCES Entity(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
