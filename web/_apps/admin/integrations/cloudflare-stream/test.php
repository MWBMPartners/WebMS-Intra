<?php
// Path: _apps/admin/integrations/cloudflare-stream/test.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Cloudflare Stream "Test connection" POST handler 🔌 (#386 fold-in)
 * -----------------------------------------------------------------------------
 * Admin + CSRF gated. Calls `CloudflareStream::testConnection()` (a minimal
 * `GET /accounts/{acct}/stream?per_page=1`) and flashes success/failure back
 * to the settings page — parity with the BookIT Phase 2 "Test connection"
 * affordance, and the cheapest legitimate confidence-raiser for the
 * **[CF-kc]** endpoint set ahead of the first real upload.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/386
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\CloudflareStream;
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

$redirect = '/admin/integrations/cloudflare-stream';

$result = CloudflareStream::testConnection();

$_SESSION['flash_msg']  = $result['message'];
$_SESSION['flash_type'] = $result['success'] === true ? 'success' : 'danger';
header('Location: ' . $redirect, true, 302);
exit();
