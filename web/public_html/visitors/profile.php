<?php
// Path: public_html/visitors/profile.php
/**
 * Visitor Tracking — single visitor profile + contact log + add-contact form.
 *
 * @package   Portal\Visitors
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/258
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
    header('Location: /visitors');
    exit();
}

$v = null;
$stmt = $db->prepare(
    'SELECT v.*, u.fullName AS assigneeName FROM tblVisitor v '
    . 'LEFT JOIN tblUsers u ON u.userID = v.assignedToID '
    . 'WHERE v.visitorID = ? AND v.siteID = ? LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $id, $siteId);
    $stmt->execute();
    $v = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($v === null) {
    http_response_code(404);
    exit('Visitor not found');
}

$contacts = [];
$stmt = $db->prepare(
    'SELECT c.contactID, c.contactedAt, c.method, c.summary, c.nextContactAt, '
    . '       u.fullName AS byName '
    . 'FROM tblVisitorContact c JOIN tblUsers u ON u.userID = c.contactedByID '
    . 'WHERE c.visitorID = ? ORDER BY c.contactedAt DESC'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $contacts[] = $r;
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

$pageTitle   = $v['fullName'];
$pageSection = 'visitors';
$breadcrumbs = ['Dashboard' => '/', 'Visitors' => '/visitors', $v['fullName'] => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
?>

<h1 class="mb-1"><?php echo htmlspecialchars((string) $v['fullName'], ENT_QUOTES, 'UTF-8'); ?></h1>
<p class="text-muted">
    First visited <?php echo htmlspecialchars(date('j M Y', strtotime((string) $v['firstVisitedAt'])), ENT_QUOTES, 'UTF-8'); ?>
    &middot; source: <?php echo htmlspecialchars((string) $v['source'], ENT_QUOTES, 'UTF-8'); ?>
</p>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <h2 class="h6">Contact details</h2>
                <p class="small mb-1"><strong>Email:</strong> <?php echo htmlspecialchars((string) ($v['email'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="small mb-0"><strong>Phone:</strong> <?php echo htmlspecialchars((string) ($v['phone'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card h-100">
            <div class="card-body">
                <h2 class="h6">Status &amp; assignment</h2>
                <form method="post" action="/visitors/save" class="row g-2 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="updateStatus" value="1">
                    <input type="hidden" name="visitorID" value="<?php echo (int) $id; ?>">
                    <div class="col-md-4">
                        <label class="form-label small">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <?php foreach (['new','in-touch','converted','lost'] as $s): ?>
                                <option value="<?php echo $s; ?>" <?php echo $v['status'] === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Assigned to</label>
                        <select name="assignedToID" class="form-select form-select-sm">
                            <option value="0">— Unassigned —</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?php echo (int) $u['userID']; ?>" <?php echo (int) $v['assignedToID'] === (int) $u['userID'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string) $u['fullName'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2"><button type="submit" class="btn btn-primary btn-sm w-100">Update</button></div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <h2 class="h6">Record contact</h2>
        <form method="post" action="/visitors/contact-save" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="visitorID" value="<?php echo (int) $id; ?>">
            <div class="col-md-3">
                <label class="form-label small">Method</label>
                <select name="method" class="form-select form-select-sm">
                    <option value="call">Call</option>
                    <option value="email">Email</option>
                    <option value="visit">Visit</option>
                    <option value="text">Text</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small">Next contact (optional)</label>
                <input type="date" name="nextContactAt" class="form-control form-control-sm">
            </div>
            <div class="col-12">
                <label class="form-label small">Summary</label>
                <textarea name="summary" class="form-control form-control-sm" rows="3" placeholder="Markdown supported."></textarea>
            </div>
            <div class="col-12"><button type="submit" class="btn btn-primary btn-sm">Save contact</button></div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h2 class="h6">Contact log</h2>
        <?php if (count($contacts) === 0): ?>
            <p class="text-muted small mb-0">No contacts recorded yet.</p>
        <?php else: foreach ($contacts as $c): ?>
            <div class="border-bottom py-2">
                <p class="mb-1 small text-muted">
                    <?php echo htmlspecialchars(date('j M Y, H:i', strtotime((string) $c['contactedAt'])), ENT_QUOTES, 'UTF-8'); ?>
                    &middot; <strong><?php echo htmlspecialchars((string) $c['byName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    &middot; <?php echo htmlspecialchars((string) $c['method'], ENT_QUOTES, 'UTF-8'); ?>
                    <?php if ($c['nextContactAt'] !== null): ?>
                        &middot; <span class="badge bg-info text-dark">Next: <?php echo htmlspecialchars(date('j M', strtotime((string) $c['nextContactAt'])), ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                </p>
                <div class="portal-markdown"><?php echo Markdown::render((string) ($c['summary'] ?? ''), ['allow_links' => true]); ?></div>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
