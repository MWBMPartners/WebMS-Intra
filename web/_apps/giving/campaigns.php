<?php
// Path: _apps/giving/campaigns.php
/**
 * -----------------------------------------------------------------------------
 * Giving — Pledge Campaigns: List 🎯
 * -----------------------------------------------------------------------------
 * GET /giving/campaigns
 *
 * Every logged-in member sees the site's active pledge campaigns as a card
 * grid with goal thermometers and their own pledge status (#299 sub-feature
 * 2). Treasurer/admin (`Giving::canManage()`) additionally sees inactive
 * campaigns (badged) and an inline "New campaign" form.
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
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db        = App::db();
$siteId    = Site::id();
$userId    = (int) ($_SESSION['user_id'] ?? 0);
$settings  = App::settings()['giving'] ?? [];
$currency  = (string) ($settings['currency'] ?? 'GBP');
$canManage = Giving::canManage();

// -----------------------------------------------------------------------------
// 📋 Campaigns — everyone sees active ones; canManage also sees inactive
// (retired) campaigns, badged, for historical reference.
// -----------------------------------------------------------------------------
$sql = 'SELECT campaignID, name, description, goalAmountPence, currency, startDate, endDate, isActive '
    . 'FROM tblPledgeCampaigns WHERE siteID = ?';
if ($canManage === false) {
    $sql .= ' AND isActive = 1';
}
$sql .= ' ORDER BY isActive DESC, startDate DESC';

$campaigns = [];
$stmt = $db->prepare($sql);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $campaigns[] = $r;
    }
    $stmt->close();
}

// 💰 Raised totals — one grouped query for the whole site (§3.1 of the build
// spec) rather than one query per card.
$raisedByCampaign = [];
$stmt = $db->prepare(
    'SELECT campaignID, COALESCE(SUM(amountPence), 0) AS raised '
    . 'FROM tblGivingEntry WHERE siteID = ? AND campaignID IS NOT NULL GROUP BY campaignID'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $raisedByCampaign[(int) $r['campaignID']] = (int) $r['raised'];
    }
    $stmt->close();
}

// 🙋 The current member's own OPEN pledges, keyed by campaignID, for the
// "You pledged £X" chip vs. a "Make a pledge" button.
$myPledges = [];
if ($userId > 0) {
    $stmt = $db->prepare(
        'SELECT campaignID, amountPence, paymentSchedule FROM tblPledges '
        . 'WHERE siteID = ? AND userID = ? AND status = \'open\''
    );
    if ($stmt !== false) {
        $stmt->bind_param('ii', $siteId, $userId);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) {
            $myPledges[(int) $r['campaignID']] = $r;
        }
        $stmt->close();
    }
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'Pledge Campaigns';
$pageSection = 'giving';
$breadcrumbs = ['Dashboard' => '/', 'Giving' => '/giving', 'Campaigns' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-bullseye me-2"></i>Pledge Campaigns</h1>
        <p class="text-secondary mb-0">Goals the church is raising toward, and your own pledges.</p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($canManage === true): ?>
            <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#newCampaignForm">
                <i class="fa-solid fa-plus me-1"></i>New campaign
            </button>
        <?php endif; ?>
        <a href="/giving/manage" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to Giving</a>
    </div>
</div>

<?php if ($canManage === true): ?>
    <div class="collapse mb-4" id="newCampaignForm">
        <div class="card">
            <div class="card-header"><strong>New campaign</strong></div>
            <div class="card-body">
                <form method="post" action="/giving/campaign-save" class="row g-2 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="campaignID" value="0">
                    <div class="col-md-4">
                        <label class="form-label small">Name</label>
                        <input type="text" class="form-control form-control-sm" name="name" required placeholder="Roof Repair Fund">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Goal amount</label>
                        <input type="text" class="form-control form-control-sm" name="goalAmount" required placeholder="5000.00">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Start date</label>
                        <input type="date" class="form-control form-control-sm" name="startDate" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">End date (optional)</label>
                        <input type="date" class="form-control form-control-sm" name="endDate">
                    </div>
                    <div class="col-md-1 form-check ms-1">
                        <input type="checkbox" class="form-check-input" name="isActive" id="newCampaignActive" value="1" checked>
                        <label class="form-check-label small" for="newCampaignActive">Active</label>
                    </div>
                    <div class="col-md-1">
                        <button class="btn btn-primary btn-sm w-100" type="submit"><i class="fa-solid fa-plus"></i></button>
                    </div>
                    <div class="col-12">
                        <textarea class="form-control form-control-sm" name="description" rows="2" placeholder="Description (optional)"></textarea>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (count($campaigns) === 0): ?>
    <div class="alert alert-info">No pledge campaigns yet.</div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($campaigns as $c):
            $campaignId = (int) $c['campaignID'];
            $goal       = (int) $c['goalAmountPence'];
            $raised     = $raisedByCampaign[$campaignId] ?? 0;
            $pct        = $goal > 0 ? intdiv($raised * 100, $goal) : 0;
            $barWidth   = min(100, $pct);
            $barClass   = $pct >= 100 ? 'bg-primary' : 'bg-success';
            $cur        = (string) ($c['currency'] ?? $currency);
            $mine       = $myPledges[$campaignId] ?? null;
        ?>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <h5 class="mb-1"><?php echo htmlspecialchars((string) $c['name'], ENT_QUOTES, 'UTF-8'); ?></h5>
                            <?php if ((int) $c['isActive'] === 0): ?>
                                <span class="badge text-bg-secondary">Retired</span>
                            <?php elseif ($pct >= 100): ?>
                                <span class="badge text-bg-success">Goal reached</span>
                            <?php endif; ?>
                        </div>
                        <p class="small text-muted mb-2">
                            <?php echo htmlspecialchars(date('d/m/Y', (int) strtotime((string) $c['startDate'])), ENT_QUOTES, 'UTF-8'); ?>
                            &ndash;
                            <?php echo $c['endDate'] !== null ? htmlspecialchars(date('d/m/Y', (int) strtotime((string) $c['endDate'])), ENT_QUOTES, 'UTF-8') : 'ongoing'; ?>
                        </p>
                        <?php if ((string) ($c['description'] ?? '') !== ''): ?>
                            <p class="small mb-2"><?php echo htmlspecialchars(mb_strimwidth((string) $c['description'], 0, 160, '…'), ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>

                        <div class="progress mb-2" style="height:14px;">
                            <div class="progress-bar <?php echo $barClass; ?>" role="progressbar" style="width: <?php echo $barWidth; ?>%;"
                                 aria-valuenow="<?php echo $pct; ?>" aria-valuemin="0" aria-valuemax="100"><?php echo $pct; ?>%</div>
                        </div>
                        <p class="small text-muted mb-3">
                            <strong><?php echo htmlspecialchars(Giving::formatAmount($raised, $cur), ENT_QUOTES, 'UTF-8'); ?></strong>
                            of <?php echo htmlspecialchars(Giving::formatAmount($goal, $cur), ENT_QUOTES, 'UTF-8'); ?>
                        </p>

                        <?php if ($mine !== null): ?>
                            <span class="badge text-bg-info">
                                You pledged <?php echo htmlspecialchars(Giving::formatAmount((int) $mine['amountPence'], $cur), ENT_QUOTES, 'UTF-8'); ?>
                                <?php echo htmlspecialchars((string) $mine['paymentSchedule'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <a href="/giving/campaign?id=<?php echo $campaignId; ?>" class="btn btn-outline-secondary btn-sm ms-2">Manage pledge</a>
                        <?php else: ?>
                            <a href="/giving/campaign?id=<?php echo $campaignId; ?>" class="btn btn-primary btn-sm">Make a pledge</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
