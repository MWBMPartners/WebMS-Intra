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
 * Two modes:
 *
 *   1. Web UI (this file) — admin clicks a button, sees a per-table preview
 *      of how many rows would be deleted, then confirms.
 *
 *   2. CRON-style endpoint at /admin/maintenance/retention?cron=1&token=…
 *      — for scheduled execution. Auth is via the
 *      `maintenance.cronToken` setting (matching token in the query string)
 *      instead of session auth, so a wget/curl from cron works.
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
 * The scheduled-job (cron) path above is untouched by this: it was never
 * gated by App::isAdmin() in the first place, it authenticates with a shared
 * secret token instead of a session, and per-organisation retention is a
 * separate, not-yet-decided question tracked in issue #491 — this change is
 * only about WHO may press the web button, not what the sweep deletes.
 *
 * Settings:
 *
 *   audit.retentionDays        (default 365) — activity logs
 *   errors.retentionDays       (default 365) — error logs
 *   maintenance.cronToken      ('' by default — empty value disables cron mode)
 *
 * Deletions are hard (no soft-delete column on these tables). Output is a
 * JSON document under cron mode and an HTML report under web mode.
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
// 🤖 Cron mode — no session auth, token-gated
// -----------------------------------------------------------------------------
$isCron = isset($_GET['cron']) === true && $_GET['cron'] === '1';
if ($isCron === true) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $configured = (string) (App::settings('maintenance.cronToken') ?? '');
    $provided   = (string) ($_GET['token'] ?? '');

    if ($configured === '' || hash_equals($configured, $provided) === false) {
        http_response_code(403);
        echo json_encode(['status' => 'forbidden']);
        exit();
    }

    $result = run_retention_sweep();
    Logger::activity('AuditRetentionSweep', 'Cron sweep deleted ' . $result['totalDeleted'] . ' rows');
    echo json_encode(['status' => 'ok'] + $result);
    exit();
}

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
        not only yours. So only a global administrator can use this page.
        Nothing has been changed.
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
                   . $result['registrationsDeleted'] . ' event registration(s).';
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
</div>

<form method="post" action="/admin/maintenance/retention"
      data-confirm="About <?php echo number_format($preview['activity'] + $preview['errors']); ?> log row(s) and <?php echo number_format($preview['registrations']); ?> event registration(s) are currently eligible, including any children's details they hold. The exact number may differ slightly, because more records become eligible as time passes. This cannot be undone. Continue?"
      data-confirm-destructive="true">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <button type="submit" class="btn btn-warning" <?php echo ($preview['activity'] + $preview['errors'] + $preview['registrations']) === 0 ? 'disabled' : ''; ?>>
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
        <pre class="small mb-0"><code>curl -s "https://&lt;your-portal&gt;/admin/maintenance/retention?cron=1&amp;token=&lt;TOKEN&gt;"</code></pre>
        <p class="small text-muted mb-0 mt-2">
            The token is checked with <code>hash_equals</code> (constant-time compare). Empty token disables cron mode.
        </p>
    </div>
</div>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';

// -----------------------------------------------------------------------------
// 🔧 Helpers (defined at the bottom so they're available regardless of
//     which entry path executed — file is procedural)
// -----------------------------------------------------------------------------

/**
 * 📏 The rule that decides whether one registration is past its keep-by date.
 *
 * Two limits worth stating plainly rather than leaving to be discovered.
 *
 * The number shown on the page is a snapshot, not a promise. More records
 * become eligible as time passes, and a setting can be changed in between, so
 * the count an administrator sees and the number actually removed a moment
 * later can differ slightly. The page says "currently eligible" for that
 * reason. Guaranteeing an exact figure would mean fixing the list of candidates
 * and checking each one again before deleting, which is not worth the
 * complication for a clear-out that runs on a timescale of months.
 *
 * NOW() is the database server's idea of the time, while these event columns
 * hold local wall-clock times. If the two disagree, the deadline moves by that
 * difference - later if the database is behind, earlier if it is ahead. At 90
 * days a few hours either way does not matter, but it is a real difference and
 * not a guaranteed-safe one, so it is written down here rather than assumed.
 *
 * Written out once, on purpose, and used by BOTH the count shown to an
 * administrator and the delete that actually runs. If the two were written
 * separately they would eventually drift apart, and then the page would promise
 * to remove one number of records and remove a different one. For a table
 * holding children's medical notes that is not an acceptable risk.
 *
 * Three values are bound, in this order: the site, then the fallback number of
 * days twice (once to test it is above zero, once to do the date arithmetic).
 *
 * Two details worth explaining, because both look redundant and neither is:
 *
 *   COALESCE(e.registrationRetentionDays, ?) - the event's own number of days
 *   when it has one, the site's setting otherwise. That is exactly the rule
 *   "the event's own setting wins". Testing it is above zero first is what
 *   makes zero mean "keep indefinitely".
 *
 *   GREATEST(COALESCE(end, start), start) - the later of the event's end and
 *   its start. An end time is optional, so it may be missing; but it can also
 *   be WRONG. Nothing stops somebody saving an event whose end is before its
 *   start, and nothing stops an event being moved into the future while its old
 *   end date is left behind. Taking whichever is later means a stale end date
 *   can never drag the deadline earlier than the event itself. Without it, an
 *   event starting in December with a leftover end date in January of the year
 *   just gone would have its registrations deleted today.
 *
 * @return string The WHERE clause, including the word WHERE.
 */
