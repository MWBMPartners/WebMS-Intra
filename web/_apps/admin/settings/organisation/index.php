<?php
// Path: _apps/admin/settings/organisation/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Organisation / Site HQ address 🏠 (#456 Chunk A)
 * -----------------------------------------------------------------------------
 * The site-wide "where is this organisation" address — feeds public pages
 * / feeds / branding that need a physical HQ location. Stored as nine
 * global (`siteID IS NULL`) `org.*` settings keys (the `portal.sabbath.
 * location_lat/lng` precedent) rather than a `tblSites` column, so a
 * multi-site install gets free per-site overrides via the existing
 * `App::settingForSite()` pattern if ever needed later.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/456
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;

require_once PORTAL_CORE . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'location-input.php';
require_once PORTAL_CORE . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'location-display.php';
require_once PORTAL_CORE . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'location-map-assets.php';

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$values = [
    'line1'       => (string) (App::settings('org.address.line1') ?? ''),
    'line2'       => (string) (App::settings('org.address.line2') ?? ''),
    'city'        => (string) (App::settings('org.address.city') ?? ''),
    'region'      => (string) (App::settings('org.address.region') ?? ''),
    'postcode'    => (string) (App::settings('org.address.postcode') ?? ''),
    'countryCode' => (string) (App::settings('org.address.countryCode') ?? 'GB'),
    'lat'         => (string) (App::settings('org.latitude') ?? ''),
    'lng'         => (string) (App::settings('org.longitude') ?? ''),
    'w3w'         => (string) (App::settings('org.what3words') ?? ''),
];
$hasCoords = $values['lat'] !== '' && $values['lng'] !== '';

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');

// 🗺️ Preview map — only when coords are already set (opt-in page-scoped
//    CSP widening, #386 precedent).
if ($hasCoords === true) {
    $cspImgExtra = 'https://*.tile.openstreetmap.org';
}

$pageTitle   = 'Organisation Address';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Settings' => '/admin/settings', 'Organisation' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-house-chimney me-2"></i>Organisation Address</h1>
<p class="text-secondary">
    The organisation's physical HQ address — feeds public pages, feeds, and branding that need a
    location for this install.
</p>

<div class="card mb-4">
    <div class="card-body">
        <form method="post" action="/admin/settings/organisation/save">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <?php portal_location_input([
                'values'     => $values,
                'compact'    => false,
                'lookup'     => true,
                'w3wSuggest' => true,
            ]); ?>
            <div class="mt-3">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i>Save address</button>
            </div>
        </form>
    </div>
</div>

<?php if ($hasCoords === true): ?>
    <div class="card mb-4">
        <div class="card-header">Preview</div>
        <div class="card-body">
            <?php portal_location_display([
                'address' => $values,
                'lat'     => (float) $values['lat'],
                'lng'     => (float) $values['lng'],
                'w3w'     => $values['w3w'] !== '' ? $values['w3w'] : null,
                'showMap' => true,
                'mapId'   => 'orgAddressMap',
            ]); ?>
        </div>
    </div>
<?php endif; ?>

<a href="/admin/settings" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Settings</a>

<?php
portal_location_map_assets(App::cspNonce());
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
