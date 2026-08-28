<?php
// Path: _apps/giving/statements-generate.php
/**
 * -----------------------------------------------------------------------------
 * Giving — Bulk statements: generate (gap #4, #440)
 * -----------------------------------------------------------------------------
 * POST-only, treasurer-only. Upserts one tblGivingStatementLog row per
 * eligible donor for the given period, then renders up to
 * `giving.statements.batchPerRun` still-missing PDFs through the SAME
 * renderer the self-service page uses (Giving::renderStatementPdf() —
 * byte-identical output by construction). Re-trigger this same POST to
 * continue a larger run — mirrors the Newsletter dispatch pattern
 * (Newsletter.php / newsletter/send.php).
 *
 * Also handles the small `action=retry` branch: clears a single row's
 * `errorMsg` (site + donor + period scoped) so it re-enters the normal
 * next-Generate/next-Email selection — retries are always manual, never
 * automatic (a systemic failure must not loop-spam a donor or the mail
 * provider).
 *
 * @package   Portal\Giving
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/440
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Giving;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (Giving::canManage() === false) {
    Router::renderError(403);
    return;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$from = (string) ($_POST['from'] ?? '');
$to   = (string) ($_POST['to']   ?? '');
$fromTs = strtotime($from);
$toTs   = strtotime($to);
// 🛡️ Real, ordered, sane-sized (≤ 5 years) range only — mirrors the sanity
// gate the plan calls for; renderStatementPdf() re-validates independently
// too, but reject obviously-bad input before it reaches any SQL/filename.
if ($fromTs === false || $toTs === false || $fromTs > $toTs || ($toTs - $fromTs) > (5 * 366 * 86400)) {
    $_SESSION['flash_msg']  = 'Invalid date range.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /giving/statements');
    exit();
}

$action = (string) ($_POST['action'] ?? 'generate');

// -----------------------------------------------------------------------------
// 🔁 Retry — clear one row's errorMsg (scoped to this site + period + the
// donorID this treasurer's own preview page showed them), never a blanket
// auto-retry.
// -----------------------------------------------------------------------------
if ($action === 'retry') {
    $donorId = (int) ($_POST['donorID'] ?? 0);
    if ($donorId > 0) {
        $periodKey = Giving::statementPeriodKey($from, $to);
        $db = App::db();
        $stmt = $db->prepare(
            'UPDATE tblGivingStatementLog SET errorMsg = NULL WHERE siteID = ? AND donorID = ? AND periodKey = ?'
        );
        if ($stmt !== false) {
            $stmt->bind_param('iis', $siteId, $donorId, $periodKey);
            $stmt->execute();
            $stmt->close();
        }
        Logger::activity('GivingStatementsRetry', 'Cleared statement error for donor #' . $donorId . ', period ' . $periodKey, $userId);
    }
    $_SESSION['flash_msg']  = 'Error cleared — click Generate or Email again to retry.';
    $_SESSION['flash_type'] = 'info';
    header('Location: /giving/statements?from=' . urlencode($from) . '&to=' . urlencode($to));
    exit();
}

// -----------------------------------------------------------------------------
// 🧾 Normal generate flow.
// -----------------------------------------------------------------------------
$regenerate = isset($_POST['regenerate']) === true && $_POST['regenerate'] === '1';

$settings = App::settings()['giving'] ?? [];
$cap      = (int) ($settings['statements']['batchPerRun'] ?? 25);
if ($cap < 1) {
    $cap = 25;
}

// 🕰️ DreamHost shared FastCGI can still kill a long request regardless of
// this — batching (not a raised time limit) is the actual mechanism, this
// is only a defensive backstop (offsite-backup-run.php precedent).
@set_time_limit(300);

$upserted = Giving::upsertStatementLogRows($siteId, $from, $to, $userId > 0 ? $userId : null);
$result   = Giving::renderStatementBatch($siteId, $from, $to, $cap, $regenerate);
$periodKey = Giving::statementPeriodKey($from, $to);

Logger::activity(
    'GivingStatementsGenerate',
    sprintf(
        'Period %s: %d donor(s) upserted, %d rendered, %d failed, %d remaining',
        $periodKey,
        $upserted,
        $result['rendered'],
        $result['failed'],
        $result['remaining']
    ),
    $userId
);

$msg = sprintf('Generated %d statement(s) this run', $result['rendered']);
if ($result['failed'] > 0) {
    $msg .= sprintf(', %d failed (see per-row error + retry)', $result['failed']);
}
$msg .= $result['remaining'] > 0
    ? sprintf('. %d remaining — click Generate again to continue.', $result['remaining'])
    : '. All statements for this period are generated.';

$_SESSION['flash_msg']  = $msg;
$_SESSION['flash_type'] = $result['failed'] > 0 ? 'warning' : 'success';
header('Location: /giving/statements?from=' . urlencode($from) . '&to=' . urlencode($to));
exit();
