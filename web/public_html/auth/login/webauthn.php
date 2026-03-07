<?php
// Path: public_html/auth/login/webauthn.php
/**
 * -----------------------------------------------------------------------------
 * Login — WebAuthn Authentication Endpoint
 * -----------------------------------------------------------------------------
 * Handles two AJAX actions for passwordless login:
 *   1. auth_options — generate PublicKeyCredentialRequestOptions
 *   2. auth_verify  — verify assertion response, create session
 *
 * Called via AJAX from the login page. Returns JSON responses.
 *
 * @package   Portal\Auth
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.5.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\WebAuthn;

Auth::ensureSession();

header('Content-Type: application/json; charset=utf-8');

// 📋 Parse JSON body
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

// 📋 Get WebAuthn RP settings
$rpID   = App::settings('auth.webauthn.rpID') ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
$scheme = (isset($_SERVER['HTTPS']) === true && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$origin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

// =========================================================================
// 📋 Action: auth_options — generate assertion options
// =========================================================================
if ($action === 'auth_options') {
    // 📋 For discoverable credentials (resident keys), we allow empty credentials
    // The browser will find the right credential stored on the authenticator
    $options = WebAuthn::authenticationOptions([], $rpID);

    echo json_encode(['success' => true, 'options' => $options]);
    exit();
}

// =========================================================================
// 📋 Action: auth_verify — verify assertion and create session
// =========================================================================
if ($action === 'auth_verify') {
    $credential = $input['credential'] ?? [];

    if (empty($credential) === true) {
        echo json_encode(['success' => false, 'error' => 'No credential data received.']);
        exit();
    }

    $credentialId = $credential['id'] ?? '';

    // 🔍 Look up the credential in the database
    $stmt = $mysqli->prepare(
        'SELECT WA.credID, WA.userID, WA.publicKey, WA.signCount, WA.friendlyName, '
        . 'U.fullName, U.emailAddress, U.isActive '
        . 'FROM tblWebAuthnCredentials WA '
        . 'JOIN tblUsers U ON U.userID = WA.userID '
        . 'WHERE WA.credentialID = ? '
        . 'LIMIT 1'
    );

    if ($stmt === false) {
        echo json_encode(['success' => false, 'error' => 'Database error.']);
        exit();
    }

    $stmt->bind_param('s', $credentialId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row === null) {
        echo json_encode(['success' => false, 'error' => 'Credential not recognised.']);
        exit();
    }

    if ((int) $row['isActive'] !== 1) {
        echo json_encode(['success' => false, 'error' => 'Account is inactive.']);
        exit();
    }

    try {
        $result = WebAuthn::verifyAuthentication(
            $credential,
            $row['publicKey'],
            (int) $row['signCount'],
            $rpID,
            $origin
        );

        if ($result['verified'] === true) {
            // 📋 Update sign count and last used timestamp
            $upd = $mysqli->prepare('UPDATE tblWebAuthnCredentials SET signCount = ?, lastUsedAt = NOW() WHERE credID = ?');
            if ($upd !== false) {
                $credDBID = (int) $row['credID'];
                $upd->bind_param('ii', $result['newSignCount'], $credDBID);
                $upd->execute();
                $upd->close();
            }

            // 🔄 Create session
            session_regenerate_id(true);

            $_SESSION['user_id']    = (int) $row['userID'];
            $_SESSION['user_name']  = $row['fullName'];
            $_SESSION['user_email'] = $row['emailAddress'];

            Logger::activity('LoginWebAuthn', 'User logged in via passkey: ' . ($row['friendlyName'] ?? 'Passkey'));

            $redirect = $input['redirect'] ?? '/';
            if (str_starts_with($redirect, '/') === false || str_starts_with($redirect, '//') === true) {
                $redirect = '/';
            }

            echo json_encode(['success' => true, 'redirect' => $redirect]);
            exit();
        }
    } catch (\Throwable $ex) {
        Logger::errorPlatform('WebAuthn', 'Error', 'AUTH_FAIL', $ex->getMessage(), '');
        echo json_encode(['success' => false, 'error' => 'Authentication failed.']);
        exit();
    }

    echo json_encode(['success' => false, 'error' => 'Authentication failed.']);
    exit();
}

echo json_encode(['success' => false, 'error' => 'Unknown action.']);
exit();
