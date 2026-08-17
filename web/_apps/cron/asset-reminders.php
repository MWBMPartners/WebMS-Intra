<?php
// Path: _apps/cron/asset-reminders.php
/**
 * -----------------------------------------------------------------------------
 * Cron — Asset Tracker reminder sweep 📦⏰ (#405, Phase 2 Pass 3)
 * -----------------------------------------------------------------------------
 * Endpoint expected to be called periodically (e.g. every 15-60 minutes) by
 * an external scheduler. Four reminder families, each single-shot via
 * `tblAssetReminderLog`'s `uq_astrl_ref` unique key (`refType`, `refID`,
 * `dueDate` — migration 160):
 *
 *   maintenance   — scheduled maintenance whose nextDueDate falls within
 *                   [today, today+assets.reminder_lead_days_maintenance]
 *   warranty      — assets whose warrantyExpiry falls within
 *                   [today, today+assets.reminder_lead_days_warranty]
 *   insurance     — assets whose insuranceRenewalDate falls within
 *                   [today, today+assets.reminder_lead_days_insurance]
 *   loan-overdue  — active loans whose dueDate is already in the past
 *
 * Token gate cloned from `cron/discipleship-sweep.php`/`cron/event-
 * reminders.php`: authenticates via `?key=<assets.cron_token>` (constant-
 * time compare), 403s when the stored token is empty — migration 160
 * seeds `assets.cron_token` as an EMPTY string, so this endpoint refuses
 * every request until an admin sets a real token at /admin (settings key
 * `assets.cron_token`, isSensitive=1).
 *
 * MULTI-SITE: this endpoint loops every site that owns at least one
 * non-deleted asset, calling `Site::forceContext($siteId)` before that
 * site's work — `AssetRegister::audit()`/`Logger::activity()` both read
 * `Site::id()` internally to stamp the row they write, so every log/audit
 * entry this run produces must be attributed to the site the due item
 * actually belongs to. The four `AssetRegister::listX($siteId, …)` read
 * helpers and `resolveReminderRecipients()` never depend on this ambient
 * context themselves (they take/derive `$siteId` explicitly) — see
 * `AssetRegister`'s own class-header point 12 for the full design note.
 *
 * SINGLE-SHOT DEDUPE: `sendAssetReminder()` below checks
 * `tblAssetReminderLog` FIRST (the common "not sent yet" path never even
 * attempts a duplicate send), then inserts the log row wrapped in a
 * try/catch for `\mysqli_sql_exception` — the rare case of two overlapping
 * cron runs racing past the check is caught by `uq_astrl_ref` itself and
 * treated identically to "already sent" (same duplicate-catch shape as
 * `AssetRegister::addIdentifier()`/`assignToEvent()`).
 *
 * RECIPIENTS / NO SECRETS: every family resolves recipients via
 * `AssetRegister::resolveReminderRecipients()` (owner-authority holders +
 * site admin/asset_manager fallback); loan-overdue ADDITIONALLY includes
 * the loan's own counterparty via `AssetRegister::resolveLoanCounterpartyEmail()`
 * — the ONLY family that reaches someone outside that authority/admin set,
 * per #405's own security musts. Every email body is `htmlspecialchars()`'d
 * and NEVER includes `licenseKey`, `publicToken`, or `insurancePolicyNumber`.
 *
 * HOUSEKEEPING: after the reminder sweep, each site also runs
 * `AssetRegister::persistCurrentValues()` (writes today's straight-line
 * book value — closes the "currentValuePence never written" gap flagged
 * by `computeStraightLineValue()`'s own doc, feeds the #408 value
 * dashboard), `purgeExpiredFoundReports()` (#401) and `purgeExpiredScanLog()`
 * (#410) — both provided in earlier passes but not yet wired into any
 * cron caller until now.
 *
 * @package   Portal\App\Cron
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   2.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/404
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/405
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AssetRegister;
use Portal\Core\Logger;
use Portal\Core\Mailer;
use Portal\Core\Settings;
use Portal\Core\Site;

// -----------------------------------------------------------------------------
// 🔑 Token gate (constant-time compare) — mirrors reminders.cron_token /
// discipleship.cron_token. An empty stored token ALWAYS 403s, so this
// endpoint is inert until an admin explicitly sets assets.cron_token.
// -----------------------------------------------------------------------------
$incoming = (string) ($_GET['key'] ?? '');
$expected = (string) (Settings::get('assets.cron_token', '') ?? '');
if ($expected === '' || hash_equals($expected, $incoming) === false) {
    http_response_code(403);
    exit('Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');

if ((string) Settings::get('assets.reminders_enabled', '1') !== '1') {
    echo 'Asset reminders disabled';
    exit();
}

$db = App::db();

// -----------------------------------------------------------------------------
// 🔗 Absolute /assets/item?id=… link for a reminder email. Same
// scheme+host resolution as AssetRegister::siteBaseUrl() (private to that
// class) — duplicated here rather than exposed, matching the existing
// house convention of small per-call-site scheme+host resolution (see
// Newsletter::baseUrl(), PrayerChain's own inline resolution). $_SERVER
// values are set by the webserver from the connection itself, never from
// a request body.
// -----------------------------------------------------------------------------
function assetReminderUrl(int $assetId): string
{
    $https  = (string) ($_SERVER['HTTPS'] ?? '');
    $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host . '/assets/item?id=' . $assetId;
}

/**
 * Send one reminder email to every valid address in $recipients, UNLESS
 * this exact (refType, refID, dueDate) has already been logged by an
 * earlier run — see the file header's SINGLE-SHOT DEDUPE note. On a fresh
 * send, always records the `tblAssetReminderLog` row (even when
 * $recipients resolved to zero valid addresses — recipientCount=0 is a
 * legitimate, loggable outcome, and logging it prevents the sweep from
 * retrying the same due item forever on a site with no configured
 * recipient) and an `AssetRegister::audit()` entry (actorType 'system').
 *
 * @param string[] $recipients
 *
 * @return int|null Recipients actually mailed, or NULL when this due item
 *         was already handled by an earlier run (nothing sent this call).
 */
