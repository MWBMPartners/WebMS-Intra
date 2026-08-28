<?php
// Path: _apps/admin/reports/builder/delete.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Reports Builder: delete handler 🗑️📊 (#156)
 * -----------------------------------------------------------------------------
 * CSRF'd POST (fronted by `data-confirm`, never native `confirm()`).
 * Author-or-site-admin only; site-scoped so a cross-tenant reportID is
 * indistinguishable from a missing one.
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
    $_SESSION['flash_msg']  = 'Invalid or expired form token.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/reports/builder');
    exit();
}

$siteId   = Site::id();
$userId   = (int) ($_SESSION['user_id'] ?? 0);
$reportId = (int) ($_POST['reportID'] ?? 0);

if ($reportId <= 0) {
    $_SESSION['flash_msg']  = 'Invalid report.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/reports/builder');
    exit();
}

$existing = ReportBuilder::get($reportId, $siteId);
if ($existing === null) {
    $_SESSION['flash_msg']  = 'Report not found.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/reports/builder');
    exit();
}

$isOwner = ((int) ($existing['createdByID'] ?? 0)) === $userId;
if ($isOwner === false && App::isSiteAdmin() === false) {
    $_SESSION['flash_msg']  = 'You can only delete reports you created.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/reports/builder');
    exit();
}

$deleted = ReportBuilder::delete($reportId, $siteId);
if ($deleted === true) {
    Logger::activity('ReportDefinitionDeleted', 'reportID=' . $reportId, $userId);
    $_SESSION['flash_msg']  = 'Report deleted.';
    $_SESSION['flash_type'] = 'success';
} else {
    $_SESSION['flash_msg']  = 'Report not found.';
    $_SESSION['flash_type'] = 'danger';
}

header('Location: /admin/reports/builder');
exit();
