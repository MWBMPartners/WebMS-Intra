<?php
// Path: _apps/admin/calendar/coordinators-save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Coordinator grant / revoke POST handler 👥 (#341)
 * -----------------------------------------------------------------------------
 * action=grant : looks up userID by email, INSERT … ON DUPLICATE KEY UPDATE
 *                to revive a previously-revoked coordinator if applicable.
 * action=revoke: stamps revokedAt = NOW() on the coordinator row.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/341
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . Site::url('admin/calendar/coordinators'), true, 302);
    exit();
}

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    Logger::activity('EventCoordSaveRejected', 'Invalid CSRF on coordinators/save');
    http_response_code(400);
    exit('Bad request');
}

$eventId  = (int) ($_POST['eventID'] ?? 0);
$action   = (string) ($_POST['action'] ?? '');
$adminId  = (int) ($_SESSION['user_id'] ?? 0);
$siteId   = Site::id();
$redirect = Site::url('admin/calendar/coordinators') . '?eventID=' . $eventId;

// 🛡️ Confirm event exists in active site.
// Imported events are read-only (#514 D5); this makes an imported event exactly as "not found" as a missing one.
$stmt = $mysqli->prepare('SELECT eventID FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0 AND externalFeedID IS NULL');
$stmt->bind_param('ii', $eventId, $siteId);
$stmt->execute();
$ok = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($ok === false) {
    http_response_code(404);
    exit('Event not found');
}

if ($action === 'grant') {
    $email = trim((string) ($_POST['userEmail'] ?? ''));
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $_SESSION['flash_msg']  = 'Invalid email address.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . $redirect, true, 302);
        exit();
    }

    // 🔍 Resolve the person by e-mail — AN ACTIVE MEMBER OF THIS EVENT'S OWN
    //    ORGANISATION, AND NOBODY ELSE (#511).
    //
    // ⚠️ IT USED TO BE `WHERE emailAddress = ?` WITH NOTHING ELSE, and that was
    //    wrong in three separate ways at once:
    //
    //    1. **It reached the whole installation.** One organisation's
    //       administrator could make a member of ANOTHER organisation a
    //       coordinator of their event — and a coordinator of an event can see
    //       and act on it. The administrator of organisation A should not be
    //       able to hand anything to somebody who belongs only to B.
    //    2. **It accepted a closed account.** `tblUsers.isActive` was not
    //       asked, so an account somebody had deliberately switched off could
    //       be granted a live coordinator role.
    //    3. **The two replies told a stranger whether an address has an
    //       account.** "No user found with that email" against "Coordinator
    //       granted" is an answer about somebody who may belong to a different
    //       customer entirely. See below: both answers are now the same.
    //
    //    The join is the one `leadership/assign.php` already uses for the same
    //    question, so this is the house rule rather than a new one: an INNER
    //    JOIN on `tblUserSites` for this organisation with `us.isActive = 1`,
    //    plus `u.isActive = 1` on the account itself. Both halves matter —
    //    a membership switched off and an account switched off are different
    //    states, and either one should refuse.
    //
    // 📏 WHY THE LOOKUP WAITED FOR SOMETHING ELSE, recorded because the order
    //    was the whole difficulty: narrowing it to members was refused while
    //    `admin/users/save.php` created an account with NO membership row, so
    //    an administrator who had just added somebody could not then make them
    //    a coordinator. That page adds the row now, so the narrowing is safe.
    //
    // The column on tblUsers is called emailAddress, not email — "email" was
    // never a real column (see full_schema.sql), so this prepare() used to
    // throw straight away (MYSQLI_REPORT_STRICT turns MySQL's "unknown
    // column" into a thrown mysqli_sql_exception), and granting a
    // coordinator crashed with a 500 on every attempt (#501).
    $stmt = $mysqli->prepare(
        'SELECT u.userID FROM tblUsers u '
        . 'INNER JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
        . 'WHERE u.emailAddress = ? AND u.isActive = 1 LIMIT 1'
    );
    $stmt->bind_param('is', $siteId, $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row === null) {
        // 🤫 ONE ANSWER FOR EVERY REFUSAL, AND THAT IS THE POINT. This cannot
        //    say "no such account", "not a member here" or "that account is
        //    switched off" separately: each of those is a fact about somebody
        //    who may belong to a different organisation, and the differences
        //    between the replies are what let an administrator of one
        //    organisation test whether an address has an account anywhere on
        //    the installation. The same reasoning as the password-reset page,
        //    which is compared with itself for exactly this (#625).
        //
        //    It is deliberately NOT a warning badge either: there is nothing
        //    for the administrator to put right except the address they typed.
        $_SESSION['flash_msg']  = 'No active member of this organisation has that email address. '
            . 'They need a portal account here, switched on, before they can be made a coordinator.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: ' . $redirect, true, 302);
        exit();
    }

    $targetUserId = (int) $row['userID'];

    // 💾 Upsert — if the user was previously revoked, clear revokedAt to revive.
    $stmt = $mysqli->prepare(
        'INSERT INTO tblEventCoordinators (eventID, userID, grantedByID, grantedAt) '
        . 'VALUES (?, ?, ?, NOW()) '
        . 'ON DUPLICATE KEY UPDATE revokedAt = NULL, grantedByID = VALUES(grantedByID), grantedAt = NOW()'
    );
    $stmt->bind_param('iii', $eventId, $targetUserId, $adminId);
    $stmt->execute();
    $stmt->close();

    Logger::activity('EventCoordGranted', 'Event #' . $eventId . ' → user ' . $targetUserId);
    $_SESSION['flash_msg']  = 'Coordinator granted.';
    $_SESSION['flash_type'] = 'success';
} elseif ($action === 'revoke') {
    $coordId = (int) ($_POST['coordinatorID'] ?? 0);
    if ($coordId <= 0) {
        header('Location: ' . $redirect, true, 302);
        exit();
    }
    $stmt = $mysqli->prepare(
        'UPDATE tblEventCoordinators SET revokedAt = NOW() WHERE coordinatorID = ? AND eventID = ?'
    );
    $stmt->bind_param('ii', $coordId, $eventId);
    $stmt->execute();
    $stmt->close();

    Logger::activity('EventCoordRevoked', 'Coordinator #' . $coordId . ' on event #' . $eventId);
    $_SESSION['flash_msg']  = 'Coordinator revoked.';
    $_SESSION['flash_type'] = 'info';
}

header('Location: ' . $redirect, true, 302);
exit();
