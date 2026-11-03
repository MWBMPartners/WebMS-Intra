-- =============================================================================
-- Migration 243: which roles open which door, chosen per organisation (#727)
-- =============================================================================
-- Two new tables and one installation-wide setting. Nothing existing is
-- changed, nothing is relaxed, and no row is written for any organisation —
-- which is the point: an organisation that never opens the roles page has no
-- row here, and `Portal\Core\Capabilities` then uses the role the gate was
-- hard-coded to before this existed. #499's lesson, where "fail closed" was
-- built first and locked members out.
--
-- -----------------------------------------------------------------------------
-- WHY TWO TABLES AND NOT ONE
-- -----------------------------------------------------------------------------
-- A capability is opened by a SET of roles, and holding any ONE of them is
-- enough (the owner, 3 November 2026). The set may be EMPTY, and an empty set
-- MUST be distinguishable from "this organisation has never chosen anything":
--
--   no row in tblCapabilityMap   -> as shipped, the standard role opens it
--   a row, and no role rows      -> nobody, deliberately, confirmed on the page
--
-- One table cannot say both without a sentinel row holding an empty `roleKey`
-- — and a sentinel could not carry `fk_capmaprole_role` below, which is what
-- makes a deleted role disappear from every set it was in instead of leaving a
-- door nobody can see is shut.
--
-- -----------------------------------------------------------------------------
-- EVERY PART OF EVERY UNIQUE KEY IS `NOT NULL`, AND THAT IS NOT TIDINESS
-- -----------------------------------------------------------------------------
-- #487: in MySQL and MariaDB a unique key STANDS ASIDE for any row where any
-- part of it is empty — an empty value is not even equal to itself — so a key
-- with a nullable part is a rule only for the rows that fill in every one of
-- its columns, and `ON DUPLICATE KEY UPDATE` then stops meaning "change the
-- existing row" and starts meaning "insert another one". `Capabilities::save()`
-- leans on `uq_capmap_site_cap` for exactly that, so `siteID` and
-- `capabilityKey` are both NOT NULL. `decidedByID` is OUTSIDE the key and
-- nullable on purpose: a person who changed a mapping and has since been
-- erased leaves the mapping standing with nobody named against it, which is
-- better than either keeping their number or losing the mapping.
--
-- -----------------------------------------------------------------------------
-- WHY `roleKey` AND NOT `roleID`
-- -----------------------------------------------------------------------------
-- A role's KEY never changes once the role exists (#516) and is what every
-- gate in the portal compares against; a roleID is a number that means nothing
-- outside one organisation. Storing the key means a mapping reads the same way
-- in a backup, in the audit trail and on the page. The referential integrity is
-- not given up for that: `fk_capmaprole_role` points at
-- `tblRoles(siteID, roleKey)`, which `uq_roles_site_key` already covers in
-- exactly that order — which matters because MySQL 8.4 REFUSES a foreign key
-- that points at anything other than the primary key or a unique key covering
-- exactly those columns in that order (ERROR 6125, #552). The collation is left
-- to the table default, `utf8mb4_general_ci`, because `tblRoles.roleKey`
-- declares that collation itself and a foreign key between columns of different
-- collations cannot be created at all.
--
-- -----------------------------------------------------------------------------
-- THE INSTALLATION'S REFUSAL
-- -----------------------------------------------------------------------------
-- `capabilities.locked` is an installation-wide row (siteID IS NULL) holding
-- the capability keys an organisation may NOT re-point, comma separated. It is
-- seeded EMPTY, so nothing is locked until whoever runs the installation says
-- so on the roles page. It exists because the two administrator levels may be
-- different parties (the standing rule of 29 October 2026), and the
-- conservative direction is that an organisation tailors what it is allowed to
-- tailor and the installation can refuse.
--
-- It is read in exactly ONE place, `Capabilities::installationLocks()`, and
-- written in exactly one place, `admin/roles/save.php`, for a global
-- administrator only. A seeded setting nothing reads is a lie with a Save
-- button (#671); this one is read by every draw of the page and by every save.
--
-- -----------------------------------------------------------------------------
-- SAFE TO RUN TWICE
-- -----------------------------------------------------------------------------
-- `CREATE TABLE IF NOT EXISTS` is standard in MySQL and MariaDB (it is only
-- `IF [NOT] EXISTS` on a COLUMN or an INDEX that MySQL 8 rejects, which is why
-- there is none of that here). The settings row and this migration's own record
-- both carry `ON DUPLICATE KEY UPDATE`, which matters because the installer
-- replays every numbered migration after loading `full_schema.sql`, ignoring
-- what `tblMigrations` already says.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/727
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tblCapabilityMap` (
    `mapID`         INT          NOT NULL AUTO_INCREMENT,
    `siteID`        INT          NOT NULL COMMENT 'The organisation whose choice this is. One installation may serve many, and each chooses for itself',
    `capabilityKey` VARCHAR(64)  NOT NULL COMMENT 'A key from Capabilities::CAPABILITIES, e.g. erasure.review. Written by the portal from its own list, never from a request',
    `decidedAtUtc`  DATETIME     NOT NULL COMMENT 'A MOMENT IN UTC, written with UTC_TIMESTAMP() only - never a time on the server clock, so two installations in different countries agree about the order of things',
    `decidedByID`   INT          DEFAULT NULL COMMENT 'Who last chose, or empty where that account has since been removed. Outside the unique key on purpose, so an empty value can never make the key stand aside (#487)',
    PRIMARY KEY (`mapID`),
    UNIQUE KEY `uq_capmap_site_cap` (`siteID`, `capabilityKey`),
    CONSTRAINT `fk_capmap_site` FOREIGN KEY (`siteID`) REFERENCES `tblSites`(`siteID`) ON DELETE CASCADE,
    CONSTRAINT `fk_capmap_decider` FOREIGN KEY (`decidedByID`) REFERENCES `tblUsers`(`userID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='One row per organisation per capability, saying that organisation HAS chosen which roles open it. The roles themselves are in tblCapabilityMapRole; a row here with no rows there means "nobody, deliberately" (#727)';

CREATE TABLE IF NOT EXISTS `tblCapabilityMapRole` (
    `mapRoleID` INT         NOT NULL AUTO_INCREMENT,
    `mapID`     INT         NOT NULL COMMENT 'The choice this role belongs to',
    `siteID`    INT         NOT NULL COMMENT 'The organisation, repeated here so the link to tblRoles can be made on (siteID, roleKey) - a role key only means anything inside one organisation',
    `roleKey`   VARCHAR(50) NOT NULL COMMENT 'The role that opens the capability. Holding ANY ONE of a capability''s roles is enough: any-of, never all-of',
    PRIMARY KEY (`mapRoleID`),
    UNIQUE KEY `uq_capmaprole_map_role` (`mapID`, `roleKey`),
    KEY `idx_capmaprole_site_role` (`siteID`, `roleKey`),
    CONSTRAINT `fk_capmaprole_map` FOREIGN KEY (`mapID`) REFERENCES `tblCapabilityMap`(`mapID`) ON DELETE CASCADE,
    CONSTRAINT `fk_capmaprole_role` FOREIGN KEY (`siteID`, `roleKey`) REFERENCES `tblRoles`(`siteID`, `roleKey`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Which roles open one capability for one organisation. A deleted role leaves every set it was in through fk_capmaprole_role, and the roles page refuses to delete a role that is the LAST one in a set (#727)';

-- ── The installation's refusal, seeded empty so nothing is locked ────────────
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'capabilities.locked', '', '', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('243_capability_roles.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