function sendAssetReminder(
    \mysqli $db,
    int $siteId,
    string $refType,
    int $refId,
    int $assetId,
    string $dueDate,
    string $entityType,
    string $subject,
    string $bodyHtml,
    array $recipients
): ?int {
    // 🔁 Check-first — the common "not sent yet" path never even attempts
    // a duplicate write. uq_astrl_ref (below) is the authoritative
    // concurrency backstop for the rare overlapping-run race.
    $chk = $db->prepare('SELECT 1 FROM tblAssetReminderLog WHERE refType = ? AND refID = ? AND dueDate = ? LIMIT 1');
    if ($chk === false) {
        return null;
    }
    $chk->bind_param('sis', $refType, $refId, $dueDate);
    $chk->execute();
    $already = $chk->get_result()->fetch_assoc() !== null;
    $chk->close();
    if ($already === true) {
        return null;
    }

    // 📧 Send — every address independently FILTER_VALIDATE_EMAIL'd
    // (resolveReminderRecipients()/resolveLoanCounterpartyEmail() already
    // validate, but this is the actual dispatch point, so it re-checks
    // rather than trusting its caller).
    $sent = 0;
    foreach (array_unique($recipients) as $email) {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            if (Mailer::send($email, $subject, $bodyHtml) === true) {
                $sent++;
            }
        }
    }

    try {
        $ins = $db->prepare(
            'INSERT INTO tblAssetReminderLog (siteID, refType, refID, assetID, dueDate, recipientCount) '
            . 'VALUES (?, ?, ?, ?, ?, ?)'
        );
        if ($ins !== false) {
            $ins->bind_param('isiisi', $siteId, $refType, $refId, $assetId, $dueDate, $sent);
            $ins->execute();
            $ins->close();
        }
    } catch (\mysqli_sql_exception $e) {
        // 🏁 uq_astrl_ref caught a concurrent run that logged this exact
        // due item between our check above and this INSERT — treat
        // exactly like "already sent", never a fatal error. The mail
        // above may have double-sent in this narrow race window, but the
        // log row (and therefore every FUTURE run) stays single-shot.
        return null;
    }

    // 📜 Audit — action 'reminder' is deliberately NOT in the
    // ['create','update','delete'] set AssetRegister::audit() mirrors
    // into the platform-wide tblAuditTrail for; a reminder is an EVENT,
    // same convention as the 'scan'/'print' audit entries elsewhere in
    // this app. NEVER carries a secret in its meta — refType/dueDate/
    // recipientCount only.
    AssetRegister::audit(
        $entityType,
        $refId,
        $assetId,
        'reminder',
        null,
        null,
        ['refType' => $refType, 'dueDate' => $dueDate, 'recipientCount' => $sent],
        'system'
    );

    return $sent;
}

// -----------------------------------------------------------------------------
// ⚙️ Lead-day tunables — process-wide (migration 160 seeds these with
// siteID=NULL — no per-site override exists yet), read once, not
// re-fetched per site.
// -----------------------------------------------------------------------------
$leadMaintenance = (int) Settings::get('assets.reminder_lead_days_maintenance', 7);
$leadWarranty    = (int) Settings::get('assets.reminder_lead_days_warranty', 30);
$leadInsurance   = (int) Settings::get('assets.reminder_lead_days_insurance', 30);

