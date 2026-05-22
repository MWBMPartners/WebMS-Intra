<?php
// Path: public_html/privacy/consent.php
/**
 * -----------------------------------------------------------------------------
 * Privacy — Consent recorder 📝
 * -----------------------------------------------------------------------------
 * POST-only endpoint called by the cookie consent banner (cookie-banner.js).
 * Records the user's decision in tblConsentLog and sets a long-lived cookie
 * so we don't show the banner again on this device.
 *
 * Request body (form-encoded):
 *   csrf_token   — required
 *   type         — 'cookies' | 'privacy_policy' | 'marketing' | 'analytics'
 *   decision     — 'accept' | 'reject' | 'withdraw'
 *
 * @package   Portal\Privacy
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'method_not_allowed']);
    exit();
}

Auth::ensureSession();

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(403);
    echo json_encode(['status' => 'csrf_failed']);
    exit();
}

$type     = (string) ($_POST['type']     ?? '');
$decision = (string) ($_POST['decision'] ?? '');

$validTypes     = ['cookies', 'privacy_policy', 'marketing', 'analytics'];
$validDecisions = ['accept', 'reject', 'withdraw'];

if (in_array($type, $validTypes, true) === false
    || in_array($decision, $validDecisions, true) === false
) {
    http_response_code(400);
    echo json_encode(['status' => 'bad_request']);
    exit();
}

$siteId    = Site::id();
$userId    = ($_SESSION['user_id'] ?? null) === null ? null : (int) $_SESSION['user_id'];
$sessionId = session_id() ?: null;
$ip = $_SERVER['HTTP_CF_CONNECTING_IP']
    ?? $_SERVER['HTTP_X_FORWARDED_FOR']
    ?? $_SERVER['REMOTE_ADDR']
    ?? '';
if (str_contains((string) $ip, ',') === true) {
    $ip = trim(explode(',', (string) $ip)[0]);
}
$ip        = substr((string) $ip, 0, 45);
$ua        = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

// 📝 Snapshot the current privacy policy URL/text for the audit hash.
$policyText = (string) (App::settings('privacy.policyURL') ?? '')
            . '|' . (string) (App::settings('privacy.controllerName') ?? '')
            . '|' . (string) (App::settings('privacy.dataRetentionDays') ?? '');
$policyHash = hash('sha256', $policyText);

$stmt = $mysqli->prepare(
    'INSERT INTO tblConsentLog '
    . '(siteID, userID, sessionID, consentType, decision, policyHash, ipAddress, userAgent) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
if ($stmt === false) {
    http_response_code(500);
    echo json_encode(['status' => 'db_error']);
    exit();
}
$stmt->bind_param('iissssss', $siteId, $userId, $sessionId, $type, $decision, $policyHash, $ip, $ua);
$stmt->execute();
$stmt->close();

// 🍪 Set a long-lived cookie so the banner doesn't reappear on this device.
//    Stores only the decision token; the audit trail is in tblConsentLog.
setcookie('portal_consent_' . $type, $decision, [
    'expires'  => time() + (365 * 86400),
    'path'     => '/',
    'secure'   => (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off'),
    'httponly' => false,  // JS reads it to suppress the banner
    'samesite' => 'Lax',
]);

echo json_encode(['status' => 'ok', 'type' => $type, 'decision' => $decision]);
