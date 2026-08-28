<?php
// Path: _apps/cron/workflow-timeouts.php
/**
 * -----------------------------------------------------------------------------
 * Cron — Workflow timeout sweep ⏰🔄 (#443)
 * -----------------------------------------------------------------------------
 * Endpoint expected to be called hourly by an external scheduler (timeout
 * granularity is hours — tblWorkflowSteps.timeoutHours). Clone of
 * `cron/user-reminders.php`'s structural discipline: token gate first,
 * per-site loop with Site::forceContext(), grand-total text/plain summary.
 *
 * TOKEN GATE: `?key=` vs `workflows.cron_token`, constant-time
 * `hash_equals()`, empty stored token ⇒ ALWAYS 403 — migration 174 seeds
 * `workflows.cron_token` empty + isSensitive=1, so this endpoint is inert
 * until an admin sets a real token at /admin/settings.
 *
 * GLOBAL KILL-SWITCH: `workflows.enabled` — read ONCE before the loop
 * (genuinely global, siteID=NULL, like `workflows.cron_token` itself), NOT
 * per-site inside it.
 *
 * MULTI-SITE: loops every active site (`tblSites.isActive = 1`).
 * `Site::forceContext($siteId)` runs before each site's work wrapped in
 * try/catch-continue — mirrors cron/user-reminders.php exactly, including NO
 * context reset after the loop (the process exits immediately after). All
 * of the real work (per-instance timeout resolution, escalation dedupe,
 * auto-act) lives in `Portal\Core\Workflow::timeoutSweep($siteId)` — this
 * file is the scheduling/token/summary shell around it.
 *
 * NEVER AUTO-ACTS on a bare timeout — Workflow::timeoutSweep() only auto-
 * approves/auto-rejects a step that explicitly sets
 * autoAction='approve'|'reject'; a NULL or 'escalate' autoAction only
 * escalates (notify + keep waiting), deduped per (instanceID, stepID).
 *
 * @package   Portal\App\Cron
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/443
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Logger;
use Portal\Core\Settings;
use Portal\Core\Site;
use Portal\Core\Workflow;

// -----------------------------------------------------------------------------
// 🔑 Token gate (constant-time compare). An empty stored token ALWAYS 403s,
// so this endpoint is inert until an admin explicitly sets
// workflows.cron_token.
// -----------------------------------------------------------------------------
$incoming = (string) ($_GET['key'] ?? '');
$expected = (string) (Settings::get('workflows.cron_token', '') ?? '');
if ($expected === '' || hash_equals($expected, $incoming) === false) {
    http_response_code(403);
    exit('Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');

if ((string) Settings::get('workflows.enabled', '1') === '0') {
    echo 'Workflow engine disabled';
    exit();
}

$db = App::db();

// -----------------------------------------------------------------------------
// 🌍 Every distinct, active site.
// -----------------------------------------------------------------------------
$siteIds = [];
$siteStmt = $db->prepare('SELECT siteID FROM tblSites WHERE isActive = 1');
if ($siteStmt !== false) {
    $siteStmt->execute();
    $result = $siteStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $siteIds[] = (int) $row['siteID'];
    }
    $siteStmt->close();
}

$grandChecked = 0;
$grandAutoActed = 0;
$grandEscalated = 0;

foreach ($siteIds as $siteId) {
    // 🌐 Force this site's context — one misbehaving site must never abort
    // the whole sweep for every OTHER site.
    try {
        Site::forceContext($siteId);
    } catch (\Throwable $e) {
        Logger::errorPlatform(
            'WorkflowTimeouts',
            'Warning',
            'WORKFLOW_TIMEOUTS_SITE_CONTEXT_FAIL',
            $e->getMessage(),
            'siteID=' . $siteId
        );
        continue;
    }

    $summary = Workflow::timeoutSweep($siteId);
    $grandChecked += $summary['checked'];
    $grandAutoActed += $summary['auto_acted'];
    $grandEscalated += $summary['escalated'];

    if ($summary['checked'] > 0) {
        Logger::activity(
            'WorkflowTimeoutSweep',
            sprintf('checked=%d auto_acted=%d escalated=%d', $summary['checked'], $summary['auto_acted'], $summary['escalated'])
        );
    }
}
// 🚧 NO Site::forceContext() reset after the loop — mirrors cron/user-
// reminders.php exactly; the process exits immediately after this script.

// -----------------------------------------------------------------------------
// 📋 Grand-total text/plain summary — greppable from the scheduler's logs.
// -----------------------------------------------------------------------------
echo "Workflow timeout sweep — " . count($siteIds) . " site(s)\n";
echo sprintf("checked=%d auto_acted=%d escalated=%d\n", $grandChecked, $grandAutoActed, $grandEscalated);
