<?php
// Path: _apps/admin/users/unplaced.php
/**
 * -----------------------------------------------------------------------------
 * Accounts that belong to no organisation 🧭 (#533)
 * -----------------------------------------------------------------------------
 * A list, for a GLOBAL administrator only, of every account on the whole
 * installation that has no `tblUserSites` row at all — so migration 199
 * could not place it automatically, because the portal has more than one
 * organisation and guessing which one is not something the owner asked
 * this build to do (20 September 2026 decision — see that migration's own
 * header for the two cases it CAN place automatically).
 *
 * WHAT "BELONGS TO NO ORGANISATION" ACTUALLY MEANS FOR THAT PERSON
 * -------------------------------------------------------------------------
 * Their calendar subscription answers "Invalid token" (`calendar/feed.php`
 * requires an active membership row). They cannot check in to an internal
 * event (`calendar/anon-checkin.php`, after #533 removed its own
 * compatibility branch). No organisation's administrator can see them —
 * every member picker, and the members page itself, joins `tblUserSites`.
 * And they cannot be promoted off a waiting list
 * (`Events::promoteFromWaitlist()`, same reason).
 *
 * HOW IT HAPPENED
 * -------------------------------------------------------------------------
 * Before commit e1d0a34 (#518), the members page's "Add User" wrote an
 * account into `tblUsers` and nothing into `tblUserSites` at all. "Remove
 * from site" (`admin/sites/users.php`) can also delete a membership row
 * outright, which is the one path that still makes a row-less account on
 * purpose today.
 *
 * WHAT THIS PAGE DELIBERATELY DOES NOT SHOW
 * -------------------------------------------------------------------------
 * An OFFBOARDED account — one whose only membership row has
 * `isActive = 0` — is NOT listed here and is not affected by any of this.
 * It genuinely belongs nowhere right now; that is what offboarding means,
 * and the `NOT EXISTS` test below only ever matches an account with no
 * row at all, active or not.
 *
 * WHAT THIS PAGE CANNOT DO
 * -------------------------------------------------------------------------
 * It cannot guess which organisation an account should go into — that
 * judgement call belongs to a human who knows the congregation, which is
 * the whole reason this page exists instead of a second automatic
 * back-fill rule. It also cannot undo a placement once made; removing
 * somebody from an organisation again is the existing "Remove from site"
 * control on `/admin/sites/users`.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/533
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\AccountGuard;
use Portal\Core\Auth;
use Portal\Core\Site;

// 🛡️ Global administrator only. Using AccountGuard:: here (rather than a
//    bare App::isRootAdmin() call) both matches the wording
//    `admin/sites/*`'s sibling pages already use for the same "umbrella
//    admin only" refusal, and is what
//    tools/audit-checks/check_account_writes_guarded.py looks for on any
//    file that writes tblUserSites — this page's save handler does that,
//    and the check scans this file too because they share the "unplaced"
//    name pattern in the audit trail of this change.
if (AccountGuard::actorIsGlobal() === false) {
    http_response_code(403);
    echo t('error.umbrella_admin_only');
    exit();
}

// 📌 Page metadata
$pageTitle   = 'No organisation';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Users' => '/admin/users', 'No organisation' => ''];

// -----------------------------------------------------------------------------
// 📋 Every account with no tblUserSites row at all, capped at 500 so this
//    page can never become the next unbounded query — the same cap the
//    check-in rate limit settled on for a different reason (#519).
// -----------------------------------------------------------------------------
$totalUnplaced = 0;
$countResult = $mysqli->query(
    'SELECT COUNT(*) AS cnt FROM tblUsers u '
    . 'WHERE NOT EXISTS (SELECT 1 FROM tblUserSites us WHERE us.userID = u.userID)'
);
if ($countResult !== false) {
    $totalUnplaced = (int) ($countResult->fetch_assoc()['cnt'] ?? 0);
}

$accounts = [];
$listResult = $mysqli->query(
    'SELECT u.userID, u.fullName, u.emailAddress, u.isActive, u.createdAt FROM tblUsers u '
    . 'WHERE NOT EXISTS (SELECT 1 FROM tblUserSites us WHERE us.userID = u.userID) '
    . 'ORDER BY u.fullName ASC LIMIT 500'
);
if ($listResult !== false) {
    while ($row = $listResult->fetch_assoc()) {
        $accounts[] = $row;
    }
}

// 🌐 Every active organisation, for the "place into" picker on each row.
$organisations = Site::allActive($mysqli);

// 📋 Flash message from the save handler
$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 🧭 No organisation -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-compass me-2"></i>Accounts that belong to no organisation</h1>
    <a href="/admin/users" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i>Back to Users
    </a>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="alert alert-info">
    <p class="mb-2">
        <strong>What this means for these accounts.</strong> Their calendar subscription answers
        &ldquo;Invalid token&rdquo; instead of working. They cannot check in to an internal event at the
        door. No organisation's administrator can see them on the Users page or in any member picker. And
        they cannot be promoted off an event's waiting list.
    </p>
    <p class="mb-2">
        <strong>How this happens.</strong> An account created at Admin &rarr; Users before September 2026
        got no membership record at all &mdash; that gap is now fixed for new accounts, but existing ones
        are unaffected until placed here. &ldquo;Remove from site&rdquo; also leaves an account with none.
    </p>
    <p class="mb-0">
        <strong>What to do.</strong> Pick the right organisation for each account below and press
        &ldquo;Add to organisation&rdquo;. Accounts that have been formally offboarded (their only
        membership record is switched off, not removed entirely) are <em>not</em> shown here and are
        unaffected &mdash; they belong nowhere on purpose.
    </p>
</div>

<?php if ($totalUnplaced > 500): ?>
    <p class="text-muted small">Showing the first 500 of <?php echo number_format($totalUnplaced); ?>.</p>
<?php endif; ?>

<?php if (count($accounts) === 0): ?>
    <div class="alert alert-success mb-0">
        <i class="fa-solid fa-circle-check me-1"></i>Every account belongs to an organisation.
    </div>
<?php else: ?>
<div class="portal-data-list">
    <div class="portal-data-row portal-data-header d-none d-md-flex">
        <div class="col-md-3">Name</div>
        <div class="col-md-3">Email</div>
        <div class="col-md-2">Status</div>
        <div class="col-md-2">Created</div>
        <div class="col-md-2 text-end">Place into</div>
    </div>
    <?php foreach ($accounts as $account): ?>
        <?php
        $uid      = (int) $account['userID'];
        $isActive = $account['isActive'] === 1 || $account['isActive'] === '1';
        ?>
        <div class="portal-data-row">
            <div class="col-12 col-md-3">
                <span class="d-md-none fw-semibold">Name: </span>
                <strong><?php echo htmlspecialchars((string) ($account['fullName'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div class="col-12 col-md-3">
                <span class="d-md-none fw-semibold">Email: </span>
                <small><?php echo htmlspecialchars((string) $account['emailAddress'], ENT_QUOTES, 'UTF-8'); ?></small>
            </div>
            <div class="col-12 col-md-2">
                <span class="d-md-none fw-semibold">Status: </span>
                <?php if ($isActive === true): ?>
                    <span class="badge bg-success">Active</span>
                <?php else: ?>
                    <span class="badge bg-secondary">Inactive</span>
                <?php endif; ?>
            </div>
            <div class="col-12 col-md-2">
                <span class="d-md-none fw-semibold">Created: </span>
                <small class="text-muted"><?php echo htmlspecialchars((string) $account['createdAt'], ENT_QUOTES, 'UTF-8'); ?></small>
            </div>
            <div class="col-12 col-md-2 text-md-end mt-2 mt-md-0">
                <?php if (count($organisations) === 0): ?>
                    <span class="text-muted small">No active organisations</span>
                <?php else: ?>
                <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/users/unplaced/save'), ENT_QUOTES, 'UTF-8'); ?>" class="d-flex gap-1 justify-content-md-end">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="userID" value="<?php echo $uid; ?>">
                    <select name="siteID" class="form-select form-select-sm" style="max-width: 12rem;" required>
                        <option value="">Choose&hellip;</option>
                        <?php foreach ($organisations as $org): ?>
                            <option value="<?php echo (int) $org['siteID']; ?>"><?php echo htmlspecialchars((string) $org['siteName'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-sm btn-success">
                        <i class="fa-solid fa-plus me-1"></i>Add
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
