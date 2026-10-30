-- =============================================================================
-- Path: _sql/231_organisation_address_per_site.sql
-- Migration 231 — the organisation's postal address, map position and
-- what3words address become EACH ORGANISATION'S OWN (#677)
--
-- @package   Portal\Sql
-- @author    MWBM Partners Ltd (t/a MWservices)
-- @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
-- @license   All Rights Reserved
-- @version   1.0.0
-- @link      https://github.com/MWBMPartners/WebMS-Intra/issues/677
-- =============================================================================
--
-- WHAT WAS WRONG
--   `_apps/admin/settings/organisation/save.php` wrote all nine `org.*` keys
--   through one helper whose INSERT put NULL in `siteID` — the row every
--   organisation on the installation uses. The page is called Organisation
--   settings and sits under Admin, Settings, Organisation, so nothing about it
--   suggested that Saving set the address for everybody.
--
--   BE PRECISE ABOUT THE HARM, because the issue overstated it and that was
--   corrected by measuring rather than reading. #677 said a second
--   organisation's pages "would display the first organisation's address and
--   invite its members to e-mail the first organisation's support address".
--   The second half was true. The FIRST HALF WAS NOT: searched on 30 October
--   2026, NOTHING outside the admin page itself reads any of the nine `org.*`
--   keys — not a member page, not a public page, not an e-mail.
--   `GivingRetention` only MENTIONS `org.address.countryCode`, in a comment
--   explaining why it deliberately does not read it.
--
--   So nothing was displayed to the wrong people. What was really wrong is
--   narrower and still worth fixing: an address stored at a level it does not
--   belong to, a page that lied about what its Save button did, and a fault
--   that would have become a real leak the moment anything did read it.
--
--   The page has also been for a GLOBAL administrator only since 11 September
--   2026, which is why no ordinary administrator could trip it. That gate was
--   put there BECAUSE the write was installation-wide; it is relaxed for the
--   address now that the address belongs to the organisation.
--
-- WHAT THIS DOES
--   Copies each non-empty installation-wide `org.*` value down to every
--   organisation that does not already have its own. The owner chose this on
--   30 October 2026 over wiping and starting again, and the reason matters:
--   almost every installation serves ONE organisation, and for those this
--   keeps exactly the address they already had. An installation serving
--   several gets the same address against each, which an administrator then
--   corrects — visible and editable, rather than silently lost.
--
--   An EMPTY value is not copied. Copying it would write nine blank rows per
--   organisation that say nothing the merge does not already say, and the
--   merge falls back to the installation's row anyway.
--
-- WHAT IT DELIBERATELY DOES NOT TOUCH
--   `portal.support.email`. The owner chose on 30 October 2026 that it becomes
--   per-organisation WITH A FALLBACK to the installation's value, and a
--   fallback only works while the installation's row is the one left standing.
--   Copying it down would break the very thing that was asked for: changing
--   the installation's support address would then reach nobody, because every
--   organisation would be carrying its own copy of the old one.
--
--   The installation-wide `org.*` rows are also LEFT IN PLACE. They are the
--   seeded defaults (all empty except `org.address.countryCode`, which is
--   'GB'), and after this migration nothing writes them — the page writes the
--   organisation's row. They remain as what a NEWLY CREATED organisation
--   inherits until somebody fills its address in, which is the right answer
--   for a country code and harmless for the rest.
--
-- SAFE TO RUN TWICE
--   `ON DUPLICATE KEY UPDATE settingID = settingID` is a no-op. Note the
--   column: an earlier draft of this paragraph said `settingKey = settingKey`,
--   which is not what the statement does and could not be — it answers
--   ERROR 1052, "Column 'settingKey' in field list is ambiguous", because the
--   derived table below has a column of that name too. Caught by the
--   independent check, which ran the sentence rather than reading it.
--
--   Every row this statement inserts has a REAL siteID, so the ordinary
--   `uq_setting_key_site` on `(settingKey, siteID)` is enough to catch a row
--   that already exists for that organisation. `uq_setting_key_scope` on
--   `(settingKey, siteScope)` would serve as well, but it is not load-bearing
--   here and saying so would overstate it: that key exists for the
--   INSTALLATION-wide rows, which this statement never writes, because MySQL
--   never treats one empty value as equal to another (see migration 187).
--
--   There is no schema change here, so nothing needs guarding.
--
-- A FRESH INSTALL
--   `full_schema.sql` seeds one organisation (`default`) and seeds these keys
--   installation-wide with empty values apart from the country code, so the
--   folded-in copy of this statement moves exactly one row: that organisation
--   gets its own `org.address.countryCode` of 'GB'. The statement is identical
--   in both places, so an upgraded database and a fresh one end up the same,
--   which is what the end-to-end migration harness requires.
-- =============================================================================

