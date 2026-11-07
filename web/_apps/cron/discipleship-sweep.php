<?php
// Path: _apps/cron/discipleship-sweep.php
/**
 * -----------------------------------------------------------------------------
 * Cron — Discipleship auto-completion sweep 🧭 (#303 Phase 2)
 * -----------------------------------------------------------------------------
 * Optional endpoint for freshness without any page views — the auto-sweep
 * already runs lazily on every discipleship page view
 * (`Discipleship::autoSweep()`), so this cron is a convenience, not a
 * dependency. Cloned from `cron/event-reminders.php`'s gate: authenticates
 * via `?key=<discipleship.cron_token>` (constant-time compare), 403s when
 * the stored token is empty (same semantics as `reminders.cron_token`).
 *
 * Loops every distinct ACTIVE organisation that owns at least one active pathway and
 * runs one site-wide `Discipleship::autoSweep()` per site whose Discipleship
 * switch -- its own row, else the installation's -- is on, printing plain
 * text counts.
 *
 * @package   Portal\App\Cron
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/303
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Discipleship;
use Portal\Core\ScheduledJobs;
use Portal\Core\Settings;

// 🔑 Token gate (constant-time compare) — mirrors reminders.cron_token.
$incoming = (string) ($_GET['key'] ?? '');
$expected = (string) (Settings::get('discipleship.cron_token', '') ?? '');
if ($expected === '' || hash_equals($expected, $incoming) === false) {
    http_response_code(403);
    exit('Forbidden');
}

// ⏰ The token was accepted, so this really is the scheduler
//    calling. Record that the job began (#775). AFTER the gate
//    on purpose: a request with the wrong token must never be
//    able to make a job that has stopped look alive.
ScheduledJobs::began(App::db(), 'cron/discipleship-sweep');

header('Content-Type: text/plain; charset=utf-8');

$db = App::db();

// 🌍 Every distinct ACTIVE organisation that owns at least one active pathway.
//
// 🏢 An organisation switched off (`tblSites.isActive = 0`) is not swept
//    (#819 round 1). It used to be, because this read only `tblPathways`, so a
//    portal could go on writing permanent progress rows for an organisation
//    nobody can sign in to. That also made the "Check this portal" page
//    disagree with the job: `PortalCheck::featureInUse()` counts only ACTIVE
//    organisations, so with every active organisation off it called the job
//    "not needed" while the job was still writing rows. `cron/giving-statements`
//    skips such an organisation too (through `Site::forceContext()`).
$siteIds = [];
$stmt = $db->prepare(
    'SELECT DISTINCT `p`.`siteID` FROM `tblPathways` AS `p` '
    . 'JOIN `tblSites` AS `s` ON `s`.`siteID` = `p`.`siteID` AND `s`.`isActive` = 1 '
    . 'WHERE `p`.`isActive` = 1'
);
if ($stmt !== false) {
    $stmt->execute();
    $result = $stmt->get_result();
    while (($row = $result->fetch_assoc()) !== null) {
        $siteIds[] = (int) $row['siteID'];
    }
    $stmt->close();
}

// 🎛️ WHETHER DISCIPLESHIP IS ON IS DECIDED PER ORGANISATION, inside the
//    loop (#819). This used to be ONE question asked before it --
//    `Discipleship::isEnabled()`, which reads the merged settings of
//    whichever organisation the scheduler's request lands on -- and the
//    answer was then applied to EVERY organisation. So an organisation that
//    had switched Discipleship off was swept whenever the request's
//    organisation had it on (writing permanent progress rows for a feature
//    it turned off), and an organisation that had it on got nothing whenever
//    the request's organisation had it off. Each organisation is now asked
//    about itself (`Discipleship::isEnabledFor()`: its own row, else the
//    installation-wide one), and one that is off is skipped.
//
//    That is the same rule as Admin, Apps and the pages FOR EVERY ROW A PAGE
//    CAN WRITE. It is not the same for one hand-written shape: this asks the
//    database for the key, and the key column ignores capitals and trailing
//    spaces (`utf8mb4_unicode_ci`), while a page's own settings are matched
//    by exact name. So a row typed in by hand as `Discipleship.Enabled` or
//    `discipleship.enabled ` counts here and not on the organisation's pages
//    -- measured on MySQL and MariaDB in #819's round-2 check. No page writes
//    such a key; the Settings and Apps pages write the exact name.
$stats    = [];
$skipped  = 0;
foreach ($siteIds as $siteId) {
    if (Discipleship::isEnabledFor($siteId) === false) {
        $skipped++;
        continue;
    }
    $stats[$siteId] = Discipleship::autoSweep($siteId);
}

// 🚫 Organisations had pathways and EVERY one has Discipleship off: the job did
//    what it was told, which is nothing, and records that as `refused`. The
//    words say exactly that much and no more: an organisation with NO pathway
//    is never asked, so "Discipleship disabled" -- the old words -- would
//    overstate it for an organisation that has the switch on and simply has
//    nothing to sweep yet. (With no active pathway anywhere there is nothing
//    to sweep and nothing to switch off, so that case falls through to the
//    ordinary finish below with a count of 0, recorded as `ok`. Before #819
//    it said "Discipleship disabled" whenever the REQUEST's organisation had
//    the switch off -- a statement about one organisation, made on behalf of
//    all of them.)
if ($siteIds !== [] && $stats === []) {
    echo 'Discipleship is switched off for every active organisation that has an active pathway, so nothing was swept';
    // ⏰ A FINISH, because discipleship is switched off (#775). This path is the job
    //    doing what it was told, not failing to run, and a start
    //    with no finish would report it as having died part-way
    //    — a job working as configured read as broken, for ever.
    ScheduledJobs::finished(App::db(), 'cron/discipleship-sweep', ScheduledJobs::OUTCOME_REFUSED);
    exit();
}

// ⏰ THE WORK IS DONE, so record the finish (#775). A run that
//    began and never reached here leaves `finishedAtUtc` empty,
//    which is how a job dying part-way is told apart from one
//    that is not being called at all.
ScheduledJobs::finished(App::db(), 'cron/discipleship-sweep');


echo 'OK ' . json_encode(['sitesSwept' => count($stats), 'sitesSwitchedOff' => $skipped, 'inserted' => $stats]);
