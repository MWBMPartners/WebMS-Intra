<?php
// Path: public_html/site/switch.php
/**
 * -----------------------------------------------------------------------------
 * Site Switcher Handler 🌐
 * -----------------------------------------------------------------------------
 * POST handler for switching the active site in multi-site mode.
 * Validates CSRF, checks user belongs to the target site, updates
 * the session, and redirects to the dashboard.
 *
 * @package   Portal\App\Site
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
use Portal\Core\Site;

// 🛡️ Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /', true, 302);
    exit();
}

// 🛡️ CSRF protection
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /');
    exit();
}

// 🔍 Get target site ID
$targetSiteId = (int) ($_POST['site_id'] ?? 0);
if ($targetSiteId <= 0) {
    header('Location: /', true, 302);
    exit();
}

// 🛡️ Check user is logged in
$user = App::user();
if ($user === null) {
    header('Location: /login', true, 302);
    exit();
}

$userId = (int) $user['userID'];
$db     = App::db();

// 🔍 Verify user belongs to the target site
if (Site::userBelongsTo($userId, $targetSiteId, $db) === false) {
    Logger::activity('SiteSwitchDenied', 'User attempted to switch to site #' . $targetSiteId . ' without access');
    header('Location: /', true, 302);
    exit();
}

// 🔄 Switch to the target site
Site::set($targetSiteId, $db);

// 📝 Log the switch
Logger::activity('SiteSwitch', 'Switched to site #' . $targetSiteId);

// 🔄 Redirect to dashboard
header('Location: /', true, 302);
exit();
