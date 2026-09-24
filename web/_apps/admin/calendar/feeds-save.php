<?php
// Path: _apps/admin/calendar/feeds-save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — add, pause, resume or delete an outside calendar 🗓️✍️
 * -----------------------------------------------------------------------------
 * The handler behind the buttons on `/admin/calendar/feeds`. It does none of
 * the real work itself: adding, pausing and deleting all go through
 * `Portal\Core\FeedImporter`, which takes the calendar's own row lock first
 * so that none of them can ever run while a refresh is half way through
 * writing events.
 *
 * WHAT #514 PART P6 CHANGED HERE, AND WHY
 * ---------------------------------------
 *   * **Who may use this page.** It used to ask `App::isAdmin()`, which says
 *     yes to the old portal-wide "administrator" flag. That flag says
 *     nothing about WHICH organisation somebody belongs to, so on a portal
 *     with several organisations it let one organisation's administrator
 *     change another's calendars (#514 leak-hunt finding 3). The question
 *     asked now is the right one: are you an administrator OF THIS
 *     organisation?
 *   * **Which addresses may be used.** It used to accept anything PHP would
 *     call a web address and begin with http. That included addresses
 *     pointing at the hosting company's own internal network.
 *     `SafeFetch::check()` now decides, and every refusal gives the SAME
 *     sentence — so the page cannot be used to find out what does and does
 *     not exist behind the server.
 *   * **Deleting really deletes.** It used to remove the events and the
 *     calendar, and leave behind every "I am going" answer attached to those
 *     events, pointing at event numbers that no longer existed.
 *     `FeedImporter::deleteFeed()` clears those too.
 *
 * @package   Portal\App\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   2.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/514
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\EventVisibility;
use Portal\Core\FeedImporter;
use Portal\Core\Logger;
use Portal\Core\SafeFetch;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/calendar/feeds', true, 302);
    exit();
}

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

// 🛡️ An administrator OF THIS ORGANISATION, with an active account. See the
//    header for why the old portal-wide flag is not enough.
if (EventVisibility::isAdminOfSite($mysqli, $userId, $siteId) === false) {
    http_response_code(403);
    exit('Forbidden');
}
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$action = (string) ($_POST['action'] ?? '');

/**
 * Show one message and go back to the list.
 *
 * Written once so that every path out of this file looks the same to whoever
 * is using it — including the refusals, which must not be distinguishable
 * from one another by anything a visitor can see.
 */
$finish = static function (string $message, string $type): never {
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: /admin/calendar/feeds', true, 302);
    exit();
};

if ($action === 'add') {
    $name  = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
    $typed = mb_substr(trim((string) ($_POST['url'] ?? '')), 0, 2000);
    $mins  = max(15, min(10080, (int) ($_POST['fetchEveryMins'] ?? 360)));

    if ($name === '') {
        $finish('Please give the calendar a name.', 'danger');
    }

    // 🧹 One standard spelling, or nothing. `webcal://` — which is what a
    //    "subscribe" link usually gives you — becomes `https://` here, so the
    //    portal stores and fetches an address a web server understands.
    $url = SafeFetch::normaliseUrl($typed);
    if ($url === null) {
        $finish(SafeFetch::REFUSED_MESSAGE, 'danger');
    }

    // 🔎 May it be fetched at all? Every refusal carries the same sentence,
    //    on purpose: a different message for "that name does not exist" and
    //    "that address is on the internal network" would let somebody map the
    //    inside of the hosting company's network one guess at a time.
    $check = SafeFetch::check($url);
    if ($check['ok'] !== true) {
        $finish(
            ($check['message'] !== '') ? (string) $check['message'] : SafeFetch::REFUSED_MESSAGE,
            'danger'
        );
    }

    // 👁️ `audienceLevel` is written as 'public' until the calendar settings
    //    page exists (part P8). Every calendar has been public in effect
    //    since #327, and migration 204 marked every existing one public for
    //    that reason, so a newly added one must behave the same until an
    //    administrator can choose. The column's own default is the narrow
    //    'members', so leaving it out here would quietly change what a new
    //    calendar shows.
    //
    //    `nextFetchAt` is set to now so the scheduled job picks the calendar
    //    up even if the first refresh below does not run or does not finish.
    $stmt = $mysqli->prepare(
        'INSERT INTO tblExternalFeeds (siteID, name, url, fetchEveryMins, createdByID, audienceLevel, nextFetchAt) '
        . "VALUES (?, ?, ?, ?, ?, 'public', UTC_TIMESTAMP())"
    );
    $stmt->bind_param('issii', $siteId, $name, $url, $mins, $userId);
    $stmt->execute();
    $feedId = (int) $mysqli->insert_id;
    $stmt->close();

    Logger::activity('ExternalFeedAdded', $name);

    // ⬇️ Fetch it straight away, so the administrator finds out now whether
    //    the address works rather than in six hours' time. This is a MANUAL
    //    refresh, which is what lets it run even though the calendar was
    //    only made a moment ago.
    $answer = FeedImporter::refresh($mysqli, $feedId, 'manual', $userId);
    $type   = ($answer['outcome'] === 'failed') ? 'warning' : 'success';
    $finish('Calendar added. ' . (string) $answer['message'], $type);
}

$feedId = (int) ($_POST['feedID'] ?? 0);
if ($feedId <= 0) {
    header('Location: /admin/calendar/feeds', true, 302);
    exit();
}

if ($action === 'pause') {
    // Pausing hides every one of this calendar's events at once, because the
    // visibility rule tests whether the calendar is switched on as it goes
    // rather than copying a flag onto each event (#514 decision D3).
    $done = FeedImporter::setActive($mysqli, $feedId, $siteId, false);
    Logger::activity('ExternalFeedPause', 'Feed #' . $feedId);
    $finish(
        $done === true ? 'That calendar is paused. Its events are hidden until you resume it.' : 'Nothing to pause.',
        $done === true ? 'success' : 'warning'
    );
}

if ($action === 'resume') {
    $done = FeedImporter::setActive($mysqli, $feedId, $siteId, true);
    Logger::activity('ExternalFeedResume', 'Feed #' . $feedId);
    $finish(
        $done === true ? 'That calendar is switched back on. Its events are visible again.' : 'Nothing to resume.',
        $done === true ? 'success' : 'warning'
    );
}

if ($action === 'remove') {
    $done = FeedImporter::deleteFeed($mysqli, $feedId, $siteId);
    Logger::activity('ExternalFeedRemove', 'Feed #' . $feedId);
    $finish(
        $done === true
            ? 'That calendar and all the events it brought in have been deleted.'
            : 'Nothing to delete.',
        $done === true ? 'success' : 'warning'
    );
}

header('Location: /admin/calendar/feeds', true, 302);
exit();
