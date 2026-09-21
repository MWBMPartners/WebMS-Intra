<?php
// Path: _apps/admin/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin Dashboard 🛡️
 * -----------------------------------------------------------------------------
 * Central admin hub showing summary cards for key system metrics:
 *   - Recent errors count
 *   - Active users count
 *   - Recent activity entries
 *   - Pending migrations
 *   - System information (PHP version, DB version, environment)
 *
 * Only accessible to users with isAdmin=1 or isRootAdmin=1.
 *
 * WHAT WAS WRONG BEFORE (issue #522, fixed 20 September 2026)
 * -------------------------------------------------------------
 * On a portal with more than one organisation, every one of the five counts
 * below queried the WHOLE installation with no organisation condition at all
 * — so an administrator of organisation A, looking at their own dashboard, saw
 * organisation B's errors, activity and members mixed in with their own, with
 * nothing on screen to say so. `admin/errors/index.php` and
 * `admin/activity/index.php` already scope a non-umbrella administrator to
 * their own organisation; this page simply never learned to.
 *
 * THE FIX
 * -------
 * On a SINGLE-organisation portal the installation IS the organisation, so
 * nothing changes — the unscoped queries are already correct and scoping them
 * "for tidiness" would be actively wrong: `tblErrors.siteID` and
 * `tblActivityLogs.siteID` are NULL for pre-bootstrap rows and for
 * AccountGuard's own security records, and adding `siteID = ?` would silently
 * drop those from the only administrator who exists to see them.
 *
 * On a MULTI-organisation portal every card counts the OPEN organisation, the
 * card subtitles say which organisation that is, and — only for a global
 * ("umbrella") administrator, so the two figures can never be confused — a
 * small "All organisations" line appears underneath using today's old,
 * unscoped queries. `App::isUmbrellaAdmin()` is used (not `App::isRootAdmin()`)
 * because that is the same word `errors/index.php` and `activity/index.php`
 * already use for the same distinction, so all three pages agree on who is
 * "global" here.
 *
 * WHY NOT `AccountGuard::memberScopeSql()`
 * -----------------------------------------
 * That helper deliberately returns an EMPTY scope for a global administrator
 * (so they can see everyone when the page is asking "may this administrator
 * reach this account"). Used here it would put installation-wide numbers
 * under one organisation's name on the global administrator's own dashboard —
 * exactly the mislabelling #522 says must not happen. So this page writes its
 * own `siteID = ?` conditions instead of reusing that helper.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.5.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/522
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\AccountGuard;
use Portal\Core\App;
use Portal\Core\ReservedKeys;
use Portal\Core\Router;
use Portal\Core\Site;

// 📌 Page metadata for the template system
$pageTitle   = 'Admin Dashboard';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => ''];

// 🛡️ Admin access check
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// -----------------------------------------------------------------------------
// 🌐 Which organisation this dashboard is about, and who is looking at it.
//    Read once, at the top, because every card below depends on both.
// -----------------------------------------------------------------------------
$siteId     = Site::id();
$singleOrg  = AccountGuard::isSingleOrganisation();
$isUmbrella = App::isUmbrellaAdmin();
$orgName    = (string) (Site::branding('name') ?? '');

// -----------------------------------------------------------------------------
// 📊 Gather summary data for dashboard cards
// -----------------------------------------------------------------------------

// 🔴 Recent errors (last 24 hours) — this organisation on a multi-org portal,
//    the whole installation on a single-org one (see the file header).
$errorCount24h = 0;
if ($singleOrg === true) {
    $stmt = $mysqli->prepare(
        'SELECT COUNT(*) AS cnt FROM tblErrors WHERE createdAt >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
    );
} else {
    $stmt = $mysqli->prepare(
        'SELECT COUNT(*) AS cnt FROM tblErrors WHERE siteID = ? AND createdAt >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
    );
}
if ($stmt !== false) {
    if ($singleOrg === false) {
        $stmt->bind_param('i', $siteId);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $errorCount24h = (int) ($row['cnt'] ?? 0);
    $stmt->close();
}

// 🔴 Total errors
$errorCountTotal = 0;
if ($singleOrg === true) {
    $stmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM tblErrors');
} else {
    $stmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM tblErrors WHERE siteID = ?');
}
if ($stmt !== false) {
    if ($singleOrg === false) {
        $stmt->bind_param('i', $siteId);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $errorCountTotal = (int) ($row['cnt'] ?? 0);
    $stmt->close();
}

// 👥 Active members — on a multi-org portal, an account with an ACTIVE
//    membership row for THIS organisation; on a single-org portal, every
//    active account on the installation (unchanged from before #522).
$userCountActive = 0;
if ($singleOrg === true) {
    $stmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM tblUsers WHERE isActive = 1');
} else {
    $stmt = $mysqli->prepare(
        'SELECT COUNT(*) AS cnt FROM tblUsers u WHERE u.isActive = 1 AND EXISTS ('
        . 'SELECT 1 FROM tblUserSites us WHERE us.userID = u.userID AND us.siteID = ? AND us.isActive = 1)'
    );
}
if ($stmt !== false) {
    if ($singleOrg === false) {
        $stmt->bind_param('i', $siteId);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $userCountActive = (int) ($row['cnt'] ?? 0);
    $stmt->close();
}

// 👥 Total members — "everyone who has ever belonged here" on a multi-org
//    portal (any membership row, active or ended), every account on the
//    installation on a single-org one.
$userCountTotal = 0;
if ($singleOrg === true) {
    $stmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM tblUsers');
} else {
    $stmt = $mysqli->prepare(
        'SELECT COUNT(*) AS cnt FROM tblUsers u WHERE EXISTS ('
        . 'SELECT 1 FROM tblUserSites us WHERE us.userID = u.userID AND us.siteID = ?)'
    );
}
if ($stmt !== false) {
    if ($singleOrg === false) {
        $stmt->bind_param('i', $siteId);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $userCountTotal = (int) ($row['cnt'] ?? 0);
    $stmt->close();
}

// 📋 Recent activity (last 24 hours)
$activityCount24h = 0;
if ($singleOrg === true) {
    $stmt = $mysqli->prepare(
        'SELECT COUNT(*) AS cnt FROM tblActivityLogs WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
    );
} else {
    $stmt = $mysqli->prepare(
        'SELECT COUNT(*) AS cnt FROM tblActivityLogs WHERE siteID = ? AND timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
    );
}
if ($stmt !== false) {
    if ($singleOrg === false) {
        $stmt->bind_param('i', $siteId);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $activityCount24h = (int) ($row['cnt'] ?? 0);
    $stmt->close();
}

// -----------------------------------------------------------------------------
// 🌍 "All organisations" figures — a GLOBAL administrator on a MULTI-org
//    portal only. Run only when they can matter, so a scoped card is never
//    even tempted to fall back to these by accident.
// -----------------------------------------------------------------------------
$allOrgErrors24h   = null;
$allOrgErrorsTotal = null;
$allOrgActive       = null;
$allOrgTotal        = null;
$allOrgActivity24h  = null;
if ($isUmbrella === true && $singleOrg === false) {
    $stmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM tblErrors WHERE createdAt >= DATE_SUB(NOW(), INTERVAL 24 HOUR)');
    if ($stmt !== false) {
        $stmt->execute();
        $allOrgErrors24h = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmt->close();
    }
    $stmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM tblErrors');
    if ($stmt !== false) {
        $stmt->execute();
        $allOrgErrorsTotal = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmt->close();
    }
    $stmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM tblUsers WHERE isActive = 1');
    if ($stmt !== false) {
        $stmt->execute();
        $allOrgActive = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmt->close();
    }
    $stmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM tblUsers');
    if ($stmt !== false) {
        $stmt->execute();
        $allOrgTotal = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmt->close();
    }
    $stmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM tblActivityLogs WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)');
    if ($stmt !== false) {
        $stmt->execute();
        $allOrgActivity24h = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmt->close();
    }
}

// 🔧 Pending migrations count
$pendingMigrations = 0;
$appliedMigrations = [];
$stmt = $mysqli->prepare('SELECT filename AS migrationFile FROM tblMigrations');
if ($stmt !== false) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $appliedMigrations[] = $r['migrationFile'];
    }
    $stmt->close();
}

// 📂 Count SQL files in the migrations directory
$sqlDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_sql';
$sqlFiles = [];
if (is_dir($sqlDir) === true) {
    $scan = scandir($sqlDir);
    if ($scan !== false) {
        foreach ($scan as $file) {
            if (str_ends_with($file, '.sql') === true && $file !== 'full_schema.sql') {
                $sqlFiles[] = $file;
            }
        }
    }
}
$pendingMigrations = 0;
foreach ($sqlFiles as $file) {
    if (in_array($file, $appliedMigrations, true) === false) {
        $pendingMigrations++;
    }
}

// 🖥️ System info
//    The database line used to read the raw version string straight off the
//    connection and print it with the word "MySQL" in front. That was wrong on
//    a MariaDB server, and it said nothing about whether the version still
//    receives security fixes. Portal\Core\DbServer answers both, and is the
//    same code the installation wizard, the health page and the Server
//    Information page use — so all four always agree.
$phpVersion = PHP_VERSION;
$dbInfo     = \Portal\Core\DbServer::inspect($mysqli);
$dbVersion  = trim($dbInfo['engine'] . ' ' . $dbInfo['version']);
$portalEnv  = PORTAL_ENV ?? 'production';

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 🛡️ Admin Dashboard -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-shield-halved me-2"></i>Admin Dashboard</h1>
</div>

<?php
// 🔒 #515 — warn staff when an organisation's key is the same as an address
// the portal itself relies on (Portal\Core\ReservedKeys explains what
// "reserved" means and why an existing clash is left working rather than
// renamed automatically). Shown in EVERY detection mode, not only when
// address prefixes are actually in use — this alert is advice for the one
// person who can act on it, not a monitor light, so it is useful even
// before a portal ever switches multisite on. A failure here must NEVER
// break this page (#508 was exactly this shape of fault — an admin page
// crashing part way through drawing itself) — any exception simply leaves
// the alert absent, same as "nothing is reserved".
$keyClashes = [];
try {
    $keyClashes = ReservedKeys::clashingSites($mysqli);
} catch (\Throwable) {
    $keyClashes = [];
}
// 🔭 A site administrator (not a global one) only ever sees their OWN
// organisation's figures on this page — the #522 scoping this file already
// applies to every card below — so only their own organisation's clash, if
// it has one, belongs here too. A global administrator sees every clash,
// because only a global administrator can do anything about any of them.
if ($isUmbrella === false) {
    $keyClashes = array_values(array_filter(
        $keyClashes,
        static fn (array $clash): bool => $clash['siteID'] === $siteId
    ));
}
$keysLiveNow = ReservedKeys::keysAreAddresses();
?>
<?php if ($keyClashes !== []): ?>
<div class="alert alert-warning" role="alert">
    <h2 class="h6 alert-heading mb-2"><i class="fa-solid fa-triangle-exclamation me-1"></i> An organisation key is reserved</h2>
    <?php foreach ($keyClashes as $clash): ?>
    <?php $clashKey = htmlspecialchars($clash['siteKey'], ENT_QUOTES, 'UTF-8'); ?>
    <p class="mb-1 small">
        <strong><?php echo htmlspecialchars($clash['siteName'], ENT_QUOTES, 'UTF-8'); ?></strong>
        (<code><?php echo $clashKey; ?></code>)
        — <?php echo htmlspecialchars(ReservedKeys::describe($clash['siteKey'], $clash['kinds']), ENT_QUOTES, 'UTF-8'); ?>
        <?php if ($keysLiveNow === true): ?>
        Its pages are taking over /<?php echo $clashKey; ?>/ now.
        <?php else: ?>
        It would take over /<?php echo $clashKey; ?>/ if the portal were switched to address prefixes.
        <?php endif; ?>
        <?php if ($isUmbrella === true): ?>
        Change the key at <a href="<?php echo htmlspecialchars(Router::url('admin/sites'), ENT_QUOTES, 'UTF-8'); ?>">Admin &rarr; Sites</a> (Edit).
        Links already shared under /<?php echo $clashKey; ?>/ will stop working when it changes.
        <?php else: ?>
        Ask a global administrator to change it.
        <?php endif; ?>
    </p>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- 📊 Summary Cards -->
<div class="row g-4 mb-4">
    <!-- 🔴 Errors Card -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card border-danger h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="card-subtitle mb-2 text-danger">
                            Errors (24h)<?php echo $singleOrg === false ? ' — ' . htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8') : ''; ?>
                        </h6>
                        <h2 class="card-title mb-0"><?php echo $errorCount24h; ?></h2>
                        <small class="text-muted"><?php echo number_format($errorCountTotal); ?> total</small>
                        <?php if ($allOrgErrors24h !== null): ?>
                            <div class="text-muted small mt-1">All organisations: <?php echo number_format($allOrgErrors24h); ?> / <?php echo number_format((int) $allOrgErrorsTotal); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="fs-1 text-danger opacity-25">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-transparent border-danger">
                <a href="/admin/errors" class="text-danger text-decoration-none small">
                    View Error Log <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- 👥 Users Card -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card border-primary h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="card-subtitle mb-2 text-primary">
                            Active Members<?php echo $singleOrg === false ? ' — ' . htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8') : ''; ?>
                        </h6>
                        <h2 class="card-title mb-0"><?php echo $userCountActive; ?></h2>
                        <small class="text-muted"><?php echo number_format($userCountTotal); ?> total</small>
                        <?php if ($allOrgActive !== null): ?>
                            <div class="text-muted small mt-1">All organisations: <?php echo number_format($allOrgActive); ?> / <?php echo number_format((int) $allOrgTotal); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="fs-1 text-primary opacity-25">
                        <i class="fa-solid fa-users"></i>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-transparent border-primary">
                <a href="/admin/users" class="text-primary text-decoration-none small">
                    Manage Users <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- 📋 Activity Card -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card border-success h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="card-subtitle mb-2 text-success">
                            Activity (24h)<?php echo $singleOrg === false ? ' — ' . htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8') : ''; ?>
                        </h6>
                        <h2 class="card-title mb-0"><?php echo number_format($activityCount24h); ?></h2>
                        <small class="text-muted">audit log entries</small>
                        <?php if ($allOrgActivity24h !== null): ?>
                            <div class="text-muted small mt-1">All organisations: <?php echo number_format($allOrgActivity24h); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="fs-1 text-success opacity-25">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-transparent border-success">
                <a href="/admin/activity" class="text-success text-decoration-none small">
                    View Activity Log <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- 🔧 Migrations Card -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card border-warning h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="card-subtitle mb-2 text-warning">Pending Migrations</h6>
                        <h2 class="card-title mb-0"><?php echo $pendingMigrations; ?></h2>
                        <small class="text-muted"><?php echo count($appliedMigrations); ?> applied</small>
                    </div>
                    <div class="fs-1 text-warning opacity-25">
                        <i class="fa-solid fa-database"></i>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-transparent border-warning">
                <a href="/admin/migrations" class="text-warning text-decoration-none small">
                    Run Migrations <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- 🖥️ System Information -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="fa-solid fa-server me-2"></i>System Information</h5>
    </div>
    <div class="card-body">
        <div class="portal-data-list">
            <div class="portal-data-row">
                <div class="col-12 col-md-4 fw-semibold">Environment</div>
                <div class="col-12 col-md-8">
                    <span class="badge bg-<?php echo ($portalEnv === 'production') ? 'success' : (($portalEnv === 'dev') ? 'warning' : 'info'); ?>">
                        <?php echo htmlspecialchars($portalEnv, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
            </div>
            <div class="portal-data-row">
                <div class="col-12 col-md-4 fw-semibold">PHP Version</div>
                <div class="col-12 col-md-8"><?php echo htmlspecialchars($phpVersion, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div class="portal-data-row">
                <div class="col-12 col-md-4 fw-semibold">Database</div>
                <div class="col-12 col-md-8">
                    <?php echo htmlspecialchars($dbVersion, ENT_QUOTES, 'UTF-8'); ?>
                    <?php if ($dbInfo['state'] !== 'ok'): ?>
                        <a href="/admin/system-info" class="badge bg-warning text-decoration-none ms-1">Needs a look</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="portal-data-row">
                <div class="col-12 col-md-4 fw-semibold">Portal Version</div>
                <div class="col-12 col-md-8"><?php echo htmlspecialchars(App::version(), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div class="portal-data-row">
                <div class="col-12 col-md-4 fw-semibold">Server Time</div>
                <div class="col-12 col-md-8"><?php echo htmlspecialchars(date('Y-m-d H:i:s T'), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- 🔗 Quick Links -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0"><i class="fa-solid fa-link me-2"></i>Admin Quick Links</h5>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/errors" class="btn btn-outline-danger w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-triangle-exclamation fa-lg"></i>
                    <span class="small">Error Log</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/activity" class="btn btn-outline-success w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-clock-rotate-left fa-lg"></i>
                    <span class="small">Activity Log</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/users" class="btn btn-outline-primary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-users fa-lg"></i>
                    <span class="small">Users</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/migrations" class="btn btn-outline-warning w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-database fa-lg"></i>
                    <span class="small">Migrations</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/settings" class="btn btn-outline-secondary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-gear fa-lg"></i>
                    <span class="small">Settings</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/integrations" class="btn btn-outline-info w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-plug-circle-check fa-lg"></i>
                    <span class="small">Integrations</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/captcha" class="btn btn-outline-warning w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-robot fa-lg"></i>
                    <span class="small">Captcha</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/release-notes" class="btn btn-outline-info w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-clipboard-list fa-lg"></i>
                    <span class="small">Release Notes</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/email-templates" class="btn btn-outline-primary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-envelope fa-lg"></i>
                    <span class="small">Email Templates</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/maintenance/retention" class="btn btn-outline-warning w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-broom fa-lg"></i>
                    <span class="small">Retention</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/system-info" class="btn btn-outline-secondary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-server fa-lg"></i>
                    <span class="small">Server Info</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/api-docs" class="btn btn-outline-info w-100 d-flex flex-column align-items-center gap-1 py-3" target="_blank" rel="noopener">
                    <i class="fa-solid fa-book fa-lg"></i>
                    <span class="small">API Docs</span>
                </a>
            </div>
            <?php if (App::isUmbrellaAdmin() === true): ?>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/sites" class="btn btn-outline-dark w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-sitemap fa-lg"></i>
                    <span class="small">Sites</span>
                </a>
            </div>
            <?php endif; ?>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/settings" class="btn btn-outline-info w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-sliders fa-lg"></i>
                    <span class="small">Old Settings</span>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- 🔗 More Admin Screens — the AppRegistry marketplace + reporting/workflow/
     compliance screens below have no other entry point in the app (they were
     reachable only by typing the URL directly). Sectioned into its own card
     so the primary Quick Links grid above stays short (#gap-fix D3). -->
<div class="card mt-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="fa-solid fa-ellipsis me-2"></i>More Admin Screens</h5>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/apps" class="btn btn-outline-secondary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-cubes fa-lg"></i>
                    <span class="small">Apps</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/reports" class="btn btn-outline-secondary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-chart-bar fa-lg"></i>
                    <span class="small">Reports</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/reports/builder" class="btn btn-outline-secondary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-table-list fa-lg"></i>
                    <span class="small">Report Builder</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/workflows" class="btn btn-outline-secondary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-diagram-project fa-lg"></i>
                    <span class="small">Workflows</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/audit" class="btn btn-outline-secondary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-shield-halved fa-lg"></i>
                    <span class="small">Audit Trail</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/sms" class="btn btn-outline-secondary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-comment-sms fa-lg"></i>
                    <span class="small">SMS</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/transcription" class="btn btn-outline-secondary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-closed-captioning fa-lg"></i>
                    <span class="small">Transcription</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/safeguarding/dbs" class="btn btn-outline-secondary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-user-shield fa-lg"></i>
                    <span class="small">Safeguarding</span>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="/admin/decision-cards" class="btn btn-outline-secondary w-100 d-flex flex-column align-items-center gap-1 py-3">
                    <i class="fa-solid fa-hand-holding-heart fa-lg"></i>
                    <span class="small">Decision Cards</span>
                </a>
            </div>
        </div>
    </div>
</div>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
