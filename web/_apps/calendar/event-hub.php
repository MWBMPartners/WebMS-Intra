<?php
// Path: _apps/calendar/event-hub.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Event Team Hub 🧑‍🤝‍🧑 (#386 Phase 1)
 * -----------------------------------------------------------------------------
 * Per-event staff/volunteer/organiser landing page. Gathers: an event
 * banner, the viewer's own crew/job/role roster context, a Resources list
 * (links + notes grouped into free-text sections), a video grid (YouTube /
 * Vimeo / Cloudflare Stream signed playback), and — for coordinators/
 * admins — inline add/edit forms plus a tool strip linking the existing
 * crews/jobs/attendance/broadcast/registrations pages (previously reachable
 * only by hand-typing the URL).
 *
 * GET /calendar/event/hub?eventID=N
 *
 * Access: Auth::isEventTeamMember() — coordinator, crew member, job
 * assignee, or tblEventPeople row. Manage rights (inline forms + tool
 * strip): App::isAdmin() || Auth::isCoordinatorOf() (unchanged house idiom).
 *
 * Phase 1 video handling is EXTERNAL REFERENCES ONLY — see
 * event-hub-save.php. Cloudflare Stream videos use signed-URL playback via
 * Portal\Core\VideoEmbed::signedToken(); a video whose signing key isn't
 * configured renders an "unavailable" tile, never a broken iframe.
 *
 * @package   Portal\Calendar
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/386
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\CloudflareStream;
use Portal\Core\Markdown;
use Portal\Core\Settings;
use Portal\Core\Site;
use Portal\Core\VideoEmbed;

Auth::ensureSession();
Auth::requireLogin();

$eventId = (int) ($_GET['eventID'] ?? 0);
if ($eventId <= 0 || Auth::isEventTeamMember($eventId) === false) {
    http_response_code(403);
    exit('Forbidden');
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

// 📋 Fetch event (site-scoped, soft-delete aware).
$event = null;
$stmt = $mysqli->prepare(
    'SELECT eventID, eventName, eventSlug, startDateTime, endDateTime, '
    . '       locationName, status '
    . 'FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $eventId, $siteId);
    $stmt->execute();
    $event = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}
if ($event === null) {
    http_response_code(404);
    exit('Event not found');
}

$canManage = App::isAdmin() === true || Auth::isCoordinatorOf($eventId) === true;

// 🎫 Viewer's own roster context — crew(s), job(s), event-person role(s).
$rosterChips = [];
if (Auth::isCoordinatorOf($eventId) === true) {
    $rosterChips[] = ['label' => 'Coordinator', 'icon' => 'fa-user-shield', 'class' => 'bg-primary'];
}

$stmt = $mysqli->prepare(
    'SELECT c.name, m.role FROM tblEventCrewMembers m '
    . 'JOIN tblEventCrews c ON c.crewID = m.crewID '
    . 'WHERE c.eventID = ? AND m.userID = ?'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $eventId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $label = (string) $r['name'] . ($r['role'] === 'leader' ? ' (Leader)' : '');
        $rosterChips[] = ['label' => $label, 'icon' => 'fa-people-group', 'class' => 'bg-secondary'];
    }
    $stmt->close();
}

$stmt = $mysqli->prepare(
    'SELECT j.name FROM tblEventJobAssignments a '
    . 'JOIN tblEventJobs j ON j.jobID = a.jobID '
    . 'WHERE j.eventID = ? AND a.userID = ?'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $eventId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $rosterChips[] = ['label' => (string) $r['name'], 'icon' => 'fa-briefcase', 'class' => 'bg-info text-dark'];
    }
    $stmt->close();
}

$stmt = $mysqli->prepare('SELECT role FROM tblEventPeople WHERE eventID = ? AND userID = ?');
if ($stmt !== false) {
    $stmt->bind_param('ii', $eventId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $rosterChips[] = ['label' => ucfirst((string) $r['role']), 'icon' => 'fa-star', 'class' => 'bg-warning text-dark'];
    }
    $stmt->close();
}

// 📚 Resources, grouped by section (preserves section-then-sortOrder order).
$resourceSections = [];
$stmt = $mysqli->prepare(
    'SELECT resourceID, section, resourceType, title, url, body, sortOrder '
    . 'FROM tblEventHubResources WHERE eventID = ? ORDER BY section ASC, sortOrder ASC, resourceID ASC'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $eventId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $section = (string) $r['section'];
        if (isset($resourceSections[$section]) === false) {
            $resourceSections[$section] = [];
        }
        $resourceSections[$section][] = $r;
    }
    $stmt->close();
}

