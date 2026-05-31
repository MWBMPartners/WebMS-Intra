<?php
// Path: public_html/admin/maintenance/backup.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Backup Management UI 📦
 * -----------------------------------------------------------------------------
 * List / inspect / run / restore / delete JSON snapshots created by
 * Portal\Core\DbBackup. Supports per-table restore (TRUNCATE + re-INSERT
 * inside a transaction) and full-snapshot restore (every table in the
 * manifest, sequentially, all inside one outer transaction).
 *
 * Actions:
 *   GET  /admin/maintenance/backup                     — list
 *   GET  /admin/maintenance/backup?inspect=NAME        — show manifest detail
 *   POST action=snapshot_now                           — DbBackup::snapshot('manual')
 *   POST action=restore_table  name=NAME table=TABLE   — per-table restore
 *   POST action=restore_full   name=NAME               — restore all tables
 *   POST action=delete         name=NAME               — recursive delete
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/227
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\DbBackup;
use Portal\Core\Maintenance;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

$db     = App::db();
$backup = new DbBackup($db);
$flash  = '';
$flashType = 'info';

// 🛠️ Action handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $flash = 'Invalid CSRF token.';
        $flashType = 'danger';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'snapshot_now') {
            $result = $backup->snapshot('manual');
            if ($result['success'] === true) {
                $flash = sprintf(
                    'Snapshot created: %s (%d tables, %d rows).',
                    basename($result['directory']),
                    $result['tables'],
                    $result['rows']
                );
                $flashType = 'success';
            } else {
                $flash = 'Snapshot failed: ' . ($result['error'] ?? 'unknown');
                $flashType = 'danger';
            }
        } elseif ($action === 'restore_table') {
            $name  = (string) ($_POST['name']  ?? '');
            $table = (string) ($_POST['table'] ?? '');
            if ($name === '' || $table === '') {
                $flash = 'Missing snapshot name or table.';
                $flashType = 'danger';
            } else {
                Maintenance::setActive(
                    true,
                    sprintf('Restoring table %s from snapshot %s.', $table, $name)
                );
                $path = rtrim(PORTAL_ROOT, DIRECTORY_SEPARATOR)
                      . DIRECTORY_SEPARATOR . '_backups'
                      . DIRECTORY_SEPARATOR . $name;
                $result = $backup->restoreTable($path, $table);
                Maintenance::setActive(false);
                if ($result['success'] === true) {
                    $flash = sprintf(
                        'Restored %s — %d rows reinserted.',
                        $table,
                        $result['rows_restored']
                    );
                    $flashType = 'success';
                } else {
                    $flash = 'Restore failed: ' . ($result['error'] ?? 'unknown');
                    $flashType = 'danger';
                }
            }
        } elseif ($action === 'restore_full') {
            $name = (string) ($_POST['name'] ?? '');
            if ($name === '') {
                $flash = 'Missing snapshot name.';
                $flashType = 'danger';
            } else {
                Maintenance::setActive(
                    true,
                    sprintf('Full restore from snapshot %s in progress.', $name)
                );
                $path = rtrim(PORTAL_ROOT, DIRECTORY_SEPARATOR)
                      . DIRECTORY_SEPARATOR . '_backups'
                      . DIRECTORY_SEPARATOR . $name;
                $manifestPath = $path . DIRECTORY_SEPARATOR . '_manifest.json';
                $manifest = is_readable($manifestPath) === true
                    ? json_decode((string) file_get_contents($manifestPath), true)
                    : null;
                $errors = [];
                $okCount = 0;
                if (is_array($manifest) === true && isset($manifest['tables']) === true) {
                    foreach (array_keys((array) $manifest['tables']) as $t) {
                        $r = $backup->restoreTable($path, (string) $t);
                        if ($r['success'] === true) {
                            $okCount++;
                        } else {
                            $errors[] = $t . ': ' . ($r['error'] ?? 'unknown');
                        }
                    }
                } else {
                    $errors[] = 'Manifest missing or unreadable.';
                }
                Maintenance::setActive(false);
                if (count($errors) === 0) {
                    $flash = sprintf('Full restore complete — %d tables restored.', $okCount);
                    $flashType = 'success';
                } else {
                    $flash = sprintf(
                        '%d tables restored; %d errors: %s',
                        $okCount,
                        count($errors),
                        implode('; ', array_slice($errors, 0, 3))
                    );
                    $flashType = 'warning';
                }
            }
        } elseif ($action === 'delete') {
            $name = (string) ($_POST['name'] ?? '');
            if ($name === '' || str_contains($name, '/') || str_contains($name, '..')) {
                $flash = 'Invalid snapshot name.';
                $flashType = 'danger';
            } else {
                $path = rtrim(PORTAL_ROOT, DIRECTORY_SEPARATOR)
                      . DIRECTORY_SEPARATOR . '_backups'
                      . DIRECTORY_SEPARATOR . $name;
                // 🪞 Use prune(0) via a temp instance scoped to just-this-snapshot
                //    — simpler: inline a recursive delete via a small helper since
                //    DbBackup::prune() trims by count, not name. Manual approach:
                $ok = (function (string $dir): bool {
                    if (is_dir($dir) === false) {
                        return false;
                    }
                    $entries = scandir($dir);
                    if ($entries === false) {
                        return false;
                    }
                    foreach ($entries as $e) {
                        if ($e === '.' || $e === '..') {
                            continue;
                        }
                        $p = $dir . DIRECTORY_SEPARATOR . $e;
                        if (is_dir($p) === true) {
                            return false;
                        }
                        if (unlink($p) === false) {
                            return false;
                        }
                    }
                    return rmdir($dir);
                })($path);
                if ($ok === true) {
                    $flash = sprintf('Snapshot %s deleted.', $name);
                    $flashType = 'success';
                } else {
                    $flash = sprintf('Could not delete %s.', $name);
                    $flashType = 'danger';
                }
            }
        }
    }
}

