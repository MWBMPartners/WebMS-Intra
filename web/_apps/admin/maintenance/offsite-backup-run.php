<?php
// Path: public_html/admin/maintenance/offsite-backup-run.php
/**
 * Admin — "Run now" trigger. Shells out to the admin-installed
 * sync-offsite.sh and pipes its stdout/stderr into the log.
 *
 * Synchronous; long-running runs may hit PHP's max_execution_time.
 * For larger archives lean on the weekly cron — this button is for
 * smoke-testing after setup.
 *
 * WHO MAY PRESS THIS BUTTON, AND WHY IT IS A GLOBAL ADMINISTRATOR ONLY
 * -----------------------------------------------------------------------------
 * This runs sync-offsite.sh over the WHOLE installation's backups — every
 * organisation's records, not only the one the person pressing the button
 * belongs to — and writes a row into tblOffsiteSyncLog that is shared by the
 * whole installation too. Until 13 September 2026 the only check was
 * App::isAdmin(), which is true for an administrator of a SINGLE organisation
 * as well as for a global administrator (see web/_core/App.php). So an
 * administrator of one organisation could trigger a sync of every other
 * organisation's data to wherever the destination is configured, and could do
 * it as often as they liked (issue #498, "same shape, found at the same
 * time" as #495).
 *
 * This matches web/_apps/admin/maintenance/backup.php, made global-
 * administrator-only for the same reason on the same day, in all three ways
 * a refusal can be seen:
 *
 *   - the same two checks in the same order: anybody who is not an
 *     administrator at all gets the ordinary "access denied" page, exactly as
 *     before this change; an administrator who is not a global administrator
 *     gets the worded refusal;
 *   - the same HTTP status, 403 ("forbidden"), with the explanation written
 *     into that same response, so it cannot be lost on the way;
 *   - the same rule for the activity log: a refusal is recorded only for a
 *     genuine POST with a valid form token (see the comment on the check).
 *
 * WHAT WAS TRIED FIRST AND WHY IT WAS CHANGED (13 September 2026)
 * -----------------------------------------------------------------------------
 * The first version of this refusal redirected back to
 * /admin/maintenance/offsite-backup with a message stored in the session, and
 * replaced the App::isAdmin() check instead of keeping it. An independent
 * check found three problems with that:
 *
 *   1. The message ended "nothing has been logged", which was untrue: the
 *      same request writes an OffsiteBackupRunRefused row to the activity log.
 *      The message now says only "Nothing has been run", which is true
 *      whether or not a log row was written.
 *   2. With the App::isAdmin() check gone, a member who is not an
 *      administrator at all was redirected to a page that then refused them
 *      with a second 403, and the off-site message sat unread in their
 *      session until some later page displayed it. It also told a plain
 *      member that an off-site copy of every organisation exists, and wrote
 *      a refusal row for them. Keeping App::isAdmin() first restores the
 *      original behaviour for them: a plain 403, nothing in the session,
 *      nothing logged.
 *   3. The brief asked for backup.php's status (403), and a redirect has to
 *      be a 3xx status because a browser ignores a Location header on a 403.
 *      Writing the explanation into the 403 response itself, as backup.php
 *      does, gives both: the right status and a message the person sees.
 *
 * What this CANNOT do: the "Run now" button on offsite-backup.php is still
 * shown to an administrator of one organisation. Pressing it now leads to the
 * refusal page below, with a link back. Hiding the button is a change to that
 * page, which is outside this file.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/249
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/498
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;

Auth::ensureSession();
Auth::requireLogin();

// 🔒 Not an administrator at all: the ordinary "access denied" page, exactly
//    as before 13 September 2026. Kept in front of the check below so that a
//    plain member is not told about the off-site copy, gets nothing left in
//    their session, and does not add a row to the activity log.
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// 🚧 Global administrators only — see "WHO MAY PRESS THIS BUTTON" above. This
//    runs BEFORE the POST/form-token check below (before anything else that
//    could have a side effect), so nobody else ever reaches the part of this
//    file that runs the script or touches tblOffsiteSyncLog.
if (App::isRootAdmin() === false) {
    // 📝 Only a refused ACTION is written to the activity log, and only when
    //    the form token is genuine — exactly backup.php's rule, for the same
    //    reason: a forged cross-site request has no valid token, and logging
    //    every one of those would let a stranger fill the activity log with
    //    noise without ever being signed in as anybody real. Simply loading
    //    a page is never logged; the token is checked once, here, and the
    //    request ends below, so it is never checked a second time for this
    //    branch.
    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && Auth::verifyCsrf($_POST['csrf_token'] ?? '') === true
    ) {
        Logger::activity(
            'OffsiteBackupRunRefused',
            'Refused: off-site sync "Run now" may only be used by a global administrator',
            $_SESSION['user_id'] ?? null
        );
    }

    // 🧾 The explanation is written into this 403 response itself, as
    //    backup.php does, rather than redirecting with a session message —
    //    see "WHAT WAS TRIED FIRST" above. It says "Nothing has been run" and
    //    no more: a refusal row may just have been written to the activity
    //    log, so claiming nothing was recorded would be untrue.
    http_response_code(403);
    $pageTitle   = 'Off-site backup';
    $pageSection = 'admin';
    $breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Maintenance' => '/admin/maintenance', 'Off-site backup' => '/admin/maintenance/offsite-backup', 'Run now' => ''];
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
    ?>
    <h1 class="mb-3"><i class="fa-solid fa-cloud-arrow-up me-2"></i>Off-site backup</h1>
    <div class="alert alert-danger">
        The off-site backup copies every organisation's records on this
        installation, not only yours, to an external destination, so only a
        global administrator can run it. Nothing has been run. You can still
        see the status of the off-site copy on the
        <a href="/admin/maintenance/offsite-backup">Off-site backup page</a>.
    </div>
    <?php
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$script = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_backups' . DIRECTORY_SEPARATOR . 'sync-offsite.sh';
if (is_file($script) === false || is_executable($script) === false) {
    $_SESSION['flash_msg']  = 'sync-offsite.sh not present or not executable.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/maintenance/offsite-backup');
    exit();
}

$adminId = (int) ($_SESSION['user_id'] ?? 0);
$cmd = escapeshellcmd($script) . ' 2>&1';
@set_time_limit(900);
$start = time();
$output = [];
exec($cmd, $output, $exit);
$durationSec = time() - $start;
$captured = implode("\n", $output);

// Also overlay an admin-trigger marker into the log row inserted by the
// script itself, when possible. If the script didn't reach the logger
// (e.g. die before php call), insert a fallback row here.
$db = App::db();
$rs = $db->query('SELECT logID FROM tblOffsiteSyncLog ORDER BY logID DESC LIMIT 1');
$mostRecent = $rs !== false ? ($rs->fetch_assoc() ?? null) : null;
if ($mostRecent !== null && $rs !== false) {
    $rs->free();
}
if ($exit !== 0 && $mostRecent === null) {
    // Script never reached its own logger — record the failure directly.
    $err = 'shell exit ' . $exit;
    $cap = mb_substr($captured, 0, 1000);
    $trig = 'admin-' . $adminId;
    $dest = (string) (App::settings()['backup']['offsite']['destination'] ?? 'unknown');
    $stmt = $db->prepare('INSERT INTO tblOffsiteSyncLog (triggeredBy, destination, status, errorMsg, output, durationSec) VALUES (?, ?, "failed", ?, ?, ?)');
    if ($stmt !== false) {
        $stmt->bind_param('ssssi', $trig, $dest, $err, $cap, $durationSec);
        $stmt->execute();
        $stmt->close();
    }
}

Logger::activity('OffsiteBackupRun', 'Manual off-site sync (exit ' . (int) $exit . ')', $adminId);

if ($exit === 0) {
    $_SESSION['flash_msg']  = 'Off-site sync completed in ' . $durationSec . 's.';
    $_SESSION['flash_type'] = 'success';
} else {
    $_SESSION['flash_msg']  = 'Off-site sync failed (exit ' . (int) $exit . '). Check the run log.';
    $_SESSION['flash_type'] = 'danger';
}
header('Location: /admin/maintenance/offsite-backup');
exit();
