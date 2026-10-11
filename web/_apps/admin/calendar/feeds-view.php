<?php
// Path: _apps/admin/calendar/feeds-view.php
/**
 * -----------------------------------------------------------------------------
 * Admin — one outside calendar's own page 🗓️🔍
 * -----------------------------------------------------------------------------
 * `admin/calendar/feeds/view?id=N` shows calendar N of THIS organisation:
 *
 *   1. At the top: its name, whether it is switched on, who can see its
 *      events, what happened the last time the portal tried to refresh it,
 *      when it will next try, how many of its dates are waiting for
 *      approval, and the "Settings" and "Refresh now" buttons.
 *   2. History (`#history`): the newest 20 refresh attempts — when, whether
 *      the scheduled job or a person started it, how it ended, and what it
 *      changed.
 *   3. Events (`#events`): every imported event of the calendar that is still
 *      live, earliest first, 100 to a page — when it is, who can see it, and
 *      WHY (the calendar's own setting, a choice, a rule, waiting for
 *      approval, marked private, and so on).
 *   4. Category matching (`#categories`): every category word on the
 *      calendar's events, and every word already matched, each with a choice
 *      of which of this organisation's categories it means.
 *
 * Chunk P8-3 of #514 part 8 added:
 *   5. Rules (`#rules`): every rule of the calendar, switched on or off, with
 *      its conditions in words, who may see the events it matches, its dates,
 *      an "Edit" link, and "Add a rule".
 *   - On each event: "Set this date…" (a choice for that one date) and, for a
 *     date of a repeating event, "Set every date…" (a choice for all of
 *     them). An event left by the old #327 importer with no identity cannot
 *     have a choice until the calendar's next successful refresh gives it
 *     one, so it shows a note saying so and no buttons (challenge M3).
 *
 * Chunk P8-4 added:
 *   6. Waiting for approval (`#waiting`): this calendar's dates waiting for
 *      an administrator's approval, 100 to a page, with Approve, Decline and
 *      Preview (`_approval-rows.php`, `back=feed`). It works whether or not
 *      the organisation has the Approvals app switched on — and is the only
 *      place to decide when it is off (the Router then refuses every
 *      `approvals…` address). The header's waiting count links to it.
 *
 * WHO MAY SEE IT
 * --------------
 * Signed in, and an administrator OF THIS ORGANISATION (a site administrator
 * of it, or a global administrator), else the portal's 403 page. The calendar
 * is looked up by number AND organisation first: another organisation's
 * number and a number that does not exist both get the portal's 404 page,
 * after the SAME one statement (the #503 rule), and nothing else is read.
 *
 * EVERYTHING COMES FROM ONE CLASS
 * -------------------------------
 * Every figure here is read through `Portal\Core\FeedAdmin` (and the words for
 * each state from its label constants). The page runs no SQL and names no
 * table.
 *
 * TIMES (the owner's rule of 23 September 2026)
 * ---------------------------------------------
 *   * Moments — when a refresh started, when the next one is due — are
 *     stored in UTC and shown in the ORGANISATION's zone with the zone's
 *     abbreviation (BST, GMT and so on), by `FeedAdmin::showMoment()`, so the
 *     repeated hour on the autumn clock-change night can be told apart.
 *   * An event's start is a WALL-CLOCK time with its own zone, and is shown
 *     exactly as stored (`FeedAdmin::showWallClock()`), with the zone named
 *     when it differs from the organisation's. It is never converted: the
 *     calendar grids print the same stored time, and converting here would
 *     make the two disagree.
 *
 * WHAT IT CANNOT SHOW
 * -------------------
 *   * A per-event history. The History section is per REFRESH.
 *   * Events a refresh has removed. Only live events are listed.
 *   (The "Partly read" badge beside the name shows the importer's own
 *   record of the newest refresh that READ the calendar — see
 *   `FeedAdmin::calendar()`. The History section separately labels each
 *   capped refresh "Partly read", including older ones.)
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

use Portal\Core\Auth;
use Portal\Core\EventVisibility;
use Portal\Core\ExternalAudience;
use Portal\Core\FeedAdmin;
use Portal\Core\FeedResolver;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

// 🛡️ An administrator OF THIS ORGANISATION — whatever number was sent.
if (EventVisibility::isAdminOfSite($mysqli, $userId, $siteId) === false) {
    Router::renderError(403);
    return;
}

// 🔎 The calendar, by number AND organisation, before anything else is read.
$idRaw    = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$feedId   = ($idRaw === false || $idRaw === null || $idRaw <= 0) ? 0 : (int) $idRaw;
$calendar = ($feedId > 0) ? FeedAdmin::calendar($mysqli, $feedId, $siteId) : null;
if ($calendar === null) {
    Router::renderError(404);
    return;
}

// 🔢 The page numbers of the two lists, `page` (events) and `wpage`
//    (waiting dates). A whole number from 1 to 100,000, else page 1.
//
//    WHY THE TOP LIMIT (chunk P8-4 fix round 1, check finding LOW 4): the
//    first version took any whole number, and `?wpage=9223372036854775807`
//    then made "(page − 1) × 100" too big for a whole number — PHP turned it
//    into a fraction, FeedAdmin::pendingApprovals() refused it, and the page
//    answered 500 (and recorded an error) for a typed address. 100,000 pages
//    of 100 is ten million rows: far beyond any real calendar, and small
//    enough that every sum below stays a whole number. A junk value ("abc",
//    "-3", "1.5") is page 1, as before.
$pageNumber = static function (mixed $raw): int {
    $n = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);

    return ($n === false) ? 1 : (int) $n;
};
$page = $pageNumber($_GET['page'] ?? 1);

$orgZone    = FeedResolver::organisationZone($siteId);
$runs       = FeedAdmin::runs($mysqli, $feedId, $siteId, 20);
$events     = FeedAdmin::events($mysqli, $feedId, $siteId, $page);
$tags       = FeedAdmin::tags($mysqli, $feedId, $siteId);
$categories = FeedAdmin::categories($mysqli, $siteId);
$rules      = FeedAdmin::rules($mysqli, $feedId, $siteId);
// ⏳ This calendar's waiting dates, 100 to a page (chunk P8-4). Its own page
//    number (`wpage`), and each list's page links carry the OTHER list's
//    number too (`$pagesUrl` below), so moving through the events never
//    moves these, and the other way round. (Until fix round 1 the events'
//    links dropped `wpage`, so the waiting list went back to its first page.)
$wpage      = $pageNumber($_GET['wpage'] ?? 1);
$waitingRows = FeedAdmin::pendingApprovals($mysqli, $siteId, $feedId, 100, ($wpage - 1) * 100);
$choiceUrl  = Site::url('admin/calendar/feeds/choice');
$ruleUrl    = Site::url('admin/calendar/feeds/rule');

/**
 * One rule's conditions in words, the way an administrator would say it:
 * "Category is exactly 'outreach' and title contains 'open', except title
 * contains 'planning'". Words come only from FeedAdmin's label constants;
 * the typed values are quoted as typed (the page escapes the whole line).
 *
 * @param list<array<string,mixed>> $conditions
 */