function registration_sweep_where(): string
{
    return 'WHERE e.siteID = ? '
        . '  AND COALESCE(e.registrationRetentionDays, ?) > 0 '
        . '  AND GREATEST(COALESCE(e.endDateTime, e.startDateTime), e.startDateTime) '
        . '      < DATE_SUB(NOW(), INTERVAL COALESCE(e.registrationRetentionDays, ?) DAY)';
}

/**
 * 🏢 Every site, with the number of days that site keeps registrations for.
 *
 * A site that has switched the clear-out off is left out of the list entirely,
 * so nothing of its is touched. Sites that are no longer in use are still
 * included: a site being closed down is not a reason to keep a child's medical
 * notes for ever - if anything it is a reason not to.
 *
 * @return array<int, int> The site's identity number, mapped to its number of days.
 */
function registration_sweep_sites(): array
{
    $db  = App::db();
    $out = [];

    $result = $db->query('SELECT siteID FROM tblSites');
    if ($result === false) {
        return $out;
    }

    while ($row = $result->fetch_assoc()) {
        $siteId = (int) $row['siteID'];

        $run = (string) (App::settingForSite('events.registrationRetentionRun', $siteId) ?? 'true');
        if ($run !== 'true') {
            continue;
        }

        $days = (int) (App::settingForSite('events.registrationRetentionDays', $siteId) ?? '90');
        if ($days < 0) {
            // A negative number is meaningless here. Treated as the ordinary
            // default rather than as "keep indefinitely", because somebody
            // typing "-1" has made a mistake, not expressed an intention.
            $days = 90;
        }

        $out[$siteId] = $days;
    }
    $result->free();

    return $out;
}

/**
 * Count rows that WOULD be deleted at the current window.
 *
 * @return array{activity:int,errors:int,registrations:int}
 */
function preview_retention_counts(int $activityDays, int $errorDays): array
{
    $db = App::db();
    $out = ['activity' => 0, 'errors' => 0, 'registrations' => 0];

    $stmt = $db->prepare(
        'SELECT COUNT(*) AS cnt FROM tblActivityLogs '
        . 'WHERE timestamp < DATE_SUB(NOW(), INTERVAL ? DAY)'
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $activityDays);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $out['activity'] = (int) ($row['cnt'] ?? 0);
        $stmt->close();
    }

    $stmt = $db->prepare(
        'SELECT COUNT(*) AS cnt FROM tblErrors '
        . 'WHERE createdAt < DATE_SUB(NOW(), INTERVAL ? DAY)'
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $errorDays);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $out['errors'] = (int) ($row['cnt'] ?? 0);
        $stmt->close();
    }

    // 🧒 Registrations, counted site by site with each site's own setting -
    //    the same rule, and the same WHERE clause, as the delete itself. An
    //    administrator has to be shown the real number BEFORE confirming.
    //    Being told "12 log rows" and then silently losing several thousand
    //    children's registration records would be indefensible.
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS cnt FROM tblEventRegistrations AS r '
        . 'INNER JOIN tblEvents AS e ON e.eventID = r.eventID '
        . registration_sweep_where()
    );
    if ($stmt !== false) {
        foreach (registration_sweep_sites() as $siteId => $days) {
            $stmt->bind_param('iii', $siteId, $days, $days);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $out['registrations'] += (int) ($row['cnt'] ?? 0);
        }
        $stmt->close();
    }

    return $out;
}

