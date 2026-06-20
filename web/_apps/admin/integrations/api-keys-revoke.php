<?php
// Path: _apps/admin/integrations/api-keys-revoke.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Revoke API key POST (#323 Phase 1)
 * -----------------------------------------------------------------------------
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

// 🛡️ Cross-site guard — key must belong to this site.
$stmt = $mysqli->prepare('SELECT keyID FROM tblApiKeys WHERE keyID = ? AND siteID = ?');
$stmt->bind_param('ii', $keyId, $siteId);
$stmt->execute();
$ok = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($ok === false) { http_response_code(404); exit('Key not found'); }

ApiKey::revoke($keyId, $userId);

$_SESSION['flash_msg']  = 'API key revoked.';
$_SESSION['flash_type'] = 'warning';
header('Location: /admin/integrations/api-keys', true, 302);
exit();
