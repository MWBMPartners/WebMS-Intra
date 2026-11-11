<?php
// Path: apps/expenses/submit/index.php
/**
 * -----------------------------------------------------------------------------
 * Expenses -- Claim Submission Form (Responsive) 💸
 * -----------------------------------------------------------------------------
 * Reworked to drop <table> tags in favour of Bootstrap's flexbox/grid layout so
 * the item list adapts elegantly on phones, tablets, and desktops.  Column
 * labels collapse into inline placeholders on narrow viewports for optimal UX.
 *
 * It also shows the one-off message another Expenses page left for this
 * person (#587). The withdraw page sends people here with "Claim not found",
 * and this page's own save handler sends them back here with every one of its
 * refusals ("Total must be greater than zero", for one). Until 12 October
 * 2026 this page showed none of them: the form simply came back empty, and
 * the words turned up later on some other page.
 *
 * YOUR CLAIMS (#834, 7 November 2026)
 * -----------------------------------
 * Above the form, this page lists the signed-in person's OWN claims in the
 * organisation they are in, newest first, ten to a page, each linking to the
 * claim's own page. Until then nothing anywhere listed a member's claims:
 * this page was a form only, the dashboard's "My Pending Claims" card and the
 * Apps menu both led here, the help told people to find their claims here,
 * and `ExpenseMailer::notify()` mails the approvers, not the claimant, when a
 * claim is submitted. So withdrawing or changing a pending claim — both
 * offered only on the claim's own page — could not be reached by an ordinary
 * member through any page at all.
 *
 * WHY ABOVE THE FORM: the dashboard card that brings people here is a count
 * of their claims waiting for a decision, and the help sends people here to
 * look one up. Below a form of unknown length the list would be off screen
 * for exactly the people sent to find it. Ten to a page keeps it short for
 * somebody who only wants the form.
 *
 * WHY ONLY A LINK, AND NO WITHDRAW OR CHANGE BUTTON IN THE LIST: whether a
 * claim may still be changed is decided by `ExpenseRules::whyNotEditable()`,
 * which needs the claim's approval count, and the claim's own page already
 * asks it and offers both buttons. Asking it here too would be a second place
 * drawing those buttons (#651, #647) and one more query per row. So the list
 * shows the status and leaves the decision to the page that already makes it.
 *
 * WHAT IT CANNOT DO: it lists only this organisation's claims, on purpose. A
 * member of two organisations sees each one's claims on that organisation's
 * own address, and nowhere a list of both.
 *
 * A REFUSED CLAIM COMES BACK FILLED IN (#870, 9 November 2026). The save
 * handler keeps the title, department and item lines of a refused claim, and
 * this page fills the form in from them once. The receipts cannot be kept —
 * a page cannot choose files for somebody — and a note above the files box
 * says so. With JavaScript off the five plain item rows are filled in (more
 * when more were typed); with it on the rows are built by the script below,
 * which sets each value as a value, never as markup.
 *
 * THE FILE CHOOSER OFFERS WHAT THE RULE ACCEPTS (#871): its `accept` list is
 * built from `ExpenseRules::RECEIPT_TYPES`, never written here by hand.
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Captcha;
use Portal\Core\ExpenseRules;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

// 📌 Page metadata for the template system
$pageTitle   = 'Expense Claims';
$pageSection = 'expenses';
// The page now holds the person's claims AND the form, so it is named after
// the app (the name the Apps menu gives it), not after the form alone (#834).
$breadcrumbs = ['Dashboard' => Router::url(''), 'Expense Claims' => ''];

// 📋 The one-off message another page left for this person, read and cleared
//    exactly as the approvals page does (approve/index.php).
$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$siteId = Site::id();

// ✍️ WHAT WAS TYPED INTO A REFUSED CLAIM (#870), read and cleared exactly as
//    the one-off message is, so it fills the form in ONCE and never again.
//    The save handler keeps it on every refusal after the form token was
//    accepted; `ExpenseRules` says which refusals keep nothing, and why. It
//    never holds the receipts — a page cannot choose files for somebody —
//    and the form says so when it has been filled in again. It is given
//    back ONLY to the account it was kept for: the session belongs to the
//    browser, and a second person can sign in on it while it waits (see
//    `ExpenseRules::claimDraftFrom()`). Kept typing for anybody else is
//    cleared here unseen, never shown.
$draft = ExpenseRules::draftForForm($_SESSION[ExpenseRules::DRAFT_SESSION_KEY] ?? null, $siteId, (int) ($_SESSION['user_id'] ?? 0));
unset($_SESSION[ExpenseRules::DRAFT_SESSION_KEY]);
$draftTitle = $draft['claimTitle'] ?? '';
$draftDept  = $draft['deptID'] ?? 0;
$draftItems = $draft['items'] ?? [];

// 📎 What the file chooser offers, built from the ONE list of receipt kinds
//    (#871). It used to say "image/*,application/pdf", which hid the Word,
//    Excel and CSV files the rule accepts and the help page names.
$receiptAccept = ExpenseRules::receiptAcceptList();

/* -------------------------------------------------------------------------- */
/* 🏢 Active departments                                                      */
/* -------------------------------------------------------------------------- */
$depts = [];
$stmt = $mysqli->prepare('SELECT deptID, deptName FROM tblDepts WHERE isActive = 1 AND siteID = ? ORDER BY deptName');
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($d = $res->fetch_assoc()) {
        $depts[] = $d;
    }
    $stmt->close();
}

