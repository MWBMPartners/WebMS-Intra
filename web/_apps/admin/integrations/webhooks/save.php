<?php
// Path: _apps/admin/integrations/webhooks/save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Create/update webhook POST (#324)
 * -----------------------------------------------------------------------------
 * Two modes, distinguished by presence of a positive `webhookID`:
 *
 *   (a) CREATE — validates name / targetUrl / eventTypes, mints a fresh
 *       HMAC signing secret (bin2hex(random_bytes(32))), INSERTs the row,
 *       and flashes the plaintext secret ONCE for index.php to display.
 *
 *   (b) UPDATE — re-validates and UPDATEs an existing webhook scoped to
 *       this site. Only fields explicitly present in the POST body
 *       override the stored value, so index.php's "pause/reactivate"
 *       button can post just `webhookID` + `isActive` without clobbering
 *       name/targetUrl/eventTypes on a simple toggle.
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

// 🛡️ Admin gate — identical to api-keys-save.php.
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

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

/**
 * 🔍 Validate name + targetUrl. Returns an error string, or '' if OK.
 *
 * @param string $name      Webhook display name (≤100 chars, required).
 * @param string $targetUrl Delivery URL (required, http(s) only, ≤500 chars).
 */
$validateFields = static function (string $name, string $targetUrl): string {
    if ($name === '') {
        return 'Name is required.';
    }
    if (mb_strlen($name) > 100) {
        return 'Name must be 100 characters or fewer.';
    }
    if ($targetUrl === '') {
        return 'Target URL is required.';
    }
    if (mb_strlen($targetUrl) > 500) {
        return 'Target URL must be 500 characters or fewer.';
    }
    $validated = filter_var($targetUrl, FILTER_VALIDATE_URL);
    if ($validated === false) {
        return 'Target URL must be a valid URL.';
    }
    $scheme = parse_url($targetUrl, PHP_URL_SCHEME);
    if (in_array($scheme, ['http', 'https'], true) === false) {
        return 'Target URL must use http:// or https://.';
    }
    return '';
};

$webhookId = (int) ($_POST['webhookID'] ?? 0);

// ============================================================================
// (b) UPDATE — existing webhook, scoped to this site
// ============================================================================
if ($webhookId > 0) {
    $stmt = $mysqli->prepare('SELECT name, eventTypes, targetUrl, isActive FROM tblWebhooks WHERE webhookID = ? AND siteID = ?');
    $stmt->bind_param('ii', $webhookId, $siteId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing === null) {
        http_response_code(404);
        exit('Webhook not found');
    }

    $name       = isset($_POST['name']) === true ? trim((string) $_POST['name']) : (string) $existing['name'];
    $targetUrl  = isset($_POST['targetUrl']) === true ? trim((string) $_POST['targetUrl']) : (string) $existing['targetUrl'];
    $eventTypes = isset($_POST['eventTypes']) === true ? trim((string) $_POST['eventTypes']) : (string) $existing['eventTypes'];
    if ($eventTypes === '') {
        $eventTypes = 'all';
    }
    $isActive = ((string) ($_POST['isActive'] ?? (string) $existing['isActive']) === '1') ? 1 : 0;

    $error = $validateFields($name, $targetUrl);
    if ($error !== '') {
        $_SESSION['flash_msg']  = $error;
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/integrations/webhooks', true, 302);
        exit();
    }

    $stmt = $mysqli->prepare(
        'UPDATE tblWebhooks SET name = ?, eventTypes = ?, targetUrl = ?, isActive = ? '
        . 'WHERE webhookID = ? AND siteID = ?'
    );
    if ($stmt === false) {
        Logger::errorPlatform('MySQL', 'Error', 'WEBHOOK_SAVE', $mysqli->error, '');
        $_SESSION['flash_msg']  = 'Could not update webhook — database error.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/integrations/webhooks', true, 302);
        exit();
    }
    $stmt->bind_param('sssiii', $name, $eventTypes, $targetUrl, $isActive, $webhookId, $siteId);
    $ok = $stmt->execute();
    if ($ok === false) {
        Logger::errorPlatform('MySQL', 'Error', 'WEBHOOK_SAVE', $mysqli->error, '');
        $stmt->close();
        $_SESSION['flash_msg']  = 'Could not update webhook — database error.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /admin/integrations/webhooks', true, 302);
        exit();
    }
    $stmt->close();

    Logger::activity('WebhookUpdated', 'Webhook #' . $webhookId . ' isActive=' . $isActive . ' siteID=' . $siteId, $userId);

    $_SESSION['flash_msg']  = 'Webhook updated.';
    $_SESSION['flash_type'] = 'success';
    header('Location: /admin/integrations/webhooks', true, 302);
    exit();
}

// ============================================================================
// (a) CREATE — new webhook
// ============================================================================
$name       = trim((string) ($_POST['name'] ?? ''));
$targetUrl  = trim((string) ($_POST['targetUrl'] ?? ''));
$eventTypes = trim((string) ($_POST['eventTypes'] ?? ''));
if ($eventTypes === '') {
    $eventTypes = 'all';
}
$isActive = ((string) ($_POST['isActive'] ?? '0') === '1') ? 1 : 0;

$error = $validateFields($name, $targetUrl);
if ($error !== '') {
    $_SESSION['flash_msg']  = $error;
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/integrations/webhooks', true, 302);
    exit();
}

// 🔐 HMAC-SHA256 signing secret — 32 random bytes → 64 hex chars.
//    Shown to the admin exactly ONCE via the flash below.
$signingSecret = bin2hex(random_bytes(32));

$stmt = $mysqli->prepare(
    'INSERT INTO tblWebhooks (siteID, name, eventTypes, targetUrl, signingSecret, isActive, createdByID) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
);
if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'WEBHOOK_SAVE', $mysqli->error, '');
    $_SESSION['flash_msg']  = 'Could not create webhook — database error.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/integrations/webhooks', true, 302);
    exit();
}
$stmt->bind_param('issssii', $siteId, $name, $eventTypes, $targetUrl, $signingSecret, $isActive, $userId);
$ok = $stmt->execute();
if ($ok === false) {
    Logger::errorPlatform('MySQL', 'Error', 'WEBHOOK_SAVE', $mysqli->error, '');
    $stmt->close();
    $_SESSION['flash_msg']  = 'Could not create webhook — database error.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/integrations/webhooks', true, 302);
    exit();
}
$newId = (int) $mysqli->insert_id;
$stmt->close();

Logger::activity('WebhookCreated', 'Webhook #' . $newId . ' (' . $name . ') siteID=' . $siteId, $userId);

$_SESSION['webhook_secret_minted'] = [
    'plaintext' => $signingSecret,
    'webhookID' => $newId,
    'name'      => $name,
];
$_SESSION['flash_msg']  = 'Webhook created.';
$_SESSION['flash_type'] = 'success';
header('Location: /admin/integrations/webhooks', true, 302);
exit();
