<?php
// Path: _apps/giving/campaign.php
/**
 * -----------------------------------------------------------------------------
 * Giving — Pledge Campaign: Detail 🎯
 * -----------------------------------------------------------------------------
 * GET /giving/campaign?id=<campaignID>
 *
 * Campaign thermometer + stats, the member's own pledge form (make / update /
 * cancel), and — treasurer/admin only — the pledger list with on-schedule
 * status, the attributed-gifts log, and an edit-campaign form (#299
 * sub-feature 2).
 *
 * An inactive (retired) campaign 404s for everyone except `canManage` —
 * mirrors how a retired campaign is meant to disappear from member view
 * while staying reachable for historical reporting.
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
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db        = App::db();
$siteId    = Site::id();
$userId    = (int) ($_SESSION['user_id'] ?? 0);
$canManage = Giving::canManage();

$campaignId = (int) ($_GET['id'] ?? 0);
$campaign   = null;
if ($campaignId > 0) {
    $stmt = $db->prepare('SELECT * FROM tblPledgeCampaigns WHERE campaignID = ? AND siteID = ? LIMIT 1');
    if ($stmt !== false) {
        $stmt->bind_param('ii', $campaignId, $siteId);
        $stmt->execute();
        $campaign = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}
if ($campaign === null) {
    Router::renderError(404);
    return;
}
// 🛡️ Retired (isActive=0) campaigns are canManage-only — everyone else 404s.
if ((int) $campaign['isActive'] === 0 && $canManage === false) {
    Router::renderError(404);
    return;
}

$settings = App::settings()['giving'] ?? [];
$currency = (string) ($campaign['currency'] ?? ($settings['currency'] ?? 'GBP'));

// -----------------------------------------------------------------------------
// 📊 Thermometer (§3.1) — raised is a single indexed SUM; the *100 happens in
// PHP (64-bit) rather than SQL, where it could overflow a signed INT for a
// large campaign.
// -----------------------------------------------------------------------------
$goal   = (int) $campaign['goalAmountPence'];
$raised = 0;
$stmt = $db->prepare('SELECT COALESCE(SUM(amountPence), 0) AS raised FROM tblGivingEntry WHERE siteID = ? AND campaignID = ?');
if ($stmt !== false) {
    $stmt->bind_param('ii', $siteId, $campaignId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $raised = $row !== null ? (int) $row['raised'] : 0;
}
$pct      = $goal > 0 ? intdiv($raised * 100, $goal) : 0;
$barWidth = min(100, $pct);
$barClass = $pct >= 100 ? 'bg-primary' : 'bg-success';

// -----------------------------------------------------------------------------
// 🤝 Open pledges for this campaign + how much each has paid toward it
// (§3.2) — one row-set query plus one grouped paid-to-date query, not N+1.
// -----------------------------------------------------------------------------
$openPledges = [];
$stmt = $db->prepare(
    'SELECT pl.pledgeID, pl.userID, pl.amountPence, pl.paymentSchedule, pl.createdAt, u.fullName '
    . 'FROM tblPledges pl INNER JOIN tblUsers u ON u.userID = pl.userID '
    . 'WHERE pl.siteID = ? AND pl.campaignID = ? AND pl.status = \'open\' '
    . 'ORDER BY u.fullName'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $siteId, $campaignId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $openPledges[] = $r;
    }
    $stmt->close();
}

$paidByPledge = [];
$stmt = $db->prepare(
    'SELECT pledgeID, COALESCE(SUM(amountPence), 0) AS paid FROM tblGivingEntry '
    . 'WHERE siteID = ? AND campaignID = ? AND pledgeID IS NOT NULL GROUP BY pledgeID'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $siteId, $campaignId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $paidByPledge[(int) $r['pledgeID']] = (int) $r['paid'];
    }
    $stmt->close();
}

// asOf = min(today, campaign.endDate ?? today) — expectations freeze at the
// campaign's end once it has passed, rather than keep growing forever.
$today = date('Y-m-d');
$asOf  = ($campaign['endDate'] !== null && (string) $campaign['endDate'] < $today) ? (string) $campaign['endDate'] : $today;

$onScheduleCount = 0;
$pledgeRows      = [];
foreach ($openPledges as $p) {
    // pledgeStart = max(campaign.startDate, DATE(pledge.createdAt)) — a
    // pledge made after the campaign opened only owes from when it was made.
    $pledgeStart = max((string) $campaign['startDate'], date('Y-m-d', (int) strtotime((string) $p['createdAt'])));
    $expected    = Giving::pledgeExpectedToDate((int) $p['amountPence'], (string) $p['paymentSchedule'], $pledgeStart, $asOf);
    $paid        = $paidByPledge[(int) $p['pledgeID']] ?? 0;
    $onSchedule  = $paid >= $expected;
    if ($onSchedule === true) {
        $onScheduleCount++;
    }
    $pledgeRows[] = [
        'pledgeID'        => (int) $p['pledgeID'],
        'fullName'        => (string) $p['fullName'],
        'amountPence'     => (int) $p['amountPence'],
        'paymentSchedule' => (string) $p['paymentSchedule'],
        'expected'        => $expected,
        'paid'            => $paid,
        'onSchedule'      => $onSchedule,
    ];
}
$pctOnSchedule = count($openPledges) > 0 ? intdiv($onScheduleCount * 100, count($openPledges)) : null;

// -----------------------------------------------------------------------------
// 💷 "Total pledged" display figure (§3.3) — only meaningful as a single sum
// when the campaign has a fixed end date; an open-ended campaign shows
// per-schedule sums instead, since a lifetime total is unbounded.
// -----------------------------------------------------------------------------
$totalPledgedByEndDate = null;
$perScheduleSums       = ['one-off' => 0, 'weekly' => 0, 'monthly' => 0];
if ($campaign['endDate'] !== null) {
    $sum = 0;
    foreach ($openPledges as $p) {
        $pledgeStart = max((string) $campaign['startDate'], date('Y-m-d', (int) strtotime((string) $p['createdAt'])));
        $sum += Giving::pledgeExpectedToDate((int) $p['amountPence'], (string) $p['paymentSchedule'], $pledgeStart, (string) $campaign['endDate']);
    }
    $totalPledgedByEndDate = $sum;
} else {
    foreach ($openPledges as $p) {
        $sched = (string) $p['paymentSchedule'];
        if (isset($perScheduleSums[$sched]) === true) {
            $perScheduleSums[$sched] += (int) $p['amountPence'];
        }
    }
}

// -----------------------------------------------------------------------------
// 🙋 The current member's own open pledge (prefill the form + show Cancel).
// -----------------------------------------------------------------------------
$myPledge = null;
if ($userId > 0) {
    $stmt = $db->prepare(
        'SELECT pledgeID, amountPence, paymentSchedule FROM tblPledges '
        . 'WHERE siteID = ? AND campaignID = ? AND userID = ? AND status = \'open\' LIMIT 1'
    );
    if ($stmt !== false) {
        $stmt->bind_param('iii', $siteId, $campaignId, $userId);
        $stmt->execute();
        $myPledge = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

// -----------------------------------------------------------------------------
// 🧾 canManage-only: attributed gifts log.
// -----------------------------------------------------------------------------
$gifts = [];
if ($canManage === true) {
    $stmt = $db->prepare(
        'SELECT e.entryID, e.donatedAt, e.amountPence, e.currency, e.method, '
        . '       COALESCE(u.fullName, e.donorName, "Anonymous") AS donor '
        . 'FROM tblGivingEntry e LEFT JOIN tblUsers u ON u.userID = e.donorID '
        . 'WHERE e.siteID = ? AND e.campaignID = ? ORDER BY e.donatedAt DESC, e.entryID DESC LIMIT 200'
    );
    if ($stmt !== false) {
        $stmt->bind_param('ii', $siteId, $campaignId);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) {
            $gifts[] = $r;
        }
        $stmt->close();
    }
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = (string) $campaign['name'];
$pageSection = 'giving';
$breadcrumbs = ['Dashboard' => '/', 'Giving' => '/giving', 'Campaigns' => '/giving/campaigns', (string) $campaign['name'] => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h1 class="mb-1">
            <i class="fa-solid fa-bullseye me-2"></i><?php echo htmlspecialchars((string) $campaign['name'], ENT_QUOTES, 'UTF-8'); ?>
            <?php if ((int) $campaign['isActive'] === 0): ?>
                <span class="badge text-bg-secondary align-middle">Retired</span>
            <?php endif; ?>
        </h1>
        <p class="text-secondary mb-0">
            <?php echo htmlspecialchars(date('d/m/Y', (int) strtotime((string) $campaign['startDate'])), ENT_QUOTES, 'UTF-8'); ?>
            &ndash;
            <?php echo $campaign['endDate'] !== null ? htmlspecialchars(date('d/m/Y', (int) strtotime((string) $campaign['endDate'])), ENT_QUOTES, 'UTF-8') : 'ongoing'; ?>
        </p>
    </div>
    <a href="/giving/campaigns" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>All campaigns</a>
</div>

<?php if ((string) ($campaign['description'] ?? '') !== ''): ?>
    <p class="mb-3"><?php echo nl2br(htmlspecialchars((string) $campaign['description'], ENT_QUOTES, 'UTF-8')); ?></p>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-body">
        <div class="progress mb-2" style="height:18px;">
            <div class="progress-bar <?php echo $barClass; ?>" role="progressbar" style="width: <?php echo $barWidth; ?>%;"
                 aria-valuenow="<?php echo $pct; ?>" aria-valuemin="0" aria-valuemax="100"><?php echo $pct; ?>%</div>
        </div>
        <p class="mb-0">
            <strong><?php echo htmlspecialchars(Giving::formatAmount($raised, $currency), ENT_QUOTES, 'UTF-8'); ?></strong>
            of <?php echo htmlspecialchars(Giving::formatAmount($goal, $currency), ENT_QUOTES, 'UTF-8'); ?>
            <?php if ($pct >= 100): ?><span class="badge text-bg-success ms-1">Goal reached</span><?php endif; ?>
        </p>
    </div>
</div>

<div class="alert alert-light border d-flex flex-wrap gap-3 justify-content-between align-items-center mb-4">
    <div><strong><?php echo count($openPledges); ?></strong> open pledge<?php echo count($openPledges) === 1 ? '' : 's'; ?></div>
    <div>
        <?php if ($pctOnSchedule !== null): ?>
            <strong><?php echo $pctOnSchedule; ?>%</strong> on schedule
        <?php else: ?>
            <span class="text-muted">No pledges yet</span>
        <?php endif; ?>
    </div>
    <div>
        <?php if ($totalPledgedByEndDate !== null): ?>
            Total pledged by end: <strong><?php echo htmlspecialchars(Giving::formatAmount($totalPledgedByEndDate, $currency), ENT_QUOTES, 'UTF-8'); ?></strong>
        <?php elseif (count($openPledges) > 0): ?>
            <?php
                $parts = [];
                foreach ($perScheduleSums as $sched => $sum) {
                    if ($sum > 0) {
                        $parts[] = htmlspecialchars(Giving::formatAmount($sum, $currency), ENT_QUOTES, 'UTF-8') . '/' . htmlspecialchars($sched, ENT_QUOTES, 'UTF-8');
                    }
                }
            ?>
            Pledged: <strong><?php echo $parts !== [] ? implode(' + ', $parts) : '&mdash;'; ?></strong>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><strong><?php echo $myPledge !== null ? 'Update your pledge' : 'Make a pledge'; ?></strong></div>
    <div class="card-body">
        <?php if ((int) $campaign['isActive'] === 0): ?>
            <p class="text-muted mb-0">This campaign is no longer accepting pledges.</p>
        <?php else: ?>
            <form method="post" action="/giving/pledge-save" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="campaignID" value="<?php echo $campaignId; ?>">
                <input type="hidden" name="action" value="save">
                <div class="col-md-3">
                    <label class="form-label small">Amount</label>
                    <input type="text" class="form-control form-control-sm" name="amount" required
                           value="<?php echo $myPledge !== null ? htmlspecialchars(number_format(((int) $myPledge['amountPence']) / 100, 2, '.', ''), ENT_QUOTES, 'UTF-8') : ''; ?>"
                           placeholder="25.00">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Schedule</label>
                    <?php $mySchedule = $myPledge !== null ? (string) $myPledge['paymentSchedule'] : 'monthly'; ?>
                    <select class="form-select form-select-sm" name="paymentSchedule">
                        <option value="one-off" <?php echo $mySchedule === 'one-off' ? 'selected' : ''; ?>>One-off</option>
                        <option value="weekly" <?php echo $mySchedule === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                        <option value="monthly" <?php echo $mySchedule === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary btn-sm w-100" type="submit"><?php echo $myPledge !== null ? 'Update' : 'Pledge'; ?></button>
                </div>
            </form>
            <?php if ($myPledge !== null): ?>
                <form method="post" action="/giving/pledge-save" class="mt-2">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="campaignID" value="<?php echo $campaignId; ?>">
                    <input type="hidden" name="action" value="cancel">
                    <button type="submit" class="btn btn-link btn-sm text-danger p-0" data-confirm="Cancel your pledge to this campaign?">
                        Cancel my pledge
                    </button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($canManage === true): ?>
    <div class="card mb-4">
        <div class="card-header"><strong>Pledges</strong></div>
        <div class="card-body">
            <?php if (count($pledgeRows) === 0): ?>
                <p class="text-muted mb-0">No open pledges yet.</p>
            <?php else: ?>
                <div class="portal-data-list">
                    <div class="portal-data-row portal-data-header d-none d-md-flex">
                        <div class="col-md-3">Pledger</div>
                        <div class="col-md-2">Amount</div>
                        <div class="col-md-2 text-end">Paid to date</div>
                        <div class="col-md-2 text-end">Expected to date</div>
                        <div class="col-md-3 text-end">Status</div>
                    </div>
                    <?php foreach ($pledgeRows as $p): ?>
                        <div class="portal-data-row">
                            <div class="col-12 col-md-3"><?php echo htmlspecialchars($p['fullName'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="col-12 col-md-2">
                                <?php echo htmlspecialchars(Giving::formatAmount($p['amountPence'], $currency), ENT_QUOTES, 'UTF-8'); ?>
                                <span class="text-muted small">/<?php echo htmlspecialchars($p['paymentSchedule'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <div class="col-12 col-md-2 text-md-end"><?php echo htmlspecialchars(Giving::formatAmount($p['paid'], $currency), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="col-12 col-md-2 text-md-end"><?php echo htmlspecialchars(Giving::formatAmount($p['expected'], $currency), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="col-12 col-md-3 text-md-end">
                                <?php if ($p['onSchedule'] === true): ?>
                                    <span class="badge text-bg-success">On track</span>
                                <?php else: ?>
                                    <span class="badge text-bg-warning">Behind</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><strong>Attributed gifts</strong></div>
        <div class="card-body">
            <?php if (count($gifts) === 0): ?>
                <p class="text-muted mb-0">No gifts attributed to this campaign yet.</p>
            <?php else: ?>
                <div class="portal-data-list">
                    <div class="portal-data-row portal-data-header d-none d-md-flex">
                        <div class="col-md-2">Date</div>
                        <div class="col-md-4">Donor</div>
                        <div class="col-md-3">Method</div>
                        <div class="col-md-3 text-end">Amount</div>
                    </div>
                    <?php foreach ($gifts as $g): ?>
                        <div class="portal-data-row">
                            <div class="col-12 col-md-2"><?php echo htmlspecialchars(date('d/m/Y', (int) strtotime((string) $g['donatedAt'])), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="col-12 col-md-4"><?php echo htmlspecialchars((string) $g['donor'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="col-12 col-md-3 text-muted"><?php echo htmlspecialchars((string) $g['method'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="col-12 col-md-3 text-md-end"><strong><?php echo htmlspecialchars(Giving::formatAmount((int) $g['amountPence'], (string) ($g['currency'] ?? $currency)), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><strong>Edit campaign</strong></div>
        <div class="card-body">
            <form method="post" action="/giving/campaign-save" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="campaignID" value="<?php echo $campaignId; ?>">
                <div class="col-md-4">
                    <label class="form-label small">Name</label>
                    <input type="text" class="form-control form-control-sm" name="name" required value="<?php echo htmlspecialchars((string) $campaign['name'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Goal amount</label>
                    <input type="text" class="form-control form-control-sm" name="goalAmount" required value="<?php echo htmlspecialchars(number_format($goal / 100, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Start date</label>
                    <input type="date" class="form-control form-control-sm" name="startDate" required value="<?php echo htmlspecialchars((string) $campaign['startDate'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">End date</label>
                    <input type="date" class="form-control form-control-sm" name="endDate" value="<?php echo htmlspecialchars((string) ($campaign['endDate'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-1 form-check ms-1">
                    <input type="checkbox" class="form-check-input" name="isActive" id="editCampaignActive" value="1" <?php echo (int) $campaign['isActive'] === 1 ? 'checked' : ''; ?>>
                    <label class="form-check-label small" for="editCampaignActive">Active</label>
                </div>
                <div class="col-md-1">
                    <button class="btn btn-primary btn-sm w-100" type="submit"><i class="fa-solid fa-floppy-disk"></i></button>
                </div>
                <div class="col-12">
                    <textarea class="form-control form-control-sm" name="description" rows="2" placeholder="Description (optional)"><?php echo htmlspecialchars((string) ($campaign['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
            </form>
            <p class="small text-muted mt-2 mb-0">There is no delete — untick "Active" to retire this campaign. Pledges and attributed gift history are kept.</p>
        </div>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
