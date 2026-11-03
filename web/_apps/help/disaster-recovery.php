<?php
// Path: public_html/help/disaster-recovery.php
/**
 * Help — Disaster recovery in-portal landing. Surfaces the
 * docs/disaster-recovery-runbook.md for at-the-console admins.
 *
 * @package   Portal\Help
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/250
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/583
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
        <p class="small">Switch maintenance mode on straight away. Everybody else then sees a
            holding page while you work; you can still sign in and use every page.</p>
        <?php /* 🛟 #583. This used to promise "a friendly 'we'll be back' page" and stop
                 there, which left out the one thing somebody closing the portal after a
                 break-in needs to know: `Maintenance::renderHoldingPage()` always prints
                 "<portal> is being upgraded", whatever the real reason, AFTER whatever
                 message has been stored. So a portal closed because of an intrusion tells
                 its members it is being upgraded unless the administrator types something
                 of their own. The hard-coded sentence is #583 item (f) and is not fixed —
                 it lives in `web/_core/Maintenance.php`, not on this page — so this page
                 says what is true today rather than implying the message is chosen for
                 you. Runbook section 4 says the same thing. */ ?>
        <p class="small"><strong>Type your own message when you do</strong> &mdash; under the
            message you type, the holding page always says the portal is being upgraded,
            whatever the real reason.</p>
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
        <?php /* 🛟 #583. This item used to end "check DreamHost status". WebMS is a
                 product on hosting we do not control (#500), so no page may name one
                 customer's hosting company as though it were everybody's. */ ?>
        <li><strong>DB connection lost</strong> &mdash; every page says
            <code>Database connection error.</code> Check your hosting company's status page
            first, then the five database values in <code>_auth_keys/auth_creds.php</code>
            against the database page of your hosting panel &mdash; a wrong password there
            gives exactly this message. <strong>Never move, rename or delete that file on a
            live portal:</strong> without it the portal offers its installation wizard to
            every visitor. Runbook section&nbsp;6.1.</li>
        <?php /* 🛟 #583. This item used to say "prune `_uploads/photos/queue/` older than
                 30 days", which the runbook forbids in so many words ("Do not delete
                 anything under `_uploads/` by hand. The database points at those files.").
                 It is not merely out of date, it is destructive: `_apps/photos/upload.php`
                 writes a `tblPhoto` row whose `filePath` is `queue/<name>` with
                 `status = 'pending_approval'`, so deleting those files throws away
                 members' photos that are still waiting for a moderator and leaves the row
                 behind pointing at nothing. The snapshot half was right and is kept. */ ?>
        <li><strong>Disk full</strong> &mdash; uploads fail and "Run snapshot now" fails.
            Delete old snapshots with the <strong>Delete</strong> button beside each one on the
            <a href="<?php echo htmlspecialchars(Site::url('admin/maintenance/backup'), ENT_QUOTES, 'UTF-8'); ?>">Backup page</a>,
            keeping at least the newest four. <strong>Do not delete anything under
            <code>_uploads/</code> by hand</strong> &mdash; the database points at those
            files, and a photo still waiting for a moderator would leave a row pointing at
            nothing. Runbook section&nbsp;6.2.</li>
        <li><strong>Bad migration</strong> — Full-restore the latest snapshot via <a href="<?php echo htmlspecialchars(Site::url('admin/maintenance/backup'), ENT_QUOTES, 'UTF-8'); ?>">Backups</a>.</li>
        <?php /* 🛟 #583. The old item read "clear every session, force password reset,
                 rotate `enc.key`, rotate every encrypted integration credential. Exact SQL
                 in runbook section 4.4." Four things wrong with it, and the third is the
                 one that could cost a customer everything:
                 • "clear every session" and "force password reset" are buttons the portal
                   DOES NOT HAVE. They are #582, which is a feature request, not built —
                   so this page was telling somebody mid-incident to use tools that do not
                   exist, and the runbook's own section 7 opens by saying so plainly.
                 • "rotate `enc.key`" is advice the runbook spends a whole section
                   (7.9) telling people NOT to follow: replacing that file by hand makes
                   every integration secret and every person's two-factor sign-in
                   unreadable at once. There is no tool that re-encrypts them.
                 • "runbook section 4.4" has not existed since the runbook was
                   reorganised under #488. The ANCHOR in the link was already updated to
                   `#7-after-a-break-in`; only the words a reader sees were left behind,
                   so the link text and the page it opened disagreed.
                 Rotating the integration credentials IS real and is kept, because 7.9
                 step 2 is a page an administrator can open today. */ ?>
        <li><strong>Stolen credentials</strong> &mdash; work through
            <strong>runbook section&nbsp;7</strong> in the order it gives: that order is what
            stops you locking yourself out. Two things to know before you start.
            <strong>The portal has no button that signs everybody out, and none that makes
            everybody choose a new password</strong> &mdash; section&nbsp;7 gives the steps
            that work today, some of which need a command line on the server.
            <strong>Do not replace <code>_auth_keys/enc.key</code> by hand.</strong> Nothing
            re-encrypts what it protects, so every integration's secret and every person's
            two-factor sign-in becomes unreadable the moment you swap it, and only putting
            the old file back makes them readable again. Make a new secret at each outside
            service instead, cancel the old one, and type the new one into that service's
            page under
            <a href="<?php echo htmlspecialchars(Site::url('admin/integrations'), ENT_QUOTES, 'UTF-8'); ?>">Integrations</a>;
            then revoke and re-issue every key on
            <a href="<?php echo htmlspecialchars(Site::url('admin/integrations/api-keys'), ENT_QUOTES, 'UTF-8'); ?>">API keys</a>.
            The exact commands are in
            <a href="https://github.com/MWBMPartners/WebMS-Intra/blob/main/docs/disaster-recovery-runbook.md#7-after-a-break-in" target="_blank" rel="noopener">runbook section&nbsp;7</a>.</li>
        <?php /* 🛟 #583. The old item read "Disable user + revoke sessions, then run the
                 offboarding flow." "Revoke sessions" names nothing: there is no session
                 revocation anywhere under `web/` (grep for revokeSessions /
                 signOutEverywhere finds no file), and it is also no longer needed —
                 `Auth::ensureSession()` ends the session of an account that is switched
                 off, which runbook 7.7 records as tested (28). And offboarding is the
                 route for somebody genuinely leaving; pointed at a victim's account it
                 erases their records rather than protecting them. */ ?>
        <li><strong>Compromised account</strong> &mdash; open
            <a href="<?php echo htmlspecialchars(Site::url('admin/users'), ENT_QUOTES, 'UTF-8'); ?>">Users</a>,
            press <strong>Edit</strong> beside that person, untick <strong>Active</strong> and
            Save. From then on they cannot sign in with a password, a passkey, Microsoft or
            Google, and if they are signed in at this moment they are signed out on their next
            click &mdash; a page already open on their screen stays there until they click.
            Then look for a way back in that a new password does not close: a passkey they
            added, a Microsoft or Google account they linked, or global administrator rights
            they gave themselves. Runbook section&nbsp;7.3 lists all three, and
            section&nbsp;7.7 is this step in full. Offboarding is for somebody who is
            genuinely leaving &mdash; it is not the tool for an account that has been taken
            over.</li>
        <?php // 🛟 #694, and the last three sentences #697. Said HERE because this is
              //    the page somebody opens when they are about to put a backup back, and
              //    the Backup page's own card only appears AFTER the restore has already
              //    happened. Those sentences used to say the OPPOSITE - that another
              //    restore would replace the waiting list and the portal would merely say
              //    so - which was true until 31 October 2026 and is what #697 fixed.
              //
              //    ⚠️ THREE TABLES, NOT FOUR, and the #697 package briefly said four
              //    here. `tblRestoreHeldRows` IS on the list a one-table restore refuses,
              //    but a backup with no "covers the whole database" flag was taken before
              //    that flag existed (27 October 2026) and the holding table did not exist
              //    until 31 October 2026 - so such a backup cannot contain it, and there is
              //    nothing to leave alone. Measured by restoring one. This is the sentence
              //    a customer reads before deciding whether to accept a loss, so a true
              //    sentence was replaced with a false one in the worst possible place. ?>
        <li><strong>An older backup leaves rows behind</strong> &mdash; a backup taken before
            27&nbsp;October&nbsp;2026 does not say it covers the whole database, so putting it back
            leaves three tables alone and the database&rsquo;s own rules then remove rows from two of
            them: <strong>every setting an organisation has chosen for itself</strong> &mdash; its
            address and support e-mail, and any limit it has set &mdash; and
            <strong>people&rsquo;s two-factor backup codes</strong>, including the record of which
            ones have already been used. The portal <strong>keeps those rows</strong> and the
            <a href="<?php echo htmlspecialchars(Site::url('admin/maintenance/backup'), ENT_QUOTES, 'UTF-8'); ?>">Backup page</a>
            offers one button to put them back and one to discard them. They wait until you choose
            &mdash; nothing deletes them on a timer. <strong>Putting another backup back does not
            throw them away.</strong> The portal reads the waiting rows before it empties anything
            and writes them again at the end, and the result says what it did. One thing it will not
            do silently: if a backup already records one of those rows as put back, it honours that
            backup instead of offering the row again &mdash; and says so. (If more than 20,000 rows
            are waiting it leaves the whole list alone rather than rewriting it, and every one of
            them is still waiting afterwards.) Putting back the one table they are kept in WOULD
            lose them, so the portal refuses to and says why. A backup taken since 27&nbsp;October&nbsp;2026 does not cause
            this loss in the first place.</li>
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
