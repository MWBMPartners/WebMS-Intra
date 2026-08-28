<?php
// Path: public_html/admin/livestream/index.php
/**
 * Admin — Livestream channels + schedules.
 *
 * Single-page CRUD: list channels, add channel inline, list schedules per
 * channel, add schedule inline, toggle/remove via POST actions.
 *
 * @package   Portal\Admin
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/273
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Livestream;
use Portal\Core\RateLimiter;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\WebPush;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$db     = App::db();
$siteId = Site::id();

// 📝 POST handling — single endpoint, action-dispatched.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        http_response_code(400);
        exit('Bad request');
    }
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add_channel') {
        $name      = trim((string) ($_POST['name'] ?? ''));
        $platform  = (string) ($_POST['platform'] ?? 'youtube');
        $vid       = trim((string) ($_POST['channelOrVideoId'] ?? ''));
        $override  = trim((string) ($_POST['embedHtmlOverride'] ?? ''));
        $allowed   = ['youtube','youtube-live','vimeo','twitch','facebook','custom'];
        if (in_array($platform, $allowed, true) === false) {
            $platform = 'youtube';
        }
        if ($name !== '') {
            $stmt = $db->prepare(
                'INSERT INTO tblLivestreamChannel (siteID, name, platform, channelOrVideoId, embedHtmlOverride) '
                . 'VALUES (?, ?, ?, ?, ?)'
            );
            if ($stmt !== false) {
                $vidOrNull      = $vid !== '' ? $vid : null;
                $overrideOrNull = $override !== '' ? $override : null;
                $stmt->bind_param('issss', $siteId, $name, $platform, $vidOrNull, $overrideOrNull);
                $stmt->execute();
                $stmt->close();
            }
        }
    } elseif ($action === 'delete_channel') {
        $channelId = (int) ($_POST['channelID'] ?? 0);
        if ($channelId > 0) {
            $stmt = $db->prepare('DELETE FROM tblLivestreamChannel WHERE channelID = ? AND siteID = ?');
            if ($stmt !== false) {
                $stmt->bind_param('ii', $channelId, $siteId);
                $stmt->execute();
                $stmt->close();
            }
        }
    } elseif ($action === 'add_schedule') {
        $channelId = (int) ($_POST['channelID'] ?? 0);
        $dow       = (int) ($_POST['dayOfWeek'] ?? -1);
        $start     = (string) ($_POST['startTime'] ?? '');
        $end       = (string) ($_POST['endTime'] ?? '');
        $tz        = trim((string) ($_POST['timezone'] ?? 'Europe/London'));
        if ($tz === '') { $tz = 'Europe/London'; }
        if ($channelId > 0 && $dow >= 0 && $dow <= 6 && $start !== '' && $end !== '') {
            // Verify channel belongs to current site before touching schedules.
            $check = $db->prepare('SELECT 1 FROM tblLivestreamChannel WHERE channelID = ? AND siteID = ?');
            $ok = false;
            if ($check !== false) {
                $check->bind_param('ii', $channelId, $siteId);
                $check->execute();
                $ok = $check->get_result()->fetch_row() !== null;
                $check->close();
            }
            if ($ok === true) {
                $stmt = $db->prepare(
                    'INSERT INTO tblLivestreamSchedule (channelID, dayOfWeek, startTime, endTime, timezone) '
                    . 'VALUES (?, ?, ?, ?, ?)'
                );
                if ($stmt !== false) {
                    $stmt->bind_param('iisss', $channelId, $dow, $start, $end, $tz);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
    } elseif ($action === 'delete_schedule') {
        $scheduleId = (int) ($_POST['scheduleID'] ?? 0);
        if ($scheduleId > 0) {
            $stmt = $db->prepare(
                'DELETE s FROM tblLivestreamSchedule s '
                . 'INNER JOIN tblLivestreamChannel c ON c.channelID = s.channelID '
                . 'WHERE s.scheduleID = ? AND c.siteID = ?'
            );
            if ($stmt !== false) {
                $stmt->bind_param('ii', $scheduleId, $siteId);
                $stmt->execute();
                $stmt->close();
            }
        }
    } elseif ($action === 'toggle_schedule') {
        $scheduleId = (int) ($_POST['scheduleID'] ?? 0);
        if ($scheduleId > 0) {
            $stmt = $db->prepare(
                'UPDATE tblLivestreamSchedule s '
                . 'INNER JOIN tblLivestreamChannel c ON c.channelID = s.channelID '
                . 'SET s.isActive = 1 - s.isActive '
                . 'WHERE s.scheduleID = ? AND c.siteID = ?'
            );
            if ($stmt !== false) {
                $stmt->bind_param('ii', $scheduleId, $siteId);
                $stmt->execute();
                $stmt->close();
            }
        }
    } elseif ($action === 'notify_live') {
        // 📣 "We're live now" manual push (#322) — the PRIMARY, deterministic
        // go-live trigger. Also postable from the Host Console (which passes
        // an eventID so the push URL binds the live/chat widget to that event).
        if (WebPush::isConfigured() === false) {
            $_SESSION['flash_msg']  = 'Web Push is not configured yet — set it up at /admin/integrations/push first.';
            $_SESSION['flash_type'] = 'danger';
        } elseif (RateLimiter::tooMany('pushgolive:' . $siteId, 3, 900) === true) {
            $_SESSION['flash_msg']  = 'Too many "we\'re live" pushes sent recently for this site — try again shortly.';
            $_SESSION['flash_type'] = 'warning';
        } else {
            $liveNow = Livestream::currentlyLive($siteId);
            if ($liveNow === null) {
                $_SESSION['flash_msg']  = 'No channel is currently scheduled live — nothing to notify.';
                $_SESSION['flash_type'] = 'warning';
            } else {
                $eventId  = (int) ($_POST['eventID'] ?? 0);
                $liveUrl  = $eventId > 0 ? '/live?eventID=' . $eventId : '/live';
                $ttl      = (int) (App::settings('push.ttl.golive') ?? '900');
                $siteName = (string) (App::settings('site.name') ?? 'Portal');
                $stats = WebPush::sendToChannel(
                    $siteId,
                    'livestream',
                    [
                        'title' => $siteName . ' is live now',
                        'body'  => (string) $liveNow['name'],
                        'url'   => $liveUrl,
                        'tag'   => 'golive',
                    ],
                    $ttl,
                    'high',
                    'golive' . $siteId,
                    'pushLivestream'
                );
                RateLimiter::recordHit('pushgolive:' . $siteId, 900);
                $_SESSION['flash_msg']  = 'Push sent — ' . $stats['sent'] . ' delivered, ' . $stats['failed'] . ' failed, ' . $stats['pruned'] . ' pruned.';
                $_SESSION['flash_type'] = $stats['sent'] > 0 ? 'success' : 'warning';
            }
        }

        // 🔁 Return to wherever the button was posted from — Host Console's
        // "We're live" button lives on a per-event page and should stay
        // there; the allowlist keeps this from ever being an open redirect.
        $returnTo = (string) ($_POST['returnTo'] ?? '');
        if ($returnTo !== '' && preg_match('#^/admin/host-console/event\?id=\d+$#', $returnTo) === 1) {
            header('Location: ' . $returnTo);
            exit();
        }
    }

    header('Location: /admin/livestream');
    exit();
}

// 📋 Load channels + schedules for this site.
$channels = [];
$rs = $db->query('SELECT channelID, name, platform, channelOrVideoId, embedHtmlOverride FROM tblLivestreamChannel WHERE siteID = ' . (int) $siteId . ' ORDER BY name');
if ($rs !== false) {
    while ($r = $rs->fetch_assoc()) {
        $r['schedules'] = [];
        $channels[(int) $r['channelID']] = $r;
    }
    $rs->free();
}
if (count($channels) > 0) {
    $ids = implode(',', array_map('intval', array_keys($channels)));
    $rs = $db->query(
        'SELECT scheduleID, channelID, dayOfWeek, startTime, endTime, timezone, isActive '
        . 'FROM tblLivestreamSchedule WHERE channelID IN (' . $ids . ') '
        . 'ORDER BY dayOfWeek, startTime'
    );
    if ($rs !== false) {
        while ($r = $rs->fetch_assoc()) {
            $channels[(int) $r['channelID']]['schedules'][] = $r;
        }
        $rs->free();
    }
}

$dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
$csrf     = Auth::csrfToken();

// 🔔 "We're live now" push (#322) — only offered when Web Push is
// configured AND a channel is currently in its scheduled window.
$pushConfigured = WebPush::isConfigured();
$currentlyLive  = Livestream::currentlyLive($siteId);

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$pageTitle   = 'Livestream';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Livestream' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-tower-broadcast me-2"></i>Livestream</h1>
        <p class="text-secondary mb-0">Channels and weekly schedule for the live embed.</p>
    </div>
    <div class="d-flex gap-2">
        <form method="post" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="notify_live">
            <button type="submit" class="btn btn-primary btn-sm"
                    <?php echo ($pushConfigured === false || $currentlyLive === null) ? 'disabled' : ''; ?>
                    title="<?php echo $pushConfigured === false ? 'Configure Web Push at /admin/integrations/push first' : ($currentlyLive === null ? 'No channel is currently in its scheduled live window' : 'Send a push to everyone subscribed to the livestream channel'); ?>">
                <i class="fa-solid fa-bullhorn me-1"></i>Send "We're live" notification
            </button>
        </form>
        <a href="/live" class="btn btn-outline-secondary btn-sm" target="_blank"><i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Open live page</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><strong>Add a channel</strong></div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="add_channel">
            <div class="col-md-4">
                <label class="form-label">Name</label>
                <input type="text" class="form-control" name="name" required maxlength="255" placeholder="Sabbath Service">
            </div>
            <div class="col-md-2">
                <label class="form-label">Platform</label>
                <select class="form-select" name="platform">
                    <option value="youtube">YouTube video</option>
                    <option value="youtube-live">YouTube channel-live</option>
                    <option value="vimeo">Vimeo</option>
                    <option value="twitch">Twitch</option>
                    <option value="facebook">Facebook</option>
                    <option value="custom">Custom embed</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Channel / video ID</label>
                <input type="text" class="form-control" name="channelOrVideoId" maxlength="100" placeholder="dQw4w9WgXcQ">
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button class="btn btn-primary w-100" type="submit"><i class="fa-solid fa-plus me-1"></i>Add channel</button>
            </div>
            <div class="col-12">
                <label class="form-label small text-muted">Custom embed HTML (only when platform = custom)</label>
                <textarea class="form-control font-monospace small" name="embedHtmlOverride" rows="2" placeholder="<iframe …></iframe>"></textarea>
            </div>
        </form>
    </div>
</div>

<?php if (count($channels) === 0): ?>
    <div class="alert alert-info">No channels yet. Add one above to get started.</div>
<?php else: foreach ($channels as $c): ?>
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <strong><?php echo htmlspecialchars((string) $c['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                <span class="badge bg-secondary ms-2"><?php echo htmlspecialchars((string) $c['platform'], ENT_QUOTES, 'UTF-8'); ?></span>
                <?php if ($c['channelOrVideoId'] !== null && $c['channelOrVideoId'] !== ''): ?>
                    <code class="ms-2 small"><?php echo htmlspecialchars((string) $c['channelOrVideoId'], ENT_QUOTES, 'UTF-8'); ?></code>
                <?php endif; ?>
            </div>
            <form method="post" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="delete_channel">
                <input type="hidden" name="channelID" value="<?php echo (int) $c['channelID']; ?>">
                <button type="submit"
                        class="btn btn-outline-danger btn-sm"
                        data-confirm="Delete this channel and all its schedules?"
                        title="Delete channel" aria-label="Delete channel">
                    <i class="fa-solid fa-trash" aria-hidden="true"></i>
                </button>
            </form>
        </div>
        <div class="card-body">
            <h6 class="text-muted small text-uppercase mb-3">Weekly schedule</h6>
            <?php if (count($c['schedules']) === 0): ?>
                <p class="text-muted small mb-3">No schedules yet — add the first below.</p>
            <?php else: ?>
                <div class="portal-data-list mb-3">
                    <?php foreach ($c['schedules'] as $s): ?>
                        <div class="row py-2 border-bottom align-items-center">
                            <div class="col-md-2"><strong><?php echo htmlspecialchars($dayNames[(int) $s['dayOfWeek']] ?? '?', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                            <div class="col-md-3 small">
                                <?php echo htmlspecialchars(substr((string) $s['startTime'], 0, 5), ENT_QUOTES, 'UTF-8'); ?>
                                –
                                <?php echo htmlspecialchars(substr((string) $s['endTime'], 0, 5), ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <div class="col-md-3 small text-muted"><?php echo htmlspecialchars((string) $s['timezone'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="col-md-2">
                                <?php if ((int) $s['isActive'] === 1): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Paused</span>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-2 text-end">
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="toggle_schedule">
                                    <input type="hidden" name="scheduleID" value="<?php echo (int) $s['scheduleID']; ?>">
                                    <button type="submit" class="btn btn-outline-secondary btn-sm">Toggle</button>
                                </form>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="delete_schedule">
                                    <input type="hidden" name="scheduleID" value="<?php echo (int) $s['scheduleID']; ?>">
                                    <button type="submit"
                                            class="btn btn-outline-danger btn-sm"
                                            data-confirm="Delete this schedule slot?"
                                            title="Delete schedule" aria-label="Delete schedule">
                                        <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="add_schedule">
                <input type="hidden" name="channelID" value="<?php echo (int) $c['channelID']; ?>">
                <div class="col-md-2">
                    <label class="form-label small">Day</label>
                    <select class="form-select form-select-sm" name="dayOfWeek">
                        <?php foreach ($dayNames as $i => $dn): ?>
                            <option value="<?php echo $i; ?>"><?php echo htmlspecialchars($dn, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Start</label>
                    <input type="time" class="form-control form-control-sm" name="startTime" value="10:00" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">End</label>
                    <input type="time" class="form-control form-control-sm" name="endTime" value="12:00" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Timezone</label>
                    <input type="text" class="form-control form-control-sm" name="timezone" value="Europe/London" maxlength="50" required>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-outline-primary btn-sm w-100" type="submit"><i class="fa-solid fa-plus me-1"></i>Add</button>
                </div>
            </form>
        </div>
    </div>
<?php endforeach; endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
