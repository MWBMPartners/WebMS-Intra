<?php
// Path: apps/expenses/submit/index.php
/**
 * -----------------------------------------------------------------------------
 * Expenses -- Claim Submission Form (Responsive) 💸
 * -----------------------------------------------------------------------------
 * Reworked to drop <table> tags in favour of Bootstrap's flexbox/grid layout so
 * the item list adapts elegantly on phones, tablets, and desktops.  Column
 * labels collapse into inline placeholders on narrow viewports for optimal UX.
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Captcha;
use Portal\Core\Site;

// 📌 Page metadata for the template system
$pageTitle   = 'Submit Expense Claim';
$pageSection = 'expenses';
$breadcrumbs = ['Dashboard' => '/', 'Expenses' => '/expenses/submit', 'Submit Claim' => ''];

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

<!-- 💸 Expense Claim Submission Form -->
<h1 class="mb-4">Submit Expense Claim</h1>

<form method="post" action="/expenses/submit/save.php" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">

    <div class="mb-3">
        <label class="form-label">Claim Title</label>
        <input type="text" class="form-control" name="claimTitle" required>
    </div>

    <div class="mb-3">
        <label class="form-label">Department</label>
        <select class="form-select" name="deptID" required>
            <option value="" disabled selected>Select department...</option>
            <?php foreach ($depts as $dept): ?>
                <option value="<?php echo (int) $dept['deptID']; ?>"><?php echo htmlspecialchars($dept['deptName'], ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select>
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
                    <input type="text" name="itemDesc[]" class="form-control" placeholder="Item <?php echo $rowIdx; ?>">
                </div>
                <div class="col-4 col-md-2">
                    <label class="form-label d-md-none">Qty</label>
                    <input type="number" name="itemQty[]" class="form-control text-end" min="1" value="1">
                </div>
                <div class="col-4 col-md-2">
                    <label class="form-label d-md-none">Unit &pound;</label>
                    <input type="number" step="0.01" name="itemUnit[]" class="form-control text-end" value="0.00">
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
        <div class="dropzone" onclick="document.getElementById('files').click()">Drop files here or click to upload</div>
        <input type="file" name="files[]" id="files" class="form-control d-none" multiple accept="image/*,application/pdf">
        <noscript>
            <input type="file" name="files[]" class="form-control mt-2" multiple accept="image/*,application/pdf">
        </noscript>
    </div>

    <!-- 🤖 Captcha widget (if configured) -->
    <?php echo Captcha::widget(); ?>

    <button type="submit" class="btn btn-primary">Submit Claim</button>
</form>

<style>
    .dropzone { border: 2px dashed #6c757d; padding: 1rem; text-align: center; border-radius: .5rem; cursor: pointer; }
    .item-row .form-label { font-size: .75rem; font-weight: 600; }
</style>

<!-- 🤖 Captcha script (if configured) -->
<?php echo Captcha::scriptTag(); ?>

<!-- 📦 Inline items JavaScript -->
<script>
(function(){
    const list = document.getElementById('items-list');
    const grandTotal = document.getElementById('grandTotal');
    const totalInput = document.getElementById('totalAmount');

    function itemTemplate(disabledRemove){
        return `<div class="item-row row g-2 align-items-end mb-2">
            <div class="col-12 col-md-5">
                <label class="form-label d-md-none">Description</label>
                <input type="text" name="itemDesc[]" class="form-control" required>
            </div>
            <div class="col-4 col-md-2">
                <label class="form-label d-md-none">Qty</label>
                <input type="number" name="itemQty[]" class="form-control text-end" min="1" value="1" required>
            </div>
            <div class="col-4 col-md-2">
                <label class="form-label d-md-none">Unit \u00a3</label>
                <input type="number" step="0.01" name="itemUnit[]" class="form-control text-end" required>
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
})();
</script>

<?php
// 📄 Include shared footer template
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