/* -------------------------------------------------------------------------- */
/* 🧾 Your claims (#834)                                                      */
/* -------------------------------------------------------------------------- */
// The signed-in person's OWN claims in THIS organisation. Both conditions are
// load-bearing and each is pinned by its own check in part P of
// tools/access-pages-selftest.php: without `userID` a member would see
// everybody's claims, with names of what they were for and how much; without
// `siteID` a member of two organisations would see the other one's claims on
// this one's address. The index `idx_claims_site_user_created` is exactly
// (siteID, userID, createdAt), so this costs one index range read.
//
// Newest first by when the claim was SUBMITTED (`createdAt`, the date the
// claim's own page shows as "Submitted"), and by claim number after that, so
// two claims made in the same second always come out in the same order.
$ownPerPage     = 10;
$ownUserId      = (int) ($_SESSION['user_id'] ?? 0);
$ownClaims      = [];
$ownClaimsTotal = 0;
// "Could not be read" is its own answer, never "you have no claims": telling
// somebody they have no claims when the list simply failed would send them to
// submit the same claim twice.
$ownClaimsUnreadable = false;
$ownPage             = 1;
$ownPages            = 1;
// A page number arrives from the address, so it is only ever an integer of 1
// or more; anything else (a list, text, nothing) is page 1. Do NOT shorten
// this to `(int) $_GET['page']`: 0, -1, "abc" and an empty value would then
// give a negative OFFSET, which the database refuses, so the list would say
// "could not be read" and write an error row. Check P11 of
// tools/access-pages-selftest.php sends exactly those four.
$pageAsked = $_GET['page'] ?? '1';
if (is_string($pageAsked) === true && preg_match('/^[1-9][0-9]{0,5}$/', $pageAsked) === 1) {
    $ownPage = (int) $pageAsked;
}
if ($ownUserId > 0) {
    try {
        $count = $mysqli->prepare('SELECT COUNT(*) AS n FROM tblExpenseClaims WHERE siteID = ? AND userID = ?');
        $count->bind_param('ii', $siteId, $ownUserId);
        $count->execute();
        $ownClaimsTotal = (int) ($count->get_result()->fetch_assoc()['n'] ?? 0);
        $count->close();

        // A page past the end shows the last page, not an empty list that
        // reads as "you have no claims".
        $ownPages = max(1, (int) ceil($ownClaimsTotal / $ownPerPage));
        $ownPage  = min($ownPage, $ownPages);
        $limit    = $ownPerPage;
        $offset   = ($ownPage - 1) * $ownPerPage;

        $list = $mysqli->prepare(
            'SELECT EC.claimID, EC.claimTitle, EC.totalAmount, EC.status, EC.createdAt, D.deptName '
            . 'FROM tblExpenseClaims EC '
            . 'LEFT JOIN tblDepts D ON D.deptID = EC.deptID '
            . 'WHERE EC.siteID = ? AND EC.userID = ? '
            . 'ORDER BY EC.createdAt DESC, EC.claimID DESC '
            . 'LIMIT ? OFFSET ?'
        );
        $list->bind_param('iiii', $siteId, $ownUserId, $limit, $offset);
        $list->execute();
        $res = $list->get_result();
        while ($row = $res->fetch_assoc()) {
            $ownClaims[] = $row;
        }
        $list->close();
    } catch (\Throwable $e) {
        // Recorded for the administrator, and said plainly on the page; the
        // form below still works, so nobody is stopped from claiming.
        \Portal\Core\Logger::exception($e);
        $ownClaims           = [];
        $ownClaimsUnreadable = true;
    }
}

