<?php
// Path: _apps/admin/integrations/api-keys-rotate.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Rotate API key POST (#323 Phase 1)
 * -----------------------------------------------------------------------------
 * Revoke + re-mint with the same metadata. New plaintext flashed once.
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

$stmt = $mysqli->prepare('SELECT keyID FROM tblApiKeys WHERE keyID = ? AND siteID = ? AND isActive = 1');
$stmt->bind_param('ii', $keyId, $siteId);
$stmt->execute();
$ok = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($ok === false) { http_response_code(404); exit('Active key not found'); }

try {
    $result = ApiKey::rotate($keyId, $userId);
    $_SESSION['api_key_minted'] = [
        'plaintext' => $result['plaintext'],
        'keyID'     => $result['keyID'],
        'prefix'    => $result['prefix'],
    ];
    $_SESSION['flash_msg']  = 'API key rotated. Copy the new plaintext now.';
    $_SESSION['flash_type'] = 'success';
} catch (\RuntimeException $e) {
    $_SESSION['flash_msg']  = 'Rotate failed: ' . $e->getMessage();
    $_SESSION['flash_type'] = 'danger';
}

header('Location: /admin/integrations/api-keys', true, 302);
exit();
