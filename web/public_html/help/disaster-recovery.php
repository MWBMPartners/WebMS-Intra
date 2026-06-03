<?php
// Path: public_html/help/disaster-recovery.php
/**
 * Help — Disaster recovery in-portal landing. Surfaces the
 * docs/disaster-recovery-runbook.md for at-the-console admins.
 *
 * @package   Portal\Help
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/250
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;

Auth::ensureSession();
Auth::requireLogin();

$pageTitle   = 'Help — Disaster Recovery';
$pageSection = 'help';
$breadcrumbs = ['Dashboard' => '/', 'Help' => '/help', 'Disaster Recovery' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

if (App::isAdmin() === false): ?>
    <div class="alert alert-info">
        Disaster recovery is admin-only. The <a href="/help/getting-started">Getting Started guide</a> is the right page for general users.
    </div>
<?php
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
    return;
endif;
?>

<h1 class="mb-3"><i class="fa-solid fa-life-ring me-2"></i>Disaster recovery</h1>
<p class="text-secondary">If something is on fire right now, the
<a href="https://github.com/MWBMPartners/WebMS-Intra/blob/main/docs/disaster-recovery-runbook.md" target="_blank" rel="noopener">runbook</a>
walks you through it command-by-command. This page summarises the
must-know shortcuts.</p>

<div class="alert alert-warning">
    <strong>If you're not sure where to start:</strong> open
    <a href="/admin/maintenance/health">/admin/maintenance/health</a> first
    and read which probe is red.
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card h-100"><div class="card-body">
        <h5><i class="fa-solid fa-1 me-1"></i>Stop the bleeding</h5>
        <p class="small">Toggle maintenance mode immediately to show a friendly "we'll be back" page.</p>
        <a class="btn btn-warning btn-sm" href="/admin/maintenance/health">Maintenance mode</a>
    </div></div></div>
    <div class="col-md-4"><div class="card h-100"><div class="card-body">
        <h5><i class="fa-solid fa-2 me-1"></i>Diagnose</h5>
        <p class="small">Check probes, error log, and activity.</p>
        <a class="btn btn-outline-primary btn-sm" href="/admin/maintenance/health">Health</a>
        <a class="btn btn-outline-primary btn-sm" href="/admin/errors">Errors</a>
        <a class="btn btn-outline-primary btn-sm" href="/admin/activity">Activity</a>
    </div></div></div>
    <div class="col-md-4"><div class="card h-100"><div class="card-body">
        <h5><i class="fa-solid fa-3 me-1"></i>Restore</h5>
        <p class="small">Roll back to the latest snapshot. Use the off-site copy if local backups are gone.</p>
        <a class="btn btn-outline-success btn-sm" href="/admin/maintenance/backup">Backups</a>
        <a class="btn btn-outline-success btn-sm" href="/admin/maintenance/offsite-backup">Off-site</a>
    </div></div></div>
</div>

<div class="card mb-3"><div class="card-body">
    <h5>Common failure modes</h5>
    <ul>
        <li><strong>White page / 500</strong> — Tail the PHP error log; check <a href="/admin/errors">/admin/errors</a>.</li>
        <li><strong>DB connection lost</strong> — Try <code>mysql … -e "SELECT NOW();"</code>; check DreamHost status.</li>
        <li><strong>Disk full</strong> — Prune <code>_backups/</code> older than 4 most recent; prune <code>_uploads/photos/queue/</code> older than 30 days.</li>
        <li><strong>Bad migration</strong> — Full-restore the latest snapshot via <a href="/admin/maintenance/backup">Backups</a>.</li>
        <li><strong>Stolen credentials</strong> — clear every session, force password reset, rotate <code>enc.key</code>, rotate every encrypted integration credential. Exact SQL in <a href="https://github.com/MWBMPartners/WebMS-Intra/blob/main/docs/disaster-recovery-runbook.md#44-stolen-credentials" target="_blank" rel="noopener">runbook section 4.4</a>.</li>
        <li><strong>Compromised account</strong> — Disable user + revoke sessions, then run the offboarding flow.</li>
    </ul>
</div></div>

<div class="card"><div class="card-body">
    <h5>Communication template</h5>
    <p class="small text-muted">Paste verbatim into Slack / email / SMS once maintenance mode is on.</p>
    <pre class="small bg-body-tertiary p-3 rounded">Subject: Portal temporarily unavailable

Hi team,

Our portal is currently unavailable while we investigate a technical
issue. Sign-in, dashboards, and uploads are all affected.

We're aware and working on it. Expected back: [time + timezone] /
we'll update by [time] if we don't have a fix yet.

Nothing you submitted is lost — we'll send a follow-up once we're
sure everything is in order.

Sorry for the disruption.

— [Name]</pre>
</div></div>

<p class="mt-3">
    <a class="btn btn-outline-secondary btn-sm" href="https://github.com/MWBMPartners/WebMS-Intra/blob/main/docs/disaster-recovery-runbook.md" target="_blank" rel="noopener">
        <i class="fa-solid fa-book me-1"></i>Full runbook (docs/disaster-recovery-runbook.md)
    </a>
</p>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