// 🎥 Videos + their resolved (or unavailable) embed src.
$videos = [];
$stmt = $mysqli->prepare(
    'SELECT videoID, provider, videoRef, sourceUrl, title, requiresSignedUrl, allowedOrigins, '
    . '       uploadStatus, errorDetail, sortOrder '
    . 'FROM tblEventHubVideos WHERE eventID = ? ORDER BY sortOrder ASC, videoID ASC'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $eventId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $videos[] = $r;
    }
    $stmt->close();
}

foreach ($videos as &$video) {
    $provider = (string) $video['provider'];
    $ref      = (string) $video['videoRef'];
    $status   = (string) $video['uploadStatus'];
    $embedSrc = null;

    // 🎬 Only resolve a playable embed for refs that are actually playable
    //    — a pasted 'external' reference (Phase 1 behaviour, unchanged), or
    //    a Cloudflare upload that has reached 'ready' (#386 Phase 1.5).
    //    'pending'/'processing'/'error' uploads render a status tile
    //    instead, below — never a half-baked iframe.
    if ($status === 'external' || $status === 'ready') {
        if ($provider === 'cloudflare') {
            if ((int) $video['requiresSignedUrl'] === 1) {
                $token = VideoEmbed::signedToken($ref);
                if ($token !== null) {
                    $embedSrc = VideoEmbed::embedUrl('cloudflare', $ref, $token);
                }
            } else {
                $embedSrc = VideoEmbed::embedUrl('cloudflare', $ref);
            }
        } else {
            $embedSrc = VideoEmbed::embedUrl($provider, $ref);
        }
    }

    $video['embedSrc'] = $embedSrc;
}
unset($video);

// ☁️ Phase 1.5 — is direct upload usable on this page render?
$cfConfigured = CloudflareStream::isConfigured();

// 🔐 Page-scoped CSP extension — MUST be set before header.php is required.
//    Base policy untouched; only the origins this page's videos actually
//    need are added to frame-src.
$cspFrameExtra = VideoEmbed::frameSrcOrigins($videos);

