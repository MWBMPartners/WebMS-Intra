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
 * @package   Portal\Admin
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/249
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
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
