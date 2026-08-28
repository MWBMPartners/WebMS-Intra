<?php
// Path: _apps/admin/reports/builder/export.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Reports Builder: CSV export 📥📊 (#156)
 * -----------------------------------------------------------------------------
 * Same load/authorisation as run.php. Streams via
 * ReportBuilder::streamCsv() -> CsvExporter::download() (CWE-1236 formula-
 * cell neutralisation + filename sanitisation already handled there).
 * Row cap = reports.builder.maxRows (further capped by the definition's
 * own optional "limit"). Logs the PII-column detail — the audit trail for
 * PII actually leaving the system as a downloadable file.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/156
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AppRegistry;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\ReportBuilder;
use Portal\Core\ReportRegistry;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

if (App::isAdmin() !== true) {
    $_SESSION['flash_msg']  = t('error.access_denied_inline');
    $_SESSION['flash_type'] = 'danger';
    header('Location: /dashboard');
    exit();
}

if (AppRegistry::isEnabled('reports') === false) {
    $_SESSION['flash_msg']  = 'Reports is disabled for this site.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: /admin/apps');
    exit();
}

$siteId   = Site::id();
$userId   = (int) ($_SESSION['user_id'] ?? 0);
$reportId = (int) ($_GET['id'] ?? 0);

if ($reportId <= 0) {
    $_SESSION['flash_msg']  = 'Invalid report.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/reports/builder');
    exit();
}

$row = ReportBuilder::get($reportId, $siteId);
if ($row === null) {
    $_SESSION['flash_msg']  = 'Report not found.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/reports/builder');
    exit();
}

$isOwner  = ((int) ($row['createdByID'] ?? 0)) === $userId;
$isShared = (int) $row['isShared'] === 1;
if ($isShared === false && $isOwner === false && App::isSiteAdmin() === false) {
    $_SESSION['flash_msg']  = 'You do not have access to this report.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/reports/builder');
    exit();
}

try {
    $definition = ReportBuilder::decodeDefinitionJson((string) $row['definition']);
    $compiled   = ReportBuilder::compile($definition, $siteId);
} catch (\InvalidArgumentException $e) {
    $_SESSION['flash_msg']  = 'This report definition is no longer valid and could not be exported: ' . $e->getMessage() . '.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/reports/builder/run?id=' . $reportId);
    exit();
}

$piiCols = [];
foreach ((array) $compiled['columns'] as $meta) {
    $colEntry = ReportRegistry::column((string) $row['sourceKey'], (string) $meta['key']);
    if ($colEntry !== null && ($colEntry['pii'] ?? false) === true) {
        $piiCols[] = (string) $meta['key'];
    }
}
Logger::activity(
    'ReportExport',
    'reportID=' . $reportId . ' source=' . (string) $row['sourceKey']
        . (count($piiCols) > 0 ? ' piiColumns=' . implode(',', $piiCols) : ''),
    $userId
);

$slug = preg_replace('/[^a-z0-9]+/i', '-', (string) $row['reportName']) ?? 'report';
$filename = strtolower(trim($slug, '-')) . '-' . date('Ymd-His') . '.csv';

ReportBuilder::streamCsv($compiled, $filename);
