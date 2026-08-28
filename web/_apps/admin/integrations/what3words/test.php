<?php
// Path: _apps/admin/integrations/what3words/test.php
/**
 * -----------------------------------------------------------------------------
 * Admin — what3words "Test connection" POST handler 🔌 (#456 Chunk A)
 * -----------------------------------------------------------------------------
 * Clone of cloudflare-stream/test.php. Calls `What3Words::testConnection()`
 * and flashes the machine-safe result — the key is never surfaced.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/456
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\What3Words;

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

$redirect = '/admin/integrations/what3words';

$result = What3Words::testConnection();

$_SESSION['flash_msg']  = $result['message'];
$_SESSION['flash_type'] = $result['success'] === true ? 'success' : 'danger';
header('Location: ' . $redirect, true, 302);
exit();
