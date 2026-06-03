<?php
// Path: public_html/giving/reports.php
/**
 * Giving — totals by category / month / donor (treasurer only).
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

$byCategory = [];
$stmt = $db->prepare(
    'SELECT c.name, COUNT(e.entryID) AS n, COALESCE(SUM(e.amountPence), 0) AS total '
    . 'FROM tblGivingCategory c LEFT JOIN tblGivingEntry e '
    . '    ON e.categoryID = c.categoryID AND e.donatedAt BETWEEN ? AND ? '
    . 'WHERE c.siteID = ? GROUP BY c.categoryID ORDER BY total DESC'
);
if ($stmt !== false) {
    $stmt->bind_param('ssi', $from, $to, $siteId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $byCategory[] = $r;
    }
    $stmt->close();
}

$byMonth = [];
$stmt = $db->prepare(
    'SELECT DATE_FORMAT(donatedAt, "%Y-%m") AS ym, COUNT(*) AS n, SUM(amountPence) AS total '
    . 'FROM tblGivingEntry WHERE siteID = ? AND donatedAt BETWEEN ? AND ? '
    . 'GROUP BY ym ORDER BY ym DESC'
);
if ($stmt !== false) {
    $stmt->bind_param('iss', $siteId, $from, $to);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $byMonth[] = $r;
    }
    $stmt->close();
}

$byDonor = [];
$stmt = $db->prepare(
    'SELECT COALESCE(u.fullName, e.donorName, "Anonymous") AS donor, '
    . '       COUNT(e.entryID) AS n, SUM(e.amountPence) AS total '
    . 'FROM tblGivingEntry e LEFT JOIN tblUsers u ON u.userID = e.donorID '
    . 'WHERE e.siteID = ? AND e.donatedAt BETWEEN ? AND ? '
    . 'GROUP BY donor ORDER BY total DESC LIMIT 50'
);
if ($stmt !== false) {
    $stmt->bind_param('iss', $siteId, $from, $to);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $byDonor[] = $r;
    }
    $stmt->close();
}

$pageTitle   = 'Giving Reports';
$pageSection = 'giving';
$breadcrumbs = ['Dashboard' => '/', 'Giving' => '/giving', 'Reports' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-3"><i class="fa-solid fa-chart-bar me-2"></i>Giving reports</h1>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-3"><input type="date" name="from" value="<?php echo htmlspecialchars($from, ENT_QUOTES, 'UTF-8'); ?>" class="form-control form-control-sm"></div>
    <div class="col-md-3"><input type="date" name="to" value="<?php echo htmlspecialchars($to, ENT_QUOTES, 'UTF-8'); ?>" class="form-control form-control-sm"></div>
    <div class="col-md-2"><button class="btn btn-outline-primary btn-sm w-100">Update</button></div>
</form>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card"><div class="card-body">
            <h5>By category</h5>
            <div class="portal-data-list">
                <?php foreach ($byCategory as $c): ?>
                    <div class="row py-1 border-bottom small">
                        <div class="col-7"><?php echo htmlspecialchars((string) $c['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-2 text-muted text-end"><?php echo (int) $c['n']; ?></div>
                        <div class="col-3 text-end"><strong><?php echo htmlspecialchars(Giving::formatAmount((int) $c['total'], $currency), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div></div>
    </div>
    <div class="col-md-6">
        <div class="card"><div class="card-body">
            <h5>By month</h5>
            <div class="portal-data-list">
                <?php foreach ($byMonth as $m): ?>
                    <div class="row py-1 border-bottom small">
                        <div class="col-7"><?php echo htmlspecialchars((string) $m['ym'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-2 text-muted text-end"><?php echo (int) $m['n']; ?></div>
                        <div class="col-3 text-end"><strong><?php echo htmlspecialchars(Giving::formatAmount((int) $m['total'], $currency), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div></div>
    </div>
    <div class="col-12">
        <div class="card"><div class="card-body">
            <h5>Top donors</h5>
            <div class="portal-data-list">
                <?php foreach ($byDonor as $d): ?>
                    <div class="row py-1 border-bottom small">
                        <div class="col-md-7"><?php echo htmlspecialchars((string) $d['donor'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-2 text-muted text-end"><?php echo (int) $d['n']; ?> gifts</div>
                        <div class="col-md-3 text-end"><strong><?php echo htmlspecialchars(Giving::formatAmount((int) $d['total'], $currency), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div></div>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
