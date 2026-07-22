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
$goalAmount  = Giving::parseAmount((string) ($_POST['goalAmount'] ?? ''));
$startDate   = (string) ($_POST['startDate'] ?? '');
$endDateRaw  = trim((string) ($_POST['endDate'] ?? ''));
$endDate     = $endDateRaw === '' ? null : $endDateRaw;
$isActive    = isset($_POST['isActive']) ? 1 : 0;

// 🛡️ Validation — a rejected save must never touch the DB (#299 edge case 2).
if ($name === '' || $goalAmount <= 0 || $startDate === '' || strtotime($startDate) === false) {
    $_SESSION['flash_msg']  = 'Name, a positive goal amount, and a valid start date are required.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /giving/campaigns');
    exit();
}
if ($endDate !== null && (strtotime($endDate) === false || $endDate < $startDate)) {
    $_SESSION['flash_msg']  = 'End date must be a valid date on or after the start date.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /giving/campaigns');
    exit();
}

$settings   = App::settings()['giving'] ?? [];
$currency   = (string) ($settings['currency'] ?? 'GBP');
$descParam  = $description === '' ? null : $description;

if ($campaignId > 0) {
    // ✏️ Update — siteID-scoped so a cross-site id can never be edited.
    $stmt = $db->prepare(
        'UPDATE tblPledgeCampaigns SET name = ?, description = ?, goalAmountPence = ?, currency = ?, '
        . 'startDate = ?, endDate = ?, isActive = ? WHERE campaignID = ? AND siteID = ?'
    );
    if ($stmt !== false) {
        $stmt->bind_param(
            'ssisssiii',
            $name, $descParam, $goalAmount, $currency, $startDate, $endDate, $isActive, $campaignId, $siteId
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

header('Location: /giving/campaigns');
exit();
