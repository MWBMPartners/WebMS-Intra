<?php
// Path: _apps/admin/calendar/feeds-approvals-act.php
/**
 * -----------------------------------------------------------------------------
 * Admin — approve or decline imported dates waiting for approval ✅❌🗓️
 * -----------------------------------------------------------------------------
 * The Approve and Decline buttons on the Approvals inbox and on a calendar's
 * own page (`_approval-rows.php`) post here — one date at a time, or several
 * ticked dates at once. POST only; a GET goes back to the calendar list.
 *
 * ORDER OF CHECKS (the #514 part 8 plan, section 3.2)
 * ----------------------------------------------------
 *   1. Signed in, and an administrator OF THIS ORGANISATION (a site
 *      administrator of it, or a global administrator), else the portal's
 *      403 page. The old portal-wide "administrator" flag is not enough: it
 *      says nothing about which organisation somebody belongs to.
 *      `FeedAdmin::decide()` checks who is asking again, so a fault here
 *      still cannot let anybody else decide (plan L1, D29).
 *   2. The form token, before anything with a side effect.
 *   3. The decision word ("approved" or "declined"; anything else is
 *      "Unknown action." and nothing is done).
 *   4. The dates. Each must arrive as its approval number (digits only) plus
 *      the two fingerprints the page showed (request and content, exactly 64
 *      lower-case hexadecimal characters each — nothing before or after, not
 *      even a line break). A malformed one is skipped and counted "could not
 *      be read"; nothing about it reaches the database.
 *   5. ALL the dates go to ONE call, `FeedAdmin::decide()` (challenge M2),
 *      which groups them by calendar, takes each calendar's lock once,
 *      decides every date of it, works out who can see what ONCE, and
 *      commits. So a date settled by this same request is never reported as
 *      "changed".
 *
 * WHAT IT SAYS AFTERWARDS
 * -----------------------
 * Exactly `decide()`'s own message, escaped by the page that shows it:
 * "Approved 3." / "Declined 1.", plus "N had changed since you opened the
 * page; please review it again." when a date was no longer what the page
 * showed — or belonged to another organisation, or no longer exists: those
 * look exactly the same from here, on purpose — and, only when a date of a
 * repeating event was really decided, the owner's one always-true sentence
 * (28 September 2026, plan top-section item 10): "Other dates of the same
 * repeating event may follow this decision. The list of waiting dates shows
 * where each one now stands." It NEVER says how many other dates
 * "followed": every way of counting that was tried turned out untrue in
 * some case (see `FeedAdmin::decide()`'s docblock). This handler adds only
 * "N could not be read; please try again." for malformed dates.
 *
 * WHERE IT GOES BACK TO
 * ---------------------
 * `back=approvals` AND the Approvals app switched on → the inbox's
 * `#imports` section. Otherwise → the calendar's own page, `#waiting`
 * (`feed` must be a calendar of THIS organisation — anything else goes to
 * the calendar list). When the organisation has switched the Approvals app
 * off, every `approvals…` address answers "this app is switched off", so
 * sending anybody there would strand them; the calendar's own page is then
 * the place to decide.
 *
 * WHAT IT CANNOT DO
 * -----------------
 * - Decide more than 200 dates in one go (`FeedAdmin::MAX_BULK`); more is
 *   refused with nothing done.
 * - Keep a note longer than 500 characters: it is refused, never cut short
 *   (plan L3; `FeedAdmin::decide()` checks it).
 *
 * @package   Portal\App\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/514
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\AppRegistry;
use Portal\Core\Auth;
use Portal\Core\EventVisibility;
use Portal\Core\FeedAdmin;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

$listUrl = Site::url('admin/calendar/feeds');

// A GET here has nothing to do: go to the list.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ' . $listUrl, true, 302);
    exit();
}

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

// 🛡️ 1. An administrator OF THIS ORGANISATION — see the header.
if (EventVisibility::isAdminOfSite($mysqli, $userId, $siteId) === false) {
    Router::renderError(403);
    return;
}

/** Leave one message and go to `$url` (the house flash-and-return). */
$finish = static function (string $message, string $type, string $url): never {
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . $url, true, 302);
    exit();
};

/** One posted text field, or '' when it is missing or not text (a list would become "Array"). */
$postText = static function (string $field): string {
    $value = $_POST[$field] ?? '';

    return is_string($value) === true ? $value : '';
};

$back    = ($postText('back') === 'feed') ? 'feed' : 'approvals';
$inboxOn = AppRegistry::isEnabled('approvals');
$inbox   = Site::url('approvals') . '#imports';

// 🔐 2. The form token FIRST. Where a refusal goes needs no database: the
//    inbox when that is where the button was (and it is switched on), else
//    the calendar list.
if (Auth::verifyCsrf($postText('csrf_token')) === false) {
    $finish('Invalid or expired form token. Nothing was changed. Please try again.', 'danger', ($back === 'approvals' && $inboxOn === true) ? $inbox : $listUrl);
}

