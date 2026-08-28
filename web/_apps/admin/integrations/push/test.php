<?php
// Path: _apps/admin/integrations/push/test.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Web Push "Send test" POST handler 🔔🧪 (#322)
 * -----------------------------------------------------------------------------
 * Admin + CSRF gated. Sends ONE test notification to the CURRENT admin's
 * OWN active subscriptions only (`onlyUserIds=[me]` in spirit — implemented
 * directly against WebPush::send() per row rather than sendToChannel(),
 * since a test send deliberately bypasses the channel filter and
 * notifyPrefs check: an admin testing the feature wants to hear from
 * whichever device(s) they personally subscribed, regardless of which
 * channels they ticked). Never touches another user's subscriptions.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/322
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\WebPush;

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

$redirect = '/admin/integrations/push';

if (WebPush::isConfigured() === false) {
    $_SESSION['flash_msg']  = 'Push is not fully configured yet — generate/paste keys, set a contact, and enable it first.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $redirect, true, 302);
    exit();
}

$db      = App::db();
$siteId  = Site::id();
$adminId = (int) ($_SESSION['user_id'] ?? 0);

$stmt = $db->prepare(
    'SELECT subID, endpoint, p256dhKey, authKey FROM tblPushSubscriptions '
    . 'WHERE siteID = ? AND userID = ? AND isActive = 1'
);
$sent = 0;
$failed = 0;
$pruned = 0;
if ($stmt !== false) {
    $stmt->bind_param('ii', $siteId, $adminId);
    $stmt->execute();
    $rs = $stmt->get_result();
    $payload = json_encode([
        'title' => 'Test notification',
        'body'  => 'Web Push is working on this device.',
        'url'   => '/admin/integrations/push',
        'tag'   => 'push-test',
    ], JSON_UNESCAPED_SLASHES);
    while (($row = $rs->fetch_assoc()) !== null) {
        $status = WebPush::send($row, (string) $payload, 60, 'normal', 'push-test');
        if ($status >= 200 && $status < 300) {
            $sent++;
        } elseif ($status === 404 || $status === 410) {
            $pruned++;
        } else {
            $failed++;
        }
    }
    $stmt->close();
}

if ($sent === 0 && $failed === 0 && $pruned === 0) {
    $_SESSION['flash_msg']  = 'No active push subscriptions found for your account on this device. Enable push at /account/notifications first, on the device you want to test.';
    $_SESSION['flash_type'] = 'warning';
} else {
    $_SESSION['flash_msg']  = "Test push sent={$sent} failed={$failed} pruned={$pruned}.";
    $_SESSION['flash_type'] = $sent > 0 ? 'success' : 'danger';
}
header('Location: ' . $redirect, true, 302);
exit();
