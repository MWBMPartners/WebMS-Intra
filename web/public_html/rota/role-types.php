<?php
// Path: public_html/rota/role-types.php
/**
 * Rota — Role type CRUD (admin).
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
$flash  = '';
$flashType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::verifyCsrf($_POST['csrf_token'] ?? '') === true) {
    $action = (string) ($_POST['action'] ?? 'create');
    try {
        if ($action === 'delete') {
            $id = (int) ($_POST['roleTypeID'] ?? 0);
            $stmt = $db->prepare('DELETE FROM tblRotaRoleType WHERE roleTypeID = ? AND siteID = ?');
            if ($stmt !== false) {
                $stmt->bind_param('ii', $id, $siteId);
                $stmt->execute();
                $stmt->close();
                $flash = 'Role type deleted.';
                $flashType = 'success';
            }
        } else {
            $name = trim((string) ($_POST['name'] ?? ''));
            $desc = trim((string) ($_POST['description'] ?? ''));
            $hex  = trim((string) ($_POST['colorHex'] ?? '#5e6ad2'));
            if ($name === '') {
                throw new \RuntimeException('Name required.');
            }
            if (preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) !== 1) {
                $hex = '#5e6ad2';
            }
            $stmt = $db->prepare(
                'INSERT INTO tblRotaRoleType (siteID, name, description, colorHex) VALUES (?, ?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE description = VALUES(description), colorHex = VALUES(colorHex)'
            );
            if ($stmt !== false) {
                $stmt->bind_param('isss', $siteId, $name, $desc, $hex);
                $stmt->execute();
                $stmt->close();
                $flash = 'Role type saved.';
                $flashType = 'success';
            }
        }
    } catch (\Throwable $e) {
        $flash = 'Error: ' . $e->getMessage();
        $flashType = 'danger';
    }
}

$rows = [];
$rs = $db->query('SELECT roleTypeID, name, description, colorHex FROM tblRotaRoleType WHERE siteID = ' . $siteId . ' ORDER BY name');
if ($rs !== false) {
    while ($r = $rs->fetch_assoc()) {
        $rows[] = $r;
    }
    $rs->free();
}

$pageTitle   = 'Rota Role Types';
$pageSection = 'rota';
$breadcrumbs = ['Dashboard' => '/', 'Duty Roster' => '/rota', 'Manage' => '/rota/manage', 'Role Types' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-tags me-2"></i>Role Types</h1>
        <p class="text-muted mb-0">Define the duties members can be scheduled for.</p>
    </div>
    <a href="/rota/manage" class="btn btn-outline-secondary btn-sm">&larr; Manage</a>
</div>

<?php if ($flash !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5">Add / update role type</h2>
        <form method="post" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="col-md-4"><label class="form-label small">Name</label><input type="text" name="name" class="form-control form-control-sm" required maxlength="100"></div>
            <div class="col-md-5"><label class="form-label small">Description (optional)</label><input type="text" name="description" class="form-control form-control-sm" maxlength="255"></div>
            <div class="col-md-2"><label class="form-label small">Colour</label><input type="color" name="colorHex" class="form-control form-control-color form-control-sm" value="#5e6ad2"></div>
            <div class="col-md-1 d-flex align-items-end"><button type="submit" class="btn btn-primary btn-sm">Save</button></div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h2 class="h5">Existing</h2>
        <?php if (count($rows) === 0): ?>
            <p class="text-muted mb-0">No role types yet.</p>
        <?php else: ?>
            <div class="portal-data-list">
                <?php foreach ($rows as $r): ?>
                    <div class="row py-2 align-items-center border-bottom">
                        <div class="col-md-1"><span class="badge" style="background:<?php echo htmlspecialchars((string) $r['colorHex'], ENT_QUOTES, 'UTF-8'); ?>;color:#fff;">&nbsp;&nbsp;</span></div>
                        <div class="col-md-3"><strong><?php echo htmlspecialchars((string) $r['name'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div class="col-md-6 small text-muted"><?php echo htmlspecialchars((string) ($r['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-2 text-end">
                            <form method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="roleTypeID" value="<?php echo (int) $r['roleTypeID']; ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm"
                                        data-confirm="Delete role type '<?php echo htmlspecialchars((string) $r['name'], ENT_QUOTES, 'UTF-8'); ?>'? All scheduled slots for this role will also be deleted." data-confirm-destructive="true">Delete</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
