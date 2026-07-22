<?php
// Path: _apps/admin/integrations/api-keys-rotate.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Rotate API key POST (#323 Phase 1; grace window added Phase 2)
 * -----------------------------------------------------------------------------
 * Revoke + re-mint with the same metadata. New plaintext flashed once. The
 * admin picks a rotation grace period (0/1/24/72h) from the form; 0 keeps
 * the original immediate-revoke behaviour, >0 lets the old key keep working
 * until the grace cutoff so in-flight callers can switch over.
 */

declare(strict_types=1);

use Portal\Core\ApiKey;
use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/integrations/api-keys', true, 302); exit();
}

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) { http_response_code(403); exit('Forbidden'); }
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) { http_response_code(400); exit('Bad request'); }

$keyId  = (int) ($_POST['keyID'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
$siteId = Site::id();

// 🕐 Rotation grace (hours) — constrained to the options the form actually
//    offers; anything else falls back to null (ApiKey::rotate resolves that
//    against the `api.keys.rotationGraceHours` setting, default 24).
$allowedGrace = [0, 1, 24, 72];
$graceHours   = array_key_exists('graceHours', $_POST) === true ? (int) $_POST['graceHours'] : null;
if ($graceHours !== null && in_array($graceHours, $allowedGrace, true) === false) {
    $graceHours = null;
}

$stmt = $mysqli->prepare('SELECT keyID FROM tblApiKeys WHERE keyID = ? AND siteID = ? AND isActive = 1');
$stmt->bind_param('ii', $keyId, $siteId);
$stmt->execute();
$ok = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($ok === false) { http_response_code(404); exit('Active key not found'); }

try {
    $result = ApiKey::rotate($keyId, $userId, $graceHours);
    $_SESSION['api_key_minted'] = [
        'plaintext' => $result['plaintext'],
        'keyID'     => $result['keyID'],
        'prefix'    => $result['prefix'],
    ];
    $_SESSION['flash_msg']  = $graceHours === 0
        ? 'API key rotated (immediate). Copy the new plaintext now.'
        : 'API key rotated. Copy the new plaintext now — the old key keeps working during its grace window.';
    $_SESSION['flash_type'] = 'success';
} catch (\RuntimeException $e) {
    $_SESSION['flash_msg']  = 'Rotate failed: ' . $e->getMessage();
    $_SESSION['flash_type'] = 'danger';
}

header('Location: /admin/integrations/api-keys', true, 302);
exit();
