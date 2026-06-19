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
        'SELECT c.cardID, c.fullName, c.email, c.phone, c.decision, c.status, c.createdAt, u.fullName AS assigneeName '
        . 'FROM tblSalvationCards c LEFT JOIN tblUsers u ON u.userID = c.assignedToID '
        . 'WHERE c.siteID = ? ORDER BY c.createdAt DESC LIMIT 500'
    );
    $stmt->bind_param('i', $siteId);
} else {
    $stmt = $mysqli->prepare(
        'SELECT c.cardID, c.fullName, c.email, c.phone, c.decision, c.status, c.createdAt, u.fullName AS assigneeName '
        . 'FROM tblSalvationCards c LEFT JOIN tblUsers u ON u.userID = c.assignedToID '
        . 'WHERE c.siteID = ? AND c.status = ? ORDER BY c.createdAt DESC LIMIT 500'
    );
    $stmt->bind_param('is', $siteId, $status);
}
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) { $rows[] = $r; }
$stmt->close();

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
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