$ruleInWords = static function (array $conditions): string {
    $phrase = static function (array $c): string {
        $field = FeedAdmin::MATCH_FIELD_LABELS[(string) $c['matchField']] ?? (string) $c['matchField'];
        $type  = FeedAdmin::MATCH_TYPE_LABELS[(string) $c['matchType']] ?? (string) $c['matchType'];

        return mb_strtolower($field, 'UTF-8') . ' ' . $type . " '" . (string) $c['matchValue'] . "'";
    };
    $ordinary   = [];
    $exceptions = [];
    foreach ($conditions as $c) {
        if ((int) $c['isException'] === 1) {
            $exceptions[] = $phrase($c);
        } else {
            $ordinary[] = $phrase($c);
        }
    }
    $text = implode(' and ', $ordinary);
    if ($exceptions !== []) {
        $text .= ', except ' . implode(' or ', $exceptions);
    }

    return ($text === '') ? 'No conditions' : mb_strtoupper(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($text, 1, null, 'UTF-8');
};

/** A day on the organisation's calendar (a rule's or choice's From/To), as words. */
$dayInWords = static function (?string $ymd): string {
    $d = ($ymd === null) ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);

    return ($d === false) ? '' : $d->format('D j M Y');
};

$isOn     = ((int) $calendar['isActive'] === 1);
$waiting  = (int) $calendar['waiting'];
$stale    = ($isOn === true) ? FeedAdmin::waitingForRefreshMessage((int) $calendar['stale']) : '';
$message  = (string) ($calendar['lastFetchMessage'] ?? '');
$failed   = ((int) ($calendar['lastFetchOk'] ?? 0) !== 1);
$failures = (int) $calendar['consecutiveFailures'];