// -----------------------------------------------------------------------------
// 🌍 Every distinct, active site that owns at least one non-deleted asset
// — mirrors cron/discipleship-sweep.php's own "only sites that actually
// have something to sweep" convention. Pre-filtering isActive=1 here
// means Site::forceContext() below should never throw for a site drawn
// from this list.
// -----------------------------------------------------------------------------
$siteIds = [];
$siteStmt = $db->prepare(
    'SELECT DISTINCT a.siteID FROM tblAssets a '
    . 'INNER JOIN tblSites s ON s.siteID = a.siteID AND s.isActive = 1 '
    . 'WHERE a.isDeleted = 0'
);
if ($siteStmt !== false) {
    $siteStmt->execute();
    $result = $siteStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $siteIds[] = (int) $row['siteID'];
    }
    $siteStmt->close();
}

// -----------------------------------------------------------------------------
// 📊 Run-wide totals (echoed as the text/plain summary at the very end).
// -----------------------------------------------------------------------------
$grandTotals = [
    'maintenance'  => ['due' => 0, 'sent' => 0, 'skipped' => 0],
    'warranty'     => ['due' => 0, 'sent' => 0, 'skipped' => 0],
    'insurance'    => ['due' => 0, 'sent' => 0, 'skipped' => 0],
    'loan-overdue' => ['due' => 0, 'sent' => 0, 'skipped' => 0],
];
$grandValuationsWritten  = 0;
$grandFoundReportsPurged = 0;
$grandScanLogPurged      = 0;