/**
 * Perform the actual delete. Returns counts per table.
 *
 * @return array{activityDeleted:int,errorsDeleted:int,registrationsDeleted:int,totalDeleted:int}
 */
function run_retention_sweep(): array
{
    $activityDays = (int) (App::settings('audit.retentionDays')  ?? '365');
    $errorDays    = (int) (App::settings('errors.retentionDays') ?? '365');
    if ($activityDays < 1) { $activityDays = 365; }
    if ($errorDays    < 1) { $errorDays    = 365; }

    $db = App::db();
    $activityDeleted = 0;
    $errorsDeleted   = 0;

    $stmt = $db->prepare(
        'DELETE FROM tblActivityLogs WHERE timestamp < DATE_SUB(NOW(), INTERVAL ? DAY)'
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $activityDays);
        $stmt->execute();
        $activityDeleted = (int) $stmt->affected_rows;
        $stmt->close();
    }

    $stmt = $db->prepare(
        'DELETE FROM tblErrors WHERE createdAt < DATE_SUB(NOW(), INTERVAL ? DAY)'
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $errorDays);
        $stmt->execute();
        $errorsDeleted = (int) $stmt->affected_rows;
        $stmt->close();
    }

    // 🧹 Children's event registrations.
    //
    //    This is the most sensitive clear-out here, and the one that matters
    //    most. Event registrations hold a child's name, date of birth,
    //    allergies and medical notes, together with a parent's telephone number
    //    and email address.
    //
    //    Most are submitted by people with no account - a parent should not
    //    have to create one to bring their child to a holiday club - which
    //    means a "delete everything you hold about me" request cannot reach
    //    them: there is nothing to match a person against. So nobody should
    //    have to ASK. After the event, and a reasonable gap, they simply go.
    //
    //    Two levels, because events genuinely differ. An event may set its own
    //    number of days; otherwise the site's setting applies. A residential
    //    trip may need longer for insurance; a single afternoon may want less.
    //
    //    Zero means keep indefinitely. It has to be set deliberately on an
    //    event, and is never the default - "we kept a child's medical notes for
    //    ever" should not be something that happens by accident.
    //
    //    Repeating events need no special handling, which is worth saying
    //    because it looks as though they should. A registration always points
    //    at one specific event record, and that record carries its own start
    //    and end times. Repetition is described separately, against the SERIES,
    //    and the only thing that reads it is the calendar feed export - which
    //    gathers matching event records up into a single repeating entry purely
    //    for the benefit of somebody's calendar application. Nothing generates
    //    event records from it. So the dates used below always belong to the
    //    very event the registration was made for.
    $registrationsDeleted = 0;

    // One site at a time, deliberately.
    //
    //    The two clear-outs above read one set of settings and then delete
    //    across the whole portal. That is wrong here, and dangerously so. This
    //    page is open to ANY administrator, not only a portal-wide one, and it
    //    can also be run from a scheduled job that has no current site at all.
    //
    //    Read one site's settings and apply them everywhere, and an
    //    administrator of site B who sets "keep for 1 day" would silently
    //    destroy site A's registrations too - records site A believed it was
    //    keeping for 90 days. The reverse is just as bad: site B switching the
    //    clear-out off would leave every other site's children's medical notes
    //    sitting there indefinitely.
    //
    //    So each site's own setting decides, and the delete below is limited to
    //    that same site. A site is never able to reach another site's records.
    $stmt = $db->prepare(
        'DELETE r FROM tblEventRegistrations AS r '
        . 'INNER JOIN tblEvents AS e ON e.eventID = r.eventID '
        . registration_sweep_where()
    );
    if ($stmt !== false) {
        foreach (registration_sweep_sites() as $siteId => $days) {
            $stmt->bind_param('iii', $siteId, $days, $days);
            $stmt->execute();
            $registrationsDeleted += (int) $stmt->affected_rows;
        }
        $stmt->close();
    }

    return [
        'activityDeleted'      => $activityDeleted,
        'errorsDeleted'        => $errorsDeleted,
        'registrationsDeleted' => $registrationsDeleted,
        'totalDeleted'         => $activityDeleted + $errorsDeleted + $registrationsDeleted,
    ];
}
