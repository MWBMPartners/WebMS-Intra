<?php
// Path: public_html/calendar/event.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Single Event Detail Page 📅
 * -----------------------------------------------------------------------------
 * Displays full details for a single event, including description, location,
 * people, links, materials, and series info. Accessed via /calendar/event?slug=X
 *
 * Assigned assets (#409) — Asset Tracker items assigned to this event via
 * AssetRegister::listAssetsForEvent(). Gated on Auth::check() (unlike the
 * Documents/Materials sections above it) — this reveals what equipment
 * will be on-site and when, which a public/anonymous visitor should never
 * see. The Unassign button posts to _apps/assets/event-assign.php, which
 * re-derives its own admin/asset_manager/isResponsibleFor() gate
 * independently server-side.
 *
 * Draft events (#503) — a draft is shown only to people who can manage events
 * (App::isAdmin(), the calendar/manage/ check), with a "Draft - not visible to
 * the public" notice. Everybody else gets the same "not found" as an address
 * that matches no event. See the visibility rule after the event is loaded.
 *
 * @package   Portal\Calendar
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/409
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AssetRegister;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;

require_once PORTAL_CORE . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'location-display.php';
require_once PORTAL_CORE . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'location-map-assets.php';

// 🛡️ Ensure session for nav state
Auth::ensureSession();

// 🔍 Get event by slug
$slug = trim($_GET['slug'] ?? '');
if ($slug === '') {
    Router::renderError(404);
    return;
}

// 🌐 Multi-site scope
$siteId = Site::id();

// 📋 Fetch event
$event = null;
$stmt = $mysqli->prepare(
    'SELECT e.*, c.categoryName, t.typeName, s.seriesName, s.seriesSlug '
    . 'FROM tblEvents e '
    . 'LEFT JOIN tblEventCategories c ON c.categoryID = e.categoryID '
    . 'LEFT JOIN tblEventTypes t ON t.typeID = e.typeID '
    . 'LEFT JOIN tblEventSeries s ON s.seriesID = e.seriesID '
    . 'WHERE e.eventSlug = ? AND e.isDeleted = 0 AND e.siteID = ? LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('si', $slug, $siteId);
    $stmt->execute();
    $event = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($event === null) {
    Router::renderError(404);
    return;
}

// 🛡️ Who may see this event (#503)
//
// 1. Only an event whose status is published, cancelled or postponed is shown
//    to people in general. Those are the same three statuses the search-engine
//    block further down already required. Cancelled and postponed stay visible
//    on purpose: somebody who already has the link needs to learn it is off.
//
// 2. A DRAFT is shown only to people who can manage events. "Can manage
//    events" is App::isAdmin(): EXACTLY the check every page under
//    calendar/manage/ makes before it lets anybody create, edit or delete an
//    event (calendar/manage/index.php, save.php, delete.php and the rest). It
//    is true for a global administrator and for an administrator of the
//    organisation (site) being viewed. It is deliberately not a new rule, so if
//    who may manage events ever changes, this page follows automatically.
//
// 3. Everybody else asking for a draft gets EXACTLY the same "not found" as an
//    address that matches no event at all: the same call, made before anything
//    has been sent. Saying "you may not see this" would confirm that the event
//    exists, and addresses are made from event names, so they are easy to guess.
//
// The draft check runs BEFORE the sign-in request for non-public events below.
// The other way round, a signed-out visitor asking for a non-public draft would
// be sent to the sign-in page while a made-up address says "not found", and the
// difference would give the draft away. The cost is that an administrator who
// is not signed in also gets "not found" for a draft until they sign in.
//
// What was wrong before: the event was loaded with no condition on status, and
// the only check asked for sign-in when the event was NOT public. New events
// start as drafts and are public by default, so most drafts could be read in
// full (description, dates, location) by anybody with the address, signed in
// or not, even though the calendar listings hide them.
//
// Why the page checks this itself: the router does not yet ask anybody to sign
// in for any page (#497), so nothing before this point protects it.
//
// ⚠️ This protects THIS page only. calendar/export.php applies the same rule to
//    its single-event download; other pages that show an event make their own
//    checks.
$publicEventStatuses = ['published', 'cancelled', 'postponed'];
$isVisibleStatus     = in_array((string) ($event['status'] ?? ''), $publicEventStatuses, true) === true;

if ($isVisibleStatus === false && App::isAdmin() === false) {
    Router::renderError(404);
    return;
}

// 🛡️ Non-public events require sign-in (unchanged). A signed-in member may
//    read a published non-public event; only drafts are held back from them.
if (($event['isPublic'] === '0' || (int) $event['isPublic'] === 0) && Auth::check() === false) {
    Auth::requireLogin();
}

// 🗺️ #456 Chunk A — page-scoped CSP widening for OSM tiles, ONLY when the
// event has coordinates (conditional, #386 precedent). Must be set BEFORE
// header.php is required below.
if ($event['locationGeoLat'] !== null && $event['locationGeoLng'] !== null) {
    $cspImgExtra = 'https://*.tile.openstreetmap.org';
}

// 🔗 Links to other portal pages go through Site::url() (#503 round 2)
//
// A portal can tell its organisations (sites) apart by the start of the
// address ("path mode"): /cambridge/calendar/event is Cambridge's event page.
// A link that leaves that part out, such as /calendar/event, is read as the
// FIRST organisation's page. Event slugs are only unique within one
// organisation, so such a link can open a different organisation's event
// with the same slug, or "not found". Site::url() adds the organisation's
// part in path mode and returns the plain address in every other mode, so
// nothing changes for a portal that does not use path mode.
//
// What was wrong before: every portal link on this page was written as a
// bare address, including the address given to search engines.
//
// Deliberately NOT given the prefix: /assets/uploads/... (hero image and
// materials). The portal has no page at those addresses; they can only work
// as real files in the web root, and a real file's address has no
// organisation part. (Seen while checking this, not fixed here: uploads are
// saved to _uploads/calendar, OUTSIDE the web root, and nothing in this
// repository serves /assets/uploads, so these links depend on something set
// up on the server by hand. The prefix would not change that.)
// ⚠️ This covers the links THIS page writes. The shared header and footer,
//    and the pages these links lead to (for example where calendar/rsvp
//    sends you back afterwards), build their own addresses.
$pageTitle   = $event['eventName'];
$pageSection = 'calendar';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Calendar' => Site::url('calendar'), $event['eventName'] => ''];

// 📋 Fetch event people
$people = [];
$stmt = $mysqli->prepare(
    'SELECT ep.*, u.fullName FROM tblEventPeople ep '
    . 'LEFT JOIN tblUsers u ON u.userID = ep.userID '
    . 'WHERE ep.eventID = ? ORDER BY ep.sortOrder, ep.role'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $event['eventID']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $people[] = $r;
    }
    $stmt->close();
}

// 📋 Fetch event links
$links = [];
$stmt = $mysqli->prepare('SELECT * FROM tblEventLinks WHERE eventID = ? ORDER BY sortOrder');
if ($stmt !== false) {
    $stmt->bind_param('i', $event['eventID']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $links[] = $r;
    }
    $stmt->close();
}

// 📋 Fetch event materials
$materials = [];
$stmt = $mysqli->prepare('SELECT * FROM tblEventMaterials WHERE eventID = ? ORDER BY sortOrder');
if ($stmt !== false) {
    $stmt->bind_param('i', $event['eventID']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $materials[] = $r;
    }
    $stmt->close();
}

// 📚 Fetch event-scoped documents from the document library (#351).
//     Distinct from tblEventMaterials (the simple per-event attachment
//     table above) — these are tblDocuments rows whose eventID has been
//     set to this event. Honours isPublished + isDeleted gates so drafts
//     never leak to the public event page.
$eventDocs = [];
$stmt = $mysqli->prepare(
    'SELECT d.documentID, d.title, d.description, d.fileName, d.fileSize, d.mimeType, '
    . '       d.downloadCount, d.createdAt, c.categoryName '
    . 'FROM tblDocuments d '
    . 'LEFT JOIN tblDocCategories c ON c.categoryID = d.categoryID '
    . 'WHERE d.eventID = ? AND d.siteID = ? AND d.isPublished = 1 AND d.isDeleted = 0 '
    . 'ORDER BY c.sortOrder, d.title ASC'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $event['eventID'], $siteId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $eventDocs[] = $r;
    }
    $stmt->close();
}

// 📦 Assigned assets (#409) — physical/digital assets assigned to this
// event via AssetRegister::listAssetsForEvent() (Asset Tracker, #393).
// Gated on Auth::check() — UNLIKE the Documents/Materials sections above,
// this reveals WHAT EQUIPMENT will be on-site and WHEN, which is not safe
// to expose to an anonymous public visitor on a public event page (see
// this file's own render gate further down). Always queried when logged
// in, regardless of whether the Asset Tracker app itself is enabled —
// mirrors this file's existing Documents/Materials sections, which don't
// gate on their own app's enabled flag either; an uninstalled/empty Asset
// Tracker simply yields zero rows either way.
$assignedAssets = [];
if (Auth::check() === true) {
    $assignedAssets = AssetRegister::listAssetsForEvent((int) $event['eventID'], $siteId);
}
// 🛡️ Unassign-button gate — deliberately the general manager gate (not a
// per-asset isResponsibleFor() check, which would mean an extra query per
// row on a page that's mostly about the EVENT, not the assets) — hiding
// the button for a merely-responsible-but-non-manager viewer here is a UI
// conservatism only; event-assign.php still independently re-derives and
// re-checks the WIDER admin/asset_manager/isResponsibleFor() gate itself.
$canManageAssets = App::isAdmin() === true || App::hasRole('asset_manager') === true;

// 📋 Fetch event themes
$themes = [];
$stmt = $mysqli->prepare(
    'SELECT th.themeName, th.color FROM tblEventThemeMap tm '
    . 'JOIN tblEventThemes th ON th.themeID = tm.themeID AND th.siteID = ? '
    . 'WHERE tm.eventID = ?'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $siteId, $event['eventID']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $themes[] = $r;
    }
    $stmt->close();
}

