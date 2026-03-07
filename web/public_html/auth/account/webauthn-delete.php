<?php
// Path: public_html/auth/account/webauthn-delete.php
/**
 * -----------------------------------------------------------------------------
 * Account — WebAuthn Credential Delete Handler
 * -----------------------------------------------------------------------------
 * Removes a WebAuthn/PassKey credential. Safety-checked: will not remove if
 * it is the user's only login method.
 *
 * @package   Portal\Auth
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.5.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Logger;

Auth::ensureSession();
Auth::requireLogin();

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    header('Location: /account?error=wa_csrf');
    exit();
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$credID = (int) ($_POST['credID'] ?? 0);

if ($credID === 0) {
    header('Location: /account?error=wa_delete');
    exit();
}

// 🛡️ Safety: ensure user has at least 2 login methods before deleting
$methodCount = Auth::countLoginMethods($userId, $mysqli);
if ($methodCount <= 1) {
    header('Location: /account?error=unlink_fail');
    exit();
}

// 🛡️ Verify the credential belongs to this user
$stmt = $mysqli->prepare('SELECT friendlyName FROM tblWebAuthnCredentials WHERE credID = ? AND userID = ?');
if ($stmt === false) {
    header('Location: /account?error=wa_delete');
    exit();
}
$stmt->bind_param('ii', $credID, $userId);
$stmt->execute();
$cred = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($cred === null) {
    header('Location: /account?error=wa_delete');
    exit();
}

// 🗑️ Delete the credential
$del = $mysqli->prepare('DELETE FROM tblWebAuthnCredentials WHERE credID = ? AND userID = ?');
if ($del !== false) {
    $del->bind_param('ii', $credID, $userId);
    $del->execute();
    $del->close();

    Logger::activity('WebAuthnDelete', 'Removed passkey: ' . ($cred['friendlyName'] ?? 'Passkey'));
}

header('Location: /account?wa_deleted=1');
exit();
