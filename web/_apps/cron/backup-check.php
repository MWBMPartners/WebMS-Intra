<?php
// Path: _apps/cron/backup-check.php
/**
 * -----------------------------------------------------------------------------
 * Cron — Backup freshness check and alert email 🔔 (#142, #497)
 * -----------------------------------------------------------------------------
 * Works out how old the newest backup snapshot is. If it is older than
 * `portal.backups.max_age_hours`, or there is none at all, it emails everybody
 * listed in `portal.backups.alert_recipients`. Meant for a daily call from the
 * hosting company's scheduler:
 *
 *   curl -fsS "https://<your-portal>/cron/backup-check?token=<TOKEN>"
 *
 * WHY IT HAS ITS OWN ADDRESS
 * This used to be /admin/maintenance/backup-check?cron=1&token=…, a mode of
 * the staff page. That page is seeded as a protected address, and from
 * 14 September 2026 the Router really does send a signed-out visitor to the
 * sign-in page (issue #497). A scheduler has a token but no session, so it
 * would have been redirected, no alert would ever have been sent, and nothing
 * would have said so — the one failure a backup alert exists to prevent. Every
 * other scheduled job already lives at a cron/... address seeded as NOT
 * protected with its own token check, and this now does too. The "?cron=1"
 * mode has been removed from the staff page.
 *
 * THE TOKEN CHECK IS DELIBERATELY THE SAME AS THE OLD JOB MODE
 * Same setting (`maintenance.cronToken`), same `token` query parameter, same
 * constant-time comparison, and the same responses: 403 with
 * {"error":"invalid_token"} for a missing, wrong or unset token, and the same
 * JSON report otherwise. An EMPTY stored token refuses everything.
 *
 * The verdict (fresh, stale or missing) comes from _backup-freshness.php
 * beside this file, shared with the staff page so the two always agree. The
 * alert email is sent only from here.
 *
 * WHAT IT CANNOT DO
 *   - It does not run while the portal is in maintenance mode. During an
 *     upgrade this address gets the maintenance page (HTTP 503) and no alert
 *     is sent, like every other cron/ job except the read-only /cron/health
 *     report. That is the owner's decision of 14 September 2026, carried out
 *     in web/_core/Maintenance.php: a job that sends email must not run
 *     against a database that is half way through an upgrade. The old
 *     "?cron=1" address DID run during maintenance, because it sat under
 *     `admin/maintenance…`, so this is a deliberate change.
 *   - A failed email is not retried, and is not reported beyond the
 *     `alerts_sent` count in the JSON.
 *
 * @package   Portal\App\Cron
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/142
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/497
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Mailer;

require_once __DIR__ . DIRECTORY_SEPARATOR . '_backup-freshness.php';

// -----------------------------------------------------------------------------
// 🔑 Token check. No session is involved at all.
// -----------------------------------------------------------------------------
$expected = (string) (App::settings()['maintenance']['cronToken'] ?? '');
$supplied = (string) ($_GET['token'] ?? '');
if ($expected === '' || hash_equals($expected, $supplied) === false) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'invalid_token']);
    exit();
}

// 📦 The verdict, shared with the staff page.
$check          = backup_freshness_check();
$snapshots      = $check['snapshots'];
$thresholdHours = $check['thresholdHours'];
$recipients     = $check['recipients'];
$state          = $check['state'];
$message        = $check['message'];
$ageHours       = $check['ageHours'];

// 🚨 Alert dispatch (only if stale or critical AND we have recipients).
//    Only this scheduled job sends the email. The staff page just shows the
//    status, so an administrator who is actively looking at it is not emailed
//    as well. (When this lived inside the staff page, the same rule was written
//    as "only in cron mode".)
$alertsSent = 0;
if ($state !== 'ok' && count($recipients) > 0) {
    $portalName = (string) (App::settings()['site']['name'] ?? 'WebMS Intra');
    $subject    = sprintf('[%s] Backup freshness alert: %s', $portalName, $state);
    $body       = $message . "\n\n"
                . sprintf("Snapshot count: %d\n", count($snapshots))
                . sprintf("Threshold: %d hours\n", $thresholdHours)
                . "\nReview: " . (string) (App::settings()['site']['url'] ?? '')
                . "/admin/maintenance/backup-check\n";
    foreach ($recipients as $to) {
        try {
            if (Mailer::send($to, $subject, $body) === true) {
                $alertsSent++;
            }
        } catch (\Throwable $e) {
            // 🛡️ Mail-send failure is non-fatal — we still return the
            //    status JSON so the cron can decide what to do next.
        }
    }
}

// 🖼️ Report — same keys, same order as the old job mode.
header('Content-Type: application/json');
echo json_encode([
    'state'           => $state,
    'message'         => $message,
    'age_hours'       => $ageHours,
    'threshold_hours' => $thresholdHours,
    'snapshot_count'  => count($snapshots),
    'recipients'      => count($recipients),
    'alerts_sent'     => $alertsSent,
    'checked_at'      => date('c'),
]);
exit();
