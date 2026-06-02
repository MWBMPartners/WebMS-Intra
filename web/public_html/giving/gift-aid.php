<?php
// Path: public_html/giving/gift-aid.php
/**
 * Giving — Gift Aid declaration management.
 *
 * Members see their own declaration state + a digital acceptance form;
 * treasurers see every declaration on the site.
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
$isMgr    = Giving::canManage();
$settings = App::settings()['giving'] ?? [];

// Member view: their own declarations only.
$mine = [];
$stmt = $db->prepare('SELECT declarationID, status, validFrom, validTo, acceptedAt FROM tblGiftAidDeclaration WHERE siteID = ? AND donorID = ? ORDER BY validFrom DESC');
if ($stmt !== false) {
    $stmt->bind_param('ii', $siteId, $userId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $mine[] = $r;
    }
    $stmt->close();
}

// Treasurer view: every declaration with totals.
$all = [];
if ($isMgr === true) {
    $stmt = $db->prepare(
        'SELECT d.declarationID, d.status, d.validFrom, d.validTo, u.fullName, u.userID '
        . 'FROM tblGiftAidDeclaration d INNER JOIN tblUsers u ON u.userID = d.donorID '
        . 'WHERE d.siteID = ? ORDER BY u.fullName, d.validFrom DESC'
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) {
            $all[] = $r;
        }
        $stmt->close();
    }
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'Gift Aid';
$pageSection = 'giving';
$breadcrumbs = ['Dashboard' => '/', 'Giving' => '/giving', 'Gift Aid' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-file-signature me-2"></i>Gift Aid</h1>
<p class="text-secondary">Gift Aid lets the charity reclaim 25p in every £1 you donate, at no cost to you. To qualify you must be a UK taxpayer.</p>

<div class="card mb-4">
    <div class="card-header"><strong>My declarations</strong></div>
    <div class="card-body">
        <?php if (count($mine) === 0): ?>
            <p class="text-muted">You have no Gift Aid declarations on file.</p>
        <?php else: ?>
            <div class="portal-data-list mb-3">
                <?php foreach ($mine as $m): ?>
                    <div class="row py-1 border-bottom small">
                        <div class="col-md-3">
                            <?php $cls = match ((string) $m['status']) { 'active' => 'success', 'lapsed' => 'warning', default => 'secondary' }; ?>
                            <span class="badge bg-<?php echo $cls; ?>"><?php echo htmlspecialchars((string) $m['status'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="col-md-3"><?php echo htmlspecialchars(date('d/m/Y', (int) strtotime((string) $m['validFrom'])), ENT_QUOTES, 'UTF-8'); ?>
                            → <?php echo $m['validTo'] !== null ? htmlspecialchars(date('d/m/Y', (int) strtotime((string) $m['validTo'])), ENT_QUOTES, 'UTF-8') : 'ongoing'; ?>
                        </div>
                        <div class="col-md-6 text-muted">Accepted <?php echo htmlspecialchars((string) ($m['acceptedAt'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="/giving/gad-save" class="border-top pt-3">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="accept">
            <p>
                I want <?php echo htmlspecialchars((string) ($settings['charityName'] ?? 'this charity'), ENT_QUOTES, 'UTF-8'); ?>
                to treat all qualifying gifts of money made today, in the past four years,
                and in the future as Gift Aid donations. I confirm I have paid or will pay
                an amount of UK Income Tax and/or Capital Gains Tax for the current tax year
                at least equal to the tax all charities will reclaim on my donations.
            </p>
            <div class="row g-2">
                <div class="col-md-8">
                    <label class="form-label small">Home address (required for HMRC)</label>
                    <input type="text" class="form-control form-control-sm" name="address" maxlength="500" placeholder="42 Mill Road, Cambridge">
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Postcode</label>
                    <input type="text" class="form-control form-control-sm" name="postcode" maxlength="20" placeholder="CB1 2AB">
                </div>
            </div>
            <button class="btn btn-primary btn-sm mt-3" type="submit"><i class="fa-solid fa-check me-1"></i>I accept this declaration</button>
        </form>
    </div>
</div>

<?php if ($isMgr === true && count($all) > 0): ?>
    <div class="card">
        <div class="card-header"><strong>All declarations (treasurer)</strong></div>
        <div class="card-body">
            <div class="portal-data-list">
                <?php foreach ($all as $a): ?>
                    <div class="row py-1 border-bottom small">
                        <div class="col-md-4"><strong><?php echo htmlspecialchars((string) $a['fullName'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div class="col-md-2"><span class="badge bg-secondary"><?php echo htmlspecialchars((string) $a['status'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div class="col-md-4 text-muted"><?php echo htmlspecialchars(date('d/m/Y', (int) strtotime((string) $a['validFrom'])), ENT_QUOTES, 'UTF-8'); ?>
                            → <?php echo $a['validTo'] !== null ? htmlspecialchars(date('d/m/Y', (int) strtotime((string) $a['validTo'])), ENT_QUOTES, 'UTF-8') : 'ongoing'; ?>
                        </div>
                        <div class="col-md-2 text-end">
                            <?php if ((string) $a['status'] === 'active'): ?>
                                <form method="post" action="/giving/gad-save" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="withdraw">
                                    <input type="hidden" name="declarationID" value="<?php echo (int) $a['declarationID']; ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Mark this declaration withdrawn?">Withdraw</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
