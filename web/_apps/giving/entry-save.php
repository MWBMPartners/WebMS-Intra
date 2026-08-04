<?php
// Path: public_html/giving/entry-save.php
/**
 * Giving — record an entry (treasurer only).
 *
 * @package   Portal\Giving
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/266
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Giving;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

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

$db        = App::db();
$siteId    = Site::id();
$userId    = (int) ($_SESSION['user_id'] ?? 0);
$settings  = App::settings()['giving'] ?? [];
$currency  = (string) ($settings['currency'] ?? 'GBP');

$donorName   = trim((string) ($_POST['donorName'] ?? ''));
$categoryId  = (int) ($_POST['categoryID'] ?? 0);
$amount      = Giving::parseAmount((string) ($_POST['amount'] ?? ''));
$date        = (string) ($_POST['donatedAt'] ?? '');
$method      = (string) ($_POST['method'] ?? 'cash');
$reference   = trim((string) ($_POST['reference'] ?? ''));
$notes       = trim((string) ($_POST['notes'] ?? ''));
// 🎯 Campaign selector (#299 sub-feature 2): 0 = Auto, -1 = None, >0 = explicit
// campaign choice. Never a pledgeID — that is resolved server-side only, in
// Giving::attributeGift().
$campaignSel = (int) ($_POST['campaignID'] ?? 0);

if (in_array($method, ['cash','cheque','bank-transfer','card','standing-order','other'], true) === false) {
    $method = 'cash';
}
if ($amount <= 0 || $categoryId <= 0 || $date === '' || strtotime($date) === false) {
    $_SESSION['flash_msg']  = 'Amount, category, and date are required.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /giving/manage');
    exit();
}

// Resolve donor: try to match the typed name to a member; otherwise store as free-text.
$donorId = null;
if ($donorName !== '') {
    $stmt = $db->prepare('SELECT userID FROM tblUsers WHERE fullName = ? AND isActive = 1 LIMIT 1');
    if ($stmt !== false) {
        $stmt->bind_param('s', $donorName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row !== null) {
            $donorId = (int) $row['userID'];
        }
    }
}
$freeTextName = $donorId === null && $donorName !== '' ? $donorName : null;

// 🎯 Auto-attribution (#299 sub-feature 2) — the ONLY place this pair is
// ever derived; see Giving::attributeGift() for the full rule.
$attr        = Giving::attributeGift($siteId, $donorId, $date, $campaignSel);
$campaignBind = $attr['campaignID'];
$pledgeBind   = $attr['pledgeID'];

$stmt = $db->prepare(
    'INSERT INTO tblGivingEntry '
    . '(siteID, donorID, donorName, categoryID, amountPence, currency, donatedAt, method, reference, notes, recordedByID, campaignID, pledgeID) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
if ($stmt !== false) {
    $stmt->bind_param(
        'iisiisssssiii',
        $siteId, $donorId, $freeTextName, $categoryId, $amount, $currency,
        $date, $method, $reference, $notes, $userId, $campaignBind, $pledgeBind
    );
    $stmt->execute();
    $stmt->close();
}

Logger::activity('GivingEntryRecorded', 'Recorded ' . Giving::formatAmount($amount, $currency) . ' from ' . ($donorName !== '' ? $donorName : 'anonymous'), $userId);

$_SESSION['flash_msg']  = 'Entry recorded.';
$_SESSION['flash_type'] = 'success';
header('Location: /giving/manage');
exit();
