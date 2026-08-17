<?php
// Path: public_html/index.php  (front-controller)
/**
 * -----------------------------------------------------------------------------
 * Portal Front Controller 🎯
 * -----------------------------------------------------------------------------
 * Single entry point for all live traffic.  Loads bootstrap then hands the
 * request to Core\Router.  Default route keys come from tblRoutes; if URL is
 * empty, Router maps it to "dashboard" (see Router::extractPath).
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// 🔧 Check if the portal has been installed (credentials file exists)
$authCredsPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '_auth_keys' . DIRECTORY_SEPARATOR . 'auth_creds.php';
if (is_readable($authCredsPath) === false) {
    // 📌 Redirect to the installation wizard
    $installPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '_install' . DIRECTORY_SEPARATOR . 'index.php';
    if (is_readable($installPath) === true) {
        require $installPath;
        exit();
    }
    http_response_code(500);
    exit('Portal not configured. Please run the installer.');
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\Auth;
use Portal\Core\Maintenance;
use Portal\Core\Router;

// 🔑 Start (or resume) the session BEFORE the maintenance gate below.
//    Maintenance::currentUserCanBypass() → App::isAdmin() → App::user()
//    reads $_SESSION, but only if session_status() === PHP_SESSION_ACTIVE
//    (see App::user()) — and nothing upstream in bootstrap.php starts a
//    session (it's started lazily by individual app pages via this same
//    Auth::ensureSession() call). Without it here, a logged-in admin's
//    session cookie is never read this early, App::user() caches a null
//    "not logged in" result for the rest of the request (self::$userLoaded
//    latches true on first call), and currentUserCanBypass() always
//    returns false — so admins get the 503 page along with everyone else
//    with no way to sign in and lift maintenance mode. ensureSession() is
//    idempotent (no-ops if already active), so this can't double-start the
//    session that app handlers start further down the line.
Auth::ensureSession();

// 🚧 Maintenance gate (#220).
//    If portal.maintenance.active = '1' OR the installed_version is
//    behind PORTAL_VERSION (indicating new code was deployed but the
//    DB hasn't been brought up yet), gate non-admin / non-allow-listed
//    requests to a 503 maintenance page. Admins and routes on the
//    allow list (auth/login, admin/upgrade, admin/maintenance, assets)
//    pass through so admins can sign in and run the upgrade.
if (Maintenance::isActive() === true
    && Maintenance::isAllowed(Router::extractPath()) === false
    && Maintenance::currentUserCanBypass() === false
) {
    Maintenance::renderAndExit();
}

Router::dispatch($mysqli);