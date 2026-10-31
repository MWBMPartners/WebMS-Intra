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
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$pageTitle   = 'Help — Disaster Recovery';
$pageSection = 'help';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Help' => Site::url('help'), 'Disaster Recovery' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

if (App::isAdmin() === false): ?>
    <div class="alert alert-info">
        Disaster recovery is admin-only. The <a href="<?php echo htmlspecialchars(Site::url('help/getting-started'), ENT_QUOTES, 'UTF-8'); ?>">Getting Started guide</a> is the right page for general users.
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
    <a href="<?php echo htmlspecialchars(Site::url('admin/maintenance/health'), ENT_QUOTES, 'UTF-8'); ?>">/admin/maintenance/health</a> first
    and read which probe is red.
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card h-100"><div class="card-body">
        <h5><i class="fa-solid fa-1 me-1"></i>Stop the bleeding</h5>
        <p class="small">Toggle maintenance mode immediately to show a friendly "we'll be back" page.</p>
        <?php /* The toggle lives on Admin, Settings, Maintenance. This button pointed at
                 admin/maintenance/health until 27 October 2026 — the health page has no
                 toggle on it at all, so the first step of a recovery led nowhere. */ ?>
        <a class="btn btn-warning btn-sm" href="<?php echo htmlspecialchars(Site::url('admin/settings/maintenance'), ENT_QUOTES, 'UTF-8'); ?>">Maintenance mode</a>
    </div></div></div>
    <div class="col-md-4"><div class="card h-100 border-info"><div class="card-body">
        <h5><i class="fa-solid fa-key me-1"></i>You can still get back in</h5>
        <p class="small">
            While maintenance mode is on, everybody else sees the holding page &mdash;
            but <strong>signing in still works</strong>, and so does resetting a
            forgotten password. If you use a code from your phone or a passkey, those
            work too. The health report an uptime monitor reads, at /cron/health,
            also keeps working, so the monitor can see an update is under way.
        </p>
        <p class="small mb-0">
            Worth knowing because maintenance mode also switches itself on by
            itself, whenever the portal's files are newer than its database. That
            happens on every update. If it catches you signed out, you are not
            stuck.
        </p>
    </div></div></div>
    <div class="col-md-4"><div class="card h-100"><div class="card-body">
        <h5><i class="fa-solid fa-2 me-1"></i>Diagnose</h5>
        <p class="small">Check probes, error log, and activity.</p>
        <a class="btn btn-outline-primary btn-sm" href="<?php echo htmlspecialchars(Site::url('admin/maintenance/health'), ENT_QUOTES, 'UTF-8'); ?>">Health</a>
        <a class="btn btn-outline-primary btn-sm" href="<?php echo htmlspecialchars(Site::url('admin/errors'), ENT_QUOTES, 'UTF-8'); ?>">Errors</a>
        <a class="btn btn-outline-primary btn-sm" href="<?php echo htmlspecialchars(Site::url('admin/activity'), ENT_QUOTES, 'UTF-8'); ?>">Activity</a>
    </div></div></div>
    <div class="col-md-4"><div class="card h-100"><div class="card-body">
        <h5><i class="fa-solid fa-3 me-1"></i>Restore</h5>
        <p class="small">
            Roll back to the latest snapshot. If the backups on this server are gone
            too, use an off-site copy — but only if one was set up: it needs a command
            line on the server, and the Off-site page says whether any copy has really
            been sent.
        </p>
        <a class="btn btn-outline-success btn-sm" href="<?php echo htmlspecialchars(Site::url('admin/maintenance/backup'), ENT_QUOTES, 'UTF-8'); ?>">Backups</a>
        <a class="btn btn-outline-success btn-sm" href="<?php echo htmlspecialchars(Site::url('admin/maintenance/offsite-backup'), ENT_QUOTES, 'UTF-8'); ?>">Off-site</a>
    </div></div></div>
</div>

<div class="card mb-3"><div class="card-body">
    <h5>Common failure modes</h5>
    <ul>
        <li><strong>White page / 500</strong> — Tail the PHP error log; check <a href="<?php echo htmlspecialchars(Site::url('admin/errors'), ENT_QUOTES, 'UTF-8'); ?>">/admin/errors</a>.</li>
        <li><strong>DB connection lost</strong> — Try <code>mysql … -e "SELECT NOW();"</code>; check DreamHost status.</li>
        <li><strong>Disk full</strong> — Prune <code>_backups/</code> older than 4 most recent; prune <code>_uploads/photos/queue/</code> older than 30 days.</li>
        <li><strong>Bad migration</strong> — Full-restore the latest snapshot via <a href="<?php echo htmlspecialchars(Site::url('admin/maintenance/backup'), ENT_QUOTES, 'UTF-8'); ?>">Backups</a>.</li>
        <li><strong>Stolen credentials</strong> — clear every session, force password reset, rotate <code>enc.key</code>, rotate every encrypted integration credential. Exact SQL in <a href="https://github.com/MWBMPartners/WebMS-Intra/blob/main/docs/disaster-recovery-runbook.md#7-after-a-break-in" target="_blank" rel="noopener">runbook section 4.4</a>.</li>
        <li><strong>Compromised account</strong> &mdash; Disable user + revoke sessions, then run the offboarding flow.</li>
        <?php // 🛟 #694. Said HERE because this is the page somebody opens when they are
              //    about to put a backup back, and the Backup page's own card only
              //    appears AFTER the restore has already happened. ?>
        <li><strong>An older backup leaves rows behind</strong> &mdash; a backup taken before
            27&nbsp;October&nbsp;2026 does not say it covers the whole database, so putting it back
            leaves three tables alone and the database&rsquo;s own rules then remove some rows from
            two of them: <strong>every setting an organisation has chosen for itself</strong> &mdash;
            its address and support e-mail, and any limit it has set &mdash; and
            <strong>people&rsquo;s two-factor backup codes</strong>, including the record of which
            ones have already been used. The portal <strong>keeps those rows</strong> and the
            <a href="<?php echo htmlspecialchars(Site::url('admin/maintenance/backup'), ENT_QUOTES, 'UTF-8'); ?>">Backup page</a>
            offers one button to put them back and one to discard them. They wait until you choose
            &mdash; nothing deletes them on a timer. <strong>Deal with them before you put another
            backup back</strong>, though: a backup that holds its own copy of this waiting list will
            replace it, and the portal says so before and after it happens. (A backup from before
            this feature existed has no such list in it and leaves the waiting rows alone.) A backup
            taken since 27&nbsp;October&nbsp;2026 does not cause this loss in the first place.</li>
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
