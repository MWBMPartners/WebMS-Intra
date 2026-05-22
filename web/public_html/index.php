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

use Portal\Core\Router;

Router::dispatch($mysqli);