<?php
// Path: public_html/expenses/view/index.php
/**
 * -----------------------------------------------------------------------------
 * Expenses — Claim Detail View 📄
 * -----------------------------------------------------------------------------
 * Displays full claim details including line items, uploaded evidence files,
 * approval history, payment records, and PDF download links.
 * Accessible to: the claimant; a lead, approver or required approver of the
 * claim's own department; a treasurer; and an administrator. The
 * organisation-wide Approver role on its own opens only the claims of the
 * departments its holder approves for (#648, D-103). Anybody else is told
 * the claim does not exist.
 *
 * Each RECEIPT links to `expenses/receipt` (#867), which asks the same rule
 * as this page and sends the file; before #867 the list linked to nothing.
 * Opening this page is recorded in the activity log (D-130); a refusal is not.
 *
 * It also shows the one-off message another Expenses page left for this
 * person (#587): the withdraw page sends people here, and until 12 October
 * 2026 this page showed no such message, so "withdrawn successfully", "cannot
 * be withdrawn" and an out-of-date form were all met with silence here and
 * then turned up later on some other page.
 *
 * @package   Portal\Expenses
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.4.2
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Capabilities;
use Portal\Core\ExpenseRules;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

// 📌 Page metadata
$pageTitle   = 'View Claim';
$pageSection = 'expenses';

// 🛡️ Auth check
Auth::ensureSession();
if (Auth::check() === false) {
    Auth::requireLogin();
    return;
}

$claimID = (int) ($_GET['id'] ?? 0);
if ($claimID <= 0) {
    Router::renderError(404);
    return;
}

// -----------------------------------------------------------------------------
// 👁️ Whose claims this person may open — worked out BEFORE the claim is read
// -----------------------------------------------------------------------------
// #648 (D-103, the owner, 10 November 2026): the organisation-wide Approver
// role opens only the claims of the departments its holder approves for —
// exactly what the approvals list shows them and what the decision handler
// lets them decide. Administrators and treasurers open every claim, as
// before; the claimant always opens their own.
//
// WHAT WAS WRONG. This page let `App::can(Capabilities::EXPENSES_APPROVE)`
// (the role) through before it looked at departments at all — the department
// read was even skipped for a role holder — so the role opened ANY claim in
// the organisation in full: line items, suppliers, receipts, approval
// history and payments. #542's comment here said in so many words that this
// was #541 item 3 and had not been decided; D-103 decided it.
//
// THE RULE IS NOT HERE. `ExpenseRules::reachToSee()` works out the reach and
// `ExpenseRules::mayViewClaim()` answers for this one claim; the spreadsheet
// (`expenses/api/export.php`) asks the same reach inside its query. Do not
// put a role or department test back into this page.
//
// It is worked out FIRST, before the claim is looked up, so that somebody
// whose answer is "no" costs the same database work whether the claim number
// is real or made up.
$siteId        = Site::id();
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$reach         = ExpenseRules::reachToSee($mysqli, $currentUserId, $siteId);

// -----------------------------------------------------------------------------
// 📋 Fetch claim header with claimant and department info
// -----------------------------------------------------------------------------
$claim = null;
$stmt = $mysqli->prepare(
    'SELECT EC.*, U.fullName AS claimantName, U.emailAddress AS claimantEmail, D.deptName '
    . 'FROM tblExpenseClaims EC '
    . 'JOIN tblUsers U ON U.userID = EC.userID '
    . 'JOIN tblDepts D ON D.deptID = EC.deptID '
    . 'WHERE EC.claimID = ? AND EC.siteID = ? LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $claimID, $siteId);
    $stmt->execute();
    $claim = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// 🛡️ A claim this person may not open is answered EXACTLY as a claim that
//    does not exist: the same 404, from the same call, after the same reads.
//    It used to be a 403, which told anybody holding a sign-in that the
//    number was a real claim of this organisation. (Ownership before status:
//    a stranger is never told a claim exists — the money pages' rule.)
if ($claim === null
    || ExpenseRules::mayViewClaim($reach, (int) $claim['userID'], (int) $claim['deptID'], $currentUserId) === false) {
    Router::renderError(404);
    return;
}

// 📝 D-130 (the owner, 11 November 2026): every look at an expense record is
//    recorded — this page, the spreadsheet and a receipt. One `ExpenseClaimView`
//    record per opening, naming its person (`Logger::activity()` fills `userID`
//    from the session, #626) and carrying the claim NUMBER only: no title, no
//    amount, no name. Written only HERE, once the page is going to be shown: a
//    refusal and a made-up number are both answered above and both record
//    nothing, so the log cannot tell them apart either (the existence-leak
//    rule). The number is in the WORDS, not the log's `claimID` column, which
//    links to `tblExpenseClaims` with no ON DELETE rule and would then stop
//    `api/expenses/delete` removing a claim anybody had looked at.
//    `Logger::activity()` catches everything itself, so it cannot stop the page.
Logger::activity('ExpenseClaimView', 'Opened expense claim #' . $claimID);

$isClaimant    = ((int) $claim['userID'] === $currentUserId);
$isAdmin       = App::isAdmin();
// 🚪 Only for the "Go to Treasury" button and its note below — NOT for who may
//    open the page, which `reachToSee()` above decided (and which asks the
//    same capability). #739 made this a capability, `expenses.treasury`,
//    that an organisation may point at roles of its own on the Roles page.
$hasTreasurer  = App::can(Capabilities::EXPENSES_TREASURY);

// ✔️ May this person DECIDE this claim — for the "Go to Approvals" button and
//    the claimant's "you cannot decide your own claim" note. Asked of
//    `ExpenseRules::reachToDecide()`, the rule the approvals list follows and
//    the one `expenses/approve/save.php` applies: an administrator, or a lead,
//    approver or required approver of THIS claim's department. The
//    organisation-wide role on its own is not authority to decide (it never
//    was), so it no longer draws a button that leads to a list where the claim
//    is not shown. Read only for a claim still waiting for a decision, which
//    is the only state either use is drawn in.
$mayDecide = false;
if ($claim['status'] === 'Pending') {
    $mayDecide = ExpenseRules::reachCovers(
        ExpenseRules::reachToDecide($mysqli, $currentUserId, $siteId),
        (int) $claim['deptID']
    );
}

// ✏️ May this person change the claim? (#651)
//
//    Asked through `ExpenseRules::whyNotEditable()`, the ONE place that
//    decides, so this page can never offer an Edit button that
//    expenses/edit/save.php would refuse — and never hide one it would allow.
//
//    The approval count is only read when it could possibly matter: the
//    claimant, on a Pending claim. Every other visitor to this page — an
//    approver, a treasurer, an administrator, or the claimant looking at a
//    claim that has moved on — pays nothing for this.
$mayEditClaim = false;
if ($isClaimant === true && $claim['status'] === 'Pending') {
    $mayEditClaim = (ExpenseRules::whyNotEditable(
        (string) $claim['status'],
        ExpenseRules::approvalCount($mysqli, $claimID),
        (int) $claim['userID'],
        $currentUserId
    ) === ExpenseRules::EDIT_ALLOWED);
}

$pageTitle   = 'Claim #' . $claimID . ' — ' . $claim['claimTitle'];
// "Expense Claims" is the Expenses page's own name since #834, which lists
// the claimant's claims above the form; the back button below says the same.
$breadcrumbs = ['Dashboard' => Router::url(''), 'Expense Claims' => Router::url('expenses/submit'), 'Claim #' . $claimID => ''];

// 💷 The claim's OWN currency (#966, D-139): the one it was made in, kept on
//    the row. An older claim that recorded none shows its figures with no
//    currency rather than borrowing today's, which could be a different one.
$claimCurrency = (string) ($claim['currency'] ?? '');
$claimMoney = static fn (float|int|string $amount): string => htmlspecialchars(
    \Portal\Core\Currency::formatMajor($amount, $claimCurrency), ENT_QUOTES, 'UTF-8');

// -----------------------------------------------------------------------------
// 📋 Fetch line items
// -----------------------------------------------------------------------------
$items = [];
$stmt = $mysqli->prepare(
    'SELECT itemName, description, quantity, unitCost, lineTotal, purchaseDate, supplier, '
    . '`currency`, `quotedCurrency`, `quotedLineTotal` '
    . 'FROM tblExpenseClaimItems WHERE claimID = ? ORDER BY itemID'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $claimID);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $items[] = $r;
    }
    $stmt->close();
}

// -----------------------------------------------------------------------------
// 📎 Fetch uploaded evidence files
// -----------------------------------------------------------------------------
$files = [];
$stmt = $mysqli->prepare(
    'SELECT fileID, fileLabel, storedFilename, fileSize, fileType, stage, uploadedAt '
    . 'FROM tblExpenseClaimFiles WHERE claimID = ? ORDER BY uploadedAt'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $claimID);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $files[] = $r;
    }
    $stmt->close();
}

// -----------------------------------------------------------------------------
// ✅ Fetch approval history
// -----------------------------------------------------------------------------
$approvals = [];
$stmt = $mysqli->prepare(
    // LEFT JOIN, not JOIN. An approver's number is emptied on their approvals
    // when they ask to be forgotten (migration 192). With a plain JOIN the
    // whole approval line dropped off this page: the claim looked as though
    // nobody had ever approved it.
    'SELECT A.decision, A.comments, A.approverRole, A.decidedAt, U.fullName AS approverName '
    . 'FROM tblExpenseClaimApprovals A '
    . 'LEFT JOIN tblUsers U ON U.userID = A.userID '
    . 'WHERE A.claimID = ? ORDER BY A.decidedAt'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $claimID);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $approvals[] = $r;
    }
    $stmt->close();
}

// -----------------------------------------------------------------------------
// 💳 Fetch payment records
// -----------------------------------------------------------------------------
$payments = [];
$stmt = $mysqli->prepare(
    'SELECT P.payReference, P.payMethod, P.payAmount, P.addedAt, U.fullName AS paidByName '
    . 'FROM tblExpenseClaimPayments P '
    . 'LEFT JOIN tblUsers U ON U.userID = P.paidByID '
    . 'WHERE P.claimID = ? ORDER BY P.addedAt'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $claimID);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $payments[] = $r;
    }
    $stmt->close();
}

// 📄 The stage the stored document was LAST MADE WITH (#587).
//    The line under "Generated PDF" used to print the claim's CURRENT status
//    there, which was true only while every change of status remade the
//    document. Withdrawing a claim does not remake it, so a withdrawn claim's
//    page said "last generated for status: Withdrawn" beside a document
//    stamped PENDING. ExpensePdf::generate() records the stage each time it
//    writes the file (`tblExpenseClaimFiles.stage`: Pending, Approved, Not
//    Approved or Complete), so the newest such row for this file is the
//    truth. The highest fileID is the newest: `uploadedAt` holds whole
//    seconds, and two rows can share one.
//    When no row says, the page says nothing about the stage rather than
//    guess.
$pdfStage  = '';
$pdfFileId = 0;
if ((string) $claim['fileName'] !== '') {
    foreach ($files as $f) {
        if ($f['storedFilename'] === $claim['fileName'] && (string) $f['stage'] !== '' && (int) $f['fileID'] > $pdfFileId) {
            $pdfFileId = (int) $f['fileID'];
            $pdfStage  = (string) $f['stage'];
        }
    }
}

// 📋 The one-off message another page left for this person, read and cleared
//    exactly as the approvals page does (approve/index.php). Read HERE, after
//    the "not found" and "not allowed" answers above, so that a refused page
//    does not swallow a message the person has not yet been shown.
$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

// 📊 Status badge mapping
$statusClass = match ($claim['status']) {
    'Pending'    => 'warning',
    'Approved'   => 'info',
    'Rejected'   => 'danger',
    'Reimbursed' => 'success',
    'Withdrawn'  => 'secondary',
    default      => 'secondary',
};

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- 📄 Claim Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="mb-1">
            <i class="fa-solid fa-file-invoice me-2"></i>Claim #<?php echo (int) $claim['claimID']; ?>
        </h1>
        <p class="text-muted mb-0"><?php echo htmlspecialchars($claim['claimTitle'], ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
    <span class="badge bg-<?php echo $statusClass; ?> fs-5">
        <?php echo htmlspecialchars($claim['status'], ENT_QUOTES, 'UTF-8'); ?>
    </span>
</div>

<!-- 📊 Claim Summary -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card shadow-sm">
            <div class="card-body text-center">
                <small class="text-muted d-block">Total Amount</small>
                <h3 class="mb-0" data-claim-total><?php echo $claimMoney($claim['totalAmount']); ?></h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm">
            <div class="card-body text-center">
                <small class="text-muted d-block">Claimant</small>
                <strong><?php echo htmlspecialchars($claim['claimantName'], ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm">
            <div class="card-body text-center">
                <small class="text-muted d-block">Department</small>
                <strong><?php echo htmlspecialchars($claim['deptName'], ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm">
            <div class="card-body text-center">
                <small class="text-muted d-block">Submitted</small>
                <strong><?php echo htmlspecialchars(\Portal\Core\I18n::formatDate($claim['createdAt']), ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
        </div>
    </div>
</div>

<!-- 📦 Line Items -->
<div class="card mb-4">
    <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-list me-1"></i>Line Items</h5></div>
    <div class="card-body">
        <div class="portal-data-list">
            <div class="portal-data-row portal-data-header d-none d-md-flex">
                <div class="col-md-4">Description</div>
                <div class="col-md-2 text-center">Qty</div>
                <div class="col-md-2 text-end">Unit cost, as quoted</div>
                <div class="col-md-2 text-end">Line, in the claim's currency</div>
                <div class="col-md-2">Supplier</div>
            </div>

            <?php foreach ($items as $item): ?>
                <div class="portal-data-row">
                    <div class="col-12 col-md-4">
                        <span class="d-md-none fw-semibold">Description: </span>
                        <?php echo htmlspecialchars($item['itemName'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="col-12 col-md-2 text-md-center">
                        <span class="d-md-none fw-semibold">Qty: </span>
                        <?php echo (int) $item['quantity']; ?>
                    </div>
                    <div class="col-12 col-md-2 text-md-end">
                        <?php
                        // 💱 Quoted in another currency (D-139): the quoted
                        //    figure in its own currency, and the rate worked
                        //    out from the two figures — never fetched.
                        $lineQuoted = (string) ($item['quotedCurrency'] ?? '');
                        if ($lineQuoted === '') {
                            $lineQuoted = $claimCurrency;
                        }
                        $lineForeign = $lineQuoted !== $claimCurrency && $item['quotedLineTotal'] !== null;
                        ?>
                        <span class="d-md-none fw-semibold">Unit, as quoted: </span>
                        <?php echo htmlspecialchars(\Portal\Core\Currency::formatMajor($item['unitCost'], $lineQuoted), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="col-12 col-md-2 text-md-end">
                        <span class="d-md-none fw-semibold">Line: </span>
                        <strong><?php echo $claimMoney($item['lineTotal']); ?></strong>
                        <?php if ($lineForeign === true): ?>
                            <small class="d-block text-muted" data-line-quoted>
                                <?php echo htmlspecialchars(\Portal\Core\Currency::formatMajor($item['quotedLineTotal'], $lineQuoted), ENT_QUOTES, 'UTF-8'); ?> quoted;
                                <?php echo htmlspecialchars(ExpenseRules::rateWords((float) $item['quotedLineTotal'], $lineQuoted, (float) $item['lineTotal'], $claimCurrency), ENT_QUOTES, 'UTF-8'); ?>
                            </small>
                        <?php endif; ?>
                    </div>
                    <div class="col-12 col-md-2">
                        <span class="d-md-none fw-semibold">Supplier: </span>
                        <?php echo htmlspecialchars($item['supplier'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="portal-data-row fw-bold">
                <div class="col-12 col-md-8 text-md-end">Total</div>
                <div class="col-12 col-md-2 text-md-end"><?php echo $claimMoney($claim['totalAmount']); ?></div>
                <div class="col-md-2"></div>
            </div>
        </div>
    </div>
</div>

<!-- 📎 Evidence Files -->
<?php if (count($files) > 0): ?>
<div class="card mb-4">
    <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-paperclip me-1"></i>Evidence &amp; Documents</h5></div>
    <div class="card-body">
        <div class="portal-data-list">
            <div class="portal-data-row portal-data-header d-none d-md-flex">
                <div class="col-md-5">Filename</div>
                <div class="col-md-2">Type</div>
                <div class="col-md-2">Size</div>
                <div class="col-md-3">Uploaded</div>
            </div>

            <?php foreach ($files as $f): ?>
                <div class="portal-data-row">
                    <div class="col-12 col-md-5">
                        <span class="d-md-none fw-semibold">File: </span>
                        <i class="fa-solid fa-file me-1"></i>
                        <?php
                        // 📥 A RECEIPT opens (#867): a row with no stage (NULL), which
                        //    only the submit handler writes. Anybody reading this line
                        //    already passed this page's own `mayViewClaim()` above, which
                        //    is the same rule the receipt address asks, so the link is
                        //    drawn for exactly the people it will open for. A row WITH a
                        //    stage is a version of the claim's generated PDF, which that
                        //    address deliberately does not serve, so it stays plain text.
                        //    EXACTLY `=== null`, the same test as the address's
                        //    `stage IS NULL`: an empty-text stage (which nothing writes)
                        //    would otherwise get a link the address answers with 404.
                        ?>
                        <?php if ($f['stage'] === null): ?>
                            <a href="<?php echo htmlspecialchars(Router::url('expenses/receipt') . '?claim=' . (int) $claim['claimID'] . '&id=' . (int) $f['fileID'], ENT_QUOTES, 'UTF-8'); ?>"
                               data-receipt-link="<?php echo (int) $f['fileID']; ?>"
                               title="Download this receipt"><?php echo htmlspecialchars((string) $f['fileLabel'], ENT_QUOTES, 'UTF-8'); ?></a>
                        <?php elseif ((int) $f['fileID'] === $pdfFileId): ?>
                            <?php
                            // 📄 #954: the NEWEST version of the generated PDF opens, through
                            //    `expenses/claim-pdf`, which asks the same rule as this page.
                            //    Only this row: every stage overwrites ONE file, so an older
                            //    stage's row describes a version that no longer exists, and a
                            //    link on it would hand out the newest bytes under the old name.
                            ?>
                            <a href="<?php echo htmlspecialchars(Router::url('expenses/claim-pdf') . '?claim=' . (int) $claim['claimID'], ENT_QUOTES, 'UTF-8'); ?>"
                               data-claim-pdf-link="<?php echo (int) $f['fileID']; ?>"
                               title="Download the claim's PDF"><?php echo htmlspecialchars((string) $f['fileLabel'], ENT_QUOTES, 'UTF-8'); ?></a>
                        <?php else: ?>
                            <?php echo htmlspecialchars((string) $f['fileLabel'], ENT_QUOTES, 'UTF-8'); ?>
                            <?php if ($f['stage'] !== '' && (string) $f['storedFilename'] === (string) $claim['fileName']): ?>
                                <small class="text-muted" data-claim-pdf-replaced="<?php echo (int) $f['fileID']; ?>">(replaced by a later version; earlier versions are not kept)</small>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($f['stage'] !== null && $f['stage'] !== ''): ?>
                            <span class="badge bg-secondary ms-1"><?php echo htmlspecialchars(ucfirst($f['stage']), ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="col-12 col-md-2">
                        <span class="d-md-none fw-semibold">Type: </span>
                        <small><?php echo htmlspecialchars($f['fileType'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></small>
                    </div>
                    <div class="col-12 col-md-2">
                        <span class="d-md-none fw-semibold">Size: </span>
                        <small><?php echo $f['fileSize'] !== null ? number_format((int) $f['fileSize'] / 1024, 1) . ' KB' : '—'; ?></small>
                    </div>
                    <div class="col-12 col-md-3">
                        <span class="d-md-none fw-semibold">Uploaded: </span>
                        <small><?php echo htmlspecialchars(\Portal\Core\I18n::formatDateTime($f['uploadedAt']), ENT_QUOTES, 'UTF-8'); ?></small>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ✅ Approval History -->
<div class="card mb-4">
    <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-check-double me-1"></i>Approval History</h5></div>
    <div class="card-body">
        <?php if (count($approvals) === 0): ?>
            <p class="text-muted mb-0">
                <?php echo $claim['status'] === 'Pending' ? 'Awaiting approval.' : 'No approval records.'; ?>
            </p>
        <?php else: ?>
            <div class="portal-data-list">
                <div class="portal-data-row portal-data-header d-none d-md-flex">
                    <div class="col-md-3">Approver</div>
                    <div class="col-md-2">Role</div>
                    <div class="col-md-2">Decision</div>
                    <div class="col-md-2">Date</div>
                    <div class="col-md-3">Comments</div>
                </div>

                <?php foreach ($approvals as $a): ?>
                    <?php
                    $decClass = $a['decision'] === 'Approved' ? 'success' : 'danger';
                    ?>
                    <div class="portal-data-row">
                        <div class="col-12 col-md-3">
                            <span class="d-md-none fw-semibold">Approver: </span>
                            <strong><?php echo htmlspecialchars((string) ($a['approverName'] ?? 'Approver\'s details removed'), ENT_QUOTES, 'UTF-8'); ?></strong>
                        </div>
                        <div class="col-12 col-md-2">
                            <span class="d-md-none fw-semibold">Role: </span>
                            <small><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $a['approverRole'] ?? 'approver')), ENT_QUOTES, 'UTF-8'); ?></small>
                        </div>
                        <div class="col-12 col-md-2">
                            <span class="d-md-none fw-semibold">Decision: </span>
                            <span class="badge bg-<?php echo $decClass; ?>"><?php echo htmlspecialchars($a['decision'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="col-12 col-md-2">
                            <span class="d-md-none fw-semibold">Date: </span>
                            <small><?php echo htmlspecialchars(\Portal\Core\I18n::formatDateTime($a['decidedAt']), ENT_QUOTES, 'UTF-8'); ?></small>
                        </div>
                        <div class="col-12 col-md-3">
                            <span class="d-md-none fw-semibold">Comments: </span>
                            <small><?php echo htmlspecialchars($a['comments'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- 💳 Payment Records -->
<?php if (count($payments) > 0): ?>
<div class="card mb-4">
    <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-credit-card me-1"></i>Payment Records</h5></div>
    <div class="card-body">
        <div class="portal-data-list">
            <div class="portal-data-row portal-data-header d-none d-md-flex">
                <div class="col-md-3">Reference</div>
                <div class="col-md-2">Method</div>
                <div class="col-md-2 text-end">Amount</div>
                <div class="col-md-2">Paid By</div>
                <div class="col-md-3">Date</div>
            </div>

            <?php foreach ($payments as $p): ?>
                <div class="portal-data-row">
                    <div class="col-12 col-md-3">
                        <span class="d-md-none fw-semibold">Ref: </span>
                        <strong><?php echo htmlspecialchars($p['payReference'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                    <div class="col-12 col-md-2">
                        <span class="d-md-none fw-semibold">Method: </span>
                        <?php echo htmlspecialchars($p['payMethod'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="col-12 col-md-2 text-md-end">
                        <span class="d-md-none fw-semibold">Amount: </span>
                        <?php echo $p['payAmount'] !== null ? $claimMoney($p['payAmount']) : '—'; ?>
                    </div>
                    <div class="col-12 col-md-2">
                        <span class="d-md-none fw-semibold">Paid By: </span>
                        <?php echo htmlspecialchars($p['paidByName'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="col-12 col-md-3">
                        <span class="d-md-none fw-semibold">Date: </span>
                        <small><?php echo htmlspecialchars(\Portal\Core\I18n::formatDateTime($p['addedAt']), ENT_QUOTES, 'UTF-8'); ?></small>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 📄 PDF Download -->
<?php if ($claim['fileName'] !== null && $claim['fileName'] !== ''): ?>
<div class="card mb-4">
    <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-file-pdf me-1"></i>Generated PDF</h5></div>
    <div class="card-body">
        <p class="mb-0">
            <i class="fa-solid fa-download me-1"></i>
            <?php if ($pdfFileId > 0): ?>
                <?php // #954: a link only when a stage row names this file — the same test the address makes. ?>
                <strong><a href="<?php echo htmlspecialchars(Router::url('expenses/claim-pdf') . '?claim=' . (int) $claim['claimID'], ENT_QUOTES, 'UTF-8'); ?>"
                           data-claim-pdf-card="1"><?php echo htmlspecialchars($claim['fileName'], ENT_QUOTES, 'UTF-8'); ?></a></strong>
            <?php else: ?>
                <strong><?php echo htmlspecialchars($claim['fileName'], ENT_QUOTES, 'UTF-8'); ?></strong>
            <?php endif; ?>
            <?php if ($pdfStage !== ''): ?>
                <small class="text-muted ms-2">(Last generated for status: <?php echo htmlspecialchars($pdfStage, ENT_QUOTES, 'UTF-8'); ?><?php echo $claim['status'] === 'Withdrawn' ? '. It was not remade when the claim was withdrawn.' : ''; ?>)</small>
            <?php endif; ?>
        </p>
    </div>
</div>
<?php endif; ?>

<?php
// #545: a claimant who is also an approver or an administrator used to get a
// "Go to Approvals" button here. The button is no longer offered on their
// own claim, and without a word in its place the page said nothing about who
// decides it — which matters most to somebody who is their department's
// only approver (found by the independent check of 11 October 2026). The
// sentence is the same one the refusal uses (ExpenseRules::WHO_DECIDES). It
// is shown only to a claimant who holds that authority; an ordinary claimant
// never had the button and is shown nothing new.
?>
<?php if ($claim['status'] === 'Pending' && $isClaimant === true && $mayDecide === true): ?>
    <p class="small text-secondary" id="own-claim-note"><?php echo htmlspecialchars(ExpenseRules::MSG_OWN_CLAIM_PAGE, ENT_QUOTES, 'UTF-8'); ?></p>
<?php endif; ?>
<?php
// The same for payment (12 October 2026). Once the claim is APPROVED, a
// claimant who is a treasurer or an administrator is not offered "Go to
// Treasury" on it (see the buttons below), and used to be told nothing in its
// place. The test is the button's own test with "is the claimant" turned
// round, so the note appears exactly where the button is withheld. An
// ordinary claimant never had the button and is shown nothing new.
?>
<?php if ($claim['status'] === 'Approved' && $isClaimant === true && ($hasTreasurer === true || $isAdmin === true)): ?>
    <p class="small text-secondary" id="own-payment-note"><?php echo htmlspecialchars(ExpenseRules::MSG_OWN_PAYMENT_PAGE, ENT_QUOTES, 'UTF-8'); ?></p>
<?php endif; ?>

<!-- 🔗 Action Buttons -->
<div class="d-flex gap-2 mb-4">
    <a href="<?php echo htmlspecialchars(Router::url('expenses/submit'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">
        <?php // #834: "your claims" only when it is the viewer's own claim; an approver or treasurer reading somebody else's claim is going back to the Expenses page, where it is not listed. ?>
        <i class="fa-solid fa-arrow-left me-1"></i> <?php echo $isClaimant === true ? 'Back to your claims' : 'Back to Expense Claims'; ?>
    </a>
    <?php
    // #545: neither button is offered on a person's OWN claim. Nobody
    // decides or pays their own claim, the two lists leave it out, and both
    // handlers refuse it, so the button would lead to a page where the
    // claim is not listed. $isClaimant is worked out at the top of this
    // page, from the same account number ExpenseRules::isOwnClaim() compares.
    ?>
    <?php if ($claim['status'] === 'Pending' && $isClaimant === false && $mayDecide === true): ?>
        <a href="<?php echo htmlspecialchars(Router::url('expenses/approve'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-primary">
            <i class="fa-solid fa-check me-1"></i> Go to Approvals
        </a>
    <?php endif; ?>
    <?php if ($claim['status'] === 'Approved' && $isClaimant === false && ($hasTreasurer === true || $isAdmin === true)): ?>
        <a href="<?php echo htmlspecialchars(Router::url('expenses/treasury'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-success">
            <i class="fa-solid fa-money-bill-wave me-1"></i> Go to Treasury
        </a>
    <?php endif; ?>
    <?php if ($mayEditClaim === true): ?>
        <!-- ✏️ Change the claim (#651) — the claimant only, and only while nobody
             has decided on it. A claim that is still Pending but already has an
             approval against it deliberately shows NO button: see
             ExpenseRules::whyNotEditable() for why the status alone is not enough. -->
        <a href="<?php echo htmlspecialchars(Router::url('expenses/edit') . '?id=' . (int) $claim['claimID'], ENT_QUOTES, 'UTF-8'); ?>"
           class="btn btn-outline-primary">
            <i class="fa-solid fa-pen me-1"></i> Change Claim
        </a>
    <?php endif; ?>
    <?php if ($claim['status'] === 'Pending' && $isClaimant === true): ?>
        <!-- 🔄 Withdraw claim form — only visible to claimant for Pending claims -->
        <form method="POST" action="<?php echo htmlspecialchars(Router::url('expenses/withdraw/save'), ENT_QUOTES, 'UTF-8'); ?>" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="claimID" value="<?php echo (int) $claim['claimID']; ?>">
            <button type="submit" class="btn btn-outline-danger"
                    data-confirm="Are you sure you want to withdraw this claim? This action cannot be undone." data-confirm-destructive="true">
                <i class="fa-solid fa-rotate-left me-1"></i> Withdraw Claim
            </button>
        </form>
    <?php endif; ?>
</div>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
