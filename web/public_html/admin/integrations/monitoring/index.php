<?php
// Path: public_html/admin/integrations/monitoring/index.php
/**
 * Admin — External error monitoring (Sentry / GlitchTip) config + test.
 *
 * @package   Portal\Admin
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/143
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\ErrorMonitor;
use Portal\Core\Router;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$settings = App::settings()['monitoring'] ?? [];
$enabled  = (string) ($settings['enabled'] ?? '0') === '1';
$hasDsn   = ((string) ($settings['sentryDsn'] ?? '')) !== '';
$env      = (string) ($settings['environment'] ?? App::env());
$sample   = (string) ($settings['sampleRate'] ?? '1.0');

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'Error monitoring';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Integrations' => '/admin/integrations', 'Error monitoring' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-satellite-dish me-2"></i>Error monitoring</h1>
<p class="text-secondary">
    Forward every <code>Logger::errorPlatform()</code> event to an external
    service (Sentry / GlitchTip) so errors are captured even when the portal's
    own database is unreachable. Silent no-op when DSN is empty.
</p>

<div class="card mb-3">
    <div class="card-header"><strong>Configuration</strong></div>
    <div class="card-body">
        <form method="post" action="/admin/integrations/monitoring/save" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="col-md-9">
                <label class="form-label">Sentry / GlitchTip DSN <?php echo $hasDsn === true ? '<span class="badge bg-success">set</span>' : ''; ?></label>
                <input type="password" class="form-control" name="dsn" placeholder="<?php echo $hasDsn === true ? 'Leave blank to keep current' : 'https://PUBLIC_KEY@oXXXXXX.ingest.sentry.io/PROJECT_ID'; ?>" autocomplete="off">
                <small class="text-muted">GlitchTip DSNs use the same format and same store API.</small>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="enabled" name="enabled" value="1" <?php echo $enabled === true ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="enabled">Enable</label>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Environment tag</label>
                <input type="text" class="form-control" name="environment" value="<?php echo htmlspecialchars($env, ENT_QUOTES, 'UTF-8'); ?>" placeholder="<?php echo htmlspecialchars(App::env(), ENT_QUOTES, 'UTF-8'); ?>">
                <small class="text-muted">Defaults to PORTAL_ENV (<code><?php echo htmlspecialchars(App::env(), ENT_QUOTES, 'UTF-8'); ?></code>).</small>
            </div>
            <div class="col-md-4">
                <label class="form-label">Sample rate (0.0 – 1.0)</label>
                <input type="text" class="form-control" name="sampleRate" value="<?php echo htmlspecialchars($sample, ENT_QUOTES, 'UTF-8'); ?>">
                <small class="text-muted">1.0 = every event; 0.1 = 10% sampling.</small>
            </div>
            <div class="col-12">
                <button class="btn btn-primary" type="submit">Save</button>
                <?php if ($hasDsn === true): ?>
                    <form method="post" action="/admin/integrations/monitoring/test" class="d-inline ms-2">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <button class="btn btn-outline-warning" type="submit">
                            <i class="fa-solid fa-paper-plane me-1"></i>Send test event
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><strong>How it works</strong></div>
    <div class="card-body small">
        <p>
            Every call to <code>Portal\Core\Logger::errorPlatform()</code> writes a row to
            <code>tblErrors</code> first, then (best-effort) fires the event to the
            configured monitor's <code>store</code> endpoint. The store call uses
            cURL with a 5-second timeout and a 2-second connect timeout — under no
            circumstances should monitoring slow down user-facing response time.
        </p>
        <p>
            The same configuration works for <strong>Sentry</strong>, <strong>GlitchTip</strong>,
            and any other service that accepts Sentry's <code>store</code> envelope shape.
        </p>
        <p class="mb-0">
            Threat model: the DSN itself doesn't grant write access to anything
            other than the configured project's event ingestion endpoint, so it's
            stored encrypted at rest via the existing libsodium pipeline.
        </p>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
