<?php
// Path: public_html/expenses/approve/index.php
/**
 * -----------------------------------------------------------------------------
 * Expenses — Approval Dashboard ✔️
 * -----------------------------------------------------------------------------
 * Lists pending claims that the current user is authorised to approve. Shows
 * summary info, status badges, and opens a modal to approve / decline each.
 * Supports multi-approver workflow with dept-based filtering.
 *
 * A person's OWN claims are never listed (#545): nobody decides their own
 * claim, so offering it here would only lead to a refusal. The page says so
 * in one line, so a lead looking for their own claim knows why it is absent.
 *
 * @package   Portal\Expenses
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.4.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\ExpenseRules;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

// 📌 Page metadata for the template system
$pageTitle   = 'Approve Expense Claims';
$pageSection = 'expenses';
$breadcrumbs = ['Dashboard' => Router::url(''), 'Expenses' => Router::url('expenses/approve'), 'Approve' => ''];

// 📋 Flash message
$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

// 📋 Fetch pending claims — admins see all, everybody else their own departments' claims
$claims = [];
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$siteId = Site::id();

// 👁️ WHICH claims are listed: `ExpenseRules::reachToDecide()` (#648).
//
//    An administrator sees every pending claim of this organisation; anybody
//    else sees the pending claims of the departments they are a lead,
//    approver or required approver of — exactly the claims
//    `expenses/approve/save.php` will let them decide. The organisation-wide
//    Approver role on its own adds NO department (it never did on this page,
//    and since D-103 it does not on the claim page or in the spreadsheet
//    either). A treasurer is not offered other departments' claims here: they
//    pay claims, they do not decide them.
//
//    This used to be two queries, one per kind of person, with the department
//    rule written out as a join in the second. The join is now
//    `Departments::approverDepts()` — the same read the decision handler, the
//    claim page and the spreadsheet use — turned into a condition by
//    `ExpenseRules::reachSql()`, so the four places cannot disagree about
//    which departments somebody has. What that read already did, and the old
//    join did too, is unchanged:
//
//    #517: the department membership must belong to THIS organisation, and
//    the approver's own membership of it must be active. Before #517 neither
//    was tested: a membership row left behind after somebody left the
//    organisation, or a hand-made row naming this department from another
//    organisation, still showed them the claim (measured before #517: a
//    member of another organisation only was listed as an approver here).
//
//    DELIBERATELY NO TEST THAT THE DEPARTMENT IS SWITCHED ON (owner's answer
//    Q3, 21 September 2026): retiring a department stops NEW claims being
//    charged to it, while claims already submitted are finished by its own
//    approvers, exactly as before it was retired. Adding `D.isActive = 1`
//    here was considered and rejected: it was proven during planning to
//    strand such a claim for ever, because save.php still requires every lead
//    and required approver to approve, and an administrator's approval alone
//    never satisfies that.
//
//    🙅 #545: `EC.userID <> ?` leaves the viewer's OWN claims out, for an
//    administrator too: the handlers refuse a person's own claim, so a list
//    that offered it would be offering a button that cannot work.
$reach = ExpenseRules::reachSql(ExpenseRules::reachToDecide($mysqli, $currentUserId, $siteId), 'EC.deptID');
$stmt  = $mysqli->prepare(
    'SELECT EC.claimID, EC.claimTitle, U.fullName, D.deptName, EC.totalAmount, EC.`currency`, EC.createdAt '
    . 'FROM tblExpenseClaims EC '
    . 'JOIN tblUsers U ON U.userID = EC.userID '
    . 'JOIN tblDepts D ON D.deptID = EC.deptID '
    . "WHERE EC.status = 'Pending' AND EC.siteID = ? AND EC.userID <> ? AND " . $reach['sql'] . ' '
    . 'ORDER BY EC.createdAt DESC'
);

if ($stmt !== false) {
    $stmt->bind_param('ii' . $reach['types'], $siteId, $currentUserId, ...$reach['params']);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $claims[] = $row;
    }
    $stmt->close();
}

// 💷 Each claim in its OWN currency, written here once (#966, D-139): the
//    list and the Review window both read `totalWords`, so neither builds a
//    symbol of its own (the window used to write a pound sign, by its escape code,
//    whatever the claim was in). `quotedWords` lists the lines quoted in
//    another currency, added up per currency, so the person deciding sees
//    both figures; the claim's own figure is the one approved and paid.
$quotedByClaim = \Portal\Core\ExpenseRules::quotedElsewhere($mysqli, array_map(static fn (array $c): int => (int) $c['claimID'], $claims));
foreach ($claims as $ci => $c) {
    $claims[$ci]['totalWords']  = \Portal\Core\Currency::formatMajor((float) $c['totalAmount'], (string) ($c['currency'] ?? ''));
    $claims[$ci]['quotedWords'] = implode('; ', $quotedByClaim[(int) $c['claimID']] ?? []);
}

// 📝 D-130 (the owner, 11 November 2026): every look at expense records is
//    recorded. This list shows claim titles, claimants' names and amounts, so
//    opening it writes one `ExpenseApprovalsListView` record naming the person
//    (#626) and saying HOW MANY claims it showed — no title, name or amount.
//    A list showing no claims showed no expense record, so it records nothing;
//    that is also every member with nothing to decide, who would otherwise
//    leave a record on every visit. `Logger::activity()` never throws.
if (count($claims) > 0) {
    Logger::activity('ExpenseApprovalsListView', 'Opened the expense approvals list: ' . count($claims) . ' claim(s) shown');
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

<!-- ✔️ Pending Expense Approvals -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-check-double me-2"></i>Pending Expense Approvals</h1>
    <?php
    // 📤 The Export button, only for somebody the download will actually serve
    //    (#675). This used to be drawn for every visitor of this page, and
    //    this page is open to anybody signed in — it just shows an empty list
    //    to somebody with nothing to decide. So a department approver, a
    //    department lead, a treasurer or an ordinary member saw the button and
    //    pressing it gave them a bare "Forbidden" with no way back.
    //
    //    ExpenseRules::mayExportClaims() is the ONE place that decides, and
    //    `expenses/api/export.php` asks the same question. Never test the
    //    role here instead: two copies is how this broke.
    //
    //    Since #648 the file is not "every claim" for everybody shown the
    //    button: a holder of the Approver role gets the claims of the
    //    departments they approve for, and an administrator or a treasurer
    //    (D-040) every claim. The title used to say "Export all claims",
    //    which stopped being true for the first of those.
    if (ExpenseRules::mayExportClaims() === true):
    ?>
        <a href="<?php echo htmlspecialchars(Router::url('expenses/api/export'), ENT_QUOTES, 'UTF-8'); ?>?csrf_token=<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>"
           class="btn btn-outline-success" title="Export the claims you may see as a CSV file">
            <i class="fa-solid fa-file-csv me-1"></i> Export
        </a>
    <?php endif; ?>
</div>

<!-- #545: said whether or not the list is empty, so somebody looking for a
     claim they made themselves is told why it is not here. -->
<p class="small text-secondary">Your own claims are not listed here: somebody else must decide them.</p>

<?php if (empty($claims) === true): ?>
    <div class="alert alert-info">No claims awaiting your approval.</div>
<?php else: ?>

    <!-- 📋 Responsive data list (replaces <table>) -->
    <div class="portal-data-list">
        <!-- 🏷️ Header row (visible on md+ screens) -->
        <div class="portal-data-row portal-data-header d-none d-md-flex">
            <div class="col-md-1">ID</div>
            <div class="col-md-3">Title</div>
            <div class="col-md-2">Claimant</div>
            <div class="col-md-2">Department</div>
            <div class="col-md-1 text-end">Total</div>
            <div class="col-md-2 text-end">Submitted</div>
            <div class="col-md-1 text-end"></div>
        </div>

        <?php foreach ($claims as $c): ?>
            <div class="portal-data-row">
                <div class="col-12 col-md-1">
                    <?php
                    // 📎 #867: the claim number opens the claim's own page, where its
                    //    receipts are listed and can be opened — so an approver can
                    //    look at the evidence before deciding. Safe to draw for every
                    //    row: this list follows `reachToDecide()`, and every claim it
                    //    reaches is also within `reachToSee()`, which the claim page
                    //    and the receipt address ask (#648).
                    ?>
                    <span class="d-md-none fw-semibold">ID: </span><a href="<?php echo htmlspecialchars(Router::url('expenses/view') . '?id=' . (int) $c['claimID'], ENT_QUOTES, 'UTF-8'); ?>" data-claim-link="<?php echo (int) $c['claimID']; ?>" title="Open the claim and its receipts">#<?php echo (int) $c['claimID']; ?></a>
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
                    <span class="d-md-none fw-semibold">Total: </span><span data-claim-total><?php echo htmlspecialchars($c['totalWords'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php if ($c['quotedWords'] !== ''): ?>
                        <small class="d-block text-muted" data-claim-quoted><?php echo htmlspecialchars($c['quotedWords'], ENT_QUOTES, 'UTF-8'); ?></small>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-md-2 text-md-end small text-secondary">
                    <span class="d-md-none fw-semibold">Submitted: </span><?php echo \Portal\Core\I18n::formatDate($c['createdAt']); ?>
                </div>
                <div class="col-12 col-md-1 text-md-end mt-2 mt-md-0">
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#approveModal"
                            data-claim='<?php echo htmlspecialchars(json_encode($c, JSON_HEX_TAG | JSON_HEX_APOS), ENT_QUOTES, 'UTF-8'); ?>'>
                        Review
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

<?php endif; ?>

<!-- 📝 Approval Modal -->
<div class="modal fade" id="approveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <form method="post" action="<?php echo htmlspecialchars(Router::url('expenses/approve/save'), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="claimID" id="claimID">
                <div class="modal-header">
                    <h5 class="modal-title">Approve Claim</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="claimDetails"></div>
                    <div class="mb-3">
                        <label class="form-label">Decision</label>
                        <select class="form-select" name="decision" required>
                            <option value="Approved">Approve</option>
                            <option value="Rejected">Reject</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Comments (optional)</label>
                        <textarea class="form-control" name="comments" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Submit</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 📦 Modal population script -->
<script nonce="<?php echo htmlspecialchars(\Portal\Core\App::cspNonce(), ENT_QUOTES, 'UTF-8'); ?>">
const modal = document.getElementById('approveModal');
modal.addEventListener('show.bs.modal', ev => {
    const btn  = ev.relatedTarget;
    const data = JSON.parse(btn.getAttribute('data-claim'));
    document.getElementById('claimID').value = data.claimID;
    const container = document.getElementById('claimDetails');
    container.textContent = '';
    const dl = document.createElement('dl');
    dl.className = 'row';
    const fields = [
        ['Title', data.claimTitle],
        ['Claimant', data.fullName],
        ['Department', data.deptName],
        ['Total', data.totalWords],
        ['Quoted in another currency', data.quotedWords || 'Nothing: every line is in the claim\'s own currency'],
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
