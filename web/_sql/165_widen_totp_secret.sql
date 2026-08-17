-- =============================================================================
-- 165_widen_totp_secret.sql — widen tblUsers.totpSecret for encrypted storage
-- -----------------------------------------------------------------------------
-- TOTP two-factor setup stores the shared secret ENCRYPTED via
-- encrypt_setting() (libsodium sodium_crypto_secretbox: 24-byte nonce +
-- ciphertext + 16-byte MAC, base64-encoded). For a standard TOTP secret that
-- output is ~96 base64 characters — but tblUsers.totpSecret was created
-- VARCHAR(64) (migration 032, back when 2FA was never actually functional
-- because Auth::encrypt()/decrypt() didn't exist — see the gap-fix that added
-- them). With bootstrap.php's MYSQLI_REPORT_STRICT, an INSERT/UPDATE of the
-- 96-char ciphertext into a 64-char column throws "Data too long for column"
-- rather than silently truncating, so 2FA enrolment would fail at the DB write
-- the moment the encrypt/decrypt wrappers made it that far.
--
-- Widen to VARCHAR(255) — generous headroom over the ~96-char current output
-- (covers future key rotation / a longer secret), consistent with how other
-- encrypted values are stored in this schema.
--
-- Portable DDL (DEV_NOTES.md -> "Portable DDL convention (MySQL 8.0 ∩
-- MariaDB)"): a plain MODIFY COLUMN would replay fine, but it is guarded on
-- information_schema.CHARACTER_MAXIMUM_LENGTH so a replay on an already-widened
-- schema is a strict no-op, matching the house guard idiom (migrations 037,
-- 112, 138). Production is MySQL 8.0 — no MariaDB-only `IF [NOT] EXISTS` on
-- ALTER (that is ERROR 1064 on MySQL 8).
--
-- @link https://github.com/MWBMPartners/WebMS-Intra
-- =============================================================================

SET @needs_widen := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblUsers'
      AND COLUMN_NAME  = 'totpSecret'
      AND CHARACTER_MAXIMUM_LENGTH < 255
);
SET @sql := IF(@needs_widen = 1,
    'ALTER TABLE `tblUsers` MODIFY COLUMN `totpSecret` VARCHAR(255) DEFAULT NULL COMMENT ''Encrypted TOTP shared secret (libsodium ciphertext via encrypt_setting(); widened from 64 in migration 165)''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 📋 Self-record this migration (installer replays every numbered migration
-- after full_schema.sql, ignoring tblMigrations — this INSERT is idempotent).
-- -----------------------------------------------------------------------------
INSERT INTO `tblMigrations` (`filename`) VALUES ('165_widen_totp_secret.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
