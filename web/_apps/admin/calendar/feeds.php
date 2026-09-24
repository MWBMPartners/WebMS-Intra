<?php
// Path: _apps/admin/calendar/feeds.php
/**
 * -----------------------------------------------------------------------------
 * Admin — the outside calendars this organisation subscribes to 🗓️📋
 * -----------------------------------------------------------------------------
 * Lists each calendar, whether it is switched on, and what happened the last
 * time the portal tried to refresh it. The buttons post to
 * `/admin/calendar/feeds/save`.
 *
 * WHAT #514 PART P6 CHANGED HERE
 * ------------------------------
 *   * **Who may see this page.** It used to ask `App::isAdmin()`, which says
 *     yes to the old portal-wide "administrator" flag — a flag that says
 *     nothing about WHICH organisation somebody belongs to (#514 leak-hunt
 *     finding 3). It now asks whether the person is an administrator OF THIS
 *     organisation, which is the same question the visibility rule asks.
 *   * **What the "last refresh" line says.** It used to print
 *     `lastFetchStatus`, which the old job filled with text such as
 *     "HTTP 404 Could not resolve host: calendar.example.com". That put the
 *     calendar's own address on the screen, and some of these addresses are
 *     secret links that let anybody holding one read the whole diary. The
 *     page now prints `lastFetchMessage`, which is plain English and never
 *     contains the address.
 *
 * This is a deliberately small page. The full calendar settings — who may see
 * its events, which categories it maps to, its own time zone, its refresh
 * history — arrive with part P8.
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
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

// 🛡️ An administrator OF THIS ORGANISATION — see the header.
if (EventVisibility::isAdminOfSite($mysqli, $userId, $siteId) === false) {
    http_response_code(403);
    exit('Forbidden');
}

$feeds = [];
$stmt  = $mysqli->prepare(
    'SELECT feedID, name, url, fetchEveryMins, isActive, lastFetchedAt, lastFetchMessage, '
    . 'lastImportCount, lastFetchOk, lastRunCapped, consecutiveFailures '
    . 'FROM tblExternalFeeds WHERE siteID = ? ORDER BY name'
);
$stmt->bind_param('i', $siteId);
$stmt->execute();
$result = $stmt->get_result();
while (($r = $result->fetch_assoc()) !== null) {
    $feeds[] = $r;
}
$stmt->close();

// 💬 The one message the save handler left for us.
//
// THIS BLOCK WAS MISSING, AND THE PAGE WAS SILENT. The handler behind these
// buttons has set `$_SESSION['flash_msg']` since #327 — "Invalid name or URL"
// — and this page never read it, so NOBODY HAS EVER SEEN THAT MESSAGE. An
// administrator typing an address the portal would not accept was sent back to
// a page that looked exactly as it had before, with no new calendar on it and
// nothing to say why. It was found by running the real page over real HTTP
// while proving part P6, not by reading either file: each one looks correct on
// its own.
//
// It matters far more now than it did then. The refusal message is the ONLY
// thing that tells an administrator an address was turned down, and the result
// of the first refresh is the only thing that tells them whether the calendar
// they just added actually works.
$flashMsg  = (string) ($_SESSION['flash_msg']  ?? '');
$flashType = (string) ($_SESSION['flash_type'] ?? '');
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$pageTitle = 'External Calendar Feeds';
$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:880px;">
    <h1 class="h4 mb-2"><i class="fa-solid fa-rss me-2 text-primary"></i>Outside calendars</h1>
    <p class="text-muted small">Subscribe the portal to a calendar somebody else publishes — a partner organisation's diary, a denominational bulletin, a public-holiday calendar. The portal checks each one regularly and copies its events into <code>/calendar</code>.</p>

    <?php if ($flashMsg !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>" role="alert"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if (count($feeds) === 0): ?>
        <div class="alert alert-info small">No outside calendars yet.</div>
    <?php else: ?>
        <div class="portal-data-list mb-4">
        <?php foreach ($feeds as $f): ?>
            <div class="portal-data-row">
                <div class="portal-data-row-main">
                    <strong><?php echo htmlspecialchars((string) $f['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <span class="badge <?php echo (int) $f['isActive'] === 1 ? 'bg-success' : 'bg-secondary'; ?> ms-1"><?php echo (int) $f['isActive'] === 1 ? 'On' : 'Paused'; ?></span>
                    <?php if ((int) $f['lastRunCapped'] === 1): ?>
                        <span class="badge bg-warning text-dark ms-1" title="This calendar has more dates in it than the portal takes from one calendar, so only part of it was read. Nothing is ever removed on the strength of a partial reading.">Partly read</span>
                    <?php endif; ?>
                    <?php if ((int) $f['consecutiveFailures'] > 0): ?>
                        <span class="badge bg-danger ms-1"><?php echo (int) $f['consecutiveFailures']; ?> failed in a row</span>
                    <?php endif; ?>
                    <div class="small text-muted">
                        Checked every <strong><?php echo (int) $f['fetchEveryMins']; ?> minutes</strong>
                        <?php if (empty($f['lastFetchedAt']) === false): ?>
                            &middot; last tried <?php echo htmlspecialchars(date('j M H:i', strtotime((string) $f['lastFetchedAt'])), ENT_QUOTES, 'UTF-8'); ?>
                            (<?php echo (int) $f['lastImportCount']; ?> events)
                        <?php else: ?>
                            &middot; not tried yet
                        <?php endif; ?>
                    </div>
                    <?php if (($f['lastFetchMessage'] ?? '') !== ''): ?>
                        <?php // 🔒 lastFetchMessage, never lastFetchStatus: the old column held text
                              //    that could contain the calendar's own address. See the header. ?>
                        <div class="small <?php echo ((int) ($f['lastFetchOk'] ?? 0) === 1) ? 'text-muted' : 'text-danger'; ?>">
                            <?php echo htmlspecialchars((string) $f['lastFetchMessage'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php endif; ?>
                    <div class="small text-muted text-truncate" style="max-width:540px;"><?php echo htmlspecialchars((string) $f['url'], ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="portal-data-row-aside">
                    <form method="post" action="/admin/calendar/feeds/save" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="feedID" value="<?php echo (int) $f['feedID']; ?>">
                        <input type="hidden" name="action" value="<?php echo (int) $f['isActive'] === 1 ? 'pause' : 'resume'; ?>">
                        <button class="btn btn-sm btn-outline-secondary" title="<?php echo (int) $f['isActive'] === 1 ? 'Pause this calendar' : 'Switch this calendar back on'; ?>" aria-label="<?php echo (int) $f['isActive'] === 1 ? 'Pause this calendar' : 'Switch this calendar back on'; ?>"><i class="fa-solid fa-<?php echo (int) $f['isActive'] === 1 ? 'pause' : 'play'; ?>" aria-hidden="true"></i></button>
                    </form>
                    <form method="post" action="/admin/calendar/feeds/save" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="feedID" value="<?php echo (int) $f['feedID']; ?>">
                        <input type="hidden" name="action" value="remove">
                        <button class="btn btn-sm btn-outline-danger" data-confirm="Delete this calendar and every event it brought in?" data-confirm-destructive="true" title="Delete this calendar" aria-label="Delete this calendar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h2 class="h6">Add a calendar</h2>
    <p class="text-muted small">Paste the calendar's published address. A <code>webcal://</code> link works: the portal turns it into an ordinary secure address for you. Addresses inside a private network are refused.</p>
    <form method="post" action="/admin/calendar/feeds/save" class="row g-2 align-items-end">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="action" value="add">
        <div class="col-md-4"><label class="form-label small" for="feedName">Name</label><input id="feedName" type="text" name="name" required maxlength="120" class="form-control form-control-sm" placeholder="e.g. Conference calendar"></div>
        <div class="col-md-6"><label class="form-label small" for="feedUrl">Calendar address</label><input id="feedUrl" type="text" name="url" required maxlength="2000" class="form-control form-control-sm" placeholder="https://example.com/calendar.ics"></div>
        <div class="col-md-2"><label class="form-label small" for="feedMins">Check every (minutes)</label><input id="feedMins" type="number" name="fetchEveryMins" min="15" max="10080" value="360" class="form-control form-control-sm"></div>
        <div class="col-md-12"><button class="btn btn-primary btn-sm"><i class="fa-solid fa-plus me-1"></i>Add calendar</button></div>
    </form>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
