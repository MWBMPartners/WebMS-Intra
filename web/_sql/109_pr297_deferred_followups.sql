-- =============================================================================
-- Migration 109: PR #297 deferred follow-ups (#307 + #312)
-- =============================================================================
-- Two small follow-ups deferred from the multi-brand product layer PR (#297)
-- per the "for consideration" issues filed alongside that PR.
--
-- 1️⃣ #307 — openapi.json brand-aware controller.
--    The static `web/public_html/openapi.json` is replaced by
--    `web/public_html/openapi.php`, which loads the spec body from
--    `web/_core/api-spec.json` and rewrites `info.title` + `info.contact.name`
--    to the active brand at request time. Mirror of the `manifest.json` →
--    `manifest.php` pattern shipped in migration 108.
--
-- 2️⃣ #312 — prayerRequests.* → prayer-requests.* setting-key naming.
--    The original Prayer Requests app (PR #129) used camelCase for its
--    `tblSettings` keys; every other app uses the kebab-case slug. This
--    migration renames the rows. All handler reads were updated in the
--    same PR as this migration (see related Edit calls on
--    web/_apps/prayer-requests/*.php).
--    REPLACE() itself is idempotent — re-running on a database that has
--    already been migrated leaves the rows unchanged. The FILE was not,
--    though: migration 039 seeds the old camelCase names and
--    full_schema.sql seeds the new kebab-case ones, so on a fresh install
--    both exist and the rename collided, silently creating duplicate rows.
--    A delete step was added in September 2026 to remove any old-name row
--    whose new-name twin already exists — see the comment on it below.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/307
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/312
-- =============================================================================

-- 1️⃣ -------------------------------------------------------------------------
-- Route the public URL `/openapi.json` to the new PHP controller.
-- The static file deletion happens in the PR's filesystem changes; this
-- INSERT makes the router pick up the request after Apache falls through.
-- ----------------------------------------------------------------------------
INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('openapi.json', 'openapi.php', 0)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`);

-- 2️⃣ -------------------------------------------------------------------------
-- Standardise `prayerRequests.*` → `prayer-requests.*` setting keys.
-- ----------------------------------------------------------------------------
-- ⚠️ FIRST, drop any old-name row whose new-name twin already exists.
--
--    Without this the rename below collides. Migration 039 seeds the old
--    camelCase names, and `full_schema.sql` seeds the new kebab-case names —
--    so on a fresh install BOTH exist by the time this file runs, and renaming
--    the old one produces a second row with a name that is already taken.
--
--    That collision used to pass unnoticed, because the table's "no two rows
--    may be the same" rule could not see duplicates among portal-wide settings
--    (their site is left empty, and MySQL never treats one empty value as
--    equal to another). Every install has quietly carried duplicate
--    prayer-request settings ever since. Migration 187 closes that hole, which
--    turns the collision into a hard error — so it has to be prevented here,
--    in the file that causes it.
--
--    `<=>` is MySQL's "equal, and treat two empties as equal" comparison. It
--    is needed because a portal-wide setting has no site number, so a plain
--    `=` would never match one old row to its new twin.
--
--    BEFORE dropping the old row, its value is carried across when the old row
--    looks edited and the new one does not. On a database that is catching up,
--    an administrator may have changed the setting under its OLD name, while
--    the new name has only just been seeded with a default. Deleting the old
--    row without looking would throw that choice away.
--
--    "Looks edited" means the value differs from that row's own recorded
--    default. It is not perfect, but it is the only evidence available, and it
--    errs towards keeping what somebody chose.

UPDATE `tblSettings` AS `newName`
 INNER JOIN `tblSettings` AS `oldName`
    ON `oldName`.`settingKey` = CONCAT('prayerRequests.', SUBSTRING(`newName`.`settingKey`, 17))
   AND `oldName`.`siteID` <=> `newName`.`siteID`
   SET `newName`.`settingValue` = `oldName`.`settingValue`
 WHERE `newName`.`settingKey` LIKE 'prayer-requests.%'
   AND (CAST(`oldName`.`settingValue` AS BINARY) <=> CAST(`oldName`.`defaultValue` AS BINARY)) = 0
   AND (CAST(`newName`.`settingValue` AS BINARY) <=> CAST(`newName`.`defaultValue` AS BINARY)) = 1
   -- If there is more than one old-name row for the same setting (possible
   -- before migration 187 stopped duplicates forming), take the newest of the
   -- EDITED ones. "Edited" has to be part of this choice, not just a condition
   -- outside it: pick the newest row overall and it might be an untouched seed
   -- sitting above a genuinely edited older row, in which case this whole
   -- statement matches nothing and the edit is lost when the old rows are
   -- deleted below.
   AND `oldName`.`settingID` = (
        SELECT MAX(`pick`.`settingID`) FROM (
            SELECT `settingID`, `settingKey`, `siteID`, `settingValue`, `defaultValue`
              FROM `tblSettings`
        ) AS `pick`
        WHERE `pick`.`settingKey` = `oldName`.`settingKey`
          AND `pick`.`siteID` <=> `oldName`.`siteID`
          AND (CAST(`pick`.`settingValue` AS BINARY) <=> CAST(`pick`.`defaultValue` AS BINARY)) = 0
   );

DELETE `oldName`
  FROM `tblSettings` AS `oldName`
 INNER JOIN `tblSettings` AS `newName`
    ON `newName`.`settingKey` = REPLACE(`oldName`.`settingKey`, 'prayerRequests.', 'prayer-requests.')
   AND `newName`.`siteID` <=> `oldName`.`siteID`
 WHERE `oldName`.`settingKey` LIKE 'prayerRequests.%';

-- Belt-and-braces approach: list every known camelCase key explicitly so
-- the rename is deterministic + auditable. Add new aliases here if more
-- `prayerRequests.*` keys are discovered later.
--
-- Anything left after the delete above has no new-name twin, so renaming it
-- is safe and preserves the administrator's value.
UPDATE `tblSettings`
   SET `settingKey` = REPLACE(`settingKey`, 'prayerRequests.', 'prayer-requests.')
 WHERE `settingKey` IN (
    'prayerRequests.enabled',
    'prayerRequests.displayName',
    'prayerRequests.displayIcon',
    'prayerRequests.allowTestimony',
    'prayerRequests.allowCongregationFeed',
    'prayerRequests.allowAnonymous',
    'prayerRequests.moderationRequired',
    'prayerRequests.brandColor'
 );

-- 🛟 Safety net for any other prayerRequests.* keys we missed in the
--    explicit list above. The pattern match is anchored so it only
--    touches keys that start with the literal prefix.
UPDATE `tblSettings`
   SET `settingKey` = REPLACE(`settingKey`, 'prayerRequests.', 'prayer-requests.')
 WHERE `settingKey` LIKE 'prayerRequests.%';
