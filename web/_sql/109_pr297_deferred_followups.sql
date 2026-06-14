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
--    REPLACE() is idempotent — re-running on a DB that's already been
--    migrated leaves the rows unchanged.
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
-- Belt-and-braces approach: list every known camelCase key explicitly so
-- the rename is deterministic + auditable. Add new aliases here if more
-- `prayerRequests.*` keys are discovered later.
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
