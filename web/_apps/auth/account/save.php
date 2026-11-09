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
// ⚠️ A class used by its SHORT name needs its `use` line in THIS file, and
//    the line only fails when it RUNS — `php -l` is happy without it (#536).
use Portal\Core\I18n;
use Portal\Core\Logger;
use Portal\Core\NotifyPrefs;
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

    // ⚠️ WHAT WAS WRONG HERE, TWICE.
    //
    //    #612: this card used to store ONLY its own switches, replacing
    //    everything else the person had stored — and a choice that is missing
    //    counts as ON (WebPush::notifyPrefAllows()), so a member who had
    //    switched "We're live now" off, then pressed Save here, was switched
    //    back ON. #612 made it read first and change only its own keys.
    //
    //    #667: that read had no lock, so two saves at once could each read the
    //    old value and the second write threw away the first's change; and it
    //    still wrote `false` for `emailDigest` and `expenseUpdates`, which this
    //    card had stopped drawing (#569) — an unticked box and an absent box
    //    look the same in a post. Both keys are RETIRED now (#662, #667:
    //    nothing has ever read either, and `expenseUpdates` was a second name
    //    for `expenseStatusUpdates`); nothing writes them, and what a member
    //    had stored is left alone.
    //
    //    NOW this card writes through `NotifyPrefs::merge()`, the ONE writer of
    //    the column, exactly as the full Notification preferences page does:
    //    only the switches the card marked as drawn (`prefsShown[<key>]`) are
    //    changed, under a row lock, and every other key is kept as stored. A
    //    post with no marker at all changes nothing (#677).
    //
    // 📨 THE ANSWER GOES IN THE ADDRESS, not the session. This branch used to
    //    put its message in `$_SESSION['flash_msg']`, which the Account page
    //    never reads — so "saved", and the "could not be saved" refusal, were
    //    both swallowed, and the message surfaced later on some unrelated page
    //    that does read it. The Account page reads `?notify_saved=1` and the
    //    `?error=` words in its own map, as every other action here does.
    $changes = NotifyPrefs::changesFromPost($_POST);
    if ($changes === null) {
        header('Location: ' . Site::url('account') . '?error=notify_stale', true, 302);
        exit();
    }
    $merged = NotifyPrefs::merge($mysqli, $userId, $changes);
    if ($merged['outcome'] !== NotifyPrefs::SAVED && $merged['outcome'] !== NotifyPrefs::NOTHING_TO_CHANGE) {
        // Nothing was written: `merge()` rolls back on any refusal.
        Logger::errorPlatform('MySQL', 'Error', 'NOTIFY_PREFS_MERGE', 'Notification preferences could not be saved (' . $merged['outcome'] . ')', '');
        header('Location: ' . Site::url('account') . '?error=notify_db', true, 302);
        exit();
    }
    if ($merged['replacedUnreadable'] === true) {
        // See NotifyPrefs' class docblock: every other choice still reads as
        // ON, as it already did. Recorded so the replacement is never silent.
        Logger::errorPlatform('Portal', 'Warning', 'NOTIFY_PREFS_UNREADABLE', 'A member\'s stored notification preferences could not be read and were replaced by the choices they had just saved', '');
    }

    Logger::activity('NotificationPrefsUpdate', 'Updated notification preferences');
    header('Location: ' . Site::url('account') . '?notify_saved=1', true, 302);
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

// -----------------------------------------------------------------------------
// 5a. 🌍 The language, if the control was on the form (#763, D-062)
// -----------------------------------------------------------------------------
// ⚠️ ASK WHETHER THE FIELD ARRIVED, NEVER WHAT IT CONTAINS. An empty value is
//    a real instruction here — "forget my choice and follow my browser" — so
//    `$_POST['locale'] ?? ''` would be indistinguishable from a form that
//    never carried the control, and a post from anywhere else would silently
//    wipe a member's language. #677 cost this project a cleared portal address
//    the same way, and the shape is the same: a missing field read as a value.
//
// `I18n::chooseLanguage()` is the ONE place a choice is stored or cleared, so
// this page and the `?lang=` address cannot disagree. It refuses a language
// the portal does not know and answers false; the only way to see that is a
// hand-made request, since the form offers a fixed list, so the refusal is
// recorded rather than shown.
if (array_key_exists('locale', $_POST) === true && is_string($_POST['locale']) === true) {
    $wanted = trim($_POST['locale']);
    if (I18n::chooseLanguage($wanted === '' ? null : $wanted) === false) {
        Logger::activity(
            'ProfileLanguageRefused',
            'A language the portal does not offer was submitted and was not stored'
        );
    }
}

// 📝 Log the profile update
Logger::activity('ProfileUpdate', 'User updated profile information');

// -----------------------------------------------------------------------------
// 6. 🔀 Redirect with success message
// -----------------------------------------------------------------------------

header('Location: ' . Site::url('account') . '?success=1', true, 302);
exit();
