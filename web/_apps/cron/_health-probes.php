<?php
// Path: _apps/cron/_health-probes.php
/**
 * -----------------------------------------------------------------------------
 * Shared — System Health probes (the checks both callers run) 🩺
 * -----------------------------------------------------------------------------
 * NOT a page. No address points at this file; it is only ever loaded with
 * require_once by:
 *
 *   - web/_apps/admin/maintenance/health.php — the staff page, which draws a
 *     card for each check;
 *   - web/_apps/cron/health.php — the address for uptime monitors,
 *     /cron/health?token=…, which returns the same checks as JSON.
 *
 * WHY THIS FILE EXISTS
 * The monitor used to call a "?cron=1" mode of the staff page itself. Once the
 * Router started enforcing sign-in (issue #497) that could not work: the staff
 * page is a protected address, so a monitor with a token and no session would
 * be redirected to the sign-in page. Worse, a redirect is not an error to most
 * monitors, so nobody would have been told. The monitor address therefore
 * moved to /cron/health, and the checks moved here so the page and the monitor
 * can never report different things.
 *
 * The checks below are moved unchanged from the staff page; only the
 * indentation changed, because they now sit inside a function.
 *
 * WHAT THIS FILE CANNOT DO
 * No permission check. Each caller decides who may see the result.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/228
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/497
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\DbBackup;

/**
 * Run every health check.
 *
 * @param \mysqli $db The portal's database connection.
 *
 * @return array{overall: string, probes: array<string, array{state: string, label: string, detail: string}>}
 *         overall is 'ok', 'warn' or 'crit' — the worst state of any check.
 */
function maintenance_health_probes(\mysqli $db): array
{
    // 🧪 Probes — each returns ['state' => 'ok|warn|crit', 'label' => '', 'detail' => '']
    $probes = [];

    // 1. Database
    //    Two separate things are being reported here, and they used to be muddled
    //    together. "Can we reach the database at all?" is the health question. "Is
    //    the version we reached still supported?" is a different question, and this
    //    page used to answer it by printing the word "MySQL" in front of whatever
    //    version string came back — which was simply wrong on a MariaDB server, and
    //    said nothing about whether the version still receives security fixes.
    //
    //    Portal\Core\DbServer answers the second question, and is the SAME code the
    //    installation wizard and the Server Information page use, so all three
    //    always agree.
    //    On the traffic light itself, note what this probe does NOT do. A database
    //    that is past its security-fix date is worth knowing about, but it is not
    //    an incident: it will be equally true tomorrow and every day after. This
    //    page is polled by uptime monitors, so turning it amber for that would mean
    //    a permanent alert that everyone quickly learns to ignore — and a real
    //    problem arriving later would land in a warning nobody reads any more.
    //
    //    So the light stays green while the database is reachable, and the version
    //    and its support position are reported in the text beside it. The one
    //    exception is a database too old to run this portal properly, which is a
    //    real fault happening right now and does turn the light amber.
    try {
        // 🩺 Prove the connection actually works BEFORE asking what version it is.
        //
        //    This order matters and was got wrong once. DbServer::inspect() is
        //    deliberately forgiving: if a query fails it falls back to the version
        //    string the driver recorded when the connection was first opened, so it
        //    returns a sensible-looking answer even from a connection that has since
        //    died. Calling it first therefore turned a dead database into a green
        //    "Connected" light — the exact opposite of what this page is for.
        //
        //    A plain 'SELECT 1' has no such fallback. If the database is not there,
        //    this throws, and the catch below reports it as a real fault.
        $probe = $db->query('SELECT 1');
        if ($probe !== false) {
            $probe->free();
        }

        $dbInfo = \Portal\Core\DbServer::inspect($db);

        // 📣 Show DbServer's OWN wording rather than writing a shorter version here.
        //    That matters more than it looks. DbServer answers 'ok' both for a
        //    version it knows is supported AND for one newer than anything it has
        //    been told about — and in the second case its wording deliberately says
        //    it cannot vouch for the support position. Rewriting every 'ok' as "a
        //    supported version" here threw that distinction away and put back the
        //    false reassurance the whole class exists to remove.
        $probes['Database'] = [
            'state'  => $dbInfo['state'] === 'crit' ? 'warn' : 'ok',
            'label'  => 'Connected',
            'detail' => $dbInfo['state'] === 'ok'
                ? (string) $dbInfo['headline']
                : (string) $dbInfo['headline'] . ' — see Admin → Server Information',
        ];
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

    // 5. Sessions — PHP native sessions, count files in the configured save_path
    $sessSavePath = (string) (session_save_path() ?: sys_get_temp_dir());
    $sessCount = 0;
    $sessDetail = '';
    if (is_dir($sessSavePath) === true && is_readable($sessSavePath) === true) {
        $entries = @scandir($sessSavePath);
        if (is_array($entries) === true) {
            // PHP names session files sess_<id>; count files modified in last 30 min.
            $cutoff = time() - (30 * 60);
            foreach ($entries as $e) {
                if (str_starts_with($e, 'sess_') === false) {
                    continue;
                }
                $mt = @filemtime($sessSavePath . DIRECTORY_SEPARATOR . $e);
                if ($mt !== false && $mt >= $cutoff) {
                    $sessCount++;
                }
            }
            $sessDetail = sprintf('Files in %s (last 30 min)', $sessSavePath);
        } else {
            $sessDetail = 'save_path not readable';
        }
    } else {
        $sessDetail = 'save_path unreadable: ' . $sessSavePath;
    }
    $probes['Active sessions'] = ['state' => 'ok', 'label' => sprintf('%d', $sessCount), 'detail' => $sessDetail];

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

    return ['overall' => $overall, 'probes' => $probes];
}
