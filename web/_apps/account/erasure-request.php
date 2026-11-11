<?php
// Path: _apps/account/erasure-request.php
/**
 * Account — ask for your personal data to be erased (GDPR Article 17).
 *
 * Shows what would happen to each kind of record, then files the request and
 * emails a link to confirm it. Clicking that link moves the request to
 * "pending review", where an administrator carries it out.
 *
 * The filing itself is GdprEraser::fileRequest(), which "Delete my account"
 * (/account/delete/confirm) calls too, so there is one way to file a request
 * and one place for the words about what happens next. This page has no typed
 * phrase, so its request needs the emailed link; if that email cannot be sent,
 * an administrator can confirm the request from their list instead.
 *
 * ADDRESSES: every redirect, link and form target here is built by
 * Router::url(), so it stays inside the organisation's own address. They
 * used to be written out ("/account/my-data"), which lands on the DEFAULT
 * organisation when organisations are told apart by the first part of the
 * address (roll-out package B2b, 12 October 2026; part of #536).
 * tools/audit-checks/check_site_aware_addresses.py holds this file to it.
 *
 * @package   Portal\Account
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/235
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\GdprEraser;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$allowDelete = (App::settings('privacy.allowAccountDelete') ?? 'true') === 'true';
if ($allowDelete === false) {
    http_response_code(403);
    exit('Account erasure is disabled on this portal.');
}

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

// Pull user info for the snapshot.
$user = null;
$stmt = $db->prepare('SELECT fullName, emailAddress FROM tblUsers WHERE userID = ? LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($user === null) {
    http_response_code(404);
    exit('User not found');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        http_response_code(400);
        exit('Bad request');
    }

    try {
        $filed = GdprEraser::fileRequest($siteId, $userId);
    } catch (\Throwable $e) {
        Logger::exception($e);
        $_SESSION['flash_msg']  = 'Your request could not be recorded, and nothing was changed. Please try again; if it '
            . 'keeps happening, ask an administrator for help.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . Router::url('account/my-data'));
        exit();
    }

    $next = GdprEraser::whatHappensNext($filed);
    $_SESSION['flash_msg']  = $next['message'];
    $_SESSION['flash_type'] = $next['type'];
    header('Location: ' . Router::url('account/my-data'));
    exit();
}

// What each instruction will do, in words: GdprEraser::inventoryWords(), the
// ONE place a row becomes words (#927). The page used to hold its own map of
// action to words. Before that it printed the instruction's own name followed
// by a "d", which read well for "delete" and gave "anonymised" and "retaind"
// for the other two. #703 round 7 then changed "kept, with your details
// removed" to "kept, with some details about you removed", because an expense
// claim is "anonymised" and still keeps the claimant's account number on
// purpose (D-113) - but the LABEL still read as though nothing linked the
// claim to the person, and only the reason beside it said otherwise. The words
// now follow whether the number stays, asked of the same rule the erasure
// itself follows, so the two cannot drift apart.
$inventory = GdprEraser::inventory($userId);
$csrf = Auth::csrfToken();

$pageTitle   = 'Request erasure';
$pageSection = 'account';
$breadcrumbs = ['Dashboard' => Router::url(''), 'My Account' => Router::url('account'), 'My data' => Router::url('account/my-data'), 'Erasure request' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-3"><i class="fa-solid fa-trash me-2"></i>Request data erasure</h1>

<?php // 🗣️ #703 round 7: this said "Records below will be deleted or anonymised", above rows marked
      //    "kept" that keep your name and account number on purpose (the worker records, D-016,
      //    D-110, D-111; gifts kept for tax) and an expense claim that keeps your number (D-113). ?>
<div class="alert alert-warning">
    <strong>Once it is carried out, this cannot be undone.</strong> The request is carried out within one month,
    as data protection law requires. Each kind of record below is then deleted, or kept with some details
    about you removed, or kept as it is. <strong>Some kept records keep your name or your account number on
    purpose</strong>, and the reason beside each one says which and why.
</div>

<div class="card mb-3"><div class="card-body">
    <?php // A second-level heading styled small: it used to be an h5 straight after the h1, which skips three levels. ?>
    <h2 class="h5">What will happen to your data:</h2>
    <ul>
        <?php foreach ($inventory as $i): ?>
            <li>
                <code><?php echo htmlspecialchars((string) $i['table'], ENT_QUOTES, 'UTF-8'); ?></code>
                — <strong><?php echo (int) $i['rows']; ?></strong> row(s) will be
                <strong><?php echo htmlspecialchars(GdprEraser::inventoryWords($i), ENT_QUOTES, 'UTF-8'); ?></strong>.
                <?php if (($i['reason'] ?? '') !== ''): ?>
                    <span class="text-muted">— <?php echo htmlspecialchars((string) $i['reason'], ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</div></div>

<form method="post" class="card"><div class="card-body">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
    <p>You will receive a confirmation email at <strong><?php echo htmlspecialchars((string) $user['emailAddress'], ENT_QUOTES, 'UTF-8'); ?></strong>. Click the link to file the request.</p>
    <button class="btn btn-danger" type="submit" data-confirm="Send the erasure confirmation email?">
        <i class="fa-solid fa-envelope me-1"></i>Send confirmation email
    </button>
    <a class="btn btn-outline-secondary" href="<?php echo htmlspecialchars(Router::url('account/my-data'), ENT_QUOTES, 'UTF-8'); ?>">Cancel</a>
</div></form>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
