<?php
// Path: public_html/service-plans/edit.php
/**
 * Service Plans — edit plan metadata + items list with reorder/edit/delete.
 *
 * @package   Portal\ServicePlans
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/262
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Markdown;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$siteId = Site::id();
$id     = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: /service-plans');
    exit();
}

$plan = null;
$stmt = $db->prepare('SELECT * FROM tblServicePlan WHERE planID = ? AND siteID = ? LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('ii', $id, $siteId);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($plan === null) {
    http_response_code(404);
    exit('Plan not found');
}

$items = [];
$stmt = $db->prepare(
    'SELECT i.itemID, i.sectionType, i.position, i.title, i.presenterID, i.presenterText, '
    . '       i.durationMin, i.notes, u.fullName AS presenterName '
    . 'FROM tblServicePlanItem i LEFT JOIN tblUsers u ON u.userID = i.presenterID '
    . 'WHERE i.planID = ? ORDER BY i.position, i.itemID'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $items[] = $r;
    }
    $stmt->close();
}

$users = [];
$rs = $db->query("SELECT userID, fullName FROM tblUsers WHERE isActive = 1 ORDER BY fullName");
if ($rs !== false) {
    while ($r = $rs->fetch_assoc()) {
        $users[] = $r;
    }
    $rs->free();
}

$pageTitle   = (string) $plan['title'];
$pageSection = 'service-plans';
$breadcrumbs = ['Dashboard' => '/', 'Service Plans' => '/service-plans', (string) $plan['title'] => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();

$sectionTypes = [
    'greeting'       => 'Greeting / Welcome',
    'song'           => 'Song / Hymn',
    'prayer'         => 'Prayer',
    'scripture'      => 'Scripture',
    'sermon'         => 'Sermon',
    'offering'       => 'Offering',
    'communion'      => 'Communion',
    'special_music'  => 'Special music',
    'announcement'   => 'Announcement',
    'reading'        => 'Reading',
    'other'          => 'Other',
];
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><?php echo htmlspecialchars((string) $plan['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="text-secondary mb-0">
            <?php echo htmlspecialchars(date('l j F Y', strtotime((string) $plan['serviceDate'])), ENT_QUOTES, 'UTF-8'); ?>
            &middot; status: <strong><?php echo htmlspecialchars((string) $plan['status'], ENT_QUOTES, 'UTF-8'); ?></strong>
        </p>
    </div>
    <div>
        <a href="/service-plans/print?id=<?php echo $id; ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
            <i class="fa-solid fa-print me-1"></i>Print
        </a>
        <a href="/service-plans" class="btn btn-outline-secondary btn-sm">&larr; Back</a>
    </div>
</div>

<!-- Plan metadata -->
<div class="card mb-3">
    <div class="card-body">
        <form method="post" action="/service-plans/save" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="planID" value="<?php echo $id; ?>">
            <div class="col-md-5">
                <label class="form-label small">Title</label>
                <input type="text" name="title" class="form-control form-control-sm" required maxlength="255" value="<?php echo htmlspecialchars((string) $plan['title'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small">Service date</label>
                <input type="date" name="serviceDate" class="form-control form-control-sm" required value="<?php echo htmlspecialchars((string) $plan['serviceDate'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <?php foreach (['draft','published','archived'] as $s): ?>
                        <option value="<?php echo $s; ?>" <?php echo $plan['status'] === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary btn-sm w-100">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Items -->
<div class="card">
    <div class="card-body">
        <h2 class="h5">Sections</h2>
        <?php foreach ($items as $idx => $it): ?>
            <div class="border-bottom py-3">
                <form method="post" action="/service-plans/item-save" class="row g-2">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="planID" value="<?php echo $id; ?>">
                    <input type="hidden" name="itemID" value="<?php echo (int) $it['itemID']; ?>">
                    <input type="hidden" name="action" value="update">
                    <div class="col-md-1 text-center small text-muted">
                        <strong><?php echo $idx + 1; ?></strong><br>
                        <form method="post" action="/service-plans/item-save" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="planID" value="<?php echo $id; ?>">
                            <input type="hidden" name="itemID" value="<?php echo (int) $it['itemID']; ?>">
                            <input type="hidden" name="action" value="move-up">
                            <button type="submit" class="btn btn-link btn-sm p-0" title="Move up" <?php echo $idx === 0 ? 'disabled' : ''; ?>>▲</button>
                        </form>
                        <form method="post" action="/service-plans/item-save" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="planID" value="<?php echo $id; ?>">
                            <input type="hidden" name="itemID" value="<?php echo (int) $it['itemID']; ?>">
                            <input type="hidden" name="action" value="move-down">
                            <button type="submit" class="btn btn-link btn-sm p-0" title="Move down" <?php echo $idx === count($items) - 1 ? 'disabled' : ''; ?>>▼</button>
                        </form>
                    </div>
                    <div class="col-md-2">
                        <select name="sectionType" class="form-select form-select-sm">
                            <?php foreach ($sectionTypes as $val => $lbl): ?>
                                <option value="<?php echo $val; ?>" <?php echo $it['sectionType'] === $val ? 'selected' : ''; ?>><?php echo htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <input type="text" name="title" class="form-control form-control-sm" maxlength="255" value="<?php echo htmlspecialchars((string) ($it['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Title">
                    </div>
                    <div class="col-md-2">
                        <select name="presenterID" class="form-select form-select-sm">
                            <option value="0">— Presenter —</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?php echo (int) $u['userID']; ?>" <?php echo (int) $it['presenterID'] === (int) $u['userID'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string) $u['fullName'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <input type="number" name="durationMin" min="0" max="240" class="form-control form-control-sm" value="<?php echo (int) ($it['durationMin'] ?? 0); ?>" placeholder="Min">
                    </div>
                    <div class="col-md-2 text-end">
                        <button type="submit" class="btn btn-success btn-sm">Save</button>
                        <form method="post" action="/service-plans/item-save" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="planID" value="<?php echo $id; ?>">
                            <input type="hidden" name="itemID" value="<?php echo (int) $it['itemID']; ?>">
                            <input type="hidden" name="action" value="delete">
                            <button type="submit" class="btn btn-outline-danger btn-sm"
                                    data-confirm="Delete this section?" data-confirm-destructive="true">Del</button>
                        </form>
                    </div>
                    <div class="col-12">
                        <input type="text" name="presenterText" class="form-control form-control-sm" maxlength="255" value="<?php echo htmlspecialchars((string) ($it['presenterText'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Presenter (if not a portal user)">
                    </div>
                    <div class="col-12">
                        <textarea name="notes" class="form-control form-control-sm" rows="2" placeholder="Notes / AV cues (markdown)"><?php echo htmlspecialchars((string) ($it['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                </form>
            </div>
        <?php endforeach; ?>

        <!-- Add item -->
        <form method="post" action="/service-plans/item-save" class="row g-2 mt-3 pt-3 border-top">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="planID" value="<?php echo $id; ?>">
            <input type="hidden" name="action" value="create">
            <div class="col-md-3">
                <select name="sectionType" class="form-select form-select-sm">
                    <?php foreach ($sectionTypes as $val => $lbl): ?>
                        <option value="<?php echo $val; ?>"><?php echo htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <input type="text" name="title" class="form-control form-control-sm" maxlength="255" placeholder="Title (e.g. Hymn 256 — Amazing Grace)">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-plus me-1"></i>Add section</button>
            </div>
        </form>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
