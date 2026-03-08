<?php
// Path: public_html/leadership/index.php
/**
 * -----------------------------------------------------------------------------
 * Leadership Directory 👑
 * -----------------------------------------------------------------------------
 * Displays current leadership roles and their holders in a directory format.
 * Shows active assignments grouped by role, with term dates. Admins see
 * management links; all authenticated users can view the directory.
 *
 * @package   Portal\Leadership
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.9.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/38
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

// 📌 Page metadata
$pageTitle   = 'Leadership';
$pageSection = 'leadership';
$breadcrumbs = ['Dashboard' => '/', 'Leadership' => ''];

// 🛡️ Auth check
Auth::ensureSession();
if (Auth::check() === false) {
    Auth::requireLogin();
    return;
}

// 📋 Flash message
$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

// 🌐 Multi-site scope
$siteId  = Site::id();
$isAdmin = App::isAdmin();

// -----------------------------------------------------------------------------
// 📋 Fetch active roles with their current holders
// Current = isActive = 1 AND (endDate IS NULL OR endDate >= TODAY)
// -----------------------------------------------------------------------------
$roles = [];
$stmtRoles = $mysqli->prepare(
    'SELECT r.roleID, r.roleName, r.description, r.sortOrder '
    . 'FROM tblLeadershipRoles r '
    . 'WHERE r.siteID = ? AND r.isActive = 1 '
    . 'ORDER BY r.sortOrder, r.roleName'
);
if ($stmtRoles !== false) {
    $stmtRoles->bind_param('i', $siteId);
    $stmtRoles->execute();
    $resultRoles = $stmtRoles->get_result();
    while ($r = $resultRoles->fetch_assoc()) {
        $r['holders'] = [];
        $roles[$r['roleID']] = $r;
    }
    $stmtRoles->close();
}

// 📋 Fetch current assignments for all active roles
$today = date('Y-m-d');
$stmtAssign = $mysqli->prepare(
    'SELECT a.assignmentID, a.roleID, a.userID, a.personName, a.personEmail, '
    . 'a.startDate, a.endDate, a.notes, u.fullName AS userName, u.emailAddress AS userEmail '
    . 'FROM tblLeadershipAssignments a '
    . 'LEFT JOIN tblUsers u ON u.userID = a.userID '
    . 'WHERE a.siteID = ? AND a.isActive = 1 '
    . 'AND (a.endDate IS NULL OR a.endDate >= ?) '
    . 'ORDER BY a.startDate'
);
if ($stmtAssign !== false) {
    $stmtAssign->bind_param('is', $siteId, $today);
    $stmtAssign->execute();
    $resultAssign = $stmtAssign->get_result();
    while ($a = $resultAssign->fetch_assoc()) {
        $rid = (int) $a['roleID'];
        if (isset($roles[$rid]) === true) {
            // 📌 Resolve display name: portal user name > personName
            $a['displayName']  = $a['userName'] ?? $a['personName'] ?? 'Unknown';
            $a['displayEmail'] = $a['userEmail'] ?? $a['personEmail'] ?? '';
            $roles[$rid]['holders'][] = $a;
        }
    }
    $stmtAssign->close();
}

// 📊 Count totals for summary
$totalRoles   = count($roles);
$totalHolders = 0;
$vacantRoles  = 0;
foreach ($roles as $role) {
    $holderCount = count($role['holders']);
    $totalHolders += $holderCount;
    if ($holderCount === 0) {
        $vacantRoles++;
    }
}

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- 👑 Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-crown me-2" style="color:#d4af37;"></i>Leadership Directory</h1>
    <?php if ($isAdmin === true): ?>
        <div class="d-flex gap-2">
            <a href="/leadership/history" class="btn btn-outline-secondary">
                <i class="fa-solid fa-clock-rotate-left me-1"></i> History
            </a>
            <a href="/leadership/assign" class="btn btn-primary">
                <i class="fa-solid fa-user-plus me-1"></i> Assign Role
            </a>
            <a href="/leadership/manage" class="btn btn-outline-primary">
                <i class="fa-solid fa-gear me-1"></i> Manage Roles
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- 📊 Summary cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="fs-3 fw-bold text-primary"><?php echo $totalRoles; ?></div>
                <small class="text-muted">Roles Defined</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="fs-3 fw-bold text-success"><?php echo $totalHolders; ?></div>
                <small class="text-muted">Active Holders</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="fs-3 fw-bold <?php echo $vacantRoles > 0 ? 'text-warning' : 'text-muted'; ?>"><?php echo $vacantRoles; ?></div>
                <small class="text-muted">Vacant Roles</small>
            </div>
        </div>
    </div>
</div>

<!-- 👥 Leadership directory grouped by role -->
<?php if ($totalRoles === 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-1"></i>
        No leadership roles have been defined yet.
        <?php if ($isAdmin === true): ?>
            <a href="/leadership/manage" class="alert-link">Create roles</a> to get started.
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="row g-4">
        <?php foreach ($roles as $role): ?>
            <div class="col-12 col-md-6 col-lg-4">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fa-solid fa-crown me-1" style="color:#d4af37;"></i>
                            <?php echo htmlspecialchars($role['roleName'], ENT_QUOTES, 'UTF-8'); ?>
                        </h5>
                        <?php if (count($role['holders']) === 0): ?>
                            <span class="badge bg-warning text-dark">Vacant</span>
                        <?php else: ?>
                            <span class="badge bg-success"><?php echo count($role['holders']); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if ($role['description'] !== null && $role['description'] !== ''): ?>
                            <p class="text-muted small mb-3"><?php echo htmlspecialchars($role['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>

                        <?php if (count($role['holders']) === 0): ?>
                            <p class="text-muted fst-italic mb-0">No one currently assigned.</p>
                        <?php else: ?>
                            <ul class="list-unstyled mb-0">
                                <?php foreach ($role['holders'] as $holder): ?>
                                    <li class="d-flex align-items-start mb-2">
                                        <i class="fa-solid fa-user me-2 mt-1 text-muted"></i>
                                        <div>
                                            <strong><?php echo htmlspecialchars($holder['displayName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            <?php if ($holder['displayEmail'] !== ''): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($holder['displayEmail'], ENT_QUOTES, 'UTF-8'); ?></small>
                                            <?php endif; ?>
                                            <?php if ($holder['startDate'] !== null): ?>
                                                <br><small class="text-muted">
                                                    Since <?php echo htmlspecialchars(date('M Y', strtotime($holder['startDate'])), ENT_QUOTES, 'UTF-8'); ?>
                                                    <?php if ($holder['endDate'] !== null): ?>
                                                        &ndash; <?php echo htmlspecialchars(date('M Y', strtotime($holder['endDate'])), ENT_QUOTES, 'UTF-8'); ?>
                                                    <?php endif; ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                    <?php if ($isAdmin === true): ?>
                        <div class="card-footer text-end">
                            <a href="/leadership/assign?roleID=<?php echo (int) $role['roleID']; ?>"
                               class="btn btn-sm btn-outline-primary" title="Assign someone to this role">
                                <i class="fa-solid fa-user-plus me-1"></i> Assign
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
