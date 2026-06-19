<?php
// Path: _apps/admin/integrations/api-keys-save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Mint API key POST (#323 Phase 1)
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

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$name   = (string) ($_POST['name'] ?? '');
$scopes = trim((string) ($_POST['scopes'] ?? ''));
$exp    = trim((string) ($_POST['expiresAt'] ?? ''));

if (trim($name) === '') {
    $_SESSION['flash_msg']  = 'Name is required.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/integrations/api-keys', true, 302); exit();
}

$scopeList = $scopes === ''
    ? []
    : array_values(array_filter(array_map('trim', explode(',', $scopes)), static fn($s) => $s !== ''));

$expiresAt = null;
if ($exp !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp) === 1) {
    $expiresAt = new DateTimeImmutable($exp . ' 23:59:59');
}

$result = ApiKey::mint($siteId, $name, $scopeList, $expiresAt, $userId);

$_SESSION['api_key_minted'] = [
    'plaintext' => $result['plaintext'],
    'keyID'     => $result['keyID'],
    'prefix'    => $result['prefix'],
];
$_SESSION['flash_msg']  = 'API key minted.';
$_SESSION['flash_type'] = 'success';
header('Location: /admin/integrations/api-keys', true, 302);
exit();
