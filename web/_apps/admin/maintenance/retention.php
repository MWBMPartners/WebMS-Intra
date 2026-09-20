<?php
// Path: public_html/admin/maintenance/retention.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Audit-Log Retention Sweeper 🧹
 * -----------------------------------------------------------------------------
 * Hard-deletes rows from tblActivityLogs + tblErrors older than the configured
 * retention window. Without this sweeper, both tables grow forever — disk
 * usage on shared hosting becomes a real problem after a year or two.
 *
 * This file is the staff page only: a global administrator sees a per-table
 * preview of how many rows would be deleted, then confirms.
 *
 * The scheduled version of the same sweep is at /cron/retention-sweep?token=…
 * (web/_apps/cron/retention-sweep.php). Until 14 September 2026 it was a
 * "?cron=1&token=…" mode of this page. It moved because this address is seeded
 * as protected, and once the Router really enforced that (issue #497) a
 * scheduler with a token but no session would only have been redirected to
 * the sign-in page, so the sweep would have stopped without any error. The
 * page and the job run the same code, in web/_apps/cron/_retention-sweep.php.
 *
 * WHO MAY USE THE WEB UI, AND WHY IT IS A GLOBAL ADMINISTRATOR ONLY
 * -----------------------------------------------------------------------------
 * Both the sweep and the preview counts above it act on the WHOLE
 * installation, not the one organisation the visiting administrator belongs
 * to: the activity-log and error deletes have no organisation column to limit
 * them to, and the event-registration clean-up loops over every organisation
 * on the server in turn (see registration_sweep_sites()).
 *
 * Until 13 September 2026 the only check was App::isAdmin(), which is true for
 * an administrator of a SINGLE organisation as well as for a global
 * administrator (see web/_core/App.php). So an administrator of one
 * organisation could press "Run Sweep Now" and hard-delete activity logs,
 * error rows and children's event-registration records — including
 * safeguarding details such as allergies and medical notes — belonging to
 * every OTHER organisation on the installation, without their knowledge or
 * consent. This matches the fix already made to backup.php for the same
 * reason (issue #495): an action with no organisation boundary needs a
 * permission with no organisation boundary.
 *
 * The scheduled job (now /cron/retention-sweep, see above) is untouched by
 * this: it was never gated by App::isAdmin() in the first place, it
 * authenticates with a shared secret token instead of a session, and
 * per-organisation retention is a separate, not-yet-decided question tracked
 * in issue #491 — this change is only about WHO may press the web button, not
 * what the sweep deletes.
 *
 * Settings:
 *
 *   audit.retentionDays        (default 365) — activity logs
 *   errors.retentionDays       (default 365) — error logs
 *   attend.detailRetentionDays (default 90) — how long the scrambled sender
 *                               address is kept on an anonymous check-in
 *                               (#530: a browser-description column used to
 *                               sit on the same timer; migration 201 dropped
 *                               it, since nothing ever read it). Each
 *                               organisation has its own; 0 means keep for
 *                               ever. Changed at /admin/settings/attendance.
 *   maintenance.cronToken      ('' by default — used by /cron/retention-sweep;
 *                               an empty value switches the job off)
 *
 * Deletions are hard (no soft-delete column on these tables). This page shows
 * an HTML report; the scheduled job returns JSON.
 *
 * ONE PART OF THIS SWEEP DELETES NOTHING, and it is worth saying here because
 * everything else on this page does. The anonymous check-in clear-out (#525)
 * empties two columns out of rows that stay exactly where they are. Its two
 * numbers are reported separately and are deliberately NOT added to the
 * "deleted N rows" total.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/491
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/495
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;

// -----------------------------------------------------------------------------
// 🧹 The sweep's rules and the delete itself are shared with the scheduled job
//    at /cron/retention-sweep, so the numbers this page previews and the rows
//    the job deletes are always decided by the same code.
//
//    This page used to BE the scheduled job as well, through a "?cron=1&token="
//    mode handled right here, before any sign-in check. That mode was removed on
//    14 September 2026 (issue #497). This address is seeded as protected, and
//    now that the Router really enforces that, a scheduler with a token but no
//    session would only ever be redirected to the sign-in page.
// -----------------------------------------------------------------------------
require_once PORTAL_APPS . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . '_retention-sweep.php';

// -----------------------------------------------------------------------------
// 🛡️ Web mode — admin-only
// -----------------------------------------------------------------------------
$pageTitle   = 'Audit Retention Sweeper';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Maintenance' => '', 'Retention' => ''];

Auth::ensureSession();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// 🚧 Global administrators only — see "WHO MAY USE THE WEB UI" in the file
//    header above. This sits BEFORE the POST handler and BEFORE the preview
//    counts are computed, so nothing below runs for anybody else: no sweep,
//    and the installation-wide row counts (which already covered every
//    organisation, not just the visitor's own) are not shown either.
//
//    Matches how backup.php refuses (issue #495 fix, 13 September 2026): the
//    same wording style, the same 403 status, and a refusal recorded in the
//    activity log — but ONLY for a POST with a genuine form token, otherwise
//    a forged request from another website could fill the log with
//    refusals just by loading images from it. Simply opening the page is not
//    recorded.
if (App::isRootAdmin() === false) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && Auth::verifyCsrf($_POST['csrf_token'] ?? '') === true
    ) {
        Logger::activity(
            'RetentionSweepRefused',
            'Refused: the audit-log retention sweep may only be run by a global administrator',
            $_SESSION['user_id'] ?? null
        );
    }

    http_response_code(403);
    // $pageTitle / $pageSection / $breadcrumbs are already set above, before
    // Auth::ensureSession() runs — reused here rather than repeated.
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
    ?>
    <h1 class="mb-3"><i class="fa-solid fa-broom me-2"></i>Audit Retention Sweeper</h1>
    <div class="alert alert-danger">
        This sweep deletes activity-log and error rows, and event
        registrations — including children's names, dates of birth, allergies
        and medical notes — across every organisation on this installation,
        not only yours. It also empties the scrambled sender address out of
        old anonymous check-ins, again across every organisation. So only a
        global administrator can use this page. Nothing has been changed.
    </div>
    <?php
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
    return;
}

$activityDays = (int) (App::settings('audit.retentionDays')  ?? '365');
$errorDays    = (int) (App::settings('errors.retentionDays') ?? '365');
if ($activityDays < 1) { $activityDays = 365; }
if ($errorDays    < 1) { $errorDays    = 365; }

$flashMsg  = '';
$flashType = '';

// -----------------------------------------------------------------------------
// 🚀 Execute on POST (with CSRF)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $flashMsg  = 'Invalid or expired form token. Please try again.';
        $flashType = 'danger';
    } else {
        $result = run_retention_sweep();
        Logger::activity('AuditRetentionSweep', 'Admin sweep deleted ' . $result['totalDeleted'] . ' rows');
        $flashMsg  = 'Sweep complete. Deleted ' . $result['activityDeleted']
                   . ' activity log row(s), ' . $result['errorsDeleted'] . ' error row(s) and '
                   . $result['registrationsDeleted'] . ' event registration(s). '
                   // 🚪 Said as a separate sentence, with the word "emptied"
                   //    rather than "deleted", because nothing was deleted here
                   //    and the difference matters: the check-in rows and every
                   //    count on them are still there.
                   . 'Also stored the sender figure for ' . $result['checkinDaysStored']
                   . ' event-day(s) of anonymous check-ins and then emptied the scrambled address '
                   . 'out of ' . $result['checkinDetailCleared']
                   . ' check-in row(s). Those rows and their counts were not deleted.';
        $flashType = 'success';
    }
}