foreach ($siteIds as $siteId) {
    // 🌐 Force this site's context — see file header's MULTI-SITE note.
    // Wrapped defensively: one misbehaving site (inactive mid-run,
    // multisite toggled off, etc) must never abort the whole sweep for
    // every OTHER site.
    try {
        Site::forceContext($siteId);
    } catch (\Throwable $e) {
        Logger::errorPlatform(
            'AssetReminders',
            'Warning',
            'ASSET_REMINDERS_SITE_CONTEXT_FAIL',
            $e->getMessage(),
            'siteID=' . $siteId
        );
        continue;
    }

    // 📍 Per-site counters — logged via one Logger::activity() row per
    // site below (accurately siteID-attributed), then folded into
    // $grandTotals for the final cross-site text/plain summary.
    $siteTotals = [
        'maintenance'  => ['due' => 0, 'sent' => 0, 'skipped' => 0],
        'warranty'     => ['due' => 0, 'sent' => 0, 'skipped' => 0],
        'insurance'    => ['due' => 0, 'sent' => 0, 'skipped' => 0],
        'loan-overdue' => ['due' => 0, 'sent' => 0, 'skipped' => 0],
    ];

    // -------------------------------------------------------------------
    // 🔧 Maintenance due.
    // -------------------------------------------------------------------
    foreach (AssetRegister::listDueMaintenanceReminders($siteId, $leadMaintenance) as $m) {
        $siteTotals['maintenance']['due']++;
        $assetId   = (int) $m['assetID'];
        $dueDate   = (string) $m['nextDueDate'];
        $assetName = (string) $m['assetName'];
        $recipients = AssetRegister::resolveReminderRecipients($assetId, 'maintenance');

        $subject = '🔧 Maintenance due soon: ' . $assetName;
        $body = '<p><strong>' . htmlspecialchars($assetName, ENT_QUOTES, 'UTF-8') . '</strong>'
              . ($m['assetTagCode'] !== null ? ' (' . htmlspecialchars((string) $m['assetTagCode'], ENT_QUOTES, 'UTF-8') . ')' : '')
              . ' has ' . htmlspecialchars(ucfirst((string) $m['maintType']), ENT_QUOTES, 'UTF-8') . ' due on '
              . htmlspecialchars($dueDate, ENT_QUOTES, 'UTF-8') . '.</p>'
              . '<p>' . htmlspecialchars((string) $m['title'], ENT_QUOTES, 'UTF-8') . '</p>'
              . '<p><a href="' . htmlspecialchars(assetReminderUrl($assetId), ENT_QUOTES, 'UTF-8') . '">View this asset</a></p>';

        $sent = sendAssetReminder($db, $siteId, 'maintenance', (int) $m['maintID'], $assetId, $dueDate, 'maintenance', $subject, $body, $recipients);
        if ($sent === null) {
            $siteTotals['maintenance']['skipped']++;
        } else {
            $siteTotals['maintenance']['sent'] += $sent;
        }
    }

    // -------------------------------------------------------------------
    // 🛡️ Warranty expiring.
    // -------------------------------------------------------------------
    foreach (AssetRegister::listExpiringWarranties($siteId, $leadWarranty) as $w) {
        $siteTotals['warranty']['due']++;
        $assetId   = (int) $w['assetID'];
        $dueDate   = (string) $w['warrantyExpiry'];
        $assetName = (string) $w['name'];
        // 🔗 Warranty repair/replacement is closest, of the two owner-
        // authority flags this schema tracks, to "maintenance" — see
        // AssetRegister::resolveReminderRecipients()'s own doc.
        $recipients = AssetRegister::resolveReminderRecipients($assetId, 'maintenance');

        $subject = '🛡️ Warranty expiring soon: ' . $assetName;
        $body = '<p><strong>' . htmlspecialchars($assetName, ENT_QUOTES, 'UTF-8') . '</strong>'
              . ($w['assetTagCode'] !== null ? ' (' . htmlspecialchars((string) $w['assetTagCode'], ENT_QUOTES, 'UTF-8') . ')' : '')
              . '&#39;s warranty expires on ' . htmlspecialchars($dueDate, ENT_QUOTES, 'UTF-8') . '.</p>'
              . '<p><a href="' . htmlspecialchars(assetReminderUrl($assetId), ENT_QUOTES, 'UTF-8') . '">View this asset</a></p>';

        $sent = sendAssetReminder($db, $siteId, 'warranty', $assetId, $assetId, $dueDate, 'asset', $subject, $body, $recipients);
        if ($sent === null) {
            $siteTotals['warranty']['skipped']++;
        } else {
            $siteTotals['warranty']['sent'] += $sent;
        }
    }

    // -------------------------------------------------------------------
    // 🧾 Insurance renewing. NEVER includes insurancePolicyNumber in the
    // mail body — see AssetRegister::listExpiringInsurance()'s selected
    // columns and the inline comment below.
    // -------------------------------------------------------------------
    foreach (AssetRegister::listExpiringInsurance($siteId, $leadInsurance) as $ins) {
        $siteTotals['insurance']['due']++;
        $assetId   = (int) $ins['assetID'];
        $dueDate   = (string) $ins['insuranceRenewalDate'];
        $assetName = (string) $ins['name'];
        // 🔒 'insurance' is not one of resolveReminderRecipients()'s two
        // recognised authority flags, so this deliberately skips the
        // owner-authority join and reaches ONLY the site admin/
        // asset_manager fallback — insurance renewal is a financial/
        // governance concern, not an owner-upkeep flag this schema
        // tracks. See that method's own doc.
        $recipients = AssetRegister::resolveReminderRecipients($assetId, 'insurance');

        $insurer = $ins['insurerName'] !== null ? (string) $ins['insurerName'] : null;
        $subject = '🧾 Insurance renewal due soon: ' . $assetName;
        $body = '<p><strong>' . htmlspecialchars($assetName, ENT_QUOTES, 'UTF-8') . '</strong>'
              . ($ins['assetTagCode'] !== null ? ' (' . htmlspecialchars((string) $ins['assetTagCode'], ENT_QUOTES, 'UTF-8') . ')' : '')
              . '&#39;s insurance renews on ' . htmlspecialchars($dueDate, ENT_QUOTES, 'UTF-8') . '.</p>'
              . ($insurer !== null ? '<p>Insurer: ' . htmlspecialchars($insurer, ENT_QUOTES, 'UTF-8') . '</p>' : '')
              // 🔐 insurancePolicyNumber is deliberately NEVER interpolated
              // into this body — a reference number is exactly the kind of
              // quasi-secret this codebase avoids putting in outbound mail.
              . '<p><a href="' . htmlspecialchars(assetReminderUrl($assetId), ENT_QUOTES, 'UTF-8') . '">View this asset</a></p>';

        $sent = sendAssetReminder($db, $siteId, 'insurance', $assetId, $assetId, $dueDate, 'asset', $subject, $body, $recipients);
        if ($sent === null) {
            $siteTotals['insurance']['skipped']++;
        } else {
            $siteTotals['insurance']['sent'] += $sent;
        }
    }

    // -------------------------------------------------------------------
    // 🔄 Loans overdue. Recipients are lending authority (+ site admin/
    // asset_manager fallback) PLUS the loan's own counterparty — and
    // deliberately NOTHING WIDER than that (see file header's RECIPIENTS
    // note and AssetRegister::resolveLoanCounterpartyEmail()'s own doc).
    // -------------------------------------------------------------------
    foreach (AssetRegister::listOverdueLoans($siteId) as $loan) {
        $siteTotals['loan-overdue']['due']++;
        $assetId   = (int) $loan['assetID'];
        $loanId    = (int) $loan['loanID'];
        $dueDate   = (string) $loan['dueDate'];
        $assetName = (string) $loan['assetName'];

        $recipients = AssetRegister::resolveReminderRecipients($assetId, 'lending');
        $counterpartyEmail = AssetRegister::resolveLoanCounterpartyEmail($loan);
        if ($counterpartyEmail !== null) {
            $recipients[] = $counterpartyEmail;
        }

        $direction = (string) $loan['direction'] === 'out' ? 'lent out' : 'borrowed';
        $subject = '⚠️ Overdue: ' . $assetName;
        $body = '<p><strong>' . htmlspecialchars($assetName, ENT_QUOTES, 'UTF-8') . '</strong> ('
              . htmlspecialchars($direction, ENT_QUOTES, 'UTF-8') . ') was due back on '
              . htmlspecialchars($dueDate, ENT_QUOTES, 'UTF-8') . ' and is now overdue.</p>'
              . '<p>Counterparty: ' . htmlspecialchars((string) $loan['counterpartyDisplayName'], ENT_QUOTES, 'UTF-8') . '</p>'
              . '<p><a href="' . htmlspecialchars(assetReminderUrl($assetId), ENT_QUOTES, 'UTF-8') . '">View this asset</a></p>';

        $sent = sendAssetReminder($db, $siteId, 'loan-overdue', $loanId, $assetId, $dueDate, 'loan', $subject, $body, $recipients);
        if ($sent === null) {
            $siteTotals['loan-overdue']['skipped']++;
        } else {
            $siteTotals['loan-overdue']['sent'] += $sent;
        }
    }

    // -------------------------------------------------------------------
    // 🧹 Per-site housekeeping — persist today's straight-line book
    // values (#408), then purge retention-expired found-reports (#401)/
    // scan-log (#410) rows. Both purge methods existed before this pass
    // but had no cron caller until now (see each method's own doc).
    // -------------------------------------------------------------------
    $valuationsWritten  = AssetRegister::persistCurrentValues($siteId);
    $foundReportsPurged = AssetRegister::purgeExpiredFoundReports($siteId);
    $scanLogPurged      = AssetRegister::purgeExpiredScanLog($siteId);

    $grandValuationsWritten  += $valuationsWritten;
    $grandFoundReportsPurged += $foundReportsPurged;
    $grandScanLogPurged      += $scanLogPurged;

    foreach ($grandTotals as $family => $ignored) {
        $grandTotals[$family]['due']     += $siteTotals[$family]['due'];
        $grandTotals[$family]['sent']    += $siteTotals[$family]['sent'];
        $grandTotals[$family]['skipped'] += $siteTotals[$family]['skipped'];
    }

    // 📓 Per-site activity log row — Logger::activity() itself reads
    // Site::id(), which is still THIS site for the remainder of the loop
    // body (forceContext() only moves on to the next site on the NEXT
    // iteration), so this row is correctly attributed even in a
    // multi-site install.
    Logger::activity(
        'AssetRemindersRun',
        sprintf(
            'Maintenance %d/%d sent, warranty %d/%d, insurance %d/%d, loan-overdue %d/%d; '
                . '%d valuation(s) written, %d found-report(s) purged, %d scan-log row(s) purged',
            $siteTotals['maintenance']['sent'], $siteTotals['maintenance']['due'],
            $siteTotals['warranty']['sent'], $siteTotals['warranty']['due'],
            $siteTotals['insurance']['sent'], $siteTotals['insurance']['due'],
            $siteTotals['loan-overdue']['sent'], $siteTotals['loan-overdue']['due'],
            $valuationsWritten,
            $foundReportsPurged,
            $scanLogPurged
        )
    );
}

// -----------------------------------------------------------------------------
// 📋 text/plain summary — one line per family, across every site swept.
// -----------------------------------------------------------------------------
echo "Asset Tracker reminder sweep — " . count($siteIds) . " site(s)\n";
foreach ($grandTotals as $family => $t) {
    echo sprintf(
        "%-14s due=%d sent=%d skipped(already-sent)=%d\n",
        $family,
        $t['due'],
        $t['sent'],
        $t['skipped']
    );
}
echo "valuationsWritten=" . $grandValuationsWritten . "\n";
echo "foundReportsPurged=" . $grandFoundReportsPurged . "\n";
echo "scanLogPurged=" . $grandScanLogPurged . "\n";
