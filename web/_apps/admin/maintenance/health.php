<?php
// Path: public_html/admin/maintenance/health.php
/**
 * -----------------------------------------------------------------------------
 * Admin — System Health Dashboard 🩺
 * -----------------------------------------------------------------------------
 * Single page answering "is the portal healthy?". Probes DB, disk, backups,
 * errors, email, sessions, migrations, PHP, security headers in parallel
 * (where practical) and renders a status card per dimension.
 *
 * Uptime monitors (Uptime Robot, etc.) use /cron/health?token=… instead, which
 * returns the same checks as JSON (web/_apps/cron/health.php). Until
 * 14 September 2026 that was a "?cron=1" mode of this page; the comment above
 * the sign-in check below says why it moved.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/228
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;

// 🛡️ Staff only. The Router already sends a signed-out visitor to sign in,
//    because this address is seeded as protected; these checks stay as well so
//    the page is still safe if it is ever reached some other way.
//
//    This page used to have a "?cron=1&token=" mode for uptime monitors,
//    checked here before any sign-in. It was removed on 14 September 2026
//    (issue #497): with the Router enforcing sign-in, a monitor with a token and
//    no session would only ever be redirected. Monitors now use /cron/health.
Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

// 🧪 The checks are shared with /cron/health, so this page and the monitor
//    always report the same thing.
require_once PORTAL_APPS . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . '_health-probes.php';
$health  = maintenance_health_probes(App::db());
$probes  = $health['probes'];
$overall = $health['overall'];

$pageTitle   = 'System Health';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Maintenance' => '/admin/maintenance', 'Health' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

$overallBadge = match ($overall) {
    'ok'   => 'success',
    'warn' => 'warning',
    'crit' => 'danger',
    default => 'secondary',
};
$overallLabel = match ($overall) {
    'ok'   => 'All systems healthy',
    'warn' => 'Attention needed',
    'crit' => 'Critical issues',
    default => 'Unknown',
};
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-heart-pulse me-2"></i>System Health</h1>
        <p class="text-secondary mb-0">Live probes of database, disk, backups, errors, email, sessions, migrations.</p>
    </div>
    <a href="/admin/maintenance" class="btn btn-outline-secondary btn-sm">&larr; Maintenance</a>
</div>

<div class="alert alert-<?php echo $overallBadge; ?> mb-4">
    <strong><?php echo htmlspecialchars($overallLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
    <span class="text-muted">— checked <?php echo htmlspecialchars(date('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8'); ?></span>
</div>

<div class="row g-3">
    <?php foreach ($probes as $name => $p):
        $cls = match ($p['state']) {
            'ok'   => 'success',
            'warn' => 'warning',
            'crit' => 'danger',
            default => 'secondary',
        };
    ?>
        <div class="col-md-6 col-lg-4">
            <div class="card h-100 border-<?php echo $cls; ?>">
                <div class="card-body">
                    <h3 class="h6 mb-1"><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></h3>
                    <p class="mb-1"><span class="badge bg-<?php echo $cls; ?>"><?php echo htmlspecialchars($p['label'], ENT_QUOTES, 'UTF-8'); ?></span></p>
                    <p class="small text-muted mb-0"><?php echo htmlspecialchars($p['detail'], ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card mt-4">
    <div class="card-body">
        <h2 class="h5">Cron monitoring</h2>
        <p>Point Uptime Robot, healthchecks.io, or DreamHost cron at this address, using the token saved in <code>maintenance.cronToken</code>:</p>
        <pre class="bg-body-tertiary p-2 rounded"><code>0 * * * * curl -fsS "https://<?php echo htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'portal', ENT_QUOTES, 'UTF-8'); ?>/cron/health?token=YOUR_TOKEN" &gt; /dev/null</code></pre>
        <p class="small text-muted mb-0">
            Returns JSON with overall status + per-probe state.
            The old address, this page with <code>?cron=1</code> added, no longer works: this page now always asks for a sign-in.
            The address above keeps answering with JSON while the portal is in maintenance mode, so a monitor sees an upgrade in progress rather than an outage. The other scheduled jobs do not run during maintenance mode.
            On a test copy (beta, alpha or dev) with the pre-release sign-in gate switched on, it works differently: a monitor is not signed in, so it is sent to the sign-in page instead, maintenance mode or not. Many monitors count that redirect as "up".
        </p>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
