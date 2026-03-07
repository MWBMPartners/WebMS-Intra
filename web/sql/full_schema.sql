-- =============================================================================
-- WebMS Intra — Full Database Schema
-- =============================================================================
-- Consolidated schema for fresh installs or safe re-runs on existing databases.
--
-- Safety guarantees:
--   • CREATE TABLE IF NOT EXISTS  — skips tables that already exist
--   • INSERT ... ON DUPLICATE KEY — preserves existing settings & route values
--   • No DROP, TRUNCATE, or DELETE — existing data is never removed
--   • FK ordering respected       — parent tables created before children
--
-- After running, all individual migrations (000–006) are marked as executed
-- in tblMigrations so the web-based Migrator won't re-run them.
--
-- Covers migrations: 000, 001, 002, 003, 004, 005, 006, 007, 008, 009, 010, 011, 012
-- =============================================================================
-- @package   Portal\Database
-- @author    MWBM Partners Ltd (t/a MWservices)
-- @copyright 2025-2026 MWBM Partners Ltd (t/a MWservices)
-- @license   All Rights Reserved
-- @version   0.8.0
-- =============================================================================


-- #############################################################################
-- SECTION 1: CORE PLATFORM TABLES (no foreign keys)
-- #############################################################################

-- -----------------------------------------------------------------------------
-- 📋 tblMigrations — tracks which SQL migration files have been executed
-- (from migration 000)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblMigrations` (
    `migrationID`   INT          NOT NULL AUTO_INCREMENT COMMENT 'Unique migration record identifier',
    `filename`      VARCHAR(255) NOT NULL                COMMENT 'Name of the SQL migration file executed',
    `executedAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp when migration was run',
    `executedByID`  INT          DEFAULT NULL             COMMENT 'UserID of the admin who triggered this migration',
    PRIMARY KEY (`migrationID`),
    UNIQUE KEY `uq_filename` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Tracks executed SQL migrations to prevent re-running.';


-- -----------------------------------------------------------------------------
-- ⚙️ tblSettings — dot-notation key/value configuration store
-- isSensitive=1 values are encrypted with libsodium at rest
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblSettings` (
    `settingID`    INT          NOT NULL AUTO_INCREMENT,
    `settingKey`   VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
                   COMMENT 'Setting name with period-separated hierarchy (e.g. auth.ms365.clientID)',
    `settingValue` MEDIUMTEXT   CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
                   COMMENT 'Current value for this setting',
    `updatedAt`    DATETIME     DEFAULT CURRENT_TIMESTAMP
                   COMMENT 'Timestamp of when setting was last updated',
    `isSensitive`  TINYINT(1)   NOT NULL DEFAULT 0
                   COMMENT 'Boolean: if 1, value is encrypted at rest',
    `defaultValue` MEDIUMTEXT   CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
                   COMMENT 'Optional default value for this setting',
    PRIMARY KEY (`settingID`),
    UNIQUE KEY `settingKey` (`settingKey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- 🗺️ tblRoutes — clean-URL routing (routeKey → targetFile)
-- Used by core/Router.php to map request paths to app PHP files
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblRoutes` (
    `routeID`     INT          NOT NULL AUTO_INCREMENT,
    `routeKey`    VARCHAR(100) COLLATE utf8mb4_general_ci NOT NULL
                  COMMENT 'Clean URL path (e.g. expenses/submit)',
    `targetFile`  VARCHAR(255) COLLATE utf8mb4_general_ci NOT NULL
                  COMMENT 'Relative path to PHP file inside apps/ directory',
    `isProtected` TINYINT(1)   DEFAULT 1
                  COMMENT '1 = requires authentication, 0 = public',
    `lastUpdated` DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`routeID`),
    UNIQUE KEY `uq_routeKey` (`routeKey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------------------------------------------------------
-- 🏷️ tblRoles — role definitions (Admin, Treasurer, Developer, etc.)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblRoles` (
    `roleID`   INT          NOT NULL AUTO_INCREMENT,
    `roleKey`  VARCHAR(50)  COLLATE utf8mb4_general_ci NOT NULL
               COMMENT 'Unique machine-readable role identifier',
    `roleName` VARCHAR(100) COLLATE utf8mb4_general_ci NOT NULL
               COMMENT 'Human-readable role name',
    PRIMARY KEY (`roleID`),
    UNIQUE KEY `roleKey` (`roleKey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------------------------------------------------------
