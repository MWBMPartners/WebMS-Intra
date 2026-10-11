<?php
// Path: _apps/admin/calendar/feeds.php
/**
 * -----------------------------------------------------------------------------
 * Admin — the outside calendars this organisation subscribes to 🗓️📋
 * -----------------------------------------------------------------------------
 * One row per outside calendar of THIS organisation: its name, whether it is
 * switched on, who can see its events, what happened the last time the
 * portal tried to refresh it, when it will next try, how many of its dates
 * are waiting for approval, and the buttons to open it, change its settings,
 * refresh it now, pause or resume it, or delete it.
 *
 * WHAT #514 PART P8 CHANGED HERE (chunk P8-2), AND WHY
 * ----------------------------------------------------
 *   * **It asks one class for everything.** Every figure on this page comes
 *     from `Portal\Core\FeedAdmin::calendars()`. The page itself runs no SQL
 *     and names no table. FeedAdmin is where every read and write of the
 *     outside-calendar tables lives, so the "only this organisation's rows"
 *     rule is written once there, not once per page (see that file's
 *     header).
 *   * **"Last tried" comes from the refresh history, not `lastFetchedAt`.**
 *     The importer used to write `lastFetchedAt` in the database SERVER's own
 *     time zone (`NOW()`), and printed it here with `strtotime()`, which read
 *     it in PHP's zone — so on a server not set to UTC, or in British Summer
 *     Time, the time shown was an hour or more out (#514 part 8 plan, S14
 *     and challenge M4). "Last tried" is now the newest run's `startedAt`,
 *     which the importer has always written in UTC, shown in the
 *     organisation's own zone with its abbreviation (BST, GMT and so on) by
 *     `FeedAdmin::showMoment()`. Old `lastFetchedAt` values never reach the
 *     screen.
 *   * **The waiting count is live** (C10 item 5): it counts the waiting rows
 *     as they are now, never the number a past refresh recorded (which is 0
 *     after a refresh that found nothing new, whatever is still waiting).
 *   * **A warning line for events waiting for the scheduled refresh** (C10
 *     item 6): an event whose stored answer ("who can see it") has run out
 *     is shown only to administrators until the scheduled refresh works it
 *     out again. The line is shown only for a switched-on calendar — the
 *     scheduled job skips paused ones, and a paused calendar's events are
 *     hidden from everyone anyway (challenge L9).
 *   * **Adding a calendar moved to its own form** (`feeds/edit`), which also
 *     lets the administrator choose who can see its events before the first
 *     refresh. The old in-page form made every new calendar public until
 *     somebody changed it.
 *   * **Every address comes from `Site::url()`**, so every link and button
 *     keeps the organisation's own address prefix when organisations are
 *     told apart by the first part of the address. A bare address starting /admin/ would
 *     silently switch the administrator to another organisation.
 *   * **A refusal is the portal's own 403 page** (`Router::renderError()`),
 *     not a bare "Forbidden".
 *
 * WAITING FOR APPROVAL (chunk P8-4)
 * --------------------------------
 * The waiting count links to where those dates are decided: the Approvals
 * inbox's `#imports` section when the organisation has the Approvals app
 * switched on, else the calendar's own page `#waiting`. When the app is
 * off, every `approvals…` address answers "this app is switched off", so a
 * link there would strand the administrator; a note above the list says
 * where the dates are listed instead.
 *
 * THE "PARTLY READ" BADGE (kept from P6)
 * -------------------------------------
 * Shown when `lastRunCapped` is 1: the newest refresh that actually READ
 * the calendar found more dates than the portal takes from one calendar, so
 * only part of it was read. It comes from `FeedAdmin::calendars()` (added in
 * chunk P8-2's follow-up), which returns the importer's own record of that —
 * see `FeedAdmin::calendar()` for why that record, and not the newest run's
 * outcome, is the one to trust.
 *
 * WHAT THIS PAGE DOES NOT CHECK
 * -----------------------------
 * Who is asking is checked HERE (an administrator of this organisation),
 * not by FeedAdmin's reads, which take no actor by design.
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

use Portal\Core\App;
use Portal\Core\AppRegistry;
use Portal\Core\Auth;
use Portal\Core\EventVisibility;
use Portal\Core\FeedAdmin;
use Portal\Core\FeedResolver;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

// 🛡️ An administrator OF THIS ORGANISATION — a site administrator of it, or a
//    global administrator. The old portal-wide "administrator" flag
//    (`App::isAdmin()`) is NOT enough: it says nothing about which
//    organisation somebody belongs to (#514 leak-hunt finding 3).
if (EventVisibility::isAdminOfSite($mysqli, $userId, $siteId) === false) {
    Router::renderError(403);
    return;
}

$calendars = FeedAdmin::calendars($mysqli, $siteId);
$orgZone   = FeedResolver::organisationZone($siteId);

// 💬 The one message the last button press left for this page. The type is
//    checked against the four the page knows, so nothing else can reach the
//    `class` attribute.
$flashMsg  = (string) ($_SESSION['flash_msg']  ?? '');
$flashType = (string) ($_SESSION['flash_type'] ?? '');
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
if (in_array($flashType, ['success', 'info', 'warning', 'danger'], true) === false) {
    $flashType = 'info';
}

$esc     = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$saveUrl = Site::url('admin/calendar/feeds/save');
$editUrl = Site::url('admin/calendar/feeds/edit');
$viewUrl = Site::url('admin/calendar/feeds/view');
$inboxOn = AppRegistry::isEnabled('approvals');

$pageTitle   = 'Outside calendars';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Admin' => Site::url('admin'), 'Outside calendars' => ''];
// The form token is printed AFTER anything that could have checked one, so
// it is the current one (a successful check replaces it, Auth.php).
$csrf = $esc(Auth::csrfToken());
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:880px;">
    <h1 class="h4 mb-2"><i class="fa-solid fa-rss me-2 text-primary" aria-hidden="true"></i>Outside calendars</h1>
    <p class="text-muted small">
        Subscribe the portal to a calendar somebody else publishes — a partner organisation's diary, a
        denominational bulletin, a public-holiday calendar. The portal checks each one regularly and copies its
        events into the organisation's calendar, shown only to the people you choose.
    </p>

    <?php if ($flashMsg !== ''): ?>
        <div class="alert alert-<?php echo $esc($flashType); ?>" role="alert"><?php echo $esc($flashMsg); ?></div>
    <?php endif; ?>

    <?php if (App::isRootAdmin() === true): ?>
        <p class="small text-muted" id="feeds-global-admin-note">
            <i class="fa-solid fa-globe me-1" aria-hidden="true"></i>
            You are a global administrator. This page lists the outside calendars of the organisation you are
            looking at now. To manage another organisation's, open that organisation first, then open Outside
            calendars again.
        </p>
    <?php endif; ?>

    <?php if ($inboxOn === false): ?>
        <p class="small text-muted" id="feeds-approvals-off-note">
            <i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i>
            The Approvals app is switched off for this organisation, so imported events waiting for approval are
            listed on each calendar's own page instead.
        </p>
    <?php endif; ?>

    <p><a class="btn btn-primary btn-sm" href="<?php echo $esc($editUrl); ?>"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add an outside calendar</a></p>

    <?php if ($calendars === []): ?>
        <div class="alert alert-info small">No outside calendars yet.</div>
    <?php else: ?>
        <div class="portal-data-list mb-4">
        <?php foreach ($calendars as $c): ?>
            <?php
            $feedId   = (int) $c['feedID'];
            $isOn     = ((int) $c['isActive'] === 1);
            $failures = (int) $c['consecutiveFailures'];
            $waiting  = (int) $c['waiting'];
            $stale    = ($isOn === true) ? FeedAdmin::waitingForRefreshMessage((int) $c['stale']) : '';
            $message  = (string) ($c['lastFetchMessage'] ?? '');
            $failed   = ((int) ($c['lastFetchOk'] ?? 0) !== 1);
            ?>
            <div class="portal-data-row" id="feed-<?php echo $feedId; ?>">
                <?php // ↔️ Why the style on the text column (fix round 1, M1): a list row is a
                      //    flex row on a wide screen, and a flex item will not shrink below the
                      //    width of its own content unless told it may (`min-width: 0`). A
                      //    Google "secret address" is about 120 characters, so without this the
                      //    text column stayed as wide as the address and pushed the buttons out
                      //    of the row, where the list's own `overflow: hidden` hid them. With it,
                      //    the column takes the room the buttons leave, and the address is cut
                      //    short with an ellipsis. `text-break` lets a long name with no spaces
                      //    wrap instead of spilling. Done on this page only: portal.css is
                      //    shared by every page, and changing its rules is not this fix's job. ?>
                <div class="portal-data-row-main text-break" style="flex: 1 1 auto; min-width: 0;">
                    <strong><?php echo $esc($c['name']); ?></strong>
                    <span class="badge <?php echo $isOn === true ? 'bg-success' : 'bg-secondary'; ?> ms-1"><?php echo $isOn === true ? 'On' : 'Paused'; ?></span>
                    <?php if ((int) ($c['lastRunCapped'] ?? 0) === 1): ?>
                        <span class="badge bg-warning text-dark ms-1" data-badge="partly-read" title="This calendar has more dates in it than the portal takes from one calendar, so only part of it was read. Nothing is ever removed on the strength of a partial reading.">Partly read</span>
                    <?php endif; ?>
                    <?php if ($failures > 0): ?>
                        <span class="badge bg-danger ms-1"><?php echo $failures; ?> failed in a row</span>
                    <?php endif; ?>
                    <div class="small">Who can see its events: <?php echo $esc(FeedAdmin::audienceLabel($c)); ?></div>
                    <div class="small text-muted">
                        Checked every <?php echo (int) $c['fetchEveryMins']; ?> minutes
                        &middot; Next refresh <?php echo $isOn === true ? $esc(FeedAdmin::showMoment($c['nextFetchAt'] === null ? null : (string) $c['nextFetchAt'], $orgZone)) : '—'; ?>
                    </div>
                    <?php // ⏰ "Last tried" is the newest run's start (UTC), never
                          //    lastFetchedAt — see the header. A calendar with a
                          //    message but no run row (older than the history
                          //    table) shows the message without a time rather
                          //    than a time that could be wrong. ?>
                    <?php if ($c['lastTriedAt'] !== null): ?>
                        <div class="small <?php echo $failed === true ? 'text-danger' : 'text-muted'; ?>">
                            Last tried <?php echo $esc(FeedAdmin::showMoment((string) $c['lastTriedAt'], $orgZone)); ?><?php echo $message !== '' ? ': ' . $esc($message) : ''; ?>
                        </div>
                    <?php elseif ($message !== ''): ?>
                        <div class="small <?php echo $failed === true ? 'text-danger' : 'text-muted'; ?>">Last result: <?php echo $esc($message); ?></div>
                    <?php else: ?>
                        <div class="small text-muted">Not tried yet</div>
                    <?php endif; ?>
                    <?php if ($waiting > 0): ?>
                        <?php // A present fact: waiting rows counted as they are now. It
                              // links to where they are decided (see the header). ?>
                        <div class="small"><i class="fa-solid fa-hourglass-half me-1" aria-hidden="true"></i><a
                            href="<?php echo $esc($inboxOn === true ? Site::url('approvals') . '#imports' : $viewUrl . '?id=' . $feedId . '#waiting'); ?>"
                            data-waiting-link="<?php echo $feedId; ?>"><?php echo $waiting; ?> waiting for approval</a></div>
                    <?php endif; ?>
                    <?php if ($stale !== ''): ?>
                        <div class="small text-warning-emphasis"><i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i><?php echo $esc($stale); ?></div>
                    <?php endif; ?>
                    <?php // The address stays visible to administrators (P6 kept it):
                          // it is how they tell two calendars apart. It is never
                          // put into a message or a log line (some are secret links). ?>
                    <div class="small text-muted text-truncate" style="max-width:100%;"><?php echo $esc($c['url']); ?></div>
                </div>
                <?php // ♿ Each button's visible word is short ("Open", "Pause");
                      //    the calendar's name follows it for screen readers
                      //    only, so a list of buttons read out of context still
                      //    says which calendar each one acts on. ?>
                <div class="portal-data-row-aside d-flex flex-wrap gap-1 align-items-start" style="flex: 0 0 auto;">
                    <a class="btn btn-sm btn-outline-primary" href="<?php echo $esc($viewUrl . '?id=' . $feedId); ?>">Open<span class="visually-hidden"> <?php echo $esc($c['name']); ?></span></a>
                    <a class="btn btn-sm btn-outline-secondary" href="<?php echo $esc($editUrl . '?id=' . $feedId); ?>">Settings<span class="visually-hidden"> of <?php echo $esc($c['name']); ?></span></a>
                    <form method="post" action="<?php echo $esc($saveUrl); ?>" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="feedID" value="<?php echo $feedId; ?>">
                        <input type="hidden" name="action" value="refresh">
                        <input type="hidden" name="back" value="list">
                        <button type="submit" class="btn btn-sm btn-outline-secondary">Refresh now<span class="visually-hidden">: <?php echo $esc($c['name']); ?></span></button>
                    </form>
                    <form method="post" action="<?php echo $esc($saveUrl); ?>" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="feedID" value="<?php echo $feedId; ?>">
                        <input type="hidden" name="action" value="<?php echo $isOn === true ? 'pause' : 'resume'; ?>">
                        <input type="hidden" name="back" value="list">
                        <button type="submit" class="btn btn-sm btn-outline-secondary"><?php echo $isOn === true ? 'Pause' : 'Resume'; ?><span class="visually-hidden"> <?php echo $esc($c['name']); ?></span></button>
                    </form>
                    <form method="post" action="<?php echo $esc($saveUrl); ?>" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="feedID" value="<?php echo $feedId; ?>">
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="back" value="list">
                        <button type="submit" class="btn btn-sm btn-outline-danger"
                                data-confirm="Delete this calendar and all its imported events? Any RSVPs on them are deleted too."
                                data-confirm-destructive="true">Delete<span class="visually-hidden"> <?php echo $esc($c['name']); ?></span></button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
