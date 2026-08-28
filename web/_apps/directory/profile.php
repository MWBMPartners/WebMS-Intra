<?php
// Path: public_html/directory/profile.php
/**
 * Member Directory — single profile, respecting per-field visibility.
 *
 * @package   Portal\Directory
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/261
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Markdown;
use Portal\Core\Site;

// 📍 #456 Chunk B — coords display (gated + coarsened, see the `$can`
// checks below). Only the display partial is needed here — this page
// never captures input.
require_once PORTAL_CORE . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'location-display.php';

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$siteId = Site::id();
$viewer = (int) ($_SESSION['user_id'] ?? 0);
$isAdmin = App::isAdmin();
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: /directory');
    exit();
}

$u = null;
$stmt = $db->prepare(
    'SELECT u.userID, u.fullName, u.emailAddress AS email, u.displayBio, u.displayPhone, u.displayAddress, u.displayPhoto, '
    . '       u.latitude, u.longitude, u.what3words, u.visibilityCoords, '
    . '       u.visibilityName, u.visibilityRoles, u.visibilityEmail, u.visibilityPhone, u.visibilityAddress, '
    . '       u.visibilityBio, u.visibilityPhoto '
    . 'FROM tblUsers u '
    . 'INNER JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
    . 'WHERE u.userID = ? LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $siteId, $id);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($u === null) {
    http_response_code(404);
    exit('Not found');
}

// ⚠️ Pre-existing quirk, inherited verbatim (#456 Chunk B does NOT fix
// this): 'team' behaves exactly like 'private' for any non-owner/
// non-admin viewer — only 'members'/'public' pass below. visibilityCoords
// (added this chunk) is gated through this SAME $can() closure, so it
// inherits the identical team-as-private behaviour by construction.
$can = static function (string $level) use ($viewer, $id, $isAdmin): bool {
    if ($viewer === $id || $isAdmin === true) {
        return true;
    }
    return $level === 'members' || $level === 'public';
};

if ($can($u['visibilityName']) === false) {
    http_response_code(403);
    exit('Profile is private.');
}

// 📍 #456 Chunk B — coords are gated STRICTER than the address text:
// a SEPARATE $can($u['visibilityCoords']) check, independent of
// visibilityAddress (a member may share their address text while still
// keeping an exact map pin private). $isOwnerOrAdmin mirrors $can()'s own
// unconditional-pass branch — used below to decide full-precision vs.
// coarsened + W3W-suppressed display, never to bypass the gate itself.
$isOwnerOrAdmin  = ($viewer === $id || $isAdmin === true);
$hasCoords       = $u['latitude'] !== null && $u['longitude'] !== null;
$showCoordsBlock = $can($u['visibilityCoords']) === true && $hasCoords === true;

// 🗺️ Page-scoped CSP widening for OSM tiles, ONLY when a map will
// actually render (#386 precedent) — must be set BEFORE header.php.
if ($showCoordsBlock === true) {
    $cspImgExtra = 'https://*.tile.openstreetmap.org';
}

$pageTitle   = $u['fullName'];
$pageSection = 'directory';
$breadcrumbs = ['Dashboard' => '/', 'Directory' => '/directory', $u['fullName'] => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-3"><?php echo htmlspecialchars((string) $u['fullName'], ENT_QUOTES, 'UTF-8'); ?></h1>

<div class="card">
    <div class="card-body">
        <?php if ($can($u['visibilityBio']) === true && trim((string) $u['displayBio']) !== ''): ?>
            <div class="portal-markdown mb-3"><?php echo Markdown::render((string) $u['displayBio'], ['allow_links' => true]); ?></div>
        <?php endif; ?>
        <dl class="row small mb-0">
            <?php if ($can($u['visibilityEmail']) === true && $u['email'] !== null): ?>
                <dt class="col-sm-3">Email</dt>
                <dd class="col-sm-9"><a href="mailto:<?php echo htmlspecialchars((string) $u['email'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $u['email'], ENT_QUOTES, 'UTF-8'); ?></a></dd>
            <?php endif; ?>
            <?php if ($can($u['visibilityPhone']) === true && $u['displayPhone'] !== null): ?>
                <dt class="col-sm-3">Phone</dt>
                <dd class="col-sm-9"><?php echo htmlspecialchars((string) $u['displayPhone'], ENT_QUOTES, 'UTF-8'); ?></dd>
            <?php endif; ?>
            <?php if ($can($u['visibilityAddress']) === true && $u['displayAddress'] !== null): ?>
                <dt class="col-sm-3">Address</dt>
                <dd class="col-sm-9"><?php echo nl2br(htmlspecialchars((string) $u['displayAddress'], ENT_QUOTES, 'UTF-8')); ?></dd>
            <?php endif; ?>
        </dl>
        <?php if ($showCoordsBlock === true): ?>
            <?php
            // 📍 #456 Chunk B — the non-owner display path: owner/admin see
            // full precision + the exact what3words; any OTHER viewer
            // permitted by the visibilityCoords tier sees the pin coarsened
            // to 3dp (~110m, GeoLocation::coarsenCoords()) with the
            // location.coords_approx badge, and NEVER the what3words value
            // (a 3m-precise W3W square cannot be meaningfully coarsened, so
            // it is suppressed entirely rather than shown next to an
            // approximate pin — see location-display.php's own coarsen
            // handling for where that suppression actually happens).
            portal_location_display([
                'lat'     => (float) $u['latitude'],
                'lng'     => (float) $u['longitude'],
                'w3w'     => $isOwnerOrAdmin === true ? $u['what3words'] : null,
                'coarsen' => $isOwnerOrAdmin === false,
                'showMap' => true,
                'mapId'   => 'profileMap',
                'visible' => true,
            ]);
            ?>
        <?php endif; ?>
        <?php if ($viewer === $id): ?>
            <a href="/directory/my-settings" class="btn btn-outline-primary btn-sm mt-2">Edit my profile</a>
        <?php endif; ?>
    </div>
</div>

<?php
if ($showCoordsBlock === true) {
    require_once PORTAL_CORE . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'location-map-assets.php';
    portal_location_map_assets(App::cspNonce());
}
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
