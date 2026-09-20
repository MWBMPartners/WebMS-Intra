<?php
// Path: public_html/offboarding/rehire.php
/**
 * Offboarding — reactivate a user within the undo window. Restores
 * isActive flags but NOT credentials; the user must reset password
 * via /forgot-password to sign in.
 *
 * #518 FIX (17 September 2026): App::isAdmin() alone let an administrator
 * of ANY organisation undo ANY offboarding record and reactivate ANY
 * account. This is the one place an ENDED membership still counts as
 * "belongs here" — see Portal\Core\AccountGuard's own docblock for why —
 * and the guard runs BEFORE the "already undone" / "undo window has
 * passed" checks, so a record belonging to another organisation cannot
 * be distinguished from a missing record number by which message comes
 * back.
 *
 * @package   Portal\Offboarding
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/240
 */

declare(strict_types=1);

use Portal\Core\AccountGuard;
use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$db      = App::db();
$adminId = (int) ($_SESSION['user_id'] ?? 0);
$id      = (int) ($_POST['offboardingID'] ?? 0);

$undoWindowDays = (int) (App::settings()['offboarding']['undo_window_days'] ?? 7);

// 🛡️ #518: a record number of 0 or less redirects silently, with NO log
//    at all — there is nothing to log; the page has been sent no number.
if ($id <= 0) {
    header('Location: /offboarding');
    exit();
}

// 🛡️ Codex catch-up A2 (20 September 2026): asked here, before the lookup
//    below, so that EVERY request that reaches the lookup pays for this
//    one read, whatever it finds — a missing record number and a record
//    belonging to another organisation must cost the same amount of
//    database work, not just look the same on screen (#503). The result
//    is cached for the request, so AccountGuard's own later call (inside
//    check(), below) is answered from that cache, not a second query.
AccountGuard::isSingleOrganisation();

// Locate the offboarding row.
$o = null;
$stmt = $db->prepare(
    'SELECT offboardingID, userID, offboardedAt, rehiredAt FROM tblOffboarding WHERE offboardingID = ? LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $o = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
// 🛡️ Codex catch-up A2 (20 September 2026), fixing #518's own leftover: a
//    missing record number and a record belonging to another organisation
//    used to be TWO separate code paths that happened to produce the same
//    redirect — but a missing number skipped the account read entirely,
//    so it did strictly less database work than a foreign record, and
//    that difference is exactly what #503 says a refusal must not have.
//    Both cases now go through the SAME AccountGuard::check() call:
//    - a missing record ($o === null) passes account number 0, which
//      evaluate() refuses as NOT_FOUND with reason "missing" before any
//      query — byte for byte the record logRefusal() used to write here
//      by hand;
//    - a record belonging elsewhere passes the real account number, and
//      the guard runs BEFORE the "already undone" / "undo window has
//      passed" checks below, on purpose — otherwise a foreign record
//      would say one of those two specific things, which is exactly the
//      "does this record exist" signal a refusal must not give. An ENDED
//      membership in the open organisation still counts as "belongs
//      here" for AccountGuard::REACH_REHIRE — see that class's own
//      docblock for why.
//    WHAT THIS CANNOT PROMISE: for a record that exists but belongs
//    elsewhere, the guard still reads that account's facts (one indexed
//    query); for a number that matches nothing there is no account to
//    read, so that one read is skipped. A fake read to make the timing
//    match exactly was rejected — it would add a query nobody needs, and
//    #503 already accepts the same residue ("a number that matches a row
//    still costs reading that row").
$targetUserId = $o === null ? 0 : (int) $o['userID'];
$verdict = AccountGuard::check($targetUserId, AccountGuard::REACH_REHIRE, 'undo offboarding record #' . $id);
if ($verdict === AccountGuard::NOT_FOUND || $o === null) {
    header('Location: /offboarding');
    exit();
}
if ($verdict !== AccountGuard::ALLOW) {
    $_SESSION['flash_msg']  = AccountGuard::message($verdict, AccountGuard::REACH_REHIRE);
    $_SESSION['flash_type'] = 'danger';
    header('Location: /offboarding');
    exit();
}

if ($o['rehiredAt'] !== null) {
    header('Location: /offboarding');
    exit();
}
$ageDays = (time() - strtotime((string) $o['offboardedAt'])) / 86400;
if ($ageDays > $undoWindowDays) {
    $_SESSION['flash_msg']  = 'Undo window has passed.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /offboarding');
    exit();
}

$userId = (int) $o['userID'];

try {
    $db->begin_transaction();
    // Reactivate user + site membership. NOT credentials — user must reset.
    $stmt = $db->prepare('UPDATE tblUsers SET isActive = 1 WHERE userID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }
    // 🛡️ #518: a global administrator still reactivates EVERY membership
    //    row the account has, in every organisation — older behaviour,
    //    unchanged (see AccountGuard's docblock, "WHAT THIS CLASS CANNOT
    //    DO"). Anyone else can only reach here for an account that
    //    belongs (or belonged) to THEIR organisation, so the update is
    //    scoped to it — bringing someone back should not silently revive
    //    a membership in an organisation this administrator has never
    //    been given any say over.
    if (AccountGuard::actorIsGlobal() === true) {
        $stmt = $db->prepare('UPDATE tblUserSites SET isActive = 1 WHERE userID = ?');
        if ($stmt !== false) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
        }
    } else {
        $openSiteId = Site::id();
        $stmt = $db->prepare('UPDATE tblUserSites SET isActive = 1 WHERE userID = ? AND siteID = ?');
        if ($stmt !== false) {
            $stmt->bind_param('ii', $userId, $openSiteId);
            $stmt->execute();
            $stmt->close();
        }
    }
    $stmt = $db->prepare(
        'UPDATE tblOffboarding SET rehiredAt = NOW(), rehiredByID = ? WHERE offboardingID = ?'
    );
    if ($stmt !== false) {
        $stmt->bind_param('ii', $adminId, $id);
        $stmt->execute();
        $stmt->close();
    }
    $db->commit();
    $_SESSION['flash_msg']  = 'User rehired. They must reset their password to sign in.';
    $_SESSION['flash_type'] = 'success';
} catch (\Throwable $e) {
    $db->rollback();
    $_SESSION['flash_msg']  = 'Rehire failed: ' . $e->getMessage();
    $_SESSION['flash_type'] = 'danger';
}

header('Location: /offboarding');
exit();
