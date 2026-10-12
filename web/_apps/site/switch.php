<?php
// Path: _apps/site/switch.php
/**
 * -----------------------------------------------------------------------------
 * Switch organisation — handler 🌐
 * -----------------------------------------------------------------------------
 * POST handler behind the organisation switcher in the menu and the chooser
 * page (/site/choose). It checks the form token, checks the organisation is
 * one of the person's own, and then moves them to it in whichever way the
 * installation tells organisations apart:
 *
 *   sign-in session             saves the choice in the session, then goes to
 *                               the front page;
 *   first part of the address   goes to that organisation's own prefix;
 *   own address each            goes to that organisation's own address
 *                               (where the person signs in again: a browser
 *                               keeps each address's sign-in separate).
 *
 * WHAT WAS WRONG BEFORE (#502, 12 October 2026)
 *   - It ignored Site::set() failing (an organisation that had just been
 *     switched off, say) and wrote "Switched" to the activity log anyway.
 *   - It wrote "Switched" even when the person chose the organisation they
 *     were already in.
 *   - It always redirected to the bare front page. In the two address ways
 *     that left the person exactly where they were, so the switcher did
 *     nothing they could see.
 *   - It asked Site::userBelongsTo(), a different question from the list
 *     the switcher itself was drawn from.
 *
 * Every redirect here is built by Site::url() or Site::addressOf(), so it
 * stays inside the right organisation's address.
 *
 * @package   Portal\App\Site
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/45
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/502
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

/** Back to the chooser, with a message for it to show. */
$backToChooser = static function (string $message = ''): never {
    if ($message !== '') {
        $_SESSION['flash_msg']  = $message;
        $_SESSION['flash_type'] = 'danger';
    }
    header('Location: ' . Site::url('site/choose'), true, 302);
    exit();
};

// 🏠 One organisation only: there is nothing to switch, whatever was sent.
//    First, so that an installation with one organisation answers this
//    address exactly as it always has: a redirect to the front page.
if (Site::isMultisiteEnabled() === false) {
    header('Location: ' . Site::url(''), true, 302);
    exit();
}

// 🛡️ Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $backToChooser();
}

// 🛡️ Check user is logged in
$user = App::user();
if ($user === null) {
    header('Location: ' . Site::url('login'), true, 302);
    exit();
}

// 🛡️ CSRF protection
if (Auth::verifyCsrf((string) ($_POST['csrf_token'] ?? '')) === false) {
    $backToChooser('That form had expired. Please choose again.');
}

// 🔍 Get target site ID
$targetSiteId = (int) ($_POST['site_id'] ?? 0);
if ($targetSiteId <= 0) {
    $backToChooser();
}

$userId = (int) $user['userID'];
$db     = App::db();

// 🔍 Is it one of the person's own organisations? Asked of the SAME list the
//    switcher and the chooser are drawn from (Site::organisationsFor()).
//    The refusal says the same thing whether the organisation does not
//    exist, is switched off, or is simply not theirs, so it cannot be used
//    to find out which organisations exist.
$target = null;
try {
    foreach (Site::organisationsFor($userId, $db) as $own) {
        if ($own['siteID'] === $targetSiteId) {
            $target = $own;
        }
    }
} catch (\Throwable $e) {
    Logger::errorPlatform('Sites', 'Error', 'SWITCH_LIST_FAILED', 'The list of organisations could not be read', $e->getMessage());
    $backToChooser('The portal could not read your organisations just now, so nothing was changed. Please try again.');
}
if ($target === null) {
    // The account is named in the row. It used to be left empty, so the log
    // said somebody had tried, and not who.
    Logger::activity('SiteSwitchDenied', 'User attempted to switch to site #' . $targetSiteId . ' without access', $userId);
    $backToChooser('That organisation is not one of yours. Nothing was changed.');
}

// 🔗 The two address ways: the organisation is part of the address, so
//    "switching" is going to that organisation's address. Nothing is saved.
if (Site::detectionMode() !== Site::MODE_SESSION) {
    $address = Site::addressOf($target);
    if ($address === '') {
        $backToChooser('That organisation has no address of its own yet. Ask an administrator to give it one.');
    }
    header('Location: ' . $address, true, 302);
    exit();
}

// 🔄 Sign-in session: save the choice. Stop if it could not be saved (the
//    organisation was switched off a moment ago, or the database refused).
$before = Site::sessionState() === Site::SESSION_OK ? Site::id() : 0;
if (Site::set($targetSiteId, $db) === false) {
    $backToChooser('The portal could not move you to that organisation just now. Please try again.');
}

// 📝 Log only a real change of organisation.
if ($before !== $targetSiteId) {
    Logger::activity('SiteSwitch', 'Switched to site #' . $targetSiteId, $userId);
}

// 🔄 Redirect to the front page
header('Location: ' . Site::url(''), true, 302);
exit();
