<?php
// Path: public_html/giving/categories.php
/**
 * Giving — category CRUD.
 *
 * @package   Portal\Giving
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/266
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Giving;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (Giving::canManage() === false) {
    Router::renderError(403);
    return;
}

$db     = App::db();
$siteId = Site::id();

$cats = [];
$stmt = $db->prepare(
    'SELECT c.categoryID, c.name, c.description, c.isActive, c.defaultFund, '
    . '       COUNT(e.entryID) AS entryCount, COALESCE(SUM(e.amountPence), 0) AS totalPence '
    . 'FROM tblGivingCategory c LEFT JOIN tblGivingEntry e ON e.categoryID = c.categoryID '
    . 'WHERE c.siteID = ? GROUP BY c.categoryID ORDER BY c.sortOrder, c.name'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $cats[] = $r;
    }
    $stmt->close();
}

$settings = App::settings()['giving'] ?? [];
$currency = (string) ($settings['currency'] ?? 'GBP');

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'Giving Categories';
$pageSection = 'giving';
$breadcrumbs = ['Dashboard' => '/', 'Giving' => '/giving', 'Categories' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-tags me-2"></i>Giving categories</h1>

<div class="card mb-3"><div class="card-body">
    <form method="post" action="/giving/cat-save" class="row g-2 align-items-end">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="add">
        <div class="col-md-3">
            <label class="form-label small">Name</label>
            <input type="text" class="form-control form-control-sm" name="name" required maxlength="255" placeholder="Tithe">
        </div>
        <div class="col-md-4">
            <label class="form-label small">Description</label>
            <input type="text" class="form-control form-control-sm" name="description" maxlength="500">
        </div>
        <div class="col-md-3">
            <label class="form-label small">Default fund / accounting code</label>
            <input type="text" class="form-control form-control-sm" name="defaultFund" maxlength="100">
        </div>
        <div class="col-md-2">
            <button class="btn btn-outline-primary btn-sm w-100"><i class="fa-solid fa-plus me-1"></i>Add</button>
        </div>
    </form>
</div></div>

<?php if (count($cats) === 0): ?>
    <div class="alert alert-info">No categories yet.</div>
<?php else: ?>
    <div class="card"><div class="card-body">
        <div class="portal-data-list">
            <?php foreach ($cats as $c): ?>
                <div class="row py-2 border-bottom align-items-center">
                    <div class="col-md-3"><strong><?php echo htmlspecialchars((string) $c['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        <?php if ((int) $c['isActive'] === 0): ?><span class="badge bg-secondary ms-1">inactive</span><?php endif; ?>
                    </div>
                    <div class="col-md-4 small text-muted"><?php echo htmlspecialchars((string) ($c['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-md-2 small"><?php echo htmlspecialchars((string) ($c['defaultFund'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-md-2 small text-end"><?php echo (int) $c['entryCount']; ?> · <?php echo htmlspecialchars(Giving::formatAmount((int) $c['totalPence'], $currency), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-md-1 text-end">
                        <form method="post" action="/giving/cat-save" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="categoryID" value="<?php echo (int) $c['categoryID']; ?>">
                            <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-toggle-on"></i></button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div></div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
