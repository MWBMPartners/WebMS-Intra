<?php
// Path: public_html/admin/maintenance/offsite-backup.php
/**
 * Admin — Off-site backup status + manual trigger.
 *
 * @package   Portal\Admin
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/249
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

$db = App::db();

// POST handler — must run before any output so the redirect is clean.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'save') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        http_response_code(400);
        exit('Bad request');
    }
    $upsert = static function (mysqli $db, string $key, string $value): void {
        $stmt = $db->prepare('SELECT settingID FROM tblSettings WHERE settingKey = ? AND siteID IS NULL LIMIT 1');
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $stmt->bind_result($id);
        $exists = $stmt->fetch() === true;
        $stmt->close();
        if ($exists === true) {
            $u = $db->prepare('UPDATE tblSettings SET settingValue = ?, updatedAt = NOW() WHERE settingID = ?');
            if ($u !== false) {
                $u->bind_param('si', $value, $id);
                $u->execute();
                $u->close();
            }
        } else {
            $u = $db->prepare('INSERT INTO tblSettings (settingKey, settingValue, isSensitive, siteID, updatedAt) VALUES (?, ?, 0, NULL, NOW())');
            if ($u !== false) {
                $u->bind_param('ss', $key, $value);
                $u->execute();
                $u->close();
            }
        }
    };
    $destChoice = (string) ($_POST['destination'] ?? 'rclone');
    if (in_array($destChoice, ['rclone','s3','sftp'], true) === false) {
        $destChoice = 'rclone';
    }
    $upsert($db, 'backup.offsite.enabled',      isset($_POST['enabled']) === true ? '1' : '0');
    $upsert($db, 'backup.offsite.destination',  $destChoice);
    $upsert($db, 'backup.offsite.rcloneRemote', trim((string) ($_POST['rcloneRemote'] ?? '')));
    $upsert($db, 'backup.offsite.keepWeekly',   (string) max(1, min(52, (int) ($_POST['keepWeekly'] ?? 8))));
    $upsert($db, 'backup.offsite.keepMonthly',  (string) max(1, min(60, (int) ($_POST['keepMonthly'] ?? 12))));
    $upsert($db, 'backup.offsite.alertEmail',   trim((string) ($_POST['alertEmail'] ?? '')));
    $_SESSION['flash_msg']  = 'Off-site backup settings saved.';
    $_SESSION['flash_type'] = 'success';
    header('Location: /admin/maintenance/offsite-backup');
    exit();
}

$settings = App::settings()['backup']['offsite'] ?? [];
$enabled  = (string) ($settings['enabled'] ?? '0') === '1';
$dest     = (string) ($settings['destination'] ?? 'rclone');
$remote   = (string) ($settings['rcloneRemote'] ?? '');
$keepW    = (int) ($settings['keepWeekly'] ?? 8);
$keepM    = (int) ($settings['keepMonthly'] ?? 12);
$alertTo  = (string) ($settings['alertEmail'] ?? '');

$scriptPath = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_backups' . DIRECTORY_SEPARATOR . 'sync-offsite.sh';
$scriptExists = is_file($scriptPath);
$keyPath = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_auth_keys' . DIRECTORY_SEPARATOR . 'offsite.key';
$keyExists = is_readable($keyPath);

$recent = [];
$rs = $db->query(
    'SELECT logID, runAt, triggeredBy, destination, snapshotName, bundleSize, durationSec, status, errorMsg '
    . 'FROM tblOffsiteSyncLog ORDER BY runAt DESC LIMIT 20'
);
if ($rs !== false) {
    while ($r = $rs->fetch_assoc()) {
        $recent[] = $r;
    }
    $rs->free();
}

$last = $recent[0] ?? null;

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'Off-site backup';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Maintenance' => '/admin/maintenance', 'Off-site backup' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-cloud-arrow-up me-2"></i>Off-site backup</h1>
<p class="text-secondary">Weekly encrypted copy of the newest snapshot to an external destination. Defends against a DreamHost-side incident wiping `_backups/` along with the portal.</p>

<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card"><div class="card-body">
        <div class="small text-muted">Last sync</div>
        <?php if ($last !== null): ?>
            <div class="h4 mb-0">
                <?php $cls = match ((string) $last['status']) { 'success' => 'success', 'skipped' => 'secondary', default => 'danger' }; ?>
                <span class="badge bg-<?php echo $cls; ?>"><?php echo htmlspecialchars((string) $last['status'], ENT_QUOTES, 'UTF-8'); ?></span>
                <small class="text-muted"><?php echo htmlspecialchars((string) $last['runAt'], ENT_QUOTES, 'UTF-8'); ?></small>
            </div>
        <?php else: ?>
            <div class="h4 mb-0 text-muted">never</div>
        <?php endif; ?>
    </div></div></div>
    <div class="col-md-4"><div class="card"><div class="card-body">
        <div class="small text-muted">Script + key status</div>
        <div>
            sync-offsite.sh: <?php echo $scriptExists === true ? '<span class="badge bg-success">present</span>' : '<span class="badge bg-warning">missing</span>'; ?><br>
            offsite.key: <?php echo $keyExists === true ? '<span class="badge bg-success">present</span>' : '<span class="badge bg-warning">missing</span>'; ?>
        </div>
    </div></div></div>
    <div class="col-md-4"><div class="card"><div class="card-body">
        <div class="small text-muted">Configured</div>
        <div class="h4 mb-0">
            <?php if ($enabled === true): ?>
                <span class="badge bg-success">enabled</span>
            <?php else: ?>
                <span class="badge bg-secondary">disabled</span>
            <?php endif; ?>
        </div>
        <div class="small text-muted"><?php echo htmlspecialchars($dest, ENT_QUOTES, 'UTF-8'); ?></div>
    </div></div></div>
</div>

<?php if ($scriptExists === false || $keyExists === false): ?>
    <div class="card mb-3 border-warning"><div class="card-body">
        <h5><i class="fa-solid fa-triangle-exclamation me-1 text-warning"></i>Setup required</h5>
        <ol class="small">
            <li>Copy the reference scripts from the repo into <code>web/_backups/</code>:<br>
                <code>cp tools/offsite-backup/sync-offsite.sh.example web/_backups/sync-offsite.sh</code><br>
                <code>cp tools/offsite-backup/log-offsite-result.php  web/_backups/log-offsite-result.php</code><br>
                <code>chmod +x web/_backups/sync-offsite.sh</code>
            </li>
            <li>Generate an encryption key (kept OUT of git):<br>
                <code>openssl rand -base64 64 &gt; web/_auth_keys/offsite.key && chmod 600 web/_auth_keys/offsite.key</code>
            </li>
            <li>Edit <code>sync-offsite.sh</code> and set <code>DESTINATION</code> + remote target.</li>
            <li>Schedule via DreamHost web panel: weekly, Sundays 04:00 UTC, command:<br>
                <code>/home/USER/portal/web/_backups/sync-offsite.sh</code>
            </li>
        </ol>
        <p class="small text-muted mb-0">Full instructions in <code>docs/offsite-backup-setup.md</code>.</p>
    </div></div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-header"><strong>Settings</strong></div>
    <div class="card-body">
        <form method="post" action="/admin/maintenance/offsite-backup" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="save">
            <div class="col-md-3">
                <label class="form-label">Destination</label>
                <select class="form-select" name="destination">
                    <?php foreach (['rclone','s3','sftp'] as $d): ?>
                        <option value="<?php echo $d; ?>" <?php echo $dest === $d ? 'selected' : ''; ?>><?php echo $d; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">rclone remote (e.g. b2:portal-backups)</label>
                <input type="text" class="form-control" name="rcloneRemote" value="<?php echo htmlspecialchars($remote, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-1">
                <label class="form-label">Weekly</label>
                <input type="number" min="1" max="52" class="form-control" name="keepWeekly" value="<?php echo $keepW; ?>">
            </div>
            <div class="col-md-1">
                <label class="form-label">Monthly</label>
                <input type="number" min="1" max="60" class="form-control" name="keepMonthly" value="<?php echo $keepM; ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Alert email</label>
                <input type="email" class="form-control" name="alertEmail" value="<?php echo htmlspecialchars($alertTo, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <div class="form-check me-3">
                    <input type="checkbox" class="form-check-input" id="osEnabled" name="enabled" value="1" <?php echo $enabled === true ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="osEnabled">Enabled</label>
                </div>
                <button class="btn btn-primary btn-sm" type="submit">Save</button>
            </div>
        </form>
    </div>
</div>

<?php if ($scriptExists === true && $keyExists === true): ?>
    <form method="post" action="/admin/maintenance/offsite-backup/run" class="mb-3">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <button class="btn btn-warning" type="submit" data-confirm="Run the off-site sync now? This can take several minutes.">
            <i class="fa-solid fa-play me-1"></i>Run now
        </button>
        <span class="small text-muted ms-2">Runs the same script the cron uses, synchronously.</span>
    </form>
<?php endif; ?>

<div class="card">
    <div class="card-header"><strong>Recent runs</strong></div>
    <div class="card-body">
        <?php if (count($recent) === 0): ?>
            <p class="text-muted mb-0">No runs logged yet. Set up the script + key, then trigger manually or wait for cron.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm small">
                    <thead><tr><th>Run</th><th>By</th><th>Snapshot</th><th>Size</th><th>Duration</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($recent as $r):
                            $cls = match ((string) $r['status']) { 'success' => 'success', 'skipped' => 'secondary', default => 'danger' };
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string) $r['runAt'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) $r['triggeredBy'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><code><?php echo htmlspecialchars((string) ($r['snapshotName'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code></td>
                                <td><?php echo $r['bundleSize'] !== null ? round(((int) $r['bundleSize']) / (1024 * 1024), 1) . ' MB' : '—'; ?></td>
                                <td><?php echo $r['durationSec'] !== null ? ((int) $r['durationSec']) . 's' : '—'; ?></td>
                                <td><span class="badge bg-<?php echo $cls; ?>"><?php echo htmlspecialchars((string) $r['status'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php if (($r['errorMsg'] ?? '') !== ''): ?>
                                        <div class="small text-muted"><?php echo htmlspecialchars((string) $r['errorMsg'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