/**
 * The badge colour for a claim's status: the SAME colours the claim's own
 * page uses (expenses/view/index.php), so a claim looks the same in the list
 * and when opened. Change both together.
 */
$ownStatusClass = static fn (string $status): string => match ($status) {
    'Pending'    => 'warning',
    'Approved'   => 'info',
    'Rejected'   => 'danger',
    'Reimbursed' => 'success',
    'Withdrawn'  => 'secondary',
    default      => 'secondary',
};

// 📝 D-130 (the owner, 11 November 2026): every look at expense records is
//    recorded, a claimant's own included. The list above shows this person's
//    claims (titles, amounts, departments), so when it shows any it writes one
//    `ExpenseOwnClaimsView` record naming the person (#626), with the page and
//    HOW MANY claims it showed — no title or amount. Somebody with no claims
//    (or whose list could not be read) was shown none and records nothing, so
//    opening the form to make a first claim leaves no record.
if (count($ownClaims) > 0) {
    Logger::activity('ExpenseOwnClaimsView', 'Opened their own expense claims list: page ' . $ownPage . ', ' . count($ownClaims) . ' claim(s) shown');
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

<h1 class="mb-4">Expense Claims</h1>

<!-- 🧾 Your claims (#834): this person's own claims in this organisation -->
<section class="mb-5" id="your-claims" aria-labelledby="your-claims-heading">
    <h2 class="h4 mb-3" id="your-claims-heading">Your claims</h2>
    <?php if ($ownClaimsUnreadable === true): ?>
        <p class="text-danger" id="your-claims-unreadable">Your claims could not be read just now. Please try again in a moment. The form below still works.</p>
    <?php elseif ($ownClaimsTotal === 0): ?>
        <p class="text-muted" id="your-claims-none">You have not made any expense claims here yet. When you submit one with the form below, it will be listed here.</p>
    <?php else: ?>
        <p class="small text-secondary mb-2">
            Open a claim to see its receipts, approvals and any comments. From a claim's own page you can change it while nobody has decided on it, and withdraw it while it is still pending.
        </p>
        <div class="portal-data-list" id="your-claims-list">
            <div class="portal-data-row portal-data-header d-none d-md-flex">
                <div class="col-md-4">Claim</div>
                <div class="col-md-2">Department</div>
                <div class="col-md-2">Submitted</div>
                <div class="col-md-2 text-md-end">Total</div>
                <div class="col-md-2 text-md-end">Status</div>
            </div>
            <?php foreach ($ownClaims as $own): ?>
                <div class="portal-data-row" data-claim-id="<?php echo (int) $own['claimID']; ?>">
                    <div class="col-12 col-md-4">
                        <a href="<?php echo htmlspecialchars(Router::url('expenses/view') . '?id=' . (int) $own['claimID'], ENT_QUOTES, 'UTF-8'); ?>">
                            #<?php echo (int) $own['claimID']; ?> &mdash; <?php echo htmlspecialchars((string) ($own['claimTitle'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    </div>
                    <div class="col-12 col-md-2">
                        <span class="d-md-none fw-semibold">Department: </span>
                        <?php echo htmlspecialchars((string) ($own['deptName'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="col-12 col-md-2">
                        <span class="d-md-none fw-semibold">Submitted: </span>
                        <?php echo htmlspecialchars(\Portal\Core\I18n::formatDate((string) ($own['createdAt'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="col-12 col-md-2 text-md-end">
                        <span class="d-md-none fw-semibold">Total: </span>
                        <?php echo htmlspecialchars(\Portal\Core\Currency::formatMajor((float) $own['totalAmount'], \Portal\Core\Currency::ofSite()), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="col-12 col-md-2 text-md-end">
                        <span class="badge bg-<?php echo $ownStatusClass((string) $own['status']); ?>"><?php echo htmlspecialchars((string) $own['status'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($ownPages > 1): ?>
            <nav class="d-flex justify-content-between align-items-center mt-2" aria-label="Pages of your claims">
                <span>
                    <?php if ($ownPage > 1): ?>
                        <a href="<?php echo htmlspecialchars(Router::url('expenses/submit') . '?page=' . ($ownPage - 1), ENT_QUOTES, 'UTF-8'); ?>">&laquo; Newer claims</a>
                    <?php endif; ?>
                </span>
                <small class="text-secondary">Page <?php echo $ownPage; ?> of <?php echo $ownPages; ?> (<?php echo $ownClaimsTotal; ?> claims)</small>
                <span>
                    <?php if ($ownPage < $ownPages): ?>
                        <a href="<?php echo htmlspecialchars(Router::url('expenses/submit') . '?page=' . ($ownPage + 1), ENT_QUOTES, 'UTF-8'); ?>">Older claims &raquo;</a>
                    <?php endif; ?>
                </span>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>

<!-- 💸 Expense Claim Submission Form -->
<h2 class="h4 mb-3" id="submit-a-claim">Submit a new claim</h2>

<form method="post" action="<?php echo htmlspecialchars(Router::url('expenses/submit/save'), ENT_QUOTES, 'UTF-8'); ?>" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">

    <?php if ($draft !== null): ?>
        <!-- ✍️ #870: the form was filled in again from a refused claim. -->
        <div class="alert alert-info" id="claim-draft-restored">
            <i class="fa-solid fa-circle-info me-1"></i>What you typed has been filled in again below. Any receipts you chose were not kept, so please choose them again before you submit.
        </div>
    <?php endif; ?>

    <div class="mb-3">
        <label class="form-label">Claim Title</label>
        <input type="text" class="form-control" name="claimTitle" value="<?php echo htmlspecialchars($draftTitle, ENT_QUOTES, 'UTF-8'); ?>" required>
    </div>

    <div class="mb-3">
        <label class="form-label">Department</label>
        <?php if (count($depts) === 0): ?>
            <!-- 🏢 #517: with no departments there is nothing a claim can be
                 charged to, and the save handler would refuse every claim.
                 This used to show an empty list with no explanation; now it
                 says why, and the Submit button below is switched off. -->
            <div class="alert alert-info mb-0">
                <i class="fa-solid fa-circle-info me-1"></i>No departments have been set up for this organisation yet.
                An administrator adds them at Admin &rarr; Departments.
            </div>
        <?php else: ?>
        <?php
        // The kept department is chosen again only if it is still one of
        // this organisation's live departments; otherwise the prompt is, so
        // exactly ONE option is ever `selected`.
        $draftDeptListed = in_array($draftDept, array_map(static fn (array $d): int => (int) $d['deptID'], $depts), true);
        ?>
        <select class="form-select" name="deptID" required>
            <option value="" disabled<?php echo $draftDeptListed === true ? '' : ' selected'; ?>>Select department...</option>
            <?php foreach ($depts as $dept): ?>
                <option value="<?php echo (int) $dept['deptID']; ?>"<?php echo ($draftDeptListed === true && (int) $dept['deptID'] === $draftDept) ? ' selected' : ''; ?>><?php echo htmlspecialchars($dept['deptName'], ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
    </div>

    <h3 class="h5 mt-4">Items</h3>

    <!-- 📊 Column headings for >= md screens -->
    <div class="d-none d-md-flex fw-semibold border-bottom py-1 mb-2 small text-secondary">
        <div class="col-md-5">Description</div>
        <div class="col-md-2 text-end">Qty</div>
        <div class="col-md-2 text-end">Unit <?php echo htmlspecialchars(\Portal\Core\Currency::label(\Portal\Core\Currency::ofSite()), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="col-md-2 text-end">Line <?php echo htmlspecialchars(\Portal\Core\Currency::label(\Portal\Core\Currency::ofSite()), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="col-md-1"></div>
    </div>

    <div id="items-list">
        <!-- ⚠️ Static fallback rows for no-JS environments -->
        <noscript>
            <?php
            // Five plain rows, or as many as a refused claim had (#870), each
            // filled in from what was typed where there is something.
            $noScriptRows = max(5, count($draftItems));
            ?>
            <?php for ($rowIdx = 1; $rowIdx <= $noScriptRows; $rowIdx++): ?>
            <?php $kept = $draftItems[$rowIdx - 1] ?? ['desc' => '', 'qty' => '1', 'unit' => '0.00']; ?>
            <div class="row g-2 align-items-end mb-2">
                <div class="col-12 col-md-5">
                    <label class="form-label d-md-none">Description</label>
                    <input type="text" name="itemDesc[]" class="form-control" aria-label="Description" placeholder="Item <?php echo $rowIdx; ?>" value="<?php echo htmlspecialchars($kept['desc'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-4 col-md-2">
                    <label class="form-label d-md-none">Qty</label>
                    <input type="number" name="itemQty[]" class="form-control text-end" aria-label="Quantity" min="1" value="<?php echo htmlspecialchars($kept['qty'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-4 col-md-2">
                    <label class="form-label d-md-none">Unit <?php echo htmlspecialchars(\Portal\Core\Currency::label(\Portal\Core\Currency::ofSite()), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input type="number" step="0.01" name="itemUnit[]" class="form-control text-end" aria-label="Unit price" value="<?php echo htmlspecialchars($kept['unit'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-4 col-md-3"></div>
            </div>
            <?php endfor; ?>
            <p class="text-muted small"><i class="fa-solid fa-circle-info me-1"></i>JavaScript is disabled — 5 line items are shown. Blank rows will be ignored. Line totals will be calculated on the server.</p>
        </noscript>
    </div>

    <button type="button" id="addRow" class="btn btn-outline-secondary btn-sm mb-3">+ Add item</button>

    <div class="mb-3 text-end">
        <strong>Total: <?php echo htmlspecialchars(\Portal\Core\Currency::prefix(\Portal\Core\Currency::ofSite()), ENT_QUOTES, 'UTF-8'); ?><span id="grandTotal">0.00</span></strong>
        <input type="hidden" name="totalAmount" id="totalAmount">
    </div>

    <h3 class="h5 mt-4">Supporting Files</h3>
    <div class="mb-3">
        <div class="dropzone" id="filesDropzone" role="button" tabindex="0">Drop files here or click to upload</div>
        <input type="file" name="files[]" id="files" class="form-control d-none" multiple accept="<?php echo htmlspecialchars($receiptAccept, ENT_QUOTES, 'UTF-8'); ?>">
        <noscript>
            <input type="file" name="files[]" class="form-control mt-2" multiple accept="<?php echo htmlspecialchars($receiptAccept, ENT_QUOTES, 'UTF-8'); ?>">
        </noscript>
    </div>

    <!-- 🤖 Captcha widget (if configured) -->
    <?php echo Captcha::widget(); ?>

    <button type="submit" class="btn btn-primary"<?php echo count($depts) === 0 ? ' disabled' : ''; ?>>Submit Claim</button>
</form>

<style>
    .dropzone { border: 2px dashed #6c757d; padding: 1rem; text-align: center; border-radius: .5rem; cursor: pointer; }
    .item-row .form-label { font-size: .75rem; font-weight: 600; }
</style>

<!-- 🤖 Captcha script (if configured) -->
<?php echo Captcha::scriptTag(); ?>

<!-- 📦 Inline items JavaScript -->
<script nonce="<?php echo htmlspecialchars(\Portal\Core\App::cspNonce(), ENT_QUOTES, 'UTF-8'); ?>">
(function(){
    const list = document.getElementById('items-list');
    const grandTotal = document.getElementById('grandTotal');
    const totalInput = document.getElementById('totalAmount');

    function itemTemplate(disabledRemove){
        return `<div class="item-row row g-2 align-items-end mb-2">
            <div class="col-12 col-md-5">
                <label class="form-label d-md-none">Description</label>
                <input type="text" name="itemDesc[]" class="form-control" aria-label="Description" required>
            </div>
            <div class="col-4 col-md-2">
                <label class="form-label d-md-none">Qty</label>
                <input type="number" name="itemQty[]" class="form-control text-end" aria-label="Quantity" min="1" value="1" required>
            </div>
            <div class="col-4 col-md-2">
                <label class="form-label d-md-none">Unit \u00a3</label>
                <input type="number" step="0.01" name="itemUnit[]" class="form-control text-end" aria-label="Unit price" required>
            </div>
            <div class="col-4 col-md-2 text-end">
                <label class="form-label d-md-none">Line \u00a3</label>
                <span class="d-block pt-2 lineTotal">0.00</span>
            </div>
            <div class="col-12 col-md-1 text-end">
                <button type="button" class="btn btn-outline-danger btn-sm removeRow" ${disabledRemove?'disabled':''}>&times;</button>
            </div>
        </div>`;
    }

    function recalc(){
        let sum = 0;
        list.querySelectorAll('.item-row').forEach(row => {
            const qty  = parseFloat(row.querySelector('[name="itemQty[]"]').value) || 0;
            const unit = parseFloat(row.querySelector('[name="itemUnit[]"]').value) || 0;
            const line = qty * unit;
            row.querySelector('.lineTotal').textContent = line.toFixed(2);
            sum += line;
        });
        grandTotal.textContent = sum.toFixed(2);
        totalInput.value = sum.toFixed(2);
    }

    // ✍️ The item lines of a refused claim, kept by the save handler (#870).
    //    Written into the page as JSON with every character that could end a
    //    script or start markup escaped, and set on each box as a VALUE —
    //    never pasted into the markup, where a description that holds markup
    //    of its own would become part of the page. (No example is written
    //    here on purpose: tools/page-scripts-selftest.php reads the page as
    //    served, script text included, and an example tag with a handler in
    //    this comment reads to it as a real handler on a real element.)
    const keptItems = <?php echo json_encode($draftItems, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE); ?>;

    // 🏁 The first row, then one more for each further kept line. The first
    //    row's remove button stays switched off, as it always was.
    const rowsToDraw = Math.max(1, keptItems.length);
    for (let i = 0; i < rowsToDraw; i++) {
        list.insertAdjacentHTML('beforeend', itemTemplate(i === 0));
        const kept = keptItems[i];
        if (kept) {
            const row = list.lastElementChild;
            row.querySelector('[name="itemDesc[]"]').value = kept.desc;
            row.querySelector('[name="itemQty[]"]').value  = kept.qty;
            row.querySelector('[name="itemUnit[]"]').value = kept.unit;
        }
    }

    document.getElementById('addRow').addEventListener('click', () => {
        list.insertAdjacentHTML('beforeend', itemTemplate(false));
    });

    list.addEventListener('input', recalc);
    list.addEventListener('click', e => {
        if (e.target.matches('.removeRow')) {
            e.target.closest('.item-row').remove();
            recalc();
        }
    });

    recalc();

    // 📎 The file area opens the file chooser on a click, and on Enter or
    // Space for somebody using the keyboard. Both were handlers written on
    // the element (onclick="...", onkeydown="..."), which browsers refuse on a
    // page with the standard header (#144, 3 June 2026; #625): the area did
    // nothing and no file could be added. WHAT IT STILL DOES NOT DO: take a
    // file that is dropped on it. It never did; only the chooser adds files.
    const dropzone  = document.getElementById('filesDropzone');
    const fileInput = document.getElementById('files');
    if (dropzone !== null && fileInput !== null) {
        dropzone.addEventListener('click', () => { fileInput.click(); });
        dropzone.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                fileInput.click();
            }
        });
    }
})();
</script>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
