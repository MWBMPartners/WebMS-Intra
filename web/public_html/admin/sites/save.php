<?php
// Path: public_html/admin/sites/save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Save Site 🌐
 * -----------------------------------------------------------------------------
 * POST handler for creating or updating a site record in tblSites.
 * Umbrella admin only. Redirects back to admin/sites with flash message.
 *
 * @package   Portal\App\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.9.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/45
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;

// 🛡️ POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/sites', true, 302);
    exit();
}

// 🛡️ Umbrella admin only
if (App::isUmbrellaAdmin() === false) {
    http_response_code(403);
    echo 'Access denied.';
    exit();
}

// 🛡️ CSRF
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/sites');
    exit();
}

$db = App::db();

// 📋 Sanitise inputs
$siteID       = (int) ($_POST['siteID'] ?? 0);
$siteName     = trim($_POST['siteName'] ?? '');
$siteKey      = trim(strtolower($_POST['siteKey'] ?? ''));
$hostPattern  = trim($_POST['hostPattern'] ?? '');
$logoPath     = trim($_POST['logoPath'] ?? '/assets/images/logo.svg');
$faviconPath  = trim($_POST['faviconPath'] ?? '');
$primaryColor = trim($_POST['primaryColor'] ?? '#5e6ad2');
$copyrightOrg = trim($_POST['copyrightOrg'] ?? '');
$timezone     = trim($_POST['timezone'] ?? 'UTC');
$isActive     = isset($_POST['isActive']) ? 1 : 0;

// 🔍 Validate primaryColor as #RGB or #RRGGBB hex; fall back to indigo default
if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $primaryColor) !== 1) {
    $primaryColor = '#5e6ad2';
}

// 🔍 Validate required fields
if ($siteName === '' || $siteKey === '') {
    $_SESSION['flash_msg'] = 'Site name and key are required.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/sites', true, 302);
    exit();
}

// 🔍 Validate siteKey format (alphanumeric + hyphens only)
if (preg_match('/^[a-z0-9\-]+$/', $siteKey) !== 1) {
    $_SESSION['flash_msg'] = 'Site key must contain only lowercase letters, numbers, and hyphens.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/sites', true, 302);
    exit();
}

// 🔍 Validate timezone
if (in_array($timezone, timezone_identifiers_list(), true) === false) {
    $timezone = 'UTC';
}

if ($siteID > 0) {
    // ♻️ UPDATE existing site
    $stmt = $db->prepare(
        'UPDATE tblSites SET siteName = ?, siteKey = ?, hostPattern = ?, logoPath = ?, '
        . 'faviconPath = ?, primaryColor = ?, copyrightOrg = ?, timezone = ?, isActive = ? '
        . 'WHERE siteID = ?'
    );
    if ($stmt === false) {
        $_SESSION['flash_msg'] = 'Database error: ' . $db->error;
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/sites', true, 302);
        exit();
    }

    $hostPatternVal = ($hostPattern !== '') ? $hostPattern : null;
    $copyrightVal   = ($copyrightOrg !== '') ? $copyrightOrg : null;
    $faviconVal     = ($faviconPath !== '') ? $faviconPath : null;

    $stmt->bind_param(
        'ssssssssii',
        $siteName, $siteKey, $hostPatternVal, $logoPath, $faviconVal,
        $primaryColor, $copyrightVal, $timezone, $isActive, $siteID
    );

    if ($stmt->execute() === true) {
        Logger::activity('SiteUpdate', 'Updated site #' . $siteID . ' (' . $siteName . ')');
        $_SESSION['flash_msg'] = 'Site "' . $siteName . '" updated successfully.';
        $_SESSION['flash_type'] = 'success';
    } else {
        $_SESSION['flash_msg'] = 'Failed to update site: ' . $stmt->error;
        $_SESSION['flash_type'] = 'danger';
    }
    $stmt->close();
} else {
    // ➕ INSERT new site
    $stmt = $db->prepare(
        'INSERT INTO tblSites (siteName, siteKey, hostPattern, logoPath, faviconPath, primaryColor, copyrightOrg, timezone, isActive) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if ($stmt === false) {
        $_SESSION['flash_msg'] = 'Database error: ' . $db->error;
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/sites', true, 302);
        exit();
    }

    $hostPatternVal = ($hostPattern !== '') ? $hostPattern : null;
    $copyrightVal   = ($copyrightOrg !== '') ? $copyrightOrg : null;
    $faviconVal     = ($faviconPath !== '') ? $faviconPath : null;

    $stmt->bind_param(
        'ssssssssi',
        $siteName, $siteKey, $hostPatternVal, $logoPath, $faviconVal,
        $primaryColor, $copyrightVal, $timezone, $isActive
    );

    if ($stmt->execute() === true) {
        $newSiteId = $stmt->insert_id;
        Logger::activity('SiteCreate', 'Created site #' . $newSiteId . ' (' . $siteName . ')');
        $_SESSION['flash_msg'] = 'Site "' . $siteName . '" created successfully.';
        $_SESSION['flash_type'] = 'success';
    } else {
        $_SESSION['flash_msg'] = 'Failed to create site: ' . $stmt->error;
        $_SESSION['flash_type'] = 'danger';
    }
    $stmt->close();
}

header('Location: /admin/sites', true, 302);
exit();