-- 🏠 Give every organisation its own copy of whatever address the
--    installation currently holds.
--
--    WHY THE WHOLE SELECT SITS INSIDE A DERIVED TABLE. Written the obvious way,
--    with `CROSS JOIN tblSites AS s` as the last thing before the duplicate
--    clause, MySQL answers ERROR 1064 — because `CROSS JOIN ... ON` is valid
--    JOIN syntax, so it reads `ON DUPLICATE` as the join's own condition and
--    then chokes on `KEY UPDATE`. Measured on MySQL 8.0.36; it is not something
--    `php -l` or any check here could have caught. What fixes it is that the
--    OUTER query then has no join at all for `ON` to attach to — not the
--    `) AS src` token itself, which is only where that becomes true. The
--    comma-join alternative does not help: it moves the error to ERROR 1052
--    on `settingID`.
INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`)
SELECT `src`.`siteID`,
       `src`.`settingKey`,
       `src`.`settingValue`,
       `src`.`defaultValue`,
       `src`.`isSensitive`
  FROM (
           SELECT `s`.`siteID`      AS `siteID`,
                  `inst`.`settingKey`   AS `settingKey`,
                  `inst`.`settingValue` AS `settingValue`,
                  `inst`.`defaultValue` AS `defaultValue`,
                  `inst`.`isSensitive`  AS `isSensitive`
             FROM (
                      SELECT `settingKey`, `settingValue`, `defaultValue`, `isSensitive`
                        FROM `tblSettings`
                       WHERE `siteID` IS NULL
                         AND `settingKey` IN (
                             'org.address.line1',
                             'org.address.line2',
                             'org.address.city',
                             'org.address.region',
                             'org.address.postcode',
                             'org.address.countryCode',
                             'org.latitude',
                             'org.longitude',
                             'org.what3words'
                         )
                         AND `settingValue` <> ''
                  ) AS `inst`
            CROSS JOIN `tblSites` AS `s`
       ) AS `src`
-- `settingID`, not `settingKey`, and the REASON matters because the first
-- version of this comment stated a false one. It claimed a table-qualified
-- left side was also ERROR 1064. It is not: measured on MySQL 8.0.36,
-- `ON DUPLICATE KEY UPDATE `tblSettings`.`settingKey` = …` is accepted. The
-- 1064 in that earlier draft came from the CROSS JOIN described above, not
-- from the qualification, and the two were run together and confused.
--
-- The real reason is simply ambiguity: unqualified `settingKey` matches a
-- column of the derived table as well, which is ERROR 1052. `settingID`
-- exists only on the target table, so it needs no qualifying and cannot be
-- ambiguous, and setting it to itself is a true no-op. That is what makes the
-- migration safe to run twice AND what protects an organisation that has
-- already set its own value from being overwritten.
ON DUPLICATE KEY UPDATE `settingID` = `settingID`;

-- 📋 Record that this migration has run.
INSERT INTO `tblMigrations` (`filename`) VALUES ('231_organisation_address_per_site.sql')
    ON DUPLICATE KEY UPDATE `filename` = `filename`;
