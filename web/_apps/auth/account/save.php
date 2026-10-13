<?php
// Path: apps/auth/account/save.php
/**
 * -----------------------------------------------------------------------------
 * Account Profile – Save Handler 💾
 * -----------------------------------------------------------------------------
 * Updates the current user's profile information (full name, email, phone) in
 * tblUsers.  Validates email uniqueness (excluding the current user) and
 * refreshes the session variables to reflect the changes immediately.
 * -----------------------------------------------------------------------------
 * @package    Portal\Auth
 * @author     MWBM Partners Ltd (t/a MWservices)
 * @copyright  2025-2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version    0.2.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

// -----------------------------------------------------------------------------
// 1. 🛡️ Only accept POST from authenticated users
// -----------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . Site::url('account'), true, 302);
    exit();
}

Auth::requireLogin();

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    header('Location: ' . Site::url('account') . '?error=csrf', true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 1b. 🔔 Handle notification preference updates
// -----------------------------------------------------------------------------

$action = $_POST['action'] ?? 'update_profile';

if ($action === 'update_notifications') {
    $userId = (int) $_SESSION['user_id'];

    // ⚠️ WHAT WAS WRONG HERE (#612): this card has three switches, and the code used to store
    // ONLY those three, replacing everything else the person had stored. The other choices
    // live on Account, Notifications ("We're live now", "Service reminders", the approvals
    // push and every other e-mail choice), and a choice that is missing counts as ON
    // (WebPush::notifyPrefAllows()). So a member who had switched "We're live now" off, and
    // then pressed Save on this card without touching it, was switched back ON, and was
    // sent the next "we are live" push. Now the stored choices are read first and ONLY this
    // card's three keys are changed. WHAT THIS CANNOT DO: it cannot tell what a missing key
    // meant; it leaves every other key exactly as it found it (including unknown ones).
    $stored = [];
    $readStmt = $mysqli->prepare('SELECT notifyPrefs FROM tblUsers WHERE userID = ? LIMIT 1');
    if ($readStmt === false) {
        // Unable to read what is stored: write nothing rather than overwrite it with a guess.
        $_SESSION['flash_msg']  = 'Your notification preferences could not be saved. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Site::url('account'), true, 302);
        exit();
    }
    $readStmt->bind_param('i', $userId);
    $readStmt->execute();
    $readRow = $readStmt->get_result()->fetch_assoc();
    $readStmt->close();
    $decodedPrefs = ($readRow !== null && $readRow['notifyPrefs'] !== null) ? json_decode((string) $readRow['notifyPrefs'], true) : null;
    if (is_array($decodedPrefs) === true) {
        $stored = $decodedPrefs;
    }
    $stored['emailDigest']    = isset($_POST['emailDigest']) === true;
    $stored['expenseUpdates'] = isset($_POST['expenseUpdates']) === true;
    $stored['eventReminders'] = isset($_POST['eventReminders']) === true;
    $prefs = json_encode($stored, JSON_THROW_ON_ERROR);

    $npStmt = $mysqli->prepare('UPDATE tblUsers SET notifyPrefs = ? WHERE userID = ?');
    if ($npStmt !== false) {
        $npStmt->bind_param('si', $prefs, $userId);
        $npStmt->execute();
        $npStmt->close();
    }

    Logger::activity('NotificationPrefsUpdate', 'Updated notification preferences');
    $_SESSION['flash_msg']  = 'Notification preferences saved.';
    $_SESSION['flash_type'] = 'success';
    header('Location: ' . Site::url('account'), true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 2. 📝 Validate fields
// -----------------------------------------------------------------------------

$userId       = (int) $_SESSION['user_id'];
$fullName     = trim($_POST['fullName'] ?? '');
$emailAddress = strtolower(trim($_POST['emailAddress'] ?? ''));
$phoneNumber  = trim($_POST['phoneNumber'] ?? '');

if ($fullName === '') {
    header('Location: ' . Site::url('account') . '?error=name', true, 302);
    exit();
}

if ($emailAddress === '' || filter_var($emailAddress, FILTER_VALIDATE_EMAIL) === false) {
    header('Location: ' . Site::url('account') . '?error=email', true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 3. 🔍 Check email uniqueness (excluding current user)
// -----------------------------------------------------------------------------

$checkStmt = $mysqli->prepare(
    'SELECT userID FROM tblUsers WHERE emailAddress = ? AND userID != ? LIMIT 1'
);

if ($checkStmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'PROFILE_PREP_FAIL', $mysqli->error, '');
    header('Location: ' . Site::url('account') . '?error=db', true, 302);
    exit();
}

$checkStmt->bind_param('si', $emailAddress, $userId);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();
$existing    = $checkResult->fetch_assoc();
$checkStmt->close();

if ($existing !== null) {
    header('Location: ' . Site::url('account') . '?error=email_taken', true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 4. 💾 Update tblUsers
// -----------------------------------------------------------------------------

$updateStmt = $mysqli->prepare(
    'UPDATE tblUsers SET fullName = ?, emailAddress = ?, phoneNumber = ? WHERE userID = ?'
);

if ($updateStmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'PROFILE_UPDATE_FAIL', $mysqli->error, '');
    header('Location: ' . Site::url('account') . '?error=db', true, 302);
    exit();
}

$updateStmt->bind_param('sssi', $fullName, $emailAddress, $phoneNumber, $userId);
$updateStmt->execute();
$updateStmt->close();

// -----------------------------------------------------------------------------
// 5. 🔄 Update session variables and reset App user cache
// -----------------------------------------------------------------------------

$_SESSION['user_name']  = $fullName;
$_SESSION['user_email'] = $emailAddress;
App::resetUser();

// 📝 Log the profile update
Logger::activity('ProfileUpdate', 'User updated profile information');

// -----------------------------------------------------------------------------
// 6. 🔀 Redirect with success message
// -----------------------------------------------------------------------------

header('Location: ' . Site::url('account') . '?success=1', true, 302);
exit();
