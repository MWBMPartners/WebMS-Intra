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

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

$db     = App::db();
$userId = (int) ($_GET['id'] ?? 0);
if ($userId <= 0) {
    header('Location: /admin/users');
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
$canOffboard = (AccountGuard::verdict($userId, AccountGuard::REACH_ACCOUNT) === AccountGuard::ALLOW);

$pageTitle   = 'Offboard ' . $u['fullName'];
$pageSection = 'offboarding';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Users' => '/admin/users', 'Offboard' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
?>

<h1 class="mb-3"><i class="fa-solid fa-door-open me-2 text-danger"></i>Offboard <?php echo htmlspecialchars((string) $u['fullName'], ENT_QUOTES, 'UTF-8'); ?></h1>

<?php if ($canOffboard === true): ?>
<div class="alert alert-warning">
    <strong>Offboarding will:</strong>
    <ul class="mb-0">
        <li>Mark the user inactive (no longer able to sign in)</li>
        <li>Delete every passkey / WebAuthn credential</li>
        <li>Disable the local-account password (cleared hash)</li>
        <li>End active site memberships (<code>tblUserSites.isActive = 0</code>)</li>
        <li>End leadership assignments (<code>endDate = NOW()</code> on open rows)</li>
        <li>Remove role assignments</li>
        <li>Audit-log the action to <code>tblOffboarding</code> with per-step success/failure</li>
    </ul>
    <p class="mt-2 mb-0">Within the configured undo window, an admin can rehire the user from <a href="/offboarding">/offboarding</a> — the account is reactivated but credentials are NOT restored; the user must reset password and re-enrol passkeys.</p>
</div>

<form method="post" action="/offboarding/do">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="userID" value="<?php echo (int) $u['userID']; ?>">
    <div class="card">
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label">Reason (audit-logged)</label>
                <input type="text" name="reason" class="form-control" maxlength="500" placeholder="e.g. Left the volunteer team / moved away" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Data disposition</label>
                <select name="dataDisposition" class="form-select">
                    <option value="retain">Retain — keep user data unchanged (default)</option>
                    <option value="anonymise">Anonymise — replace PII with tombstone (GDPR follow-up via #235)</option>
                    <option value="delete">Delete — invoke right-to-erasure (GDPR follow-up via #235)</option>
                </select>
                <div class="form-text">Anonymise / Delete dispositions are recorded but the actual data transformation is performed by #235 GDPR engine when that ships. For now, both behave like "retain" with a flag for the follow-up batch run.</div>
            </div>
            <div class="mb-3">
                <label class="form-label">Effective date</label>
                <input type="date" name="effectiveDate" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <button type="submit" class="btn btn-danger"
                    data-confirm="Offboard <?php echo htmlspecialchars((string) $u['fullName'], ENT_QUOTES, 'UTF-8'); ?>? This deletes their credentials. Within the undo window the account can be reactivated but they'll need to re-enrol." data-confirm-destructive="true"
                    data-confirm-confirm-label="Offboard">
                Offboard user
            </button>
            <a href="/admin/users" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </div>
</form>
<?php else: ?>
<!-- 🛡️ #518: this account can be SEEN by this administrator, but not
     offboarded by them — e.g. it belongs to a global administrator, or
     to another organisation too. No form is drawn at all. -->
<div class="alert alert-info">
    <?php echo htmlspecialchars(AccountGuard::message(AccountGuard::GLOBAL_ONLY, AccountGuard::REACH_ACCOUNT), ENT_QUOTES, 'UTF-8'); ?>
    <a href="/offboarding">Back to offboarding</a>
</div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
