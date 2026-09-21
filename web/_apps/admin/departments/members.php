<?php
// Path: _apps/admin/departments/members.php
/**
 * -----------------------------------------------------------------------------
 * Who is in a department, and with which flags 🏢 (#517)
 * -----------------------------------------------------------------------------
 * The roster of ONE department of the organisation currently open: everyone
 * recorded in it, their five flags (lead, required approver, approver,
 * assistant, secretary — `Departments::FLAGS`) with a "Save flags" button
 * per person, a Remove button, and an "Add member" form offering only this
 * organisation's active members who are not in the department yet.
 *
 * WHO MAY USE THIS PAGE: any administrator of the organisation currently
 * open (`App::isAdmin()`); the save handler then asks `AccountGuard` about
 * the particular account.
 *
 * An unknown department and another organisation's department both get the
 * same "page not found" (one lookup by (deptID, siteID) finds nothing).
 *
 * A RETIRED department shows its roster and still lets the flags be
 * changed — its pending claims are still finished by its own approvers
 * (owner's answer Q3), so an administrator may need to change who that is —
 * but offers no "Add member" form.
 *
 * WHAT THIS PAGE CANNOT DO: it does not hide somebody whose membership of
 * the organisation has ended; it shows "(membership ended)". Such a person
 * approves nothing while it is ended. An organisation's administrator
 * cannot change or remove that row: the account-change guard treats an
 * ended membership as "not in this organisation", so only a global
 * administrator can. Each row asks the guard's own `verdict()` (which
 * records nothing) and shows a plain note instead of flag boxes and a Remove
 * button that could only fail (the first version drew them anyway). The
 * same applies to a global administrator's account.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.1
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/517
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\AccountGuard;
use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Departments;
use Portal\Core\Router;
use Portal\Core\Site;

if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$siteId = Site::id();
$deptId = (int) ($_GET['id'] ?? 0);
$dept   = Departments::get($mysqli, $deptId, $siteId);
if ($dept === null) {
    Router::renderError(404);
    return;
}

// 📌 Page metadata
$pageTitle   = 'Department members — ' . $dept['deptName'];
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Departments' => Site::url('admin/departments'), 'Members' => ''];

$members = Departments::membersOf($mysqli, $deptId, $siteId);

// 👤 Who can be added: this organisation's ACTIVE members, with an active
//    account, not already in this department.
// 🛡️ Hide the accounts a site administrator can never add (#517 check,
//    21 September 2026). The save handler asks AccountGuard with
//    REACH_THIS_ORG, and for anyone who is not a global administrator the
//    guard refuses an account that is itself a global administrator
//    (isRootAdmin) or holds the older portal-wide isAdmin flag (rows 6 and 7
//    of AccountGuard::decide()). The first version of this list still
//    offered those accounts, so pressing Add could only ever end in "Only a
//    global administrator can change this account". The same method the
//    guard uses decides who is global here, so the list and the refusal
//    cannot disagree. A global administrator still sees everyone. This only
//    tidies the list: the save handler's own check is what protects those
//    accounts, and it is unchanged.
$hideProtected = (AccountGuard::actorIsGlobal() === false)
    ? 'AND COALESCE(u.isRootAdmin, 0) = 0 AND COALESCE(u.isAdmin, 0) = 0 '
    : '';
$candidates = [];
if ($dept['isActive'] === true) {
    $candStmt = $mysqli->prepare(
        'SELECT u.userID, u.fullName, u.emailAddress FROM tblUsers u '
        . 'JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
        . 'WHERE u.isActive = 1 '
        . $hideProtected
        . 'AND u.userID NOT IN (SELECT userID FROM tblUserDepts WHERE deptID = ? AND siteID = ?) '
        . 'ORDER BY u.fullName'
    );
    if ($candStmt !== false) {
        $candStmt->bind_param('iii', $siteId, $deptId, $siteId);
        $candStmt->execute();
        $candResult = $candStmt->get_result();
        while (($row = $candResult->fetch_assoc()) !== null) {
            $candidates[] = $row;
        }
        $candStmt->close();
    }
}

// 📋 Flash message from the save handler
$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$csrf    = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
$saveUrl = htmlspecialchars(Site::url('admin/departments/members/save'), ENT_QUOTES, 'UTF-8');

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 🏢 Department members -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0">
        <i class="fa-solid fa-building me-2"></i><?php echo htmlspecialchars($dept['deptName'], ENT_QUOTES, 'UTF-8'); ?>
        <small class="text-muted fs-6">#<?php echo (int) $dept['deptID']; ?></small>
        <?php if ($dept['isActive'] === false): ?>
            <span class="badge bg-secondary fs-6 align-middle">Retired</span>
        <?php endif; ?>
    </h1>
    <a href="<?php echo htmlspecialchars(Site::url('admin/departments'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i>Back to Departments
    </a>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($dept['isActive'] === false): ?>
    <div class="alert alert-secondary">
        <i class="fa-solid fa-box-archive me-1"></i>This department is retired: no new expense claim can be charged to
        it, and nobody new can be added. Claims already submitted to it are still finished by the people flagged
        below, so their flags can still be changed.
    </div>
<?php endif; ?>

<!-- 🏷️ What each flag means, rendered from Departments::FLAGS so this page
     can never describe a flag differently from the code. -->
<details class="mb-3">
    <summary class="text-muted">What the flags mean</summary>
    <ul class="mt-2 mb-0 small">
        <?php foreach (Departments::FLAGS as $flag): ?>
            <li><strong><?php echo htmlspecialchars($flag['label'], ENT_QUOTES, 'UTF-8'); ?></strong> —
                <?php echo htmlspecialchars($flag['description'], ENT_QUOTES, 'UTF-8'); ?>.</li>
        <?php endforeach; ?>
        <!-- #542: this used to say the Expense Approver role was ALSO needed. It was, and that stranded
             claims whose lead or required approver lacked it. The flags alone decide now. -->
        <li>These flags are enough on their own: a non-administrator does not also need the Expense Approver role to decide this department's claims.</li>
    </ul>
</details>

<?php if (count($members) === 0): ?>
    <p class="text-muted">Nobody is in this department yet.</p>
<?php else: ?>
<div class="portal-data-list mb-4">
    <div class="portal-data-row portal-data-header d-none d-md-flex">
        <div class="col-md-3">Name</div>
        <div class="col-md-7">Flags</div>
        <div class="col-md-2 text-end">Actions</div>
    </div>
    <?php foreach ($members as $member): ?>
        <?php
        $uid       = (int) $member['userID'];
        $formId    = 'flags-' . $uid;
        $canChange = AccountGuard::verdict($uid, AccountGuard::REACH_THIS_ORG) === AccountGuard::ALLOW;
        ?>
        <div class="portal-data-row">
            <div class="col-12 col-md-3">
                <span class="d-md-none fw-semibold">Name: </span>
                <strong><?php echo htmlspecialchars((string) $member['fullName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                <?php if ($member['memberActive'] === false): ?>
                    <small class="text-muted">(membership ended)</small>
                <?php endif; ?>
                <br><small class="text-muted"><?php echo htmlspecialchars((string) $member['emailAddress'], ENT_QUOTES, 'UTF-8'); ?></small>
            </div>
            <div class="col-12 col-md-7">
                <span class="d-md-none fw-semibold">Flags: </span>
                <?php if ($canChange === false): ?>
                    <?php foreach (Departments::FLAGS as $flagKey => $flag): ?>
                        <?php if ($member[$flagKey] === true): ?>
                            <span class="badge bg-info text-dark"><?php echo htmlspecialchars($flag['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                <form method="post" action="<?php echo $saveUrl; ?>" id="<?php echo $formId; ?>" class="d-flex flex-wrap gap-2 align-items-center">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="action" value="flags">
                    <input type="hidden" name="deptID" value="<?php echo (int) $dept['deptID']; ?>">
                    <input type="hidden" name="userID" value="<?php echo $uid; ?>">
                    <?php foreach (Departments::FLAGS as $flagKey => $flag): ?>
                        <?php $boxId = $formId . '-' . $flagKey; ?>
                        <div class="form-check form-check-inline mb-0">
                            <input class="form-check-input" type="checkbox" id="<?php echo $boxId; ?>" name="flags[<?php echo $flagKey; ?>]" value="1"<?php echo $member[$flagKey] === true ? ' checked' : ''; ?>>
                            <label class="form-check-label small" for="<?php echo $boxId; ?>" title="<?php echo htmlspecialchars($flag['description'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flag['label'], ENT_QUOTES, 'UTF-8'); ?></label>
                        </div>
                    <?php endforeach; ?>
                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-floppy-disk me-1"></i>Save flags</button>
                </form>
                <?php endif; ?>
            </div>
            <div class="col-12 col-md-2 text-md-end mt-2 mt-md-0">
                <?php if ($canChange === true): ?>
                <form method="post" action="<?php echo $saveUrl; ?>" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="deptID" value="<?php echo (int) $dept['deptID']; ?>">
                    <input type="hidden" name="userID" value="<?php echo $uid; ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Remove <?php echo htmlspecialchars((string) $member['fullName'], ENT_QUOTES, 'UTF-8'); ?> from this department?">
                        <i class="fa-solid fa-user-minus me-1"></i>Remove
                    </button>
                </form>
                <?php else: ?>
                    <small class="text-muted">Only a global administrator can change this.</small>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($dept['isActive'] === true): ?>
    <h2 class="h5">Add member</h2>
    <?php if (count($candidates) === 0): ?>
        <p class="text-muted">Every active member of this organisation is already in this department.</p>
    <?php else: ?>
    <form method="post" action="<?php echo $saveUrl; ?>" class="row g-2 align-items-end">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="deptID" value="<?php echo (int) $dept['deptID']; ?>">
        <div class="col-12 col-md-5">
            <label class="form-label" for="add-userID">Person</label>
            <select class="form-select" id="add-userID" name="userID" required>
                <option value="">Choose&hellip;</option>
                <?php foreach ($candidates as $candidate): ?>
                    <option value="<?php echo (int) $candidate['userID']; ?>">
                        <?php echo htmlspecialchars((string) ($candidate['fullName'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        (<?php echo htmlspecialchars((string) ($candidate['emailAddress'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-5">
            <span class="form-label d-block">Flags</span>
            <?php foreach (Departments::FLAGS as $flagKey => $flag): ?>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" id="add-<?php echo $flagKey; ?>" name="flags[<?php echo $flagKey; ?>]" value="1">
                    <label class="form-check-label small" for="add-<?php echo $flagKey; ?>" title="<?php echo htmlspecialchars($flag['description'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flag['label'], ENT_QUOTES, 'UTF-8'); ?></label>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="col-12 col-md-2">
            <button type="submit" class="btn btn-success"><i class="fa-solid fa-user-plus me-1"></i>Add</button>
        </div>
    </form>
    <?php endif; ?>
<?php endif; ?>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
