<?php
// Path: public_html/auth/account/notifications-save.php
/**
 * -----------------------------------------------------------------------------
 * Account — Notification preferences save handler 📬
 * -----------------------------------------------------------------------------
 * POST-only. Saves the switches on the Notification preferences page into
 * `tblUsers.notifyPrefs` through `NotifyPrefs::merge()` — the one writer of a
 * member's choices (an erasure empties the column; a restore replaces it) —
 * plus the Sabbath choice and the newsletter opt-in.
 *
 * Only the switches the page marked as DRAWN are changed; every other stored
 * choice is kept exactly as it was (#667). See the long note below.
 *
 * @package   Portal\Auth
 * @license   All Rights Reserved
 * @version   1.1.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Newsletter;
use Portal\Core\NotifyPrefs;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . Site::url('account/notifications'), true, 302);
    exit();
}

Auth::ensureSession();
Auth::requireLogin();

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['notifications_flash']      = 'Invalid or expired form token.';
    $_SESSION['notifications_flash_type'] = 'danger';
    header('Location: ' . Site::url('account/notifications'), true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 🔔 The switches: MERGED into what is stored, never written over it (#667)
// -----------------------------------------------------------------------------
// ⚠️ WHAT WAS WRONG HERE. This handler used to REBUILD `tblUsers.notifyPrefs`
//    from its own list of eight keys and write the result over the whole
//    column. Two harms followed, both recorded so nobody brings it back:
//
//      1. Every key NOT on that list was DROPPED the next time the member
//         pressed Save. The small card on /account owned a key this page did
//         not list (`expenseUpdates`), so saving here threw it away; and each
//         switch #569 took off this page lost the member's stored choice the
//         same way. This page and that card fought over the column (#667).
//      2. It wrote `false` for every listed key the form did not send, so a
//         switch that was merely NOT DRAWN (the push switches, while push is
//         not set up) read as switched OFF. The page used to paper over that
//         by carrying each "on" push choice in a hidden field.
//
//    NOW: `NotifyPrefs::changesFromPost()` reads ONLY the keys this form
//    marked as drawn (`prefsShown[<key>]`, written beside each switch on the
//    page), and `NotifyPrefs::merge()` — the one writer of a member's choices
//    (an erasure empties the column; a restore replaces it) — changes just
//    those, under a row lock inside its own transaction, keeping
//    every other key exactly as stored. The list of keys a member can set is
//    `NotifyPrefs::SETTABLE`; there is no second list here to keep in step.
//    (So adding a switch — `rotaSwaps` for #657, read by
//    `Portal\Core\RotaSwaps` — needs NO change to this handler: the key on
//    that list and the switch drawn on the page are the whole change, and
//    `tools/rota-swaps-selftest.php` saves it through this page to prove it.)
//
//    A post that carries NO `prefsShown` at all — a page left open from before
//    this change, or a request made by hand — or whose markers name only keys
//    nobody may set, changes NOTHING and says so
//    (#677: ask whether a field ARRIVED, never what it contains). The Sabbath
//    choice and the newsletter below are not saved either, because the same
//    out-of-date page would carry those as well, and one honest refusal is
//    easier to understand than a save that half happened.
$changes = NotifyPrefs::changesFromPost($_POST);
if ($changes === null) {
    $_SESSION['notifications_flash']      = 'This page was out of date, so nothing was changed. Please check your choices below and press Save again.';
    $_SESSION['notifications_flash_type'] = 'warning';
    header('Location: ' . Site::url('account/notifications'), true, 302);
    exit();
}

$user   = App::user();
$userId = (int) ($user['userID'] ?? 0);

$merged = NotifyPrefs::merge($mysqli, $userId, $changes);
if ($merged['outcome'] !== NotifyPrefs::SAVED && $merged['outcome'] !== NotifyPrefs::NOTHING_TO_CHANGE) {
    // Nothing was written: `merge()` rolls back on any refusal. The outcome
    // word is recorded, never the stored preferences themselves.
    Logger::errorPlatform('MySQL', 'Error', 'NOTIFY_PREFS_MERGE', 'Notification preferences could not be saved (' . $merged['outcome'] . ')', '');
    $_SESSION['notifications_flash']      = t('error.db_save_preferences');
    $_SESSION['notifications_flash_type'] = 'danger';
    header('Location: ' . Site::url('account/notifications'), true, 302);
    exit();
}
if ($merged['replacedUnreadable'] === true) {
    // The stored value was not a set of named choices, so it was replaced by
    // the choices this save made. Every other choice still reads as ON, as it
    // already did — see NotifyPrefs' class docblock for why. Recorded so the
    // replacement is never silent.
    Logger::errorPlatform('Portal', 'Warning', 'NOTIFY_PREFS_UNREADABLE', 'A member\'s stored notification preferences could not be read and were replaced by the choices they had just saved', '');
}

// 🕯️ Sabbath override (#231). 'inherit'/'on'/'off' validated; anything else
//    falls back to 'inherit'.
//
// ⚠️ ONLY WHEN THE FIELD ARRIVED (#677). The three buttons are drawn only
//    while the organisation has the Sabbath feature switched on, and this used
//    to read `$_POST['sabbathHonour'] ?? 'inherit'` — so pressing Save while
//    the feature was off reset a member's choice to "inherit", and switching
//    the feature back on later did not bring it back. It used to share one
//    statement with the switches; it is its own statement now because the
//    switches go through `NotifyPrefs::merge()`.
if (array_key_exists('sabbathHonour', $_POST) === true && is_string($_POST['sabbathHonour']) === true) {
    $sabbathHonour = $_POST['sabbathHonour'];
    if (in_array($sabbathHonour, ['inherit', 'on', 'off'], true) === false) {
        $sabbathHonour = 'inherit';
    }
    $stmt = $mysqli->prepare('UPDATE `tblUsers` SET `sabbathHonour` = ? WHERE `userID` = ?');
    if ($stmt === false) {
        Logger::errorPlatform('MySQL', 'Error', 'NOTIFY_PREFS_PREP', $mysqli->error, '');
        $_SESSION['notifications_flash']      = t('error.db_save_preferences');
        $_SESSION['notifications_flash_type'] = 'danger';
        header('Location: ' . Site::url('account/notifications'), true, 302);
        exit();
    }
    $stmt->bind_param('si', $sabbathHonour, $userId);
    $stmt->execute();
    $stmt->close();
}

// 📰 Newsletter opt-in — merged in from the old separate page at
//    /account/notifications, which used to occupy this address and hide the
//    fuller preferences page behind it.
//
//    It is stored in its own table rather than in the preferences JSON above,
//    because an unsubscribe link at the bottom of a newsletter has to work for
//    someone who is not signed in, and that needs a token only this table
//    holds. The token is only ever generated on the FIRST insert; an existing
//    row keeps the token it already has, so unsubscribe links already sent out
//    in earlier newsletters keep working.
//
//    Only touched when the Newsletter app is switched on for this site AND
//    the form that was posted DREW the choice (#875). Asking only whether the
//    newsletter is on NOW was not enough: a page opened while it was off has
//    no tick box, and saved after it was switched on that read as "unticked",
//    so a member was unsubscribed from a list they were never shown a choice
//    about. The page draws Newsletter::choiceShownMarker() beside the box,
//    and Newsletter::choiceFromPost() answers null when it did not arrive.
//    The marker also names the organisation the box was drawn for, and a
//    marker for any other organisation changes nothing (round 2 of #875's
//    check): this request writes the subscription of the organisation it is
//    in NOW, which a switch in another tab can have changed.
$newsletterOn     = (string) (App::settings()['newsletter']['enabled'] ?? '0');
$siteId           = Site::id();
$newsletterChoice = Newsletter::choiceFromPost($_POST, $siteId);
if (($newsletterOn === '1' || $newsletterOn === 'true') && $newsletterChoice !== null) {
    $optedIn      = $newsletterChoice === true ? 1 : 0;
    $freshToken   = bin2hex(random_bytes(20));
    $nlStmt = $mysqli->prepare(
        'INSERT INTO tblNewsletterSubscription (siteID, userID, optedIn, unsubToken) '
        . 'VALUES (?, ?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE optedIn = VALUES(optedIn)'
    );
    if ($nlStmt !== false) {
        $nlStmt->bind_param('iiis', $siteId, $userId, $optedIn, $freshToken);
        $nlStmt->execute();
        $nlStmt->close();
    } else {
        // Not fatal — the preferences above are already saved. Record it so an
        // administrator can see why a newsletter choice did not stick.
        Logger::errorPlatform('MySQL', 'Error', 'NEWSLETTER_OPTIN_PREP', $mysqli->error, '');
    }
}

Logger::activity('NotificationPrefsUpdated', 'User updated notification preferences');
$_SESSION['notifications_flash']      = 'Preferences saved.';
$_SESSION['notifications_flash_type'] = 'success';
header('Location: ' . Site::url('account/notifications'), true, 302);
exit();
