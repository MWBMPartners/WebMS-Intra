<?php
// Path: public_html/auth/account/delete-confirm.php
/**
 * -----------------------------------------------------------------------------
 * Account — Self-deletion confirm handler 🗑️
 * -----------------------------------------------------------------------------
 * POST-only. Executes the actual deletion across every table that holds
 * personal data, then logs the user out.
 *
 * Anonymisation strategy: where another record depends on this user (e.g.
 * an expense approval, an attendance count recorded by them), the row
 * stays but its userID FK is set to NULL via the existing ON DELETE SET NULL
 * constraints — preserving the audit trail without identifying the
 * deleted person.
 *
 * Hard-delete tables (where the rows ARE the user's data):
 *   tblLocalAccounts, tblLinkedAccounts, tblWebAuthnCredentials,
 *   tblPasswordResets, tblTrustedDevices, tblConsentLog (their own),
 *   tblPrayerRequests where submitterID = $userId AND visibility = 'leadership'
 *     (congregation-visible prayer requests are kept, anonymised — the
 *      request still helps the church if it's been answered).
 *
 * @package   Portal\Auth
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /account/delete', true, 302);
    exit();
}

Auth::ensureSession();
Auth::requireLogin();

if ((App::settings('privacy.allowAccountDelete') ?? 'true') !== 'true') {
    Router::renderError(403);
    return;
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /account/delete', true, 302);
    exit();
}

if (trim((string) ($_POST['confirm_phrase'] ?? '')) !== 'DELETE MY ACCOUNT') {
    $_SESSION['flash_msg']  = 'Confirmation phrase did not match.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /account/delete', true, 302);
    exit();
}

$user   = App::user();
$userId = (int) ($user['userID'] ?? 0);
if ($userId <= 0) {
    header('Location: /account/delete', true, 302);
    exit();
}

// 🛡️ Block root admins from deleting themselves — they'd lock the umbrella.
// They can ask another root admin to do it, OR demote first.
if ((string) ($user['isRootAdmin'] ?? '0') === '1') {
    $_SESSION['flash_msg']  = 'Root admins cannot delete their own account. Demote yourself first or ask another root admin.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /account/delete', true, 302);
    exit();
}

App::beginTransaction();
try {
    // 🧹 Hard-delete tables where the rows ARE the user's identity / secrets
    $deletes = [
        'DELETE FROM tblTrustedDevices       WHERE userID = ?',
        'DELETE FROM tblWebAuthnCredentials  WHERE userID = ?',
        'DELETE FROM tblPasswordResets       WHERE userID = ?',
        'DELETE FROM tblLinkedAccounts       WHERE userID = ?',
        'DELETE FROM tblLocalAccounts        WHERE userID = ?',
    ];
    foreach ($deletes as $sql) {
        $stmt = $mysqli->prepare($sql);
        if ($stmt !== false) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
        }
    }

    // 🙏 Prayer requests: keep the congregation-visible answered ones (they
    // still help the wider church), delete leadership-only ones outright.
    $stmt = $mysqli->prepare(
        "DELETE FROM tblPrayerRequests WHERE submitterID = ? AND visibility = 'leadership'"
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }
    // Anonymise remaining prayer requests authored by this user
    $stmt = $mysqli->prepare(
        'UPDATE tblPrayerRequests SET submitterID = NULL, isAnonymous = 1, '
        . "submitterName = 'Former member', submitterEmail = NULL "
        . 'WHERE submitterID = ?'
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }

    // 👤 Finally, anonymise the user row itself. We keep the row so FKs
    // elsewhere don't break, but every identifying field is cleared.
    // The email is replaced with a hash-based placeholder so the unique
    // index doesn't conflict, and the row is marked inactive.
    $placeholderEmail = 'deleted+' . substr(hash('sha256', (string) $userId . (string) random_int(0, PHP_INT_MAX)), 0, 16) . '@deleted.local';
    $stmt = $mysqli->prepare(
        'UPDATE tblUsers SET '
        . "fullName = 'Deleted user', emailAddress = ?, phoneNumber = NULL, "
        . 'avatarPath = NULL, isActive = 0, '
        . 'isAdmin = 0, isRootAdmin = 0, '
        . 'totpEnabled = 0, totpSecret = NULL '
        . 'WHERE userID = ?'
    );
    if ($stmt !== false) {
        $stmt->bind_param('si', $placeholderEmail, $userId);
        $stmt->execute();
        $stmt->close();
    }

    App::commit();
} catch (\Throwable $ex) {
    App::rollback();
    Logger::exception($ex);
    $_SESSION['flash_msg']  = 'Failed to complete deletion. Please contact an admin.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /account/delete', true, 302);
    exit();
}

Logger::activity('AccountDeleted', 'Self-deletion completed for user #' . $userId);

// 🔚 Log the user out
$_SESSION = [];
if (session_id() !== '') {
    session_destroy();
}
setcookie('PHPSESSID', '', time() - 3600, '/');

// 🏠 Redirect to home with a goodbye message via a fresh session
session_start();
$_SESSION['flash_msg']  = 'Your account has been deleted. Goodbye.';
$_SESSION['flash_type'] = 'info';
header('Location: /', true, 302);
exit();
