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

use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\PasskeyAddress;
use Portal\Core\SelfServicePassword;
use Portal\Core\Site;
use Portal\Core\WebAuthn;

Auth::ensureSession();

header('Content-Type: application/json; charset=utf-8');

// 📋 Parse JSON body
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

// 🔑 The name a passkey is tied to (the "relying party ID") and the origin
//    the browser must report. `PasskeyAddress::relyingParty()` is the ONE
//    place that decides both (#903). WHAT WAS HERE: the setting read with
//    `?? $_SERVER['HTTP_HOST']`. The install script seeds that setting as an
//    EMPTY string and `??` falls back only on null, so every installation
//    where nobody had typed a value sent `rpId: ""`, which a browser refuses
//    (measured), so nobody could sign in with a passkey.
$rp     = PasskeyAddress::relyingParty();
$rpID   = $rp['id'];
$origin = $rp['origin'];
if (($action === 'auth_options' || $action === 'auth_verify') && $rpID === '') {
    // Never send an empty rpId, and never check an answer against one.
    echo json_encode(['success' => false, 'error' => $rp['problem']]);
    exit();
}

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

            // 🔑 `Auth::beginSessionFor()` is the ONE place a sign-in decides
            //    what it keeps of the session before it (#900). This endpoint
            //    signs in whoever's authenticator answered, including into a
            //    browser somebody ELSE is signed in on, so the previous person's
            //    waiting messages and typing must go — and the same person
            //    proving themselves again from the "Add a password" page (#731)
            //    must keep theirs. It also replaces the identifier, which the
            //    bare `session_regenerate_id(true)` here used to do on its own
            //    while keeping every entry.
            Auth::beginSessionFor($webauthnUserId);

            $_SESSION['user_id']    = $webauthnUserId;
            $_SESSION['user_name']  = $row['fullName'];
            $_SESSION['user_email'] = $row['emailAddress'];

            // 🌐 Set active site ID for multi-site context
            Auth::initSessionSite($webauthnUserId, $mysqli);

            // 🔑 RECORD THAT THIS PERSON HAS JUST PROVED WHO THEY ARE (#731).
            //    `Portal\Core\SelfServicePassword` is the ONE place that
            //    records and reads that proof, and this is one of the FOUR
            //    paths allowed to record it, because a passkey assertion has
            //    just been verified above. A proof must NEVER come from an
            //    existing session: a session can be borrowed, which is the
            //    whole reason the gate on "add a password to my own account"
            //    exists.
            //
            //    IT IS RECORDED FOR THE PASSKEY'S OWNER, which may not be the
            //    person who was signed in a moment ago — this endpoint signs in
            //    whoever's authenticator answered, and it has just replaced the
            //    session's identity to say so. `recentlyProvedThemselves()`
            //    compares the proof against the SIGNED-IN person for exactly
            //    that reason, so somebody who presents their own passkey into
            //    somebody else's borrowed session proves themselves for their
            //    OWN account and nobody else's.
            //
            //    Recorded here rather than after the two-factor gate below,
            //    for the reason given at the matching call in `Auth.php`: that
            //    gate takes the sign-in away and answers with a redirect, so a
            //    proof written after it would never exist for an account that
            //    uses two-factor authentication.
            SelfServicePassword::recordProof($webauthnUserId, SelfServicePassword::METHOD_PASSKEY);


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
            // deviceIsTrusted() "remembered device" bypass — and, since
            // 27 October 2026, the same ORDER as well: the unset() comes
            // before the address is worked out. It did not before, which is
            // what the note below is about. The response
            // shape stays `{success, redirect}` — the login page's JS
            // already just follows `redirect`, so no front-end change is
            // needed to route it to the 2FA challenge instead of home.
            //
            // 🛡️ TAKE THE SIGN-IN AWAY FIRST, and work out the address AFTER.
            //    Until 27 October 2026 the address was worked out ABOVE this
            //    gate, and `Auth::safeRedirectUrl()` was declared `string $url`.
            //    `$input` is the posted JSON, so the CALLER chooses the type:
            //    `{"redirect":["x"]}` made that call throw a TypeError while
            //    `$_SESSION['user_id']` was already set. The `catch (\Throwable)`
            //    at the foot of this block swallowed it and answered
            //    "Authentication failed." — so somebody holding a passkey for a
            //    two-factor account was told they had FAILED while being left
            //    signed in, with the second factor never asked for. The quietest
            //    of the five places this shape appeared, and the last found: a
            //    search for `safeRedirectUrl($_GET[` cannot see a JSON body.
            if (Auth::userRequires2fa($webauthnUserId) === true && Auth::deviceIsTrusted($webauthnUserId) === false) {
                $_SESSION['2fa_user_id'] = $webauthnUserId;
                unset($_SESSION['user_id'], $_SESSION['2fa_passed']);
                $_SESSION['login_redirect'] = Auth::safeRedirectUrl($input['redirect'] ?? Site::url(''));
                Logger::activity('LoginWebAuthnPending2fa', 'Passkey login pending 2FA challenge: ' . ($row['friendlyName'] ?? 'Passkey'));
                echo json_encode(['success' => true, 'redirect' => Site::url('auth/2fa/verify')]);
                exit();
            }

            // 🛡️ Route through Auth::safeRedirectUrl() (security-review
            // follow-up) — the previous inline check missed backslash/scheme
            // tricks that the shared helper rejects, so a value like
            // "/\evil.com" (browsers normalise "/\" → "//") slipped through as
            // a protocol-relative open redirect. The password + SSO paths
            // already use safeRedirectUrl(); this brings WebAuthn to parity.
            // Worked out HERE, below the two-factor gate, so that nothing which
            // touches caller-chosen input can run while this session still holds
            // an identity it has not finished earning.
            $redirect = Auth::safeRedirectUrl($input['redirect'] ?? Site::url(''));

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
