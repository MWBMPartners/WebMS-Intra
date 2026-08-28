<?php
// Path: _apps/admin/integrations/what3words/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — What3Words integration settings 🔤 (#456 Chunk A)
 * -----------------------------------------------------------------------------
 * Clone of the cloudflare-stream admin trio's structure. `w3w.enabled`
 * gates ONLY the API adapter (autosuggest / validation / lat-lng<->W3W
 * conversion) — the stored-field fallback (a typed W3W value displays and
 * links regardless) works with this flag OFF, and the W3W input itself is
 * ALWAYS present on every form (locked decision).
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

$enabled     = (string) (App::settings('w3w.enabled') ?? 'false') === 'true';
$hasApiKey   = ((string) (App::settings('w3w.apiKey') ?? '')) !== '';

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');

$pageTitle   = 'what3words Integration';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Integrations' => '/admin/integrations', 'what3words' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-location-crosshairs me-2"></i>what3words Integration</h1>
<p class="text-secondary">
    what3words addresses a 3m square with three simple words. The <code>///word.word.word</code>
    field is always available for manual entry across the portal (Events, Venues, Resources, Asset
    Locations) — the API below only ADDS validation, coordinate conversion, and typing autosuggest.
</p>

<div class="alert alert-info small">
    <strong>Works with the API off:</strong> a typed what3words value is stored and displayed with a
    working <code>///</code> link regardless of this setting — the API is a pure enhancement.
</div>

<div class="card mb-4">
    <div class="card-header"><strong>API</strong></div>
    <div class="card-body">
        <form method="post" action="/admin/integrations/what3words/save" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">

            <div class="col-12">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="w3wEnabled" name="enabled" value="1" <?php echo $enabled === true ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="w3wEnabled">Enable the what3words API (validation, coordinate conversion, autosuggest)</label>
                </div>
            </div>

            <div class="col-md-6">
                <label class="form-label">API key <?php echo $hasApiKey === true ? '<span class="badge bg-success">configured</span>' : '<span class="badge bg-secondary">not set</span>'; ?></label>
                <input type="password" name="apiKey" class="form-control" autocomplete="off"
                       placeholder="<?php echo $hasApiKey === true ? 'Leave blank to keep current' : 'Paste your what3words API key'; ?>">
                <small class="text-muted">From <a href="https://what3words.com/select-plan" target="_blank" rel="noopener">what3words.com</a>. Never re-displayed once saved.</small>
            </div>

            <div class="col-12">
                <button class="btn btn-primary" type="submit">Save settings</button>
            </div>
        </form>

        <form method="post" action="/admin/integrations/what3words/test" class="mt-3 pt-3 border-top">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <button class="btn btn-outline-secondary" type="submit" <?php echo ($enabled === false || $hasApiKey === false) ? 'disabled' : ''; ?>>
                <i class="fa-solid fa-plug me-1"></i>Test connection
            </button>
            <small class="text-muted d-block mt-1">
                Converts what3words' own documentation example square — nothing is stored or changed.
            </small>
        </form>
    </div>
</div>

<a href="/admin/integrations" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Integrations</a>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
