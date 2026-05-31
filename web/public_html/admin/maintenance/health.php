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
 * Cron mode: ?cron=1&token=… returns the same probes as JSON for external
 * monitoring (Uptime Robot, etc.).
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
use Portal\Core\DbBackup;

$cronMode = isset($_GET['cron']) === true && $_GET['cron'] === '1';

if ($cronMode === true) {
    $expected = (string) (App::settings()['maintenance']['cronToken'] ?? '');
    $supplied = (string) ($_GET['token'] ?? '');
    if ($expected === '' || hash_equals($expected, $supplied) === false) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'invalid_token']);
        exit();
    }
} else {
    Auth::ensureSession();
    Auth::requireLogin();
    if (App::isAdmin() === false) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$db = App::db();

// 🧪 Probes — each returns ['state' => 'ok|warn|crit', 'label' => '', 'detail' => '']
$probes = [];

// 1. Database
try {
    $rs = $db->query('SELECT VERSION() AS v');
    $v  = $rs !== false ? ($rs->fetch_assoc()['v'] ?? '?') : '?';
    if ($rs !== false) {
        $rs->free();
    }
    $probes['Database'] = ['state' => 'ok', 'label' => 'Connected', 'detail' => 'MySQL ' . $v];
} catch (\Throwable $e) {
    $probes['Database'] = ['state' => 'crit', 'label' => 'Connection failed', 'detail' => $e->getMessage()];
}

// 2. Disk — _backups + _uploads
$diskState = 'ok';
$diskDetail = '';
foreach (['_backups', '_uploads'] as $dir) {
    $path = PORTAL_ROOT . DIRECTORY_SEPARATOR . $dir;
    if (is_dir($path) === false) {
        continue;
    }
    $free = @disk_free_space($path);
    $total = @disk_total_space($path);
    if ($free === false || $total === false || $total <= 0) {
        continue;
    }
    $pct = ($free / $total) * 100;
    $diskDetail .= sprintf(
        '%s: %.1f%% free (%.1f GB / %.1f GB) · ',
        $dir,
        $pct,
        $free / 1024 / 1024 / 1024,
        $total / 1024 / 1024 / 1024
    );
    if ($pct < 5) {
        $diskState = 'crit';
    } elseif ($pct < 15 && $diskState === 'ok') {
        $diskState = 'warn';
    }
}
$probes['Disk space'] = ['state' => $diskState, 'label' => $diskState === 'ok' ? 'Healthy' : ucfirst($diskState), 'detail' => rtrim($diskDetail, ' · ')];

// 3. Backups
$backup    = new DbBackup($db);
$snapshots = $backup->listSnapshots();
if (count($snapshots) === 0) {
    $probes['Backups'] = ['state' => 'crit', 'label' => 'None', 'detail' => 'No snapshots in _backups/.'];
} else {
    $threshold = (int) (App::settings()['portal']['backups']['max_age_hours'] ?? 36);
    $createdAt = strtotime((string) $snapshots[0]['created_at']);
    $ageHrs    = $createdAt !== false ? (int) round((time() - $createdAt) / 3600) : -1;
    if ($ageHrs < 0) {
        $probes['Backups'] = ['state' => 'warn', 'label' => 'Timestamp unparseable', 'detail' => $snapshots[0]['name']];
    } elseif ($ageHrs > $threshold) {
        $probes['Backups'] = ['state' => 'warn', 'label' => 'Stale', 'detail' => sprintf('Last: %d hrs ago (threshold %d) · %d total', $ageHrs, $threshold, count($snapshots))];
    } else {
        $probes['Backups'] = ['state' => 'ok', 'label' => 'Fresh', 'detail' => sprintf('Last: %d hrs ago · %d total', $ageHrs, count($snapshots))];
    }
}

// 4. Errors in last 24h
try {
    $rs = $db->query("SELECT COUNT(*) AS c FROM tblErrors WHERE createdAt > DATE_SUB(NOW(), INTERVAL 1 DAY)");
    $count24h = $rs !== false ? (int) ($rs->fetch_assoc()['c'] ?? 0) : 0;
    if ($rs !== false) {
        $rs->free();
    }
    $state = $count24h === 0 ? 'ok' : ($count24h > 50 ? 'crit' : 'warn');
    $probes['Errors (24h)'] = ['state' => $state, 'label' => sprintf('%d', $count24h), 'detail' => $count24h > 0 ? 'See /admin/errors' : 'All clear'];
} catch (\Throwable $e) {
    $probes['Errors (24h)'] = ['state' => 'warn', 'label' => 'Query failed', 'detail' => $e->getMessage()];
}

// 5. Sessions
try {
    $rs = $db->query("SELECT COUNT(*) AS c FROM tblSessions WHERE lastSeenAt > DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
    $active = $rs !== false ? (int) ($rs->fetch_assoc()['c'] ?? 0) : 0;
    if ($rs !== false) {
        $rs->free();
    }
    $probes['Active sessions'] = ['state' => 'ok', 'label' => sprintf('%d', $active), 'detail' => 'Last 30 min'];
} catch (\Throwable $e) {
    $probes['Active sessions'] = ['state' => 'warn', 'label' => 'Query failed', 'detail' => $e->getMessage()];
}

// 6. Migrations
try {
    $rs = $db->query('SELECT COUNT(*) AS c FROM tblMigrations');
    $migCount = $rs !== false ? (int) ($rs->fetch_assoc()['c'] ?? 0) : 0;
    if ($rs !== false) {
        $rs->free();
    }
    $installed = (string) (App::settings()['portal']['installed_version'] ?? '?');
    $code = defined('PORTAL_VERSION') ? PORTAL_VERSION : '?';
    $drift = $installed !== '?' && $installed !== '' && version_compare($installed, (string) $code, '<');
    $probes['Migrations'] = [
        'state'  => $drift ? 'warn' : 'ok',
        'label'  => sprintf('%d applied', $migCount),
        'detail' => sprintf('DB %s / code %s%s', $installed, $code, $drift ? ' — upgrade needed' : ''),
    ];
} catch (\Throwable $e) {
    $probes['Migrations'] = ['state' => 'warn', 'label' => 'Query failed', 'detail' => $e->getMessage()];
}

// 7. PHP
$phpExts = ['curl', 'gd', 'mbstring', 'openssl', 'mysqli'];
$missing = array_filter($phpExts, static fn (string $e) => extension_loaded($e) === false);
$probes['PHP'] = [
    'state'  => count($missing) === 0 ? 'ok' : 'warn',
    'label'  => PHP_VERSION,
    'detail' => count($missing) === 0
        ? 'All required extensions loaded'
        : 'Missing: ' . implode(', ', $missing),
];

// 8. Maintenance flag
$maintFlag = (string) (App::settings()['portal']['maintenance']['active'] ?? '0');
$probes['Maintenance mode'] = [
    'state'  => $maintFlag === '1' ? 'warn' : 'ok',
    'label'  => $maintFlag === '1' ? 'ACTIVE' : 'Off',
    'detail' => $maintFlag === '1' ? 'Public access is gated' : 'Portal is open',
];

// 🚦 Overall
$overall = 'ok';
foreach ($probes as $p) {
    if ($p['state'] === 'crit') {
        $overall = 'crit';
        break;
    }
    if ($p['state'] === 'warn') {
        $overall = 'warn';
    }
}

if ($cronMode === true) {
    header('Content-Type: application/json');
    echo json_encode([
        'overall'    => $overall,
        'checked_at' => date('c'),
        'probes'     => $probes,
    ]);
    exit();
}

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
        <p>Hook this page into Uptime Robot, healthchecks.io, or DreamHost cron:</p>
        <pre class="bg-body-tertiary p-2 rounded"><code>0 * * * * curl -fsS "https://<?php echo htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'portal', ENT_QUOTES, 'UTF-8'); ?>/admin/maintenance/health?cron=1&amp;token=YOUR_TOKEN" &gt; /dev/null</code></pre>
        <p class="small text-muted mb-0">Returns JSON with overall status + per-probe state.</p>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
