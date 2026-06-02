<?php
// Path: public_html/account/recurring.php
/**
 * Account — recurring payments (filter on tblPayment.isRecurring).
 *
 * Cancellation is provider-dependent: subscriptions land via webhook on
 * the relevant provider's invoice events. Today we surface them
 * read-only — cancel flow lands in the per-provider follow-up PR.
 *
 * @package   Portal\Account
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/268
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$rows = [];
$stmt = $db->prepare(
    'SELECT paymentID, provider, amountPence, currency, purpose, occurredAt '
    . 'FROM tblPayment WHERE siteID = ? AND userID = ? AND isRecurring = 1 '
    . 'ORDER BY occurredAt DESC LIMIT 100'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $siteId, $userId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
}

$pageTitle   = 'Recurring payments';
$pageSection = 'account';
$breadcrumbs = ['Dashboard' => '/', 'My Account' => '/account', 'Recurring' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-3"><i class="fa-solid fa-rotate me-2"></i>Recurring payments</h1>

<?php if (count($rows) === 0): ?>
    <div class="alert alert-info">You have no active recurring payments.</div>
<?php else: ?>
    <div class="card"><div class="card-body">
        <div class="portal-data-list">
            <?php foreach ($rows as $r):
                $sym = match ((string) $r['currency']) { 'GBP' => '£', 'EUR' => '€', 'USD' => '$', default => $r['currency'] . ' ' };
            ?>
                <div class="row py-1 border-bottom small">
                    <div class="col-md-3"><strong><?php echo $sym . number_format(((int) $r['amountPence']) / 100, 2); ?></strong></div>
                    <div class="col-md-3"><span class="badge bg-light text-dark"><?php echo htmlspecialchars((string) $r['purpose'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                    <div class="col-md-3 text-muted"><?php echo htmlspecialchars((string) $r['provider'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-md-3 text-muted"><?php echo htmlspecialchars((string) ($r['occurredAt'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="small text-muted mt-3 mb-0">To cancel a recurring payment, contact the treasurer — full self-cancel UI lands with the PayPal / GoCardless adapter PRs.</p>
    </div></div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
