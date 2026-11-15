-- =============================================================================
-- Migration 295: how long a network address is kept, and two true comments (#1019)
-- =============================================================================
-- What this changes:
--
--   A. Seeds `privacy.networkAddressDays` (365) installation-wide: after how
--      many days the nightly clear-out empties a network (IP) address from the
--      places that had no keeping period (the owner's decision D-178). Read
--      and written ONLY through Portal\Core\NetworkAddressRetention, with the
--      activity log's rules (D-117 to D-119): 0 is "keep for ever", an
--      organisation may hold a shorter figure of its own, never a longer one.
--      ON DUPLICATE KEY changes only the default, so a figure somebody has
--      already chosen is never overwritten by a second run.
--
--   B. The COMMENT on `tblAssetAudit`.`ipHash`. It said "public/anonymous
--      actions only", which was untrue: `AssetRegister::audit()` writes it on
--      every row, signed-in changes included. A comment that understates what
--      a column holds is how a person decides an erasure need not reach it.
--
--   C. A COMMENT on `tblRecordingPlay`.`ipHash`, which had none: since #1019
--      the recording page writes a SALTED fingerprint (AddressFingerprint).
--      Before, it wrote an UNSALTED SHA-256 of the raw address, which can be
--      walked back to the address in minutes. NO ROW IS CHANGED: those older
--      values cannot be turned into the new form (the address is not kept),
--      nothing reads the column, and the clear-out in A empties each one once
--      it is older than the period. No customer installation existed before
--      this change (D-135), so no such row is expected outside test databases.
--
-- HOW IT IS WRITTEN. Safe to run twice: A is an upsert that keeps the stored
-- value, B and C restate each column's whole definition with only the comment
-- changed. No `IF EXISTS` on a column (MySQL 8 rejects MariaDB's form; see
-- .claude/rules/database.md). Both tables exist on every database this runs on
-- (migration 159 made `tblAssetAudit`, and `tblRecordingPlay` is older still).
-- =============================================================================

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'privacy.networkAddressDays', '365', '365', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

ALTER TABLE `tblAssetAudit`
    MODIFY COLUMN `ipHash` CHAR(64) DEFAULT NULL COMMENT 'Salted SHA-256 of the network address each change came from, written on EVERY row by AssetRegister::audit(), signed-in changes included. Emptied after privacy.networkAddressDays and by an erasure of the actor (#1019, D-164, D-178; migration 295)';

ALTER TABLE `tblRecordingPlay`
    MODIFY COLUMN `ipHash` CHAR(64) DEFAULT NULL COMMENT 'Salted fingerprint of the network address a play came from, made by AddressFingerprint::of() since #1019 (before that an UNSALTED SHA-256 of the raw address). Emptied after privacy.networkAddressDays (D-178; migration 295)';

INSERT INTO `tblMigrations` (`filename`) VALUES ('295_network_address_period.sql')
    ON DUPLICATE KEY UPDATE `filename` = `filename`;
