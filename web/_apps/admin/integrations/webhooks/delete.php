<?php
// Path: _apps/admin/integrations/webhooks/delete.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Delete webhook POST (#324)
 * -----------------------------------------------------------------------------
 * Hard-deletes a tblWebhooks row, scoped to the active site. Deliveries in
 * tblWebhookDeliveries cascade via `fk_wd_webhook ... ON DELETE CASCADE`.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/324
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/integrations/webhooks', true, 302);
    exit();
}

// 🛡️ Admin gate — identical to api-keys-revoke.php.
Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$webhookId = (int) ($_POST['webhookID'] ?? 0);
$userId    = (int) ($_SESSION['user_id'] ?? 0);
$siteId    = Site::id();

// 🛡️ Cross-site guard — webhook must belong to this site.
$stmt = $mysqli->prepare('SELECT webhookID, name FROM tblWebhooks WHERE webhookID = ? AND siteID = ?');
$stmt->bind_param('ii', $webhookId, $siteId);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($existing === null) {
    http_response_code(404);
    exit('Webhook not found');
}

$stmt = $mysqli->prepare('DELETE FROM tblWebhooks WHERE webhookID = ? AND siteID = ?');
if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'WEBHOOK_DELETE', $mysqli->error, '');
    $_SESSION['flash_msg']  = 'Could not delete webhook — database error.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/integrations/webhooks', true, 302);
    exit();
}
$stmt->bind_param('ii', $webhookId, $siteId);
$ok = $stmt->execute();
if ($ok === false) {
    Logger::errorPlatform('MySQL', 'Error', 'WEBHOOK_DELETE', $mysqli->error, '');
    $stmt->close();
    $_SESSION['flash_msg']  = 'Could not delete webhook — database error.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/integrations/webhooks', true, 302);
    exit();
}
$stmt->close();

Logger::activity('WebhookDeleted', 'Webhook #' . $webhookId . ' (' . (string) $existing['name'] . ') siteID=' . $siteId, $userId);

$_SESSION['flash_msg']  = 'Webhook deleted.';
$_SESSION['flash_type'] = 'warning';
header('Location: /admin/integrations/webhooks', true, 302);
exit();