-- 👥 tblGroups — committees / working groups
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblGroups` (
    `groupID`         INT          NOT NULL AUTO_INCREMENT,
    `groupName`       VARCHAR(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
    `description`     TEXT         COLLATE utf8mb4_general_ci,
    `dateAdded`       TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    `dateLastUpdated` TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`groupID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------------------------------------------------------
-- 🏢 tblDepts — departments
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblDepts` (
    `deptID`   INT          NOT NULL AUTO_INCREMENT,
    `deptName` VARCHAR(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
    `deptCode` VARCHAR(50)  COLLATE utf8mb4_general_ci DEFAULT NULL,
    `isActive` TINYINT(1)   DEFAULT 1,
    PRIMARY KEY (`deptID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- #############################################################################
-- SECTION 2: USER TABLES
-- #############################################################################

-- -----------------------------------------------------------------------------
-- 👤 tblUsers — core user accounts
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblUsers` (
    `userID`       INT          NOT NULL AUTO_INCREMENT,
    `fullName`     VARCHAR(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
    `emailAddress` VARCHAR(255) COLLATE utf8mb4_general_ci NOT NULL,
    `phoneNumber`  VARCHAR(50)  COLLATE utf8mb4_general_ci DEFAULT NULL,
    `avatarPath`   VARCHAR(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
    `locale`       VARCHAR(10)  COLLATE utf8mb4_general_ci DEFAULT 'en',
    `isActive`     TINYINT(1)   DEFAULT 1,
    `isAdmin`      TINYINT(1)   DEFAULT 0,
    `isRootAdmin`  TINYINT(1)   DEFAULT 0,
    `createdAt`    DATETIME     DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`userID`),
    UNIQUE KEY `emailAddress` (`emailAddress`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------------------------------------------------------
-- 🔑 tblLocalAccounts — local username/password credentials
-- Linked 1:1 with tblUsers for local authentication
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblLocalAccounts` (
    `localID`      INT          NOT NULL AUTO_INCREMENT,
    `userID`       INT          NOT NULL,
    `username`     VARCHAR(50)  COLLATE utf8mb4_general_ci NOT NULL,
    `passwordHash` VARCHAR(255) COLLATE utf8mb4_general_ci NOT NULL
                   COMMENT 'bcrypt hash via password_hash()',
    `lastLogin`    DATETIME     DEFAULT NULL,
    PRIMARY KEY (`localID`),
    UNIQUE KEY `username` (`username`),
    KEY `userID` (`userID`),
    CONSTRAINT `tblLocalAccounts_ibfk_1` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------------------------------------------------------
-- 🔓 tblPasswordResets — time-limited password-reset tokens
-- (from migration 006)
-- Plaintext token is emailed; only its SHA-256 hash is stored here
-- See: https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblPasswordResets` (
    `resetID`   INT          NOT NULL AUTO_INCREMENT,
    `userID`    INT          NOT NULL COMMENT 'FK → tblUsers.userID',
    `tokenHash` VARCHAR(255) NOT NULL COMMENT 'SHA-256 hash of the plaintext reset token',
    `expiresAt` DATETIME     NOT NULL COMMENT 'Token expiry timestamp',
    `usedAt`    DATETIME     DEFAULT NULL COMMENT 'NULL until the token is consumed',
    `createdAt` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `createdIP` VARCHAR(100) DEFAULT NULL COMMENT 'IP address that requested the reset',
    PRIMARY KEY (`resetID`),
    KEY `idx_resets_user`    (`userID`),
    KEY `idx_resets_token`   (`tokenHash`),
    KEY `idx_resets_expires` (`expiresAt`),
    CONSTRAINT `fk_resets_user` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Time-limited password-reset tokens for local accounts.';


-- -----------------------------------------------------------------------------
-- 🔗 tblLinkedAccounts — external identity provider links per user
-- (from migration 011)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblLinkedAccounts` (
    `linkID`       INT          NOT NULL AUTO_INCREMENT COMMENT 'Unique link record identifier',
    `userID`       INT          NOT NULL                COMMENT 'FK to tblUsers.userID',
    `provider`     VARCHAR(50)  NOT NULL                COMMENT 'Identity provider: ms365, google, local',
    `providerSub`  VARCHAR(255) NOT NULL                COMMENT 'Provider-specific unique subject/ID',
    `providerEmail` VARCHAR(255) DEFAULT NULL           COMMENT 'Email address from the provider (for display)',
    `linkedAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When this link was created',
    PRIMARY KEY (`linkID`),
    UNIQUE KEY `uq_provider_sub` (`provider`, `providerSub`),
    KEY `idx_user` (`userID`),
    CONSTRAINT `tblLinkedAccounts_ibfk_1` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Maps users to external identity providers for SSO login.';


-- -----------------------------------------------------------------------------
-- 🔐 tblWebAuthnCredentials — WebAuthn/PassKey credentials
-- (from migration 011)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblWebAuthnCredentials` (
    `credID`        INT            NOT NULL AUTO_INCREMENT COMMENT 'Internal DB identifier',
    `userID`        INT            NOT NULL                COMMENT 'FK to tblUsers.userID',
    `credentialID`  TEXT           NOT NULL                COMMENT 'Base64url-encoded credential ID from authenticator',
    `publicKey`     TEXT           NOT NULL                COMMENT 'Base64url-encoded COSE public key',
    `signCount`     INT UNSIGNED   NOT NULL DEFAULT 0      COMMENT 'Signature counter for clone detection',
    `friendlyName`  VARCHAR(100)   DEFAULT NULL            COMMENT 'User-chosen label (e.g. "YubiKey 5C")',
    `aaguid`        VARCHAR(36)    DEFAULT NULL            COMMENT 'Authenticator Attestation GUID (identifies key model)',
    `transports`    VARCHAR(255)   DEFAULT NULL            COMMENT 'Comma-separated transport hints: usb,nfc,ble,internal',
    `createdAt`     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When credential was registered',
    `lastUsedAt`    DATETIME       DEFAULT NULL            COMMENT 'Last successful authentication with this key',
    PRIMARY KEY (`credID`),
    KEY `idx_user` (`userID`),
    CONSTRAINT `tblWebAuthnCredentials_ibfk_1` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='WebAuthn/PassKey credentials for passwordless authentication.';


-- -----------------------------------------------------------------------------
-- 🏷️ tblUserRoles — many-to-many: users ↔ roles
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblUserRoles` (
    `userRoleID` INT NOT NULL AUTO_INCREMENT,
    `userID`     INT NOT NULL,
    `roleID`     INT NOT NULL,
    PRIMARY KEY (`userRoleID`),
    KEY `userID` (`userID`),
    KEY `roleID` (`roleID`),
    CONSTRAINT `tblUserRoles_ibfk_1` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE,
    CONSTRAINT `tblUserRoles_ibfk_2` FOREIGN KEY (`roleID`)
        REFERENCES `tblRoles` (`roleID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------------------------------------------------------
-- 👥 tblUserGroups — many-to-many: users ↔ groups
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblUserGroups` (
    `userGroupID` INT NOT NULL AUTO_INCREMENT,
    `userID`      INT NOT NULL,
    `groupID`     INT NOT NULL,
    PRIMARY KEY (`userGroupID`),
    KEY `userID`  (`userID`),
    KEY `groupID` (`groupID`),
    CONSTRAINT `tblUserGroups_ibfk_1` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE,
    CONSTRAINT `tblUserGroups_ibfk_2` FOREIGN KEY (`groupID`)
        REFERENCES `tblGroups` (`groupID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------------------------------------------------------
-- 🏢 tblUserDepts — many-to-many: users ↔ departments (with role flags)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblUserDepts` (
    `userDeptID`          INT        NOT NULL AUTO_INCREMENT,
    `userID`              INT        NOT NULL,
    `deptID`              INT        NOT NULL,
    `isDeptLead`          TINYINT(1) DEFAULT 0,
    `isDeptAssistant`     TINYINT(1) DEFAULT 0,
    `isDeptSecretary`     TINYINT(1) DEFAULT 0,
    `isApprover`          TINYINT(1) DEFAULT 0,
    `isMandatoryApprover` TINYINT(1) DEFAULT 0,
    PRIMARY KEY (`userDeptID`),
    KEY `userID` (`userID`),
    KEY `deptID` (`deptID`),
    CONSTRAINT `tblUserDepts_ibfk_1` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE,
    CONSTRAINT `tblUserDepts_ibfk_2` FOREIGN KEY (`deptID`)
        REFERENCES `tblDepts` (`deptID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- #############################################################################
-- SECTION 3: EXPENSE CLAIM TABLES
-- #############################################################################

-- -----------------------------------------------------------------------------
-- 💰 tblExpenseClaims — expense claim headers
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblExpenseClaims` (
    `claimID`     INT            NOT NULL AUTO_INCREMENT,
    `userID`      INT            NOT NULL,
    `deptID`      INT            NOT NULL,
    `claimTitle`  VARCHAR(255)   COLLATE utf8mb4_general_ci DEFAULT NULL,
    `claimDate`   DATE           DEFAULT NULL,
    `totalAmount` DECIMAL(10,2)  DEFAULT NULL,
    `status`      ENUM('Pending','Approved','Rejected','Reimbursed')
                  COLLATE utf8mb4_general_ci DEFAULT 'Pending',
    `fileName`    VARCHAR(255)   COLLATE utf8mb4_general_ci DEFAULT NULL,
    `createdAt`   DATETIME       DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`   DATETIME       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`claimID`),
    KEY `userID` (`userID`),
    KEY `deptID` (`deptID`),
    CONSTRAINT `tblExpenseClaims_ibfk_1` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`),
    CONSTRAINT `tblExpenseClaims_ibfk_2` FOREIGN KEY (`deptID`)
        REFERENCES `tblDepts` (`deptID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------------------------------------------------------
-- 📝 tblExpenseClaimItems — line items within a claim
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblExpenseClaimItems` (
    `itemID`       INT            NOT NULL AUTO_INCREMENT,
    `claimID`      INT            NOT NULL,
    `itemName`     VARCHAR(255)   COLLATE utf8mb4_general_ci DEFAULT NULL,
    `description`  TEXT           COLLATE utf8mb4_general_ci,
    `unitCost`     DECIMAL(10,2)  DEFAULT NULL,
    `quantity`     INT            DEFAULT NULL,
    `lineTotal`    DECIMAL(10,2)  DEFAULT NULL,
    `purchaseDate` DATE           DEFAULT NULL,
    `supplier`     VARCHAR(255)   COLLATE utf8mb4_general_ci DEFAULT NULL,
    PRIMARY KEY (`itemID`),
    KEY `claimID` (`claimID`),
    CONSTRAINT `tblExpenseClaimItems_ibfk_1` FOREIGN KEY (`claimID`)
        REFERENCES `tblExpenseClaims` (`claimID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------------------------------------------------------
-- 📎 tblExpenseClaimFiles — uploaded evidence/receipts
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblExpenseClaimFiles` (
    `fileID`           INT          NOT NULL AUTO_INCREMENT,
    `claimID`          INT          NOT NULL,
    `originalFilename` VARCHAR(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
    `storedFilename`   VARCHAR(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
    `fileSize`         INT          DEFAULT NULL,
    `fileType`         VARCHAR(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
    `stage`            VARCHAR(50)  COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'PDF generation stage (Pending, Approved, Not Approved, Complete)',
    `uploadedAt`       DATETIME     DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`fileID`),
    KEY `claimID` (`claimID`),
    CONSTRAINT `tblExpenseClaimFiles_ibfk_1` FOREIGN KEY (`claimID`)
        REFERENCES `tblExpenseClaims` (`claimID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------------------------------------------------------
-- ✅ tblExpenseClaimApprovals — approver decisions (from migration 002)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblExpenseClaimApprovals` (
    `approvalID` INT          NOT NULL AUTO_INCREMENT COMMENT 'Unique approval record identifier',
    `claimID`    INT          NOT NULL                COMMENT 'FK to tblExpenseClaims.claimID',
    `userID`     INT          NOT NULL                COMMENT 'FK to tblUsers.userID — the approver',
    `decision`     ENUM('Approved','Rejected') NOT NULL COMMENT 'Approver decision for this claim',
    `comments`     TEXT         DEFAULT NULL             COMMENT 'Optional comments from the approver',
    `approverRole` VARCHAR(50)  DEFAULT 'approver'       COMMENT 'Role context (admin, dept_lead, mandatory_approver, dept_approver)',
    `decidedAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the decision was made',
    PRIMARY KEY (`approvalID`),
    KEY `idx_approvals_claim` (`claimID`),
    KEY `idx_approvals_user`  (`userID`),
    CONSTRAINT `fk_approvals_claim` FOREIGN KEY (`claimID`)
        REFERENCES `tblExpenseClaims` (`claimID`) ON DELETE CASCADE,
    CONSTRAINT `fk_approvals_user`  FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Records each approver decision for expense claims (supports multi-approver workflow).';


-- -----------------------------------------------------------------------------
-- 💳 tblExpenseClaimPayments — reimbursement records (from migration 002)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblExpenseClaimPayments` (
    `payID`        INT            NOT NULL AUTO_INCREMENT COMMENT 'Unique payment record identifier',
    `claimID`      INT            NOT NULL                COMMENT 'FK to tblExpenseClaims.claimID',
    `payReference` VARCHAR(255)   NOT NULL                COMMENT 'Internal payment reference (bank ref, cheque number etc)',
    `payMethod`    VARCHAR(100)   DEFAULT NULL             COMMENT 'Payment method (Bank Transfer, Cheque, PayPal etc)',
    `payAmount`    DECIMAL(10,2)  DEFAULT NULL            COMMENT 'Amount paid (may differ from claim total in partial payments)',
    `paidByID`     INT            DEFAULT NULL             COMMENT 'FK to tblUsers.userID — treasury user who processed payment',
    `addedAt`      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the payment record was created',
    PRIMARY KEY (`payID`),
    KEY `idx_payments_claim` (`claimID`),
    CONSTRAINT `fk_payments_claim` FOREIGN KEY (`claimID`)
        REFERENCES `tblExpenseClaims` (`claimID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Records payment/reimbursement references against approved expense claims.';


-- #############################################################################
-- SECTION 3B: ATTENDANCE TABLES
-- #############################################################################

-- -----------------------------------------------------------------------------
-- 🏷️ tblAttendanceServiceTypes — types of services/events to track attendance for
-- Supports nested sub-types (e.g. Sabbath School > Children > Kindergarten).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblAttendanceServiceTypes` (
    `serviceTypeID` INT          NOT NULL AUTO_INCREMENT,
    `parentID`      INT          DEFAULT NULL COMMENT 'FK to self for sub-types (NULL = top-level)',
    `typeName`      VARCHAR(150) NOT NULL,
    `typeSlug`      VARCHAR(100) NOT NULL COMMENT 'URL-safe slug for routing/API',
    `description`   VARCHAR(500) DEFAULT NULL COMMENT 'Optional description shown in UI',
    `sortOrder`     INT          NOT NULL DEFAULT 0,
    `isActive`      TINYINT(1)   NOT NULL DEFAULT 1,
    `createdAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`serviceTypeID`),
    UNIQUE KEY `uq_att_type_slug` (`typeSlug`),
    KEY `idx_att_type_parent` (`parentID`),
    CONSTRAINT `fk_att_type_parent` FOREIGN KEY (`parentID`)
        REFERENCES `tblAttendanceServiceTypes` (`serviceTypeID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Service/event types for attendance tracking, with nested sub-types.';


-- -----------------------------------------------------------------------------
-- 📋 tblAttendanceSessions — a single attendance-recording session
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblAttendanceSessions` (
    `sessionID`     INT          NOT NULL AUTO_INCREMENT,
    `serviceTypeID` INT          NOT NULL COMMENT 'FK → tblAttendanceServiceTypes',
    `eventID`       INT          DEFAULT NULL COMMENT 'FK → tblEvents (NULL if standalone)',
    `sessionDate`   DATE         NOT NULL COMMENT 'Date of the service/event',
    `sessionTime`   TIME         DEFAULT NULL COMMENT 'Start time (optional)',
    `notes`         TEXT         DEFAULT NULL COMMENT 'Optional notes about this session',
    `isDeleted`     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Soft delete flag',
    `createdByID`   INT          DEFAULT NULL COMMENT 'FK → tblUsers',
    `updatedByID`   INT          DEFAULT NULL COMMENT 'FK → tblUsers',
    `createdAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`sessionID`),
    KEY `idx_att_sess_type`   (`serviceTypeID`),
    KEY `idx_att_sess_event`  (`eventID`),
    KEY `idx_att_sess_date`   (`sessionDate`),
    KEY `idx_att_sess_del`    (`isDeleted`),
    CONSTRAINT `fk_att_sess_type` FOREIGN KEY (`serviceTypeID`)
        REFERENCES `tblAttendanceServiceTypes` (`serviceTypeID`) ON DELETE RESTRICT,
    CONSTRAINT `fk_att_sess_creator` FOREIGN KEY (`createdByID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_att_sess_updater` FOREIGN KEY (`updatedByID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Individual attendance sessions — one row per service/event occasion.';


-- -----------------------------------------------------------------------------
-- 🔢 tblAttendanceCounts — headcount breakdowns within a session
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblAttendanceCounts` (
    `countID`     INT          NOT NULL AUTO_INCREMENT,
    `sessionID`   INT          NOT NULL COMMENT 'FK → tblAttendanceSessions',
    `groupLabel`  VARCHAR(100) NOT NULL COMMENT 'Age group or category label (e.g. Adults, Children, Visitors)',
    `headcount`   INT          NOT NULL DEFAULT 0 COMMENT 'Number of people counted',
    `sortOrder`   INT          NOT NULL DEFAULT 0,
    `createdAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`countID`),
    KEY `idx_att_count_session` (`sessionID`),
    CONSTRAINT `fk_att_count_session` FOREIGN KEY (`sessionID`)
        REFERENCES `tblAttendanceSessions` (`sessionID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Headcount breakdowns per session — multiple groups per session.';


-- #############################################################################
-- SECTION 4: LOGGING TABLES
-- #############################################################################

-- -----------------------------------------------------------------------------
-- 📝 tblActivityLogs — audit trail for all portal activity
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblActivityLogs` (
    `logID`               INT          NOT NULL AUTO_INCREMENT,
    `userID`              INT          DEFAULT NULL,
    `activityType`        VARCHAR(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
    `activityDescription` TEXT         COLLATE utf8mb4_general_ci,
    `claimID`             INT          DEFAULT NULL,
    `requestHeaders`      LONGTEXT     COLLATE utf8mb4_general_ci
                          COMMENT 'Request headers from the request which triggered the action',
    `sessionID`           VARCHAR(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
    `visitorIP`           VARCHAR(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
    `userAgent`           TEXT         COLLATE utf8mb4_general_ci,
    `sessionDataSnapshot` TEXT         COLLATE utf8mb4_general_ci,
    `timestamp`           DATETIME     DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`logID`),
    KEY `userID`  (`userID`),
    KEY `claimID` (`claimID`),
    CONSTRAINT `tblActivityLogs_ibfk_1` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`),
    CONSTRAINT `tblActivityLogs_ibfk_2` FOREIGN KEY (`claimID`)
        REFERENCES `tblExpenseClaims` (`claimID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Audit trail for all portal activity — login, actions, errors.';


-- -----------------------------------------------------------------------------
-- 🚨 tblErrors — centralised error/warning/notice log (from migration 001)
-- Referenced by core/Logger.php::errorPlatform()
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblErrors` (
    `errorID`        INT           NOT NULL AUTO_INCREMENT COMMENT 'Unique error record identifier',
    `errorPlatform`  VARCHAR(50)   NOT NULL DEFAULT 'PHP'  COMMENT 'Platform where error occurred (PHP, MySQL, dompdf, MS365, cURL, JS etc)',
    `errorSeverity`  VARCHAR(50)   NOT NULL DEFAULT 'Error' COMMENT 'Severity: Notification, Warning, Error, Fatal',
    `errorCode`      VARCHAR(100)  DEFAULT NULL             COMMENT 'Platform-specific error code',
    `errorTitle`     VARCHAR(500)  DEFAULT NULL             COMMENT 'Short error description',
    `errorDetail`    LONGTEXT      DEFAULT NULL             COMMENT 'Full error detail including backtrace',
    `userID`         INT           DEFAULT NULL             COMMENT 'UserID of logged-in user (NULL if anonymous)',
    `visitorIP`      VARCHAR(100)  DEFAULT NULL             COMMENT 'Client IP (respects CF-Connecting-IP / X-Forwarded-For)',
    `userAgent`      TEXT          DEFAULT NULL             COMMENT 'Browser user-agent',
    `requestURL`     VARCHAR(2048) DEFAULT NULL             COMMENT 'Full request URI',
    `requestHeaders` LONGTEXT      DEFAULT NULL             COMMENT 'JSON-encoded request headers',
    `isResolved`     TINYINT(1)    NOT NULL DEFAULT 0       COMMENT 'Whether admin has reviewed/resolved this error',
    `resolvedAt`     DATETIME      DEFAULT NULL             COMMENT 'When the error was marked resolved',
    `resolvedByID`   INT           DEFAULT NULL             COMMENT 'UserID of admin who resolved this error',
    `createdAt`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the error was logged',
    PRIMARY KEY (`errorID`),
    KEY `idx_errors_platform`  (`errorPlatform`),
    KEY `idx_errors_severity`  (`errorSeverity`),
    KEY `idx_errors_created`   (`createdAt`),
    KEY `idx_errors_resolved`  (`isResolved`),
    KEY `idx_errors_user`      (`userID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Centralised error log for all platforms/libraries. See core/Logger.php.';


-- #############################################################################
-- SECTION 5: SEED DATA — Settings
-- (ON DUPLICATE KEY preserves existing values)
-- #############################################################################

-- ─── Site settings ───────────────────────────────────────────────────────────
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('site.name', 'Portal', 0, 'Portal')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('site.tagline', 'Staff and Volunteer Admin Portal', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('site.timezone', 'UTC', 0, 'UTC')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('site.defaultFromEmail', '', 0, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('site.replyToEmail', '', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('site.brandLogoURL', '/assets/images/logo.svg', 0, '/assets/images/logo.svg')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('site.footerText', '', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('site.copyrightOrg', 'MWBM Partners Ltd', 0, 'Organisation Name')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('site.copyrightStartYear', '2025', 0, '2025')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- ─── Portal settings ─────────────────────────────────────────────────────────
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('portal.version', '0.2.0', 0, '0.2.0')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('portal.devAccessRoles', '', 0, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('portal.alphaAccessRoles', '', 0, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('portal.betaAccessRoles', '', 0, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- ─── Feature toggles ─────────────────────────────────────────────────────────
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('features.darkModeEnabled', 'true', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('features.timezoneAwareness', 'true', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- ─── Auth — general ──────────────────────────────────────────────────────────
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.method', 'local', 0, 'local')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.allowWordPressLogin', 'false', 0, 'false')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.allowMS365Login', 'false', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.allowGoogleLogin', 'false', 0, 'false')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.passkeySupport', 'false', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- ─── Auth — rate limiting ────────────────────────────────────────────────────
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.rateLimit.maxAttempts', '5', 0, '5')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.rateLimit.windowMinutes', '15', 0, '15')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- ─── Auth — password policy (from migration 006) ────────────────────────────
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.password.minLength', '8', 0, '8')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.password.requireUppercase', 'true', 0, 'true')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.password.requireNumber', 'true', 0, 'true')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.password.requireSpecial', 'true', 0, 'true')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.passwordReset.tokenExpiry', '60', 0, '60')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- ─── Auth — MS365 OAuth credentials (dormant until configured) ───────────────
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.ms365.tenantID', '', 1, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.ms365.tenantOnly', 'true', 1, 'true')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.ms365.enduser.clientID', '', 1, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.ms365.enduser.clientSecret', '', 1, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.ms365.enduser.redirectURI', '', 1, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.ms365.appwide.clientID', '', 1, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.ms365.appwide.clientSecret', '', 1, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.ms365.appwide.redirectURI', '', 1, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.ms365.defaultFrom', '', 1, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.ms365.defaultReplyTo', '', 0, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- ─── Auth — Captcha ──────────────────────────────────────────────────────────
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.recaptcha.siteKey', '', 1, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.recaptcha.secretKey', '', 1, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.recaptcha.version', 'v2', 0, 'v2')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.turnstile.siteKey', '', 1, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.turnstile.secretKey', '', 1, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- ─── Auth — Google OAuth ─────────────────────────────────────────────────────
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.google.clientID', '', 1, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.google.clientSecret', '', 1, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.google.redirectURI', '', 1, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.google.hostedDomain', '', 0, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- ─── Auth — WebAuthn ────────────────────────────────────────────────────────
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.webauthn.rpName', '', 0, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.webauthn.rpID', '', 0, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- ─── i18n settings (from migration 012) ─────────────────────────────────────
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('i18n.defaultLocale', 'en', 0, 'en')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('i18n.enabled', 'true', 0, 'true')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- ─── Mail settings ───────────────────────────────────────────────────────────
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('mail.defaultFromName', 'Portal', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('mail.defaultFromAddress', '', 0, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('mail.defaultReplyTo', '', 0, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('mail.useGraphAPI', 'true', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('mail.sendFromSharedMailbox', 'true', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- ─── Notification settings ───────────────────────────────────────────────────
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('notifications.allowSMS', 'false', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('notifications.allowEmail', 'true', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('notifications.enableDigestMode', 'true', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('notifications.senderName', 'Portal Notifications', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- ─── Expenses app settings ───────────────────────────────────────────────────
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('expenses.enabled', 'true', 0, 'true')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('expenses.displayName', 'Expense Claims', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('expenses.displayIcon', 'fa-solid fa-receipt', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('expenses.brandColor', '#007B55', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('expenses.allowMultipleApprovers', 'true', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('expenses.reminder.throttleHours', '6', 0, '12')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('expenses.autoGeneratePDF', 'true', 0, 'true')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('expenses.replyToEmail', '', 0, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('expenses.email.fromName', '', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('expenses.email.fromAddress', '', 0, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('expenses.email.replyTo', '', 0, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('expenses.showInlineLogsInDashboard', 'true', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('expenses.approvalThreshold', '500.00', 0, '500.00')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('expenses.requireTreasuryApproval', 'true', 0, 'true')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('expenses.followUpDays', '7', 0, '7')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('expenses.emailNotifications', 'true', 0, 'true')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- ─── API endpoint toggles ────────────────────────────────────────────────────
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('api.expenses.list.enabled', 'true', 0, 'true')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('api.expenses.stats.enabled', 'true', 0, 'true')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('api.expenses.attachments.enabled', 'true', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('api.expenses.update-status.enabled', 'false', 0, 'false')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('api.expenses.delete.enabled', 'false', 1, 'false')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- ─── Future app toggles (disabled by default) ────────────────────────────────
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('attendance.enabled', 'false', 0, 'false')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('attendance.displayName', 'Attendance', 0, 'Attendance')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('attendance.displayIcon', 'fa-solid fa-clipboard-list', 0, 'fa-solid fa-clipboard-list')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('attendance.brandColor', '#6f42c1', 0, '#6f42c1')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('calendar.enabled', 'false', 0, 'false')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('leadership.enabled', 'false', 0, 'false')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('preachingplan.enabled', 'false', 0, 'false')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;


-- #############################################################################
-- SECTION 6: SEED DATA — Routes
-- (ON DUPLICATE KEY updates targetFile so routes stay current)
-- #############################################################################

-- ─── Core pages ──────────────────────────────────────────────────────────────
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('dashboard', 'dashboard/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('login', 'auth/login/index.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- ─── Auth — forgot/reset password, account ───────────────────────────────────
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('forgot-password', 'auth/forgot-password/index.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('forgot-password/save', 'auth/forgot-password/save.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('reset-password', 'auth/reset-password/index.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('reset-password/save', 'auth/reset-password/save.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('account', 'auth/account/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('account/save', 'auth/account/save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('account/change-password', 'auth/account/change-password.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('account/linked-accounts', 'auth/account/linked-accounts.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('account/unlink', 'auth/account/unlink.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('account/webauthn', 'auth/account/webauthn.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('account/webauthn/delete', 'auth/account/webauthn-delete.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- ─── Expenses ────────────────────────────────────────────────────────────────
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('expenses/submit', 'expenses/submit/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('expenses/submit/save', 'expenses/submit/save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('expenses/approve', 'expenses/approve/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('expenses/approve/save', 'expenses/approve/save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('expenses/treasury', 'expenses/treasury/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('expenses/treasury/save', 'expenses/treasury/save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('expenses/view', 'expenses/view/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- ─── Settings ────────────────────────────────────────────────────────────────
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('settings', 'settings/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('settings/save', 'settings/save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- ─── Admin ───────────────────────────────────────────────────────────────────
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('admin/migrations', 'admin/migrations/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- ─── Help Centre ─────────────────────────────────────────────────────────────
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('help', 'help/index.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('help/getting-started', 'help/getting-started.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('help/expenses', 'help/expenses.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('help/approvals', 'help/approvals.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('help/treasury', 'help/treasury.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('help/admin', 'help/admin.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('help/faq', 'help/faq.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('help/translations', 'help/translations.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- ─── Attendance ────────────────────────────────────────────────────────────
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('attendance', 'attendance/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('attendance/record', 'attendance/record.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('attendance/record/save', 'attendance/record/save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('attendance/record/delete', 'attendance/record/delete.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('attendance/manage', 'attendance/manage/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('attendance/manage/save', 'attendance/manage/save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('attendance/report', 'attendance/report.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);


-- #############################################################################
-- SECTION 7: MARK ALL MIGRATIONS AS EXECUTED
-- Prevents the web-based Migrator from re-running individual migration files
-- #############################################################################

INSERT INTO `tblMigrations` (`filename`) VALUES ('000_create_migrations_table.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('001_create_tblErrors.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('002_create_expense_support_tables.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('003_add_missing_settings.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('004_seed_routes.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('005_add_help_routes_and_dev_settings.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('006_local_auth_enhancement.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('007_admin_routes.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('008_calendar_events_schema.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('009_attendance_schema.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('010_expenses_phase6.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('011_auth_phase7.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('012_i18n_phase8.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('013_help_translations_route.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
