<?php
// Path: public_html/projects/my-pledges.php
/**
 * Projects — member's own pledge history.
 *
 * @package   Portal\Projects
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/267
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$userId = (int) ($_SESSION['user_id'] ?? 0);

// 💳 "Pay now" entry point (#268) — only offered when Payments has a
// provider enabled AND the pledge's own project currency matches the
// configured checkout currency (gap-item Q5: `/payments/checkout` always
// charges `payments.currency`, so a mismatched project would otherwise be
// silently charged in the wrong currency at face value).
$paymentsSettings = App::settings()['payments'] ?? [];
$paymentsEnabled  = (string) ($paymentsSettings['enabled'] ?? '0') === '1';
$paymentsCurrency = (string) ($paymentsSettings['currency'] ?? 'GBP');
$csrf = Auth::csrfToken();

$rows = [];
$stmt = $db->prepare(
    'SELECT p.pledgeID, p.amountPence, p.pledgedAt, p.fulfilledAt, p.message, '
    . '       pr.title, pr.slug, pr.currency '
    . 'FROM tblProjectPledge p INNER JOIN tblProject pr ON pr.projectID = p.projectID '
    . 'WHERE p.donorID = ? ORDER BY p.pledgedAt DESC LIMIT 200'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
}

$pageTitle   = 'My Pledges';
$pageSection = 'projects';
$breadcrumbs = ['Dashboard' => '/', 'Projects' => '/projects', 'My pledges' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-3"><i class="fa-solid fa-heart me-2"></i>My pledges</h1>

<?php if (count($rows) === 0): ?>
    <div class="alert alert-info">You haven't pledged to any projects yet. <a href="/projects">Browse projects →</a></div>
<?php else: ?>
    <div class="card"><div class="card-body">
        <div class="portal-data-list">
            <?php foreach ($rows as $r):
                $cur = (string) ($r['currency'] ?? 'GBP');
                $sym = match ($cur) { 'GBP' => '£', 'EUR' => '€', 'USD' => '$', default => $cur . ' ' };
            ?>
                <div class="row py-2 border-bottom">
                    <div class="col-md-5"><a href="/projects/view?slug=<?php echo urlencode((string) $r['slug']); ?>"><strong><?php echo htmlspecialchars((string) $r['title'], ENT_QUOTES, 'UTF-8'); ?></strong></a></div>
                    <div class="col-md-2"><?php echo $sym . number_format(((int) $r['amountPence']) / 100, 2); ?></div>
                    <div class="col-md-3 small text-muted"><?php echo htmlspecialchars(date('j M Y', (int) strtotime((string) $r['pledgedAt'])), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-md-2 small">
                        <?php if ($r['fulfilledAt'] !== null): ?>
                            <span class="badge bg-success">Fulfilled</span>
                        <?php else: ?>
                            <span class="badge bg-warning">Pending</span>
                            <?php if ($paymentsEnabled === true && $cur === $paymentsCurrency): ?>
                                <form method="post" action="/payments/checkout" class="d-inline ms-1">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="purpose" value="pledge">
                                    <input type="hidden" name="purposeRef" value="<?php echo (int) $r['pledgeID']; ?>">
                                    <?php /* checkout.php's amount floor-check runs BEFORE the pledge branch overrides
                                             the amount from the pledge row itself — this placeholder value is
                                             discarded the moment the pledge branch runs; it only needs to clear
                                             the 100-pence floor. */ ?>
                                    <input type="hidden" name="amount" value="1.00">
                                    <input type="hidden" name="return_to" value="/projects/my-pledges">
                                    <button type="submit" class="btn btn-sm btn-outline-success">Pay now</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div></div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
