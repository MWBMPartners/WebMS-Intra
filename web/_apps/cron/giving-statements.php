<?php
// Path: _apps/cron/giving-statements.php
/**
 * -----------------------------------------------------------------------------
 * Cron — Giving bulk statements sweeper 🧾⏰ (gap #4, #440)
 * -----------------------------------------------------------------------------
 * Optional companion to the interactive "Email statements" button
 * (giving/statements-email.php): sweeps every already-queued-but-unsent
 * tblGivingStatementLog row across ALL sites, up to
 * `giving.statements.batchPerRun × 4` per invocation. A treasurer never
 * has to configure this — a site with a modest donor list finishes purely
 * from the UI in a click or two; this exists for a larger site whose queue
 * would otherwise need many manual re-triggers.
 *
 * TOKEN GATE (security item — plan §3.7 / §7 item 1): `?key=` vs
 * `giving.cron_token`, constant-time `hash_equals()`, empty stored token
 * ⇒ ALWAYS 403 — migration 172 seeds `giving.cron_token` empty +
 * isSensitive=1, so this endpoint is inert until an admin sets a real
 * token. Clone of cron/venue-reminders.php's gate shape.
 *
 * The queue selector itself is inherently site+period-scoped row-by-row —
 * every row already carries its own siteID/donorID/pdfPath, so there is
 * no cross-tenant ambiguity in the SELECT. `Site::forceContext()` is still
 * called per row before sending purely so `Logger::errorPlatform()`
 * (called internally by `Giving::sendStatementEmail()` on a failure)
 * attributes the error row to the CORRECT site rather than whatever site
 * the cron request happened to bootstrap against — same rationale as
 * cron/venue-reminders.php's own per-site `Site::forceContext()` call.
 * Per-site settings inside `Giving::sendStatementEmail()` are read via
 * `App::settingForSite()`, never the frozen `$SETTINGS` snapshot, for the
 * same cross-site-loop reason.
 *
 * NO context reset after the loop — mirrors cron/venue-reminders.php and
 * cron/asset-reminders.php exactly; the process exits immediately after.
 *
 * @package   Portal\App\Cron
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/440
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Giving;
use Portal\Core\Logger;
use Portal\Core\Mailer;
use Portal\Core\ScheduledJobs;
use Portal\Core\Settings;
use Portal\Core\Site;
use Portal\Core\TimeLimit;

// -----------------------------------------------------------------------------
// 🔑 Token gate (constant-time compare). An empty stored token ALWAYS 403s
// — cloned from cron/venue-reminders.php.
// -----------------------------------------------------------------------------
$incoming = (string) ($_GET['key'] ?? '');
$expected = (string) (Settings::get('giving.cron_token', '') ?? '');
if ($expected === '' || hash_equals($expected, $incoming) === false) {
    http_response_code(403);
    exit('Forbidden');
}

// ⏰ The token was accepted, so this really is the scheduler
//    calling. Record that the job began (#775). AFTER the gate
//    on purpose: a request with the wrong token must never be
//    able to make a job that has stopped look alive.
ScheduledJobs::began(App::db(), 'cron/giving-statements');

header('Content-Type: text/plain; charset=utf-8');

$db  = App::db();
$cap = (int) (Settings::get('giving.statements.batchPerRun', '25') ?? 25) * 4;
if ($cap < 1) {
    $cap = 100;
}

TimeLimit::atLeast(300);

// 🧾 WHICH ROWS: Giving::STATEMENTS_THE_JOB_SENDS, the one copy of this
//    selection, which Check this portal also counts by (#820). It includes
//    "the organisation is active": rows of a switched-off organisation used
//    to be taken here and then skipped below on every run, each using up one
//    of this run's LIMIT places ahead of real work. A row whose send FAILED is
//    not taken (failures are retried by hand, from the statements page).
$pending = [];
$stmt = $db->prepare(
    'SELECT `logID`, `siteID`, `donorID`, `pdfPath`, `periodKey`, `fromDate`, `toDate` FROM `tblGivingStatementLog` '
    . 'WHERE ' . Giving::STATEMENTS_THE_JOB_SENDS . ' '
    . 'ORDER BY `queuedAt` LIMIT ?'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $cap);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $pending[] = $r;
    }
    $stmt->close();
}

$perSite = [];
$totalSent   = 0;
$totalFailed = 0;

foreach ($pending as $row) {
    $siteId = (int) $row['siteID'];

    try {
        Site::forceContext($siteId);
    } catch (\Throwable $e) {
        Logger::errorPlatform(
            'GivingStatementsCron',
            'Warning',
            'GIVING_STATEMENTS_CRON_SITE_CONTEXT_FAIL',
            $e->getMessage(),
            'siteID=' . $siteId
        );
        continue;
    }

    if (isset($perSite[$siteId]) === false) {
        $perSite[$siteId] = ['sent' => 0, 'failed' => 0, 'waiting' => 0];
    }

    $logRow = [
        'logID'    => (int) $row['logID'],
        'siteID'   => $siteId,
        'donorID'  => (int) $row['donorID'],
        'pdfPath'  => (string) $row['pdfPath'],
        'fromDate' => (string) $row['fromDate'],
        'toDate'   => (string) $row['toDate'],
    ];

    if (Giving::sendStatementEmail($logRow) === true) {
        // "true" is sent OR kept in the mail queue to be sent (#592). Only
        // the first is counted as sent; Mailer says which it was.
        if (Mailer::lastIsWaiting() === true) {
            $perSite[$siteId]['waiting']++;
        } else {
            $perSite[$siteId]['sent']++;
            $totalSent++;
        }
    } else {
        $perSite[$siteId]['failed']++;
        $totalFailed++;
    }
}
// 🚧 NO Site::forceContext() reset after the loop — mirrors
// cron/venue-reminders.php exactly; the process exits immediately after.

echo "Giving statements sweep — " . count($pending) . " queued row(s) processed across " . count($perSite) . " site(s)\n";
foreach ($perSite as $siteId => $t) {
    echo sprintf("site %d: sent=%d waiting=%d failed=%d\n", $siteId, $t['sent'], $t['waiting'], $t['failed']);
}
// ⏰ THE WORK IS DONE, so record the finish (#775). A run that
//    began and never reached here leaves `finishedAtUtc` empty,
//    which is how a job dying part-way is told apart from one
//    that is not being called at all.
ScheduledJobs::finished(App::db(), 'cron/giving-statements');

echo sprintf("TOTAL: sent=%d waiting=%d failed=%d\n", $totalSent, Mailer::keptThisRequest(), $totalFailed);
