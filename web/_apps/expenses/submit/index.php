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
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Captcha;
use Portal\Core\Router;
use Portal\Core\Site;

// 📌 Page metadata for the template system
$pageTitle   = 'Submit Expense Claim';
$pageSection = 'expenses';
$breadcrumbs = ['Dashboard' => Router::url(''), 'Expenses' => Router::url('expenses/submit'), 'Submit Claim' => ''];

// 📋 The one-off message another page left for this person, read and cleared
//    exactly as the approvals page does (approve/index.php).
$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

/* -------------------------------------------------------------------------- */
/* 🏢 Active departments                                                      */
/* -------------------------------------------------------------------------- */
$depts = [];
$siteId = Site::id();
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

// 📄 Include shared header template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- 💸 Expense Claim Submission Form -->
<h1 class="mb-4">Submit Expense Claim</h1>

<form method="post" action="<?php echo htmlspecialchars(Router::url('expenses/submit/save'), ENT_QUOTES, 'UTF-8'); ?>" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">

    <div class="mb-3">
        <label class="form-label">Claim Title</label>
        <input type="text" class="form-control" name="claimTitle" required>
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
        <select class="form-select" name="deptID" required>
            <option value="" disabled selected>Select department...</option>
            <?php foreach ($depts as $dept): ?>
                <option value="<?php echo (int) $dept['deptID']; ?>"><?php echo htmlspecialchars($dept['deptName'], ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
    </div>

    <h4 class="mt-4">Items</h4>

    <!-- 📊 Column headings for >= md screens -->
    <div class="d-none d-md-flex fw-semibold border-bottom py-1 mb-2 small text-secondary">
        <div class="col-md-5">Description</div>
        <div class="col-md-2 text-end">Qty</div>
        <div class="col-md-2 text-end">Unit &pound;</div>
        <div class="col-md-2 text-end">Line &pound;</div>
        <div class="col-md-1"></div>
    </div>

    <div id="items-list">
        <!-- ⚠️ Static fallback rows for no-JS environments -->
        <noscript>
            <?php for ($rowIdx = 1; $rowIdx <= 5; $rowIdx++): ?>
            <div class="row g-2 align-items-end mb-2">
                <div class="col-12 col-md-5">
                    <label class="form-label d-md-none">Description</label>
                    <input type="text" name="itemDesc[]" class="form-control" aria-label="Description" placeholder="Item <?php echo $rowIdx; ?>">
                </div>
                <div class="col-4 col-md-2">
                    <label class="form-label d-md-none">Qty</label>
                    <input type="number" name="itemQty[]" class="form-control text-end" aria-label="Quantity" min="1" value="1">
                </div>
                <div class="col-4 col-md-2">
                    <label class="form-label d-md-none">Unit &pound;</label>
                    <input type="number" step="0.01" name="itemUnit[]" class="form-control text-end" aria-label="Unit price" value="0.00">
                </div>
                <div class="col-4 col-md-3"></div>
            </div>
            <?php endfor; ?>
            <p class="text-muted small"><i class="fa-solid fa-circle-info me-1"></i>JavaScript is disabled — 5 line items are shown. Blank rows will be ignored. Line totals will be calculated on the server.</p>
        </noscript>
    </div>

    <button type="button" id="addRow" class="btn btn-outline-secondary btn-sm mb-3">+ Add item</button>

    <div class="mb-3 text-end">
        <strong>Total: &pound; <span id="grandTotal">0.00</span></strong>
        <input type="hidden" name="totalAmount" id="totalAmount">
    </div>

    <h4 class="mt-4">Supporting Files</h4>
    <div class="mb-3">
        <div class="dropzone" id="filesDropzone" role="button" tabindex="0">Drop files here or click to upload</div>
        <input type="file" name="files[]" id="files" class="form-control d-none" multiple accept="image/*,application/pdf">
        <noscript>
            <input type="file" name="files[]" class="form-control mt-2" multiple accept="image/*,application/pdf">
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

    // 🏁 Initial row
    list.insertAdjacentHTML('beforeend', itemTemplate(true));

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
