<?php
// Path: _apps/giving/count/index.php
/**
 * -----------------------------------------------------------------------------
 * Giving — Offering Count Sessions: List & Start 💷
 * -----------------------------------------------------------------------------
 * Two-person offering-count workflow (#299 sub-feature 1). Lists existing
 * count sessions for the active site (most recent service date first) and
 * offers a form to start a new one. Detail entry, discrepancy handling, and
 * closing all happen on /giving/count/session — this page is list + create.
 *
 * Gate matches every other financial action in `giving`:
 * Portal\Core\Giving::canManage() (site admin OR the `treasurer` role).
 *
 * @package   Portal\Giving
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/299
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Giving;
use Portal\Core\Router;
use Portal\Core\Site;

// 🛡️ Session + gate — same financial-action gate as the rest of `giving`.
Auth::ensureSession();
Auth::requireLogin();
if (Giving::canManage() === false) {
    Router::renderError(403);
    return;
}

$db     = App::db();
$siteId = Site::id();

// -----------------------------------------------------------------------------
// 🔍 Filter (optional status filter, mirrors giving/manage's from/to pattern)
// -----------------------------------------------------------------------------
$filterStatus = (string) ($_GET['status'] ?? '');
$validStatuses = ['open', 'counting', 'discrepancy', 'closed'];
if (in_array($filterStatus, $validStatuses, true) === false) {
    $filterStatus = '';
}

$sql = 'SELECT cs.countSessionID, cs.serviceDate, cs.status, cs.cashTotal, cs.chequeTotal, cs.envelopeTotal, '
    . '       c.name AS categoryName, u1.fullName AS counter1Name, u2.fullName AS counter2Name '
    . 'FROM tblCountSessions cs '
    . 'INNER JOIN tblGivingCategory c ON c.categoryID = cs.categoryID '
    . 'LEFT JOIN tblUsers u1 ON u1.userID = cs.counter1ID '
    . 'LEFT JOIN tblUsers u2 ON u2.userID = cs.counter2ID '
    . 'WHERE cs.siteID = ?';
$types  = 'i';
$params = [$siteId];
if ($filterStatus !== '') {
    $sql .= ' AND cs.status = ?';
    $types .= 's';
    $params[] = $filterStatus;
}
$sql .= ' ORDER BY cs.serviceDate DESC, cs.countSessionID DESC LIMIT 200';

$sessions = [];
$stmt = $db->prepare($sql);
if ($stmt !== false) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $sessions[] = $r;
    }
    $stmt->close();
}

// 📋 Active giving categories (for the "start new session" category select)
$categories = [];
$stmt = $db->prepare('SELECT categoryID, name FROM tblGivingCategory WHERE siteID = ? AND isActive = 1 ORDER BY sortOrder, name');
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $categories[] = $r;
    }
    $stmt->close();
}

// 📋 Active users (for the counter1 / counter2 selects)
$users = [];
$r = $db->query('SELECT userID, fullName FROM tblUsers WHERE isActive = 1 ORDER BY fullName LIMIT 500');
if ($r !== false) {
    while ($u = $r->fetch_assoc()) {
        $users[] = $u;
    }
    $r->free();
}

$statusClass = static function (string $status): string {
    return match ($status) {
        'open'        => 'secondary',
        'counting'    => 'primary',
        'discrepancy' => 'danger',
        'closed'      => 'success',
        default       => 'secondary',
    };
};

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'Offering Count Sessions';
$pageSection = 'giving';
$breadcrumbs = ['Dashboard' => '/', 'Giving' => '/giving', 'Count Sessions' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-money-bill-transfer me-2"></i>Offering Count Sessions</h1>
        <p class="text-secondary mb-0">Two-person offering count with automatic discrepancy flagging.</p>
    </div>
    <a href="/giving/manage" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to Giving</a>
</div>

<!-- ➕ Start a new count session -->
<div class="card mb-4">
    <div class="card-header"><strong>Start a new count session</strong></div>
    <div class="card-body">
        <?php if (count($categories) === 0): ?>
            <div class="alert alert-warning mb-0">
                No giving categories yet. <a href="/giving/categories" class="alert-link">Add one</a> before starting a count session.
            </div>
        <?php else: ?>
            <form method="post" action="/giving/count/save" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="create">
                <div class="col-md-2">
                    <label class="form-label small">Service date</label>
                    <input type="date" class="form-control form-control-sm" name="serviceDate" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Category / fund</label>
                    <select class="form-select form-select-sm" name="categoryID" required>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?php echo (int) $c['categoryID']; ?>"><?php echo htmlspecialchars((string) $c['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Counter 1</label>
                    <select class="form-select form-select-sm" name="counter1ID">
                        <option value="0">— Not assigned —</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?php echo (int) $u['userID']; ?>"><?php echo htmlspecialchars((string) $u['fullName'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Counter 2</label>
                    <select class="form-select form-select-sm" name="counter2ID">
                        <option value="0">— Not assigned —</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?php echo (int) $u['userID']; ?>"><?php echo htmlspecialchars((string) $u['fullName'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1">
                    <button class="btn btn-primary btn-sm w-100" type="submit"><i class="fa-solid fa-plus"></i></button>
                </div>
            </form>
            <p class="small text-muted mt-2 mb-0">Counters can be assigned later from the session page. Both totals are entered independently — assigning the same person to both slots defeats the point of the two-person count.</p>
        <?php endif; ?>
    </div>
</div>

<!-- 🔍 Status filter -->
<form method="get" class="row g-2 align-items-end mb-3">
    <div class="col-md-3">
        <label class="form-label small">Status</label>
        <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
            <option value="">All</option>
            <?php foreach ($validStatuses as $s): ?>
                <option value="<?php echo htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $filterStatus === $s ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucfirst($s), ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</form>

<?php if (count($sessions) === 0): ?>
    <div class="alert alert-info">No count sessions <?php echo $filterStatus !== '' ? 'with status "' . htmlspecialchars($filterStatus, ENT_QUOTES, 'UTF-8') . '"' : 'yet'; ?>.</div>
<?php else: ?>
    <div class="portal-data-list">
        <div class="portal-data-row portal-data-header d-none d-md-flex">
            <div class="col-md-2">Service Date</div>
            <div class="col-md-2">Category</div>
            <div class="col-md-3">Counters</div>
            <div class="col-md-2 text-end">Total (agreed)</div>
            <div class="col-md-1 text-center">Status</div>
            <div class="col-md-2 text-end">Actions</div>
        </div>
        <?php foreach ($sessions as $s):
            $agreedTotal = null;
            if ($s['cashTotal'] !== null && $s['chequeTotal'] !== null && $s['envelopeTotal'] !== null) {
                $agreedTotal = (float) $s['cashTotal'] + (float) $s['chequeTotal'] + (float) $s['envelopeTotal'];
            }
        ?>
            <div class="portal-data-row">
                <div class="col-12 col-md-2">
                    <span class="d-md-none fw-semibold">Date: </span>
                    <strong><?php echo htmlspecialchars(date('d/m/Y', (int) strtotime((string) $s['serviceDate'])), ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
                <div class="col-12 col-md-2">
                    <span class="d-md-none fw-semibold">Category: </span>
                    <?php echo htmlspecialchars((string) $s['categoryName'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="col-12 col-md-3 small text-muted">
                    <span class="d-md-none fw-semibold">Counters: </span>
                    <?php echo htmlspecialchars((string) ($s['counter1Name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                    &amp;
                    <?php echo htmlspecialchars((string) ($s['counter2Name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="col-12 col-md-2 text-md-end">
                    <span class="d-md-none fw-semibold">Total: </span>
                    <strong><?php echo $agreedTotal !== null ? '&pound;' . number_format($agreedTotal, 2) : '—'; ?></strong>
                </div>
                <div class="col-12 col-md-1 text-md-center">
                    <span class="badge bg-<?php echo $statusClass((string) $s['status']); ?>"><?php echo htmlspecialchars((string) $s['status'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="col-12 col-md-2 text-md-end">
                    <a href="/giving/count/session?id=<?php echo (int) $s['countSessionID']; ?>" class="btn btn-sm btn-outline-primary">
                        <i class="fa-solid fa-magnifying-glass me-1"></i>Open
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
