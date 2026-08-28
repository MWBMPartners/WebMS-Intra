<?php
// Path: _apps/giving/statements-email.php
/**
 * -----------------------------------------------------------------------------
 * Giving — Bulk statements: email (gap #4, #440)
 * -----------------------------------------------------------------------------
 * POST-only, treasurer-only. Queues every eligible generated-but-unsent row
 * for this period, then sends up to `giving.statements.batchPerRun` of them
 * via Giving::sendStatementEmail() (the same send routine the optional
 * cron/giving-statements.php sweeper uses — exactly one code path). Never
 * accepts a recipient address from the request: each row's own donorID is
 * always the recipient, re-resolved live from tblUsers at send time.
 *
 * "Resend to already-emailed donors" (`resend=1`) is an explicit, audit-
 * logged override (#440 Q4) — without it, a row with `emailedAt` set can
 * never be picked up again (the dedupe fence tblGivingStatementLog's
 * UNIQUE key + this file's own selector both rely on).
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
if ($fromTs === false || $toTs === false || $fromTs > $toTs) {
    $_SESSION['flash_msg']  = 'Invalid date range.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /giving/statements');
    exit();
}
$periodKey = Giving::statementPeriodKey($from, $to);
$resend    = isset($_POST['resend']) === true && $_POST['resend'] === '1';

$db = App::db();

// -----------------------------------------------------------------------------
// 🔁 Explicit, audited resend override (#440 Q4) — clears emailedAt/
// errorMsg on already-emailed rows FIRST, then falls into the same
// queue+send flow below as if they were fresh.
// -----------------------------------------------------------------------------
if ($resend === true) {
    $stmt = $db->prepare(
        'UPDATE tblGivingStatementLog SET emailedAt = NULL, errorMsg = NULL, queuedAt = NOW() '
        . 'WHERE siteID = ? AND periodKey = ? AND pdfPath IS NOT NULL AND emailedAt IS NOT NULL'
    );
    $resentCount = 0;
    if ($stmt !== false) {
        $stmt->bind_param('is', $siteId, $periodKey);
        $stmt->execute();
        $resentCount = $stmt->affected_rows;
        $stmt->close();
    }
    if ($resentCount > 0) {
        Logger::activity('GivingStatementsResend', 'Resend override: re-queued ' . $resentCount . ' already-emailed statement(s) for period ' . $periodKey, $userId);
    }
}

// -----------------------------------------------------------------------------
// 📥 Queue every newly-eligible row (generated, never emailed, not
// currently erroring, not already queued). Opt-out + email validity are
// re-checked live inside Giving::sendStatementEmail() at actual send
// time — this step only marks intent.
// -----------------------------------------------------------------------------
$stmt = $db->prepare(
    'UPDATE tblGivingStatementLog SET queuedAt = NOW() '
    . 'WHERE siteID = ? AND periodKey = ? AND pdfPath IS NOT NULL '
    . '  AND emailedAt IS NULL AND errorMsg IS NULL AND queuedAt IS NULL'
);
if ($stmt !== false) {
    $stmt->bind_param('is', $siteId, $periodKey);
    $stmt->execute();
    $stmt->close();
}

$settings = App::settings()['giving'] ?? [];
$cap      = (int) ($settings['statements']['batchPerRun'] ?? 25);
if ($cap < 1) {
    $cap = 25;
}

// 🕰️ Defensive backstop only — batching is the actual mechanism that keeps
// this inside DreamHost's FastCGI limits (offsite-backup-run.php precedent).
@set_time_limit(300);

$pending = [];
$stmt = $db->prepare(
    'SELECT logID, siteID, donorID, pdfPath, fromDate, toDate FROM tblGivingStatementLog '
    . 'WHERE siteID = ? AND periodKey = ? AND queuedAt IS NOT NULL AND emailedAt IS NULL AND errorMsg IS NULL '
    . 'ORDER BY donorID LIMIT ?'
);
if ($stmt !== false) {
    $stmt->bind_param('isi', $siteId, $periodKey, $cap);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $pending[] = $r;
    }
    $stmt->close();
}

$sent   = 0;
$failed = 0;
foreach ($pending as $row) {
    $logRow = [
        'logID'    => (int) $row['logID'],
        'siteID'   => (int) $row['siteID'],
        'donorID'  => (int) $row['donorID'],
        'pdfPath'  => (string) $row['pdfPath'],
        'fromDate' => (string) $row['fromDate'],
        'toDate'   => (string) $row['toDate'],
    ];
    if (Giving::sendStatementEmail($logRow) === true) {
        $sent++;
    } else {
        $failed++;
    }
}

$remaining = 0;
$stmt = $db->prepare(
    'SELECT COUNT(*) FROM tblGivingStatementLog '
    . 'WHERE siteID = ? AND periodKey = ? AND queuedAt IS NOT NULL AND emailedAt IS NULL AND errorMsg IS NULL'
);
if ($stmt !== false) {
    $stmt->bind_param('is', $siteId, $periodKey);
    $stmt->execute();
    $stmt->bind_result($remaining);
    $stmt->fetch();
    $stmt->close();
}

Logger::activity(
    'GivingStatementsEmail',
    sprintf('Period %s: sent %d, failed %d, %d remaining', $periodKey, $sent, $failed, $remaining),
    $userId
);

$msg = sprintf('Sent %d, failed %d', $sent, $failed);
$msg .= $remaining > 0
    ? sprintf('. %d remaining — re-run, or configure the giving cron to finish (see DEV_NOTES.md).', $remaining)
    : '. Done.';

$_SESSION['flash_msg']  = $msg;
$_SESSION['flash_type'] = $failed > 0 ? 'warning' : 'success';
header('Location: /giving/statements?from=' . urlencode($from) . '&to=' . urlencode($to));
exit();
