<?php
// Path: public_html/giving/manage.php
/**
 * Giving — treasurer manage view: filter, browse, and record entries.
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

$db       = App::db();
$siteId   = Site::id();
$settings = App::settings()['giving'] ?? [];
$currency = (string) ($settings['currency'] ?? 'GBP');

$from = (string) ($_GET['from'] ?? date('Y-01-01'));
$to   = (string) ($_GET['to']   ?? date('Y-m-d'));
$catId = (int)    ($_GET['cat']  ?? 0);
$donorQ = trim((string) ($_GET['donor'] ?? ''));

$sql = 'SELECT e.entryID, e.donatedAt, e.amountPence, e.currency, e.method, e.reference, '
    . '       e.donorID, e.donorName, c.name AS categoryName, u.fullName AS donorFullName, '
    . '       pc.name AS campaignName '
    . 'FROM tblGivingEntry e '
    . 'INNER JOIN tblGivingCategory c ON c.categoryID = e.categoryID '
    . 'LEFT JOIN tblUsers u ON u.userID = e.donorID '
    . 'LEFT JOIN tblPledgeCampaigns pc ON pc.campaignID = e.campaignID '
    . 'WHERE e.siteID = ? AND e.donatedAt BETWEEN ? AND ?';
$types  = 'iss';
$params = [$siteId, $from, $to];
if ($catId > 0) {
    $sql .= ' AND e.categoryID = ?';
    $types .= 'i';
    $params[] = $catId;
}
if ($donorQ !== '') {
    $sql .= ' AND (u.fullName LIKE ? OR e.donorName LIKE ?)';
    $like = '%' . $donorQ . '%';
    $types .= 'ss';
    $params[] = $like;
    $params[] = $like;
}
$sql .= ' ORDER BY e.donatedAt DESC, e.entryID DESC LIMIT 500';

$entries = [];
$stmt = $db->prepare($sql);
if ($stmt !== false) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $entries[] = $r;
    }
    $stmt->close();
}

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

// 🎯 Active pledge campaigns (#299 sub-feature 2) — for the "Record an
// entry" form's Campaign selector (Auto / None / explicit choice).
$activeCampaigns = [];
$stmt = $db->prepare('SELECT campaignID, name FROM tblPledgeCampaigns WHERE siteID = ? AND isActive = 1 ORDER BY name');
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $activeCampaigns[] = $r;
    }
    $stmt->close();
}

$total = array_sum(array_map(static fn (array $e): int => (int) $e['amountPence'], $entries));

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'Manage Giving';
$pageSection = 'giving';
$breadcrumbs = ['Dashboard' => '/', 'Giving' => '/giving', 'Manage' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-hand-holding-dollar me-2"></i>Manage Giving</h1>
    <div class="d-flex gap-2">
        <a href="/giving/campaigns" class="btn btn-outline-secondary btn-sm">Campaigns</a>
        <a href="/giving/categories" class="btn btn-outline-secondary btn-sm">Categories</a>
        <a href="/giving/reports" class="btn btn-outline-secondary btn-sm">Reports</a>
        <a href="/giving/hmrc-export?from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>" class="btn btn-outline-warning btn-sm"><i class="fa-solid fa-file-csv me-1"></i>HMRC CSV</a>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><strong>Record an entry</strong></div>
    <div class="card-body">
        <form method="post" action="/giving/entry-save" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="col-md-3">
                <label class="form-label small">Donor (search by name)</label>
                <input type="text" class="form-control form-control-sm" name="donorName" list="giving-donors" placeholder="Member name or anonymous">
                <datalist id="giving-donors">
                    <?php
                    $r = $db->query('SELECT userID, fullName, emailAddress FROM tblUsers WHERE isActive = 1 ORDER BY fullName LIMIT 500');
                    if ($r !== false) {
                        while ($u = $r->fetch_assoc()) {
                            echo '<option data-id="' . (int) $u['userID'] . '" value="' . htmlspecialchars((string) $u['fullName'], ENT_QUOTES, 'UTF-8') . '">';
                        }
                        $r->free();
                    }
                    ?>
                </datalist>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Category</label>
                <select class="form-select form-select-sm" name="categoryID" required>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?php echo (int) $c['categoryID']; ?>"><?php echo htmlspecialchars((string) $c['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Amount</label>
                <input type="text" class="form-control form-control-sm" name="amount" required placeholder="50.00">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Date</label>
                <input type="date" class="form-control form-control-sm" name="donatedAt" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Method</label>
                <select class="form-select form-select-sm" name="method">
                    <option value="cash">Cash</option>
                    <option value="cheque">Cheque</option>
                    <option value="bank-transfer">Bank transfer</option>
                    <option value="card">Card</option>
                    <option value="standing-order">Standing order</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Campaign</label>
                <select class="form-select form-select-sm" name="campaignID">
                    <option value="0">Auto (donor's open pledge)</option>
                    <option value="-1">None</option>
                    <?php foreach ($activeCampaigns as $ac): ?>
                        <option value="<?php echo (int) $ac['campaignID']; ?>"><?php echo htmlspecialchars((string) $ac['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <button class="btn btn-primary btn-sm w-100" type="submit"><i class="fa-solid fa-plus"></i></button>
            </div>
            <div class="col-md-6">
                <input type="text" class="form-control form-control-sm" name="reference" placeholder="Reference (cheque no., transfer ref…)">
            </div>
            <div class="col-md-6">
                <input type="text" class="form-control form-control-sm" name="notes" placeholder="Notes (optional)">
            </div>
        </form>
        <p class="small text-muted mt-2">Leave the donor blank for anonymous cash. Use a member name from the dropdown to attribute.</p>
    </div>
</div>

<form method="get" class="row g-2 align-items-end mb-3">
    <div class="col-md-2">
        <label class="form-label small">From</label>
        <input type="date" name="from" value="<?php echo htmlspecialchars($from, ENT_QUOTES, 'UTF-8'); ?>" class="form-control form-control-sm">
    </div>
    <div class="col-md-2">
        <label class="form-label small">To</label>
        <input type="date" name="to" value="<?php echo htmlspecialchars($to, ENT_QUOTES, 'UTF-8'); ?>" class="form-control form-control-sm">
    </div>
    <div class="col-md-3">
        <label class="form-label small">Category</label>
        <select class="form-select form-select-sm" name="cat">
            <option value="0">All</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?php echo (int) $c['categoryID']; ?>" <?php echo $catId === (int) $c['categoryID'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $c['name'], ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label small">Donor</label>
        <input type="text" name="donor" value="<?php echo htmlspecialchars($donorQ, ENT_QUOTES, 'UTF-8'); ?>" class="form-control form-control-sm">
    </div>
    <div class="col-md-2">
        <button class="btn btn-outline-primary btn-sm w-100">Filter</button>
    </div>
</form>

<div class="alert alert-light border d-flex justify-content-between align-items-center">
    <strong><?php echo count($entries); ?></strong> entries
    <strong>Total: <?php echo htmlspecialchars(Giving::formatAmount($total, $currency), ENT_QUOTES, 'UTF-8'); ?></strong>
</div>

<?php if (count($entries) === 0): ?>
    <div class="alert alert-info">No entries match the filter.</div>
<?php else: ?>
    <div class="card"><div class="card-body">
        <div class="portal-data-list">
            <?php foreach ($entries as $e):
                $donor = (string) ($e['donorFullName'] ?? '') !== ''
                    ? (string) $e['donorFullName']
                    : ((string) ($e['donorName'] ?? '') !== '' ? (string) $e['donorName'] : 'Anonymous');
            ?>
                <div class="row py-1 border-bottom small">
                    <div class="col-md-1"><?php echo htmlspecialchars(date('d/m', (int) strtotime((string) $e['donatedAt'])), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-md-3"><?php echo htmlspecialchars($donor, ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-md-2">
                        <?php echo htmlspecialchars((string) $e['categoryName'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php if ((string) ($e['campaignName'] ?? '') !== ''): ?>
                            <span class="badge text-bg-info"><?php echo htmlspecialchars((string) $e['campaignName'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-2 text-muted"><?php echo htmlspecialchars((string) $e['method'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-md-2 text-muted"><?php echo htmlspecialchars((string) ($e['reference'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-md-1 text-end"><strong><?php echo htmlspecialchars(Giving::formatAmount((int) $e['amountPence'], (string) ($e['currency'] ?? $currency)), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    <div class="col-md-1 text-end">
                        <form method="post" action="/giving/entry-delete" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="entryID" value="<?php echo (int) $e['entryID']; ?>">
                            <button type="submit" class="btn btn-link btn-sm text-danger p-0" data-confirm="Delete this entry?">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div></div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
