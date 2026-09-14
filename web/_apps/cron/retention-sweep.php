<?php
// Path: _apps/cron/retention-sweep.php
/**
 * -----------------------------------------------------------------------------
 * Cron — Audit-Log Retention Sweep 🧹 (#491, #497)
 * -----------------------------------------------------------------------------
 * The scheduled job that hard-deletes old activity-log rows, old error rows
 * and children's event registrations that are past their keep-by date. It is
 * meant to be called once a night by the hosting company's scheduler:
 *
 *   curl -fsS "https://<your-portal>/cron/retention-sweep?token=<TOKEN>"
 *
 * WHY IT HAS ITS OWN ADDRESS
 * This used to be /admin/maintenance/retention?cron=1&token=…, a mode of the
 * staff page. That page is seeded as a protected address, and from
 * 14 September 2026 the Router really does send a signed-out visitor to the
 * sign-in page (issue #497 — before that, its sign-in test never fired). A
 * scheduler has a token but no session, so it would have been redirected. A
 * redirect is not an error as far as curl is concerned, so the sweep would
 * have stopped with nothing anywhere to say so. Every other scheduled job
 * already lives at a cron/... address that is seeded as NOT protected and
 * checks its own token. This one now does too, and the "?cron=1" mode has been
 * removed from the staff page.
 *
 * THE TOKEN CHECK IS DELIBERATELY THE SAME AS THE OLD JOB MODE
 * Same setting (`maintenance.cronToken`), same `token` query parameter, same
 * constant-time comparison (hash_equals, so the time taken does not reveal how
 * much of a guess was right), and the same responses: 403 with
 * {"status":"forbidden"} for a missing, wrong or unset token, and
 * {"status":"ok", …counts} after a sweep. An EMPTY stored token refuses
 * everything, so the job does nothing until a global administrator sets one.
 * It keeps the `token` parameter rather than the `key` parameter some other
 * jobs use, so an existing scheduler line only needs its address changed.
 *
 * The sweep itself is in _retention-sweep.php beside this file. It is shared
 * with the staff page, so the page's preview and this job always use the same
 * rules.
 *
 * WHAT IT CANNOT DO
 *   - It does not run while the portal is in maintenance mode. During an
 *     upgrade this address gets the maintenance page (HTTP 503), and so does
 *     every other cron/ job except the read-only /cron/health report. That is
 *     the owner's decision of 14 September 2026, carried out in
 *     web/_core/Maintenance.php: a job that deletes rows must not run against
 *     a database that is half way through an upgrade. The old "?cron=1"
 *     address DID run during maintenance, because it sat under
 *     `admin/maintenance…`, so this is a deliberate change.
 *   - On a beta or alpha copy it is behind the pre-release gate like every
 *     other address that is not on the gate's open list (owner's decision,
 *     13 September 2026).
 *
 * @package   Portal\App\Cron
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/497
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/491
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Logger;

require_once __DIR__ . DIRECTORY_SEPARATOR . '_retention-sweep.php';

// 📄 JSON, never cached — a cached "ok" from last night would hide tonight's
//    failure.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// -----------------------------------------------------------------------------
// 🔑 Token check. No session is involved at all.
// -----------------------------------------------------------------------------
$configured = (string) (App::settings('maintenance.cronToken') ?? '');
$provided   = (string) ($_GET['token'] ?? '');

if ($configured === '' || hash_equals($configured, $provided) === false) {
    http_response_code(403);
    echo json_encode(['status' => 'forbidden']);
    exit();
}

// -----------------------------------------------------------------------------
// 🧹 Run the sweep and report the counts.
// -----------------------------------------------------------------------------
$result = run_retention_sweep();
Logger::activity('AuditRetentionSweep', 'Cron sweep deleted ' . $result['totalDeleted'] . ' rows');
echo json_encode(['status' => 'ok'] + $result);
exit();
