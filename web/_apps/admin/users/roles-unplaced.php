<?php
// Path: _apps/admin/users/roles-unplaced.php
/**
 * -----------------------------------------------------------------------------
 * Role keys waiting to be placed 🖊️ (#516)
 * -----------------------------------------------------------------------------
 * A list, for a GLOBAL administrator only, of every role holding migration
 * 202 could not carry over automatically — the exact same shape
 * `admin/users/unplaced.php` already proved for accounts with no
 * organisation at all (#533). See that file's own header for the fuller
 * reasoning; this one only restates what is different about roles.
 *
 * HOW A ROW GETS HERE
 * -------------------------------------------------------------------------
 * `tblUserRoles` used to be portal-wide, so a hand-inserted row never
 * named an organisation. Migration 202 tries to work out where each such
 * row belongs, but ONLY where that is not a guess (a single-organisation
 * portal, or a multi-organisation one holding exactly one organisation
 * row). Everywhere else — genuinely more than one organisation to choose
 * from, or no active membership for the role's own organisation — the
 * holding is copied here instead of guessed at, and removed from
 * `tblUserRoles` so it grants nothing in the meantime.
 *
 * WHAT THIS PAGE CANNOT DO
 * -------------------------------------------------------------------------
 * It cannot guess which organisation a row belongs to — that judgement
 * belongs to a human who knows the congregation, the same reason
 * `admin/users/unplaced.php` exists instead of a second automatic
 * back-fill rule. It refuses to place a role into an organisation the
 * person is not an ACTIVE member of (Q4 of the #516 plan) — add them to
 * that organisation first (Admin -> Sites -> Users, or this same "no
 * organisation" list), then come back here.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/516
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\AccountGuard;
use Portal\Core\Auth;
use Portal\Core\Site;

// 🛡️ Global administrator only — see unplaced.php's own comment on why
//    AccountGuard:: (not a bare App::isRootAdmin() call) is the spelling
//    used here: it is what tools/audit-checks/check_account_writes_guarded.py
//    looks for on any file whose save handler writes a guarded table.
if (AccountGuard::actorIsGlobal() === false) {
    http_response_code(403);
    echo t('error.umbrella_admin_only');
    exit();
}

// 📌 Page metadata
$pageTitle   = 'Roles awaiting placement';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Users' => '/admin/users', 'Roles awaiting placement' => ''];

// -----------------------------------------------------------------------------
// 📋 Every parked role key, capped at 500 — the same cap #533's page
//    settled on, for the same reason (nothing here legitimately needs more
//    in one render, and an unbounded query is the last thing a "things
//    have already gone slightly wrong" page should risk becoming).
// -----------------------------------------------------------------------------
$totalUnplaced = 0;
$countResult = $mysqli->query('SELECT COUNT(*) AS cnt FROM tblUserRolesUnplaced');
if ($countResult !== false) {
    $totalUnplaced = (int) ($countResult->fetch_assoc()['cnt'] ?? 0);
}

$entries = [];
$listResult = $mysqli->query(
    'SELECT p.unplacedID, p.userID, u.fullName, u.emailAddress, p.roleKey, p.roleName, p.createdAt '
    . 'FROM tblUserRolesUnplaced p JOIN tblUsers u ON u.userID = p.userID '
    . 'ORDER BY u.fullName ASC, p.roleKey ASC LIMIT 500'
);
if ($listResult !== false) {
    while ($row = $listResult->fetch_assoc()) {
        $entries[] = $row;
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

<!-- 🖊️ Roles awaiting placement -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-user-tag me-2"></i>Roles awaiting placement</h1>
    <a href="<?php echo htmlspecialchars(Site::url('admin/users'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">
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
        <strong>What this means for these people.</strong> Each row below is a role somebody held before roles
        belonged to one organisation each (#516). The portal could not work out, on its own, which organisation
        it should apply to, so the role currently grants them nothing until it is placed.
    </p>
    <p class="mb-2">
        <strong>How this happens.</strong> These rows only exist if a role was written directly into the
        database by hand, before this feature existed, on a portal with more than one organisation. On an
        ordinary installation this list is always empty.
    </p>
    <p class="mb-0">
        <strong>What to do.</strong> Pick the right organisation for each row below and press &ldquo;Place&rdquo;.
        The role must already exist in that organisation's own list (Admin &rarr; Roles) and the person must
        already be an active member of it — add them first if not, at Admin &rarr; Sites &rarr; Users.
    </p>
</div>

<?php if ($totalUnplaced > 500): ?>
    <p class="text-muted small">Showing the first 500 of <?php echo number_format($totalUnplaced); ?>.</p>
<?php endif; ?>

<?php if (count($entries) === 0): ?>
    <div class="alert alert-success mb-0">
        <i class="fa-solid fa-circle-check me-1"></i>Nothing is waiting to be placed.
    </div>
<?php else: ?>
<div class="portal-data-list">
    <div class="portal-data-row portal-data-header d-none d-md-flex">
        <div class="col-md-3">Name</div>
        <div class="col-md-3">Email</div>
        <div class="col-md-2">Role</div>
        <div class="col-md-4 text-end">Place into</div>
    </div>
    <?php foreach ($entries as $entry): ?>
        <?php $unplacedId = (int) $entry['unplacedID']; ?>
        <div class="portal-data-row">
            <div class="col-12 col-md-3">
                <span class="d-md-none fw-semibold">Name: </span>
                <strong><?php echo htmlspecialchars((string) ($entry['fullName'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <div class="col-12 col-md-3">
                <span class="d-md-none fw-semibold">Email: </span>
                <small><?php echo htmlspecialchars((string) $entry['emailAddress'], ENT_QUOTES, 'UTF-8'); ?></small>
            </div>
            <div class="col-12 col-md-2">
                <span class="d-md-none fw-semibold">Role: </span>
                <?php echo htmlspecialchars((string) $entry['roleName'], ENT_QUOTES, 'UTF-8'); ?>
                <br><code class="small text-muted"><?php echo htmlspecialchars((string) $entry['roleKey'], ENT_QUOTES, 'UTF-8'); ?></code>
            </div>
            <div class="col-12 col-md-4 text-md-end mt-2 mt-md-0">
                <?php if (count($organisations) === 0): ?>
                    <span class="text-muted small">No active organisations</span>
                <?php else: ?>
                <div class="d-flex gap-1 justify-content-md-end flex-wrap">
                    <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/users/roles-unplaced/save'), ENT_QUOTES, 'UTF-8'); ?>" class="d-flex gap-1">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="action" value="place">
                        <input type="hidden" name="unplacedID" value="<?php echo $unplacedId; ?>">
                        <select name="siteID" class="form-select form-select-sm" style="max-width: 12rem;" required>
                            <option value="">Choose&hellip;</option>
                            <?php foreach ($organisations as $org): ?>
                                <option value="<?php echo (int) $org['siteID']; ?>"><?php echo htmlspecialchars((string) $org['siteName'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm btn-success">
                            <i class="fa-solid fa-plus me-1"></i>Place
                        </button>
                    </form>
                    <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/users/roles-unplaced/save'), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="action" value="discard">
                        <input type="hidden" name="unplacedID" value="<?php echo $unplacedId; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Discard this parked role key? This cannot be undone.">
                            <i class="fa-solid fa-trash me-1"></i>Discard
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
