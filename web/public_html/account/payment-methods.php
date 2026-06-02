<?php
// Path: public_html/account/payment-methods.php
/**
 * Account — saved payment methods (tokenised refs only — no card data
 * ever lives in this DB).
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

$methods = [];
$stmt = $db->prepare('SELECT methodID, provider, label, isDefault, createdAt FROM tblPaymentMethod WHERE siteID = ? AND userID = ? ORDER BY isDefault DESC, createdAt DESC');
if ($stmt !== false) {
    $stmt->bind_param('ii', $siteId, $userId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $methods[] = $r;
    }
    $stmt->close();
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'Payment methods';
$pageSection = 'account';
$breadcrumbs = ['Dashboard' => '/', 'My Account' => '/account', 'Payment methods' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-credit-card me-2"></i>Payment methods</h1>
<p class="text-secondary">Card details are stored only with our payment provider — never on this portal.</p>

<?php if (count($methods) === 0): ?>
    <div class="alert alert-info">No saved payment methods yet. They'll appear here after your first successful payment with "save for later".</div>
<?php else: ?>
    <div class="card"><div class="card-body">
        <div class="portal-data-list">
            <?php foreach ($methods as $m): ?>
                <div class="row py-2 border-bottom align-items-center">
                    <div class="col-md-3"><span class="badge bg-secondary"><?php echo htmlspecialchars((string) $m['provider'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                    <div class="col-md-5"><?php echo htmlspecialchars((string) ($m['label'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                        <?php if ((int) $m['isDefault'] === 1): ?><span class="badge bg-success ms-1">default</span><?php endif; ?>
                    </div>
                    <div class="col-md-2 small text-muted"><?php echo htmlspecialchars(date('j M Y', (int) strtotime((string) $m['createdAt'])), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-md-2 text-end">
                        <form method="post" action="/account/payment-methods/delete" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="methodID" value="<?php echo (int) $m['methodID']; ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Forget this card?">
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
