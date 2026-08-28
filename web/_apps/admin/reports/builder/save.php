<?php
// Path: _apps/admin/reports/builder/save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Reports Builder: save handler 📊 (#156)
 * -----------------------------------------------------------------------------
 * CSRF'd POST. Decodes the posted `definition` JSON (byte-capped +
 * depth-capped BEFORE decode — ReportBuilder::decodeDefinitionJson()),
 * then runs it through ReportBuilder::validateDefinition() — a full
 * dry-compile against the registry — as the pre-save gate. On failure the
 * safe InvalidArgumentException message is surfaced next to the offending
 * step; on success ReportBuilder::save() re-validates again internally
 * (defence against a future alternate caller) before the INSERT/UPDATE.
 *
 * Update is scoped to (reportID, siteID) AND requires the caller be either
 * the report's author or a site admin — a cross-tenant or non-owner
 * reportID is refused before any write.
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
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/reports/builder');
    exit();
}

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

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/reports/builder');
    exit();
}

$siteId    = Site::id();
$userId    = (int) ($_SESSION['user_id'] ?? 0);
$reportId  = (int) ($_POST['reportID'] ?? 0);
$name      = trim((string) ($_POST['reportName'] ?? ''));
$descRaw   = trim((string) ($_POST['description'] ?? ''));
$isShared  = (string) ($_POST['isShared'] ?? '') === '1';
$defRaw    = (string) ($_POST['definition'] ?? '');

$redirectBack = $reportId > 0 ? '/admin/reports/builder/edit?id=' . $reportId : '/admin/reports/builder/edit';

if ($name === '' || mb_strlen($name) > 150) {
    $_SESSION['flash_msg']  = 'A report name (1-150 characters) is required.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $redirectBack);
    exit();
}
$description = $descRaw === '' ? null : mb_substr($descRaw, 0, 500);

// 🛡️ On update, confirm the caller owns this report (or is a site admin)
// BEFORE decoding/compiling anything further — an IDOR-style reportID for
// another site's row is refused identically to a missing one.
if ($reportId > 0) {
    $existing = ReportBuilder::get($reportId, $siteId);
    if ($existing === null) {
        $_SESSION['flash_msg']  = 'Report not found.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/reports/builder');
        exit();
    }
    $isOwner = ((int) ($existing['createdByID'] ?? 0)) === $userId;
    if ($isOwner === false && App::isSiteAdmin() === false) {
        $_SESSION['flash_msg']  = 'You can only edit reports you created.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/reports/builder');
        exit();
    }
}

try {
    $definition = ReportBuilder::decodeDefinitionJson($defRaw);
    // 🛡️ THE pre-save gate — a full dry-compile against the registry.
    // Never echoes SQL, never echoes the exception's class/trace — only
    // the user-safe InvalidArgumentException message.
    ReportBuilder::validateDefinition($definition, $siteId);

    $newId = ReportBuilder::save($siteId, $userId, $reportId > 0 ? $reportId : null, $name, $description, $definition, $isShared);

    Logger::activity(
        $reportId > 0 ? 'ReportDefinitionUpdated' : 'ReportDefinitionCreated',
        'reportID=' . $newId . ' source=' . (string) ($definition['source'] ?? '')
    );

    $_SESSION['flash_msg']  = 'Report saved.';
    $_SESSION['flash_type'] = 'success';
    header('Location: /admin/reports/builder/edit?id=' . $newId);
    exit();
} catch (\InvalidArgumentException $e) {
    $_SESSION['flash_msg']  = 'Could not save report: ' . $e->getMessage();
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $redirectBack);
    exit();
} catch (\Throwable $e) {
    Logger::activity('ReportDefinitionSaveFailed', 'Unexpected error saving report definition');
    $_SESSION['flash_msg']  = 'An unexpected error occurred while saving this report.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $redirectBack);
    exit();
}
