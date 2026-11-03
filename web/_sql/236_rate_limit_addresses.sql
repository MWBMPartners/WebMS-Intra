-- =============================================================================
-- Migration 236: the live chat's throttle log keeps a fingerprint, not an
--                address (#712)
-- =============================================================================
-- One renamed and retyped column, one renamed index, and the existing rows
-- removed.
--
--   tblLiveRateLimits.senderIP VARCHAR(45) -> senderFingerprint CHAR(64)
--
-- -----------------------------------------------------------------------------
-- WHAT WAS WRONG BEFORE
-- -----------------------------------------------------------------------------
-- `tblLiveRateLimits` recorded the viewer's network address UNCHANGED, and
-- NOTHING ever removed a row. Measured rather than assumed: there is no
-- `DELETE FROM tblLiveRateLimits` anywhere under `web/`, the table has no
-- foreign key that could cascade into it, no scheduled job touches it, and no
-- admin page deletes from it. So every installation kept a growing record of
-- which addresses had used the live chat, for ever, outside every retention
-- rule the portal has -- under an index named `idx_ratelimit_prune`, for a
-- prune that did not exist.
--
-- `LiveChat::recordSend()`'s own docblock claimed otherwise: "pruning is done
-- incidentally (next call rolls forward via the time window)". The window only
-- narrows the COUNT the throttle reads; it has never deleted anything. That is
-- the shape this project has been bitten by before -- a comment stating the
-- guarantee the code forgot to implement.
--
-- The session token stored beside the address is also stored next to a typed
-- display name on `tblLiveChatMessages`, so the two could be joined: an address
-- and a name somebody typed.
--
-- -----------------------------------------------------------------------------
-- WHY A FINGERPRINT WORKS, AND WHY IT IS NOT CALLED ANONYMISATION
-- -----------------------------------------------------------------------------
-- The throttle has to recognise the same client coming back after it cycles
-- session tokens (new tab, new token, carry on flooding). For that the stored
-- value only has to be STABLE. It never has to be readable, and nothing
-- displays it -- so a SHA-256 does the job exactly as well as the address did.
--
-- It is a plain SHA-256, not salted or slow, for the reason #622 gives about
-- the activity log: the value has to be reproducible, and the point is to stop
-- the portal HOLDING addresses rather than to resist somebody who already has
-- an address and wants to confirm it. Anybody with a list of addresses can hash
-- it. That is accepted, which is why this is a fingerprint and not anonymity.
--
-- `LiveChat::senderFingerprint()` is the ONE place that decides the stored
-- form, asked by the writer and by the reader, so they cannot drift.
--
-- -----------------------------------------------------------------------------
-- WHY THE COLUMN IS RENAMED AND NOT JUST WIDENED
-- -----------------------------------------------------------------------------
-- Two reasons, and the second is the useful one.
--
-- First, a leftover raw address in a column called `senderFingerprint` would be
-- obvious, where in a column still called `senderIP` it would look normal.
--
-- Second, `tblLiveChatMessages` HAS A COLUMN OF ITS OWN CALLED `senderIP`, and
-- that one stays raw ON PURPOSE: the moderation screen shows it to an
-- administrator deciding whether to block somebody. Two columns of the same
-- name in the same feature, one to be hashed and one not, is how the wrong one
-- gets changed. After this they have different names.
--
-- The index `idx_ratelimit_ip` is renamed to `idx_ratelimit_fingerprint` for
-- the same reason: an index named for an address, on a column that no longer
-- holds one, is a small lie that outlives everybody who knew better.
--
-- -----------------------------------------------------------------------------
-- WHY THE EXISTING ROWS GO
-- -----------------------------------------------------------------------------
-- They hold raw addresses and cannot be turned into fingerprints by this
-- migration in any useful way -- and they need not be. A throttle row older
-- than twice the window can change no answer the throttle gives, so the rows
-- this deletes are, by definition, only a record of who used the chat and when.
-- Keeping them would mean keeping raw addresses in a column that says they are
-- fingerprints, which is worse than losing a throttle count.
--
-- The one visible cost, said plainly: for the few seconds of the throttle
-- window spanning the upgrade, somebody already at the limit gets their
-- allowance back. That is the whole of it.
--
-- -----------------------------------------------------------------------------
-- SAFE TO RUN TWICE
-- -----------------------------------------------------------------------------
-- Each step is guarded by `information_schema` + `PREPARE`/`EXECUTE`, the shape
-- migrations 194, 201 and 235 use. MySQL 8.0 has no `IF EXISTS` for a column or
-- an index change (that is MariaDB-only, and this schema is written to the
-- MySQL 8.0 dialect), so the guard is how this is done. On a database that has
-- already run it, each guard finds nothing and runs a harmless `SELECT 1`.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/712
-- =============================================================================

-- 1️⃣ The rows, before the column changes shape. Done first so that no raw
--    address is ever sitting in a column named `senderFingerprint`.
SET @col_is_old := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblLiveRateLimits'
      AND COLUMN_NAME  = 'senderIP'
);
SET @sql := IF(@col_is_old = 1,
    'DELETE FROM `tblLiveRateLimits`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2️⃣ The index, renamed before the column it covers.
SET @idx_is_old := (
    SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblLiveRateLimits'
      AND INDEX_NAME   = 'idx_ratelimit_ip'
);
SET @sql := IF(@idx_is_old = 1,
    'ALTER TABLE `tblLiveRateLimits` RENAME INDEX `idx_ratelimit_ip` TO `idx_ratelimit_fingerprint`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3️⃣ The column.
SET @sql := IF(@col_is_old = 1,
    'ALTER TABLE `tblLiveRateLimits` CHANGE COLUMN `senderIP` `senderFingerprint` CHAR(64) DEFAULT NULL COMMENT ''SHA-256 of the sender address, never the address itself (#712) - LiveChat::senderFingerprint() is the one place it is made''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `tblMigrations` (`filename`) VALUES ('236_rate_limit_addresses.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
