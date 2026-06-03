<?php
// Path: public_html/projects/manage.php
/**
 * Projects — admin manage list + inline edit (when ?slug=…).
 *
 * @package   Portal\Projects
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/267
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Projects;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$db     = App::db();
$siteId = Site::id();
$slug   = (string) ($_GET['slug'] ?? '');

$editing = null;
if ($slug !== '') {
    $stmt = $db->prepare('SELECT * FROM tblProject WHERE siteID = ? AND slug = ? LIMIT 1');
    if ($stmt !== false) {
        $stmt->bind_param('is', $siteId, $slug);
        $stmt->execute();
        $editing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

$rows = [];
$stmt = $db->prepare(
    'SELECT projectID, slug, title, targetAmountPence, currency, status, updatedAt, '
    . '       (SELECT COUNT(*) FROM tblProjectPledge p WHERE p.projectID = pr.projectID) AS pledgeCount '
    . 'FROM tblProject pr WHERE siteID = ? ORDER BY updatedAt DESC LIMIT 100'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $r['raised'] = Projects::raisedPence((int) $r['projectID']);
        $rows[] = $r;
    }
    $stmt->close();
}

$pendingPledges = [];
$stmt = $db->prepare(
    'SELECT p.pledgeID, p.amountPence, p.pledgedAt, p.donorName, '
    . '       pr.title, pr.slug, u.fullName '
    . 'FROM tblProjectPledge p '
    . 'INNER JOIN tblProject pr ON pr.projectID = p.projectID '
    . 'LEFT JOIN tblUsers u ON u.userID = p.donorID '
    . 'WHERE pr.siteID = ? AND p.fulfilledAt IS NULL '
    . 'ORDER BY p.pledgedAt DESC LIMIT 50'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $pendingPledges[] = $r;
    }
    $stmt->close();
}

$givingCategories = [];
try {
    $rs = $db->query('SELECT categoryID, name FROM tblGivingCategory WHERE siteID = ' . (int) $siteId . ' AND isActive = 1 ORDER BY name');
    if ($rs !== false) {
        while ($r = $rs->fetch_assoc()) {
            $givingCategories[] = $r;
        }
        $rs->free();
    }
} catch (\Throwable $ignored) {
    // Giving app not installed — leave empty.
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'Manage Projects';
$pageSection = 'projects';
$breadcrumbs = ['Dashboard' => '/', 'Projects' => '/projects', 'Manage' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-gear me-2"></i>Manage projects</h1>

<div class="card mb-3">
    <div class="card-header"><strong><?php echo $editing !== null ? 'Edit project' : 'Create project'; ?></strong></div>
    <div class="card-body">
        <form method="post" action="/projects/manage-save" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="projectID" value="<?php echo (int) ($editing['projectID'] ?? 0); ?>">
            <div class="col-md-6">
                <label class="form-label">Title</label>
                <input type="text" class="form-control" name="title" required maxlength="255" value="<?php echo htmlspecialchars((string) ($editing['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Target amount</label>
                <input type="text" class="form-control" name="targetAmount" required placeholder="15000.00" value="<?php echo $editing !== null ? number_format(((int) $editing['targetAmountPence']) / 100, 2, '.', '') : ''; ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <?php foreach (['planning','active','funded','completed','cancelled'] as $s): ?>
                        <option value="<?php echo $s; ?>" <?php echo ((string) ($editing['status'] ?? 'planning')) === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Starts</label>
                <input type="date" class="form-control" name="startedAt" value="<?php echo htmlspecialchars((string) ($editing['startedAt'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Ends</label>
                <input type="date" class="form-control" name="endsAt" value="<?php echo htmlspecialchars((string) ($editing['endsAt'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Cover image URL</label>
                <input type="text" class="form-control" name="coverImagePath" maxlength="255" value="<?php echo htmlspecialchars((string) ($editing['coverImagePath'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="isPublic" name="isPublic" value="1" <?php echo ((int) ($editing['isPublic'] ?? 1)) === 1 ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="isPublic">Public</label>
                </div>
            </div>
            <div class="col-12">
                <label class="form-label">Description (markdown)</label>
                <textarea class="form-control" name="description" rows="5"><?php echo htmlspecialchars((string) ($editing['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
            <div class="col-12">
                <button class="btn btn-primary" type="submit"><?php echo $editing !== null ? 'Save changes' : 'Create project'; ?></button>
                <?php if ($editing !== null): ?>
                    <a href="/projects/manage" class="btn btn-outline-secondary">New project</a>
                    <a href="/projects/view?slug=<?php echo urlencode((string) $editing['slug']); ?>" class="btn btn-outline-secondary" target="_blank">View public page</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if ($editing !== null): ?>
    <div class="card mb-3">
        <div class="card-header"><strong>Post update</strong></div>
        <div class="card-body">
            <form method="post" action="/projects/update-post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="slug" value="<?php echo htmlspecialchars((string) $editing['slug'], ENT_QUOTES, 'UTF-8'); ?>">
                <textarea class="form-control mb-2" name="content" rows="3" required placeholder="Where we are, what's next, how supporters can help…"></textarea>
                <button class="btn btn-outline-primary btn-sm" type="submit"><i class="fa-solid fa-newspaper me-1"></i>Post update</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if (count($pendingPledges) > 0): ?>
    <div class="card mb-3">
        <div class="card-header"><strong>Pending pledges (fulfil when payment lands)</strong></div>
        <div class="card-body">
            <div class="portal-data-list">
                <?php foreach ($pendingPledges as $p):
                    $donor = (string) ($p['fullName'] ?? '') !== '' ? (string) $p['fullName']
                        : ((string) ($p['donorName'] ?? '') !== '' ? (string) $p['donorName'] : 'Anonymous');
                ?>
                    <div class="row py-1 border-bottom small align-items-center">
                        <div class="col-md-3"><?php echo htmlspecialchars((string) $p['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-3"><?php echo htmlspecialchars($donor, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-2">£<?php echo number_format(((int) $p['amountPence']) / 100, 2); ?></div>
                        <div class="col-md-2 text-muted"><?php echo htmlspecialchars(date('j M', (int) strtotime((string) $p['pledgedAt'])), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-2 text-end">
                            <form method="post" action="/projects/fulfil" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="pledgeID" value="<?php echo (int) $p['pledgeID']; ?>">
                                <?php if (count($givingCategories) > 0): ?>
                                    <select class="form-select form-select-sm d-inline-block w-auto" name="givingCategoryID">
                                        <option value="0">No giving link</option>
                                        <?php foreach ($givingCategories as $gc): ?>
                                            <option value="<?php echo (int) $gc['categoryID']; ?>"><?php echo htmlspecialchars((string) $gc['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-success btn-sm"><i class="fa-solid fa-check"></i></button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (count($rows) === 0): ?>
    <div class="alert alert-info">No projects yet.</div>
<?php else: ?>
    <div class="card"><div class="card-body">
        <div class="portal-data-list">
            <?php foreach ($rows as $r):
                $target = max(1, (int) $r['targetAmountPence']);
                $pct    = min(100, (int) round(((int) $r['raised'] / $target) * 100));
            ?>
                <div class="row py-2 border-bottom align-items-center small">
                    <div class="col-md-4"><a href="/projects/manage?slug=<?php echo urlencode((string) $r['slug']); ?>"><strong><?php echo htmlspecialchars((string) $r['title'], ENT_QUOTES, 'UTF-8'); ?></strong></a></div>
                    <div class="col-md-2"><span class="badge bg-secondary"><?php echo htmlspecialchars((string) $r['status'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                    <div class="col-md-3">
                        <div class="progress" style="height:6px;"><div class="progress-bar bg-success" style="width: <?php echo $pct; ?>%;"></div></div>
                        <small class="text-muted"><?php echo $pct; ?>% · £<?php echo number_format(((int) $r['raised']) / 100, 2); ?> of £<?php echo number_format($target / 100, 2); ?></small>
                    </div>
                    <div class="col-md-1 text-muted"><?php echo (int) $r['pledgeCount']; ?> pledges</div>
                    <div class="col-md-2 text-end">
                        <a href="/projects/manage?slug=<?php echo urlencode((string) $r['slug']); ?>" class="btn btn-outline-primary btn-sm">Edit</a>
                        <a href="/projects/view?slug=<?php echo urlencode((string) $r['slug']); ?>" class="btn btn-outline-secondary btn-sm" target="_blank">View</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div></div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
