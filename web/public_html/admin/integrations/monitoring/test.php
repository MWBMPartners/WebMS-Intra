<?php
// Path: public_html/admin/integrations/monitoring/test.php
/**
 * Admin — Send a smoke-test event to the configured monitor.
 *
 * @package   Portal\Admin
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/143
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\ErrorMonitor;
use Portal\Core\Logger;
use Portal\Core\Router;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$adminId = (int) ($_SESSION['user_id'] ?? 0);
$ok = ErrorMonitor::sendTestEvent('Portal admin smoke test — ' . date('c'));

Logger::activity('ErrorMonitorTest', $ok === true ? 'Test event accepted' : 'Test event failed', $adminId);

if ($ok === true) {
    $_SESSION['flash_msg']  = 'Test event accepted by the monitor — check the project dashboard for it.';
    $_SESSION['flash_type'] = 'success';
} else {
    $_SESSION['flash_msg']  = 'Test event was NOT accepted — verify DSN and that the host is reachable.';
    $_SESSION['flash_type'] = 'danger';
}
header('Location: /admin/integrations/monitoring');
exit();
