<?php
// Path: _apps/giving/campaign-save.php
/**
 * -----------------------------------------------------------------------------
 * Giving — Pledge Campaign: Create / Update 🎯
 * -----------------------------------------------------------------------------
 * POST /giving/campaign-save
 *
 * Creates a new pledge campaign (`campaignID=0`) or updates an existing one
 * (#299 sub-feature 2). Treasurer/admin only — same gate as every other
 * financial action in `giving`. No hard-delete route: retiring a campaign is
 * `isActive=0` (the "deactivate, don't destroy" pattern) so pledges and
 * attributed gift history survive and historical thermometers keep working.
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
use Portal\Core\Router;
use Portal\Core\Site;

// 🛡️ Session + gate
Auth::ensureSession();
Auth::requireLogin();
if (Giving::canManage() === false) {
    Router::renderError(403);
    return;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$campaignId  = (int) ($_POST['campaignID'] ?? 0);
$name        = trim((string) ($_POST['name'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));
// 💷 Read by the ONE converter, Currency::parseAmount() (#1004), in the
//    campaign's own currency: an existing campaign keeps its own (D-139), a new
//    one is in the organisation's. A figure it refuses is said back below.
$goalCurrency = \Portal\Core\Currency::ofSite();
if ($campaignId > 0) {
    $cur = $db->prepare('SELECT `currency` FROM `tblPledgeCampaigns` WHERE `campaignID` = ? AND `siteID` = ? LIMIT 1');
    $cur->bind_param('ii', $campaignId, $siteId);
    $cur->execute();
    $curRow = $cur->get_result()->fetch_assoc();
    $cur->close();
    $goalCurrency = is_array($curRow) === true ? (string) $curRow['currency'] : $goalCurrency;
}
$goalTyped   = is_string($_POST['goalAmount'] ?? null) === true ? (string) $_POST['goalAmount'] : '';
$goalAmount  = \Portal\Core\Currency::parseAmount($goalTyped, $goalCurrency);
$startDate   = (string) ($_POST['startDate'] ?? '');
$endDateRaw  = trim((string) ($_POST['endDate'] ?? ''));
$endDate     = $endDateRaw === '' ? null : $endDateRaw;
$isActive    = isset($_POST['isActive']) ? 1 : 0;

// 🛡️ Validation — a rejected save must never touch the DB (#299 edge case 2).
if (trim($goalTyped) !== '' && is_string($goalAmount) === true) {
    $_SESSION['flash_msg']  = 'Goal amount: ' . $goalAmount . ' Nothing was saved.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('giving/campaigns'));
    exit();
}
if ($name === '' || is_string($goalAmount) === true || $goalAmount <= 0 || $startDate === '' || strtotime($startDate) === false) {
    $_SESSION['flash_msg']  = 'Name, a positive goal amount, and a valid start date are required.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('giving/campaigns'));
    exit();
}
if ($endDate !== null && (strtotime($endDate) === false || $endDate < $startDate)) {
    $_SESSION['flash_msg']  = 'End date must be a valid date on or after the start date.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('giving/campaigns'));
    exit();
}

$settings   = App::settings()['giving'] ?? [];
// 💷 A NEW campaign is in the organisation's currency. An existing one KEEPS its
//    own (#966, D-139, round 3 of the check): the form has no currency field, so
//    an update never names the column. Writing today's currency here used to
//    relabel an old campaign's goal after the organisation changed currency,
//    drop its own gifts from the goal and reshow its pledges in the new money.
$currency   = \Portal\Core\Currency::ofSite();
$descParam  = $description === '' ? null : $description;

if ($campaignId > 0) {
    // ✏️ Update — siteID-scoped so a cross-site id can never be edited.
    $stmt = $db->prepare(
        'UPDATE tblPledgeCampaigns SET name = ?, description = ?, goalAmountPence = ?, '
        . 'startDate = ?, endDate = ?, isActive = ? WHERE campaignID = ? AND siteID = ?'
    );
    if ($stmt !== false) {
        $stmt->bind_param(
            'ssissiii',
            $name, $descParam, $goalAmount, $startDate, $endDate, $isActive, $campaignId, $siteId
        );
        $stmt->execute();
        $stmt->close();
    }
    Logger::activity('GivingCampaignSaved', 'Updated pledge campaign "' . $name . '" (#' . $campaignId . ')', $userId);
    $_SESSION['flash_msg']  = 'Campaign updated.';
    $_SESSION['flash_type'] = 'success';
} else {
    // ➕ Create
    $stmt = $db->prepare(
        'INSERT INTO tblPledgeCampaigns '
        . '(siteID, name, description, goalAmountPence, currency, startDate, endDate, isActive, createdByID) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if ($stmt !== false) {
        $stmt->bind_param(
            'ississsii',
            $siteId, $name, $descParam, $goalAmount, $currency, $startDate, $endDate, $isActive, $userId
        );
        $stmt->execute();
        $campaignId = (int) $stmt->insert_id;
        $stmt->close();
    }
    Logger::activity('GivingCampaignSaved', 'Created pledge campaign "' . $name . '" (#' . $campaignId . ')', $userId);
    $_SESSION['flash_msg']  = 'Campaign created.';
    $_SESSION['flash_type'] = 'success';
}

header('Location: ' . Site::url('giving/campaigns'));
exit();
