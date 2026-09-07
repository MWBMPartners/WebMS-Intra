<?php
// Path: public_html/auth/account/notifications-save.php
/**
 * -----------------------------------------------------------------------------
 * Account — Notification preferences save handler 📬
 * -----------------------------------------------------------------------------
 * POST-only. Persists the user's notifyPrefs JSON.
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
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /account/notifications', true, 302);
    exit();
}

Auth::ensureSession();
Auth::requireLogin();

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['notifications_flash']      = 'Invalid or expired form token.';
    $_SESSION['notifications_flash_type'] = 'danger';
    header('Location: /account/notifications', true, 302);
    exit();
}

// 🛡️ Whitelist the keys we accept — anything else is silently dropped.
$allowedKeys = [
    'emailDigest',
    'eventReminders',
    'eventRsvpConfirmation',
    'expenseStatusUpdates',
    'expenseApproverNudges',
    'announcementsNew',
    'prayerModeration',
    'accountSecurity',
    'givingStatements',
    'taskReminders',
    'rotaReminders',
    'approvalRequests',
    'pushLivestream',
    'pushServiceReminders',
];

$incoming = $_POST['prefs'] ?? [];
if (is_array($incoming) === false) {
    $incoming = [];
}

// 🎛️ Build the bool map. Unchecked switches don't appear in $_POST at all,
// so we default to false for any allowed key that's missing.
$prefs = [];
foreach ($allowedKeys as $k) {
    $prefs[$k] = isset($incoming[$k]) === true && $incoming[$k] === '1';
}

$user   = App::user();
$userId = (int) ($user['userID'] ?? 0);

$json = json_encode($prefs, JSON_UNESCAPED_UNICODE);
if ($json === false) {
    $_SESSION['notifications_flash']      = 'Failed to encode preferences.';
    $_SESSION['notifications_flash_type'] = 'danger';
    header('Location: /account/notifications', true, 302);
    exit();
}

// 🕯️ Sabbath override (#231). 'inherit'/'on'/'off' validated; anything
//    else falls back to 'inherit'. Saved alongside notifyPrefs in one UPDATE.
$sabbathHonour = (string) ($_POST['sabbathHonour'] ?? 'inherit');
if (in_array($sabbathHonour, ['inherit', 'on', 'off'], true) === false) {
    $sabbathHonour = 'inherit';
}

$stmt = $mysqli->prepare('UPDATE tblUsers SET notifyPrefs = ?, sabbathHonour = ? WHERE userID = ?');
if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'NOTIFY_PREFS_PREP', $mysqli->error, '');
    $_SESSION['notifications_flash']      = t('error.db_save_preferences');
    $_SESSION['notifications_flash_type'] = 'danger';
    header('Location: /account/notifications', true, 302);
    exit();
}
$stmt->bind_param('ssi', $json, $sabbathHonour, $userId);
$stmt->execute();
$stmt->close();

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
//    Only touched when the Newsletter app is switched on for this site — the
//    checkbox is not shown otherwise, and an absent checkbox must not be read
//    as "unsubscribe me".
$newsletterOn = (string) (App::settings()['newsletter']['enabled'] ?? '0');
if ($newsletterOn === '1' || $newsletterOn === 'true') {
    $siteId       = Site::id();
    $optedIn      = isset($_POST['newsletterOptedIn']) === true ? 1 : 0;
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
header('Location: /account/notifications', true, 302);
exit();
