<?php
// Path: public_html/auth/account/webauthn.php
/**
 * -----------------------------------------------------------------------------
 * Account — WebAuthn / PassKey Registration Endpoint
 * -----------------------------------------------------------------------------
 * Handles two AJAX actions:
 *   1. register_options  — generate and return PublicKeyCredentialCreationOptions
 *   2. register_verify   — verify attestation response, store credential in DB
 *
 * Both return JSON responses.
 *
 * @package   Portal\Auth
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.5.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\WebAuthn;

Auth::ensureSession();
Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');

$userId    = (int) ($_SESSION['user_id'] ?? 0);
$userName  = $_SESSION['user_name'] ?? '';
$userEmail = $_SESSION['user_email'] ?? '';

// 📋 Determine request format (form-encoded or JSON body)
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json') === true) {
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';
    $csrf   = $input['csrf_token'] ?? '';
} else {
    $input  = $_POST;
    $action = $_POST['action'] ?? '';
    $csrf   = $_POST['csrf_token'] ?? '';
}

// 🔑 EVERY ANSWER CARRIES THE REPLACEMENT TOKEN (#770).
//
//    `Auth::verifyCsrf()` ROTATES the token when it accepts one. Adding a
//    passkey is TWO requests — `register_options` then `register_verify` —
//    and both read the token out of the SAME hidden field on the page. So the
//    first request spent the token, this handler returned no replacement, and
//    the second request sent the spent one and was refused: a member could not
//    add a passkey AT ALL, and the message they saw was "Invalid session
//    token."
//
//    `csrfToken` is the spelling `Portal.rememberCsrf()` already accepts
//    (#768), and since that fix it writes the replacement into every carrier
//    the page has — including the hidden field the second request reads.
//
//    ⚠️ IT GOES ON THE REFUSALS TOO, not only the successes. A refusal reached
//       AFTER `verifyCsrf()` has accepted the token has still spent it, so an
//       answer without a replacement leaves the page unable to try again —
//       which turns one failed attempt into a dead page.

// 🛡️ CSRF check
if (Auth::verifyCsrf($csrf) === false) {
    echo json_encode(['success' => false, 'error' => 'Invalid session token.']);
    exit();
}

// 📋 Get WebAuthn RP settings
$rpName = App::settings('auth.webauthn.rpName') ?? ($SETTINGS['site']['name'] ?? 'Portal');
$rpID   = App::settings('auth.webauthn.rpID') ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');

// 📋 Determine origin from request
$scheme = (isset($_SERVER['HTTPS']) === true && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$origin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

// =========================================================================
// 📋 Action: register_options — generate creation options
// =========================================================================
if ($action === 'register_options') {
    // 📋 Get already-registered credential IDs to exclude
    $excludeIDs = [];
    $stmt = $mysqli->prepare('SELECT credentialID FROM tblWebAuthnCredentials WHERE userID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $excludeIDs[] = $row['credentialID'];
        }
        $stmt->close();
    }

    $options = WebAuthn::registrationOptions($userId, $userName, $userEmail, $rpName, $rpID, $excludeIDs);

    echo json_encode(['success' => true, 'options' => $options, 'csrfToken' => Auth::csrfToken()]);
    exit();
}

// =========================================================================
// 📋 Action: register_verify — verify attestation and store credential
// =========================================================================
if ($action === 'register_verify') {
    $credential   = $input['credential'] ?? [];
    $friendlyName = trim($input['friendlyName'] ?? 'Passkey');

    if (empty($credential) === true) {
        echo json_encode(['success' => false, 'error' => 'No credential data received.', 'csrfToken' => Auth::csrfToken()]);
        exit();
    }

    try {
        $result = WebAuthn::verifyRegistration($credential, $rpID, $origin);

        // 💾 Store credential in database
        $stmt = $mysqli->prepare(
            'INSERT INTO tblWebAuthnCredentials (userID, credentialID, publicKey, signCount, friendlyName, aaguid, transports) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            throw new \RuntimeException('DB prepare failed: ' . $mysqli->error);
        }

        $stmt->bind_param(
            'ississs',
            $userId,
            $result['credentialID'],
            $result['publicKey'],
            $result['signCount'],
            $friendlyName,
            $result['aaguid'],
            $result['transports']
        );
        $stmt->execute();
        $stmt->close();

        Logger::activity('WebAuthnRegister', 'Registered passkey: ' . $friendlyName);

        echo json_encode(['success' => true, 'csrfToken' => Auth::csrfToken()]);
        exit();
    } catch (\Throwable $ex) {
        Logger::errorPlatform('WebAuthn', 'Error', 'REGISTER_FAIL', $ex->getMessage(), '');
        echo json_encode(['success' => false, 'error' => 'Registration failed: ' . $ex->getMessage(), 'csrfToken' => Auth::csrfToken()]);
        exit();
    }
}

echo json_encode(['success' => false, 'error' => 'Unknown action.', 'csrfToken' => Auth::csrfToken()]);
exit();
