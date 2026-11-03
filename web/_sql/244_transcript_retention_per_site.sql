-- =============================================================================
-- Migration 244: an organisation can choose its own transcript period (#722)
-- =============================================================================
-- Two addresses, and nothing else. No table changes, no column changes, and no
-- new setting: `transcription.retentionDays` has been seeded installation-wide
-- since migration 239 (#714), and an organisation's own figure is an ordinary
-- `tblSettings` row with a real `siteID`, written by the page below.
--
--   admin/settings/transcription        the page
--   admin/settings/transcription/save   its form target
--
-- -----------------------------------------------------------------------------
-- WHY THERE WAS NO WAY TO SET IT, WHICH IS THE WHOLE OF #722
-- -----------------------------------------------------------------------------
-- `Portal\Core\TranscriptRetention::daysForSite()` has always HONOURED a
-- per-organisation row and held it to the installation's ceiling, because such
-- a row can exist without a page to write it: somebody can put one in by hand,
-- or a whole-database restore can bring one back. A save-time cap alone is not
-- a cap (#668).
--
-- But nothing could WRITE one. The ordinary settings editor at `/settings`
-- refuses to ADD a per-organisation row for a key the installation already
-- holds (only `MailQueue::NUMBERS` is exempt), and the transcription admin page
-- writes `siteID IS NULL` rows only, because one API key pays for every
-- organisation. So the third tier was reachable and unsettable.
--
-- The first version of that class carried three public methods for a page that
-- did not exist, under a docblock saying "an organisation may tighten it" that
-- nothing could achieve. They were removed and the gap recorded as this issue
-- rather than left quiet — #687's lesson: a public method nobody calls, with an
-- authoritative docblock, reads as a feature.
--
-- -----------------------------------------------------------------------------
-- WHY A PAGE OF ITS OWN RATHER THAN A FIELD ON THE TRANSCRIPTION PAGE
-- -----------------------------------------------------------------------------
-- The two are set by different people. Admin, Transcription is where the
-- installation's own figure lives and its fields are written installation-wide;
-- putting a per-organisation field among them is how #674 happens — a form that
-- saves at one level while prefilling from another. `/admin/settings/attendance`
-- exists for the same reason and is the pattern this follows.
--
-- -----------------------------------------------------------------------------
-- WHAT A WEB-ROOT FOLDER WOULD DO TO THIS, AND WHY IT DOES NOT
-- -----------------------------------------------------------------------------
-- A real file or folder in `web/public_html/` silently beats a seeded address
-- of the same name, because `.htaccess` hands a request to the portal only when
-- nothing on disk answers it. There is no `admin` folder in the web root (it was
-- removed for #483, which had made the whole Admin area unreachable), and
-- `tools/audit-checks/check_webroot_shadowing.py` compares every seeded address
-- against what is really there on every pull request.
--
-- -----------------------------------------------------------------------------
-- SAFE TO RUN TWICE
-- -----------------------------------------------------------------------------
-- `ON DUPLICATE KEY UPDATE` on the unique key over `routeKey`, so a second run
-- rewrites the same two rows with the same values and changes nothing.
--
-- @link https://github.com/MWBMPartners/WebMS-Intra/issues/722
-- =============================================================================

INSERT INTO `tblRoutes` (`routeKey`, `targetFile`, `isProtected`) VALUES
    ('admin/settings/transcription',      'admin/settings/transcription/index.php', 1),
    ('admin/settings/transcription/save', 'admin/settings/transcription/save.php',  1)
ON DUPLICATE KEY UPDATE `targetFile` = VALUES(`targetFile`), `isProtected` = VALUES(`isProtected`);

INSERT INTO `tblMigrations` (`filename`) VALUES ('244_transcript_retention_per_site.sql')
ON DUPLICATE KEY UPDATE `filename` = `filename`;
