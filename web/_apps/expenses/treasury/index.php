<?php
// Path: public_html/expenses/treasury/index.php
/**
 * -----------------------------------------------------------------------------
 * Treasury — Reimbursement Dashboard 💷
 * -----------------------------------------------------------------------------
 * Lists approved expense claims awaiting reimbursement. Treasury users can
 * mark claims as Reimbursed and record payment references via a modal form.
 *
 * Who may open it (#538): only a treasurer of the organisation whose address
 * is open, or an administrator — the same test save.php applies before it
 * records a payment. Everybody else gets the standard "Access Denied" page
 * before any claim is fetched.
 *
 * @package   Portal\Expenses
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.4.1
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;

// 📌 Page metadata for the template system
$pageTitle   = 'Treasury';
$pageSection = 'expenses';
$breadcrumbs = ['Dashboard' => '/', 'Expenses' => '/expenses/treasury', 'Treasury' => ''];

// -----------------------------------------------------------------------------
// 🔒 #538 (21 September 2026) — who may open this page at all
// -----------------------------------------------------------------------------
// WHAT WAS WRONG BEFORE: nothing in this file asked who was looking. The
// address needs a sign-in (its row in tblRoutes is protected) and that was
// the only test, so ANY signed-in account on the whole installation could
// open this page and read every approved claim of whichever organisation's
// address they were on: the claim title, the claimant's full name, their
// department, the amount and the date. That included accounts belonging to
// a DIFFERENT organisation altogether — confirmed on a real database: a
// member of organisation B, opening organisation A's address, was shown A's
// whole queue. Only the Pay button's handler (save.php) ever checked for a
// role, so a member could look at everything but pay nothing.
//
// THE FIX: the same test the Pay handler uses, asked here BEFORE the claims
// are fetched, so a refused visitor never causes the claims query to run.
// Since #516, App::hasRole() answers for the organisation whose address is
// open, and only through an active membership there — so a treasurer of
// organisation B is refused on organisation A's address, and so is somebody
// whose membership here has ended. App::isAdmin() is true for an
// administrator of THIS organisation (through their active membership row),
// for a global administrator, and for an account carrying the older
// portal-wide isAdmin flag; that last flag opens every organisation's
// treasury, which is what it already does on every other page here, and is
// not changed by this fix.
//
// WHY THE ANSWER IS 403 AND NOT 404: the portal's standard "Access Denied"
// page is what every other role-gated page gives (expenses/view/index.php,
// the calendar, giving and payments management pages). This page is
// described in the help centre, so its existence is not a secret worth
// hiding; what must never leak is the list. Refusing here means every
// refused visitor — a member without the role, a member of another
// organisation, an ended membership — gets the same page for the same cost:
// one role lookup and no claims query.
//
// WHAT THIS CANNOT DO: it does not stop a non-member reaching this
// organisation's address at all, or seeing its name and navigation on the
// refusal page — every page of the portal behaves that way today, and that
// is not this page's to change.
//
// 'treasurer' is written as the stored key. Roles::has() lower-cases the key
// before comparing, so the 'Treasurer' spelling other files use works too;
// the stored form is used here so this test and save.php's read identically.
if (App::hasRole('treasurer') === false && App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// 📋 Flash message
$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

// 📋 Fetch approved claims awaiting reimbursement
$claims = [];
$siteId = Site::id();
$stmt = $mysqli->prepare(
    'SELECT EC.claimID, EC.claimTitle, U.fullName, D.deptName, EC.totalAmount, EC.createdAt '
    . 'FROM tblExpenseClaims EC '
    . 'JOIN tblUsers U ON U.userID = EC.userID '
    . 'JOIN tblDepts D ON D.deptID = EC.deptID '
    . 'WHERE EC.status = "Approved" AND EC.siteID = ? '
    . 'ORDER BY EC.createdAt DESC'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $claims[] = $row;
    }
    $stmt->close();
}

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- 💷 Treasury Dashboard -->
<h1 class="mb-4"><i class="fa-solid fa-sterling-sign me-2"></i>Treasury — Approved Claims</h1>

<?php if (empty($claims) === true): ?>
    <div class="alert alert-info">No approved claims awaiting reimbursement.</div>
<?php else: ?>

    <!-- 📋 Responsive data list (replaces <table>) -->
    <div class="portal-data-list">
        <!-- 🏷️ Header row (visible on md+ screens) -->
        <div class="portal-data-row portal-data-header d-none d-md-flex">
            <div class="col-md-1">ID</div>
            <div class="col-md-3">Title</div>
            <div class="col-md-2">Claimant</div>
            <div class="col-md-2">Department</div>
            <div class="col-md-1 text-end">Total &pound;</div>
            <div class="col-md-2 text-end">Submitted</div>
            <div class="col-md-1 text-end"></div>
        </div>

        <?php foreach ($claims as $c): ?>
            <div class="portal-data-row">
                <div class="col-12 col-md-1">
                    <span class="d-md-none fw-semibold">ID: </span>#<?php echo (int) $c['claimID']; ?>
                </div>
                <div class="col-12 col-md-3">
                    <span class="d-md-none fw-semibold">Title: </span><?php echo htmlspecialchars($c['claimTitle'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="col-12 col-md-2">
                    <span class="d-md-none fw-semibold">Claimant: </span><?php echo htmlspecialchars($c['fullName'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="col-12 col-md-2">
                    <span class="d-md-none fw-semibold">Dept: </span><?php echo htmlspecialchars($c['deptName'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="col-12 col-md-1 text-md-end">
                    <span class="d-md-none fw-semibold">Total: </span>&pound;<?php echo number_format((float) $c['totalAmount'], 2); ?>
                </div>
                <div class="col-12 col-md-2 text-md-end small text-secondary">
                    <span class="d-md-none fw-semibold">Submitted: </span><?php echo \Portal\Core\I18n::formatDate($c['createdAt']); ?>
                </div>
                <div class="col-12 col-md-1 text-md-end mt-2 mt-md-0">
                    <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#payModal"
                            data-claim='<?php echo htmlspecialchars(json_encode($c, JSON_HEX_TAG | JSON_HEX_APOS), ENT_QUOTES, 'UTF-8'); ?>'>
                        Pay
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

<?php endif; ?>

<!-- 💳 Reimbursement Modal -->
<div class="modal fade" id="payModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <form method="post" action="/expenses/treasury/save">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="claimID" id="payClaimID">
                <div class="modal-header">
                    <h5 class="modal-title">Record Reimbursement</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="payClaimDetails"></div>
                    <div class="mb-3">
                        <label class="form-label">Payment Reference(s)</label>
                        <input type="text" class="form-control" name="payRef[]" placeholder="e.g. BACS-12345" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Comments (optional)</label>
                        <textarea class="form-control" name="comments" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Mark as Reimbursed</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 📦 Modal population script -->
<script>
const payModal = document.getElementById('payModal');
payModal.addEventListener('show.bs.modal', ev => {
    const btn  = ev.relatedTarget;
    const data = JSON.parse(btn.getAttribute('data-claim'));
    document.getElementById('payClaimID').value = data.claimID;
    const container = document.getElementById('payClaimDetails');
    container.textContent = '';
    const dl = document.createElement('dl');
    dl.className = 'row';
    const fields = [
        ['Title', data.claimTitle],
        ['Claimant', data.fullName],
        ['Department', data.deptName],
        ['Total', '\u00a3' + parseFloat(data.totalAmount).toFixed(2)],
        ['Submitted', data.createdAt]
    ];
    fields.forEach(([label, value]) => {
        const dt = document.createElement('dt');
        dt.className = 'col-sm-4';
        dt.textContent = label;
        const dd = document.createElement('dd');
        dd.className = 'col-sm-8';
        dd.textContent = value;
        dl.appendChild(dt);
        dl.appendChild(dd);
    });
    container.appendChild(dl);
});
</script>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
