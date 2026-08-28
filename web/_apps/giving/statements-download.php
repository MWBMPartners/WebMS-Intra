<?php
// Path: _apps/giving/statements-download.php
/**
 * -----------------------------------------------------------------------------
 * Giving — Bulk statements: download (gap #4, #440)
 * -----------------------------------------------------------------------------
 * GET, treasurer-only, side-effect-free (reads already-generated PDFs only
 * — no CSRF token needed, matching the house convention for read-only GET
 * handlers). Two modes:
 *
 *   ?donorID=N  — stream that ONE donor's already-generated PDF (the #440
 *                 Q3 "left the site" per-row download link, but works for
 *                 any donor in the period).
 *   (no donorID) — stream a ZIP of every generated PDF for this period,
 *                 built with ZipArchive (never a single combined PDF — a
 *                 cross-donor PII bundle with no per-donor delivery value,
 *                 and the exact memory/time hazard batching elsewhere in
 *                 this feature avoids). Degrades to a friendly flash if
 *                 ZipArchive isn't available on this server (venues/
 *                 import.php:125 precedent) — per-row download still
 *                 works either way.
 *
 * Every row selected is always scoped to (siteID = Site::id(), periodKey)
 * — a donorID from the query string can only ever select a row that is
 * ALSO already scoped to this site+period, so there is no way to pull
 * another site's file through this endpoint.
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

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$from = (string) ($_GET['from'] ?? '');
$to   = (string) ($_GET['to']   ?? '');
if (strtotime($from) === false || strtotime($to) === false || strtotime($from) > strtotime($to)) {
    $_SESSION['flash_msg']  = 'Invalid date range.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /giving/statements');
    exit();
}
$periodKey = Giving::statementPeriodKey($from, $to);
$db        = App::db();

// -----------------------------------------------------------------------------
// 📄 Single-donor download.
// -----------------------------------------------------------------------------
$donorId = (int) ($_GET['donorID'] ?? 0);
if ($donorId > 0) {
    $row = null;
    $stmt = $db->prepare(
        'SELECT l.pdfPath, u.fullName FROM tblGivingStatementLog l '
        . 'INNER JOIN tblUsers u ON u.userID = l.donorID '
        . 'WHERE l.siteID = ? AND l.donorID = ? AND l.periodKey = ? LIMIT 1'
    );
    if ($stmt !== false) {
        $stmt->bind_param('iis', $siteId, $donorId, $periodKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    $pdfPath = $row !== null ? (string) ($row['pdfPath'] ?? '') : '';
    if ($row === null || $pdfPath === '' || is_file($pdfPath) === false) {
        $_SESSION['flash_msg']  = 'That statement has not been generated yet.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: /giving/statements?from=' . urlencode($from) . '&to=' . urlencode($to));
        exit();
    }

    $safeName = preg_replace('/[^A-Za-z0-9 _-]/', '', (string) $row['fullName']) ?? '';
    $filename = $donorId . '-' . ($safeName !== '' ? $safeName . '-' : '') . $periodKey . '.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($pdfPath));
    readfile($pdfPath);
    exit();
}

// -----------------------------------------------------------------------------
// 🗜️ Full-period ZIP.
// -----------------------------------------------------------------------------
if (class_exists('ZipArchive') === false) {
    $_SESSION['flash_msg']  = 'ZIP download is not available on this server — use the per-row "PDF" links instead.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: /giving/statements?from=' . urlencode($from) . '&to=' . urlencode($to));
    exit();
}

$partial = ($_GET['partial'] ?? '') === '1';

$ready = [];
$missingCount = 0;
$stmt = $db->prepare(
    'SELECT l.donorID, l.pdfPath, u.fullName FROM tblGivingStatementLog l '
    . 'INNER JOIN tblUsers u ON u.userID = l.donorID '
    . 'WHERE l.siteID = ? AND l.periodKey = ? AND l.pdfPath IS NOT NULL '
    . 'ORDER BY u.fullName'
);
if ($stmt !== false) {
    $stmt->bind_param('is', $siteId, $periodKey);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $ready[] = $r;
    }
    $stmt->close();
}
$stmt = $db->prepare(
    'SELECT COUNT(*) FROM tblGivingStatementLog WHERE siteID = ? AND periodKey = ? AND pdfPath IS NULL'
);
if ($stmt !== false) {
    $stmt->bind_param('is', $siteId, $periodKey);
    $stmt->execute();
    $stmt->bind_result($missingCount);
    $stmt->fetch();
    $stmt->close();
}

if ($missingCount > 0 && $partial === false) {
    $_SESSION['flash_msg']  = $missingCount . ' statement(s) for this period are not generated yet — click Generate first, '
        . 'or add &partial=1 to the download link to bundle just the ' . count($ready) . ' that are ready.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: /giving/statements?from=' . urlencode($from) . '&to=' . urlencode($to));
    exit();
}

if (count($ready) === 0) {
    $_SESSION['flash_msg']  = 'No generated statements to download for this period yet.';
    $_SESSION['flash_type'] = 'info';
    header('Location: /giving/statements?from=' . urlencode($from) . '&to=' . urlencode($to));
    exit();
}

$dir = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'giving'
     . DIRECTORY_SEPARATOR . 'statements' . DIRECTORY_SEPARATOR . (string) $siteId;
if (is_dir($dir) === false) {
    mkdir($dir, 0755, true);
}
$zipPath = $dir . DIRECTORY_SEPARATOR . 'bundle-' . $periodKey . '.zip';

$zip = new \ZipArchive();
if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
    $_SESSION['flash_msg']  = 'Could not build the ZIP archive — try again, or use the per-row "PDF" links.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /giving/statements?from=' . urlencode($from) . '&to=' . urlencode($to));
    exit();
}
foreach ($ready as $r) {
    $pdfPath = (string) $r['pdfPath'];
    if (is_file($pdfPath) === false) {
        continue;
    }
    $safeName = preg_replace('/[^A-Za-z0-9 _-]/', '', (string) $r['fullName']) ?? '';
    $entryName = (int) $r['donorID'] . '-' . ($safeName !== '' ? $safeName . '-' : '') . $periodKey . '.pdf';
    $zip->addFile($pdfPath, $entryName);
}
$zip->close();

Logger::activity('GivingStatementsDownload', 'Downloaded ZIP (' . count($ready) . ' statement(s)) for period ' . $periodKey, $userId);

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="giving-statements-' . $periodKey . '.zip"');
header('Content-Length: ' . filesize($zipPath));
readfile($zipPath);
// 🧹 The per-donor PDFs stay; only the transient bundle is removed.
unlink($zipPath);
exit();
