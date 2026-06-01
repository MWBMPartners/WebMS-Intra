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
-- After running, all individual migrations through the highest-numbered file
-- present in web/_sql/ are marked as executed in tblMigrations so the
-- web-based Migrator won't re-run them.
--
-- Covers migrations: 000-052 (matches the tblMigrations seed block at the
-- end of this file — when you add a new migration, also add its filename
-- to that block and port its DDL/seeds into the appropriate section here).
-- =============================================================================
-- @package   Portal\Database
-- @author    MWBM Partners Ltd (t/a MWservices)
-- @copyright 2025-2026 MWBM Partners Ltd (t/a MWservices)
-- @license   All Rights Reserved
-- @version   0.9.0
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
-- 🌐 tblSites — multi-site definitions (from migration 015)
-- Each row represents a portal site/division.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblSites` (
    `siteID`        INT          NOT NULL AUTO_INCREMENT,
    `siteName`      VARCHAR(255) COLLATE utf8mb4_general_ci NOT NULL
                    COMMENT 'Human-readable site name',
    `siteKey`       VARCHAR(50)  COLLATE utf8mb4_general_ci NOT NULL
                    COMMENT 'Machine-readable slug (e.g. cambridge, leeds)',
    `hostPattern`   VARCHAR(255) COLLATE utf8mb4_general_ci DEFAULT NULL
                    COMMENT 'Hostname for subdomain detection',
    `logoPath`      VARCHAR(500) COLLATE utf8mb4_general_ci DEFAULT '/assets/images/logo.svg'
                    COMMENT 'Path to site-specific logo image',
    `faviconPath`   VARCHAR(500) COLLATE utf8mb4_general_ci DEFAULT NULL
                    COMMENT 'Path or URL to per-site favicon; NULL falls back to default',
    `primaryColor`  VARCHAR(7)   COLLATE utf8mb4_general_ci DEFAULT '#5e6ad2'
                    COMMENT 'Hex colour for site branding (default: Linear-style indigo)',
    `copyrightOrg`  VARCHAR(255) COLLATE utf8mb4_general_ci DEFAULT NULL
                    COMMENT 'Copyright holder name for footer',
    `timezone`      VARCHAR(50)  COLLATE utf8mb4_general_ci DEFAULT 'UTC'
                    COMMENT 'Site-specific timezone identifier',
    `isActive`      TINYINT(1)   NOT NULL DEFAULT 1,
    `createdAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`siteID`),
    UNIQUE KEY `uq_site_key` (`siteKey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Multi-site definitions. Each row represents a portal site/division.';


-- -----------------------------------------------------------------------------
-- ⚙️ tblSettings — dot-notation key/value configuration store
-- isSensitive=1 values are encrypted with libsodium at rest
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblSettings` (
    `settingID`    INT          NOT NULL AUTO_INCREMENT,
    `siteID`       INT          DEFAULT NULL
                   COMMENT 'FK → tblSites. NULL = global default, specific siteID = per-site override',
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
    UNIQUE KEY `uq_setting_key_site` (`settingKey`, `siteID`),
    KEY `idx_settings_site` (`siteID`),
    CONSTRAINT `fk_settings_site` FOREIGN KEY (`siteID`)
        REFERENCES `tblSites` (`siteID`) ON DELETE CASCADE
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
    `siteID`   INT          NOT NULL DEFAULT 1 COMMENT 'FK → tblSites.siteID',
    `deptName` VARCHAR(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
    `deptCode` VARCHAR(50)  COLLATE utf8mb4_general_ci DEFAULT NULL,
    `isActive` TINYINT(1)   DEFAULT 1,
    PRIMARY KEY (`deptID`),
    KEY `idx_depts_site` (`siteID`),
    CONSTRAINT `fk_depts_site` FOREIGN KEY (`siteID`)
        REFERENCES `tblSites` (`siteID`)
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
    `notifyPrefs`  JSON         DEFAULT NULL COMMENT 'User notification preferences (JSON: {emailDigest, expenseUpdates, eventReminders})',
    `totpSecret`   VARCHAR(64)  DEFAULT NULL COMMENT 'Encrypted TOTP shared secret (from migration 032)',
    `totpEnabled`  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'TOTP 2FA enabled flag (from migration 032)',
    `createdAt`    DATETIME     DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`userID`),
    UNIQUE KEY `emailAddress` (`emailAddress`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------------------------------------------------------
-- 🔗 tblUserSites — user-to-site assignments with site-level role flags
-- (from migration 015)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblUserSites` (
    `userSiteID`      INT        NOT NULL AUTO_INCREMENT,
    `userID`          INT        NOT NULL COMMENT 'FK → tblUsers.userID',
    `siteID`          INT        NOT NULL COMMENT 'FK → tblSites.siteID',
    `isSiteAdmin`     TINYINT(1) NOT NULL DEFAULT 0
                      COMMENT 'Can manage users/settings/data for this site',
    `isSiteRootAdmin` TINYINT(1) NOT NULL DEFAULT 0
                      COMMENT 'Full control within this site, can assign site admins',
    `isActive`        TINYINT(1) NOT NULL DEFAULT 1,
    `joinedAt`        DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`userSiteID`),
    UNIQUE KEY `uq_user_site` (`userID`, `siteID`),
    KEY `idx_us_site` (`siteID`),
    CONSTRAINT `fk_us_user` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE,
    CONSTRAINT `fk_us_site` FOREIGN KEY (`siteID`)
        REFERENCES `tblSites` (`siteID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Maps users to sites with per-site admin role flags (4-tier hierarchy).';


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
    `isVerified`   TINYINT(1)   NOT NULL DEFAULT 0
                   COMMENT 'Whether the email was verified before activation. Admins created via the installer are auto-verified (=1).',
    `lastLogin`    DATETIME     DEFAULT NULL,
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
    `siteID`      INT            NOT NULL DEFAULT 1 COMMENT 'FK → tblSites.siteID',
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
    KEY `idx_claims_site` (`siteID`),
    CONSTRAINT `tblExpenseClaims_ibfk_1` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`),
    CONSTRAINT `tblExpenseClaims_ibfk_2` FOREIGN KEY (`deptID`)
        REFERENCES `tblDepts` (`deptID`),
    CONSTRAINT `fk_claims_site` FOREIGN KEY (`siteID`)
        REFERENCES `tblSites` (`siteID`)
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
    `siteID`        INT          NOT NULL DEFAULT 1 COMMENT 'FK → tblSites.siteID',
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
        REFERENCES `tblAttendanceServiceTypes` (`serviceTypeID`) ON DELETE SET NULL,
    KEY `idx_ast_site` (`siteID`),
    CONSTRAINT `fk_ast_site` FOREIGN KEY (`siteID`)
        REFERENCES `tblSites` (`siteID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Service/event types for attendance tracking, with nested sub-types.';


-- -----------------------------------------------------------------------------
-- 📋 tblAttendanceSessions — a single attendance-recording session
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblAttendanceSessions` (
    `sessionID`     INT          NOT NULL AUTO_INCREMENT,
    `siteID`        INT          NOT NULL DEFAULT 1 COMMENT 'FK → tblSites.siteID',
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
        REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL,
    KEY `idx_asess_site` (`siteID`),
    CONSTRAINT `fk_asess_site` FOREIGN KEY (`siteID`)
        REFERENCES `tblSites` (`siteID`)
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
-- SECTION 3C: CALENDAR / EVENTS / PREACHING PLAN TABLES
-- (from migration 008, with siteID from 015, slug uniqueness from 019,
--  composite indexes from 020)
-- #############################################################################

-- -----------------------------------------------------------------------------
-- 📂 tblEventCategories — top-level and sub-categories for events
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblEventCategories` (
    `categoryID`   INT          NOT NULL AUTO_INCREMENT,
    `siteID`       INT          NOT NULL DEFAULT 1 COMMENT 'FK → tblSites.siteID',
    `parentID`     INT          DEFAULT NULL COMMENT 'NULL = top-level; FK to self for sub-categories',
    `categoryName` VARCHAR(150) NOT NULL,
    `categorySlug` VARCHAR(100) NOT NULL COMMENT 'URL-safe slug',
    `sortOrder`    INT          NOT NULL DEFAULT 0,
    `color`        VARCHAR(9)   DEFAULT NULL COMMENT 'Hex colour (#RRGGBB or #RRGGBBAA) for the year planner / month grid',
    `displayStyle` ENUM('background','text') NOT NULL DEFAULT 'background'
                   COMMENT 'How the colour renders in the year planner: tinted background vs. coloured text',
    `isActive`     TINYINT(1)   NOT NULL DEFAULT 1,
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`categoryID`),
    UNIQUE KEY `uq_cat_slug_site` (`categorySlug`, `siteID`),
    KEY `idx_cat_parent` (`parentID`),
    KEY `idx_ecat_site` (`siteID`),
    CONSTRAINT `fk_cat_parent` FOREIGN KEY (`parentID`)
        REFERENCES `tblEventCategories` (`categoryID`) ON DELETE SET NULL,
    CONSTRAINT `fk_ecat_site` FOREIGN KEY (`siteID`)
        REFERENCES `tblSites` (`siteID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Event categories with nested sub-categories.';

-- -----------------------------------------------------------------------------
-- 🗓️ tblCalendarMonthThemes — per-year-per-month strap-line shown on the
--    year planner (e.g. "~Healthy connections~" for February 2026).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblCalendarMonthThemes` (
    `themeID`    INT          NOT NULL AUTO_INCREMENT,
    `siteID`     INT          NOT NULL COMMENT 'FK → tblSites',
    `year`       SMALLINT     NOT NULL,
    `month`      TINYINT      NOT NULL COMMENT '1..12',
    `themeText`  VARCHAR(255) NOT NULL,
    `createdAt`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`themeID`),
    UNIQUE KEY `uq_cmt_site_year_month` (`siteID`, `year`, `month`),
    KEY `idx_cmt_site_year` (`siteID`, `year`),
    CONSTRAINT `fk_cmt_site` FOREIGN KEY (`siteID`)
        REFERENCES `tblSites` (`siteID`) ON DELETE CASCADE,
    CONSTRAINT `chk_cmt_month` CHECK (`month` BETWEEN 1 AND 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Per-year-per-month strap-line shown on the calendar year planner.';


-- -----------------------------------------------------------------------------
-- 🏷️ tblEventTypes — event types with optional sub-types
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblEventTypes` (
    `typeID`   INT          NOT NULL AUTO_INCREMENT,
    `siteID`   INT          NOT NULL DEFAULT 1 COMMENT 'FK → tblSites.siteID',
    `parentID` INT          DEFAULT NULL COMMENT 'NULL = top-level; FK to self for sub-types',
    `typeName` VARCHAR(150) NOT NULL,
    `typeSlug` VARCHAR(100) NOT NULL,
    `sortOrder` INT         NOT NULL DEFAULT 0,
    `isActive` TINYINT(1)   NOT NULL DEFAULT 1,
    `createdAt` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`typeID`),
    UNIQUE KEY `uq_type_slug_site` (`typeSlug`, `siteID`),
    KEY `idx_type_parent` (`parentID`),
    KEY `idx_etype_site` (`siteID`),
    CONSTRAINT `fk_type_parent` FOREIGN KEY (`parentID`)
        REFERENCES `tblEventTypes` (`typeID`) ON DELETE SET NULL,
    CONSTRAINT `fk_etype_site` FOREIGN KEY (`siteID`)
        REFERENCES `tblSites` (`siteID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Event types with nested sub-types (e.g. Worship Service > Sabbath School).';


-- -----------------------------------------------------------------------------
-- 🎨 tblEventThemes — reusable themes/tags for events
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblEventThemes` (
    `themeID`   INT          NOT NULL AUTO_INCREMENT,
    `siteID`    INT          NOT NULL DEFAULT 1 COMMENT 'FK → tblSites.siteID',
    `themeName` VARCHAR(150) NOT NULL,
    `themeSlug` VARCHAR(100) NOT NULL,
    `color`     VARCHAR(7)   DEFAULT NULL COMMENT 'Hex color code for calendar display',
    `isActive`  TINYINT(1)   NOT NULL DEFAULT 1,
    `createdAt` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`themeID`),
    UNIQUE KEY `uq_theme_slug_site` (`themeSlug`, `siteID`),
    KEY `idx_etheme_site` (`siteID`),
    CONSTRAINT `fk_etheme_site` FOREIGN KEY (`siteID`)
        REFERENCES `tblSites` (`siteID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Reusable themes/tags that can be assigned to events.';


-- -----------------------------------------------------------------------------
-- 🔄 tblEventSeries — named series of events (nestable)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblEventSeries` (
    `seriesID`    INT          NOT NULL AUTO_INCREMENT,
    `siteID`      INT          NOT NULL DEFAULT 1 COMMENT 'FK → tblSites.siteID',
    `parentID`    INT          DEFAULT NULL COMMENT 'FK to self for nested series',
    `seriesName`  VARCHAR(255) NOT NULL,
    `seriesSlug`  VARCHAR(150) NOT NULL,
    `description` TEXT         DEFAULT NULL,
    `heroImage`   VARCHAR(500) DEFAULT NULL COMMENT 'Path in _uploads/calendar/',
    `isActive`    TINYINT(1)   NOT NULL DEFAULT 1,
    `createdAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`seriesID`),
    UNIQUE KEY `uq_series_slug_site` (`seriesSlug`, `siteID`),
    KEY `idx_series_parent` (`parentID`),
    KEY `idx_eseries_site` (`siteID`),
    CONSTRAINT `fk_series_parent` FOREIGN KEY (`parentID`)
        REFERENCES `tblEventSeries` (`seriesID`) ON DELETE SET NULL,
    CONSTRAINT `fk_eseries_site` FOREIGN KEY (`siteID`)
        REFERENCES `tblSites` (`siteID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Named event series (can be nested). Events link to a series.';


-- -----------------------------------------------------------------------------
-- 🔁 tblRecurrenceRules — recurrence patterns for event series
-- Weekly, Monthly, Quarterly, Yearly with flexible nth-day rules.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblRecurrenceRules` (
    `ruleID`       INT          NOT NULL AUTO_INCREMENT,
    `siteID`       INT          NOT NULL DEFAULT 1 COMMENT 'FK → tblSites (added by migration 018)',
    `seriesID`     INT          NOT NULL COMMENT 'FK → tblEventSeries',
    `frequency`    ENUM('weekly','fortnightly','monthly','quarterly','yearly','custom')
                   NOT NULL DEFAULT 'weekly',
    `intervalVal`  INT          NOT NULL DEFAULT 1 COMMENT 'e.g. every 2 weeks',
    `dayOfWeek`    VARCHAR(20)  DEFAULT NULL COMMENT 'CSV of days: 0=Sun..6=Sat (for weekly/fortnightly)',
    `dayOfMonth`   INT          DEFAULT NULL COMMENT 'Day of month (1-31) for monthly/yearly',
    `weekOfMonth`  INT          DEFAULT NULL COMMENT 'Nth week (1-5, -1=last) for monthly patterns',
    `monthOfYear`  INT          DEFAULT NULL COMMENT 'Month (1-12) for yearly patterns',
    `startDate`    DATE         NOT NULL COMMENT 'When recurrence begins',
    `endDate`      DATE         DEFAULT NULL COMMENT 'When recurrence ends (NULL=no end)',
    `maxOccurrences` INT        DEFAULT NULL COMMENT 'Max number of occurrences (NULL=unlimited)',
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`ruleID`),
    KEY `idx_recur_series` (`seriesID`),
    KEY `idx_recur_site` (`siteID`),
    CONSTRAINT `fk_recur_series` FOREIGN KEY (`seriesID`)
        REFERENCES `tblEventSeries` (`seriesID`) ON DELETE CASCADE,
    CONSTRAINT `fk_recur_site` FOREIGN KEY (`siteID`)
        REFERENCES `tblSites` (`siteID`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Recurrence rules for event series — generates individual event dates.';


-- -----------------------------------------------------------------------------
-- 📅 tblEvents — individual event instances
-- Each row is a single scheduled occurrence (standalone or part of a series).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblEvents` (
    `eventID`       INT          NOT NULL AUTO_INCREMENT,
    `siteID`        INT          NOT NULL DEFAULT 1 COMMENT 'FK → tblSites.siteID',
    `seriesID`      INT          DEFAULT NULL COMMENT 'FK → tblEventSeries (NULL if standalone)',
    `categoryID`    INT          DEFAULT NULL COMMENT 'FK → tblEventCategories',
    `typeID`        INT          DEFAULT NULL COMMENT 'FK → tblEventTypes',
    `eventName`     VARCHAR(255) NOT NULL,
    `eventSlug`     VARCHAR(200) NOT NULL COMMENT 'URL-safe slug for direct linking',
    `description`   TEXT         DEFAULT NULL,
    `startDateTime` DATETIME     NOT NULL COMMENT 'Event start (stored in UTC)',
    `endDateTime`   DATETIME     DEFAULT NULL COMMENT 'Event end (stored in UTC)',
    `timezone`      VARCHAR(50)  NOT NULL DEFAULT 'Europe/London',
    `isAllDay`      TINYINT(1)   NOT NULL DEFAULT 0,

    -- 📍 Location fields (can override series location)
    `locationName`    VARCHAR(255) DEFAULT NULL,
    `locationAddress` TEXT         DEFAULT NULL,
    `locationWebURL`  VARCHAR(500) DEFAULT NULL,
    `locationGeoLat`  DECIMAL(10,7) DEFAULT NULL,
    `locationGeoLng`  DECIMAL(10,7) DEFAULT NULL,
    `locationW3W`     VARCHAR(100)  DEFAULT NULL COMMENT 'what3words address',
    `locationPhone`   VARCHAR(50)   DEFAULT NULL,
    `locationEmail`   VARCHAR(255)  DEFAULT NULL,

    -- 🏢 Organisation fields
    `hostOrgName`    VARCHAR(255)  DEFAULT NULL COMMENT 'Organisation hosting the event',
    `partnerOrgs`    TEXT          DEFAULT NULL COMMENT 'JSON array of partner org names',

    -- 🖼️ Images (paths relative to _uploads/calendar/)
    `heroImage`      VARCHAR(500)  DEFAULT NULL,
    `posterImage`    VARCHAR(500)  DEFAULT NULL,
    `profileImage`   VARCHAR(500)  DEFAULT NULL,

    -- 📊 Status and visibility
    `status`       ENUM('draft','published','cancelled','postponed') NOT NULL DEFAULT 'draft',
    `isPublic`     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'Visible on public calendar',
    `isFeatured`   TINYINT(1)   NOT NULL DEFAULT 0,
    `isDeleted`    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Soft delete flag',
    `deletedAt`    DATETIME     DEFAULT NULL COMMENT 'Timestamp of soft-delete (set when isDeleted flips to 1)',
    `capacity`     INT          DEFAULT NULL COMMENT 'Max attendees (NULL = unlimited)',

    -- 🔢 Metadata
    `createdByID`  INT           DEFAULT NULL COMMENT 'FK → tblUsers',
    `updatedByID`  INT           DEFAULT NULL COMMENT 'FK → tblUsers',
    `createdAt`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`eventID`),
    UNIQUE KEY `uq_event_slug` (`eventSlug`),
    KEY `idx_event_series`   (`seriesID`),
    KEY `idx_event_category` (`categoryID`),
    KEY `idx_event_type`     (`typeID`),
    KEY `idx_event_start`    (`startDateTime`),
    KEY `idx_event_status`   (`status`),
    KEY `idx_event_deleted`  (`isDeleted`),
    KEY `idx_event_public`   (`isPublic`, `status`, `isDeleted`),
    KEY `idx_events_site`    (`siteID`),
    KEY `idx_events_site_status_date` (`siteID`, `status`, `isDeleted`, `startDateTime`),
    CONSTRAINT `fk_event_series`   FOREIGN KEY (`seriesID`)   REFERENCES `tblEventSeries` (`seriesID`)     ON DELETE SET NULL,
    CONSTRAINT `fk_event_category` FOREIGN KEY (`categoryID`) REFERENCES `tblEventCategories` (`categoryID`) ON DELETE SET NULL,
    CONSTRAINT `fk_event_type`     FOREIGN KEY (`typeID`)     REFERENCES `tblEventTypes` (`typeID`)       ON DELETE SET NULL,
    CONSTRAINT `fk_event_creator`  FOREIGN KEY (`createdByID`) REFERENCES `tblUsers` (`userID`)           ON DELETE SET NULL,
    CONSTRAINT `fk_event_updater`  FOREIGN KEY (`updatedByID`) REFERENCES `tblUsers` (`userID`)           ON DELETE SET NULL,
    CONSTRAINT `fk_events_site`    FOREIGN KEY (`siteID`)      REFERENCES `tblSites` (`siteID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Individual event instances — standalone or part of a series.';


-- -----------------------------------------------------------------------------
-- 🏷️ tblEventThemeMap — many-to-many: events ↔ themes
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblEventThemeMap` (
    `mapID`    INT NOT NULL AUTO_INCREMENT,
    `eventID`  INT NOT NULL,
    `themeID`  INT NOT NULL,
    PRIMARY KEY (`mapID`),
    UNIQUE KEY `uq_event_theme` (`eventID`, `themeID`),
    CONSTRAINT `fk_etheme_event` FOREIGN KEY (`eventID`) REFERENCES `tblEvents` (`eventID`) ON DELETE CASCADE,
    CONSTRAINT `fk_etheme_theme` FOREIGN KEY (`themeID`) REFERENCES `tblEventThemes` (`themeID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Many-to-many mapping of events to themes.';


-- -----------------------------------------------------------------------------
-- 👤 tblEventPeople — people associated with an event (host, speaker, etc.)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblEventPeople` (
    `eventPersonID` INT          NOT NULL AUTO_INCREMENT,
    `eventID`       INT          NOT NULL,
    `userID`        INT          DEFAULT NULL COMMENT 'FK → tblUsers (NULL if external person)',
    `externalName`  VARCHAR(255) DEFAULT NULL COMMENT 'Name if person is not a portal user',
    `role`          VARCHAR(100) NOT NULL DEFAULT 'host' COMMENT 'host, speaker, musician, organiser, etc.',
    `isPrimary`     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Primary person for this role',
    `sortOrder`     INT          NOT NULL DEFAULT 0,
    `createdAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`eventPersonID`),
    KEY `idx_epeople_event` (`eventID`),
    KEY `idx_epeople_user`  (`userID`),
    CONSTRAINT `fk_epeople_event` FOREIGN KEY (`eventID`) REFERENCES `tblEvents` (`eventID`) ON DELETE CASCADE,
    CONSTRAINT `fk_epeople_user`  FOREIGN KEY (`userID`)  REFERENCES `tblUsers` (`userID`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='People assigned to events with roles (host, speaker, musician, etc.).';


-- -----------------------------------------------------------------------------
-- 🔗 tblEventLinks — URLs associated with an event
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblEventLinks` (
    `linkID`    INT          NOT NULL AUTO_INCREMENT,
    `eventID`   INT          NOT NULL,
    `linkType`  VARCHAR(50)  NOT NULL DEFAULT 'website' COMMENT 'website, rsvp, social, booking, livestream, etc.',
    `linkURL`   VARCHAR(2048) NOT NULL,
    `linkLabel` VARCHAR(255)  DEFAULT NULL COMMENT 'Display label for the link',
    `sortOrder` INT          NOT NULL DEFAULT 0,
    `createdAt` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`linkID`),
    KEY `idx_elinks_event` (`eventID`),
    CONSTRAINT `fk_elinks_event` FOREIGN KEY (`eventID`) REFERENCES `tblEvents` (`eventID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='URLs related to an event — social media, booking pages, livestream links, etc.';


-- -----------------------------------------------------------------------------
-- 📎 tblEventMaterials — downloadable documents for an event
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblEventMaterials` (
    `materialID`   INT          NOT NULL AUTO_INCREMENT,
    `eventID`      INT          NOT NULL,
    `materialType` VARCHAR(50)  NOT NULL DEFAULT 'document' COMMENT 'document, notes, slides, audio, video',
    `fileName`     VARCHAR(255) NOT NULL COMMENT 'Original filename',
    `filePath`     VARCHAR(500) NOT NULL COMMENT 'Path relative to _uploads/calendar/materials/',
    `fileSize`     INT          DEFAULT NULL COMMENT 'File size in bytes',
    `mimeType`     VARCHAR(100) DEFAULT NULL,
    `sortOrder`    INT          NOT NULL DEFAULT 0,
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`materialID`),
    KEY `idx_ematerials_event` (`eventID`),
    CONSTRAINT `fk_ematerials_event` FOREIGN KEY (`eventID`) REFERENCES `tblEvents` (`eventID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Downloadable materials/documents attached to events.';


-- -----------------------------------------------------------------------------
-- 🎟️ tblEventRSVPs — event RSVP/registration responses
-- (from migration 028)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblEventRSVPs` (
    `rsvpID`    INT         NOT NULL AUTO_INCREMENT,
    `eventID`   INT         NOT NULL,
    `userID`    INT         NOT NULL,
    `siteID`    INT         NOT NULL DEFAULT 1,
    `response`  ENUM('going','maybe','not_going') NOT NULL DEFAULT 'going',
    `createdAt` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` DATETIME    DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`rsvpID`),
    UNIQUE KEY `uq_event_user` (`eventID`, `userID`),
    KEY `idx_event_response` (`eventID`, `response`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Event RSVP/registration responses';


-- -----------------------------------------------------------------------------
-- 🏷️ tblLeadershipRoles — types of leadership positions
-- (from migration 017)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblLeadershipRoles` (
    `roleID`      INT          NOT NULL AUTO_INCREMENT,
    `siteID`      INT          NOT NULL DEFAULT 1 COMMENT 'FK → tblSites.siteID',
    `roleName`    VARCHAR(150) NOT NULL,
    `roleSlug`    VARCHAR(100) NOT NULL COMMENT 'URL-safe slug',
    `description` VARCHAR(500) DEFAULT NULL,
    `sortOrder`   INT          NOT NULL DEFAULT 0,
    `isActive`    TINYINT(1)   NOT NULL DEFAULT 1,
    `createdAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`roleID`),
    UNIQUE KEY `uq_lr_slug_site` (`roleSlug`, `siteID`),
    KEY `idx_lr_site` (`siteID`),
    CONSTRAINT `fk_lr_site` FOREIGN KEY (`siteID`)
        REFERENCES `tblSites` (`siteID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Leadership role definitions (e.g. Pastor, Elder, Deacon).';


-- -----------------------------------------------------------------------------
-- 👥 tblLeadershipAssignments — person-to-role assignments with term dates
-- (from migration 017)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblLeadershipAssignments` (
    `assignmentID` INT          NOT NULL AUTO_INCREMENT,
    `siteID`       INT          NOT NULL DEFAULT 1 COMMENT 'FK → tblSites.siteID',
    `roleID`       INT          NOT NULL COMMENT 'FK → tblLeadershipRoles',
    `userID`       INT          DEFAULT NULL COMMENT 'FK → tblUsers (NULL if external person)',
    `personName`   VARCHAR(255) DEFAULT NULL COMMENT 'Name if not a portal user',
    `personEmail`  VARCHAR(255) DEFAULT NULL COMMENT 'Email if not a portal user',
    `startDate`    DATE         DEFAULT NULL COMMENT 'Term start date',
    `endDate`      DATE         DEFAULT NULL COMMENT 'Term end date (NULL = current)',
    `notes`        TEXT         DEFAULT NULL,
    `isActive`     TINYINT(1)   NOT NULL DEFAULT 1,
    `createdByID`  INT          DEFAULT NULL COMMENT 'FK → tblUsers',
    `updatedByID`  INT          DEFAULT NULL COMMENT 'FK → tblUsers',
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`assignmentID`),
    KEY `idx_la_site` (`siteID`),
    KEY `idx_la_role` (`roleID`),
    KEY `idx_la_user` (`userID`),
    KEY `idx_la_active` (`isActive`, `endDate`),
    CONSTRAINT `fk_la_site` FOREIGN KEY (`siteID`)
        REFERENCES `tblSites` (`siteID`) ON DELETE CASCADE,
    CONSTRAINT `fk_la_role` FOREIGN KEY (`roleID`)
        REFERENCES `tblLeadershipRoles` (`roleID`) ON DELETE RESTRICT,
    CONSTRAINT `fk_la_user` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_la_creator` FOREIGN KEY (`createdByID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_la_updater` FOREIGN KEY (`updatedByID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Leadership role assignments — who holds what role, with term tracking.';


-- #############################################################################
-- SECTION 4: LOGGING TABLES
-- #############################################################################

-- -----------------------------------------------------------------------------
-- 📝 tblActivityLogs — audit trail for all portal activity
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblActivityLogs` (
    `logID`               INT          NOT NULL AUTO_INCREMENT,
    `siteID`              INT          DEFAULT NULL COMMENT 'FK → tblSites.siteID (nullable for pre-bootstrap logs)',
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
        REFERENCES `tblExpenseClaims` (`claimID`),
    KEY `idx_logs_site` (`siteID`),
    CONSTRAINT `fk_logs_site` FOREIGN KEY (`siteID`)
        REFERENCES `tblSites` (`siteID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Audit trail for all portal activity — login, actions, errors.';


-- -----------------------------------------------------------------------------
-- 🚨 tblErrors — centralised error/warning/notice log (from migration 001)
-- Referenced by core/Logger.php::errorPlatform()
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblErrors` (
    `errorID`        INT           NOT NULL AUTO_INCREMENT COMMENT 'Unique error record identifier',
    `siteID`         INT           DEFAULT NULL COMMENT 'FK → tblSites.siteID (nullable for pre-bootstrap errors)',
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
    KEY `idx_errors_user`      (`userID`),
    KEY `idx_errors_site`     (`siteID`),
    CONSTRAINT `fk_errors_site` FOREIGN KEY (`siteID`)
        REFERENCES `tblSites` (`siteID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Centralised error log for all platforms/libraries. See core/Logger.php.';


-- #############################################################################
-- SECTION 4B: APP TABLES FROM MIGRATIONS 029-036
-- (announcements, documents, audit trail, TOTP backup codes, workflow engine,
--  tasks — ported from individual migration files. tblMigrations marks them
--  executed, so fresh installs MUST have these tables here too or those
--  apps silently break.)
-- #############################################################################

-- -----------------------------------------------------------------------------
-- 📢 tblAnnouncements — site noticeboard posts (from migration 029)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblAnnouncements` (
    `announcementID` INT          NOT NULL AUTO_INCREMENT,
    `siteID`         INT          NOT NULL DEFAULT 1,
    `title`          VARCHAR(255) NOT NULL,
    `slug`           VARCHAR(200) NOT NULL,
    `body`           TEXT         NOT NULL,
    `priority`       ENUM('normal','important','urgent') NOT NULL DEFAULT 'normal',
    `isPinned`       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Pinned to top of list and dashboard',
    `publishAt`      DATETIME     DEFAULT NULL COMMENT 'Scheduled publish time (NULL = immediate)',
    `expiresAt`      DATETIME     DEFAULT NULL COMMENT 'Auto-hide after this date (NULL = never)',
    `isPublished`    TINYINT(1)   NOT NULL DEFAULT 0,
    `isDeleted`      TINYINT(1)   NOT NULL DEFAULT 0,
    `createdByID`    INT          DEFAULT NULL,
    `updatedByID`    INT          DEFAULT NULL,
    `createdAt`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`announcementID`),
    UNIQUE KEY `uq_announcement_slug` (`slug`, `siteID`),
    KEY `idx_announcement_site` (`siteID`),
    KEY `idx_announcement_published` (`siteID`, `isPublished`, `isDeleted`, `publishAt`),
    KEY `idx_announcement_pinned` (`siteID`, `isPinned`, `isPublished`, `isDeleted`),
    CONSTRAINT `fk_announcement_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites` (`siteID`),
    CONSTRAINT `fk_announcement_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_announcement_updater` FOREIGN KEY (`updatedByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Site announcements / noticeboard posts';


-- -----------------------------------------------------------------------------
-- 📁 tblDocCategories — document library categories / folders (migration 030)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblDocCategories` (
    `categoryID`   INT          NOT NULL AUTO_INCREMENT,
    `siteID`       INT          NOT NULL DEFAULT 1,
    `categoryName` VARCHAR(100) NOT NULL,
    `description`  VARCHAR(255) DEFAULT NULL,
    `sortOrder`    INT          NOT NULL DEFAULT 0,
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`categoryID`),
    UNIQUE KEY `uq_doc_cat_name_site` (`categoryName`, `siteID`),
    KEY `idx_doc_cat_site` (`siteID`),
    CONSTRAINT `fk_doccat_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites` (`siteID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Document library categories / folders';


-- -----------------------------------------------------------------------------
-- 📄 tblDocuments — document library files (migration 030)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblDocuments` (
    `documentID`    INT          NOT NULL AUTO_INCREMENT,
    `siteID`        INT          NOT NULL DEFAULT 1,
    `categoryID`    INT          DEFAULT NULL,
    `title`         VARCHAR(255) NOT NULL,
    `description`   TEXT         DEFAULT NULL,
    `fileName`      VARCHAR(255) NOT NULL COMMENT 'Original upload filename',
    `filePath`      VARCHAR(500) NOT NULL COMMENT 'Path relative to _uploads/documents/',
    `fileSize`      INT          NOT NULL DEFAULT 0 COMMENT 'Size in bytes',
    `mimeType`      VARCHAR(100) DEFAULT NULL,
    `isPublished`   TINYINT(1)   NOT NULL DEFAULT 1,
    `isDeleted`     TINYINT(1)   NOT NULL DEFAULT 0,
    `downloadCount` INT          NOT NULL DEFAULT 0,
    `uploadedByID`  INT          DEFAULT NULL,
    `createdAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`documentID`),
    KEY `idx_doc_site` (`siteID`),
    KEY `idx_doc_category` (`categoryID`),
    KEY `idx_doc_published` (`siteID`, `isPublished`, `isDeleted`),
    CONSTRAINT `fk_doc_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites` (`siteID`),
    CONSTRAINT `fk_doc_category` FOREIGN KEY (`categoryID`) REFERENCES `tblDocCategories` (`categoryID`) ON DELETE SET NULL,
    CONSTRAINT `fk_doc_uploader` FOREIGN KEY (`uploadedByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Document library files';


-- -----------------------------------------------------------------------------
-- 📋 tblAuditTrail — before/after change tracking (migration 031)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblAuditTrail` (
    `auditID`    INT          NOT NULL AUTO_INCREMENT,
    `siteID`     INT          DEFAULT NULL,
    `userID`     INT          DEFAULT NULL,
    `tableName`  VARCHAR(100) NOT NULL COMMENT 'Affected database table',
    `recordID`   INT          NOT NULL COMMENT 'Primary key of affected record',
    `action`     ENUM('create','update','delete') NOT NULL,
    `fieldName`  VARCHAR(100) DEFAULT NULL COMMENT 'Specific field changed (NULL = whole record)',
    `oldValue`   TEXT         DEFAULT NULL COMMENT 'Previous value (JSON for complex types)',
    `newValue`   TEXT         DEFAULT NULL COMMENT 'New value (JSON for complex types)',
    `changeSet`  JSON         DEFAULT NULL COMMENT 'Full diff: {field: {old, new}} for multi-field changes',
    `ipAddress`  VARCHAR(45)  DEFAULT NULL,
    `createdAt`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`auditID`),
    KEY `idx_audit_table_record` (`tableName`, `recordID`),
    KEY `idx_audit_user` (`userID`),
    KEY `idx_audit_site` (`siteID`),
    KEY `idx_audit_date` (`createdAt`),
    KEY `idx_audit_action` (`action`, `tableName`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Detailed audit trail with before/after change tracking';


-- -----------------------------------------------------------------------------
-- 🔑 tblTotpBackupCodes — TOTP recovery codes (migration 032)
-- The matching `totpSecret` + `totpEnabled` columns live inline in
-- the tblUsers CREATE TABLE in SECTION 2.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblTotpBackupCodes` (
    `codeID`    INT          NOT NULL AUTO_INCREMENT,
    `userID`    INT          NOT NULL,
    `codeHash`  VARCHAR(255) NOT NULL COMMENT 'Hashed backup code',
    `isUsed`    TINYINT(1)   NOT NULL DEFAULT 0,
    `usedAt`    DATETIME     DEFAULT NULL,
    `createdAt` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`codeID`),
    KEY `idx_backup_user` (`userID`),
    CONSTRAINT `fk_backup_user` FOREIGN KEY (`userID`) REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='TOTP backup/recovery codes';


-- -----------------------------------------------------------------------------
-- 🔄 tblWorkflows — configurable workflow definitions (migration 034)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblWorkflows` (
    `workflowID`   INT          NOT NULL AUTO_INCREMENT,
    `siteID`       INT          NOT NULL DEFAULT 1,
    `workflowName` VARCHAR(100) NOT NULL,
    `workflowKey`  VARCHAR(50)  NOT NULL COMMENT 'Machine-readable key (e.g. expense_approval)',
    `description`  VARCHAR(255) DEFAULT NULL,
    `isActive`     TINYINT(1)   NOT NULL DEFAULT 1,
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`workflowID`),
    UNIQUE KEY `uq_workflow_key_site` (`workflowKey`, `siteID`),
    KEY `idx_workflow_site` (`siteID`),
    CONSTRAINT `fk_workflow_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites` (`siteID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Configurable workflow definitions';


-- -----------------------------------------------------------------------------
-- 🪜 tblWorkflowSteps — ordered stages within a workflow (migration 034)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblWorkflowSteps` (
    `stepID`        INT          NOT NULL AUTO_INCREMENT,
    `workflowID`    INT          NOT NULL,
    `stepOrder`     INT          NOT NULL DEFAULT 1,
    `stepName`      VARCHAR(100) NOT NULL,
    `stepType`      ENUM('approval','review','notification','auto') NOT NULL DEFAULT 'approval',
    `assigneeType`  ENUM('role','user','group') NOT NULL DEFAULT 'role',
    `assigneeValue` VARCHAR(100) DEFAULT NULL COMMENT 'Role name, userID, or groupID',
    `autoAction`    ENUM('approve','reject','escalate') DEFAULT NULL COMMENT 'For auto steps',
    `timeoutHours`  INT          DEFAULT NULL COMMENT 'Auto-escalate after N hours',
    `createdAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`stepID`),
    KEY `idx_wfstep_workflow` (`workflowID`),
    CONSTRAINT `fk_wfstep_workflow` FOREIGN KEY (`workflowID`) REFERENCES `tblWorkflows` (`workflowID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Ordered steps within a workflow';


-- -----------------------------------------------------------------------------
-- 🏃 tblWorkflowInstances — running workflows tied to a source record (034)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblWorkflowInstances` (
    `instanceID`  INT          NOT NULL AUTO_INCREMENT,
    `workflowID`  INT          NOT NULL,
    `siteID`      INT          NOT NULL DEFAULT 1,
    `tableName`   VARCHAR(100) NOT NULL COMMENT 'Source table (e.g. tblExpenseClaims)',
    `recordID`    INT          NOT NULL COMMENT 'PK of the source record',
    `currentStep` INT          NOT NULL DEFAULT 1,
    `status`      ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
    `startedByID` INT          DEFAULT NULL,
    `startedAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completedAt` DATETIME     DEFAULT NULL,
    PRIMARY KEY (`instanceID`),
    KEY `idx_wfi_workflow` (`workflowID`),
    KEY `idx_wfi_record` (`tableName`, `recordID`),
    KEY `idx_wfi_status` (`status`),
    CONSTRAINT `fk_wfi_workflow` FOREIGN KEY (`workflowID`) REFERENCES `tblWorkflows` (`workflowID`),
    CONSTRAINT `fk_wfi_starter` FOREIGN KEY (`startedByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Running workflow instances linked to source records';


-- -----------------------------------------------------------------------------
-- 📜 tblWorkflowActions — action log for step completions (migration 034)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblWorkflowActions` (
    `actionID`   INT          NOT NULL AUTO_INCREMENT,
    `instanceID` INT          NOT NULL,
    `stepID`     INT          NOT NULL,
    `action`     ENUM('approved','rejected','escalated','skipped') NOT NULL,
    `comment`    TEXT         DEFAULT NULL,
    `actedByID`  INT          DEFAULT NULL,
    `actedAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`actionID`),
    KEY `idx_wfa_instance` (`instanceID`),
    CONSTRAINT `fk_wfa_instance` FOREIGN KEY (`instanceID`) REFERENCES `tblWorkflowInstances` (`instanceID`) ON DELETE CASCADE,
    CONSTRAINT `fk_wfa_step` FOREIGN KEY (`stepID`) REFERENCES `tblWorkflowSteps` (`stepID`),
    CONSTRAINT `fk_wfa_actor` FOREIGN KEY (`actedByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Action log for workflow step completions';


-- -----------------------------------------------------------------------------
-- ✅ tblTasks — recurring tasks + reminders (migration 036)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblTasks` (
    `taskID`             INT          NOT NULL AUTO_INCREMENT,
    `siteID`             INT          NOT NULL DEFAULT 1,
    `title`              VARCHAR(255) NOT NULL,
    `description`        TEXT         DEFAULT NULL,
    `assignedToID`       INT          DEFAULT NULL COMMENT 'FK → tblUsers',
    `createdByID`        INT          DEFAULT NULL,
    `priority`           ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    `status`             ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
    `dueDate`            DATE         DEFAULT NULL,
    `completedAt`        DATETIME     DEFAULT NULL,

    -- 🔄 Recurrence fields
    `isRecurring`        TINYINT(1)   NOT NULL DEFAULT 0,
    `recurrenceType`     ENUM('daily','weekly','monthly','yearly') DEFAULT NULL,
    `recurrenceInterval` INT          DEFAULT 1 COMMENT 'Every N days/weeks/months/years',
    `recurrenceEndDate`  DATE         DEFAULT NULL COMMENT 'Stop recurring after this date',
    `parentTaskID`       INT          DEFAULT NULL COMMENT 'FK → tblTasks (parent recurring task)',

    -- 🔔 Reminder fields
    `reminderDate`       DATETIME     DEFAULT NULL COMMENT 'When to send reminder',
    `reminderSent`       TINYINT(1)   NOT NULL DEFAULT 0,

    `isDeleted`          TINYINT(1)   NOT NULL DEFAULT 0,
    `createdAt`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`taskID`),
    KEY `idx_task_site` (`siteID`),
    KEY `idx_task_assignee` (`assignedToID`),
    KEY `idx_task_status` (`siteID`, `status`, `isDeleted`),
    KEY `idx_task_due` (`dueDate`, `status`),
    KEY `idx_task_reminder` (`reminderDate`, `reminderSent`),
    KEY `idx_task_parent` (`parentTaskID`),
    CONSTRAINT `fk_task_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites` (`siteID`),
    CONSTRAINT `fk_task_assignee` FOREIGN KEY (`assignedToID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_task_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_task_parent` FOREIGN KEY (`parentTaskID`) REFERENCES `tblTasks` (`taskID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Recurring tasks and reminders';


-- #############################################################################
-- SECTION 5: SEED DATA — Settings
-- (ON DUPLICATE KEY preserves existing values)
-- #############################################################################

-- 🏠 Default site (siteID=1) — bootstrap before any FK-dependent seed.
--    A dozen tables (tblEventTypes, tblEventCategories, tblUserSites, …)
--    declare `siteID INT NOT NULL DEFAULT 1` with an FK back to
--    `tblSites(siteID)`. Without this row the SECTION 5B / 6 INSERTs
--    further down trip the FK and the installer's step-3 schema run
--    halts. Originally seeded by migration `015_multisite.sql`; dropped
--    when the migrations were consolidated into this file — restored
--    here. Idempotent: re-runs skip via `WHERE NOT EXISTS`.
INSERT INTO `tblSites` (`siteID`, `siteName`, `siteKey`, `copyrightOrg`, `timezone`)
SELECT 1, 'Portal', 'default', 'Organisation', 'UTC'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `tblSites` WHERE `siteID` = 1);

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
VALUES ('portal.version', '0.8.1', 0, '0.8.1')
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

-- ─── Auth — password policy (from migrations 006 + 041) ─────────────────────
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.password.minLength', '12', 0, '12')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.password.maxLength', '128', 0, '128')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.password.requireUppercase', 'true', 0, 'true')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.password.requireLowercase', 'true', 0, 'true')
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

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.hcaptcha.siteKey', '', 1, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.hcaptcha.secretKey', '', 1, '')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.recaptcha.v3.action', 'submit', 0, 'submit')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.recaptcha.v3.threshold', '0.5', 0, '0.5')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('auth.captcha.priority', 'turnstile,recaptcha,hcaptcha', 0, 'turnstile,recaptcha,hcaptcha')
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

-- ─── Multisite settings (from migration 015) ────────────────────────────────
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('multisite.enabled', 'false', 0, 'false')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('multisite.detectionMode', 'session', 0, 'session')
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

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('mail.provider', 'ms365', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('mail.google.serviceAccountKeyFile', '', 1, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('mail.google.delegateUser', '', 0, NULL)
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

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('notifications.digestEnabled', 'true', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('notifications.digestDay', 'monday', 0, NULL)
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
VALUES ('api.expenses.delete.enabled', 'false', 0, 'false')
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
VALUES ('calendar.displayName', 'Calendar', 0, 'Calendar')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('calendar.displayIcon', 'fa-solid fa-calendar-days', 0, 'fa-solid fa-calendar-days')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('calendar.brandColor', '#0d6efd', 0, '#0d6efd')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- Default calendar view (matches migration 042 — issue #136).
-- Valid values: day | week | weekdays | weekend | month | year | list
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('calendar.defaultView', 'month', 0, 'month')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('leadership.enabled', 'false', 0, 'false')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('leadership.displayName', 'Leadership', 0, 'Leadership')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('leadership.displayIcon', 'fa-solid fa-crown', 0, 'fa-solid fa-crown')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('leadership.brandColor', '#d4af37', 0, '#d4af37')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `defaultValue`)
VALUES ('preachingplan.enabled', 'false', 0, 'false')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;


-- #############################################################################
-- SECTION 5B: SEED DATA — Calendar event types, categories
-- (from migration 008)
-- #############################################################################

-- ─── Default event types ───────────────────────────────────────────────────
INSERT INTO `tblEventTypes` (`typeName`, `typeSlug`, `sortOrder`) VALUES
    ('Worship Service', 'worship-service', 1),
    ('Prayer Meeting', 'prayer-meeting', 2),
    ('Bible Study', 'bible-study', 3),
    ('Social Event', 'social-event', 4),
    ('Community Outreach', 'community-outreach', 5),
    ('Conference', 'conference', 6),
    ('Workshop', 'workshop', 7),
    ('Meeting', 'meeting', 8),
    ('Other', 'other', 99)
ON DUPLICATE KEY UPDATE `typeName` = VALUES(`typeName`);

-- ─── Sub-types for Worship Service ─────────────────────────────────────────
INSERT INTO `tblEventTypes` (`parentID`, `typeName`, `typeSlug`, `sortOrder`) VALUES
    ((SELECT t.typeID FROM (SELECT typeID FROM `tblEventTypes` WHERE typeSlug = 'worship-service') t), 'Sabbath School', 'sabbath-school', 1),
    ((SELECT t.typeID FROM (SELECT typeID FROM `tblEventTypes` WHERE typeSlug = 'worship-service') t), 'Divine Service', 'divine-service', 2),
    ((SELECT t.typeID FROM (SELECT typeID FROM `tblEventTypes` WHERE typeSlug = 'worship-service') t), 'Family Worship', 'family-worship', 3),
    ((SELECT t.typeID FROM (SELECT typeID FROM `tblEventTypes` WHERE typeSlug = 'worship-service') t), 'Afternoon Service', 'afternoon-service', 4),
    ((SELECT t.typeID FROM (SELECT typeID FROM `tblEventTypes` WHERE typeSlug = 'worship-service') t), 'Vespers', 'vespers', 5)
ON DUPLICATE KEY UPDATE `typeName` = VALUES(`typeName`);

-- ─── Default event categories ──────────────────────────────────────────────
INSERT INTO `tblEventCategories` (`categoryName`, `categorySlug`, `sortOrder`) VALUES
    ('Church Service', 'church-service', 1),
    ('Community', 'community', 2),
    ('Youth', 'youth', 3),
    ('Children', 'children', 4),
    ('Music', 'music', 5),
    ('Education', 'education', 6),
    ('Administration', 'administration', 7),
    ('Special Event', 'special-event', 8)
ON DUPLICATE KEY UPDATE `categoryName` = VALUES(`categoryName`);


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

-- 🪦 account/linked-accounts route removed — target file was never
--    created; clicking the link 404s. Removed from this schema and
--    DELETEd on existing installs by migration 058. See issue #205.
--    Re-add here AND remove the DELETE in 058 if/when the page is built.

-- 🔐 WebAuthn AJAX endpoint (POSTed from the login form). isProtected=0
--    because it runs during login (pre-auth). See issue #206.
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('login/webauthn', 'auth/login/webauthn.php', 0)
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

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('admin/integrations', 'admin/integrations/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('admin/upgrade', 'admin/upgrade.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- ─── Multi-site (from migration 015) ────────────────────────────────────────
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('admin/sites', 'admin/sites/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('admin/sites/save', 'admin/sites/save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('admin/sites/users', 'admin/sites/users.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('site/switch', 'site/switch.php', 1)
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

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('help/calendar', 'help/calendar.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- ─── Calendar ───────────────────────────────────────────────────────────────
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('calendar', 'calendar/index.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('calendar/event', 'calendar/event.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('calendar/manage', 'calendar/manage/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('calendar/manage/save', 'calendar/manage/save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('calendar/manage/delete', 'calendar/manage/delete.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('calendar/manage/series', 'calendar/manage/series.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('calendar/manage/series-edit', 'calendar/manage/series-edit.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('calendar/manage/types', 'calendar/manage/types.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('calendar/manage/month-themes', 'calendar/manage/month-themes.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('calendar/export', 'calendar/export.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('calendar/rsvp', 'calendar/rsvp.php', 1)
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

-- ─── Leadership ──────────────────────────────────────────────────────────────
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('leadership', 'leadership/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('leadership/assign', 'leadership/assign.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('leadership/save', 'leadership/save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('leadership/delete', 'leadership/delete.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('leadership/history', 'leadership/history.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('leadership/manage', 'leadership/manage/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('leadership/manage/save', 'leadership/manage/save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- ─── CSV Export Endpoints ────────────────────────────────────────────────────
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('expenses/api/export', 'expenses/api/export.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('attendance/export', 'attendance/export.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('leadership/export', 'leadership/export.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('admin/activity/export', 'admin/activity/export.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('admin/users/export', 'admin/users/export.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('admin/users/import', 'admin/users/import.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);


-- #############################################################################
-- SECTION 6B: ROUTES + SEEDS FROM MIGRATIONS 029-036
-- (announcements, documents, audit, 2FA, workflows, tasks — matches the
--  table definitions added in SECTION 4B above)
-- #############################################################################

-- ─── Announcements (migration 029) ──────────────────────────────────────────
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('announcements', 'announcements/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('announcements/view', 'announcements/view.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('announcements/manage', 'announcements/manage.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('announcements/save', 'announcements/save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('announcements/delete', 'announcements/delete.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('announcements.enabled', 'true', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('announcements.displayName', 'Announcements', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('announcements.displayIcon', 'fa-solid fa-bullhorn', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('announcements.brandColor', '#fd7e14', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- ─── Documents (migration 030) ──────────────────────────────────────────────
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('documents', 'documents/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('documents/upload', 'documents/upload.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('documents/download', 'documents/download.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('documents/delete', 'documents/delete.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('documents/categories', 'documents/categories.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('documents.enabled', 'true', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('documents.displayName', 'Documents', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('documents.displayIcon', 'fa-solid fa-folder-open', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('documents.brandColor', '#6f42c1', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('documents.maxFileSize', '10485760', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- ─── Audit trail (migration 031) ────────────────────────────────────────────
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('admin/audit', 'admin/audit/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- ─── TOTP / 2FA (migration 032) ─────────────────────────────────────────────
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('auth/2fa/verify', 'auth/2fa/verify.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('auth/2fa/setup', 'auth/2fa/setup.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('auth/2fa/disable', 'auth/2fa/disable.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('auth.totpEnabled', 'true', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('auth.totpIssuer', 'WebMS Portal', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- ─── Workflow engine (migration 034) ────────────────────────────────────────
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('admin/workflows', 'admin/workflows/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('admin/workflows/save', 'admin/workflows/save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📋 Seed default expense-approval workflow (siteID=1 row required, seeded
--    in SECTION 5 above). Idempotent via the (workflowKey, siteID) unique key.
INSERT INTO `tblWorkflows` (`siteID`, `workflowName`, `workflowKey`, `description`)
VALUES (1, 'Expense Approval', 'expense_approval', 'Default expense claim approval workflow')
ON DUPLICATE KEY UPDATE `workflowName` = VALUES(`workflowName`);

-- ─── Tasks / reminders (migration 036) ──────────────────────────────────────
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('tasks', 'tasks/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('tasks/save', 'tasks/save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`)
VALUES ('tasks/complete', 'tasks/complete.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('tasks.enabled', 'true', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('tasks.displayName', 'Tasks', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('tasks.displayIcon', 'fa-solid fa-list-check', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`)
VALUES ('tasks.brandColor', '#20c997', 0, NULL)
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;


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

INSERT INTO `tblMigrations` (`filename`) VALUES ('014_admin_integrations_route.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('015_multisite.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('016_google_mail.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('017_leadership.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('018_multisite_fixes.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('019_slug_uniqueness_multisite.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('020_composite_indexes.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('021_display_format_settings.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('022_expense_withdrawal.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('023_series_bulk_edit_route.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('024_csv_export_routes.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('025_install_upgrade_route.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('026_notification_preferences.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('027_user_import_route.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('028_event_rsvp.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('029_announcements.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('030_document_library.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('031_audit_trail.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('032_totp_2fa.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('033_reports.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('034_workflow_engine.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('035_api_expansion.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('036_tasks_reminders.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('037_site_favicon.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('038_branding_powered_by.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('039_prayer_requests.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('040_captcha_providers.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('041_password_policy_hardening.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('042_calendar_default_view.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('043_calendar_categories_and_month_themes.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('044_robots_and_ai_indexing_settings.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('045_ratelimit_username_setting.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('046_audit_retention_settings.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('047_2fa_trusted_devices.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('048_privacy_gdpr.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('049_rest_api_expansion.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('050_notification_prefs_ui.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('051_email_templates.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('052_bulk_importers.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('053_local_accounts_columns.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('054_events_deleted_at.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('055_admin_upgrade_route_fix.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('056_remove_redundant_api_routes.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('057_remove_dead_linked_accounts_route.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('058_login_webauthn_route.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('059_fix_api_expenses_delete_isSensitive.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('060_portal_versioning_and_maintenance.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('061_security_headers.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('062_help_support_route.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('063_rollout_pilot_mode.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('064_backup_freshness_check.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('065_admin_backup_ui.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/maintenance/backup', 'admin/maintenance/backup.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('066_system_health.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/maintenance/health', 'admin/maintenance/health.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('067_alerts_and_email_admin.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('068_first_run_admin.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('069_tours_and_demo_data.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('070_sabbath_and_timezone.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('071_polish_followups.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('072_email_templates.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/integrations/email-templates', 'admin/integrations/email-templates.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('073_app_registry.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('074_rota.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblMigrations` (`filename`) VALUES ('075_praise_reports.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('praise',     'praise/index.php', 1),
    ('praise/new', 'praise/new.php',   1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'praise.enabled',     '0',              '0',              0),
    (NULL, 'praise.displayName', 'Praise Reports', 'Praise Reports', 0),
    (NULL, 'praise.displayIcon', 'fa-solid fa-hands-clapping', 'fa-solid fa-hands-clapping', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('076_milestones.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

CREATE TABLE IF NOT EXISTS `tblUserMilestone` (
    `milestoneID`  INT          NOT NULL AUTO_INCREMENT,
    `userID`       INT          NOT NULL,
    `kind`         ENUM('birthday','anniversary','baptism','joining','wedding','other') NOT NULL DEFAULT 'other',
    `label`        VARCHAR(100) DEFAULT NULL,
    `monthDay`     CHAR(5)      NOT NULL,
    `originYear`   INT          DEFAULT NULL,
    `privacy`      ENUM('private','team','members','public') NOT NULL DEFAULT 'team',
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`milestoneID`),
    KEY `idx_milestone_user` (`userID`),
    KEY `idx_milestone_md`   (`monthDay`),
    CONSTRAINT `fk_milestone_user` FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('milestones',      'milestones/index.php',  1),
    ('milestones/me',   'milestones/me.php',     1),
    ('milestones/save', 'milestones/save.php',   1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'milestones.enabled',           '0', '0', 0),
    (NULL, 'milestones.digest_recipients', '',  '',  0),
    (NULL, 'milestones.displayName',       'Milestones', 'Milestones', 0),
    (NULL, 'milestones.displayIcon',       'fa-solid fa-cake-candles', 'fa-solid fa-cake-candles', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('077_care.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

CREATE TABLE IF NOT EXISTS `tblCareCase` (
    `caseID`        INT          NOT NULL AUTO_INCREMENT,
    `siteID`        INT          NOT NULL DEFAULT 1,
    `personUserID`  INT          DEFAULT NULL,
    `personName`    VARCHAR(255) DEFAULT NULL,
    `category`      ENUM('illness','hospital','bereavement','family','transition','other') NOT NULL DEFAULT 'other',
    `summary`       VARCHAR(500) NOT NULL,
    `status`        ENUM('active','resolved','long-term') NOT NULL DEFAULT 'active',
    `openedByID`    INT          DEFAULT NULL,
    `openedAt`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `closedAt`      DATETIME     DEFAULT NULL,
    PRIMARY KEY (`caseID`),
    KEY `idx_care_case_site_status` (`siteID`, `status`),
    KEY `idx_care_case_person` (`personUserID`),
    CONSTRAINT `fk_care_case_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites` (`siteID`),
    CONSTRAINT `fk_care_case_person` FOREIGN KEY (`personUserID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_care_case_opener` FOREIGN KEY (`openedByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblCareVisit` (
    `visitID`       INT      NOT NULL AUTO_INCREMENT,
    `caseID`        INT      NOT NULL,
    `visitedByID`   INT      NOT NULL,
    `visitedAt`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `kind`          ENUM('visit','call','message','prayer','other') NOT NULL DEFAULT 'visit',
    `notes`         TEXT     DEFAULT NULL,
    `followUpAt`    DATE     DEFAULT NULL,
    `followUpAssignedToID` INT DEFAULT NULL,
    `createdAt`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`visitID`),
    KEY `idx_care_visit_case` (`caseID`),
    KEY `idx_care_visit_visitor` (`visitedByID`),
    KEY `idx_care_visit_followup` (`followUpAt`, `followUpAssignedToID`),
    CONSTRAINT `fk_care_visit_case` FOREIGN KEY (`caseID`) REFERENCES `tblCareCase` (`caseID`) ON DELETE CASCADE,
    CONSTRAINT `fk_care_visit_visitor` FOREIGN KEY (`visitedByID`) REFERENCES `tblUsers` (`userID`) ON DELETE RESTRICT,
    CONSTRAINT `fk_care_visit_assignee` FOREIGN KEY (`followUpAssignedToID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblCareAccessLog` (
    `accessID`   INT      NOT NULL AUTO_INCREMENT,
    `caseID`     INT      NOT NULL,
    `viewerID`   INT      NOT NULL,
    `viewedAt`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`accessID`),
    KEY `idx_care_access_case` (`caseID`),
    KEY `idx_care_access_viewer` (`viewerID`),
    CONSTRAINT `fk_care_access_case` FOREIGN KEY (`caseID`) REFERENCES `tblCareCase` (`caseID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('care',           'care/index.php',     1),
    ('care/case',      'care/case.php',      1),
    ('care/case-save', 'care/case-save.php', 1),
    ('care/visit-save','care/visit-save.php',1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'care.enabled',           '0', '0', 0),
    (NULL, 'care.redact_after_days', '90','90', 0),
    (NULL, 'care.displayName',       'Care Register', 'Care Register', 0),
    (NULL, 'care.displayIcon',       'fa-solid fa-hand-holding-heart', 'fa-solid fa-hand-holding-heart', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('078_visitors.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

CREATE TABLE IF NOT EXISTS `tblVisitor` (
    `visitorID`        INT          NOT NULL AUTO_INCREMENT,
    `siteID`           INT          NOT NULL DEFAULT 1,
    `fullName`         VARCHAR(255) NOT NULL,
    `email`            VARCHAR(255) DEFAULT NULL,
    `phone`            VARCHAR(50)  DEFAULT NULL,
    `firstVisitedAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `source`           ENUM('in-person','public-form','referral','website','other') NOT NULL DEFAULT 'in-person',
    `assignedToID`     INT          DEFAULT NULL,
    `status`           ENUM('new','in-touch','converted','lost') NOT NULL DEFAULT 'new',
    `notes`            TEXT         DEFAULT NULL,
    `convertedUserID`  INT          DEFAULT NULL,
    `createdByID`      INT          DEFAULT NULL,
    `createdAt`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`visitorID`),
    KEY `idx_visitor_site_status` (`siteID`, `status`),
    KEY `idx_visitor_assignee` (`assignedToID`),
    CONSTRAINT `fk_visitor_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites` (`siteID`),
    CONSTRAINT `fk_visitor_assignee` FOREIGN KEY (`assignedToID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_visitor_converted` FOREIGN KEY (`convertedUserID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_visitor_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblVisitorContact` (
    `contactID`     INT      NOT NULL AUTO_INCREMENT,
    `visitorID`     INT      NOT NULL,
    `contactedByID` INT      NOT NULL,
    `contactedAt`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `method`        ENUM('visit','call','email','text','other') NOT NULL DEFAULT 'call',
    `summary`       TEXT     DEFAULT NULL,
    `nextContactAt` DATE     DEFAULT NULL,
    PRIMARY KEY (`contactID`),
    KEY `idx_visitor_contact_visitor` (`visitorID`),
    KEY `idx_visitor_contact_next` (`nextContactAt`),
    CONSTRAINT `fk_visitor_contact_visitor` FOREIGN KEY (`visitorID`) REFERENCES `tblVisitor` (`visitorID`) ON DELETE CASCADE,
    CONSTRAINT `fk_visitor_contact_by` FOREIGN KEY (`contactedByID`) REFERENCES `tblUsers` (`userID`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('visitors',              'visitors/index.php',         1),
    ('visitors/new',          'visitors/new.php',           1),
    ('visitors/save',         'visitors/save.php',          1),
    ('visitors/profile',      'visitors/profile.php',       1),
    ('visitors/contact-save', 'visitors/contact-save.php',  1),
    ('visitors/my-follow-ups','visitors/my-follow-ups.php', 1),
    ('visit',                 'visitors/public-form.php',   0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'visitors.enabled',                   '0', '0', 0),
    (NULL, 'visitors.coordinator_role',          'visitor_coordinator', 'visitor_coordinator', 0),
    (NULL, 'visitors.followup_initial_days',     '7',  '7',  0),
    (NULL, 'visitors.followup_followup_days',    '30', '30', 0),
    (NULL, 'visitors.followup_final_days',       '90', '90', 0),
    (NULL, 'visitors.public_capture_enabled',    '0',  '0',  0),
    (NULL, 'visitors.displayName',               'Visitor Tracking', 'Visitor Tracking', 0),
    (NULL, 'visitors.displayIcon',               'fa-solid fa-user-plus', 'fa-solid fa-user-plus', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('079_directory.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('directory',             'directory/index.php',   1),
    ('directory/profile',     'directory/profile.php', 1),
    ('directory/my-settings', 'directory/me.php',      1),
    ('directory/save',        'directory/save.php',    1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'directory.enabled',     '0', '0', 0),
    (NULL, 'directory.displayName', 'Member Directory', 'Member Directory', 0),
    (NULL, 'directory.displayIcon', 'fa-solid fa-address-book', 'fa-solid fa-address-book', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('080_ical_feed.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

ALTER TABLE `tblUsers`
    ADD COLUMN IF NOT EXISTS `calendarToken` VARCHAR(64) DEFAULT NULL;

ALTER TABLE `tblUsers`
    ADD INDEX IF NOT EXISTS `idx_user_calendar_token` (`calendarToken`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('calendar.ics',          'calendar/feed.php',         0),
    ('account/calendar-feed', 'calendar/account-feed.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('081_sabbath_admin.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/settings/sabbath', 'admin/settings/sabbath/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

CREATE TABLE IF NOT EXISTS `tblRotaRoleType` (
    `roleTypeID`  INT          NOT NULL AUTO_INCREMENT,
    `siteID`      INT          NOT NULL DEFAULT 1,
    `name`        VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `colorHex`    VARCHAR(7)   NOT NULL DEFAULT '#5e6ad2',
    `isActive`    TINYINT(1)   NOT NULL DEFAULT 1,
    `createdAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`roleTypeID`),
    UNIQUE KEY `uq_rota_role_name_site` (`name`, `siteID`),
    KEY `idx_rota_role_site` (`siteID`),
    CONSTRAINT `fk_rota_role_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites` (`siteID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblRotaSlot` (
    `slotID`        INT      NOT NULL AUTO_INCREMENT,
    `siteID`        INT      NOT NULL DEFAULT 1,
    `roleTypeID`    INT      NOT NULL,
    `slotDate`      DATE     NOT NULL,
    `startTime`     TIME     DEFAULT NULL,
    `endTime`       TIME     DEFAULT NULL,
    `assignedToID`  INT      DEFAULT NULL,
    `notes`         VARCHAR(500) DEFAULT NULL,
    `reminderSentAt` DATETIME DEFAULT NULL,
    `createdByID`   INT      DEFAULT NULL,
    `createdAt`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`slotID`),
    KEY `idx_rota_slot_site_date` (`siteID`, `slotDate`),
    KEY `idx_rota_slot_role` (`roleTypeID`),
    KEY `idx_rota_slot_assignee` (`assignedToID`),
    CONSTRAINT `fk_rota_slot_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites` (`siteID`),
    CONSTRAINT `fk_rota_slot_role` FOREIGN KEY (`roleTypeID`) REFERENCES `tblRotaRoleType` (`roleTypeID`) ON DELETE CASCADE,
    CONSTRAINT `fk_rota_slot_assignee` FOREIGN KEY (`assignedToID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_rota_slot_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblRotaSwapRequest` (
    `swapID`           INT      NOT NULL AUTO_INCREMENT,
    `slotID`           INT      NOT NULL,
    `requestedByID`    INT      NOT NULL,
    `targetUserID`     INT      DEFAULT NULL,
    `status`           ENUM('pending','accepted','declined','cancelled') NOT NULL DEFAULT 'pending',
    `requestMessage`   VARCHAR(500) DEFAULT NULL,
    `responseMessage`  VARCHAR(500) DEFAULT NULL,
    `respondedAt`      DATETIME DEFAULT NULL,
    `createdAt`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`swapID`),
    KEY `idx_rota_swap_slot` (`slotID`),
    KEY `idx_rota_swap_requester` (`requestedByID`),
    KEY `idx_rota_swap_target` (`targetUserID`),
    CONSTRAINT `fk_rota_swap_slot` FOREIGN KEY (`slotID`) REFERENCES `tblRotaSlot` (`slotID`) ON DELETE CASCADE,
    CONSTRAINT `fk_rota_swap_requester` FOREIGN KEY (`requestedByID`) REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE,
    CONSTRAINT `fk_rota_swap_target` FOREIGN KEY (`targetUserID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('rota',             'rota/index.php',        1),
    ('rota/manage',      'rota/manage.php',       1),
    ('rota/role-types',  'rota/role-types.php',   1),
    ('rota/slot-save',   'rota/slot-save.php',    1),
    ('rota/swap',        'rota/swap.php',         1),
    ('rota/swap-respond','rota/swap-respond.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'rota.enabled',              '0', '0', 0),
    (NULL, 'rota.reminder_days_before', '3', '3', 0),
    (NULL, 'rota.allow_open_swap',      '1', '1', 0),
    (NULL, 'rota.displayName',          'Duty Roster', 'Duty Roster', 0),
    (NULL, 'rota.displayIcon',          'fa-solid fa-calendar-week', 'fa-solid fa-calendar-week', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/apps', 'admin/apps/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'portal.industry', '', '', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'portal.i18n.minimum_coverage_for_switcher', '0', '0', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'portal.sabbath.enabled',              '0',              '0',              0),
    (NULL, 'portal.sabbath.method',               'fixed',          'fixed',          0),
    (NULL, 'portal.sabbath.timezone',             'Europe/London',  'Europe/London',  0),
    (NULL, 'portal.sabbath.location_lat',         '52.205',         '52.205',         0),
    (NULL, 'portal.sabbath.location_lng',         '0.119',          '0.119',          0),
    (NULL, 'portal.sabbath.start_offset_minutes', '0',              '0',              0),
    (NULL, 'portal.sabbath.end_offset_minutes',   '0',              '0',              0),
    (NULL, 'portal.sabbath.bypass_critical',      '1',              '1',              0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

CREATE TABLE IF NOT EXISTS `tblTours` (
    `tourID`     INT          NOT NULL AUTO_INCREMENT,
    `tourKey`    VARCHAR(64)  NOT NULL,
    `version`    VARCHAR(20)  NOT NULL,
    `title`      VARCHAR(255) NOT NULL,
    `steps`      TEXT         NOT NULL,
    `isActive`   TINYINT(1)   NOT NULL DEFAULT 1,
    `forRoles`   VARCHAR(255) NOT NULL DEFAULT '',
    `createdAt`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`tourID`),
    UNIQUE KEY `uq_tour_key_version` (`tourKey`, `version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblUserTours` (
    `userTourID`   INT      NOT NULL AUTO_INCREMENT,
    `userID`       INT      NOT NULL,
    `tourID`       INT      NOT NULL,
    `completedAt`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`userTourID`),
    UNIQUE KEY `uq_user_tour` (`userID`, `tourID`),
    KEY `idx_user_tours_user` (`userID`),
    CONSTRAINT `fk_user_tour_user` FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE,
    CONSTRAINT `fk_user_tour_tour` FOREIGN KEY (`tourID`) REFERENCES `tblTours`(`tourID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/maintenance/demo-data', 'admin/maintenance/demo-data.php', 1),
    ('admin/tours', 'admin/tours/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'portal.demo_mode.enabled',   '0', '0', 0),
    (NULL, 'portal.tours.welcome_active','1', '1', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- 🎯 Tour playback API routes (matches migration 082 / #253)
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('api/tours/active',   'api/tours/active.php',   1),
    ('api/tours/complete', 'api/tours/complete.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 🎛️ Settings group sub-pages (matches migration 083 / #252)
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/settings/alerts',      'admin/settings/group.php', 1),
    ('admin/settings/backups',     'admin/settings/group.php', 1),
    ('admin/settings/headers',     'admin/settings/group.php', 1),
    ('admin/settings/upgrade',     'admin/settings/group.php', 1),
    ('admin/settings/maintenance', 'admin/settings/group.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('help/admin-first-steps', 'help/admin-first-steps.php', 1),
    ('admin/settings/dismiss-first-run', 'admin/settings/dismiss-first-run.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'portal.first_run.dismissed',                '0', '0', 0),
    (NULL, 'portal.first_run.steps.site_branding',      '0', '0', 0),
    (NULL, 'portal.first_run.steps.email_delivery',     '0', '0', 0),
    (NULL, 'portal.first_run.steps.test_backup',        '0', '0', 0),
    (NULL, 'portal.first_run.steps.retention_cron',     '0', '0', 0),
    (NULL, 'portal.first_run.steps.invite_users',       '0', '0', 0),
    (NULL, 'portal.first_run.steps.first_announcement', '0', '0', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/integrations/email', 'admin/integrations/email.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'portal.alerts.recipients',       '',                '',                0),
    (NULL, 'portal.alerts.severities',       'Critical,Fatal',  'Critical,Fatal',  0),
    (NULL, 'portal.alerts.cooldown_minutes', '30',              '30',              0),
    (NULL, 'email.provider',                 'smtp',            'smtp',            0),
    (NULL, 'email.from',                     '',                '',                0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/maintenance/backup-check', 'admin/maintenance/backup-check.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'portal.backups.max_age_hours',    '36', '36', 0),
    (NULL, 'portal.backups.alert_recipients', '',   '',   0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'portal.rollout.pilot_mode', '1', '1', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('help/support', 'help/support.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'portal.support.email', 'portal-support@millrdsdacambridge.uk', 'portal-support@millrdsdacambridge.uk', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- 🛡️ Baseline security response headers (matches migration 061 / issue #160).
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'portal.headers.strict_transport_security', 'max-age=31536000; includeSubDomains', 'max-age=31536000; includeSubDomains', 0),
    (NULL, 'portal.headers.permissions_policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), magnetometer=(), accelerometer=(), gyroscope=(), browsing-topics=(), interest-cohort=()', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), magnetometer=(), accelerometer=(), gyroscope=(), browsing-topics=(), interest-cohort=()', 0),
    (NULL, 'portal.headers.coop', 'same-origin', 'same-origin', 0),
    (NULL, 'portal.headers.corp', 'same-origin', 'same-origin', 0),
    (NULL, 'portal.headers.referrer_policy', 'strict-origin-when-cross-origin', 'strict-origin-when-cross-origin', 0),
    (NULL, 'portal.headers.x_frame_options', 'SAMEORIGIN', 'SAMEORIGIN', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- 🔢 Portal version tracking + maintenance gate settings (matches migration 060).
--    Seeded as empty here; the installer writes the actual `portal.installed_version`
--    on step 5 finalisation, and /admin/upgrade updates it on successful migrate.
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'portal.installed_version',     '',  '',  0),
    (NULL, 'portal.maintenance.active',    '0', '0', 0),
    (NULL, 'portal.maintenance.message',   '',  '',  0),
    (NULL, 'portal.upgrade.backup.enabled',           '1',  '1',  0),
    (NULL, 'portal.upgrade.backup.keep_last_n',       '10', '10', 0),
    (NULL, 'portal.upgrade.fresh_required_below',     '',   '',   0),
    (NULL, 'portal.upgrade.require_hostname_confirm', '1',  '1',  0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('calendar/manage/import',    'calendar/manage/import.php',    1),
    ('leadership/manage/import',  'leadership/manage/import.php',  1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📨 Email template store (matches migration 051)
CREATE TABLE IF NOT EXISTS `tblEmailTemplates` (
    `templateID`      INT          NOT NULL AUTO_INCREMENT,
    `siteID`          INT          DEFAULT NULL,
    `templateKey`     VARCHAR(100) NOT NULL,
    `subject`         VARCHAR(255) NOT NULL,
    `bodyHtml`        MEDIUMTEXT   NOT NULL,
    `description`     VARCHAR(500) DEFAULT NULL,
    `availableTokens` TEXT         DEFAULT NULL,
    `isActive`        TINYINT(1)   NOT NULL DEFAULT 1,
    `createdAt`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`templateID`),
    UNIQUE KEY `uq_template_site_key` (`siteID`, `templateKey`),
    KEY `idx_template_key` (`templateKey`),
    CONSTRAINT `fk_template_site` FOREIGN KEY (`siteID`)
        REFERENCES `tblSites` (`siteID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Editable email templates with Mustache-style {{token}} substitution.';

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/email-templates',         'admin/email-templates/index.php',  1),
    ('admin/email-templates/edit',    'admin/email-templates/edit.php',   1),
    ('admin/email-templates/save',    'admin/email-templates/save.php',   1),
    ('admin/email-templates/preview', 'admin/email-templates/preview.php',1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 📬 Notification prefs UI gating (matches migration 050)
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'notifications.deliveryReady', 'false', 'false', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('account/notifications',      'auth/account/notifications.php',      1),
    ('account/notifications/save', 'auth/account/notifications-save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 🛰️ REST API enable flags (matches migration 049)
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'api.announcements.list.enabled',   'true', 'true', 0),
    (NULL, 'api.attendance.list.enabled',      'true', 'true', 0),
    (NULL, 'api.events.list.enabled',          'true', 'true', 0),
    (NULL, 'api.events.detail.enabled',        'true', 'true', 0),
    (NULL, 'api.users.list.enabled',           'true', 'true', 0),
    (NULL, 'api.events.create.enabled',        'true', 'true', 0),
    (NULL, 'api.events.update.enabled',        'true', 'true', 0),
    (NULL, 'api.events.delete.enabled',        'true', 'true', 0),
    (NULL, 'api.leadership.list.enabled',      'true', 'true', 0),
    (NULL, 'api.tasks.list.enabled',           'true', 'true', 0),
    (NULL, 'api.prayer-requests.list.enabled', 'true', 'true', 0),
    (NULL, 'api.documents.list.enabled',       'true', 'true', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('api-docs',     'api-docs/index.php', 0),
    ('openapi.json', 'openapi.json',       0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 🇪🇺 Privacy / GDPR (matches migration 048 — closes #47)
CREATE TABLE IF NOT EXISTS `tblConsentLog` (
    `consentID`   INT          NOT NULL AUTO_INCREMENT,
    `siteID`      INT          NOT NULL,
    `userID`      INT          DEFAULT NULL,
    `sessionID`   VARCHAR(255) DEFAULT NULL,
    `consentType` ENUM('cookies','privacy_policy','marketing','analytics') NOT NULL,
    `decision`    ENUM('accept','reject','withdraw') NOT NULL,
    `policyHash`  CHAR(64)     DEFAULT NULL,
    `ipAddress`   VARCHAR(45)  DEFAULT NULL,
    `userAgent`   VARCHAR(255) DEFAULT NULL,
    `createdAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`consentID`),
    KEY `idx_consent_user`    (`userID`),
    KEY `idx_consent_session` (`sessionID`),
    KEY `idx_consent_type`    (`siteID`, `consentType`),
    CONSTRAINT `fk_consent_site` FOREIGN KEY (`siteID`)
        REFERENCES `tblSites` (`siteID`) ON DELETE CASCADE,
    CONSTRAINT `fk_consent_user` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Audit trail of cookie / privacy policy consent decisions.';

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'privacy.controllerName',     '',  '',  0),
    (NULL, 'privacy.contactEmail',       '',  '',  0),
    (NULL, 'privacy.policyURL',          '',  '',  0),
    (NULL, 'privacy.dataRetentionDays',  '730', '730', 0),
    (NULL, 'privacy.cookieBannerEnabled','true','true',0),
    (NULL, 'privacy.allowAccountDelete', 'true','true',0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('privacy',                   'privacy/index.php',              0),
    ('privacy/consent',           'privacy/consent.php',            0),
    ('account/data-export',       'auth/account/data-export.php',   1),
    ('account/delete',            'auth/account/delete.php',        1),
    ('account/delete/confirm',    'auth/account/delete-confirm.php',1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 🔐 2FA trusted-device cookie (matches migration 047)
CREATE TABLE IF NOT EXISTS `tblTrustedDevices` (
    `deviceID`    INT          NOT NULL AUTO_INCREMENT,
    `userID`      INT          NOT NULL,
    `tokenHash`   CHAR(64)     NOT NULL COMMENT 'SHA-256 of the cookie token',
    `label`       VARCHAR(255) DEFAULT NULL COMMENT 'User-agent snippet for the user-facing list',
    `createdIP`   VARCHAR(45)  DEFAULT NULL,
    `lastSeenAt`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `expiresAt`   DATETIME     NOT NULL,
    `revokedAt`   DATETIME     DEFAULT NULL,
    `createdAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`deviceID`),
    UNIQUE KEY `uq_td_token_hash` (`tokenHash`),
    KEY `idx_td_user_active` (`userID`, `revokedAt`, `expiresAt`),
    CONSTRAINT `fk_td_user` FOREIGN KEY (`userID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Trusted devices that bypass the 2FA challenge for a configured window.';

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'auth.twoFactor.trustedDeviceDays', '30', '30', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- Audit / error retention + cron token (matches migration 046)
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'audit.retentionDays',   '365', '365', 0),
    (NULL, 'errors.retentionDays',  '365', '365', 0),
    (NULL, 'maintenance.cronToken', '',    '',    1)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/maintenance/retention', 'admin/maintenance/retention.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- Rate-limit by username threshold (matches migration 045 — issue #52)
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'auth.rateLimit.maxAttemptsByUsername', '10', '10', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- Admin Release Notes viewer route
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/release-notes', 'admin/release-notes/index.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- Robots / AI-indexing opt-in (matches migration 044)
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'site.allowIndexing',   'false', 'false', 0),
    (NULL, 'site.allowAiIndexing', 'false', 'false', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- Seed the branding.hidePoweredBy setting (matches migration 038)
INSERT INTO `tblSettings`
    (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`)
VALUES
    (NULL, 'branding.hidePoweredBy', 'false', 'false', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- =============================================================================
-- 🙏 tblPrayerRequests — prayer-request submissions (matches migration 039)
-- =============================================================================
CREATE TABLE IF NOT EXISTS `tblPrayerRequests` (
    `requestID`      INT          NOT NULL AUTO_INCREMENT,
    `siteID`         INT          NOT NULL
                     COMMENT 'FK → tblSites — the site the request was submitted to',
    `submitterID`    INT          DEFAULT NULL
                     COMMENT 'FK → tblUsers — NULL for anonymous public submissions',
    `submitterName`  VARCHAR(100) DEFAULT NULL
                     COMMENT 'Optional display name supplied by an anonymous submitter',
    `submitterEmail` VARCHAR(255) DEFAULT NULL
                     COMMENT 'Optional contact email supplied by an anonymous submitter (for follow-up)',
    `submitterIP`    VARCHAR(45)  DEFAULT NULL
                     COMMENT 'IPv4 or IPv6 of the submitter — for spam analysis only',
    `subject`        VARCHAR(255) NOT NULL
                     COMMENT 'Short title for the request',
    `body`           TEXT         NOT NULL
                     COMMENT 'Full text of the prayer request',
    `visibility`     ENUM('leadership','congregation') NOT NULL DEFAULT 'leadership'
                     COMMENT 'Who can see the request once published',
    `status`         ENUM('pending','active','answered','archived') NOT NULL DEFAULT 'pending'
                     COMMENT 'Lifecycle: pending → active → answered → archived',
    `isAnonymous`    TINYINT(1)   NOT NULL DEFAULT 0
                     COMMENT '1 = display "Anonymous" as the submitter in any visible view',
    `testimony`      TEXT         DEFAULT NULL
                     COMMENT 'Optional praise / testimony note attached when status moves to answered',
    `answeredAt`     DATETIME     DEFAULT NULL
                     COMMENT 'When the request was marked answered',
    `moderatorID`    INT          DEFAULT NULL
                     COMMENT 'FK → tblUsers — last admin who moderated this request',
    `moderatedAt`    DATETIME     DEFAULT NULL,
    `createdAt`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`requestID`),
    KEY `idx_site_status` (`siteID`, `status`),
    KEY `idx_submitter`   (`submitterID`),
    CONSTRAINT `fk_pr_site` FOREIGN KEY (`siteID`)
        REFERENCES `tblSites` (`siteID`) ON DELETE RESTRICT,
    CONSTRAINT `fk_pr_submitter` FOREIGN KEY (`submitterID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_pr_moderator` FOREIGN KEY (`moderatorID`)
        REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Prayer requests submitted by members or anonymously via the public route.';

-- Seed default prayer-request settings (matches migration 039)
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'prayerRequests.enabled',              'true',  'true',  0),
    (NULL, 'prayerRequests.allowAnonymous',       'true',  'true',  0),
    (NULL, 'prayerRequests.allowCongregationFeed','true',  'true',  0),
    (NULL, 'prayerRequests.requireModeration',    'true',  'true',  0),
    (NULL, 'prayerRequests.allowTestimony',       'true',  'true',  0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- Seed prayer-request routes (matches migration 039)
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('prayer-requests',                'prayer-requests/index.php',           1),
    ('prayer-requests/submit',         'prayer-requests/submit.php',          1),
    ('prayer-requests/save',           'prayer-requests/save.php',            1),
    ('prayer-requests/view',           'prayer-requests/view.php',            1),
    ('prayer-requests/manage',         'prayer-requests/manage.php',          1),
    ('prayer-requests/moderate',       'prayer-requests/moderate.php',        1),
    ('prayer-requests/anonymous',      'prayer-requests/anonymous.php',       0),
    ('prayer-requests/anonymous/save', 'prayer-requests/anonymous-save.php',  0),
    ('help/prayer-requests',           'help/prayer-requests.php',            0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- Seed admin captcha routes (matches migration 040)
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/captcha',      'admin/captcha/index.php', 1),
    ('admin/captcha/save', 'admin/captcha/save.php',  1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('084_reading_plans.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

CREATE TABLE IF NOT EXISTS `tblReadingPlan` (
    `planID`       INT          NOT NULL AUTO_INCREMENT,
    `siteID`       INT          NOT NULL DEFAULT 1,
    `slug`         VARCHAR(100) NOT NULL,
    `name`         VARCHAR(255) NOT NULL,
    `description`  TEXT         DEFAULT NULL,
    `kind`         ENUM('bible','book','curriculum','custom') NOT NULL DEFAULT 'bible',
    `totalDays`    INT          NOT NULL DEFAULT 365,
    `isPublic`     TINYINT(1)   NOT NULL DEFAULT 1,
    `createdByID`  INT          DEFAULT NULL,
    `createdAt`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`planID`),
    UNIQUE KEY `uq_rp_slug_site` (`slug`, `siteID`),
    KEY `idx_rp_site_kind` (`siteID`, `kind`),
    CONSTRAINT `fk_rp_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites` (`siteID`),
    CONSTRAINT `fk_rp_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblReadingPlanDay` (
    `dayID`     INT          NOT NULL AUTO_INCREMENT,
    `planID`    INT          NOT NULL,
    `dayNumber` INT          NOT NULL,
    `label`     VARCHAR(255) NOT NULL,
    `content`   TEXT         DEFAULT NULL,
    PRIMARY KEY (`dayID`),
    UNIQUE KEY `uq_rpd_plan_day` (`planID`, `dayNumber`),
    CONSTRAINT `fk_rpd_plan` FOREIGN KEY (`planID`) REFERENCES `tblReadingPlan` (`planID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblReadingPlanEnrollment` (
    `enrollmentID` INT      NOT NULL AUTO_INCREMENT,
    `planID`       INT      NOT NULL,
    `userID`       INT      NOT NULL,
    `startedAt`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completedAt`  DATETIME DEFAULT NULL,
    `currentDay`   INT      NOT NULL DEFAULT 1,
    PRIMARY KEY (`enrollmentID`),
    UNIQUE KEY `uq_rpe_plan_user` (`planID`, `userID`),
    KEY `idx_rpe_user` (`userID`),
    CONSTRAINT `fk_rpe_plan` FOREIGN KEY (`planID`) REFERENCES `tblReadingPlan` (`planID`) ON DELETE CASCADE,
    CONSTRAINT `fk_rpe_user` FOREIGN KEY (`userID`) REFERENCES `tblUsers` (`userID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblReadingPlanProgress` (
    `progressID`   INT      NOT NULL AUTO_INCREMENT,
    `enrollmentID` INT      NOT NULL,
    `dayNumber`    INT      NOT NULL,
    `completedAt`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`progressID`),
    UNIQUE KEY `uq_rpp_enrollment_day` (`enrollmentID`, `dayNumber`),
    CONSTRAINT `fk_rpp_enrollment` FOREIGN KEY (`enrollmentID`) REFERENCES `tblReadingPlanEnrollment` (`enrollmentID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblReadingPlan` (`siteID`, `slug`, `name`, `description`, `kind`, `totalDays`, `isPublic`) VALUES
    (1, 'bible-in-a-year',     'Bible in a Year',       'Read the whole Bible over 365 days, roughly 3-4 chapters per day.', 'bible', 365, 1),
    (1, 'bible-chronological', 'Bible Chronologically', 'Read the Bible in the order events occurred.', 'bible', 365, 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('reading-plans',          'reading-plans/index.php',  1),
    ('reading-plans/my',       'reading-plans/my.php',     1),
    ('reading-plans/plan',     'reading-plans/plan.php',   1),
    ('reading-plans/enroll',   'reading-plans/enroll.php', 1),
    ('reading-plans/check',    'reading-plans/check.php',  1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'reading_plans.enabled',          '0', '0', 0),
    (NULL, 'reading_plans.daily_reminder',   '1', '1', 0),
    (NULL, 'reading_plans.displayName',      'Reading Plans', 'Reading Plans', 0),
    (NULL, 'reading_plans.displayIcon',      'fa-solid fa-book-open', 'fa-solid fa-book-open', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('085_qr.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('qr',                  'qr.php',                        1),
    ('admin/settings/qr',   'admin/settings/qr/index.php',   1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'portal.qr.provider',              'local', 'local', 0),
    (NULL, 'portal.qr.cuercode.api_endpoint', '',      '',      0),
    (NULL, 'portal.qr.cuercode.api_key',      '',      '',      1)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('086_invites.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

CREATE TABLE IF NOT EXISTS `tblInvitation` (
    `invitationID`  INT          NOT NULL AUTO_INCREMENT,
    `siteID`        INT          NOT NULL DEFAULT 1,
    `email`         VARCHAR(255) NOT NULL,
    `tokenHash`     CHAR(64)     NOT NULL,
    `intendedRole`  VARCHAR(64)  DEFAULT NULL,
    `welcomeMessage` TEXT        DEFAULT NULL,
    `expiresAt`     DATETIME     NOT NULL,
    `acceptedAt`    DATETIME     DEFAULT NULL,
    `acceptedByID`  INT          DEFAULT NULL,
    `revokedAt`     DATETIME     DEFAULT NULL,
    `revokedByID`   INT          DEFAULT NULL,
    `createdByID`   INT          NOT NULL,
    `createdAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`invitationID`),
    UNIQUE KEY `uq_invite_token` (`tokenHash`),
    KEY `idx_invite_site_email` (`siteID`, `email`),
    KEY `idx_invite_status` (`acceptedAt`, `revokedAt`, `expiresAt`),
    CONSTRAINT `fk_invite_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites` (`siteID`),
    CONSTRAINT `fk_invite_accepted_user` FOREIGN KEY (`acceptedByID`) REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_invite_revoked_user`  FOREIGN KEY (`revokedByID`)  REFERENCES `tblUsers` (`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_invite_creator`       FOREIGN KEY (`createdByID`)  REFERENCES `tblUsers` (`userID`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('invites',         'invites/index.php',        1),
    ('invites/new',     'invites/new.php',          1),
    ('invites/save',    'invites/save.php',         1),
    ('invites/revoke',  'invites/revoke.php',       1),
    ('auth/invite',     'invites/accept.php',       0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'invites.enabled',           '0',  '0',  0),
    (NULL, 'invites.default_expiry_days','7',  '7',  0),
    (NULL, 'invites.default_role',      'user','user',0),
    (NULL, 'invites.displayName',       'Invitations', 'Invitations', 0),
    (NULL, 'invites.displayIcon',       'fa-solid fa-envelope-open-text', 'fa-solid fa-envelope-open-text', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('087_offboarding.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

CREATE TABLE IF NOT EXISTS `tblOffboarding` (
    `offboardingID`  INT          NOT NULL AUTO_INCREMENT,
    `userID`         INT          NOT NULL,
    `effectiveDate`  DATE         NOT NULL,
    `reason`         VARCHAR(500) DEFAULT NULL,
    `dataDisposition` ENUM('retain','anonymise','delete') NOT NULL DEFAULT 'retain',
    `offboardedByID` INT          NOT NULL,
    `offboardedAt`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `rehiredAt`      DATETIME     DEFAULT NULL,
    `rehiredByID`    INT          DEFAULT NULL,
    `stepsLog`       JSON         DEFAULT NULL,
    PRIMARY KEY (`offboardingID`),
    KEY `idx_offboard_user` (`userID`),
    CONSTRAINT `fk_offboard_user`         FOREIGN KEY (`userID`)         REFERENCES `tblUsers`(`userID`) ON DELETE CASCADE,
    CONSTRAINT `fk_offboard_by`           FOREIGN KEY (`offboardedByID`) REFERENCES `tblUsers`(`userID`) ON DELETE RESTRICT,
    CONSTRAINT `fk_offboard_rehired_by`   FOREIGN KEY (`rehiredByID`)    REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('offboarding',         'offboarding/index.php',     1),
    ('offboarding/user',    'offboarding/user.php',      1),
    ('offboarding/do',      'offboarding/do.php',        1),
    ('offboarding/rehire',  'offboarding/rehire.php',    1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'offboarding.enabled',          '0',  '0',  0),
    (NULL, 'offboarding.undo_window_days', '7',  '7',  0),
    (NULL, 'offboarding.displayName',      'Offboarding', 'Offboarding', 0),
    (NULL, 'offboarding.displayIcon',      'fa-solid fa-door-open', 'fa-solid fa-door-open', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('088_resources.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

CREATE TABLE IF NOT EXISTS `tblResource` (
    `resourceID`        INT          NOT NULL AUTO_INCREMENT,
    `siteID`            INT          NOT NULL DEFAULT 1,
    `name`              VARCHAR(255) NOT NULL,
    `description`       TEXT         DEFAULT NULL,
    `category`          ENUM('room','equipment','vehicle','other') NOT NULL DEFAULT 'room',
    `capacity`          INT          DEFAULT NULL,
    `location`          VARCHAR(255) DEFAULT NULL,
    `requiresApproval`  TINYINT(1)   NOT NULL DEFAULT 0,
    `hourlyRatePence`   INT          DEFAULT NULL,
    `bufferMinutes`     INT          NOT NULL DEFAULT 0,
    `isActive`          TINYINT(1)   NOT NULL DEFAULT 1,
    `createdAt`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`resourceID`),
    KEY `idx_resource_site_active` (`siteID`, `isActive`),
    CONSTRAINT `fk_resource_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblResourceBooking` (
    `bookingID`     INT          NOT NULL AUTO_INCREMENT,
    `resourceID`    INT          NOT NULL,
    `bookedByID`    INT          NOT NULL,
    `startAt`       DATETIME     NOT NULL,
    `endAt`         DATETIME     NOT NULL,
    `purpose`       VARCHAR(255) DEFAULT NULL,
    `status`        ENUM('pending','approved','declined','cancelled') NOT NULL DEFAULT 'pending',
    `approvedByID`  INT          DEFAULT NULL,
    `approvedAt`    DATETIME     DEFAULT NULL,
    `declineReason` VARCHAR(500) DEFAULT NULL,
    `notes`         TEXT         DEFAULT NULL,
    `createdAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`bookingID`),
    KEY `idx_booking_resource_start` (`resourceID`, `startAt`),
    KEY `idx_booking_status` (`status`, `startAt`),
    KEY `idx_booking_booker` (`bookedByID`),
    CONSTRAINT `fk_booking_resource`     FOREIGN KEY (`resourceID`)   REFERENCES `tblResource`(`resourceID`) ON DELETE CASCADE,
    CONSTRAINT `fk_booking_booker`       FOREIGN KEY (`bookedByID`)   REFERENCES `tblUsers`(`userID`)        ON DELETE RESTRICT,
    CONSTRAINT `fk_booking_approver`     FOREIGN KEY (`approvedByID`) REFERENCES `tblUsers`(`userID`)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('resources',             'resources/index.php',         1),
    ('resources/resource',    'resources/resource.php',      1),
    ('resources/book',        'resources/book.php',          1),
    ('resources/my-bookings', 'resources/my-bookings.php',   1),
    ('resources/approvals',   'resources/approvals.php',     1),
    ('resources/action',      'resources/action.php',        1),
    ('resources/manage',      'resources/manage.php',        1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'resources.enabled',         '0', '0', 0),
    (NULL, 'resources.default_buffer',  '15','15', 0),
    (NULL, 'resources.lookahead_days',  '90','90', 0),
    (NULL, 'resources.displayName',     'Resource Booking', 'Resource Booking', 0),
    (NULL, 'resources.displayIcon',     'fa-solid fa-building', 'fa-solid fa-building', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('089_service_plans.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;

CREATE TABLE IF NOT EXISTS `tblServicePlan` (
    `planID`        INT          NOT NULL AUTO_INCREMENT,
    `siteID`        INT          NOT NULL DEFAULT 1,
    `eventID`       INT          DEFAULT NULL,
    `title`         VARCHAR(255) NOT NULL,
    `serviceDate`   DATE         NOT NULL,
    `status`        ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    `preparedByID`  INT          DEFAULT NULL,
    `createdAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`planID`),
    KEY `idx_sp_site_date` (`siteID`, `serviceDate`),
    KEY `idx_sp_event` (`eventID`),
    CONSTRAINT `fk_sp_site`     FOREIGN KEY (`siteID`)       REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_sp_event`    FOREIGN KEY (`eventID`)      REFERENCES `tblEvents`(`eventID`) ON DELETE SET NULL,
    CONSTRAINT `fk_sp_prepared` FOREIGN KEY (`preparedByID`) REFERENCES `tblUsers`(`userID`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tblServicePlanItem` (
    `itemID`        INT          NOT NULL AUTO_INCREMENT,
    `planID`        INT          NOT NULL,
    `sectionType`   ENUM('greeting','song','prayer','scripture','sermon','offering','communion','special_music','announcement','reading','other') NOT NULL DEFAULT 'other',
    `position`      INT          NOT NULL DEFAULT 0,
    `title`         VARCHAR(255) DEFAULT NULL,
    `presenterID`   INT          DEFAULT NULL,
    `presenterText` VARCHAR(255) DEFAULT NULL,
    `durationMin`   INT          DEFAULT NULL,
    `notes`         TEXT         DEFAULT NULL,
    PRIMARY KEY (`itemID`),
    KEY `idx_spi_plan_position` (`planID`, `position`),
    CONSTRAINT `fk_spi_plan`      FOREIGN KEY (`planID`)      REFERENCES `tblServicePlan`(`planID`) ON DELETE CASCADE,
    CONSTRAINT `fk_spi_presenter` FOREIGN KEY (`presenterID`) REFERENCES `tblUsers`(`userID`)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('service-plans',          'service-plans/index.php',     1),
    ('service-plans/new',      'service-plans/new.php',       1),
    ('service-plans/edit',     'service-plans/edit.php',      1),
    ('service-plans/save',     'service-plans/save.php',      1),
    ('service-plans/print',    'service-plans/print.php',     1),
    ('service-plans/item-save','service-plans/item-save.php', 1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'service_plans.enabled',     '0', '0', 0),
    (NULL, 'service_plans.displayName', 'Service Plans', 'Service Plans', 0),
    (NULL, 'service_plans.displayIcon', 'fa-solid fa-list-ol', 'fa-solid fa-list-ol', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);