// -----------------------------------------------------------------------------
// 📊 Preview counts (what WOULD be deleted right now)
// -----------------------------------------------------------------------------
$preview = preview_retention_counts($activityDays, $errorDays);

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-broom me-2"></i>Audit Retention Sweeper</h1>
        <p class="text-secondary mb-0">Hard-delete activity logs and error rows older than the configured window.</p>
    </div>
    <a href="/admin" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i> Back to Admin
    </a>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h6"><i class="fa-solid fa-list-check me-1"></i>Activity Logs (<code>tblActivityLogs</code>)</h2>
                <p class="text-muted small mb-2">Retention: <strong><?php echo $activityDays; ?> days</strong> (setting: <code>audit.retentionDays</code>)</p>
                <p class="mb-0">Rows that would be deleted now: <strong><?php echo number_format($preview['activity']); ?></strong></p>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h6"><i class="fa-solid fa-triangle-exclamation me-1"></i>Error Log (<code>tblErrors</code>)</h2>
                <p class="text-muted small mb-2">Retention: <strong><?php echo $errorDays; ?> days</strong> (setting: <code>errors.retentionDays</code>)</p>
                <p class="mb-0">Rows that would be deleted now: <strong><?php echo number_format($preview['errors']); ?></strong></p>
            </div>
        </div>
    </div>
    <!-- 🧒 Event registrations. Given the full width and a warning colour
         deliberately: this is the only one of the three that holds information
         about a named child - their date of birth, allergies and medical notes,
         beside a parent's telephone number and email address. An administrator
         should not be able to run this sweep without having seen it. -->
    <div class="col-12">
        <div class="card shadow-sm border-warning">
            <div class="card-body">
                <h2 class="h6"><i class="fa-solid fa-child-reaching me-1"></i>Event Registrations (<code>tblEventRegistrations</code>)</h2>
                <p class="text-muted small mb-2">
                    Kept for <strong><?php echo (int) (App::settings('events.registrationRetentionDays') ?? 90); ?> days</strong>
                    after the event by default (setting: <code>events.registrationRetentionDays</code>).
                    A single event can set its own number of days, which wins over this one.
                    Each site uses its own setting.
                </p>
                <p class="mb-2">Registrations currently eligible for deletion: <strong><?php echo number_format($preview['registrations']); ?></strong></p>
                <p class="small text-muted mb-0">
                    These hold a child's name, date of birth, allergies and medical notes, with a
                    parent's telephone number and email address. Most are submitted by people with no
                    account, so they cannot be found by a &ldquo;delete everything you hold about
                    me&rdquo; request &mdash; nobody should have to ask, which is why they are removed
                    on a time limit instead.
                </p>
            </div>
        </div>
    </div>
    <!-- 🚪 Anonymous check-ins. Given its own card because it is the one part
         of this sweep that does NOT delete anything, and somebody pressing the
         button should know that before they press it rather than afterwards. -->
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h6"><i class="fa-solid fa-door-open me-1"></i>Anonymous check-in detail (<code>tblAnonymousCheckins</code>)</h2>
                <p class="text-muted small mb-2">
                    Kept for <strong><?php echo (int) (App::settings('attend.detailRetentionDays') ?? 90); ?> days</strong>
                    by default (setting: <code>attend.detailRetentionDays</code>).
                    Each organisation uses its own setting, and 0 means keep for ever.
                </p>
                <p class="mb-2">Check-in rows whose detail would be emptied now: <strong><?php echo number_format($preview['checkinDetail']); ?></strong></p>
                <p class="small text-muted mb-0">
                    <strong>Nothing is deleted here.</strong> The rows stay, with their counts, their
                    headcounts, how each check-in arrived and when. What is emptied is the browser
                    description and a scrambled version of the sender's internet address &mdash; two
                    things nothing in the portal ever shows. An anonymous check-in has no link to any
                    person, so a &ldquo;delete everything you hold about me&rdquo; request cannot reach
                    one; the time limit is the answer instead. Before each day's detail goes, the
                    &ldquo;probably how many different senders&rdquo; figure for that day is worked out
                    and stored, so the number an organisation sees does not change afterwards.
                </p>
            </div>
        </div>
    </div>
