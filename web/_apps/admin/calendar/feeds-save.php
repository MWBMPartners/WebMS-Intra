<?php
// Path: _apps/admin/calendar/feeds-save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — the buttons for an outside calendar 🗓️✍️
 * -----------------------------------------------------------------------------
 * The handler behind the buttons on the calendar list and on a calendar's own
 * page. POST only. It does none of the real work itself:
 *
 *   pause, resume  `FeedImporter::setActive()`  — pausing hides every event of
 *                  the calendar at once, because the visibility rule tests
 *                  whether the calendar is switched on as it goes (#514 D3).
 *   remove         `FeedImporter::deleteFeed()` — the calendar, every event it
 *                  brought in, and every RSVP on those events.
 *   refresh        `FeedImporter::refresh()`    — download it now.
 *   categories     `FeedAdmin::saveCategoryMap()` — which of the organisation's
 *                  own categories each word from the outside calendar means.
 *
 * All of these take the calendar's own row lock before they write, so none of
 * them can ever run while a refresh is half way through writing events.
 * Adding a calendar and changing its settings moved to the calendar form
 * (`feeds/edit`) in #514 part P8.
 *
 * THE ORDER OF CHECKS, AND WHY
 * ----------------------------
 *   1. Signed in (the address is protected as well).
 *   2. An administrator OF THIS ORGANISATION, else the portal's 403 page —
 *      whatever calendar number was sent. The old portal-wide "administrator"
 *      flag is not enough (#514 leak-hunt finding 3).
 *   3. The form token, before anything with a side effect. A failure gives
 *      the house sentence and changes nothing.
 *   4. The calendar, by number AND organisation. Another organisation's
 *      calendar and one that does not exist get exactly the SAME sentence
 *      ("That calendar could not be found.") and go to the SAME place (the
 *      list), so nothing here tells anybody that a number exists elsewhere
 *      (the #503 rule; leak-hunt finding 30).
 *
 * WHERE EACH PRESS ENDS UP
 * ------------------------
 * `back=view` returns to the SAME calendar's own page (for Refresh now and the
 * category matching, which are on that page); anything else returns to the
 * list. A calendar that could not be found always returns to the list, and
 * so does a deleted one (its page no longer exists).
 *
 * WHAT #514 PART P8 CHANGED HERE (chunk P8-2)
 * -------------------------------------------
 *   * Every address comes from `Site::url()` (it used to write bare
 *     addresses starting /admin/, which lose the organisation's own address prefix
 *     where organisations are told apart by the first part of the address).
 *   * A refusal is the portal's 403 page, and a bad form token is the house
 *     flash-and-return, not a bare "Forbidden" or "Bad request" page.
 *   * "Nothing to pause." and the like became the one not-found sentence, so
 *     "not this organisation's", "not there at all" and "a database failure
 *     the importer has already recorded" all read the same.
 *   * New actions: refresh and categories. The `add` action moved to the
 *     calendar form.
 *   * A failure the page did not expect is recorded in the error log and the
 *     person sees one plain sentence; the calendar's address is never put
 *     into a message or a log line (some addresses are secret links).
 *
 * @package   Portal\App\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   3.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/514
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\EventVisibility;
use Portal\Core\FeedAdmin;
use Portal\Core\FeedImporter;
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

// 🛡️ 2. An administrator OF THIS ORGANISATION — see the header.
if (EventVisibility::isAdminOfSite($mysqli, $userId, $siteId) === false) {
    Router::renderError(403);
    return;
}

/**
 * Leave one message and go to `$url`. Written once so that every path out of
 * this file looks the same to whoever is using it — including the refusals,
 * which must not be told apart by anything a visitor can see.
 */
$finish = static function (string $message, string $type, string $url): never {
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . $url, true, 302);
    exit();
};

// 🔐 3. The form token FIRST, before anything with a side effect.
if (Auth::verifyCsrf(is_string($_POST['csrf_token'] ?? null) === true ? (string) $_POST['csrf_token'] : '') === false) {
    $finish('Invalid or expired form token. Nothing was changed. Please try again.', 'danger', $listUrl);
}

$action = is_string($_POST['action'] ?? null) === true ? (string) $_POST['action'] : '';
$back   = (is_string($_POST['back'] ?? null) === true && $_POST['back'] === 'view') ? 'view' : 'list';

// 🔢 A whole positive number, or nothing. "12abc", an array or a negative
//    number are all "not found", exactly like a number of another
//    organisation (see the header).
$feedIdRaw = filter_var($_POST['feedID'] ?? null, FILTER_VALIDATE_INT);
$feedId    = ($feedIdRaw === false || $feedIdRaw <= 0) ? 0 : (int) $feedIdRaw;

$notFound = 'That calendar could not be found.';
$failed   = 'That could not be saved, so nothing was changed. The problem has been recorded.';

/** Where a press on calendar `$id` returns to (see the header). */
$returnTo = static function (int $id, string $anchor = '') use ($back, $listUrl): string {
    if ($back === 'view') {
        return Site::url('admin/calendar/feeds/view') . '?id=' . $id . ($anchor === '' ? '' : '#' . $anchor);
    }

    return $listUrl . '#feed-' . $id;
};

$known = ['pause', 'resume', 'remove', 'refresh', 'categories'];
if (in_array($action, $known, true) === false) {
    $finish('Unknown action. Nothing was changed.', 'danger', $listUrl);
}
if ($feedId === 0) {
    $finish($notFound, 'danger', $listUrl);
}

// ⏸️ ▶️ Pause and resume. setActive() looks the calendar up by number AND
//    organisation under its own lock, and answers false for "not this
//    organisation's", "not there" and "a database failure it has already
//    recorded" alike — so all three read as "not found".
if ($action === 'pause' || $action === 'resume') {
    $on = ($action === 'resume');
    try {
        $done = FeedImporter::setActive($mysqli, $feedId, $siteId, $on);
    } catch (\Throwable $e) {
        Logger::errorPlatform('FeedAdmin', 'Error', 'FEED_PAUSE', ($on === true ? 'Resuming' : 'Pausing') . ' calendar #' . $feedId . ' failed', $e->getMessage());
        $finish($failed, 'danger', $listUrl);
    }
    if ($done !== true) {
        $finish($notFound, 'danger', $listUrl);
    }
    // The person is passed in (chunk P8-4 fix round 1): Logger::activity() names as the
    // line's user only the user it is GIVEN. It does read the session (the session's
    // number and a copy of it, less its form token and its sign-in secrets, go into every line), but it never takes the USER from it,
    // so without this the Activity page showed "System" for an administrator's own action.
    Logger::activity($on === true ? 'ExternalFeedResume' : 'ExternalFeedPause', 'Calendar #' . $feedId, $userId);
    $finish(
        $on === true
            ? 'That calendar is switched back on. Its events are visible again to the people chosen for them.'
            : 'That calendar is paused. Its events are hidden until you resume it.',
        'success',
        $returnTo($feedId)
    );
}

// 🗑️ Delete. Always back to the list: the calendar's own page is gone.
if ($action === 'remove') {
    try {
        $done = FeedImporter::deleteFeed($mysqli, $feedId, $siteId);
    } catch (\Throwable $e) {
        Logger::errorPlatform('FeedAdmin', 'Error', 'FEED_DELETE', 'Deleting calendar #' . $feedId . ' failed', $e->getMessage());
        $finish($failed, 'danger', $listUrl);
    }
    if ($done !== true) {
        $finish($notFound, 'danger', $listUrl);
    }
    Logger::activity('ExternalFeedRemove', 'Calendar #' . $feedId, $userId);
    $finish('That calendar, all the events it brought in and any RSVPs on them have been deleted.', 'success', $listUrl);
}

// 🔄 Refresh now. The importer looks a calendar up by number only
//    (FeedImporter::refresh()), so THIS page proves it is this
//    organisation's first, through FeedAdmin (plan S23). A refresh never
//    throws; whatever happens comes back as an outcome and a plain sentence.
if ($action === 'refresh') {
    if (FeedAdmin::calendar($mysqli, $feedId, $siteId) === null) {
        $finish($notFound, 'danger', $listUrl);
    }
    $answer = FeedImporter::refresh($mysqli, $feedId, 'manual', $userId);
    Logger::activity('ExternalFeedRefresh', 'Calendar #' . $feedId, $userId);
    $type = 'success';
    if ($answer['outcome'] === 'failed') {
        $type = 'warning';
    } elseif ($answer['outcome'] === 'skipped') {
        $type = 'info';
    }
    $finish((string) $answer['message'], $type, $returnTo($feedId));
}

// 🏷️ Category matching. Only the posted `map` is handed on: FeedAdmin
//    validates every word and every category number itself (plan 5.4), and
//    refuses — writing nothing — when two spellings the database counts as
//    one word are given different settings.
if ($action === 'categories') {
    // 🧹 Only TEXT reaches FeedAdmin. A field sent as a list
    //    (`map[0][word][]=x`) would otherwise be turned into the word "Array"
    //    with a PHP warning (P8-1 check round 7). A row whose word or choice
    //    is not text is passed on with an EMPTY word, which FeedAdmin
    //    ignores like a spare row — never with a made-up choice, because
    //    "default" would quietly delete that word's matching.
    $map = [];
    foreach ((is_array($_POST['map'] ?? null) === true ? $_POST['map'] : []) as $row) {
        if (is_array($row) === false) {
            continue;
        }
        $word   = $row['word'] ?? '';
        $choice = $row['choice'] ?? 'default';
        $usable = (is_string($word) === true && is_string($choice) === true);
        $map[]  = ['word' => $usable === true ? $word : '', 'choice' => $usable === true ? $choice : 'default'];
    }
    try {
        $result = FeedAdmin::saveCategoryMap($mysqli, $feedId, $siteId, $userId, ['map' => $map]);
    } catch (\Throwable $e) {
        Logger::errorPlatform('FeedAdmin', 'Error', 'FEED_CATEGORIES', 'Saving category matching of calendar #' . $feedId . ' failed', $e->getMessage());
        $finish($failed, 'danger', $returnTo($feedId, 'categories'));
    }
    if ($result['status'] === 'not_found') {
        $finish($notFound, 'danger', $listUrl);
    }
    if ($result['ok'] !== true) {
        // FeedAdmin's own plain sentence, shown as it is (escaped by the page
        // that shows the flash).
        $finish((string) $result['message'], 'danger', $returnTo($feedId, 'categories'));
    }
    Logger::activity('ExternalCategoryMapSaved', 'Calendar #' . $feedId, $userId);
    $finish((string) $result['message'], 'success', $returnTo($feedId, 'categories'));
}

// Unreachable: every known action above ends the request.
header('Location: ' . $listUrl, true, 302);
exit();
