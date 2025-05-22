<?php
// Path: apps/expenses/submit/index.php  (REFRESHED – now div‑based, no <table>)
/**
 * -----------------------------------------------------------------------------
 * Expenses – Claim Submission Form (Responsive) 💸
 * -----------------------------------------------------------------------------
 * Reworked to drop <table> tags in favour of Bootstrap’s flexbox/grid layout so
 * the item list adapts elegantly on phones, tablets, and desktops.  Column
 * labels collapse into inline placeholders on narrow viewports for optimal UX.
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\Auth;

Auth::requireLogin();

/* -------------------------------------------------------------------------- */
/* Active departments                                                         */
/* -------------------------------------------------------------------------- */
$depts = [];
$stmt = $mysqli->prepare('SELECT deptID, deptName FROM tblDepts WHERE isActive = 1 ORDER BY deptName');
$stmt->execute();
$res = $stmt->get_result();
while ($d = $res->fetch_assoc()) { $depts[] = $d; }
$stmt->close();

?>
<!doctype html>
<html lang="en" data-bs-theme="<?php echo ($SETTINGS['features']['darkModeEnabled'] ?? 'false') === 'true' ? 'dark' : 'light'; ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New Expense Claim • <?php echo htmlspecialchars($SETTINGS['site']['name']); ?></title>
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    <script src="/assets/js/bootstrap.bundle.min.js" defer></script>
    <script src="/assets/js/claim.js" defer></script>
<?php if (captchaScriptTag() !== '') { echo captchaScriptTag(); } ?>
    <style>
        .dropzone{border:2px dashed #6c757d;padding:1rem;text-align:center;border-radius:.5rem;cursor:pointer}
        .item-row .form-label{font-size:.75rem;font-weight:600}
    </style>
</head>
<body>
<div class="container py-4">
    <h1 class="mb-4">Submit Expense Claim</h1>

    <form method="post" action="/expenses/submit/save.php" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">

        <div class="mb-3">
            <label class="form-label">Claim Title</label>
            <input type="text" class="form-control" name="claimTitle" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Department</label>
            <select class="form-select" name="deptID" required>
                <option value="" disabled selected>Select department…</option>
                <?php foreach ($depts as $dept): ?>
                    <option value="<?php echo $dept['deptID']; ?>"><?php echo htmlspecialchars($dept['deptName']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <h4 class="mt-4">Items</h4>

        <!-- Column headings for ≥ md screens -->
        <div class="d-none d-md-flex fw-semibold border-bottom py-1 mb-2 small text-secondary">
            <div class="col-md-5">Description</div>
            <div class="col-md-2 text-end">Qty</div>
            <div class="col-md-2 text-end">Unit £</div>
            <div class="col-md-2 text-end">Line £</div>
            <div class="col-md-1"></div>
        </div>

        <div id="items-list"></div>

        <button type="button" id="addRow" class="btn btn-outline-secondary btn-sm mb-3">+ Add item</button>

        <div class="mb-3 text-end">
            <strong>Total: £ <span id="grandTotal">0.00</span></strong>
            <input type="hidden" name="totalAmount" id="totalAmount">
        </div>

        <h4 class="mt-4">Supporting Files</h4>
        <div class="mb-3">
            <div class="dropzone" onclick="document.getElementById('files').click()">Drop files here or click to upload</div>
            <input type="file" name="files[]" id="files" class="form-control d-none" multiple accept="image/*,application/pdf">
        </div>

        <?php echo captchaWidget(); ?>

        <button type="submit" class="btn btn-primary">Submit Claim</button>
    </form>
</div>

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
                <label class="form-label d-md-none">Unit £</label>
                <input type="number" step="0.01" name="itemUnit[]" class="form-control text-end" required>
            </div>
            <div class="col-4 col-md-2 text-end">
                <label class="form-label d-md-none">Line £</label>
                <span class="d-block pt-2 lineTotal">0.00</span>
            </div>
            <div class="col-12 col-md-1 text-end">
                <button type="button" class="btn btn-outline-danger btn-sm removeRow" ${disabledRemove?'disabled':''}>&times;</button>
            </div>
        </div>`;
    }

    function recalc(){
        let sum=0;
        list.querySelectorAll('.item-row').forEach(row=>{
            const qty=parseFloat(row.querySelector('[name="itemQty[]"]').value)||0;
            const unit=parseFloat(row.querySelector('[name="itemUnit[]"]').value)||0;
            const line = qty*unit;
            row.querySelector('.lineTotal').textContent = line.toFixed(2);
            sum += line;
        });
        grandTotal.textContent=sum.toFixed(2);
        totalInput.value=sum.toFixed(2);
    }

    // Initial row
    list.insertAdjacentHTML('beforeend', itemTemplate(true));

    document.getElementById('addRow').addEventListener('click', ()=>{
        list.insertAdjacentHTML('beforeend', itemTemplate(false));
    });

    list.addEventListener('input', recalc);
    list.addEventListener('click', e=>{
        if(e.target.matches('.removeRow')){
            e.target.closest('.item-row').remove();
            recalc();
        }
    });

    recalc();
})();
</script>
</body>
</html>
<?php
function captchaScriptTag(){return '';}function captchaWidget(){return '';}
