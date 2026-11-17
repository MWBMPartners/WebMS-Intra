<?php
// Path: public_html/account/erasure-confirm.php
/**
 * Public confirmation landing — token in the email turns
 * pending_confirmation → pending_review.
 *
 * ADDRESSES: every redirect, link and form target here is built by
 * Router::url(), so it stays inside the organisation's own address. They
 * used to be written out ("/account/my-data"), which lands on the DEFAULT
 * organisation when organisations are told apart by the first part of the
 * address (roll-out package B2b, 12 October 2026; part of #536).
 * tools/audit-checks/check_site_aware_addresses.py holds this file to it.
 *
 * @package   Portal\Account
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/235
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\ErasureNotice;
use Portal\Core\Router;

$token = (string) ($_GET['token'] ?? '');
if ($token === '' || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
    // 🔗 A proper page, not a line of bare text (#569) — and here it matters
    //    more than usual: somebody following this link is trying to confirm
    //    that their personal details be erased, and being met with
    //    "Invalid confirmation link." in bare text gives them no idea whether
    //    their request still stands. The page says plainly that nothing has
    //    been changed.
    http_response_code(400);
    $linkPurpose = 'confirm a request to erase their personal details';
    $linkAdvice  = 'Your request has not been cancelled. Ask a site administrator to send the confirmation again.';
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'link-unusable.php';
    exit();
}

$db = App::db();
// 🔎 Which request the link belongs to, and which organisation's it is. This
//    page is reached signed out, so Site::id() is the organisation the ADDRESS
//    names, which need not be the request's: the administrators told below are
//    the request's own organisation's (#1136).
$requestId   = 0;
$requestSite = 0;
$find = $db->prepare('SELECT requestID, siteID FROM tblErasureRequest WHERE confirmToken = ? AND status = "pending_confirmation" AND requestedAt >= DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 1');
if ($find !== false) {
    $find->bind_param('s', $token);
    $find->execute();
    $found = $find->get_result()->fetch_assoc();
    $find->close();
    if ($found !== null) {
        $requestId   = (int) $found['requestID'];
        $requestSite = (int) $found['siteID'];
    }
}
$stmt = $db->prepare('UPDATE tblErasureRequest SET status = "pending_review", confirmedAt = NOW(), confirmToken = NULL WHERE confirmToken = ? AND requestID = ? AND status = "pending_confirmation" AND requestedAt >= DATE_SUB(NOW(), INTERVAL 1 DAY)');
$affected = 0;
if ($stmt !== false) {
    $stmt->bind_param('si', $token, $requestId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
}
// 📣 Tell the organisation's administrators, once, only when THIS press moved
//    the request to "waiting" (#1136; the one-month deadline). A second press
//    of the same link changes nothing and so tells nobody. It never throws and
//    never changes the page below.
if ($affected === 1 && $requestId > 0) {
    ErasureNotice::requestWaiting($requestSite, $requestId);
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Erasure request</title>
    <?php
    // 🔗 #569 — the description, canonical address and preview card.
    //    This page prints its own <head>, so it includes the shared
    //    file itself rather than inheriting it from header.php.
    $pageTitle       = 'Erasure request';
    $pageDescription = 'Confirm your request to have your personal details removed.';
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'head-meta.php';
    ?>

<style>body{font-family:Arial,sans-serif;max-width:520px;margin:80px auto;padding:24px;text-align:center;color:#1b2330;}h1{color:#5e6ad2;}</style>
</head><body>
<?php if ($affected > 0): ?>
    <h1>Request confirmed</h1>
    <?php // This used to end "and email you once complete". No such email is sent, so it does not say so. ?>
    <p>Your data erasure request has been confirmed. An administrator will review it and carry it out within one month. Until then your account works exactly as it does now.</p>
<?php else: ?>
    <h1>Link expired</h1>
    <p>This confirmation link is invalid, expired (24-hour window), or already used. Submit a new request from your account page.</p>
<?php endif; ?>
<p><a href="<?php echo htmlspecialchars(Router::url(''), ENT_QUOTES, 'UTF-8'); ?>">Return to the portal</a></p>
</body></html>
