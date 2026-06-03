<?php
// Path: public_html/resources/manage.php
/**
 * Resource Booking — admin CRUD for resources.
 *
 * @package   Portal\Resources
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/263
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
            $id = (int) ($_POST['resourceID'] ?? 0);
            $stmt = $db->prepare('UPDATE tblResource SET isActive = 0 WHERE resourceID = ? AND siteID = ?');
            if ($stmt !== false) {
                $stmt->bind_param('ii', $id, $siteId);
                $stmt->execute();
                $stmt->close();
                $flash = 'Resource archived.';
                $flashType = 'success';
            }
        } else {
            $name        = trim((string) ($_POST['name'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $category    = (string) ($_POST['category'] ?? 'room');
            $capacity    = (int) ($_POST['capacity'] ?? 0);
            $location    = trim((string) ($_POST['location'] ?? ''));
            $requiresApproval = isset($_POST['requiresApproval']) ? 1 : 0;
            $rate        = (string) ($_POST['hourlyRate'] ?? '');
            $buffer      = (int) ($_POST['bufferMinutes'] ?? 0);

            if (in_array($category, ['room','equipment','vehicle','other'], true) === false) {
                $category = 'other';
            }
            $cap = $capacity > 0 ? $capacity : null;
            $loc = $location !== '' ? $location : null;
            $desc = $description !== '' ? $description : null;
            $ratePence = ($rate !== '' && is_numeric($rate)) ? (int) round(((float) $rate) * 100) : null;

            if ($name === '') {
                throw new \RuntimeException('Name required');
            }
            $stmt = $db->prepare(
                'INSERT INTO tblResource (siteID, name, description, category, capacity, location, requiresApproval, hourlyRatePence, bufferMinutes) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if ($stmt !== false) {
                $stmt->bind_param('isssisiii', $siteId, $name, $desc, $category, $cap, $loc, $requiresApproval, $ratePence, $buffer);
                $stmt->execute();
                $stmt->close();
                $flash = 'Resource saved.';
                $flashType = 'success';
            }
        }
    } catch (\Throwable $e) {
        $flash = 'Error: ' . $e->getMessage();
        $flashType = 'danger';
    }
}

$rows = [];
$rStmt = $db->prepare('SELECT * FROM tblResource WHERE siteID = ? AND isActive = 1 ORDER BY category, name');
if ($rStmt !== false) {
    $rStmt->bind_param('i', $siteId);
    $rStmt->execute();
    $rs = $rStmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $rows[] = $r;
    }
    $rStmt->close();
}

$defaultBuffer = (int) (App::settings()['resources']['default_buffer'] ?? 15);

$pageTitle   = 'Manage Resources';
$pageSection = 'resources';
$breadcrumbs = ['Dashboard' => '/', 'Resources' => '/resources', 'Manage' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="mb-0"><i class="fa-solid fa-screwdriver-wrench me-2"></i>Manage Resources</h1>
    <a href="/resources" class="btn btn-outline-secondary btn-sm">&larr; Resources</a>
</div>

<?php if ($flash !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5">Add resource</h2>
        <form method="post" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="col-md-6"><label class="form-label small">Name</label><input type="text" name="name" class="form-control form-control-sm" required maxlength="255"></div>
            <div class="col-md-3"><label class="form-label small">Category</label>
                <select name="category" class="form-select form-select-sm">
                    <option value="room">Room</option>
                    <option value="equipment">Equipment</option>
                    <option value="vehicle">Vehicle</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="col-md-3"><label class="form-label small">Capacity (optional)</label><input type="number" name="capacity" min="0" class="form-control form-control-sm"></div>
            <div class="col-md-6"><label class="form-label small">Location</label><input type="text" name="location" class="form-control form-control-sm" maxlength="255"></div>
            <div class="col-md-3"><label class="form-label small">Hourly rate £ (optional)</label><input type="number" step="0.01" name="hourlyRate" min="0" class="form-control form-control-sm"></div>
            <div class="col-md-3"><label class="form-label small">Buffer minutes</label><input type="number" name="bufferMinutes" min="0" max="240" class="form-control form-control-sm" value="<?php echo $defaultBuffer; ?>"></div>
            <div class="col-12"><label class="form-label small">Description</label><textarea name="description" class="form-control form-control-sm" rows="2"></textarea></div>
            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="reqApproval" name="requiresApproval">
                    <label class="form-check-label small" for="reqApproval">Requires admin approval before bookings are confirmed</label>
                </div>
            </div>
            <div class="col-12"><button type="submit" class="btn btn-primary btn-sm">Save</button></div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h2 class="h5">Active resources</h2>
        <?php if (count($rows) === 0): ?>
            <p class="text-muted mb-0">None yet.</p>
        <?php else: ?>
            <div class="portal-data-list">
                <?php foreach ($rows as $r): ?>
                    <div class="row py-2 border-bottom align-items-center">
                        <div class="col-md-3"><strong><?php echo htmlspecialchars((string) $r['name'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div class="col-md-2"><span class="badge bg-light text-dark"><?php echo htmlspecialchars((string) $r['category'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div class="col-md-3 small text-muted">
                            <?php echo htmlspecialchars((string) ($r['location'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                            <?php if ($r['capacity'] !== null): ?> · cap <?php echo (int) $r['capacity']; ?><?php endif; ?>
                        </div>
                        <div class="col-md-2 small">
                            <?php if ((int) $r['requiresApproval'] === 1): ?><span class="badge bg-warning text-dark">Approval</span><?php endif; ?>
                            <?php if ($r['hourlyRatePence'] !== null): ?>£<?php echo number_format((int) $r['hourlyRatePence'] / 100, 2); ?>/hr<?php endif; ?>
                        </div>
                        <div class="col-md-2 text-end">
                            <form method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="resourceID" value="<?php echo (int) $r['resourceID']; ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm"
                                        data-confirm="Archive this resource? It won't appear in bookings but existing reservations stay." data-confirm-destructive="true">Archive</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
