-- =============================================================================
-- Migration 059: Fix api.expenses.delete.enabled isSensitive flag
-- =============================================================================
-- The setting was seeded with `isSensitive = 1` but the value is the
-- literal boolean flag 'false', not a credential. Sensitive=1 makes the
-- bootstrap settings loader try to libsodium-decrypt the value on every
-- request, which wastes cycles and (depending on the seed timing) can
-- produce a "bad ciphertext" warning. Flipping to 0 is the safe fix.
--
-- The value itself is preserved.
--
-- @see https://github.com/MWBMPartners/WebMS-Intra/issues/207
-- =============================================================================

UPDATE `tblSettings`
SET    `isSensitive` = 0
WHERE  `settingKey` = 'api.expenses.delete.enabled'
  AND  `isSensitive` = 1;
