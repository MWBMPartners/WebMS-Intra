-- =============================================================================
-- Migration 159: Asset Tracker — foundation (scaffold + schema + routing) (#393)
--                 + audit choke-point (#395)
-- =============================================================================
-- Foundation-only sub-issue for the new "Asset Tracker" app (slug `assets`).
-- Ships the full 13-table schema, the AppRegistry entry, route seeds (with a
-- lightweight stub handler for every route not fully built in this pass —
-- see _apps/assets/*.php), and the audit/GDPR choke-point
-- (`Portal\Core\AssetRegister::audit()`) every future Asset Tracker mutation
-- routes through. Only `assets/index.php` (register listing) and
-- `assets/tag.php` (public `/a/{token}` lost-and-found page) are fully built
-- here; every other handler is a documented "coming in a later sub-issue"
-- placeholder so `check_route_targets.py` stays green while the schema and
-- routing skeleton ship ahead of the CRUD/loans/maintenance/labels work.
--
-- Design notes:
--   • Money columns are INT pence (house convention, #266) — never DECIMAL.
--   • `CONDITION` is a MySQL reserved word → renamed `conditionState`
--     everywhere an asset/loan condition is recorded (every other column
--     name in this file was checked against the MySQL 8.0 reserved-word
--     list; none of the others collide).
--   • EVERY asset-child table gets `siteID` + a `tblSites` FK, EXCEPT:
--       - `tblAssetIdentifierTypes` — GLOBAL reference data (mirrors
--         `tblRoles`: shared vocabulary, not tenant-scoped).
--       - `tblAssetAudit` — carries a `siteID` COLUMN for fast per-site
--         filtering but, like `tblAuditTrail`, has NO FK constraints
--         anywhere on the table, so an audit row survives the deletion of
--         the asset/user/site it describes (immutable historical record —
--         see AssetRegister::audit() below).
--   • `tblAssetIdentifiers.typeCode` is a soft reference to
--     `tblAssetIdentifierTypes.typeCode` (no FK) — deliberately loose so an
--     admin can record an identifier under a scheme not yet in the seed
--     list without being blocked; `AssetRegister::validateIdentifier()`
--     validates format/check-digit as WARNINGS only, never a hard block.
--   • `licenseKey` holds libsodium ciphertext (encrypt_setting()/
--     decrypt_setting() from bootstrap.php) — the column NEVER holds
--     plaintext, and `AssetRegister::audit()` redacts it (and
--     `publicToken`) from every change-set it writes.
--   • Follows the portable-DDL convention from DEV_NOTES.md — every table
--     here is brand new, so plain `CREATE TABLE IF NOT EXISTS` is already
--     idempotent and needs no information_schema guard block (that idiom
--     is only required for ALTER/ADD COLUMN/ADD INDEX on EXISTING tables).
--
-- Replay/no-op proof: every CREATE is IF NOT EXISTS; every seed INSERT is
-- either ON DUPLICATE KEY UPDATE or the WHERE NOT EXISTS idiom (tblRoles,
-- matching migrations 143/148); the final tblMigrations self-record uses
-- the same idiom as every other migration in this codebase.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/393
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/395
-- =============================================================================


-- #############################################################################
-- 🗄️ TABLES
-- #############################################################################

-- -----------------------------------------------------------------------------
-- 🏷️ tblAssetCategories — per-site asset categories (#393)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblAssetCategories` (
    `categoryID`   INT          NOT NULL AUTO_INCREMENT,
    `siteID`       INT          NOT NULL DEFAULT 1,
    `categoryName` VARCHAR(100) NOT NULL,
    `icon`         VARCHAR(50)  DEFAULT NULL COMMENT 'Font Awesome class',
    `sortOrder`    INT          NOT NULL DEFAULT 0,
    `isActive`     TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (`categoryID`),
    KEY `idx_astcat_site` (`siteID`),
    CONSTRAINT `fk_astcat_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Asset Tracker — per-site categories (#393)';


-- -----------------------------------------------------------------------------
-- 📍 tblAssetLocations — per-site storage/deployment locations, optionally
-- nested (e.g. Building → Room → Cupboard). (#393)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblAssetLocations` (
    `locationID`       INT          NOT NULL AUTO_INCREMENT,
    `siteID`           INT          NOT NULL DEFAULT 1,
    `locationName`     VARCHAR(150) NOT NULL,
    `details`          VARCHAR(500) DEFAULT NULL,
    `parentLocationID` INT          DEFAULT NULL COMMENT 'Self-FK — nested locations',
    `isActive`         TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (`locationID`),
    KEY `idx_astloc_site` (`siteID`),
    CONSTRAINT `fk_astloc_site`   FOREIGN KEY (`siteID`)           REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_astloc_parent` FOREIGN KEY (`parentLocationID`) REFERENCES `tblAssetLocations`(`locationID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Asset Tracker — per-site storage/deployment locations (#393)';


-- -----------------------------------------------------------------------------
-- 🏢 tblAssetOrgs — external organisations that can own, lend, or borrow
-- assets (hire companies, partner charities, suppliers, etc). (#393)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblAssetOrgs` (
    `orgID`         INT          NOT NULL AUTO_INCREMENT,
    `siteID`        INT          NOT NULL DEFAULT 1,
    `orgName`       VARCHAR(255) NOT NULL,
    `contactName`   VARCHAR(150) DEFAULT NULL,
    `contactEmail`  VARCHAR(255) DEFAULT NULL,
    `contactPhone`  VARCHAR(50)  DEFAULT NULL,
    `agreementRef`  VARCHAR(100) DEFAULT NULL COMMENT 'Loan/hire agreement reference',
    `notes`         TEXT         DEFAULT NULL,
    `isActive`      TINYINT(1)   NOT NULL DEFAULT 1,
    `createdAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`orgID`),
    KEY `idx_astorg_site_active` (`siteID`, `isActive`),
    CONSTRAINT `fk_astorg_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Asset Tracker — external organisations (owners/lenders/borrowers) (#393)';


-- -----------------------------------------------------------------------------
-- 📦 tblAssets — the asset register itself. Physical or digital assets,
-- ownership/lending/maintenance/license metadata, depreciation, and the
-- public lost-and-found token. (#393)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblAssets` (
    `assetID`             INT           NOT NULL AUTO_INCREMENT,
    `siteID`              INT           NOT NULL DEFAULT 1,
    `assetKind`           ENUM('physical','digital') NOT NULL DEFAULT 'physical'
                          COMMENT 'physical = tangible item; digital = software licence/subscription/domain/etc',
    `name`                VARCHAR(255)  NOT NULL,
    `description`         TEXT          DEFAULT NULL,
    `categoryID`          INT           DEFAULT NULL,
    `locationID`          INT           DEFAULT NULL,
    `manufacturer`        VARCHAR(150)  DEFAULT NULL,
    `model`               VARCHAR(150)  DEFAULT NULL,
    `serialNumber`        VARCHAR(150)  DEFAULT NULL,
    `assetTagCode`        VARCHAR(50)   DEFAULT NULL COMMENT 'Human-readable tag/label code printed on the physical label (labels sub-issue)',
    `features`            TEXT          DEFAULT NULL COMMENT 'Free-text spec/feature notes',
    `conditionState`      ENUM('new','excellent','good','fair','poor','broken') NOT NULL DEFAULT 'good'
                          COMMENT 'CONDITION is a MySQL reserved word — column renamed conditionState',
    `status`              ENUM('in-service','in-repair','on-loan','borrowed','in-storage','retired','disposed','lost','stolen')
                          NOT NULL DEFAULT 'in-service',
    `purchaseDate`        DATE          DEFAULT NULL,
    `purchaseStore`       VARCHAR(255)  DEFAULT NULL,
    `purchaseCostPence`   INT           DEFAULT NULL COMMENT 'Integer minor units — house pence convention (#266)',
    `currency`            CHAR(3)       NOT NULL DEFAULT 'GBP' COMMENT 'ISO 4217 currency code',
    `warrantyExpiry`      DATE          DEFAULT NULL,
    `warrantyDetails`     VARCHAR(500)  DEFAULT NULL,
    `licenseKey`          VARCHAR(500)  DEFAULT NULL
                          COMMENT 'libsodium ciphertext via encrypt_setting() — NEVER plaintext (digital assets only)',
    `licenseSeats`        INT           DEFAULT NULL COMMENT 'Total seats for this licence (digital assets); NULL = not seat-limited',
    `renewalDate`         DATE          DEFAULT NULL COMMENT 'Next subscription/licence renewal due date',
    `accessUrl`           VARCHAR(500)  DEFAULT NULL COMMENT 'Login/admin URL for a digital asset',
    `depreciationMethod`  ENUM('none','straight-line','reducing-balance') NOT NULL DEFAULT 'none',
    `usefulLifeMonths`    INT           DEFAULT NULL,
    `salvageValuePence`   INT           DEFAULT NULL,
    `currentValuePence`   INT           DEFAULT NULL COMMENT 'Last computed/valued book value, in pence',
    `valuationDate`       DATE          DEFAULT NULL,
    `ownershipTerms`      TEXT          DEFAULT NULL COMMENT 'Free-text ownership/agreement terms (loaned-in items, shared ownership, etc)',
    `isConfidential`      TINYINT(1)    NOT NULL DEFAULT 0 COMMENT 'Hides the item from the public lost-and-found page (#395 access gate)',
    `publicToken`         CHAR(32)      NOT NULL COMMENT '32-char hex token for the public /a/{token} lost-and-found page',
    `publicPageEnabled`   TINYINT(1)    NOT NULL DEFAULT 1,
    `labelSymbology`      ENUM('qr','code128','ean13','upca','itf14','qr+code128') NOT NULL DEFAULT 'qr'
                          COMMENT 'Preferred symbology for this asset''s printed label (labels sub-issue)',
    `parentAssetID`       INT           DEFAULT NULL COMMENT 'Self-FK — bundles/kits/component relationships',
    `createdByID`         INT           NOT NULL,
    `createdAt`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `isDeleted`           TINYINT(1)    NOT NULL DEFAULT 0,
    PRIMARY KEY (`assetID`),
    UNIQUE KEY `uq_asset_public_token` (`publicToken`),
    UNIQUE KEY `uq_asset_tag` (`siteID`, `assetTagCode`),
    KEY `idx_ast_site_status` (`siteID`, `status`),
    KEY `idx_ast_site_cat` (`siteID`, `categoryID`),
    KEY `idx_ast_site_kind` (`siteID`, `assetKind`),
    KEY `idx_ast_serial` (`serialNumber`),
    KEY `idx_ast_parent` (`parentAssetID`),
    CONSTRAINT `fk_asset_site`     FOREIGN KEY (`siteID`)        REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_asset_category` FOREIGN KEY (`categoryID`)    REFERENCES `tblAssetCategories`(`categoryID`) ON DELETE SET NULL,
    CONSTRAINT `fk_asset_location` FOREIGN KEY (`locationID`)    REFERENCES `tblAssetLocations`(`locationID`) ON DELETE SET NULL,
    CONSTRAINT `fk_asset_parent`   FOREIGN KEY (`parentAssetID`) REFERENCES `tblAssets`(`assetID`) ON DELETE SET NULL,
    CONSTRAINT `fk_asset_creator`  FOREIGN KEY (`createdByID`)   REFERENCES `tblUsers`(`userID`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Asset Tracker — the register itself (#393)';


-- -----------------------------------------------------------------------------
-- 👤 tblAssetOwners — ownership/custodianship parties for an asset. Exactly
-- one of userID/deptID/groupID/orgID is expected per row — enforced in PHP
-- (AssetRegister/owners-save.php), not by SQL. (#393)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblAssetOwners` (
    `ownerID`                INT        NOT NULL AUTO_INCREMENT,
    `siteID`                 INT        NOT NULL DEFAULT 1,
    `assetID`                INT        NOT NULL,
    `partyType`              ENUM('user','dept','group','org') NOT NULL,
    `userID`                 INT        DEFAULT NULL,
    `deptID`                 INT        DEFAULT NULL,
    `groupID`                INT        DEFAULT NULL,
    `orgID`                  INT        DEFAULT NULL,
    `roleKind`               ENUM('owner','co-owner','custodian','stakeholder') NOT NULL DEFAULT 'owner',
    `sharePercent`           DECIMAL(5,2) DEFAULT NULL COMMENT 'Fractional ownership share, e.g. co-owned equipment',
    `isLendingAuthority`     TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Can approve loans of this asset',
    `isMaintenanceAuthority` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Can approve/log maintenance for this asset',
    `notes`                  VARCHAR(500) DEFAULT NULL,
    PRIMARY KEY (`ownerID`),
    KEY `idx_asto_site` (`siteID`),
    KEY `idx_asto_asset` (`assetID`),
    KEY `idx_asto_party_user` (`partyType`, `userID`),
    KEY `idx_asto_org` (`orgID`),
    CONSTRAINT `fk_asto_site`  FOREIGN KEY (`siteID`)  REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_asto_asset` FOREIGN KEY (`assetID`) REFERENCES `tblAssets`(`assetID`) ON DELETE CASCADE,
    CONSTRAINT `fk_asto_user`  FOREIGN KEY (`userID`)  REFERENCES `tblUsers`(`userID`)  ON DELETE CASCADE,
    CONSTRAINT `fk_asto_dept`  FOREIGN KEY (`deptID`)  REFERENCES `tblDepts`(`deptID`)  ON DELETE CASCADE,
    CONSTRAINT `fk_asto_group` FOREIGN KEY (`groupID`) REFERENCES `tblGroups`(`groupID`) ON DELETE CASCADE,
    CONSTRAINT `fk_asto_org`   FOREIGN KEY (`orgID`)   REFERENCES `tblAssetOrgs`(`orgID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Asset Tracker — ownership/custodianship parties (#393)';


-- -----------------------------------------------------------------------------
-- 🔄 tblAssetLoans — outbound (we lend) and inbound (we borrow) loan
-- lifecycle, with condition-in/out capture and swap-chain support via
-- parentLoanID. (#393)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblAssetLoans` (
    `loanID`               INT      NOT NULL AUTO_INCREMENT,
    `siteID`               INT      NOT NULL DEFAULT 1,
    `assetID`              INT      NOT NULL,
    `direction`            ENUM('out','in') NOT NULL COMMENT 'out = we lend this asset; in = we borrow someone else''s',
    `counterpartyType`     ENUM('user','org','other') NOT NULL,
    `counterpartyUserID`   INT      DEFAULT NULL,
    `counterpartyOrgID`    INT      DEFAULT NULL,
    `counterpartyName`     VARCHAR(255) DEFAULT NULL COMMENT 'Free-text name when counterpartyType=other',
    `counterpartyContact`  VARCHAR(255) DEFAULT NULL,
    `status`               ENUM('requested','approved','declined','active','returned','cancelled') NOT NULL DEFAULT 'requested',
    `approvedByID`         INT      DEFAULT NULL,
    `approvedAt`           DATETIME DEFAULT NULL,
    `declineReason`        VARCHAR(500) DEFAULT NULL,
    `conditionOut`         ENUM('new','excellent','good','fair','poor','broken') DEFAULT NULL,
    `conditionOutNotes`    VARCHAR(500) DEFAULT NULL,
    `conditionIn`          ENUM('new','excellent','good','fair','poor','broken') DEFAULT NULL,
    `conditionInNotes`     VARCHAR(500) DEFAULT NULL,
    `dateOut`              DATETIME DEFAULT NULL,
    `dueDate`              DATE     DEFAULT NULL,
    `dateIn`               DATETIME DEFAULT NULL,
    `parentLoanID`         INT      DEFAULT NULL COMMENT 'Self-FK — chained swap/renewal loans',
    `requestedByID`        INT      NOT NULL,
    `notes`                TEXT     DEFAULT NULL,
    PRIMARY KEY (`loanID`),
    KEY `idx_astln_asset_status` (`assetID`, `status`),
    KEY `idx_astln_site_status_due` (`siteID`, `status`, `dueDate`),
    KEY `idx_astln_cpuser` (`counterpartyUserID`),
    KEY `idx_astln_parent` (`parentLoanID`),
    CONSTRAINT `fk_astln_site`      FOREIGN KEY (`siteID`)              REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_astln_asset`     FOREIGN KEY (`assetID`)             REFERENCES `tblAssets`(`assetID`) ON DELETE CASCADE,
    CONSTRAINT `fk_astln_cpuser`    FOREIGN KEY (`counterpartyUserID`)  REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_astln_cporg`     FOREIGN KEY (`counterpartyOrgID`)   REFERENCES `tblAssetOrgs`(`orgID`) ON DELETE SET NULL,
    CONSTRAINT `fk_astln_approver`  FOREIGN KEY (`approvedByID`)        REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_astln_parent`    FOREIGN KEY (`parentLoanID`)        REFERENCES `tblAssetLoans`(`loanID`) ON DELETE SET NULL,
    CONSTRAINT `fk_astln_requester` FOREIGN KEY (`requestedByID`)       REFERENCES `tblUsers`(`userID`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Asset Tracker — loan lifecycle, in and out (#393)';


-- -----------------------------------------------------------------------------
-- 🔧 tblAssetMaintenance — service/repair/inspection/calibration history.
-- (#393)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblAssetMaintenance` (
    `maintID`            INT          NOT NULL AUTO_INCREMENT,
    `siteID`             INT          NOT NULL DEFAULT 1,
    `assetID`            INT          NOT NULL,
    `maintType`          ENUM('service','repair','inspection','calibration','upgrade','other') NOT NULL DEFAULT 'service',
    `title`              VARCHAR(255) NOT NULL,
    `details`            TEXT         DEFAULT NULL,
    `performedByUserID`  INT          DEFAULT NULL COMMENT 'Portal user who did the work, if any',
    `performedByName`    VARCHAR(255) DEFAULT NULL COMMENT 'Free-text — external contractor/engineer',
    `costPence`          INT          DEFAULT NULL,
    `performedAt`        DATE         DEFAULT NULL,
    `nextDueDate`        DATE         DEFAULT NULL,
    `status`             ENUM('scheduled','completed','cancelled') NOT NULL DEFAULT 'completed',
    `createdByID`        INT          NOT NULL,
    `createdAt`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`maintID`),
    KEY `idx_astmnt_asset_performed` (`assetID`, `performedAt`),
    KEY `idx_astmnt_site_status_due` (`siteID`, `status`, `nextDueDate`),
    CONSTRAINT `fk_astmnt_site`      FOREIGN KEY (`siteID`)            REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_astmnt_asset`     FOREIGN KEY (`assetID`)           REFERENCES `tblAssets`(`assetID`) ON DELETE CASCADE,
    CONSTRAINT `fk_astmnt_performer` FOREIGN KEY (`performedByUserID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_astmnt_creator`   FOREIGN KEY (`createdByID`)       REFERENCES `tblUsers`(`userID`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Asset Tracker — maintenance/service history (#393)';


-- -----------------------------------------------------------------------------
-- 📎 tblAssetResources — attached manuals/guides/photos/receipts/agreements
-- (either an external link or an uploaded file under _uploads/). (#393)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblAssetResources` (
    `resourceID`     INT          NOT NULL AUTO_INCREMENT,
    `siteID`         INT          NOT NULL DEFAULT 1,
    `assetID`        INT          NOT NULL,
    `resourceType`   ENUM('manual','guide','video','photo','receipt','ownership-agreement','insurance','legal','other') NOT NULL DEFAULT 'other',
    `title`          VARCHAR(255) NOT NULL,
    `linkUrl`        VARCHAR(500) DEFAULT NULL COMMENT 'External link (mutually exclusive with fileName/filePath in practice)',
    `fileName`       VARCHAR(255) DEFAULT NULL COMMENT 'Original upload filename',
    `filePath`       VARCHAR(255) DEFAULT NULL COMMENT 'Path relative to _uploads/assets/',
    `fileSize`       INT          DEFAULT NULL COMMENT 'Bytes',
    `mimeType`       VARCHAR(100) DEFAULT NULL,
    `isPublic`       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Visible on the public /a/{token} page when the asset itself is public',
    `uploadedByID`   INT          NOT NULL,
    `createdAt`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`resourceID`),
    KEY `idx_astres_site` (`siteID`),
    KEY `idx_astres_asset_type` (`assetID`, `resourceType`),
    CONSTRAINT `fk_astres_site`     FOREIGN KEY (`siteID`)       REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_astres_asset`    FOREIGN KEY (`assetID`)      REFERENCES `tblAssets`(`assetID`) ON DELETE CASCADE,
    CONSTRAINT `fk_astres_uploader` FOREIGN KEY (`uploadedByID`) REFERENCES `tblUsers`(`userID`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Asset Tracker — attached manuals/guides/photos/receipts (#393)';


-- -----------------------------------------------------------------------------
-- 🔍 tblAssetFoundReports — "I found this" submissions from the public
-- lost-and-found page. Anonymous by design (no user link), so it is
-- deliberately excluded from GdprEraser's per-user catalogue — see
-- _core/GdprEraser.php for the documented rationale. (#393 / #395)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblAssetFoundReports` (
    `reportID`         INT          NOT NULL AUTO_INCREMENT,
    `siteID`           INT          NOT NULL DEFAULT 1,
    `assetID`          INT          NOT NULL,
    `reporterName`     VARCHAR(150) DEFAULT NULL,
    `reporterContact`  VARCHAR(255) DEFAULT NULL,
    `message`          TEXT         DEFAULT NULL,
    `ipHash`           CHAR(64)     DEFAULT NULL COMMENT 'Salted SHA-256 of the reporter''s IP — see AssetRegister::audit()',
    `status`           ENUM('new','actioned','closed') NOT NULL DEFAULT 'new',
    `createdAt`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`reportID`),
    KEY `idx_astfr_asset` (`assetID`),
    KEY `idx_astfr_site_status` (`siteID`, `status`),
    CONSTRAINT `fk_astfr_site`  FOREIGN KEY (`siteID`)  REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_astfr_asset` FOREIGN KEY (`assetID`) REFERENCES `tblAssets`(`assetID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Asset Tracker — public lost-and-found reports (#393)';


-- -----------------------------------------------------------------------------
-- 🔢 tblAssetIdentifierTypes — GLOBAL reference vocabulary of identifier
-- schemes (GS1 keys, retail barcodes, RFID/EPC carriers, classification
-- codes). Mirrors tblRoles: shared, not tenant-scoped, no siteID/FKs.
-- (#393)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblAssetIdentifierTypes` (
    `typeID`            INT          NOT NULL AUTO_INCREMENT,
    `typeCode`          VARCHAR(20)  NOT NULL COMMENT 'Short machine code, e.g. GIAI, GTIN, EAN-13',
    `label`             VARCHAR(100) NOT NULL COMMENT 'Human-readable name',
    `category`          ENUM('gs1-key','retail-barcode','carrier','classification','other') NOT NULL,
    `formatRegex`        VARCHAR(255) DEFAULT NULL COMMENT 'Optional format-validation regex (future use)',
    `hasCheckDigit`     TINYINT(1)   NOT NULL DEFAULT 0,
    `checkDigitScheme`  ENUM('none','gs1-mod10','gmn-mod1021') NOT NULL DEFAULT 'none',
    `description`       VARCHAR(500) DEFAULT NULL,
    `sortOrder`         INT          NOT NULL DEFAULT 0,
    `isActive`          TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (`typeID`),
    UNIQUE KEY `uq_ident_typecode` (`typeCode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Asset Tracker — global identifier-scheme vocabulary (#393)';


-- -----------------------------------------------------------------------------
-- 🆔 tblAssetIdentifiers — GS1/barcode/RFID identifiers recorded against an
-- asset. typeCode is a SOFT reference to tblAssetIdentifierTypes (no FK) —
-- see the file-header design note. (#393)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblAssetIdentifiers` (
    `identifierID`  INT          NOT NULL AUTO_INCREMENT,
    `siteID`        INT          NOT NULL DEFAULT 1,
    `assetID`       INT          NOT NULL,
    `typeCode`      VARCHAR(20)  NOT NULL COMMENT 'Soft reference to tblAssetIdentifierTypes.typeCode — see file header',
    `subScheme`     VARCHAR(30)  DEFAULT NULL COMMENT 'Optional narrower scheme label, e.g. issuing agency',
    `value`         VARCHAR(255) NOT NULL,
    `isPrimary`     TINYINT(1)   NOT NULL DEFAULT 0,
    `isVerified`    TINYINT(1)   NOT NULL DEFAULT 0,
    `notes`         VARCHAR(500) DEFAULT NULL,
    `createdByID`   INT          NOT NULL,
    `createdAt`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`identifierID`),
    KEY `idx_astid_site` (`siteID`),
    KEY `idx_astid_asset` (`assetID`),
    KEY `idx_astid_type_value` (`typeCode`, `value`),
    UNIQUE KEY `uq_asset_ident` (`assetID`, `typeCode`, `value`),
    CONSTRAINT `fk_astid_site`    FOREIGN KEY (`siteID`)      REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_astid_asset`   FOREIGN KEY (`assetID`)     REFERENCES `tblAssets`(`assetID`) ON DELETE CASCADE,
    CONSTRAINT `fk_astid_creator` FOREIGN KEY (`createdByID`) REFERENCES `tblUsers`(`userID`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Asset Tracker — GS1/barcode/RFID identifiers per asset (#393)';


-- -----------------------------------------------------------------------------
-- 🎟️ tblAssetLicenseAssignments — seat assignment ledger for digital assets
-- that carry licenseSeats. licenseAssetID always points at the licence
-- asset; a seat is handed to either a deviceAssetID or a userID (or neither
-- — a free-text deviceName). (#393)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblAssetLicenseAssignments` (
    `assignmentID`   INT      NOT NULL AUTO_INCREMENT,
    `siteID`         INT      NOT NULL DEFAULT 1,
    `licenseAssetID` INT      NOT NULL COMMENT 'FK → tblAssets — the digital asset carrying the licence',
    `seatLabel`      VARCHAR(100) DEFAULT NULL,
    `deviceAssetID`  INT      DEFAULT NULL COMMENT 'Optional FK → tblAssets — the physical device this seat is installed on',
    `userID`         INT      DEFAULT NULL,
    `deviceName`     VARCHAR(255) DEFAULT NULL COMMENT 'Free-text device name when no tblAssets row exists for it',
    `status`         ENUM('active','released') NOT NULL DEFAULT 'active',
    `linkedAt`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `linkedByID`     INT      NOT NULL,
    `releasedAt`     DATETIME DEFAULT NULL,
    `releasedByID`   INT      DEFAULT NULL,
    `notes`          VARCHAR(500) DEFAULT NULL,
    PRIMARY KEY (`assignmentID`),
    KEY `idx_astla_site` (`siteID`),
    KEY `idx_astla_license_status` (`licenseAssetID`, `status`),
    KEY `idx_astla_device` (`deviceAssetID`),
    KEY `idx_astla_user` (`userID`),
    CONSTRAINT `fk_astla_site`     FOREIGN KEY (`siteID`)         REFERENCES `tblSites`(`siteID`),
    CONSTRAINT `fk_astla_license`  FOREIGN KEY (`licenseAssetID`) REFERENCES `tblAssets`(`assetID`) ON DELETE CASCADE,
    CONSTRAINT `fk_astla_device`   FOREIGN KEY (`deviceAssetID`)  REFERENCES `tblAssets`(`assetID`) ON DELETE SET NULL,
    CONSTRAINT `fk_astla_user`     FOREIGN KEY (`userID`)         REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL,
    CONSTRAINT `fk_astla_linker`   FOREIGN KEY (`linkedByID`)     REFERENCES `tblUsers`(`userID`) ON DELETE RESTRICT,
    CONSTRAINT `fk_astla_releaser` FOREIGN KEY (`releasedByID`)   REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Asset Tracker — licence seat assignment ledger (#393)';


-- -----------------------------------------------------------------------------
-- 📜 tblAssetAudit — the #395 audit choke-point. Every AssetRegister
-- mutation writes here via AssetRegister::audit(). Mirrors tblAuditTrail's
-- own convention: siteID is a plain COLUMN (fast filtering) but this table
-- has NO FK constraints anywhere, so a row survives the deletion of the
-- asset/user/site/API key it describes — audit history must outlive the
-- thing it audits. (#395)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tblAssetAudit` (
    `auditID`      INT      NOT NULL AUTO_INCREMENT,
    `siteID`       INT      NOT NULL DEFAULT 1,
    `assetID`      INT      NOT NULL COMMENT 'No FK by design — see table comment',
    `entityType`   ENUM('asset','owner','loan','maintenance','resource','identifier','license','label','token','found-report','event-link','stocktake','kiosk') NOT NULL,
    `entityID`     INT      NOT NULL DEFAULT 0 COMMENT '0 when the action has no specific child-row id (e.g. a token scan)',
    `action`       VARCHAR(40) NOT NULL COMMENT 'create/update/delete/scan/approve/decline/... — free-form, not ENUM, so new action verbs never need a migration',
    `changeSet`    JSON     DEFAULT NULL COMMENT '{field:{old,new}} — sensitive fields redacted, see AssetRegister::audit()',
    `meta`         JSON     DEFAULT NULL COMMENT 'Free-form extra context (e.g. loan counterparty, label symbology)',
    `actorType`    ENUM('user','system','public') NOT NULL DEFAULT 'user',
    `actorUserID`  INT      DEFAULT NULL COMMENT 'No FK by design — see table comment',
    `apiKeyID`     INT      DEFAULT NULL COMMENT 'tblApiKeys.keyID when the change arrived via bearer API key — no FK by design',
    `ipHash`       CHAR(64) DEFAULT NULL COMMENT 'Salted SHA-256 of the actor''s IP (public/anonymous actions only)',
    `createdAt`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`auditID`),
    KEY `idx_astaud_asset_created` (`assetID`, `createdAt`),
    KEY `idx_astaud_entity` (`entityType`, `entityID`),
    KEY `idx_astaud_site_created` (`siteID`, `createdAt`),
    KEY `idx_astaud_actor` (`actorUserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Asset Tracker — audit choke-point, no FKs by design (#395)';


-- #############################################################################
-- 🌱 SEEDS
-- #############################################################################

-- -----------------------------------------------------------------------------
-- ⚙️ Feature gates + tunables.
-- -----------------------------------------------------------------------------
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'assets.enabled',                     'true',     'true',     0),
    (NULL, 'assets.maxFileSize',                 '10485760', '10485760', 0),
    (NULL, 'assets.public_page_enabled',         'true',     'true',     0),
    (NULL, 'assets.found_report_retention_days', '180',      '180',      0),
    (NULL, 'assets.license_seat_block',          '0',        '0',       0),
    (NULL, 'api.assets.qr.enabled',               'true',     'true',     0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

-- -----------------------------------------------------------------------------
-- 🏷️ Role — Asset Manager. WHERE NOT EXISTS idiom (matches migrations
-- 143/148) since tblRoles has no meaningful column for ON DUPLICATE KEY
-- UPDATE to touch.
-- -----------------------------------------------------------------------------
INSERT INTO `tblRoles` (`roleKey`, `roleName`)
    SELECT 'asset_manager', 'Asset Manager'
    WHERE NOT EXISTS (SELECT 1 FROM `tblRoles` WHERE `roleKey` = 'asset_manager');

-- -----------------------------------------------------------------------------
-- 🔢 Global identifier-scheme vocabulary. GIAI/GRAI sort first — the two
-- schemes explicitly purpose-built for identifying assets/returnable items.
-- -----------------------------------------------------------------------------
INSERT INTO `tblAssetIdentifierTypes`
    (`typeCode`, `label`, `category`, `hasCheckDigit`, `checkDigitScheme`, `description`, `sortOrder`) VALUES
    ('GIAI',     'Global Individual Asset Identifier (GIAI)', 'gs1-key',        0, 'none',        'GS1 key purpose-built for identifying individual assets (recommended for assets).', 1),
    ('GRAI',     'Global Returnable Asset Identifier (GRAI)', 'gs1-key',        1, 'gs1-mod10',    'GS1 key for identifying returnable/reusable transport items and assets (recommended for assets).', 2),
    ('GTIN',     'Global Trade Item Number (GTIN)',           'gs1-key',        1, 'gs1-mod10',    'Identifies a trade item/product.', 10),
    ('GLN',      'Global Location Number (GLN)',               'gs1-key',        1, 'gs1-mod10',    'Identifies a physical location or legal entity.', 11),
    ('SSCC',     'Serial Shipping Container Code (SSCC)',     'gs1-key',        1, 'gs1-mod10',    'Identifies a logistics/shipping unit.', 12),
    ('GSRN',     'Global Service Relation Number (GSRN)',     'gs1-key',        1, 'gs1-mod10',    'Identifies a service relationship or recipient.', 13),
    ('GSIN',     'Global Shipment Identification Number (GSIN)', 'gs1-key',     1, 'gs1-mod10',    'Identifies a shipment.', 14),
    ('GINC',     'Global Identification Number for Consignment (GINC)', 'gs1-key', 0, 'none',       'Identifies a consignment for logistics purposes.', 15),
    ('GDTI',     'Global Document Type Identifier (GDTI)',    'gs1-key',        1, 'gs1-mod10',    'Identifies a document type and, optionally, a specific document instance.', 16),
    ('GCN',      'Global Coupon Number (GCN)',                 'gs1-key',        1, 'gs1-mod10',    'Identifies a coupon.', 17),
    ('GMN',      'Global Model Number (GMN)',                 'gs1-key',        1, 'gmn-mod1021',  'Identifies a product model/version for regulatory traceability.', 18),
    ('CPID',     'Component/Part Identifier (CPID)',          'gs1-key',        0, 'none',        'Identifies a component or part.', 19),
    ('EAN-8',    'European Article Number, 8-digit (EAN-8)',  'retail-barcode', 1, 'gs1-mod10',    'Compact retail barcode for small packaging.', 20),
    ('EAN-13',   'European Article Number, 13-digit (EAN-13)', 'retail-barcode', 1, 'gs1-mod10',   'Standard retail barcode.', 21),
    ('UPC-A',    'Universal Product Code, 12-digit (UPC-A)',  'retail-barcode', 1, 'gs1-mod10',    'North American standard retail barcode.', 22),
    ('UPC-E',    'Universal Product Code, compressed (UPC-E)', 'retail-barcode', 1, 'gs1-mod10',   'Space-saving compressed UPC variant for small packaging.', 23),
    ('ITF-14',   'Interleaved 2 of 5, 14-digit (ITF-14)',     'retail-barcode', 1, 'gs1-mod10',    'Carton/case-level shipping barcode.', 24),
    ('EPC',      'Electronic Product Code (EPC)',             'carrier',        0, 'none',        'RFID tag data encoding standard.', 30),
    ('RFID-TID', 'RFID Tag ID (TID)',                          'carrier',        0, 'none',        'The RFID chip''s own factory-programmed unique identifier.', 31),
    ('GPC',      'Global Product Classification (GPC)',       'classification', 0, 'none',        'Product category classification code — not a unique identifier.', 40),
    ('OTHER',    'Other / unlisted scheme',                    'other',          0, 'none',        'Any identifier scheme not covered by the entries above.', 99)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`);

-- -----------------------------------------------------------------------------
-- 🗺️ Routes. Protected app routes first, then the one public route
-- (found-save). The public /a/{token} lost-and-found page is a
-- Router::handleSpecialRoutes() special case (like /e/{slug}) — NOT a
-- tblRoutes row, so it is not seeded here (see _core/Router.php).
-- Every targetFile below resolves to a real handler shipped in this same
-- change — either the full page (index.php) or a documented
-- "later sub-issue" stub — so check_route_targets.py stays green.
-- -----------------------------------------------------------------------------
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('assets',                    'assets/index.php',            1),
    ('assets/item',               'assets/item.php',             1),
    ('assets/edit',               'assets/edit.php',              1),
    ('assets/save',               'assets/save.php',              1),
    ('assets/delete',             'assets/delete.php',            1),
    ('assets/owners-save',        'assets/owners-save.php',       1),
    ('assets/categories',         'assets/categories.php',        1),
    ('assets/locations',          'assets/locations.php',         1),
    ('assets/orgs',               'assets/orgs.php',              1),
    ('assets/loans',              'assets/loans.php',             1),
    ('assets/loan',               'assets/loan.php',              1),
    ('assets/loan-save',          'assets/loan-save.php',         1),
    ('assets/loan-action',        'assets/loan-action.php',       1),
    ('assets/maintenance',        'assets/maintenance.php',       1),
    ('assets/maintenance-save',   'assets/maintenance-save.php',  1),
    ('assets/resource-save',      'assets/resource-save.php',     1),
    ('assets/resource-download',  'assets/resource-download.php', 1),
    ('assets/license-save',       'assets/license-save.php',      1),
    ('assets/license-action',     'assets/license-action.php',    1),
    ('assets/identifiers-save',   'assets/identifiers-save.php',  1),
    ('assets/labels',             'assets/labels.php',            1),
    ('assets/labels-pdf',         'assets/labels-pdf.php',        1),
    ('assets/found-reports',      'assets/found-reports.php',     1),
    ('assets/found-save',         'assets/found-save.php',        0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- -----------------------------------------------------------------------------
-- 📋 Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT must be
-- idempotent too).
-- -----------------------------------------------------------------------------
INSERT INTO `tblMigrations` (`filename`) VALUES ('159_asset_tracker.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
