<?php
// Path: public_html/offboarding/user.php
/**
 * Offboarding — confirmation page for a single user (admin-only).
 *
 * #518 FIX (17 September 2026): App::isAdmin() alone let an administrator
 * of any organisation reach the offboard confirmation for ANY account.
 * The page now asks Portal\Core\AccountGuard both whether this account
 * may be SEEN here at all, and (separately) whether it may actually be
 * offboarded — a global administrator's account can be seen, but only a
 * global administrator can offboard it.
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

$db     = App::db();
$userId = (int) ($_GET['id'] ?? 0);
if ($userId <= 0) {
    header('Location: ' . Site::url('admin/users'));
    exit();
}

// 🛡️ #518: a missing account and one that belongs to another
//    organisation must look identical here — a plain 404, exactly as an
//    unknown id already did before this fix.
if (AccountGuard::check($userId, AccountGuard::REACH_VIEW, 'open the offboarding page for account #' . $userId) !== AccountGuard::ALLOW) {
    http_response_code(404);
    exit('User not found');
}

$u = null;
$stmt = $db->prepare('SELECT userID, fullName, emailAddress, isActive FROM tblUsers WHERE userID = ? LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($u === null) {
    http_response_code(404);
    exit('User not found');
}

// 🛡️ #518: this is the SEPARATE, narrower question — not "can this
//    administrator see this account" (already answered above) but "may
//    they actually offboard it". A global administrator's own account,
//    for instance, can be seen here but not offboarded by anyone else.
$offboardVerdict = AccountGuard::verdict($userId, AccountGuard::REACH_ACCOUNT);
$canOffboard     = ($offboardVerdict === AccountGuard::ALLOW);
// 🗑️ #977: an account erased on request cannot be offboarded either, by
//    anybody. The refusal below used to be the "only a global administrator"
//    sentence whatever the reason, which would tell a GLOBAL administrator
//    looking at an erased account to ask a global administrator. It now
//    names the real reason for that one case.
//    #1064 round 5: a merged-away account likewise, with its own words.
$offboardWhyNot  = ($offboardVerdict === AccountGuard::ERASED || $offboardVerdict === AccountGuard::MERGED)
    ? $offboardVerdict : AccountGuard::GLOBAL_ONLY;

$pageTitle   = 'Offboard ' . $u['fullName'];
$pageSection = 'offboarding';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Admin' => Site::url('admin'), 'Users' => Site::url('admin/users'), 'Offboard' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
?>

<h1 class="mb-3"><i class="fa-solid fa-door-open me-2 text-danger"></i>Offboard <?php echo htmlspecialchars((string) $u['fullName'], ENT_QUOTES, 'UTF-8'); ?></h1>

<?php if ($canOffboard === true): ?>
<div class="alert alert-warning">
    <?php
    // 📋 #952 round 1 of the check: this list named seven of the steps and
    //    said nothing of small groups, and the paragraph under it named only
    //    three things a rehire does not put back, so it read as if everything
    //    else came back. Both are now written from the steps themselves —
    //    `offboarding/do.php` (steps 1 to 10) and `offboarding/rehire.php`
    //    (which switches on the account and its organisation memberships,
    //    cancels a waiting erasure request, and puts nothing else back).
    //    Change all three together; the words are pinned by
    //    section O of tools/series-suggestion-host-selftest.php.
    ?>
    <strong>Offboarding will:</strong>
    <ul class="mb-0">
        <li>Switch the account off, so they can no longer sign in</li>
        <li>Delete every passkey</li>
        <li>Remove their password</li>
        <li>End their membership of every organisation</li>
        <li>End their leadership roles</li>
        <li>End their small-group memberships and join requests, in every organisation</li>
        <li>Take their home off every small group that meets there, in every organisation, deleting its address from the group at once (a map position the portal looked up for it may be remembered until its keeping period ends); the leaders of each group are e-mailed to choose a new place</li>
        <li>Remove all their roles, user groups and departments, in every organisation</li>
        <li>Remove their Microsoft and Google sign-in links</li>
        <li>Forget every device they told the portal to trust</li>
        <li>Stop notifications to their devices</li>
        <li>Record what each step did, and file an erasure request if you choose to erase their details</li>
    </ul>
    <p class="mt-2 mb-0">Within the configured undo window, an admin can rehire the user from <a href="<?php echo htmlspecialchars(Site::url('offboarding'), ENT_QUOTES, 'UTF-8'); ?>">/offboarding</a>, unless the account has been erased by then. A rehire switches the account back on, restores its organisation memberships (every organisation for a global administrator, only this one for anybody else) and cancels any erasure request for them still waiting for review, even one they made themselves; NOTHING ELSE is put back: the password, passkeys, Microsoft or Google links, roles, user groups, departments, leadership roles, small-group memberships, trusted devices and notification subscriptions stay removed, and a small-group home address that was deleted is gone for good. The page shown after a rehire says what the account still has.</p>
</div>

<form method="post" action="<?php echo htmlspecialchars(Site::url('offboarding/do'), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="userID" value="<?php echo (int) $u['userID']; ?>">
    <div class="card">
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label">Reason (audit-logged)</label>
                <input type="text" name="reason" class="form-control" maxlength="500" placeholder="e.g. Left the volunteer team / moved away" required>
            </div>
            <?php
            // 🗂️ Two options, not three (#569). This used to offer Retain,
            //    Anonymise and Delete, and the last two DID NOTHING AT ALL —
            //    the choice was written to the audit row and no erasure ever
            //    happened, under a note saying the erasure engine had not
            //    shipped. It had: Portal\Core\GdprEraser is what the Erasure
            //    pages use.
            //
            //    They are two rather than three because the portal performs
            //    ONE kind of erasure. GdprEraser deliberately ANONYMISES
            //    where deleting would destroy figures that must survive —
            //    attendance and giving totals keep the record and lose the
            //    name — and deletes outright only where that is safe. So
            //    "Anonymise" and "Delete" could never have behaved
            //    differently, and offering both was offering a choice that
            //    did not exist. A true delete-everything mode is #663 and
            //    was NOT built, because it would make past figures wrong.
            //
            //    What happens next depends on `offboarding.erasureMode`,
            //    shown below so the administrator knows before pressing the
            //    button rather than afterwards.
            $erasureMode = (string) (App::settings('offboarding.erasureMode') ?? 'on_request');
            $undoDays    = (int) (App::settings('offboarding.undo_window_days') ?? 7);
            ?>
            <div class="mb-3">
                <label class="form-label" for="dataDisposition">What happens to their personal details</label>
                <select name="dataDisposition" id="dataDisposition" class="form-select">
                    <option value="retain">Keep their records (default)</option>
                    <option value="erase">Erase their personal details</option>
                </select>
                <div class="form-text">
                    Choosing to erase files a formal erasure request for this person.
                    Attendance and giving totals are kept but stop naming them, so past
                    figures stay correct.
                    <?php if ($erasureMode === 'after_undo_window'): ?>
                        It will be carried out automatically once the
                        <?php echo (int) $undoDays; ?>-day window for undoing this
                        offboarding has passed, so they can still be brought back until then.
                    <?php else: ?>
                        It will then wait on
                        <a href="<?php echo htmlspecialchars(Site::url('admin/erasure-requests'), ENT_QUOTES, 'UTF-8'); ?>">Admin, Erasure requests</a>
                        for somebody to carry out. Nothing is erased until then, so they can
                        still be brought back.
                    <?php endif; ?>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Effective date</label>
                <input type="date" name="effectiveDate" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <button type="submit" class="btn btn-danger"
                    data-confirm="Offboard <?php echo htmlspecialchars((string) $u['fullName'], ENT_QUOTES, 'UTF-8'); ?>? This removes their password, passkeys, Microsoft or Google links, roles and group memberships, and deletes their home from every small group that meets there. Within the undo window the account and its organisation memberships can be switched on again, unless it has been erased by then, but nothing else is put back." data-confirm-destructive="true"
                    data-confirm-confirm-label="Offboard">
                Offboard user
            </button>
            <a href="<?php echo htmlspecialchars(Site::url('admin/users'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </div>
</form>
<?php else: ?>
<!-- 🛡️ #518: this account can be SEEN by this administrator, but not
     offboarded by them — e.g. it belongs to a global administrator, or
     to another organisation too. No form is drawn at all. -->
<div class="alert alert-info">
    <?php echo htmlspecialchars(AccountGuard::message($offboardWhyNot, AccountGuard::REACH_ACCOUNT), ENT_QUOTES, 'UTF-8'); ?>
    <a href="<?php echo htmlspecialchars(Site::url('offboarding'), ENT_QUOTES, 'UTF-8'); ?>">Back to offboarding</a>
</div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
