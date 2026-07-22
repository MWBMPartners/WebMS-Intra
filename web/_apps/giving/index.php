<?php
// Path: public_html/giving/index.php
/**
 * Giving — my contributions. Logged-in member sees their own entries.
 *
 * @package   Portal\Giving
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/266
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Giving;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db       = App::db();
$siteId   = Site::id();
$userId   = (int) ($_SESSION['user_id'] ?? 0);
$settings = App::settings()['giving'] ?? [];
$currency = (string) ($settings['currency'] ?? 'GBP');

$entries = [];
$stmt = $db->prepare(
    'SELECT e.entryID, e.donatedAt, e.amountPence, e.currency, e.method, e.reference, e.notes, '
    . '       c.name AS categoryName '
    . 'FROM tblGivingEntry e INNER JOIN tblGivingCategory c ON c.categoryID = e.categoryID '
    . 'WHERE e.siteID = ? AND e.donorID = ? ORDER BY e.donatedAt DESC, e.entryID DESC LIMIT 200'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $siteId, $userId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $entries[] = $r;
    }
    $stmt->close();
}

$ytdTotal = 0;
$year = (int) date('Y');
foreach ($entries as $e) {
    if ((int) date('Y', (int) strtotime((string) $e['donatedAt'])) === $year) {
        $ytdTotal += (int) $e['amountPence'];
    }
}

$hasDeclaration = Giving::hasActiveDeclaration($siteId, $userId, date('Y-m-d'));

$pageTitle   = 'My Giving';
$pageSection = 'giving';
$breadcrumbs = ['Dashboard' => '/', 'Giving' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-hand-holding-dollar me-2"></i>My Giving</h1>
        <p class="text-secondary mb-0">Your contribution history. <?php echo (int) $year; ?> total: <strong><?php echo htmlspecialchars(Giving::formatAmount($ytdTotal, $currency), ENT_QUOTES, 'UTF-8'); ?></strong></p>
    </div>
    <div class="d-flex gap-2">
        <a href="/giving/campaigns" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-bullseye me-1"></i>Campaigns</a>
        <a href="/giving/my-statement" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-file-pdf me-1"></i>Year-end statement</a>
        <a href="/giving/gift-aid" class="btn btn-outline-<?php echo $hasDeclaration === true ? 'success' : 'warning'; ?> btn-sm">
            <i class="fa-solid fa-file-signature me-1"></i>Gift Aid
            <?php if ($hasDeclaration === false): ?>(needed)<?php endif; ?>
        </a>
        <?php if (Giving::canManage() === true): ?>
            <a href="/giving/manage" class="btn btn-primary btn-sm"><i class="fa-solid fa-gear me-1"></i>Manage</a>
        <?php endif; ?>
    </div>
</div>

<?php if (count($entries) === 0): ?>
    <div class="alert alert-info">No contributions recorded yet.</div>
<?php else: ?>
    <div class="card">
        <div class="card-body">
            <div class="portal-data-list">
                <?php foreach ($entries as $e): ?>
                    <div class="row py-2 border-bottom">
                        <div class="col-md-2 small text-muted"><?php echo htmlspecialchars(date('d/m/Y', (int) strtotime((string) $e['donatedAt'])), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-4"><strong><?php echo htmlspecialchars((string) $e['categoryName'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div class="col-md-2 small text-muted"><?php echo htmlspecialchars((string) $e['method'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-2 small text-muted"><?php echo htmlspecialchars((string) ($e['reference'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-2 text-end"><strong><?php echo htmlspecialchars(Giving::formatAmount((int) $e['amountPence'], (string) ($e['currency'] ?? $currency)), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
