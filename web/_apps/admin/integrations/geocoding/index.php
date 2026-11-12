<?php
// Path: _apps/admin/integrations/geocoding/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Geocoding integration settings 🌍 (#456 Chunk A)
 * -----------------------------------------------------------------------------
 * Provider chain: Google (when a key is set) -> OpenStreetMap Nominatim
 * fallback (<=1 request/second, cached, attribution required). Nominatim
 * requires a descriptive User-Agent built from the product name/version +
 * an admin contact address (`privacy.contactEmail`, falling back to
 * `mail.defaultFromAddress`).
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.5.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/456
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/943
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\InstallationSettings;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// 🏢 THE INSTALLATION'S OWN ROWS, never the merged settings (#943, #674). save.php
//    writes both of these installation-wide (`siteID IS NULL`), so the box and the
//    "configured" badge must show THAT row. WHAT WAS WRONG: they were read from the
//    merged settings, where the open organisation's own row has already won, so a
//    global administrator who opened this page inside such an organisation saw ITS
//    switch, pressed Save without touching anything, and made it every organisation's.
$hasGoogleKey = ((string) (InstallationSettings::value('geo.google.apiKey') ?? '')) !== '';
$autoGeocode  = (string) (InstallationSettings::value('geo.autoGeocode') ?? 'false') === 'true';
// 🔎 Said on the page when this organisation uses something else (names only).
$installWideDiffering = InstallationSettings::differsHere('geo');
// 📮 The contact Nominatim is told is the EFFECTIVE one for this organisation:
//    it is what the lookup really sends, and this page does not save it.
$contactEmail = (string) (App::settings('privacy.contactEmail') ?? '');
if ($contactEmail === '') {
    $contactEmail = (string) (App::settings('mail.defaultFromAddress') ?? '');
}

// 📊 Cache stats — read-only, prepared statement.
$cacheCount = 0;
$cacheLastUsed = null;
$db = App::db();
$statsStmt = $db->prepare('SELECT COUNT(*) AS c, MAX(lastUsedAt) AS lastUsed FROM tblGeocodeCache');
if ($statsStmt !== false) {
    $statsStmt->execute();
    $row = $statsStmt->get_result()->fetch_assoc();
    $statsStmt->close();
    $cacheCount = (int) ($row['c'] ?? 0);
    $cacheLastUsed = $row['lastUsed'] ?? null;
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');

$pageTitle   = 'Geocoding Integration';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Admin' => Site::url('admin'), 'Integrations' => Site::url('admin/integrations'), 'Geocoding' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-earth-europe me-2"></i>Geocoding Integration</h1>
<p class="text-secondary">
    Converts an address to coordinates (and back). When a key is set below, <strong>Google</strong> is
    asked first; if it has no answer, or its answer is not a usable map position, <strong>OpenStreetMap
    Nominatim</strong> is asked instead. With no key, only Nominatim is asked. Nominatim is asked at most once a second.
    A usable answer is kept, so the same address limited to the same country is looked up only once;
    an address that got no usable answer is asked again the next time.
</p>
<?php
// 📝 #1034 (12 November 2026): this used to say "Google when a key is set,
//    otherwise Nominatim" and "a given address is only ever geocoded once".
//    Neither was true: a failed or unusable Google answer falls through to
//    Nominatim, an answer that is not a real position is never kept (so it is
//    asked again), and since #1035 the same address is kept once per country.
?>

<div class="alert alert-info small">
    Nominatim's usage policy requires a descriptive User-Agent identifying this install and an admin
    contact. That contact currently resolves to
    <code><?php echo $contactEmail !== '' ? htmlspecialchars($contactEmail, ENT_QUOTES, 'UTF-8') : 'not set'; ?></code>
    (<a href="<?php echo htmlspecialchars(Site::url('admin/settings'), ENT_QUOTES, 'UTF-8'); ?>">privacy.contactEmail</a>, falling back to <code>mail.defaultFromAddress</code>).
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'install-wide-notice.php'; ?>

<div class="card mb-4">
    <div class="card-header"><strong>Provider</strong></div>
    <div class="card-body">
        <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/integrations/geocoding/save'), ENT_QUOTES, 'UTF-8'); ?>" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">

            <div class="col-md-6">
                <label class="form-label">Google Geocoding API key <?php echo $hasGoogleKey === true ? '<span class="badge bg-success">configured</span>' : '<span class="badge bg-secondary">not set</span>'; ?></label>
                <input type="password" name="googleApiKey" class="form-control" autocomplete="off"
                       placeholder="<?php echo $hasGoogleKey === true ? 'Leave blank to keep current' : 'Optional — blank uses Nominatim only'; ?>">
                <small class="text-muted">Never re-displayed once saved.</small>
            </div>

            <div class="col-12">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="geoAuto" name="autoGeocode" value="1" <?php echo $autoGeocode === true ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="geoAuto">
                        Automatically look up coordinates when a venue/event address is saved without coordinates
                    </label>
                    <div class="form-text">Best-effort — a failed or slow geocode never blocks the save. The manual "Look up coordinates" button always works regardless of this setting.</div>
                </div>
            </div>

            <div class="col-12">
                <button class="btn btn-primary" type="submit">Save settings</button>
            </div>
        </form>

        <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/integrations/geocoding/test'), ENT_QUOTES, 'UTF-8'); ?>" class="mt-3 pt-3 border-top">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <button class="btn btn-outline-secondary" type="submit">
                <i class="fa-solid fa-plug me-1"></i>Test connection
            </button>
            <small class="text-muted d-block mt-1">Geocodes a fixed test address through the live provider chain.</small>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><strong>Cache</strong></div>
    <div class="card-body">
        <div class="portal-data-list">
            <div class="portal-data-row">
                <div class="col-6 fw-semibold">Cached results</div>
                <div class="col-6"><?php echo (int) $cacheCount; ?></div>
            </div>
            <div class="portal-data-row">
                <div class="col-6 fw-semibold">Last used</div>
                <div class="col-6"><?php echo $cacheLastUsed !== null ? htmlspecialchars((string) $cacheLastUsed, ENT_QUOTES, 'UTF-8') : '—'; ?></div>
            </div>
        </div>
    </div>
</div>

<a href="<?php echo htmlspecialchars(Site::url('admin/integrations'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Integrations</a>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
