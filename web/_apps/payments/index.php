<?php
// Path: public_html/payments/index.php
/**
 * Admin — Payments configuration + reconciliation report.
 *
 * @package   Portal\Payments
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/268
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$db       = App::db();
$siteId   = Site::id();
$settings = App::settings()['payments'] ?? [];
$enabled  = (string) ($settings['enabled'] ?? '0') === '1';
$provider = (string) ($settings['provider'] ?? 'stripe');
$testMode = (string) ($settings['test_mode'] ?? '1') === '1';
$currency = (string) ($settings['currency'] ?? 'GBP');

$stripePub  = (string) ($settings['stripe']['publishable'] ?? '');
$hasStSec   = ((string) ($settings['stripe']['secret'] ?? '')) !== '';
$hasStWh    = ((string) ($settings['stripe']['webhookSecret'] ?? '')) !== '';
$ppClient   = (string) ($settings['paypal']['clientId'] ?? '');
$hasPpSec   = ((string) ($settings['paypal']['secret'] ?? '')) !== '';
$hasGcTok   = ((string) ($settings['gocardless']['token'] ?? '')) !== '';

// Reconciliation snapshot for the current month.
$monthAgg = ['count' => 0, 'gross' => 0, 'fees' => 0, 'refunded' => 0];
$stmt = $db->prepare(
    'SELECT COUNT(*) AS n, COALESCE(SUM(amountPence),0) AS gross, '
    . '       COALESCE(SUM(feePence),0) AS fees, '
    . '       COALESCE(SUM(CASE WHEN status = "refunded" THEN amountPence ELSE 0 END),0) AS refunded '
    . 'FROM tblPayment WHERE siteID = ? AND createdAt >= DATE_FORMAT(NOW(), "%Y-%m-01")'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $monthAgg = $stmt->get_result()->fetch_assoc() ?? $monthAgg;
    $stmt->close();
}

$recent = [];
$stmt = $db->prepare(
    'SELECT p.paymentID, p.provider, p.providerRef, p.amountPence, p.currency, p.status, '
    . '       p.purpose, p.createdAt, u.fullName '
    . 'FROM tblPayment p LEFT JOIN tblUsers u ON u.userID = p.userID '
    . 'WHERE p.siteID = ? ORDER BY p.createdAt DESC LIMIT 50'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $recent[] = $r;
    }
    $stmt->close();
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$sym = match ($currency) { 'GBP' => '£', 'EUR' => '€', 'USD' => '$', default => $currency . ' ' };

$pageTitle   = 'Payments';
$pageSection = 'payments';
$breadcrumbs = ['Dashboard' => '/', 'Payments' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-credit-card me-2"></i>Payments
    <?php if ($testMode === true): ?><span class="badge bg-warning ms-2">TEST MODE</span><?php endif; ?>
</h1>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card"><div class="card-body"><div class="small text-muted">This month gross</div><div class="display-6"><?php echo $sym . number_format(((int) $monthAgg['gross']) / 100, 2); ?></div></div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body"><div class="small text-muted">Processor fees</div><div class="display-6"><?php echo $sym . number_format(((int) $monthAgg['fees']) / 100, 2); ?></div></div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body"><div class="small text-muted">Refunded</div><div class="display-6"><?php echo $sym . number_format(((int) $monthAgg['refunded']) / 100, 2); ?></div></div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body"><div class="small text-muted">Payments</div><div class="display-6"><?php echo (int) $monthAgg['count']; ?></div></div></div></div>
</div>

<div class="card mb-3">
    <div class="card-header"><strong>Provider configuration</strong></div>
    <div class="card-body">
        <form method="post" action="/payments/save" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="col-md-3">
                <label class="form-label">Provider</label>
                <select class="form-select" name="provider">
                    <option value="stripe"     <?php echo $provider === 'stripe'     ? 'selected' : ''; ?>>Stripe</option>
                    <option value="paypal"     <?php echo $provider === 'paypal'     ? 'selected' : ''; ?>>PayPal (follow-up)</option>
                    <option value="gocardless" <?php echo $provider === 'gocardless' ? 'selected' : ''; ?>>GoCardless (follow-up)</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Currency</label>
                <select class="form-select" name="currency">
                    <?php foreach (['GBP','EUR','USD'] as $c): ?>
                        <option value="<?php echo $c; ?>" <?php echo $currency === $c ? 'selected' : ''; ?>><?php echo $c; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="testMode" name="test_mode" value="1" <?php echo $testMode === true ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="testMode">Test mode</label>
                </div>
                <div class="form-check ms-3">
                    <input type="checkbox" class="form-check-input" id="enabled" name="enabled" value="1" <?php echo $enabled === true ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="enabled">Enable</label>
                </div>
            </div>
            <hr>
            <div class="col-md-6">
                <h6 class="text-muted">Stripe</h6>
                <label class="form-label small">Publishable key</label>
                <input type="text" class="form-control form-control-sm" name="stripe_pub" value="<?php echo htmlspecialchars($stripePub, ENT_QUOTES, 'UTF-8'); ?>">
                <label class="form-label small mt-2">Secret key <?php echo $hasStSec === true ? '<span class="badge bg-success">set</span>' : ''; ?></label>
                <input type="password" class="form-control form-control-sm" name="stripe_secret" placeholder="<?php echo $hasStSec === true ? 'Leave blank to keep' : ''; ?>" autocomplete="off">
                <label class="form-label small mt-2">Webhook secret <?php echo $hasStWh === true ? '<span class="badge bg-success">set</span>' : ''; ?></label>
                <input type="password" class="form-control form-control-sm" name="stripe_wh" placeholder="<?php echo $hasStWh === true ? 'Leave blank to keep' : ''; ?>" autocomplete="off">
                <small class="text-muted">Webhook URL: <code><?php echo htmlspecialchars((($_SERVER['HTTPS'] ?? '') !== '' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/payments/webhook?provider=stripe', ENT_QUOTES, 'UTF-8'); ?></code></small>
            </div>
            <div class="col-md-3">
                <h6 class="text-muted">PayPal <span class="badge bg-secondary">follow-up</span></h6>
                <label class="form-label small">Client ID</label>
                <input type="text" class="form-control form-control-sm" name="pp_client" value="<?php echo htmlspecialchars($ppClient, ENT_QUOTES, 'UTF-8'); ?>">
                <label class="form-label small mt-2">Secret <?php echo $hasPpSec === true ? '<span class="badge bg-success">set</span>' : ''; ?></label>
                <input type="password" class="form-control form-control-sm" name="pp_secret" placeholder="<?php echo $hasPpSec === true ? 'Leave blank to keep' : ''; ?>" autocomplete="off">
            </div>
            <div class="col-md-3">
                <h6 class="text-muted">GoCardless <span class="badge bg-secondary">follow-up</span></h6>
                <label class="form-label small">Access token <?php echo $hasGcTok === true ? '<span class="badge bg-success">set</span>' : ''; ?></label>
                <input type="password" class="form-control form-control-sm" name="gc_token" placeholder="<?php echo $hasGcTok === true ? 'Leave blank to keep' : ''; ?>" autocomplete="off">
            </div>
            <div class="col-12">
                <button class="btn btn-primary" type="submit">Save</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><strong>Recent payments</strong></div>
    <div class="card-body">
        <?php if (count($recent) === 0): ?>
            <p class="text-muted">No payments recorded yet.</p>
        <?php else: ?>
            <div class="portal-data-list">
                <?php foreach ($recent as $p):
                    $cls = match ((string) $p['status']) {
                        'succeeded' => 'success',
                        'refunded'  => 'secondary',
                        'failed'    => 'danger',
                        default     => 'warning',
                    };
                ?>
                    <div class="row py-1 border-bottom small">
                        <div class="col-md-2 text-muted"><?php echo htmlspecialchars(date('d/m H:i', (int) strtotime((string) $p['createdAt'])), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-3"><?php echo htmlspecialchars((string) ($p['fullName'] ?? 'anon'), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-2"><span class="badge bg-light text-dark"><?php echo htmlspecialchars((string) $p['purpose'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div class="col-md-2"><?php echo $sym . number_format(((int) $p['amountPence']) / 100, 2); ?></div>
                        <div class="col-md-2"><span class="badge bg-<?php echo $cls; ?>"><?php echo htmlspecialchars((string) $p['status'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div class="col-md-1 text-end">
                            <?php if ((string) $p['status'] === 'succeeded'): ?>
                                <form method="post" action="/payments/refund" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="paymentID" value="<?php echo (int) $p['paymentID']; ?>">
                                    <button type="submit" class="btn btn-link btn-sm text-danger p-0" data-confirm="Refund this payment?">refund</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