// 🔐 Phase 1.5 (#386) — widen connect-src ONLY for a manager viewing a
//    configured install, so the browser can XHR the picked file straight
//    to Cloudflare's direct-upload host. Status polling is same-origin —
//    already covered by connect-src 'self', no extension needed for that.
//    Both known Cloudflare upload hosts are allowed; the upload JS itself
//    also refuses any uploadURL outside this pair (defence-in-depth).
if ($canManage === true && $cfConfigured === true) {
    $cspConnectExtra = 'https://upload.videodelivery.net https://upload.cloudflarestream.com';
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');

$statusBadge = [
    'draft'     => 'bg-secondary',
    'published' => 'bg-success',
    'cancelled' => 'bg-danger',
    'postponed' => 'bg-warning text-dark',
];

$pageTitle   = 'Team Hub — ' . (string) $event['eventName'];
$pageSection = 'calendar';
$breadcrumbs = [
    'Dashboard' => '/',
    'Calendar'  => '/calendar',
    (string) $event['eventName'] => '/calendar/event?slug=' . rawurlencode((string) $event['eventSlug']),
    'Team Hub'  => '',
];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container-fluid py-3">

    <?php if ($flashMsg !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <!-- 📅 Event banner -->
    <div class="d-flex flex-wrap justify-content-between align-items-start mb-3 gap-2">
        <div>
            <h1 class="h4 mb-1">
                <i class="fa-solid fa-people-roof me-2 text-primary"></i>Team Hub
            </h1>
            <p class="mb-1">
                <a href="/calendar/event?slug=<?php echo htmlspecialchars((string) $event['eventSlug'], ENT_QUOTES, 'UTF-8'); ?>" class="text-decoration-none fw-semibold">
                    <?php echo htmlspecialchars((string) $event['eventName'], ENT_QUOTES, 'UTF-8'); ?>
                </a>
                <span class="badge <?php echo $statusBadge[$event['status']] ?? 'bg-secondary'; ?> ms-2">
                    <?php echo htmlspecialchars(ucfirst((string) $event['status']), ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </p>
            <p class="text-muted small mb-0">
                <i class="fa-regular fa-calendar me-1"></i>
                <?php echo htmlspecialchars(date('j M Y, H:i', strtotime((string) $event['startDateTime'])), ENT_QUOTES, 'UTF-8'); ?>
                <?php if (!empty($event['locationName'])): ?>
                    &middot; <i class="fa-solid fa-location-dot ms-1 me-1"></i>
                    <?php echo htmlspecialchars((string) $event['locationName'], ENT_QUOTES, 'UTF-8'); ?>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <!-- 🎫 Viewer's roster context -->
    <?php if (count($rosterChips) > 0): ?>
        <div class="mb-4">
            <?php foreach ($rosterChips as $chip): ?>
                <span class="badge <?php echo htmlspecialchars($chip['class'], ENT_QUOTES, 'UTF-8'); ?> me-1 mb-1">
                    <i class="fa-solid <?php echo htmlspecialchars($chip['icon'], ENT_QUOTES, 'UTF-8'); ?> me-1"></i>
                    <?php echo htmlspecialchars($chip['label'], ENT_QUOTES, 'UTF-8'); ?>
                </span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- 🛠️ Coordinator/admin tool strip -->
    <?php if ($canManage === true): ?>
        <div class="card mb-4">
            <div class="card-body d-flex flex-wrap gap-2">
                <a href="/calendar/event/crews?eventID=<?php echo $eventId; ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="fa-solid fa-people-group me-1"></i>Crews
                </a>
                <a href="/calendar/event/jobs?eventID=<?php echo $eventId; ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="fa-solid fa-briefcase me-1"></i>Jobs
                </a>
                <a href="/calendar/event/attendance?eventID=<?php echo $eventId; ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="fa-solid fa-clipboard-check me-1"></i>Attendance
                </a>
                <a href="/calendar/event/broadcast?eventID=<?php echo $eventId; ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="fa-solid fa-bullhorn me-1"></i>Broadcast
                </a>
                <a href="/admin/calendar/registrations?eventID=<?php echo $eventId; ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="fa-solid fa-clipboard-list me-1"></i>Registrations
                </a>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- 📚 Resources -->
        <div class="col-12 col-lg-7">
            <h2 class="h5 mb-3"><i class="fa-solid fa-book-open me-2 text-primary"></i>Resources</h2>

            <?php if (count($resourceSections) === 0): ?>
                <div class="alert alert-info">No resources have been added yet.</div>
            <?php else: ?>
                <?php foreach ($resourceSections as $section => $items): ?>
                    <div class="card mb-3">
                        <div class="card-header"><strong><?php echo htmlspecialchars($section, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div class="card-body">
                            <div class="portal-data-list">
                                <?php foreach ($items as $res): ?>
                                    <div class="portal-data-row">
                                        <div class="portal-data-row-main">
                                            <?php if ($res['resourceType'] === 'link'): ?>
                                                <a href="<?php echo htmlspecialchars((string) $res['url'], ENT_QUOTES, 'UTF-8'); ?>"
                                                   class="fw-semibold text-decoration-none" target="_blank" rel="noopener">
                                                    <i class="fa-solid fa-arrow-up-right-from-square me-1 text-muted"></i>
                                                    <?php echo htmlspecialchars((string) $res['title'], ENT_QUOTES, 'UTF-8'); ?>
                                                </a>
                                            <?php else: ?>
                                                <div class="fw-semibold"><i class="fa-regular fa-note-sticky me-1 text-muted"></i><?php echo htmlspecialchars((string) $res['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                                                <div class="portal-markdown small mt-1"><?php echo Markdown::render((string) ($res['body'] ?? ''), ['allow_links' => true]); ?></div>
                                            <?php endif; ?>
                                            <?php if ($canManage === true): ?>
                                                <details class="mt-1">
                                                    <summary class="small text-decoration-underline" style="cursor:pointer;">Edit</summary>
                                                    <form method="post" action="/calendar/event/hub/save" class="row g-2 mt-1 p-2 bg-body-tertiary rounded">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                                        <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
                                                        <input type="hidden" name="action" value="editResource">
                                                        <input type="hidden" name="resourceID" value="<?php echo (int) $res['resourceID']; ?>">
                                                        <div class="col-md-4">
                                                            <label class="form-label small">Section</label>
                                                            <input type="text" name="section" maxlength="80" value="<?php echo htmlspecialchars($section, ENT_QUOTES, 'UTF-8'); ?>" class="form-control form-control-sm" required>
                                                        </div>
                                                        <div class="col-md-3">
                                                            <label class="form-label small">Type</label>
                                                            <select name="resourceType" class="form-select form-select-sm">
                                                                <option value="link" <?php echo $res['resourceType'] === 'link' ? 'selected' : ''; ?>>Link</option>
                                                                <option value="note" <?php echo $res['resourceType'] === 'note' ? 'selected' : ''; ?>>Note</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-5">
                                                            <label class="form-label small">Title</label>
                                                            <input type="text" name="title" maxlength="255" value="<?php echo htmlspecialchars((string) $res['title'], ENT_QUOTES, 'UTF-8'); ?>" class="form-control form-control-sm" required>
                                                        </div>
                                                        <div class="col-12">
                                                            <label class="form-label small">URL (if type = Link)</label>
                                                            <input type="url" name="url" maxlength="2048" value="<?php echo htmlspecialchars((string) ($res['url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" class="form-control form-control-sm">
                                                        </div>
                                                        <div class="col-12">
                                                            <label class="form-label small">Note (if type = Note, Markdown)</label>
                                                            <textarea name="body" rows="3" maxlength="10000" class="form-control form-control-sm"><?php echo htmlspecialchars((string) ($res['body'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                                                        </div>
                                                        <div class="col-12">
                                                            <button type="submit" class="btn btn-primary btn-sm">Save changes</button>
                                                        </div>
                                                    </form>
                                                </details>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($canManage === true): ?>
                                            <div class="portal-data-row-aside text-end">
                                                <form method="post" action="/calendar/event/hub/save" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                                    <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
                                                    <input type="hidden" name="action" value="reorder">
                                                    <input type="hidden" name="type" value="resource">
                                                    <input type="hidden" name="id" value="<?php echo (int) $res['resourceID']; ?>">
                                                    <button type="submit" name="direction" value="up" class="btn btn-link btn-sm p-0 me-2" title="Move up"><i class="fa-solid fa-arrow-up"></i></button>
                                                    <button type="submit" name="direction" value="down" class="btn btn-link btn-sm p-0 me-2" title="Move down"><i class="fa-solid fa-arrow-down"></i></button>
                                                </form>
                                                <form method="post" action="/calendar/event/hub/save" class="d-inline" data-confirm="Remove this resource?">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                                    <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
                                                    <input type="hidden" name="action" value="removeResource">
                                                    <input type="hidden" name="resourceID" value="<?php echo (int) $res['resourceID']; ?>">
                                                    <button type="submit" class="btn btn-link btn-sm text-danger p-0" title="Remove"><i class="fa-solid fa-trash"></i></button>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($canManage === true): ?>
                <details class="mb-4">
                    <summary class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-plus me-1"></i>Add a resource</summary>
                    <form method="post" action="/calendar/event/hub/save" class="row g-2 mt-2 p-2 bg-body-tertiary rounded">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
                        <input type="hidden" name="action" value="addResource">
                        <div class="col-md-4">
                            <label class="form-label small">Section</label>
                            <input type="text" name="section" maxlength="80" value="General" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Type</label>
                            <select name="resourceType" class="form-select form-select-sm" onchange="this.form.querySelector('.hub-res-url').classList.toggle('d-none', this.value!=='link'); this.form.querySelector('.hub-res-body').classList.toggle('d-none', this.value!=='note');">
                                <option value="link">Link</option>
                                <option value="note">Note</option>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small">Title</label>
                            <input type="text" name="title" maxlength="255" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-12 hub-res-url">
                            <label class="form-label small">URL</label>
                            <input type="url" name="url" maxlength="2048" class="form-control form-control-sm" placeholder="https://…">
                        </div>
                        <div class="col-12 hub-res-body d-none">
                            <label class="form-label small">Note (Markdown)</label>
                            <textarea name="body" rows="3" maxlength="10000" class="form-control form-control-sm"></textarea>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary btn-sm">Add resource</button>
                        </div>
                    </form>
                </details>
            <?php endif; ?>
        </div>

        <!-- 🎥 Videos -->
        <div class="col-12 col-lg-5">
            <h2 class="h5 mb-3"><i class="fa-solid fa-video me-2 text-primary"></i>Videos</h2>

            <?php if (count($videos) === 0): ?>
                <div class="alert alert-info">No videos have been added yet.</div>
            <?php else: ?>
                <div class="row g-3 mb-3">
                    <?php foreach ($videos as $video): ?>
                        <?php
                        $vStatus = (string) $video['uploadStatus'];
                        $vIsCf   = (string) $video['provider'] === 'cloudflare';
                        ?>
                        <div class="col-12">
                            <div class="card">
                                <?php if ($video['embedSrc'] !== null): ?>
                                    <div class="ratio ratio-16x9">
                                        <iframe src="<?php echo htmlspecialchars((string) $video['embedSrc'], ENT_QUOTES, 'UTF-8'); ?>"
                                                loading="lazy"
                                                allow="autoplay; encrypted-media; picture-in-picture"
                                                allowfullscreen
                                                title="<?php echo htmlspecialchars((string) $video['title'], ENT_QUOTES, 'UTF-8'); ?>"></iframe>
                                    </div>
                                <?php elseif ($vStatus === 'pending' || $vStatus === 'processing'): ?>
                                    <!-- ☁️ #386 Phase 1.5 — upload in flight; event-hub-upload.js resumes
                                         polling this tile on page load via the data- attributes below. -->
                                    <div class="ratio ratio-16x9 bg-body-tertiary d-flex align-items-center justify-content-center text-center p-3"
                                         data-hub-video-pending data-event-id="<?php echo $eventId; ?>" data-video-id="<?php echo (int) $video['videoID']; ?>">
                                        <div>
                                            <div class="spinner-border spinner-border-sm text-primary mb-2" role="status">
                                                <span class="visually-hidden">Loading…</span>
                                            </div>
                                            <div class="small text-muted">
                                                <?php echo $vStatus === 'pending' ? 'Waiting for upload…' : 'Processing on Cloudflare…'; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php elseif ($vStatus === 'error'): ?>
                                    <div class="ratio ratio-16x9 bg-body-tertiary d-flex align-items-center justify-content-center text-center p-3">
                                        <div>
                                            <i class="fa-solid fa-circle-exclamation text-danger fa-lg mb-2"></i>
                                            <div class="small text-danger">
                                                <?php echo htmlspecialchars((string) ($video['errorDetail'] ?? 'Upload failed.'), ENT_QUOTES, 'UTF-8'); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="ratio ratio-16x9 bg-body-tertiary d-flex align-items-center justify-content-center text-center p-3">
                                        <div>
                                            <i class="fa-solid fa-triangle-exclamation text-warning fa-lg mb-2"></i>
                                            <div class="small text-muted">Video unavailable — check Cloudflare Stream settings.</div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <div class="card-body py-2 d-flex justify-content-between align-items-center">
                                    <span class="small fw-semibold"><?php echo htmlspecialchars((string) $video['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php if ($canManage === true): ?>
                                        <div>
                                            <form method="post" action="/calendar/event/hub/save" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                                <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
                                                <input type="hidden" name="action" value="reorder">
                                                <input type="hidden" name="type" value="video">
                                                <input type="hidden" name="id" value="<?php echo (int) $video['videoID']; ?>">
                                                <button type="submit" name="direction" value="up" class="btn btn-link btn-sm p-0 me-2" title="Move up"><i class="fa-solid fa-arrow-up"></i></button>
                                                <button type="submit" name="direction" value="down" class="btn btn-link btn-sm p-0 me-2" title="Move down"><i class="fa-solid fa-arrow-down"></i></button>
                                            </form>
                                            <form method="post" action="/calendar/event/hub/save" class="d-inline" data-confirm="Remove this video from the hub?">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                                <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
                                                <input type="hidden" name="action" value="removeVideo">
                                                <input type="hidden" name="videoID" value="<?php echo (int) $video['videoID']; ?>">
                                                <button type="submit" class="btn btn-link btn-sm text-danger p-0" title="Remove"><i class="fa-solid fa-trash"></i></button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php if ($canManage === true && $vIsCf === true): ?>
                                    <div class="card-body py-1 border-top">
                                        <details>
                                            <summary class="small text-decoration-underline" style="cursor:pointer;">Stream settings</summary>
                                            <form method="post" action="/calendar/event/hub/video-settings" class="row g-2 mt-1 p-2 bg-body-tertiary rounded">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                                <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
                                                <input type="hidden" name="videoID" value="<?php echo (int) $video['videoID']; ?>">
                                                <div class="col-12">
                                                    <div class="form-check">
                                                        <input type="checkbox" class="form-check-input" id="hubVideoSigned<?php echo (int) $video['videoID']; ?>"
                                                               name="requiresSignedUrl" value="1" <?php echo (int) $video['requiresSignedUrl'] === 1 ? 'checked' : ''; ?>>
                                                        <label class="form-check-label small" for="hubVideoSigned<?php echo (int) $video['videoID']; ?>">Requires signed URL</label>
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label small">Allowed origins (comma-separated hostnames, blank = any)</label>
                                                    <input type="text" name="allowedOrigins" maxlength="1024" class="form-control form-control-sm"
                                                           value="<?php echo htmlspecialchars((string) ($video['allowedOrigins'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                </div>
                                                <div class="col-12">
                                                    <button type="submit" class="btn btn-sm btn-outline-primary">Save Stream settings</button>
                                                </div>
                                            </form>
                                        </details>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($canManage === true): ?>
                <details class="mb-4">
                    <summary class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-plus me-1"></i>Add a video</summary>
                    <form method="post" action="/calendar/event/hub/save" class="row g-2 mt-2 p-2 bg-body-tertiary rounded">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
                        <input type="hidden" name="action" value="addVideo">
                        <div class="col-12">
                            <label class="form-label small">YouTube / Vimeo link, or Cloudflare Stream link or UID</label>
                            <input type="text" name="sourceInput" maxlength="1024" class="form-control form-control-sm"
                                   placeholder="https://youtube.com/watch?v=… or a Cloudflare Stream UID" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small">Title</label>
                            <input type="text" name="title" maxlength="255" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="hubVideoSigned" name="requiresSignedUrl" value="1">
                                <label class="form-check-label small" for="hubVideoSigned">Requires signed URL</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary btn-sm">Add video</button>
                        </div>
                    </form>
                </details>
                <p class="text-muted small">"Requires signed URL" applies to Cloudflare Stream videos only — it must mirror the video's <code>requireSignedURLs</code> flag on the Cloudflare side.</p>
            <?php endif; ?>

            <!-- ☁️ #386 Phase 1.5 — direct browser-to-Cloudflare upload. Only
                 rendered for a manager on an install with Cloudflare Stream
                 configured; event-hub-upload.js no-ops if #hubUploadForm
                 isn't on the page, so nothing loads/executes otherwise. -->
            <?php if ($canManage === true && $cfConfigured === true): ?>
                <details class="mb-4" open>
                    <summary class="btn btn-sm btn-outline-success"><i class="fa-solid fa-cloud-arrow-up me-1"></i>Upload to Cloudflare</summary>
                    <form id="hubUploadForm" class="row g-2 mt-2 p-2 bg-body-tertiary rounded" data-event-id="<?php echo $eventId; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <div class="col-12">
                            <label class="form-label small">Video file (max 200MB)</label>
                            <input type="file" id="hubUploadFile" accept="video/*" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small">Title</label>
                            <input type="text" id="hubUploadTitle" maxlength="255" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="hubUploadSigned"
                                       <?php echo ((string) Settings::get('cfstream.defaultRequireSignedUrls', 'true') === 'true') ? 'checked' : ''; ?>>
                                <label class="form-check-label small" for="hubUploadSigned">Requires signed URL</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small">Allowed origins (optional, comma-separated hostnames)</label>
                            <input type="text" id="hubUploadOrigins" class="form-control form-control-sm" placeholder="Leave blank for the site default">
                        </div>
                        <div class="col-12">
                            <div class="progress d-none" id="hubUploadProgressWrap" role="progressbar" aria-label="Upload progress" style="height:1.25rem;">
                                <div class="progress-bar" id="hubUploadProgressBar" style="width:0%">0%</div>
                            </div>
                            <div class="small mt-1" id="hubUploadStatusLine"></div>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-success btn-sm" id="hubUploadSubmit">
                                <i class="fa-solid fa-cloud-arrow-up me-1"></i>Upload
                            </button>
                        </div>
                    </form>
                </details>
                <p class="text-muted small">Uploads go straight from your browser to Cloudflare — files up to 200MB. Larger files aren't supported yet.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
<?php if ($canManage === true && $cfConfigured === true): ?>
<script src="/assets/js/event-hub-upload.js" defer></script>
<?php endif; ?>
