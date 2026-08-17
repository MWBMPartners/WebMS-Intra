<?php
// _apps/admin/salvation/cards.php (#316)
declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) { http_response_code(403); exit('Forbidden'); }

$siteId = Site::id();
$status = (string) ($_GET['status'] ?? 'new');
if (in_array($status, ['new','assigned','contacted','complete','archived','all'], true) === false) { $status = 'new'; }

$rows = [];
if ($status === 'all') {
    $stmt = $mysqli->prepare(
        'SELECT c.cardID, c.fullName, c.email, c.phone, c.decision, c.status, c.createdAt, '
        . '       c.assignedToID, c.notes, u.fullName AS assigneeName '
        . 'FROM tblSalvationCards c LEFT JOIN tblUsers u ON u.userID = c.assignedToID '
        . 'WHERE c.siteID = ? ORDER BY c.createdAt DESC LIMIT 500'
    );
    $stmt->bind_param('i', $siteId);
} else {
    $stmt = $mysqli->prepare(
        'SELECT c.cardID, c.fullName, c.email, c.phone, c.decision, c.status, c.createdAt, '
        . '       c.assignedToID, c.notes, u.fullName AS assigneeName '
        . 'FROM tblSalvationCards c LEFT JOIN tblUsers u ON u.userID = c.assignedToID '
        . 'WHERE c.siteID = ? AND c.status = ? ORDER BY c.createdAt DESC LIMIT 500'
    );
    $stmt->bind_param('is', $siteId, $status);
}
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) { $rows[] = $r; }
$stmt->close();

// 👥 "Assign to" picker — active users (no dedicated follow-up role exists
// for Decision Cards today, unlike e.g. visitors.coordinator_role, so this
// mirrors the "no role configured → all active users" fallback used there).
$assignableUsers = [];
$rs = $mysqli->query('SELECT userID, fullName FROM tblUsers WHERE isActive = 1 ORDER BY fullName');
if ($rs !== false) {
    while ($u = $rs->fetch_assoc()) { $assignableUsers[] = $u; }
    $rs->free();
}

$pageTitle = 'Decision Cards';
$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container-fluid py-3">
    <h1 class="h4 mb-2"><i class="fa-solid fa-hand-holding-heart me-2 text-primary"></i>Decision Cards</h1>

    <div class="btn-group btn-group-sm mb-3">
        <?php foreach (['new','assigned','contacted','complete','archived','all'] as $s): ?>
            <a class="btn btn-outline-secondary <?php echo $s === $status ? 'active' : ''; ?>" href="?status=<?php echo $s; ?>"><?php echo ucfirst($s); ?></a>
        <?php endforeach; ?>
    </div>

    <?php if (count($rows) === 0): ?>
        <div class="alert alert-info">No cards in this view.</div>
    <?php else: ?>
        <div class="portal-data-list">
        <?php foreach ($rows as $r):
            $badge = ['new'=>'bg-info text-dark','assigned'=>'bg-primary','contacted'=>'bg-warning text-dark','complete'=>'bg-success','archived'=>'bg-secondary'][$r['status']] ?? 'bg-secondary';
        ?>
            <div class="portal-data-row">
                <div class="portal-data-row-main">
                    <strong><?php echo htmlspecialchars((string) $r['fullName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <span class="badge bg-secondary ms-1"><?php echo htmlspecialchars((string) $r['decision'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="badge <?php echo $badge; ?> ms-1"><?php echo ucfirst((string) $r['status']); ?></span>
                    <div class="small text-muted">
                        <?php if (!empty($r['email'])): ?><?php echo htmlspecialchars((string) $r['email'], ENT_QUOTES, 'UTF-8'); ?> &middot;<?php endif; ?>
                        <?php if (!empty($r['phone'])): ?><?php echo htmlspecialchars((string) $r['phone'], ENT_QUOTES, 'UTF-8'); ?> &middot;<?php endif; ?>
                        <?php echo htmlspecialchars(date('j M Y', strtotime((string) $r['createdAt'])), ENT_QUOTES, 'UTF-8'); ?>
                        <?php if (!empty($r['assigneeName'])): ?> &middot; → <?php echo htmlspecialchars((string) $r['assigneeName'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
                    </div>
                </div>
                <div class="portal-data-row-aside">
                    <form method="post" action="/admin/decision-cards/act" class="d-flex gap-1">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="cardID" value="<?php echo (int) $r['cardID']; ?>">
                        <button name="action" value="contacted" class="btn btn-sm btn-outline-warning" title="Mark contacted"><i class="fa-solid fa-phone"></i></button>
                        <button name="action" value="complete" class="btn btn-sm btn-outline-success" title="Mark complete"><i class="fa-solid fa-check"></i></button>
                        <button name="action" value="archive" class="btn btn-sm btn-outline-secondary" title="Archive"><i class="fa-solid fa-box-archive"></i></button>
                    </form>
                    <!-- 🙋 Assign-to picker (#gap-fix D5 — assignedToID/status='assigned'
                         existed in schema + the filter tab but had no UI to set them). -->
                    <form method="post" action="/admin/decision-cards/act" class="d-flex gap-1 mt-1">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="cardID" value="<?php echo (int) $r['cardID']; ?>">
                        <input type="hidden" name="action" value="assign">
                        <input type="hidden" name="returnStatus" value="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">
                        <select name="assignedToID" class="form-select form-select-sm" style="max-width:170px;">
                            <option value="">— Assign to —</option>
                            <?php foreach ($assignableUsers as $u): ?>
                                <option value="<?php echo (int) $u['userID']; ?>" <?php echo (int) ($r['assignedToID'] ?? 0) === (int) $u['userID'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string) $u['fullName'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm btn-outline-primary" title="Assign"><i class="fa-solid fa-user-check"></i></button>
                    </form>
                    <!-- 📝 Editable follow-up notes (#gap-fix D5). -->
                    <form method="post" action="/admin/decision-cards/act" class="d-flex gap-1 mt-1">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="cardID" value="<?php echo (int) $r['cardID']; ?>">
                        <input type="hidden" name="action" value="notes">
                        <input type="hidden" name="returnStatus" value="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">
                        <textarea name="notes" class="form-control form-control-sm" rows="1" maxlength="2000"
                                  placeholder="Follow-up notes…" style="max-width:170px;"><?php echo htmlspecialchars((string) ($r['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Save notes"><i class="fa-solid fa-floppy-disk"></i></button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