</div>

<form method="post" action="/admin/maintenance/retention"
      data-confirm="About <?php echo number_format($preview['activity'] + $preview['errors']); ?> log row(s) and <?php echo number_format($preview['registrations']); ?> event registration(s) are currently eligible, including any children's details they hold, and <?php echo number_format($preview['checkinDetail']); ?> anonymous check-in row(s) would have their scrambled sender address emptied out (those rows and their counts are kept). The exact numbers may differ slightly, because more records become eligible as time passes. This cannot be undone. Continue?"
      data-confirm-destructive="true">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <button type="submit" class="btn btn-warning" <?php echo ($preview['activity'] + $preview['errors'] + $preview['registrations'] + $preview['checkinDetail']) === 0 ? 'disabled' : ''; ?>>
        <i class="fa-solid fa-broom me-1"></i> Run Sweep Now
    </button>
    <a href="/settings" class="btn btn-outline-secondary">Adjust retention settings</a>
</form>

<div class="card mt-4 border-0 bg-body-tertiary">
    <div class="card-body">
        <h2 class="h6 mb-2"><i class="fa-solid fa-clock me-1"></i>Cron-style execution</h2>
        <p class="small mb-2">
            For scheduled execution (every night, say), set
            <code>maintenance.cronToken</code> in Settings to a long random string, then have your
            cron / scheduled task hit:
        </p>
        <pre class="small mb-0"><code>curl -s "https://&lt;your-portal&gt;/cron/retention-sweep?token=&lt;TOKEN&gt;"</code></pre>
        <p class="small text-muted mb-0 mt-2">
            The token is checked with <code>hash_equals</code> (constant-time compare). An empty token switches the scheduled job off.
            The old address, this page with <code>?cron=1</code> added, no longer runs the sweep: this page now always asks for a sign-in.
        </p>
    </div>
</div>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
