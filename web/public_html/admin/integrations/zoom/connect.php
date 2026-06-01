<?php
// Path: public_html/admin/integrations/zoom/connect.php
/**
 * Admin — Zoom OAuth initiator. Stores a CSRF state token in the session
 * and redirects to Zoom's authorise endpoint. Same endpoint serves both
 * org-level connect (admin) and per-user connect (any logged-in user)
 * by setting a session marker the callback inspects.
 *
 * @package   Portal\Admin
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/274
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Zoom;

Auth::ensureSession();
Auth::requireLogin();

$mode = (string) ($_GET['mode'] ?? 'org');
if ($mode !== 'user') {
    $mode = 'org';
}
if ($mode === 'org' && App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$settings     = App::settings()['zoom'] ?? [];
$clientId     = (string) ($settings['clientID'] ?? '');
$clientSecret = (string) ($settings['clientSecret'] ?? '');
if ($clientId === '' || $clientSecret === '') {
    $_SESSION['flash_msg']  = 'Zoom Client ID / Secret are not configured.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/integrations/zoom');
    exit();
}

$state = bin2hex(random_bytes(16));
$_SESSION['zoom_oauth_state'] = $state;
$_SESSION['zoom_oauth_mode']  = $mode;

$scheme = (($_SERVER['HTTPS'] ?? '') !== '' && (string) ($_SERVER['HTTPS'] ?? '') !== 'off') ? 'https' : 'http';
$redirect = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/admin/integrations/zoom/callback';

header('Location: ' . Zoom::authorizeUrl($clientId, $redirect, $state));
exit();