$startDt = new DateTime($event['startDateTime']);
$endDt   = $event['endDateTime'] !== null ? new DateTime($event['endDateTime']) : null;

// 📋 Fetch RSVP data (logged-in users only)
$userRsvp   = null;
$rsvpCounts = ['going' => 0, 'maybe' => 0, 'not_going' => 0];
$rsvpEnabled = ($event['status'] !== 'cancelled');

if (Auth::check() === true) {
    // 🔍 Current user's RSVP
    $rStmt = $mysqli->prepare(
        'SELECT response FROM tblEventRSVPs WHERE eventID = ? AND userID = ? LIMIT 1'
    );
    if ($rStmt !== false) {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $rStmt->bind_param('ii', $event['eventID'], $userId);
        $rStmt->execute();
        $rRow = $rStmt->get_result()->fetch_assoc();
        if ($rRow !== null) {
            $userRsvp = $rRow['response'];
        }
        $rStmt->close();
    }

    // 📊 RSVP counts by response type
    $cStmt = $mysqli->prepare(
        'SELECT response, COUNT(*) AS cnt FROM tblEventRSVPs WHERE eventID = ? GROUP BY response'
    );
    if ($cStmt !== false) {
        $cStmt->bind_param('i', $event['eventID']);
        $cStmt->execute();
        $cResult = $cStmt->get_result();
        while ($cRow = $cResult->fetch_assoc()) {
            if (isset($rsvpCounts[$cRow['response']]) === true) {
                $rsvpCounts[$cRow['response']] = (int) $cRow['cnt'];
            }
        }
        $cStmt->close();
    }
}

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

