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
    header('Location: ' . Site::url('offboarding'));
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
    header('Location: ' . Site::url('offboarding'));
    exit();
}
if ($verdict !== AccountGuard::ALLOW) {
    $_SESSION['flash_msg']  = AccountGuard::message($verdict, AccountGuard::REACH_REHIRE);
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('offboarding'));
    exit();
}

if ($o['rehiredAt'] !== null) {
    header('Location: ' . Site::url('offboarding'));
    exit();
}
// #549 mistake 3: a day is 23 or 25 hours twice a year, so dividing a span
// by 86,400 counts EXACT days where this wants CALENDAR days. `diff()->days`
// is zone-aware and counts whole days between two moments.
$ageDays = (int) (new DateTimeImmutable('@' . strtotime((string) $o['offboardedAt'])))
    ->diff(new DateTimeImmutable('@' . time()))->days;
if ($ageDays > $undoWindowDays) {
    $_SESSION['flash_msg']  = 'Undo window has passed.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('offboarding'));
    exit();
}

$userId = (int) $o['userID'];

try {
    $db->begin_transaction();
    // Reactivate user + site membership. NOT credentials — user must reset.
    // Nor roles, user-group or department memberships (#516, #517):
    // offboarding DELETED those rows (do.php steps 6, 6a, 6b), so there is
    // nothing here to switch back on; an administrator adds them again at
    // Admin → Users (roles), Admin → Groups and Admin → Departments.
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
    // 🛑 Cancel any erasure request this offboarding filed (#569).
    //
    //    Since #569, offboarding with "erase" files a real erasure request.
    //    Undoing the offboarding has to call that off, and an independent
    //    check found that it did not: the request simply stayed at
    //    'pending_review'. In the DEFAULT mode ('on_request') nothing
    //    automatic ever looks at it again, so it sat on Admin → Erasure
    //    requests with nothing marking it as undone, waiting for the next
    //    administrator working that queue to erase a person who is now a
    //    current, active member.
    //
    //    Only a request that is still WAITING is cancelled, in one statement
    //    (`status = 'pending_review'`). A request already at 'processing' is
    //    being carried out right now and must not be touched: taking it back
    //    at that point would leave a half-erased account with its request
    //    marked cancelled, which is worse than either outcome. A request the
    //    PERSON filed themselves is also at 'pending_review' and is cancelled
    //    too — that is deliberate: bringing somebody back is a decision that
    //    their data stays, and an administrator who wants it gone can file
    //    again. The alternative, erasing a reinstated member because of a
    //    request from before they returned, is plainly worse.
    //
    //    It is inside the same transaction as the rest of the undo, so a
    //    rehire that fails leaves the request exactly as it was.
    $cancelledRequests = 0;
    $stmt = $db->prepare(
        "UPDATE tblErasureRequest SET status = 'cancelled' "
        . "WHERE userID = ? AND status = 'pending_review'"
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $cancelledRequests = (int) $stmt->affected_rows;
        $stmt->close();
    }

    $db->commit();
    // #982 round 5: "They must reset their password to sign in" was untrue
    // where Microsoft or Google sign-in is set up - an active account is
    // matched by its e-mail address there. Offboarding removed the password
    // and the sign-in links; switching the account on gives neither back.
    $_SESSION['flash_msg']  = 'User rehired: their account is switched on again. Offboarding removed their password, so they '
        . 'sign in by resetting it with "Forgot password", or with Microsoft or Google where this portal offers that.'
        . ($cancelledRequests > 0
            ? ' A waiting request to erase their personal details has been cancelled.'
            : '');
    $_SESSION['flash_type'] = 'success';
} catch (\Throwable $e) {
    $db->rollback();
    // #982 round 2: the exception's own words went to the page. They are
    // recorded for whoever investigates, and the page says what happened.
    \Portal\Core\Logger::errorPlatform('Offboarding', 'Error', 'REHIRE_FAIL', 'A rehire could not be completed', $e->getMessage());
    $_SESSION['flash_msg']  = 'The rehire could not be completed, and nothing was changed. The reason has been recorded on Admin, Errors.';
    $_SESSION['flash_type'] = 'danger';
}

header('Location: ' . Site::url('offboarding'));
exit();