$totalEvents = (int) $events['total'];
$pages       = max(1, (int) ceil($totalEvents / FeedAdmin::EVENTS_PER_PAGE));

// 🏷️ Category matching. At most 450 words in one form: PHP's usual limit of
//    1,000 posted fields would otherwise silently drop the rest (each word
//    sends two fields; challenge L17). FeedAdmin reads no more than 450 either.
$maxWords     = 450;
$shownTags    = array_slice($tags, 0, $maxWords);
$categoryName = [];
foreach ($categories as $c) {
    $categoryName[(int) $c['categoryID']] = (string) $c['categoryName'];
}
$hasDefault = ($calendar['categoryID'] !== null);

$flashMsg  = (string) ($_SESSION['flash_msg']  ?? '');
$flashType = (string) ($_SESSION['flash_type'] ?? '');
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
if (in_array($flashType, ['success', 'info', 'warning', 'danger'], true) === false) {
    $flashType = 'info';
}

$esc      = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$listUrl  = Site::url('admin/calendar/feeds');
$saveUrl  = Site::url('admin/calendar/feeds/save');
$editUrl  = Site::url('admin/calendar/feeds/edit') . '?id=' . $feedId;
$selfUrl  = Site::url('admin/calendar/feeds/view') . '?id=' . $feedId;
/** This page at events page `$p` and waiting page `$w`; a first page is left out of the address. */
$pagesUrl = static fn (int $p, int $w): string => $selfUrl . ($p > 1 ? '&page=' . $p : '') . ($w > 1 ? '&wpage=' . $w : '');

