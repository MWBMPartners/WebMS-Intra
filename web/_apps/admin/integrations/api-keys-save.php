<?php
// Path: _apps/admin/integrations/api-keys-save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Mint API key POST (#323 Phase 1; scope validation added Phase 2)
 * -----------------------------------------------------------------------------
 * Scopes now arrive as a `scopes[]` checkbox array (rendered from
 * `ApiKey::SCOPES` by api-keys.php), but are re-validated here server-side
 * regardless — a raw POST could still submit arbitrary strings. Every
 * submitted token MUST be a member of `ApiKey::SCOPES`, OR the global
 * wildcard `*`, OR a `{resource}:*` wildcard for a KNOWN resource; anything
 * else is rejected outright (closes the review finding that scopes were
 * previously accepted as unvalidated free text).
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

$siteId    = Site::id();
$userId    = (int) ($_SESSION['user_id'] ?? 0);
$name      = (string) ($_POST['name'] ?? '');
$scopesRaw = $_POST['scopes'] ?? [];
$exp       = trim((string) ($_POST['expiresAt'] ?? ''));

if (trim($name) === '') {
    $_SESSION['flash_msg']  = 'Name is required.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/integrations/api-keys', true, 302); exit();
}

// -----------------------------------------------------------------------------
// 🛡️ Scope validation — every submitted token must be a member of the
// canonical ApiKey::SCOPES vocabulary, the global wildcard `*`, or a
// `{resource}:*` wildcard for a resource that vocabulary actually knows
// about. The mint form now renders checkboxes over exactly that list, but
// a raw POST (or a stale cached form) could still submit anything, so the
// server is the real gate — this closes the review finding that scopes
// were previously accepted as unvalidated free text.
// -----------------------------------------------------------------------------
if (is_array($scopesRaw) === false) {
    // 🔙 Backward-compatible fallback for a comma-separated string (old form
    //    shape / direct API callers) — same normalisation as before Phase 2.
    $scopesRaw = trim((string) $scopesRaw) === '' ? [] : explode(',', (string) $scopesRaw);
}

$scopeList = [];
foreach ($scopesRaw as $tok) {
    $tok = trim((string) $tok);
    if ($tok !== '') {
        $scopeList[] = $tok;
    }
}
$scopeList = array_values(array_unique($scopeList));

$knownResources = [];
foreach (ApiKey::SCOPES as $canonical) {
    [$res] = explode(':', $canonical, 2);
    $knownResources[$res] = true;
}

foreach ($scopeList as $tok) {
    if ($tok === '*') {
        continue;
    }
    if (in_array($tok, ApiKey::SCOPES, true) === true) {
        continue;
    }
    if (str_ends_with($tok, ':*') === true && array_key_exists(substr($tok, 0, -2), $knownResources) === true) {
        continue;
    }
    $_SESSION['flash_msg']  = 'Invalid scope "' . $tok . '" — must be one of the recognised resource:verb pairs, "*", or "{resource}:*".';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/integrations/api-keys', true, 302); exit();
}

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
