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
    header('Location: ' . Site::url('giving/manage'));
    exit();
}

// Resolve donor: try to match the typed name to a member; otherwise store as
// free-text. 🛡️ Site-scoped (INNER JOIN tblUserSites) so a gift can never be
// attributed to a same-named member of another tenant/site.
$donorId = null;
if ($donorName !== '') {
    $stmt = $db->prepare(
        'SELECT u.userID FROM tblUsers u '
        . 'INNER JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
        . 'WHERE u.fullName = ? AND u.isActive = 1 LIMIT 1'
    );
    if ($stmt !== false) {
        $stmt->bind_param('is', $siteId, $donorName);
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

$entryId = 0;
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
    $entryId = (int) $stmt->insert_id;
    $stmt->close();
}

// #933: the entry's NUMBER and whose it is by account NUMBER, never the
//    donor's name. A name typed for somebody with no account here could never
//    be reached by an erasure once it is in the log's words; the gift's own
//    row carries it, and the giving-records rule decides how long.
//    (Worked out first, so the record's words are built from numbers and
//    fixed words alone: tools/audit-checks/check_activity_descriptions_personal.py
//    reads which names a description is built from.)
$giverWords = $donorId !== null ? 'from account #' . $donorId : ($freeTextName !== null ? 'from a giver named by hand' : 'anonymously');
Logger::activity(
    'GivingEntryRecorded',
    'Recorded ' . Giving::formatAmount($amount, $currency) . ' as entry #' . $entryId . ' ' . $giverWords,
    $userId
);

$_SESSION['flash_msg']  = 'Entry recorded.';
$_SESSION['flash_type'] = 'success';
header('Location: ' . Site::url('giving/manage'));
exit();
