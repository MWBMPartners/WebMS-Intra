<?php
// Path: _apps/giving/pledge-save.php
/**
 * -----------------------------------------------------------------------------
 * Giving — Pledge: Save / Cancel 🤝
 * -----------------------------------------------------------------------------
 * POST /giving/pledge-save
 *
 * Any logged-in member may pledge to a campaign, update their own pledge, or
 * cancel it (#299 sub-feature 2) — deliberately NO `Giving::canManage()` gate
 * here, mirroring `gad-save.php`'s Gift Aid declaration handler: this is a
 * member managing their own giving commitment, not a treasurer action.
 * `userID` is always taken from the session, never from POST.
 *
 * `tblPledges.uq_pl_campaign_user` (UNIQUE on campaignID+userID) makes
 * re-pledging an upsert of the SAME row — re-pledging after a cancellation
 * re-opens it rather than creating a duplicate — see migration 151.
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
use Portal\Core\Logger;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$campaignId = (int) ($_POST['campaignID'] ?? 0);
$action     = (string) ($_POST['action'] ?? 'save');

if ($campaignId <= 0) {
    $_SESSION['flash_msg']  = 'Choose a campaign to pledge to.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /giving/campaigns');
    exit();
}

// -----------------------------------------------------------------------------
// ❌ Cancel — own pledge only, by construction (WHERE userID = $userId, the
// session's own id, never a POST-supplied value).
// -----------------------------------------------------------------------------
if ($action === 'cancel') {
    $stmt = $db->prepare(
        'UPDATE tblPledges SET status = \'cancelled\' WHERE campaignID = ? AND userID = ? AND siteID = ?'
    );
    if ($stmt !== false) {
        $stmt->bind_param('iii', $campaignId, $userId, $siteId);
        $stmt->execute();
        $stmt->close();
    }
    Logger::activity('GivingPledgeCancelled', 'Cancelled pledge to campaign #' . $campaignId, $userId);
    $_SESSION['flash_msg']  = 'Your pledge has been cancelled.';
    $_SESSION['flash_type'] = 'success';
    header('Location: /giving/campaign?id=' . $campaignId);
    exit();
}

// -----------------------------------------------------------------------------
// 💾 Save — new pledge, or update of the pledger's own existing row.
// -----------------------------------------------------------------------------
$amount   = Giving::parseAmount((string) ($_POST['amount'] ?? ''));
$schedule = (string) ($_POST['paymentSchedule'] ?? 'monthly');
if (in_array($schedule, ['one-off', 'weekly', 'monthly'], true) === false) {
    $schedule = 'monthly';
}

if ($amount <= 0) {
    $_SESSION['flash_msg']  = 'Enter a pledge amount greater than zero.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /giving/campaign?id=' . $campaignId);
    exit();
}

// 🛡️ Validate the campaign accepts new pledges: exists at this site, is
// active, and has not already ended. Pledging BEFORE a campaign's startDate
// is allowed — campaigns are announced ahead of their window opening (#299
// edge case 1).
$today    = date('Y-m-d');
$campaign = null;
$stmt = $db->prepare(
    'SELECT campaignID FROM tblPledgeCampaigns '
    . 'WHERE campaignID = ? AND siteID = ? AND isActive = 1 '
    . '  AND (endDate IS NULL OR endDate >= ?) LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('iis', $campaignId, $siteId, $today);
    $stmt->execute();
    $campaign = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($campaign === null) {
    $_SESSION['flash_msg']  = 'This campaign is not currently accepting pledges.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /giving/campaign?id=' . $campaignId);
    exit();
}

// 🔁 Upsert against uq_pl_campaign_user — "pledge again" always updates the
// pledger's one row per campaign (#299 edge case 3), re-opening a cancelled
// pledge rather than duplicating it.
$stmt = $db->prepare(
    'INSERT INTO tblPledges (siteID, campaignID, userID, amountPence, paymentSchedule, status) '
    . 'VALUES (?, ?, ?, ?, ?, \'open\') '
    . 'ON DUPLICATE KEY UPDATE amountPence = VALUES(amountPence), '
    . '    paymentSchedule = VALUES(paymentSchedule), status = \'open\''
);
if ($stmt !== false) {
    $stmt->bind_param('iiiis', $siteId, $campaignId, $userId, $amount, $schedule);
    $stmt->execute();
    $stmt->close();
}

Logger::activity(
    'GivingPledgeSaved',
    'Pledged ' . Giving::formatAmount($amount) . ' (' . $schedule . ') to campaign #' . $campaignId,
    $userId
);
$_SESSION['flash_msg']  = 'Your pledge has been saved. Thank you!';
$_SESSION['flash_type'] = 'success';
header('Location: /giving/campaign?id=' . $campaignId);
exit();
