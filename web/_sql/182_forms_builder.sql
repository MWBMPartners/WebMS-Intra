-- =============================================================================
-- Migration 182: Forms Builder app (#153)
-- =============================================================================
-- Three NEW tables (no ALTERs, so no information_schema guard blocks are
-- needed): tblForms (definitions), tblFormFields (ordered field list;
-- fieldType is validated in PHP against FormEngine::FIELD_TYPES, never a SQL
-- ENUM), tblFormResponses (immutable answersJson snapshot per submission).
-- Public sharing is DEFAULT OFF (forms.allowPublic seeded 'false') — an
-- upgrade is a functional no-op until an admin opts in. The public fill page
-- is a Router special route /f/{token} (service-plans /os/{token} precedent),
-- NOT a tblRoutes row; only its POST target (forms/public-submit) and the
-- session-authed pages are seeded here. No api/* rows (ApiRouter trap — no
-- v1 REST endpoints ship with this app).
--
-- Replay-proof: CREATE TABLE IF NOT EXISTS throughout, both seed INSERTs use
-- ON DUPLICATE KEY UPDATE, and the tblMigrations self-record is idempotent.
-- Running this file twice against an up-to-date schema is a full no-op.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/153
-- =============================================================================

-- #############################################################################
-- SCHEMA
-- #############################################################################

-- -----------------------------------------------------------------------------
-- 📝 tblForms — form definitions (one row per form, per site)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblForms` (
    `formID`           INT          NOT NULL AUTO_INCREMENT,
    `siteID`           INT          NOT NULL COMMENT 'FK to tblSites (forms are per-site)',
    `title`            VARCHAR(200) NOT NULL,
    `description`      TEXT         DEFAULT NULL COMMENT 'Shown above the form. Plain text, escaped on output',
    `status`           ENUM('draft','published','closed') NOT NULL DEFAULT 'draft',
    `audience`         ENUM('internal','public','both') NOT NULL DEFAULT 'internal',
    `publicToken`      CHAR(32)     DEFAULT NULL COMMENT 'bin2hex(random_bytes(16)) for the /f/{token} public URL. NULL until first generated',
    `confirmationText` VARCHAR(500) DEFAULT NULL COMMENT 'Thank-you message after submit. Escaped on output',
    `allowMultiple`    TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'Internal channel: may one user submit more than once',
    `opensAt`          DATETIME     DEFAULT NULL COMMENT 'NULL = open as soon as published',
    `closesAt`         DATETIME     DEFAULT NULL COMMENT 'NULL = never auto-closes',
    `createdByID`      INT          DEFAULT NULL,
    `createdAt`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`formID`),
    UNIQUE KEY `uq_forms_token` (`publicToken`),
    KEY `idx_forms_site_status` (`siteID`, `status`),
    CONSTRAINT `fk_forms_site`    FOREIGN KEY (`siteID`)      REFERENCES `tblSites`(`siteID`) ON DELETE CASCADE,
    CONSTRAINT `fk_forms_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Forms Builder form definitions (#153, migration 182)';

-- -----------------------------------------------------------------------------
-- 🧩 tblFormFields — ordered field list per form. fieldType/configJson are
-- DATA validated by PHP (FormEngine::FIELD_TYPES / sanitiseConfig()) — never
-- interpolated into SQL, never rendered unescaped.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblFormFields` (
    `fieldID`    INT          NOT NULL AUTO_INCREMENT,
    `formID`     INT          NOT NULL,
    `fieldKey`   VARCHAR(64)  NOT NULL COMMENT 'Server-generated slug of label, unique per form. answersJson key + CSV header',
    `label`      VARCHAR(200) NOT NULL,
    `fieldType`  VARCHAR(20)  NOT NULL COMMENT 'One of FormEngine::FIELD_TYPES (PHP whitelist, deliberately not a SQL ENUM)',
    `helpText`   VARCHAR(500) DEFAULT NULL,
    `configJson` JSON         DEFAULT NULL COMMENT 'Whitelist-sanitised: options[], min, max, maxLength, placeholder, rows',
    `isRequired` TINYINT(1)   NOT NULL DEFAULT 0,
    `position`   INT          NOT NULL DEFAULT 0,
    `createdAt`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`fieldID`),
    UNIQUE KEY `uq_ff_form_key` (`formID`, `fieldKey`),
    KEY `idx_ff_form_pos` (`formID`, `position`),
    CONSTRAINT `fk_ff_form` FOREIGN KEY (`formID`) REFERENCES `tblForms`(`formID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Forms Builder field definitions (#153, migration 182)';

-- -----------------------------------------------------------------------------
-- 📥 tblFormResponses — one row per submission. answersJson is an IMMUTABLE
-- snapshot {fieldKey: {label, type, value}} so later field edits/deletes can
-- never corrupt what was actually submitted. siteID denormalised from
-- tblForms for tenant-scoped listing + GDPR sweeps without a JOIN.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblFormResponses` (
    `responseID`  INT         NOT NULL AUTO_INCREMENT,
    `formID`      INT         NOT NULL,
    `siteID`      INT         NOT NULL COMMENT 'Denormalised from tblForms (tenant scoping + GDPR)',
    `channel`     ENUM('internal','public') NOT NULL DEFAULT 'internal',
    `submitterID` INT         DEFAULT NULL COMMENT 'NULL for public submissions',
    `submitterIP` VARCHAR(45) DEFAULT NULL COMMENT 'Public channel only (abuse tracing)',
    `answersJson` JSON        NOT NULL COMMENT 'Snapshot built by FormEngine::buildAnswersJson()',
    `status`      ENUM('new','reviewed') NOT NULL DEFAULT 'new',
    `createdAt`   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`responseID`),
    KEY `idx_fr_form_created` (`formID`, `createdAt`),
    KEY `idx_fr_site` (`siteID`),
    KEY `idx_fr_submitter` (`submitterID`),
    CONSTRAINT `fk_fr_form` FOREIGN KEY (`formID`)      REFERENCES `tblForms`(`formID`) ON DELETE CASCADE,
    CONSTRAINT `fk_fr_user` FOREIGN KEY (`submitterID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Forms Builder responses (#153, migration 182)';

-- #############################################################################
-- 🌱 SEEDS
-- #############################################################################

-- ⚙️ Settings — forms.enabled 'true' (AppRegistry gate; matches the
-- worship/salvation/kids seeding precedent so registering the app does not
-- silently disable it), forms.allowPublic 'false' (public sharing is opt-in;
-- the /f/{token} page uniform-404s until an admin flips it per site).
-- forms.responseRetentionDays '0' (residual default #1 — keep indefinitely
-- in v1; a future auto-purge cron can consume a nonzero value, see
-- DEV_NOTES.md).
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'forms.enabled',              'true',  'true',  0),
    (NULL, 'forms.allowPublic',          'false', 'false', 0),
    (NULL, 'forms.responseRetentionDays', '0',    '0',     0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- 🗺️ Routes — all session pages isProtected=1; the public POST target and the
-- help page isProtected=0 (prayer-requests/anonymous + help/* precedent).
-- The public GET fill page is NOT here: /f/{token} is a Router special route
-- (service-plans /os/{token} precedent). NO api/* rows (ApiRouter trap).
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('forms',               'forms/index.php',         1),
    ('forms/manage',        'forms/manage.php',        1),
    ('forms/edit',          'forms/edit.php',           1),
    ('forms/save',          'forms/save.php',          1),
    ('forms/field-save',    'forms/field-save.php',    1),
    ('forms/field-delete',  'forms/field-delete.php',  1),
    ('forms/field-move',    'forms/field-move.php',    1),
    ('forms/publish',       'forms/publish.php',       1),
    ('forms/fill',          'forms/fill.php',          1),
    ('forms/submit',        'forms/submit.php',        1),
    ('forms/responses',     'forms/responses.php',     1),
    ('forms/response-act',  'forms/response-act.php',  1),
    ('forms/export',        'forms/export.php',        1),
    ('forms/public-submit', 'forms/public-submit.php', 0),
    ('help/forms',          'help/forms.php',          0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- -----------------------------------------------------------------------------
-- 📋 Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT is idempotent).
-- -----------------------------------------------------------------------------
INSERT INTO `tblMigrations` (`filename`) VALUES ('182_forms_builder.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
