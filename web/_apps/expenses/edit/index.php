<?php
// Path: apps/expenses/edit/index.php
/**
 * -----------------------------------------------------------------------------
 * Expenses — Change a Claim Form ✏️
 * -----------------------------------------------------------------------------
 * The form the person who made a claim uses to correct it, pre-filled with
 * what the claim says now.
 *
 * It asks `Portal\Core\ExpenseRules::whyNotEditable()` — the ONE place that
 * decides — so this page can never offer an edit that `edit/save.php` would
 * refuse. Where it refuses, it says why in the same words the handler uses,
 * because both read them from `ExpenseRules::editRefusalWords()`.
 *
 * WHY THERE IS NO JAVASCRIPT ON THIS PAGE. The submit form grows a new line
 * with an "+ Add item" button, which needs a script, and every inline script
 * in this portal has to carry the answer's own nonce or the browser silently
 * refuses it — the page then looks finished while the button does nothing
 * (#625). This page sidesteps that entirely: it renders every line the claim
 * already has, plus a few empty ones, all from the server. A line is removed
 * by clearing its description, because the handler keeps only lines that have
 * one. That is less clever than the submit form and it cannot break in the
 * way the submit form can.
 *
 * Receipts are deliberately not shown or changed here; see the note in
 * `edit/save.php` and #649.
 *
 * @package   Portal\Expenses
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/651
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\ExpenseRules;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

// 📌 Page metadata for the template system
$pageTitle   = 'Change Expense Claim';
$pageSection = 'expenses';

// 📋 The one-off message another page left, read and cleared exactly as the
//    submit and approvals pages do.
$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$userId = (int) ($_SESSION['user_id'] ?? 0);
$siteId = Site::id();

// 🔢 A single value or nothing — the same guard the four money handlers use
//    (#642). `(int) ['x']` is 1 in PHP, silently.
$idRaw   = $_GET['id'] ?? 0;
$claimID = is_scalar($idRaw) === true ? (int) $idRaw : 0;

if ($claimID <= 0) {
    Router::renderError(404);
    return;
}

// -----------------------------------------------------------------------------
// 📋 The claim, and whether this person may change it
// -----------------------------------------------------------------------------
$claim = null;
$stmt  = $mysqli->prepare(
    'SELECT claimID, userID, deptID, claimTitle, totalAmount, status '
    . 'FROM tblExpenseClaims WHERE claimID = ? AND siteID = ? LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $claimID, $siteId);
    $stmt->execute();
    $claim = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// 🙈 A claim that is not there, or belongs to another organisation, is "not
//    found". Never a page that confirms it exists.
if ($claim === null) {
    Router::renderError(404);
    return;
}

$reason = ExpenseRules::whyNotEditable(
    (string) $claim['status'],
    ExpenseRules::approvalCount($mysqli, $claimID),
    (int) $claim['userID'],
    $userId
);

// 🙅 Somebody else's claim is refused WITHOUT a reason. Telling a stranger
//    "you cannot edit this because it has been approved" would confirm the
//    claim is real AND say where it has got to; a bare 403 says neither.
//
//    SAID ACCURATELY, because an earlier version of this comment claimed the
//    answer was "the same as a claim that does not exist" and an independent
//    check on 28 October 2026 pointed out that it is not: a missing claim, or
//    one belonging to another organisation, gets 404 a few lines above, and
//    this gets 403. So the two ARE distinguishable, and somebody probing can
//    learn that a claim exists.
//
//    That is deliberate and it is not a new disclosure: `view/index.php`
//    already answers 403 and 404 in exactly the same pattern, so anybody who
//    can reach this page can learn the same thing from the claim page. Making
//    this one answer 404 would only make it disagree with the rest of the
//    app. What is NOT disclosed either way is anything about the claim
//    itself — who made it, what it is for, how much, or whether it has been
//    decided.
if ($reason === ExpenseRules::EDIT_NOT_YOURS) {
    Router::renderError(403);
    return;
}

$editable = ($reason === ExpenseRules::EDIT_ALLOWED);

// -----------------------------------------------------------------------------
// 📦 The lines it holds now
// -----------------------------------------------------------------------------
$items = [];
$stmt  = $mysqli->prepare(
    'SELECT itemName, quantity, unitCost FROM tblExpenseClaimItems WHERE claimID = ? ORDER BY itemID'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $claimID);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();
}

// ➕ A few empty lines so something can be added without a script. Five is a
//    guess at "enough for a correction"; the person can save and come back
//    for more, which is why this is not a number worth agonising over.
const BLANK_LINES = 5;

// 🏢 The live departments of this organisation, for the picker.
$depts = [];
$stmt  = $mysqli->prepare('SELECT deptID, deptName FROM tblDepts WHERE isActive = 1 AND siteID = ? ORDER BY deptName');
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($d = $res->fetch_assoc()) {
        $depts[] = $d;
    }
    $stmt->close();
}

// ⚠️ A claim whose department has since been retired keeps it, but the picker
//    only lists live ones — so say so rather than silently showing a different
//    department as if it were the claim's own.
$currentDeptListed = false;
foreach ($depts as $d) {
    if ((int) $d['deptID'] === (int) $claim['deptID']) {
        $currentDeptListed = true;
        break;
    }
}

$breadcrumbs = [
    'Dashboard'                 => Router::url(''),
    'Expenses'                  => Router::url('expenses/submit'),
    'Claim #' . $claimID        => Router::url('expenses/view') . '?id=' . $claimID,
    'Change'                    => '',
];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<h1 class="mb-4"><i class="fa-solid fa-pen me-2"></i>Change Claim #<?php echo $claimID; ?></h1>

<?php if ($editable === false): ?>

    <!-- 🙅 The same sentence the save handler would give, from the same place. -->
    <div class="alert alert-warning">
        <?php echo htmlspecialchars(ExpenseRules::editRefusalWords($reason), ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <a class="btn btn-secondary" href="<?php echo htmlspecialchars(Router::url('expenses/view') . '?id=' . $claimID, ENT_QUOTES, 'UTF-8'); ?>">
        Back to the claim
    </a>

<?php else: ?>

    <p class="text-body-secondary">
        You can change this claim because nobody has decided on it yet. Once an approver has approved or
        rejected it, it can no longer be changed — you would need to withdraw it and submit a new one.
    </p>

    <form method="post" action="<?php echo htmlspecialchars(Router::url('expenses/edit/save'), ENT_QUOTES, 'UTF-8'); ?>" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="claimID" value="<?php echo $claimID; ?>">

        <div class="mb-3">
            <label class="form-label" for="claimTitle">What the claim is for</label>
            <input type="text" class="form-control" id="claimTitle" name="claimTitle" required
                   value="<?php echo htmlspecialchars((string) $claim['claimTitle'], ENT_QUOTES, 'UTF-8'); ?>">
        </div>

        <div class="mb-3">
            <label class="form-label" for="deptID">Department</label>
            <select class="form-select" id="deptID" name="deptID" required>
                <?php if ($currentDeptListed === false): ?>
                    <option value="">Choose a department</option>
                <?php endif; ?>
                <?php foreach ($depts as $d): ?>
                    <option value="<?php echo (int) $d['deptID']; ?>"
                        <?php echo ((int) $d['deptID'] === (int) $claim['deptID']) ? ' selected' : ''; ?>>
                        <?php echo htmlspecialchars((string) $d['deptName'], ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($currentDeptListed === false): ?>
                <div class="form-text text-warning">
                    This claim&rsquo;s department has been retired, so it is not in the list. Choose a
                    department before saving.
                </div>
            <?php endif; ?>
        </div>

        <!-- 📦 The lines. No <table> tags: the house component and Bootstrap's
             grid are used instead, as on the submit form. -->
        <h2 class="h5 mt-4">What it is made up of</h2>
        <p class="text-body-secondary small">
            To remove a line, clear its description. Lines with no description are not saved.
        </p>

        <?php
        $rowIdx = 0;
        $rows   = $items;
        for ($b = 0; $b < BLANK_LINES; $b++) {
            $rows[] = ['itemName' => '', 'quantity' => 1, 'unitCost' => '0.00'];
        }
        foreach ($rows as $row):
            $rowIdx++;
        ?>
            <div class="row g-2 align-items-end mb-2">
                <div class="col-12 col-md-6">
                    <label class="form-label small d-md-none" for="itemDesc<?php echo $rowIdx; ?>">Description</label>
                    <input type="text" id="itemDesc<?php echo $rowIdx; ?>" name="itemDesc[]" class="form-control"
                           aria-label="Description for line <?php echo $rowIdx; ?>"
                           placeholder="Line <?php echo $rowIdx; ?>"
                           value="<?php echo htmlspecialchars((string) $row['itemName'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small d-md-none" for="itemQty<?php echo $rowIdx; ?>">Quantity</label>
                    <input type="number" id="itemQty<?php echo $rowIdx; ?>" name="itemQty[]" min="1" step="1"
                           class="form-control text-end"
                           aria-label="Quantity for line <?php echo $rowIdx; ?>"
                           value="<?php echo (int) ($row['quantity'] ?? 1); ?>">
                </div>
                <div class="col-6 col-md-4">
                    <label class="form-label small d-md-none" for="itemUnit<?php echo $rowIdx; ?>">Unit price</label>
                    <input type="number" id="itemUnit<?php echo $rowIdx; ?>" name="itemUnit[]" min="0" step="0.01"
                           class="form-control text-end"
                           aria-label="Unit price for line <?php echo $rowIdx; ?>"
                           value="<?php echo htmlspecialchars(number_format((float) ($row['unitCost'] ?? 0), 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>
        <?php endforeach; ?>

        <!-- 💷 Shown for information only. The total is worked out again by the
             handler from the lines above, and a posted total is never read. -->
        <p class="mt-3">
            <strong>Total now:</strong>
            &pound;<?php echo htmlspecialchars(number_format((float) ($claim['totalAmount'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?>
            <span class="text-body-secondary small">
                &mdash; this is worked out again from the lines when you save.
            </span>
        </p>

        <button type="submit" class="btn btn-primary">Save changes</button>
        <a class="btn btn-link" href="<?php echo htmlspecialchars(Router::url('expenses/view') . '?id=' . $claimID, ENT_QUOTES, 'UTF-8'); ?>">
            Cancel
        </a>
    </form>

<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