// ↩️ Where to go back to (see the header). `feed` is used only when it is a
//    calendar of THIS organisation; anything else — another organisation's
//    number included — goes to the list, the same for both.
$feedRaw = filter_var($_POST['feed'] ?? null, FILTER_VALIDATE_INT);
$feedId  = ($feedRaw === false || $feedRaw === null || $feedRaw <= 0) ? 0 : (int) $feedRaw;
if ($back === 'approvals' && $inboxOn === true) {
    $returnTo = $inbox;
} elseif ($feedId > 0 && FeedAdmin::calendar($mysqli, $feedId, $siteId) !== null) {
    $returnTo = Site::url('admin/calendar/feeds/view') . '?id=' . $feedId . '#waiting';
} else {
    $returnTo = $listUrl;
}

// 3. The decision word.
$decision = $postText('decision');
if ($decision !== 'approved' && $decision !== 'declined') {
    $finish('Unknown action. Nothing was changed.', 'danger', $returnTo);
}

// 4. The dates: one (approvalID + its two fingerprints), or a ticked list
//    `items[]` of "number:request:content". Each fingerprint is exactly 64
//    lowercase hexadecimal characters, as the resolver writes them.
//
//    `\A` and `\z`, never `^` and `$` (chunk P8-4 fix round 1, check finding
//    LOW 5): `$` also matches just before a FINAL line break, so a fingerprint
//    with a newline on the end used to pass as "readable" and reach
//    decideApproval(), which then called it "changed". The number is held to
//    the same standard: digits only, so "12" followed by a line break is
//    "could not be read" too (filter_var() alone trims white space and
//    would have accepted it).
$isFingerprint = static fn (string $h): bool => preg_match('/\A[0-9a-f]{64}\z/', $h) === 1;
$toItem        = static function (mixed $id, mixed $req, mixed $con) use ($isFingerprint): ?array {
    if (is_string($id) === false || preg_match('/\A[1-9][0-9]{0,9}\z/', $id) !== 1) {
        return null;
    }
    $aid = filter_var($id, FILTER_VALIDATE_INT);
    if ($aid === false || $aid <= 0 || is_string($req) === false || is_string($con) === false
        || $isFingerprint($req) === false || $isFingerprint($con) === false) {
        return null;
    }

    return ['approvalID' => (int) $aid, 'requestHash' => $req, 'contentHash' => $con];
};
$items      = [];
$unreadable = 0;
if (array_key_exists('items', $_POST) === true) {
    $ticked = is_array($_POST['items']) === true ? $_POST['items'] : [$_POST['items']];
    if (count($ticked) > FeedAdmin::MAX_BULK) {
        $finish('You can decide at most ' . FeedAdmin::MAX_BULK . ' dates at once. Nothing was changed.', 'danger', $returnTo);
    }
    foreach ($ticked as $entry) {
        $parts = is_string($entry) === true ? explode(':', $entry) : [];
        $item  = (count($parts) === 3) ? $toItem($parts[0], $parts[1], $parts[2]) : null;
        if ($item === null) {
            $unreadable++;
            continue;
        }
        $items[] = $item;
    }
} elseif (array_key_exists('approvalID', $_POST) === true) {
    $item = $toItem($_POST['approvalID'], $_POST['requestHash'] ?? null, $_POST['contentHash'] ?? null);
    if ($item === null) {
        $unreadable++;
    } else {
        $items[] = $item;
    }
} else {
    // The bulk form sent with nothing ticked.
    $finish('No date was ticked. Nothing was changed.', 'warning', $returnTo);
}

// 5. ONE call for every date (challenge M2).
try {
    $result = FeedAdmin::decide($mysqli, $siteId, $userId, $decision, $postText('note'), $items);
} catch (\Throwable $e) {
    // Nothing was committed (decide() throws only then) — so "nothing was
    // changed" is true. The exception is recorded, never shown.
    Logger::errorPlatform('FeedAdmin', 'Error', 'APPROVAL_DECIDE', 'Deciding ' . count($items) . ' waiting dates failed', $e->getMessage());
    $finish('That could not be saved, so nothing was changed. The problem has been recorded.', 'danger', $returnTo);
}
if ($result['ok'] !== true) {
    // A refusal decide() explains itself (a note too long, a note that
    // cannot be read, too many dates) — nothing was done.
    $finish((string) $result['message'], 'danger', $returnTo);
}
if ($result['failure'] instanceof \Throwable) {
    // Some calendars were decided and committed, a later one was not: the
    // message already says how many could not be decided this time.
    Logger::errorPlatform('FeedAdmin', 'Error', 'APPROVAL_DECIDE_PART', 'Some waiting dates could not be decided this time', $result['failure']->getMessage());
}
foreach ((array) $result['decidedIds'] as $decidedId) {
    // Numbers only, never a title (plan section 3.2, rule 7). The person who
    // decided is passed in: Logger::activity() names as the line's user only
    // the user it is GIVEN. It does read the session (its number and a copy,
    // less its form token and its sign-in secrets, go into every line), but it never takes the USER from it, and a line saying
    // a date was approved by nobody in particular would be of little use later.
    Logger::activity('ImportApprovalDecided', 'Approval #' . (int) $decidedId . ' ' . $decision, $userId);
}

$message = (string) $result['message'];
if ($unreadable > 0) {
    $message .= ' ' . $unreadable . ' could not be read; please try again.';
}
$allWell = ((int) $result['changed'] === 0 && (int) $result['notDone'] === 0 && $unreadable === 0);
$finish($message, $allWell === true ? 'success' : 'warning', $returnTo);