// 🌐 Schema.org JSON-LD Event markup (#328) — SEO + rich-snippet eligibility.
//     Conditional on isPublic + status to avoid leaking unpublished/draft events
//     into search index. Only emitted on public events.
//     ⚠️ WHAT THIS CANNOT DO: it only holds back the search-engine markup.
//     It does not hide the page. Hiding a draft page is the job of the
//     visibility rule near the top (#503), which uses the same three statuses
//     ($isVisibleStatus). The status test is still needed here: somebody who
//     can manage events does reach this point for a draft, and the markup must
//     never describe a draft to a search engine.
//     (Until #503 this comment recorded that a public draft was shown in full
//     to anybody with its address. That is no longer true.)
// 🔢 The isPublic test used to be `($event['isPublic'] ?? '0') === '1'`. The event
//    is read through a prepared statement, which hands this flag back as the whole
//    number 1, never the text '1', so the markup was never output for any event.
//    It now accepts exactly 1 or '1' (a cast would also accept true, '01' and 1.5).
$eventPublicFlag = $event['isPublic'] ?? null;
if (($eventPublicFlag === 1 || $eventPublicFlag === '1') && $isVisibleStatus === true):
    $eventStatusSchema = [
        'published' => 'https://schema.org/EventScheduled',
        'cancelled' => 'https://schema.org/EventCancelled',
        'postponed' => 'https://schema.org/EventPostponed',
    ];
    $tz = (string) ($event['timezone'] ?? 'Europe/London');
    try {
        $dtStart = new \DateTimeImmutable((string) $event['startDateTime'], new \DateTimeZone($tz));
        $dtEnd   = !empty($event['endDateTime']) ? new \DateTimeImmutable((string) $event['endDateTime'], new \DateTimeZone($tz)) : null;
    } catch (\Exception $e) { $dtStart = null; $dtEnd = null; }

    // 🔗 The address search engines are told this event lives at. It uses
    //    Site::url() so a path-mode organisation's markup names its OWN page
    //    (/cambridge/calendar/event?slug=...). It used to be a bare
    //    '/calendar/event', which pointed search engines at the first
    //    organisation's event with the same slug, or at "not found".
    //    The hero image address below is a file, so it has no organisation part.
    $eventUrl = (isset($_SERVER['HTTPS']) === true && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://')
              . (string) ($_SERVER['HTTP_HOST'] ?? '') . Site::url('calendar/event') . '?slug='
              . rawurlencode((string) $event['eventSlug']);
    $heroAbsUrl = !empty($event['heroImage'])
        ? (isset($_SERVER['HTTPS']) === true && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://')
          . (string) ($_SERVER['HTTP_HOST'] ?? '') . '/assets/uploads/calendar/' . (string) $event['heroImage']
        : null;

    $jsonLd = [
        '@context'      => 'https://schema.org',
        '@type'         => 'Event',
        'name'          => (string) $event['eventName'],
        'description'   => (string) ($event['description'] ?? ''),
        'eventStatus'   => $eventStatusSchema[$event['status']] ?? 'https://schema.org/EventScheduled',
        'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
        'organizer'     => [ '@type' => 'Organization', 'name' => Site::productName() ],
        'url'           => $eventUrl,
    ];
    if ($dtStart instanceof \DateTimeImmutable) {
        $jsonLd['startDate'] = $dtStart->format('c');
    }
    if ($dtEnd instanceof \DateTimeImmutable) {
        $jsonLd['endDate'] = $dtEnd->format('c');
    }
    if (!empty($event['locationName']) || !empty($event['locationAddress'])) {
        $jsonLd['location'] = [
            '@type' => 'Place',
            'name'    => (string) ($event['locationName']    ?? ''),
            'address' => (string) ($event['locationAddress'] ?? ''),
        ];
        // 📍 #456 Chunk A — geo sub-object when coordinates are present.
        if ($event['locationGeoLat'] !== null && $event['locationGeoLng'] !== null) {
            $jsonLd['location']['geo'] = [
                '@type'     => 'GeoCoordinates',
                'latitude'  => (float) $event['locationGeoLat'],
                'longitude' => (float) $event['locationGeoLng'],
            ];
        }
    }
    if ($heroAbsUrl !== null) {
        $jsonLd['image'] = [$heroAbsUrl];
    }
    // 🛡️ The JSON_HEX_* flags are what stop an event's own text breaking out
    //    of this <script> tag. A browser ends a script element at the first
    //    "</script>" it sees, whatever the JSON around it says. Event name,
    //    description, location, timezone and hero image are stored exactly as
    //    an admin or an API key typed them, and this page is open to visitors
    //    who are not signed in. JSON_UNESCAPED_SLASHES on its own (the
    //    original flags) printed "</script><img onerror=...>" byte for byte.
    //    With JSON_HEX_TAG, < and > come out as \u003C and \u003E (the JSON
    //    escape for those two characters), so the text can never contain a
    //    closing script tag. JSON_HEX_AMP, JSON_HEX_APOS and JSON_HEX_QUOT
    //    do the same for & ' and " (\u0026, \u0027 and \u0022). A reader
    //    of the JSON gets the original characters back. The result is still
    //    valid JSON that search engines read normally. This only protects
    //    THIS block: every other place on the page must keep using
    //    htmlspecialchars().
    //    This mattered from the moment the isPublic test above was fixed,
    //    because until then this block never ran.
    echo "\n<script type=\"application/ld+json\">"
        . json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        . "</script>\n";
endif;
?>

<!-- 📅 Event Detail -->
<article class="mb-5">

    <?php
    // 📝 Draft notice (#503). The visibility rule near the top sends everybody
    //    who cannot manage events away with "not found" for a draft, so only an
    //    event manager ever sees this. It is there so they do not mistake what
    //    they are looking at for something the congregation can already read.
    ?>
    <?php if ($isVisibleStatus === false): ?>
        <div class="alert alert-info mb-4" role="status">
            <h2 class="h5 mb-1"><i class="fa-solid fa-eye-slash me-2"></i>Draft - not visible to the public</h2>
            <p class="mb-0 small">Only people who can manage events can open this page. Everybody else is told the event does not exist until it is published.</p>
        </div>
    <?php endif; ?>

    <?php // 🚫 Cancellation / postponement broadcast banner (#337) ?>
    <?php if ($event['status'] === 'cancelled'): ?>
        <div class="alert alert-danger mb-4">
            <h2 class="h5"><i class="fa-solid fa-ban me-2"></i>This event has been cancelled</h2>
            <?php if (!empty($event['cancelReason'])): ?>
                <p class="mb-1"><?php echo nl2br(htmlspecialchars((string) $event['cancelReason'], ENT_QUOTES, 'UTF-8')); ?></p>
            <?php endif; ?>
            <?php if (!empty($event['statusChangedAt'])): ?>
                <p class="small text-muted mb-0">Updated <?php echo htmlspecialchars(date('j M Y, H:i', strtotime((string) $event['statusChangedAt'])), ENT_QUOTES, 'UTF-8'); ?>.</p>
            <?php endif; ?>
        </div>
    <?php elseif ($event['status'] === 'postponed'): ?>
        <div class="alert alert-warning mb-4">
            <h2 class="h5"><i class="fa-solid fa-pause me-2"></i>This event has been postponed</h2>
            <?php if (!empty($event['cancelReason'])): ?>
                <p class="mb-1"><?php echo nl2br(htmlspecialchars((string) $event['cancelReason'], ENT_QUOTES, 'UTF-8')); ?></p>
            <?php endif; ?>
            <?php if (!empty($event['statusChangedAt'])): ?>
                <p class="small text-muted mb-0">Updated <?php echo htmlspecialchars(date('j M Y, H:i', strtotime((string) $event['statusChangedAt'])), ENT_QUOTES, 'UTF-8'); ?>. Check back for the new date.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- 🖼️ Hero image -->
    <?php if ($event['heroImage'] !== null && $event['heroImage'] !== ''): ?>
        <div class="mb-4">
            <img src="/assets/uploads/calendar/<?php echo htmlspecialchars($event['heroImage'], ENT_QUOTES, 'UTF-8'); ?>"
                 alt="<?php echo htmlspecialchars($event['eventName'], ENT_QUOTES, 'UTF-8'); ?>"
                 class="img-fluid rounded w-100" style="max-height:400px;object-fit:cover;">
        </div>
    <?php endif; ?>

    <!-- 📝 Header -->
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h1 class="mb-2"><?php echo htmlspecialchars($event['eventName'], ENT_QUOTES, 'UTF-8'); ?></h1>
            <!-- 🏷️ Badges -->
            <div class="d-flex flex-wrap gap-1 mb-2">
                <?php if ($event['status'] === 'cancelled'): ?>
                    <span class="badge bg-danger">Cancelled</span>
                <?php elseif ($event['status'] === 'postponed'): ?>
                    <span class="badge bg-warning text-dark">Postponed</span>
                <?php endif; ?>
                <?php
                // 🔢 Was `$event['isFeatured'] === '1'`, always false because the
                //    prepared statement returns the number 1, not the text '1', so
                //    the Featured badge never showed. Accepts exactly 1 or '1'.
                if ($event['isFeatured'] === 1 || $event['isFeatured'] === '1'): ?>
                    <span class="badge bg-warning text-dark"><i class="fa-solid fa-star me-1"></i>Featured</span>
                <?php endif; ?>
                <?php if ($event['categoryName'] !== null): ?>
                    <span class="badge bg-primary"><?php echo htmlspecialchars($event['categoryName'], ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
                <?php if ($event['typeName'] !== null): ?>
                    <span class="badge bg-secondary"><?php echo htmlspecialchars($event['typeName'], ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
                <?php foreach ($themes as $theme): ?>
                    <span class="badge" style="background-color:<?php echo htmlspecialchars($theme['color'] ?? '#6c757d', ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($theme['themeName'], ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="d-flex gap-2">
            <?php if (Auth::check() === true && Auth::isEventTeamMember((int) $event['eventID']) === true): ?>
                <a href="<?php echo htmlspecialchars(Site::url('calendar/event/hub'), ENT_QUOTES, 'UTF-8'); ?>?eventID=<?php echo (int) $event['eventID']; ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="fa-solid fa-people-roof me-1"></i>Team Hub
                </a>
            <?php endif; ?>
            <?php if (App::isAdmin() === true): ?>
                <a href="<?php echo htmlspecialchars(Site::url('calendar/manage'), ENT_QUOTES, 'UTF-8'); ?>?edit=<?php echo (int) $event['eventID']; ?>" class="btn btn-outline-primary btn-sm">
                    <i class="fa-solid fa-pen me-1"></i>Edit
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4">
        <!-- 📋 Main content -->
        <div class="col-12 col-lg-8">
            <!-- 📝 Description -->
            <?php if ($event['description'] !== null && $event['description'] !== ''): ?>
                <div class="mb-4">
                    <p><?php echo nl2br(htmlspecialchars($event['description'], ENT_QUOTES, 'UTF-8')); ?></p>
                </div>
            <?php endif; ?>

            <!-- 👤 People -->
            <?php if (count($people) > 0): ?>
                <div class="card mb-4">
                    <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-users me-2"></i>People</h5></div>
                    <div class="card-body">
                        <div class="portal-data-list">
                            <?php foreach ($people as $person): ?>
                                <div class="portal-data-row">
                                    <div class="portal-data-cell col-12 col-md-6" data-label="Name">
                                        <strong>
                                            <?php echo htmlspecialchars(
                                                $person['fullName'] ?? $person['externalName'] ?? 'Unknown',
                                                ENT_QUOTES, 'UTF-8'
                                            ); ?>
                                        </strong>
                                        <?php
                                        // 🔢 Was `$person['isPrimary'] === '1'`, always false because
                                        //    the prepared statement returns the number 1, not the text
                                        //    '1', so the Primary marker never showed. Accepts exactly
                                        //    1 or '1'.
                                        if ($person['isPrimary'] === 1 || $person['isPrimary'] === '1'): ?>
                                            <span class="badge bg-warning text-dark ms-1">Primary</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="portal-data-cell col-12 col-md-6 text-md-end" data-label="Role">
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars(ucfirst($person['role']), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 📎 Materials -->
            <?php if (count($materials) > 0): ?>
                <div class="card mb-4">
                    <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-file-arrow-down me-2"></i>Materials</h5></div>
                    <div class="card-body">
                        <?php foreach ($materials as $mat): ?>
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <i class="fa-solid fa-file text-muted"></i>
                                <a href="/assets/uploads/calendar/materials/<?php echo htmlspecialchars($mat['filePath'], ENT_QUOTES, 'UTF-8'); ?>"
                                   target="_blank" class="text-decoration-none">
                                    <?php echo htmlspecialchars($mat['fileName'], ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                                <?php if ($mat['fileSize'] !== null): ?>
                                    <small class="text-muted">(<?php echo round((int) $mat['fileSize'] / 1024); ?> KB)</small>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 📚 Documents (per-event library link, #351) -->
            <?php if (count($eventDocs) > 0): ?>
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fa-solid fa-folder-open me-2"></i>Documents</h5>
                        <a href="<?php echo htmlspecialchars(Site::url('documents'), ENT_QUOTES, 'UTF-8'); ?>?event=<?php echo (int) $event['eventID']; ?>" class="small text-decoration-none">View in library &rarr;</a>
                    </div>
                    <div class="card-body">
                        <?php foreach ($eventDocs as $doc): ?>
                            <div class="d-flex align-items-start gap-2 mb-3">
                                <i class="fa-solid fa-file-lines text-primary mt-1"></i>
                                <div class="flex-grow-1">
                                    <a href="<?php echo htmlspecialchars(Site::url('documents/download'), ENT_QUOTES, 'UTF-8'); ?>?id=<?php echo (int) $doc['documentID']; ?>"
                                       class="text-decoration-none fw-semibold">
                                        <?php echo htmlspecialchars((string) $doc['title'], ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                    <?php if (!empty($doc['categoryName'])): ?>
                                        <span class="badge bg-light text-dark border ms-1"><?php echo htmlspecialchars((string) $doc['categoryName'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($doc['description'])): ?>
                                        <div class="small text-muted mt-1"><?php echo htmlspecialchars(mb_substr((string) $doc['description'], 0, 200), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endif; ?>
                                    <div class="small text-muted">
                                        <?php if ($doc['fileSize'] !== null): ?>
                                            <i class="fa-solid fa-database me-1"></i><?php echo round((int) $doc['fileSize'] / 1024); ?> KB
                                            &middot;
                                        <?php endif; ?>
                                        <i class="fa-solid fa-download me-1"></i><?php echo (int) $doc['downloadCount']; ?> downloads
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 📦 Assigned assets (#409) — gated on Auth::check() above;
                 see that comment for why this section (unlike Documents/
                 Materials) is never shown to an anonymous public visitor. -->
            <?php if (Auth::check() === true && count($assignedAssets) > 0): ?>
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fa-solid fa-box me-2"></i>Assigned assets</h5>
                        <a href="<?php echo htmlspecialchars(Site::url('assets'), ENT_QUOTES, 'UTF-8'); ?>?eventID=<?php echo (int) $event['eventID']; ?>" class="small text-decoration-none">Assign an asset &rarr;</a>
                    </div>
                    <div class="card-body">
                        <div class="portal-data-list">
                            <?php foreach ($assignedAssets as $aa): ?>
                                <div class="portal-data-row align-items-center">
                                    <div class="portal-data-cell col-12 col-md-6" data-label="Asset">
                                        <a href="<?php echo htmlspecialchars(Site::url('assets/item'), ENT_QUOTES, 'UTF-8'); ?>?id=<?php echo (int) $aa['assetID']; ?>" class="text-decoration-none">
                                            <?php echo htmlspecialchars((string) $aa['assetName'], ENT_QUOTES, 'UTF-8'); ?>
                                        </a>
                                        <?php if (!empty($aa['assetTagCode'])): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars((string) $aa['assetTagCode'], ENT_QUOTES, 'UTF-8'); ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="portal-data-cell col-12 col-md-4 small text-muted" data-label="Status">
                                        <?php echo htmlspecialchars(ucwords(str_replace('-', ' ', (string) $aa['assetStatus'])), ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                    <?php if ($canManageAssets === true): ?>
                                        <div class="portal-data-cell col-12 col-md-2 text-md-end" data-label="">
                                            <form method="post" action="<?php echo htmlspecialchars(Site::url('assets/event-assign'), ENT_QUOTES, 'UTF-8'); ?>" class="d-inline"
                                                  data-confirm="Remove this asset's assignment to this event?" data-confirm-destructive="true">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="unassign">
                                                <input type="hidden" name="assetID" value="<?php echo (int) $aa['assetID']; ?>">
                                                <input type="hidden" name="assignmentID" value="<?php echo (int) $aa['assignmentID']; ?>">
                                                <?php
                                                // 🔙 Where event-assign sends the browser back to. Built with
                                                //    Site::url() like every other link on this page.
                                                //    ⚠️ In path mode this does NOT yet bring you back here:
                                                //    assets/event-assign.php only accepts a return address that
                                                //    starts with /assets or /calendar, so it refuses
                                                //    /cambridge/calendar/... and sends you to its default,
                                                //    /assets/item?id=N, which then says "not found". That check
                                                //    needs to accept the organisation's part; it is in that
                                                //    file, not here. Tested on 14 September 2026.
                                                //    Rejected: leaving this address bare. That also went wrong
                                                //    in path mode, and quietly: it showed the FIRST
                                                //    organisation's event with the same slug. A "not found" is
                                                //    at least noticed. In every other mode the value is the
                                                //    same /calendar/event?slug=... as before.
                                                ?>
                                                <input type="hidden" name="returnTo" value="<?php echo htmlspecialchars(Site::url('calendar/event'), ENT_QUOTES, 'UTF-8'); ?>?slug=<?php echo htmlspecialchars((string) $event['eventSlug'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Unassign">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- 📌 Sidebar -->
        <div class="col-12 col-lg-4">
            <!-- 📅 Date & Time card -->
            <div class="card mb-4">
                <div class="card-header"><h5 class="mb-0"><i class="fa-regular fa-clock me-2"></i>When</h5></div>
                <div class="card-body">
                    <p class="mb-1">
                        <i class="fa-regular fa-calendar me-1 text-primary"></i>
                        <strong><?php echo htmlspecialchars(\Portal\Core\I18n::formatDate($startDt->format('Y-m-d H:i:s'), 'long'), ENT_QUOTES, 'UTF-8'); ?></strong>
                    </p>
                    <?php if ($event['isAllDay'] !== '1' && (int) $event['isAllDay'] !== 1): ?>
                        <p class="mb-1">
                            <i class="fa-regular fa-clock me-1 text-primary"></i>
                            <?php echo htmlspecialchars($startDt->format('g:i A'), ENT_QUOTES, 'UTF-8'); ?>
                            <?php if ($endDt !== null): ?>
                                &ndash; <?php echo htmlspecialchars($endDt->format('g:i A'), ENT_QUOTES, 'UTF-8'); ?>
                            <?php endif; ?>
                        </p>
                    <?php else: ?>
                        <p class="mb-1"><span class="badge bg-light text-dark">All Day Event</span></p>
                    <?php endif; ?>
                    <p class="mb-0 small text-muted">
                        Timezone: <?php echo htmlspecialchars($event['timezone'], ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <!-- 📅 Add to calendar link -->
                    <a href="<?php echo htmlspecialchars(Site::url('calendar/export'), ENT_QUOTES, 'UTF-8'); ?>?id=<?php echo (int) $event['eventID']; ?>" class="btn btn-sm btn-outline-primary mt-2">
                        <i class="fa-solid fa-calendar-plus me-1"></i> Add to Calendar
                    </a>
                </div>
            </div>

            <!-- 📍 Location card -->
            <?php
            $hasLocationInfo = ($event['locationName'] ?? '') !== ''
                || ($event['locationAddress'] ?? '') !== ''
                || ($event['locationGeoLat'] !== null && $event['locationGeoLng'] !== null)
                || ($event['locationW3W'] ?? '') !== '';
            ?>
            <?php if ($hasLocationInfo === true): ?>
                <div class="card mb-4">
                    <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-location-dot me-2"></i>Where</h5></div>
                    <div class="card-body">
                        <?php if ($event['locationName'] !== null && $event['locationName'] !== ''): ?>
                            <p class="mb-1"><strong><?php echo htmlspecialchars($event['locationName'], ENT_QUOTES, 'UTF-8'); ?></strong></p>
                        <?php endif; ?>
                        <?php if ($event['locationAddress'] !== null && $event['locationAddress'] !== ''): ?>
                            <p class="mb-1 small"><?php echo nl2br(htmlspecialchars($event['locationAddress'], ENT_QUOTES, 'UTF-8')); ?></p>
                        <?php endif; ?>
                        <?php if ($event['locationWebURL'] !== null && $event['locationWebURL'] !== ''): ?>
                            <p class="mb-1 small">
                                <i class="fa-solid fa-globe me-1"></i>
                                <a href="<?php echo htmlspecialchars($event['locationWebURL'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Website</a>
                            </p>
                        <?php endif; ?>
                        <?php if ($event['locationPhone'] !== null && $event['locationPhone'] !== ''): ?>
                            <p class="mb-1 small">
                                <i class="fa-solid fa-phone me-1"></i>
                                <?php echo htmlspecialchars($event['locationPhone'], ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                        <?php endif; ?>
                        <?php portal_location_display([
                            'lat'     => $event['locationGeoLat'] !== null ? (float) $event['locationGeoLat'] : null,
                            'lng'     => $event['locationGeoLng'] !== null ? (float) $event['locationGeoLng'] : null,
                            'w3w'     => $event['locationW3W'] ?? null,
                            'showMap' => true,
                            'mapId'   => 'eventMap',
                        ]); ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 🏢 Organisation -->
            <?php if ($event['hostOrgName'] !== null && $event['hostOrgName'] !== ''): ?>
                <div class="card mb-4">
                    <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-building me-2"></i>Hosted By</h5></div>
                    <div class="card-body">
                        <p class="mb-0"><strong><?php echo htmlspecialchars($event['hostOrgName'], ENT_QUOTES, 'UTF-8'); ?></strong></p>
                        <?php
                        $partners = json_decode($event['partnerOrgs'] ?? '[]', true);
                        if (is_array($partners) === true && count($partners) > 0):
                        ?>
                            <p class="mt-2 mb-0 small text-muted">Partners:
                                <?php echo htmlspecialchars(implode(', ', $partners), ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 🔗 Links -->
            <?php if (count($links) > 0): ?>
                <div class="card mb-4">
                    <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-link me-2"></i>Links</h5></div>
                    <div class="list-group list-group-flush">
                        <?php foreach ($links as $link): ?>
                            <a href="<?php echo htmlspecialchars($link['linkURL'], ENT_QUOTES, 'UTF-8'); ?>"
                               class="list-group-item list-group-item-action" target="_blank" rel="noopener">
                                <i class="fa-solid fa-arrow-up-right-from-square me-1 text-muted"></i>
                                <?php echo htmlspecialchars($link['linkLabel'] ?? $link['linkURL'], ENT_QUOTES, 'UTF-8'); ?>
                                <span class="badge bg-light text-dark float-end"><?php echo htmlspecialchars($link['linkType'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 🔄 Series info -->
            <?php if ($event['seriesName'] !== null): ?>
                <div class="card mb-4">
                    <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-layer-group me-2"></i>Series</h5></div>
                    <div class="card-body">
                        <p class="mb-0">Part of: <strong><?php echo htmlspecialchars($event['seriesName'], ENT_QUOTES, 'UTF-8'); ?></strong></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 🎟️ RSVP -->
            <?php if (Auth::check() === true && $rsvpEnabled === true): ?>
                <div class="card mb-4">
                    <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-user-check me-2"></i>RSVP</h5></div>
                    <div class="card-body">
                        <?php
                        $totalGoing = $rsvpCounts['going'] + $rsvpCounts['maybe'];
                        $capacity   = $event['capacity'] !== null ? (int) $event['capacity'] : null;
                        $atCapacity = ($capacity !== null && $rsvpCounts['going'] >= $capacity);
                        ?>
                        <!-- 📊 Counts -->
                        <div class="d-flex gap-3 mb-3 text-center">
                            <div>
                                <div class="fw-bold text-success"><?php echo $rsvpCounts['going']; ?></div>
                                <small class="text-muted">Going</small>
                            </div>
                            <div>
                                <div class="fw-bold text-warning"><?php echo $rsvpCounts['maybe']; ?></div>
                                <small class="text-muted">Maybe</small>
                            </div>
                            <div>
                                <div class="fw-bold text-secondary"><?php echo $rsvpCounts['not_going']; ?></div>
                                <small class="text-muted">Not Going</small>
                            </div>
                        </div>

                        <?php if ($capacity !== null): ?>
                            <p class="small text-muted mb-3">
                                <i class="fa-solid fa-users me-1"></i>Capacity: <?php echo $rsvpCounts['going']; ?>/<?php echo $capacity; ?>
                                <?php if ($atCapacity === true && $userRsvp !== 'going'): ?>
                                    <span class="badge bg-danger ms-1">Full</span>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>

                        <!-- 🎯 Current status -->
                        <?php if ($userRsvp !== null): ?>
                            <?php
                            $statusLabels = ['going' => 'Going', 'maybe' => 'Maybe', 'not_going' => 'Not Going'];
                            $statusColors = ['going' => 'success', 'maybe' => 'warning', 'not_going' => 'secondary'];
                            ?>
                            <p class="mb-3">
                                Your RSVP: <span class="badge bg-<?php echo $statusColors[$userRsvp] ?? 'secondary'; ?>">
                                    <?php echo htmlspecialchars($statusLabels[$userRsvp] ?? $userRsvp, ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </p>
                        <?php endif; ?>

                        <!-- 🔘 RSVP buttons -->
                        <form method="post" action="<?php echo htmlspecialchars(Site::url('calendar/rsvp'), ENT_QUOTES, 'UTF-8'); ?>" class="d-flex flex-wrap gap-2">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="eventID" value="<?php echo (int) $event['eventID']; ?>">
                            <input type="hidden" name="slug" value="<?php echo htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'); ?>">

                            <?php if ($userRsvp !== 'going'): ?>
                                <button type="submit" name="response" value="going"
                                        class="btn btn-sm btn-outline-success"
                                        <?php echo ($atCapacity === true ? 'disabled title="Event is at full capacity"' : ''); ?>>
                                    <i class="fa-solid fa-check me-1"></i>Going
                                </button>
                            <?php endif; ?>

                            <?php if ($userRsvp !== 'maybe'): ?>
                                <button type="submit" name="response" value="maybe" class="btn btn-sm btn-outline-warning">
                                    <i class="fa-solid fa-question me-1"></i>Maybe
                                </button>
                            <?php endif; ?>

                            <?php if ($userRsvp !== 'not_going'): ?>
                                <button type="submit" name="response" value="not_going" class="btn btn-sm btn-outline-secondary">
                                    <i class="fa-solid fa-xmark me-1"></i>Not Going
                                </button>
                            <?php endif; ?>

                            <?php if ($userRsvp !== null): ?>
                                <button type="submit" name="response" value="cancel" class="btn btn-sm btn-outline-danger">
                                    <i class="fa-solid fa-trash me-1"></i>Cancel
                                </button>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</article>

<?php
portal_location_map_assets(App::cspNonce());

// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
