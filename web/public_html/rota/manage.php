<?php
// Path: public_html/rota/manage.php
/**
 * Rota — Admin scheduler view. Lists upcoming slots + form to add new.
 *
 * @package   Portal\Rota
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/256
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

$db     = App::db();
$siteId = Site::id();

// Fetch role types + upcoming slots + users for the assignee dropdown
$roleTypes = [];
$rs = $db->query('SELECT roleTypeID, name, colorHex FROM tblRotaRoleType WHERE siteID = ' . $siteId . ' AND isActive = 1 ORDER BY name');
if ($rs !== false) {
    while ($r = $rs->fetch_assoc()) {
        $roleTypes[] = $r;
    }
    $rs->free();
}

$users = [];
$rs = $db->query('SELECT userID, fullName FROM tblUsers WHERE siteID = ' . $siteId . ' AND isActive = 1 ORDER BY fullName');
if ($rs !== false) {
    while ($r = $rs->fetch_assoc()) {
        $users[] = $r;
    }
    $rs->free();
}

$endDate = date('Y-m-d', strtotime('+12 weeks'));
$slots = [];
$stmt = $db->prepare(
    'SELECT s.slotID, s.slotDate, s.startTime, s.endTime, s.notes, s.assignedToID, '
    . '       r.name AS roleName, r.colorHex, u.fullName AS assigneeName '
    . 'FROM tblRotaSlot s '
    . 'JOIN tblRotaRoleType r ON r.roleTypeID = s.roleTypeID '
    . 'LEFT JOIN tblUsers u ON u.userID = s.assignedToID '
    . 'WHERE s.siteID = ? AND s.slotDate >= CURDATE() AND s.slotDate <= ? '
    . 'ORDER BY s.slotDate, r.name'
);
if ($stmt !== false) {
    $stmt->bind_param('is', $siteId, $endDate);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $slots[] = $r;
    }
    $stmt->close();
}

$pageTitle   = 'Manage Rota';
$pageSection = 'rota';
$breadcrumbs = ['Dashboard' => '/', 'Duty Roster' => '/rota', 'Manage' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-screwdriver-wrench me-2"></i>Manage Rota</h1>
        <p class="text-secondary mb-0">Add, edit, and assign duties for the next 12 weeks.</p>
    </div>
    <div>
        <a href="/rota/role-types" class="btn btn-outline-secondary btn-sm me-1">Role types</a>
        <a href="/rota" class="btn btn-outline-secondary btn-sm">&larr; Roster</a>
    </div>
</div>

<?php if (count($roleTypes) === 0): ?>
    <div class="alert alert-info">
        Define role types first → <a href="/rota/role-types">/rota/role-types</a>.
    </div>
<?php else: ?>
    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h5"><i class="fa-solid fa-plus me-1"></i>Add slot</h2>
            <form method="post" action="/rota/slot-save" class="row g-2">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="col-md-3">
                    <label class="form-label small">Role</label>
                    <select name="roleTypeID" class="form-select form-select-sm" required>
                        <?php foreach ($roleTypes as $rt): ?>
                            <option value="<?php echo (int) $rt['roleTypeID']; ?>"><?php echo htmlspecialchars((string) $rt['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Date</label>
                    <input type="date" name="slotDate" class="form-control form-control-sm" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Start (optional)</label>
                    <input type="time" name="startTime" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">End (optional)</label>
                    <input type="time" name="endTime" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Assign to (optional)</label>
                    <select name="assignedToID" class="form-select form-select-sm">
                        <option value="">— Unfilled —</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?php echo (int) $u['userID']; ?>"><?php echo htmlspecialchars((string) $u['fullName'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary btn-sm">Add slot</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <h2 class="h5">Upcoming slots</h2>
        <?php if (count($slots) === 0): ?>
            <p class="text-muted mb-0">No slots yet.</p>
        <?php else: ?>
            <div class="portal-data-list">
                <?php foreach ($slots as $s): ?>
                    <div class="row py-2 align-items-center border-bottom">
                        <div class="col-md-2"><strong><?php echo htmlspecialchars((string) $s['slotDate'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div class="col-md-2"><span class="badge" style="background:<?php echo htmlspecialchars((string) $s['colorHex'], ENT_QUOTES, 'UTF-8'); ?>;color:#fff;"><?php echo htmlspecialchars((string) $s['roleName'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div class="col-md-2 text-muted small">
                            <?php echo $s['startTime'] !== null ? htmlspecialchars(substr((string) $s['startTime'], 0, 5), ENT_QUOTES, 'UTF-8') : 'All day'; ?>
                        </div>
                        <div class="col-md-3">
                            <?php if ($s['assignedToID'] === null): ?>
                                <span class="text-warning">Unfilled</span>
                            <?php else: ?>
                                <?php echo htmlspecialchars((string) ($s['assigneeName'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-3 text-end">
                            <form method="post" action="/rota/slot-save" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="delete" value="<?php echo (int) $s['slotID']; ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm"
                                        data-confirm="Delete this duty slot?" data-confirm-destructive="true">Delete</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
