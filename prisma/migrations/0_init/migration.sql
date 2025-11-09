-- Users cannot be deleted when referenced; use soft delete strategy externally.
CREATE TABLE `User` (
  `id` CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  `googleId` VARCHAR(191) UNIQUE,
  `email` VARCHAR(191) NOT NULL UNIQUE,
  `name` VARCHAR(191),
  `studentId` VARCHAR(191) UNIQUE,
  `role` ENUM('ADMIN','HR','ENTITY_MANAGER','VOLUNTEER') NOT NULL DEFAULT 'VOLUNTEER',
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Entities
CREATE TABLE `Entity` (
  `id` CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  `name` VARCHAR(191) NOT NULL UNIQUE,
  `slug` VARCHAR(191) NOT NULL UNIQUE,
  `publishQuotaPer7d` INT NOT NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB;

-- Entity Memberships
CREATE TABLE `EntityMembership` (
  `id` CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  `userId` CHAR(36) NOT NULL,
  `entityId` CHAR(36) NOT NULL,
  `role` ENUM('ENTITY_MANAGER','VOLUNTEER') NOT NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY `EntityMembership_user_entity_unique` (`userId`,`entityId`),
  CONSTRAINT `EntityMembership_user_fk` FOREIGN KEY (`userId`) REFERENCES `User`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `EntityMembership_entity_fk` FOREIGN KEY (`entityId`) REFERENCES `Entity`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Endeavour delete cascades to tags but restricts if registrations exist.
CREATE TABLE `Endeavour` (
  `id` CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  `entityId` CHAR(36) NOT NULL,
  `title` VARCHAR(191) NOT NULL,
  `description` TEXT NOT NULL,
  `venue` VARCHAR(191) NOT NULL,
  `startAt` DATETIME(3) NOT NULL,
  `endAt` DATETIME(3) NOT NULL,
  `maxVolunteers` INT NULL,
  `requiresTransportPayment` BOOLEAN NOT NULL DEFAULT 0,
  `createdByUserId` CHAR(36) NOT NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  CONSTRAINT `Endeavour_entity_fk` FOREIGN KEY (`entityId`) REFERENCES `Entity`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `Endeavour_creator_fk` FOREIGN KEY (`createdByUserId`) REFERENCES `User`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Tags
CREATE TABLE `Tag` (
  `id` CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  `name` VARCHAR(191) NOT NULL UNIQUE,
  `slug` VARCHAR(191) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- EndeavourTag join
CREATE TABLE `EndeavourTag` (
  `endeavourId` CHAR(36) NOT NULL,
  `tagId` CHAR(36) NOT NULL,
  PRIMARY KEY (`endeavourId`,`tagId`),
  CONSTRAINT `EndeavourTag_endeavour_fk` FOREIGN KEY (`endeavourId`) REFERENCES `Endeavour`(`id`) ON DELETE CASCADE,
  CONSTRAINT `EndeavourTag_tag_fk` FOREIGN KEY (`tagId`) REFERENCES `Tag`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Registration delete cascades to consent and payment records.
CREATE TABLE `Registration` (
  `id` CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  `endeavourId` CHAR(36) NOT NULL,
  `volunteerId` CHAR(36) NOT NULL,
  `status` ENUM('REGISTERED','SHORTLISTED','CONSENT_PENDING','PAYMENT_PENDING','CONFIRMED','REJECTED','WITHDRAWN') NOT NULL DEFAULT 'REGISTERED',
  `registeredAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY `Registration_unique` (`endeavourId`,`volunteerId`),
  KEY `Registration_status_idx` (`status`),
  KEY `Registration_endeavour_status_idx` (`endeavourId`,`status`),
  CONSTRAINT `Registration_endeavour_fk` FOREIGN KEY (`endeavourId`) REFERENCES `Endeavour`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `Registration_volunteer_fk` FOREIGN KEY (`volunteerId`) REFERENCES `User`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Participation records retain history; deletion requires manual cleanup.
CREATE TABLE `Participation` (
  `id` CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  `volunteerId` CHAR(36) NOT NULL,
  `entityId` CHAR(36) NOT NULL,
  `endeavourId` CHAR(36) NULL,
  `participatedAt` DATETIME(3) NOT NULL,
  KEY `Participation_volunteer_entity_idx` (`volunteerId`,`entityId`),
  KEY `Participation_entity_date_idx` (`entityId`,`participatedAt`),
  CONSTRAINT `Participation_volunteer_fk` FOREIGN KEY (`volunteerId`) REFERENCES `User`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `Participation_entity_fk` FOREIGN KEY (`entityId`) REFERENCES `Entity`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `Participation_endeavour_fk` FOREIGN KEY (`endeavourId`) REFERENCES `Endeavour`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- HR notes are retained while volunteer exists.
CREATE TABLE `HRNote` (
  `id` CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  `volunteerId` CHAR(36) NOT NULL,
  `entityId` CHAR(36) NOT NULL,
  `authorId` CHAR(36) NOT NULL,
  `note` TEXT NOT NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT `HRNote_volunteer_fk` FOREIGN KEY (`volunteerId`) REFERENCES `User`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `HRNote_entity_fk` FOREIGN KEY (`entityId`) REFERENCES `Entity`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `HRNote_author_fk` FOREIGN KEY (`authorId`) REFERENCES `User`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Parent contact deletion restricted to preserve audit trail.
CREATE TABLE `ParentContact` (
  `id` CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  `volunteerId` CHAR(36) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `relationship` VARCHAR(191) NOT NULL,
  `email` VARCHAR(191) NOT NULL,
  `phone` VARCHAR(191) NULL,
  UNIQUE KEY `ParentContact_unique` (`volunteerId`,`email`),
  CONSTRAINT `ParentContact_volunteer_fk` FOREIGN KEY (`volunteerId`) REFERENCES `User`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Consent snapshot is immutable; cascade ensures removal with registration.
CREATE TABLE `ConsentForm` (
  `id` CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  `registrationId` CHAR(36) NOT NULL UNIQUE,
  `formSnapshotJson` JSON NOT NULL,
  `submittedAt` DATETIME(3) NOT NULL,
  CONSTRAINT `ConsentForm_registration_fk` FOREIGN KEY (`registrationId`) REFERENCES `Registration`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Payment linked to registration; cascade ensures cleanup when registration removed.
CREATE TABLE `Payment` (
  `id` CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  `registrationId` CHAR(36) NOT NULL UNIQUE,
  `amountCents` INT NOT NULL,
  `currency` VARCHAR(8) NOT NULL,
  `status` ENUM('INITIATED','PENDING','PAID','FAILED','REFUNDED') NOT NULL,
  `providerRef` VARCHAR(191) NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  CONSTRAINT `Payment_registration_fk` FOREIGN KEY (`registrationId`) REFERENCES `Registration`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Email log
CREATE TABLE `EmailLog` (
  `id` CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  `toEmail` VARCHAR(191) NOT NULL,
  `template` VARCHAR(191) NOT NULL,
  `contextJson` JSON NOT NULL,
  `sentAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `error` TEXT NULL,
  KEY `EmailLog_to_sent_idx` (`toEmail`,`sentAt`)
) ENGINE=InnoDB;

-- Audit log
CREATE TABLE `AuditLog` (
  `id` CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  `actorUserId` CHAR(36) NULL,
  `action` VARCHAR(64) NOT NULL,
  `entityId` CHAR(36) NULL,
  `targetUserId` CHAR(36) NULL,
  `subjectId` CHAR(36) NULL,
  `metadataJson` JSON NOT NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY `AuditLog_subject_idx` (`subjectId`),
  KEY `AuditLog_action_created_idx` (`action`,`createdAt`),
  CONSTRAINT `AuditLog_actor_fk` FOREIGN KEY (`actorUserId`) REFERENCES `User`(`id`) ON DELETE SET NULL,
  CONSTRAINT `AuditLog_entity_fk` FOREIGN KEY (`entityId`) REFERENCES `Entity`(`id`) ON DELETE SET NULL,
  CONSTRAINT `AuditLog_target_fk` FOREIGN KEY (`targetUserId`) REFERENCES `User`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;