$pageTitle   = (string) $calendar['name'];
// 🍞 The trail is a list keyed by its WORDS (the house template's shape), so a
//    calendar called "Dashboard", "Admin" or "Outside calendars" used to
//    REPLACE that entry of the trail (fix round 1, L5). The calendar's name is
//    now always shown in quotation marks, which the trail's own words never
//    have, so it can never land on one of their keys. A name over 40
//    characters is cut short there (the whole name is the page's heading):
//    measured in a browser, a long name with no spaces pushed the trail past
//    the edge of a phone's screen, and the trail belongs to the shared
//    template, which this page does not change.
$trailName   = (string) $calendar['name'];
$trailName   = (mb_strlen($trailName, 'UTF-8') > 40) ? mb_substr($trailName, 0, 39, 'UTF-8') . '…' : $trailName;
$breadcrumbs = [
    'Dashboard'         => Site::url(''),
    'Admin'             => Site::url('admin'),
    'Outside calendars' => $listUrl,
    '“' . $trailName . '”' => '',
];
$csrf = $esc(Auth::csrfToken());
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:960px;">
    <h1 class="h4 mb-1 text-break"><i class="fa-solid fa-rss me-2 text-primary" aria-hidden="true"></i><?php echo $esc($calendar['name']); ?>
        <span class="badge <?php echo $isOn === true ? 'bg-success' : 'bg-secondary'; ?> ms-1 fs-6"><?php echo $isOn === true ? 'On' : 'Paused'; ?></span>
        <?php if ((int) ($calendar['lastRunCapped'] ?? 0) === 1): ?>
            <span class="badge bg-warning text-dark ms-1 fs-6" data-badge="partly-read" title="This calendar has more dates in it than the portal takes from one calendar, so only part of it was read. Nothing is ever removed on the strength of a partial reading.">Partly read</span>
        <?php endif; ?>
        <?php if ($failures > 0): ?>
            <span class="badge bg-danger ms-1 fs-6"><?php echo $failures; ?> failed in a row</span>
        <?php endif; ?>
    </h1>

    <?php if ($flashMsg !== ''): ?>
        <div class="alert alert-<?php echo $esc($flashType); ?>" role="alert"><?php echo $esc($flashMsg); ?></div>
    <?php endif; ?>

    <div class="mb-3 text-break" id="summary">
        <p class="mb-1">Who can see its events: <?php echo $esc(FeedAdmin::audienceLabel($calendar)); ?></p>
        <?php if ($calendar['audienceLevel'] === 'groups' && $calendar['list'] !== []): ?>
            <?php // The NAMES are read live from the saved list, so a person
                  // taken off it, or erased, disappears from here at once. ?>
            <p class="small text-muted mb-1">Chosen:
                <?php
                $names = [];
                foreach ($calendar['list'] as $entry) {
                    $names[] = trim((string) $entry['label'] . ' ' . ExternalAudience::stateLabel((string) $entry['state']));
                }
                echo $esc(implode(', ', $names));
                ?>
            </p>
        <?php endif; ?>
        <p class="small text-muted mb-1">
            Checked every <?php echo (int) $calendar['fetchEveryMins']; ?> minutes
            &middot; Next refresh <?php echo $isOn === true ? $esc(FeedAdmin::showMoment($calendar['nextFetchAt'] === null ? null : (string) $calendar['nextFetchAt'], $orgZone)) : '—'; ?>
        </p>
        <?php // ⏰ "Last tried" is the newest run's start, which the importer
              //    has always written in UTC — never lastFetchedAt, which older
              //    rows hold in the database server's own zone (challenge M4). ?>
        <?php if ($calendar['lastTriedAt'] !== null): ?>
            <p class="small mb-1 <?php echo $failed === true ? 'text-danger' : 'text-muted'; ?>">
                Last tried <?php echo $esc(FeedAdmin::showMoment((string) $calendar['lastTriedAt'], $orgZone)); ?><?php echo $message !== '' ? ': ' . $esc($message) : ''; ?>
            </p>
        <?php elseif ($message !== ''): ?>
            <p class="small mb-1 <?php echo $failed === true ? 'text-danger' : 'text-muted'; ?>">Last result: <?php echo $esc($message); ?></p>
        <?php else: ?>
            <p class="small text-muted mb-1">Not tried yet</p>
        <?php endif; ?>
        <?php if ($waiting > 0): ?>
            <p class="small mb-1"><i class="fa-solid fa-hourglass-half me-1" aria-hidden="true"></i><a href="#waiting"><?php echo $waiting; ?> waiting for approval</a></p>
        <?php endif; ?>
        <?php if ($stale !== ''): ?>
            <p class="small mb-1 text-warning-emphasis"><i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i><?php echo $esc($stale); ?></p>
        <?php endif; ?>
        <p class="small text-muted text-truncate mb-2" style="max-width:100%;"><?php echo $esc($calendar['url']); ?></p>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="<?php echo $esc($editUrl); ?>">Settings</a>
            <form method="post" action="<?php echo $esc($saveUrl); ?>" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="feedID" value="<?php echo $feedId; ?>">
                <input type="hidden" name="action" value="refresh">
                <input type="hidden" name="back" value="view">
                <button type="submit" class="btn btn-sm btn-outline-secondary">Refresh now</button>
            </form>
        </div>
    </div>

    <nav aria-label="On this page" class="mb-3">
        <a class="badge text-bg-light text-decoration-none" href="#history">History</a>
        <a class="badge text-bg-light text-decoration-none" href="#events">Events</a>
        <a class="badge text-bg-light text-decoration-none" href="#rules">Rules</a>
        <a class="badge text-bg-light text-decoration-none" href="#categories">Category matching</a>
        <a class="badge text-bg-light text-decoration-none" href="#waiting">Waiting for approval</a>
    </nav>

    <?php // ↔️ Every row below has `flex: 1 1 auto; min-width: 0` and `text-break` on
          //    its text column (fix round 1, M1): a row is a flex row on a wide
          //    screen, and without them one long word — an outside calendar's
          //    title, a run message, a category word — kept the column as wide as
          //    the word and spilled it past the row's edge. ?>
    <!-- ===================================================================
         🕘 History — the newest refresh attempts
         =================================================================== -->
    <section id="history" class="mb-4" aria-labelledby="history-heading">
        <h2 class="h5" id="history-heading">History</h2>
        <?php if ($runs === []): ?>
            <p class="small text-muted">No refreshes yet.</p>
        <?php else: ?>
            <div class="portal-data-list">
            <?php foreach ($runs as $run): ?>
                <?php
                // `awaitingApproval` is deliberately NOT shown: after a refresh
                // that found nothing new, or one that failed, it is 0 whatever
                // is waiting (part 7 A6), so it would mislead. The live count
                // is at the top of the page instead.
                $outcome = (string) $run['outcome'];
                $label   = FeedAdmin::RUN_OUTCOME_LABELS[$outcome] ?? 'Not recorded';
                ?>
                <div class="portal-data-row">
                    <div class="portal-data-row-main text-break" style="flex: 1 1 auto; min-width: 0;">
                        <strong><?php echo $esc(FeedAdmin::showMoment((string) $run['startedAt'], $orgZone)); ?></strong>
                        <span class="badge <?php echo $outcome === 'failed' ? 'bg-danger' : ($outcome === 'partial' ? 'bg-warning text-dark' : 'bg-secondary'); ?> ms-1"><?php echo $esc($label); ?></span>
                        <span class="small text-muted ms-1"><?php echo (string) $run['triggeredBy'] === 'manual' ? 'By hand' : 'Scheduled'; ?></span>
                        <div class="small text-muted">
                            Added <?php echo (int) $run['rowsAdded']; ?>, changed <?php echo (int) $run['rowsUpdated']; ?>,
                            removed <?php echo (int) $run['rowsRemoved']; ?>, skipped <?php echo (int) $run['rowsSkipped']; ?>
                        </div>
                        <?php if ((string) ($run['message'] ?? '') !== ''): ?>
                            <div class="small"><?php echo $esc($run['message']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- ===================================================================
         📅 Events — every live imported event, earliest first
         =================================================================== -->
    <section id="events" class="mb-4" aria-labelledby="events-heading">
        <h2 class="h5" id="events-heading">Events <span class="text-muted small">(<?php echo $totalEvents; ?>)</span></h2>
        <?php if ($events['rows'] === []): ?>
            <p class="small text-muted"><?php echo $totalEvents === 0 ? 'This calendar has no imported events yet.' : 'There are no events on this page.'; ?></p>
        <?php else: ?>
            <div class="portal-data-list">
            <?php foreach ($events['rows'] as $ev): ?>
                <?php
                $level      = (string) ($ev['importLevel'] ?? '');
                $levelLabel = FeedAdmin::LEVEL_LABELS[$level] ?? 'Not worked out yet';
                $detail     = (string) ($ev['importDetail'] ?? '');
                ?>
                <div class="portal-data-row" id="event-<?php echo (int) $ev['eventID']; ?>">
                    <div class="portal-data-row-main text-break" style="flex: 1 1 auto; min-width: 0;">
                        <strong><?php echo $esc($ev['eventName']); ?></strong>
                        <?php if ((int) $ev['importWebsite'] === 1): ?>
                            <span class="badge bg-info text-dark ms-1">On the public website</span>
                        <?php endif; ?>
                        <?php if ((int) $ev['importApiOptOut'] === 1): ?>
                            <span class="badge bg-secondary ms-1">Not sent to API keys</span>
                        <?php endif; ?>
                        <?php if ($ev['stale'] === true && $isOn === true): ?>
                            <span class="badge bg-warning text-dark ms-1">Waiting for the scheduled refresh</span>
                        <?php endif; ?>
                        <div class="small text-muted">
                            <?php echo $esc(FeedAdmin::showWallClock((string) $ev['startDateTime'], $ev['timezone'] === null ? null : (string) $ev['timezone'], (int) $ev['isAllDay'] === 1, $orgZone)); ?>
                        </div>
                        <div class="small">Who can see it: <?php echo $esc($levelLabel); ?></div>
                        <?php if ($level !== 'hidden' && isset(FeedAdmin::DETAIL_LABELS[$detail]) === true): ?>
                            <div class="small text-muted">Detail for people outside the calendar's own audience: <?php echo $esc(FeedAdmin::DETAIL_LABELS[$detail]); ?></div>
                        <?php endif; ?>
                        <div class="small text-muted">Why: <?php echo $esc(FeedAdmin::sourceLabel($ev['importSource'] === null ? null : (string) $ev['importSource'], $ev['ruleName'] === null ? null : (string) $ev['ruleName'])); ?></div>
                        <?php if ($ev['categoryName'] !== null): ?>
                            <div class="small text-muted">Category: <?php echo $esc($ev['categoryName']); ?></div>
                        <?php endif; ?>
                        <?php if ($ev['hasIdentity'] === false): ?>
                            <?php // Challenge M3: a row the old #327 importer left with no
                                  // identity. A choice is attached to an identity, so this
                                  // row can have none until a refresh gives it one; the
                                  // choice page answers "not found" for it. ?>
                            <div class="small text-muted fst-italic">This event can have its own setting after the calendar's next successful refresh.</div>
                        <?php endif; ?>
                    </div>
                    <?php if ($ev['hasIdentity'] === true): ?>
                        <div class="portal-data-row-aside d-flex flex-wrap gap-1 align-items-start" style="flex: 0 0 auto;">
                            <a class="btn btn-sm btn-outline-secondary" href="<?php echo $esc($choiceUrl . '?event=' . (int) $ev['eventID'] . '&scope=date'); ?>">Set this date…<span class="visually-hidden"> <?php echo $esc($ev['eventName']); ?></span></a>
                            <?php if ($ev['inSeries'] === true): ?>
                                <a class="btn btn-sm btn-outline-secondary" href="<?php echo $esc($choiceUrl . '?event=' . (int) $ev['eventID'] . '&scope=series'); ?>">Set every date…<span class="visually-hidden"> <?php echo $esc($ev['eventName']); ?></span></a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($pages > 1): ?>
            <nav aria-label="Pages of events" class="mt-2 d-flex flex-wrap gap-2 align-items-center small">
                <?php if ($page > 1): ?>
                    <a href="<?php echo $esc($pagesUrl($page - 1, $wpage) . '#events'); ?>">Earlier events</a>
                <?php endif; ?>
                <span>Page <?php echo $page; ?> of <?php echo $pages; ?></span>
                <?php if ($page < $pages): ?>
                    <a href="<?php echo $esc($pagesUrl($page + 1, $wpage) . '#events'); ?>">Later events</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>

    <!-- ===================================================================
         📐 Rules — every rule of this calendar (chunk P8-3)
         =================================================================== -->
    <section id="rules" class="mb-4" aria-labelledby="rules-heading">
        <h2 class="h5" id="rules-heading">Rules</h2>
        <?php // "More widely than the calendar's own setting", not "than before"
              // (chunk P8-4 fix round 1): the resolver compares a rule's or a
              // choice's answer with the calendar's own setting, or with
              // "administrators only" for a date marked private — never with what
              // the date showed before. The same words as the inbox and the guide. ?>
        <p class="small text-muted">
            A rule shows the events that match it to the people it names, or hides them. A choice for one date, or for
            every date of a repeating event, decides those dates instead. Showing an event more widely than the
            calendar's own setting — or, for a date the outside calendar marks private, to anyone but administrators —
            waits for an administrator's approval.
        </p>
        <p><a class="btn btn-sm btn-outline-primary" href="<?php echo $esc($ruleUrl . '?feed=' . $feedId); ?>">Add a rule</a></p>
        <?php if ($rules === []): ?>
            <p class="small text-muted">This calendar has no rules yet.</p>
        <?php else: ?>
            <div class="portal-data-list">
            <?php foreach ($rules as $rule): ?>
                <?php
                $rLevel  = (string) $rule['audienceLevel'];
                $rDetail = (string) $rule['detailLevel'];
                $rFrom   = $dayInWords($rule['fromDate'] === null ? null : (string) $rule['fromDate']);
                $rTo     = $dayInWords($rule['toDate'] === null ? null : (string) $rule['toDate']);
                ?>
                <div class="portal-data-row" id="rule-<?php echo (int) $rule['ruleID']; ?>">
                    <div class="portal-data-row-main text-break" style="flex: 1 1 auto; min-width: 0;">
                        <strong><?php echo $esc($rule['name']); ?></strong>
                        <span class="badge <?php echo (int) $rule['isActive'] === 1 ? 'bg-success' : 'bg-secondary'; ?> ms-1"><?php echo (int) $rule['isActive'] === 1 ? 'On' : 'Off'; ?></span>
                        <?php if ((int) $rule['websiteOptIn'] === 1): ?>
                            <span class="badge bg-info text-dark ms-1">On the public website</span>
                        <?php endif; ?>
                        <?php if ((int) $rule['apiOptOut'] === 1): ?>
                            <span class="badge bg-secondary ms-1">Not sent to API keys</span>
                        <?php endif; ?>
                        <div class="small"><?php echo $esc($ruleInWords($rule['conditions'])); ?></div>
                        <div class="small text-muted">
                            Who can see matching events: <?php echo $esc(FeedAdmin::LEVEL_LABELS[$rLevel] ?? $rLevel); ?><?php
                            if ($rLevel !== 'hidden' && isset(FeedAdmin::DETAIL_LABELS[$rDetail]) === true) {
                                echo ' · ' . $esc(FeedAdmin::DETAIL_LABELS[$rDetail]);
                            } ?>
                        </div>
                        <?php if ($rLevel === 'groups' && $rule['list'] !== []): ?>
                            <div class="small text-muted">Chosen: <?php
                                $names = [];
                                foreach ($rule['list'] as $entry) {
                                    $names[] = trim((string) $entry['label'] . ' ' . ExternalAudience::stateLabel((string) $entry['state']));
                                }
                                echo $esc(implode(', ', $names)); ?></div>
                        <?php endif; ?>
                        <?php if ($rFrom !== '' || $rTo !== ''): ?>
                            <div class="small text-muted">Days: <?php echo $esc(($rFrom !== '' ? 'from ' . $rFrom : '') . ($rFrom !== '' && $rTo !== '' ? ' ' : '') . ($rTo !== '' ? 'until ' . $rTo : '')); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="portal-data-row-aside d-flex flex-wrap gap-1 align-items-start" style="flex: 0 0 auto;">
                        <a class="btn btn-sm btn-outline-secondary" href="<?php echo $esc($ruleUrl . '?feed=' . $feedId . '&rule=' . (int) $rule['ruleID']); ?>">Edit<span class="visually-hidden"> <?php echo $esc($rule['name']); ?></span></a>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- ===================================================================
         🏷️ Category matching
         =================================================================== -->
    <section id="categories" class="mb-4" aria-labelledby="categories-heading">
        <h2 class="h5" id="categories-heading">Category matching</h2>
        <?php // `text-break` (chunk P8-4, the owner's decision of 5 October 2026): the
              // default category's name below is the organisation's own text and may be
              // one very long word, which pushed the whole page sideways on a phone
              // (measured: 670 px wide in a 390 px window). It may now wrap. ?>
        <p class="small text-muted text-break" id="categories-help">
            Each word below comes from the outside calendar's own categories. Choose which of this organisation's
            categories it means. An event gets the category of the first of its words, in alphabetical order, that
            is matched to a category here.
            <?php if ($hasDefault === true): ?>
                An event with no such word gets the calendar's default category<?php echo isset($categoryName[(int) $calendar['categoryID']]) === true ? ' (' . $esc($categoryName[(int) $calendar['categoryID']]) . ')' : ''; ?>.
            <?php else: ?>
                An event with no such word gets no category, because this calendar has no default category.
            <?php endif; ?>
            "Not matched" and "None" have the same effect today; "None" records that somebody decided this word
            should not give a category of its own.
        </p>
        <?php if ($shownTags === []): ?>
            <p class="small text-muted">No category words on this calendar's events yet.</p>
        <?php else: ?>
            <?php if (count($tags) > $maxWords): ?>
                <p class="small text-warning-emphasis">
                    This calendar has <?php echo count($tags); ?> category words. Only the first <?php echo $maxWords; ?> are
                    shown here, because a form cannot safely send more at once.
                </p>
            <?php endif; ?>
            <?php // 🔐 The form token is the FIRST field: if a browser ever did
                  //    drop fields past PHP's limit, the token would still arrive
                  //    and the words that did arrive would be judged fairly. ?>
            <form method="post" action="<?php echo $esc($saveUrl); ?>" aria-describedby="categories-help">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="action" value="categories">
                <input type="hidden" name="feedID" value="<?php echo $feedId; ?>">
                <input type="hidden" name="back" value="view">
                <div class="portal-data-list mb-2">
                <?php foreach ($shownTags as $i => $tag): ?>
                    <?php
                    $word = (string) $tag['word'];
                    if ($tag['mapped'] === false) {
                        $selected = 'default';
                    } elseif ($tag['categoryID'] === null) {
                        $selected = 'none';
                    } else {
                        $selected = (string) $tag['categoryID'];
                    }
                    // A word already matched to a category that has since been
                    // switched off keeps it when saved unchanged (FeedAdmin's
                    // keep rule) — so it must stay selectable.
                    $keptOff = ($tag['categoryID'] !== null && isset($categoryName[(int) $tag['categoryID']]) === false);
                    $boxId   = 'map-' . (int) $i;
                    ?>
                    <div class="portal-data-row">
                        <div class="portal-data-row-main row g-2 align-items-center text-break" style="flex: 1 1 auto; min-width: 0;">
                            <div class="col-12 col-md-5">
                                <label class="form-label mb-0" for="<?php echo $esc($boxId); ?>"><?php echo $esc($word); ?>
                                    <?php if ($tag['present'] === false): ?>
                                        <span class="small text-muted">(not on any event right now)</span>
                                    <?php endif; ?>
                                </label>
                                <input type="hidden" name="map[<?php echo (int) $i; ?>][word]" value="<?php echo $esc($word); ?>">
                            </div>
                            <div class="col-12 col-md-7">
                                <select class="form-select form-select-sm" id="<?php echo $esc($boxId); ?>" name="map[<?php echo (int) $i; ?>][choice]">
                                    <?php // "None" means "use the calendar's default category" when the
                                          // calendar has one (the resolver skips a word matched to no
                                          // category — the #514 part 8 decision of 25 September 2026,
                                          // item 7), so the words say so. ?>
                                    <option value="default"<?php echo $selected === 'default' ? ' selected' : ''; ?>><?php echo $hasDefault === true ? "Not matched (use the calendar's default category)" : 'Not matched (no category)'; ?></option>
                                    <option value="none"<?php echo $selected === 'none' ? ' selected' : ''; ?>><?php echo $hasDefault === true ? "None (use the calendar's default category)" : 'None (no category)'; ?></option>
                                    <?php if ($keptOff === true): ?>
                                        <option value="<?php echo (int) $tag['categoryID']; ?>" selected>The current category (switched off)</option>
                                    <?php endif; ?>
                                    <?php foreach ($categories as $c): ?>
                                        <option value="<?php echo (int) $c['categoryID']; ?>"<?php echo $selected === (string) $c['categoryID'] ? ' selected' : ''; ?>><?php echo $esc($c['categoryName']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Save category matching</button>
            </form>
        <?php endif; ?>
    </section>

    <!-- ===================================================================
         ⏳ Waiting for approval — this calendar's waiting dates (chunk P8-4)
         =================================================================== -->
    <section id="waiting" class="mb-4" aria-labelledby="waiting-heading">
        <h2 class="h5" id="waiting-heading">Waiting for approval <span class="text-muted small">(<?php echo (int) $waitingRows['total']; ?>)</span></h2>
        <p class="small text-muted">
            Dates a rule or a choice would show more widely than this calendar's own setting — or, for a date the
            outside calendar marks private, to anyone but administrators. Until a date is approved, it keeps the
            calendar's own setting, or stays hidden if the outside calendar marks it private.
        </p>
        <?php if ($waitingRows['rows'] === []): ?>
            <p class="small text-muted" id="waiting-none"><?php echo $wpage > 1 ? 'There are no waiting dates on this page.' : 'No dates of this calendar are waiting.'; ?></p>
        <?php else: ?>
            <?php
            require_once __DIR__ . DIRECTORY_SEPARATOR . '_approval-rows.php';
            portal_feed_approval_rows([
                'rows'         => $waitingRows['rows'],
                'csrf'         => Auth::csrfToken(),
                'back'         => 'feed',
                'feedId'       => $feedId,
                'showCalendar' => false,
                'orgZone'      => $orgZone,
                'prefix'       => 'waiting',
            ]);
            ?>
        <?php endif; ?>
        <?php $wpages = max(1, (int) ceil((int) $waitingRows['total'] / 100)); ?>
        <?php if ($wpages > 1): ?>
            <nav aria-label="Pages of waiting dates" class="mt-2 d-flex flex-wrap gap-2 align-items-center small">
                <?php if ($wpage > 1): ?>
                    <a href="<?php echo $esc($pagesUrl($page, $wpage - 1) . '#waiting'); ?>">Earlier waiting dates</a>
                <?php endif; ?>
                <span>Page <?php echo $wpage; ?> of <?php echo $wpages; ?></span>
                <?php if ($wpage < $wpages): ?>
                    <a href="<?php echo $esc($pagesUrl($page, $wpage + 1) . '#waiting'); ?>">Later waiting dates</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
