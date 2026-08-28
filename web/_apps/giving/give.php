<?php
// Path: public_html/giving/give.php
/**
 * Giving — "Give online" page. Presents the site's active giving categories
 * and an amount field, then POSTs to the shared, hardened
 * `/payments/checkout` endpoint — provider-agnostic (Stripe or PayPal
 * answers depending on `payments.provider`). The portal never handles raw
 * card details; the payer is redirected to the provider's own hosted
 * checkout page.
 *
 * @package   Portal\Giving
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/268
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db       = App::db();
$siteId   = Site::id();
$settings = App::settings()['payments'] ?? [];
$enabled  = (string) ($settings['enabled'] ?? '0') === '1';
$currency = (string) ($settings['currency'] ?? 'GBP');
$testMode = (string) ($settings['test_mode'] ?? '1') === '1';
$sym      = match ($currency) { 'GBP' => '£', 'EUR' => '€', 'USD' => '$', default => $currency . ' ' };

// 🛒 Active giving categories, site-scoped. Zero categories → friendly
// "not set up yet" card rather than an empty/broken form.
$categories = [];
if ($enabled === true) {
    $stmt = $db->prepare(
        'SELECT categoryID, name, description FROM tblGivingCategory '
        . 'WHERE siteID = ? AND isActive = 1 ORDER BY sortOrder, name'
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) {
            $categories[] = $r;
        }
        $stmt->close();
    }
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'Give Online';
$pageSection = 'giving';
$breadcrumbs = ['Dashboard' => '/', 'Giving' => '/giving', 'Give online' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-heart me-2"></i>Give online
    <?php if ($enabled === true && $testMode === true): ?><span class="badge bg-warning ms-2">TEST MODE</span><?php endif; ?>
</h1>

<?php if ($enabled === false): ?>
    <div class="card"><div class="card-body">
        <div class="alert alert-info mb-0">Online giving isn't available yet — please speak to the office about other ways to give.</div>
    </div></div>
<?php elseif (count($categories) === 0): ?>
    <div class="card"><div class="card-body">
        <div class="alert alert-info mb-0">Online giving isn't set up yet — please check back soon.</div>
    </div></div>
<?php else: ?>
    <div class="card" style="max-width: 560px;">
        <div class="card-body">
            <form method="post" action="/payments/checkout">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="purpose" value="giving">
                <input type="hidden" name="return_to" value="/giving/give">

                <div class="mb-3">
                    <label class="form-label" for="give-category">Giving to</label>
                    <select class="form-select" id="give-category" name="purposeRef" required>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?php echo (int) $c['categoryID']; ?>"><?php echo htmlspecialchars((string) $c['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-2">
                    <label class="form-label" for="give-amount">Amount</label>
                    <div class="input-group">
                        <span class="input-group-text"><?php echo htmlspecialchars($sym, ENT_QUOTES, 'UTF-8'); ?></span>
                        <input type="text" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,2})?" min="1.00" class="form-control" id="give-amount" name="amount" placeholder="0.00" required>
                    </div>
                    <div class="form-text">Minimum <?php echo htmlspecialchars($sym, ENT_QUOTES, 'UTF-8'); ?>1.00. The server always re-checks this regardless of what's typed here.</div>
                </div>
                <div class="mb-3 d-flex gap-2">
                    <?php foreach ([10, 20, 50] as $quick): ?>
                        <button type="button" class="btn btn-outline-secondary btn-sm portal-give-quick" data-amount="<?php echo (int) $quick; ?>.00"><?php echo htmlspecialchars($sym, ENT_QUOTES, 'UTF-8') . (int) $quick; ?></button>
                    <?php endforeach; ?>
                </div>

                <p class="small text-muted">
                    <i class="fa-solid fa-lock me-1"></i>You will be redirected to our secure payment provider to enter your card details — we never see or store them.
                </p>

                <button type="submit" class="btn btn-success w-100">Continue to secure payment</button>
            </form>
        </div>
    </div>

    <script>
    // 🖱️ Progressive enhancement only — the server revalidates the amount
    // (floor + ceiling) regardless of what these quick-amount buttons set.
    document.querySelectorAll('.portal-give-quick').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById('give-amount');
            if (input !== null) {
                input.value = btn.getAttribute('data-amount');
            }
        });
    });
    </script>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
