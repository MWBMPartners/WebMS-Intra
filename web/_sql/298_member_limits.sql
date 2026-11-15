-- =============================================================================
-- 298_member_limits.sql — rota swap request limits and the online gift ceiling
-- (#989, #992; the owner, 12 November 2026 — D-171, D-172)
-- =============================================================================
-- WHAT THIS DOES
--   1. Adds `tblRotaSwapRequest.createdAtUtc`: the moment a request was made,
--      in UTC. The per-day limit counts a member's requests since the start of
--      the ORGANISATION's own day, and the existing `createdAt` is a reading
--      of the database server's clock in whatever zone its session uses, so it
--      cannot be compared with that moment. Requests that already exist are
--      left EMPTY rather than guessed at: the count leaves them out, which on
--      the day of the upgrade only can let a member make a few more requests
--      than the limit. Converting `createdAt` would need the server's zone at
--      the time each row was written, which nothing recorded.
--   2. Seeds three LIMITS installation-wide at their built-in defaults, so
--      nothing changes until somebody chooses:
--        rota.swap.maxOpenPerDuty    1        open requests waiting per duty
--        rota.swap.maxRequestsPerDay 10       requests per member per day
--        giving.online.maxGift       1000000  hundredths: 10,000.00, the
--                                             figure that was fixed in
--                                             payments/checkout.php
--      An organisation may hold itself LOWER on Admin, Settings, Member
--      limits; never higher (#668).
--   3. Registers that page's two addresses.
--
-- Safe to run twice: the column is added only when missing (asked of
-- information_schema, because MySQL 8.0 has no ADD COLUMN IF NOT EXISTS), and
-- every insert is ON DUPLICATE KEY UPDATE. An installation that has already
-- chosen a figure keeps it: only `defaultValue` is touched.
-- =============================================================================

SET @c := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME`   = 'tblRotaSwapRequest'
      AND `COLUMN_NAME`  = 'createdAtUtc'
);
SET @sql := IF(@c = 0,
    'ALTER TABLE `tblRotaSwapRequest` ADD COLUMN `createdAtUtc` DATETIME DEFAULT NULL COMMENT ''A MOMENT IN UTC (#989, migration 298): when the request was made, written with UTC_TIMESTAMP() by RotaSwaps::request(). The per-day limit (D-171) counts a member''''s requests since the start of the organisation''''s own day by this column. Empty for a request made before migration 298, which the count leaves out'' AFTER `createdAt`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES
    (NULL, 'rota.swap.maxOpenPerDuty',   '1',       '1',       0),
    (NULL, 'rota.swap.maxRequestsPerDay', '10',     '10',      0),
    (NULL, 'giving.online.maxGift',      '1000000', '1000000', 0)
ON DUPLICATE KEY UPDATE `defaultValue` = VALUES(`defaultValue`);

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/settings/member-limits',      'admin/settings/member-limits/index.php', 1),
    ('admin/settings/member-limits/save', 'admin/settings/member-limits/save.php',  1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`), `isProtected` = VALUES(`isProtected`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('298_member_limits.sql')
    ON DUPLICATE KEY UPDATE `filename` = `filename`;