$snapshots = $backup->listSnapshots();

// 🔍 Inspect mode
$inspect = (string) ($_GET['inspect'] ?? '');
$inspectManifest = null;
if ($inspect !== '' && preg_match('/^[A-Za-z0-9_\-]+$/', $inspect) === 1) {
    $manifestPath = rtrim(PORTAL_ROOT, DIRECTORY_SEPARATOR)
                  . DIRECTORY_SEPARATOR . '_backups'
                  . DIRECTORY_SEPARATOR . $inspect
                  . DIRECTORY_SEPARATOR . '_manifest.json';
    if (is_readable($manifestPath) === true) {
        $inspectManifest = json_decode((string) file_get_contents($manifestPath), true);
    }
}

$pageTitle   = 'Backup Management';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Maintenance' => '/admin/maintenance', 'Backups' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-database me-2"></i>Backup Management</h1>
        <p class="text-secondary mb-0">JSON snapshots of every <code>tbl*</code> table in <code>web/_backups/</code>.</p>
    </div>
    <div>
        <a href="/admin/maintenance/backup-check" class="btn btn-outline-secondary btn-sm me-1">Freshness check</a>
        <a href="/admin/maintenance" class="btn btn-outline-secondary btn-sm">&larr; Maintenance</a>
    </div>
</div>

<?php if ($flash !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<form method="post" class="mb-4 d-inline-block">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="action" value="snapshot_now">
    <button type="submit" class="btn btn-primary">
        <i class="fa-solid fa-camera me-1"></i> Run snapshot now
    </button>
</form>

<?php if ($inspectManifest !== null): ?>
    <div class="card mb-4 border-primary">
        <div class="card-body">
            <h2 class="h5"><i class="fa-solid fa-magnifying-glass me-1"></i>Inspecting: <?php echo htmlspecialchars($inspect, ENT_QUOTES, 'UTF-8'); ?></h2>
            <p class="text-muted small">Created <?php echo htmlspecialchars((string) ($inspectManifest['created_at'] ?? '?'), ENT_QUOTES, 'UTF-8'); ?> · reason: <?php echo htmlspecialchars((string) ($inspectManifest['reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
            <form method="post" class="mb-3" data-confirm="Restore ALL tables from this snapshot? This will TRUNCATE every table in the manifest and re-INSERT rows from the JSON. Maintenance mode will activate during the restore." data-confirm-destructive="true" data-confirm-confirm-label="Restore all">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="restore_full">
                <input type="hidden" name="name" value="<?php echo htmlspecialchars($inspect, ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="btn btn-danger btn-sm">
                    <i class="fa-solid fa-rotate-left me-1"></i> Restore ALL tables
                </button>
            </form>
            <h3 class="h6">Per-table</h3>
            <div class="portal-data-list">
                <?php foreach ((array) ($inspectManifest['tables'] ?? []) as $tableName => $meta): ?>
                    <div class="row py-1 align-items-center border-bottom">
                        <div class="col-md-4"><code><?php echo htmlspecialchars((string) $tableName, ENT_QUOTES, 'UTF-8'); ?></code></div>
                        <div class="col-md-2"><?php echo (int) ($meta['rows'] ?? 0); ?> rows</div>
                        <div class="col-md-3 small text-muted"><?php echo htmlspecialchars(substr((string) ($meta['sha256'] ?? ''), 0, 12), ENT_QUOTES, 'UTF-8'); ?>…</div>
                        <div class="col-md-3 text-end">
                            <form method="post" class="d-inline"
      data-confirm="Restore only <?php echo htmlspecialchars((string) $tableName, ENT_QUOTES, 'UTF-8'); ?>? Table will be TRUNCATEd and rows re-INSERTed."
      data-confirm-destructive="true">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="restore_table">
                                <input type="hidden" name="name" value="<?php echo htmlspecialchars($inspect, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="table" value="<?php echo htmlspecialchars((string) $tableName, ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" class="btn btn-outline-warning btn-sm">Restore</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <h2 class="h5">Snapshots</h2>
        <?php if (count($snapshots) === 0): ?>
            <p class="text-muted mb-0">No snapshots yet. Click "Run snapshot now" to create the first one.</p>
        <?php else: ?>
            <div class="portal-data-list">
                <?php foreach ($snapshots as $snap): ?>
                    <div class="row py-2 align-items-center border-bottom">
                        <div class="col-md-4"><code><?php echo htmlspecialchars($snap['name'], ENT_QUOTES, 'UTF-8'); ?></code></div>
                        <div class="col-md-3"><?php echo htmlspecialchars($snap['created_at'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-1"><?php echo (int) $snap['tables']; ?> tbl</div>
                        <div class="col-md-2"><?php echo number_format((int) $snap['rows']); ?> rows</div>
                        <div class="col-md-2 text-end">
                            <a href="?inspect=<?php echo urlencode($snap['name']); ?>" class="btn btn-outline-primary btn-sm">Inspect</a>
                            <form method="post" class="d-inline" data-confirm="Delete this snapshot? Cannot be undone." data-confirm-destructive="true">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="name" value="<?php echo htmlspecialchars($snap['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
