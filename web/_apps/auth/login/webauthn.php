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
 * @version   0.6.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

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

            $webauthnUserId = (int) $row['userID'];

            // 🔄 Create session
            session_regenerate_id(true);

            $_SESSION['user_id']    = $webauthnUserId;
            $_SESSION['user_name']  = $row['fullName'];
            $_SESSION['user_email'] = $row['emailAddress'];

            // 🌐 Set active site ID for multi-site context
            Auth::initSessionSite($webauthnUserId, $mysqli);

            // 🛡️ Route through Auth::safeRedirectUrl() (security-review
            // follow-up) — the previous inline check missed backslash/scheme
            // tricks that the shared helper rejects, so a value like
            // "/\evil.com" (browsers normalise "/\" → "//") slipped through as
            // a protocol-relative open redirect. The password + SSO paths
            // already use safeRedirectUrl(); this brings WebAuthn to parity.
            $redirect = Auth::safeRedirectUrl($input['redirect'] ?? '/');

            // ------------------------------------------------------------
            // 🔐 2FA gate (#B2)
            // ------------------------------------------------------------
            // 🛡️ This is the PRIMARY-login WebAuthn path — a passkey used
            // to sign in from the logged-out /login page (auth_verify
            // action above). It is NOT WebAuthn-as-2nd-factor: this
            // codebase has no such flow — /auth/2fa/verify only accepts a
            // TOTP code or a backup code, never a passkey assertion. A
            // passkey therefore authenticates the SAME tier as a password
            // ("something you have" standing in for "something you know"),
            // so a 2FA-enrolled user must still clear the TOTP challenge
            // afterwards — without this gate, passkey sign-in would be a
            // silent full bypass of 2FA. Mirrors the password login path
            // (`_apps/auth/login/index.php`) EXACTLY: same session keys
            // (`2fa_user_id`, `login_redirect`), same unset() pair, same
            // deviceIsTrusted() "remembered device" bypass. The response
            // shape stays `{success, redirect}` — the login page's JS
            // already just follows `redirect`, so no front-end change is
            // needed to route it to the 2FA challenge instead of home.
            if (Auth::userRequires2fa($webauthnUserId) === true && Auth::deviceIsTrusted($webauthnUserId) === false) {
                $_SESSION['2fa_user_id']    = $webauthnUserId;
                $_SESSION['login_redirect'] = $redirect;
                unset($_SESSION['user_id'], $_SESSION['2fa_passed']);
                Logger::activity('LoginWebAuthnPending2fa', 'Passkey login pending 2FA challenge: ' . ($row['friendlyName'] ?? 'Passkey'));
                echo json_encode(['success' => true, 'redirect' => '/auth/2fa/verify']);
                exit();
            }

            Logger::activity('LoginWebAuthn', 'User logged in via passkey: ' . ($row['friendlyName'] ?? 'Passkey'));

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
