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
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/456
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$hasGoogleKey = ((string) (App::settings('geo.google.apiKey') ?? '')) !== '';
$autoGeocode  = (string) (App::settings('geo.autoGeocode') ?? 'false') === 'true';
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
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Integrations' => '/admin/integrations', 'Geocoding' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-earth-europe me-2"></i>Geocoding Integration</h1>
<p class="text-secondary">
    Converts an address to coordinates (and back). Provider chain: <strong>Google</strong> when a key
    is set below, otherwise <strong>OpenStreetMap Nominatim</strong> — throttled to one request per
    second and cached, so a given address is only ever geocoded once.
</p>

<div class="alert alert-info small">
    Nominatim's usage policy requires a descriptive User-Agent identifying this install and an admin
    contact. That contact currently resolves to
    <code><?php echo $contactEmail !== '' ? htmlspecialchars($contactEmail, ENT_QUOTES, 'UTF-8') : 'not set'; ?></code>
    (<a href="/admin/settings">privacy.contactEmail</a>, falling back to <code>mail.defaultFromAddress</code>).
</div>

<div class="card mb-4">
    <div class="card-header"><strong>Provider</strong></div>
    <div class="card-body">
        <form method="post" action="/admin/integrations/geocoding/save" class="row g-3">
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

        <form method="post" action="/admin/integrations/geocoding/test" class="mt-3 pt-3 border-top">
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

<a href="/admin/integrations" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Integrations</a>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
