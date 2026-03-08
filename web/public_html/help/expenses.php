<?php
// Path: apps/help/expenses.php
/**
 * -----------------------------------------------------------------------------
 * Help Centre -- Expenses Guide
 * -----------------------------------------------------------------------------
 * Step-by-step guide for submitting expense claims, understanding statuses,
 * uploading receipts, and tracking reimbursements.
 * -----------------------------------------------------------------------------
 * @package    Portal\Help
 * @license   All Rights Reserved
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$pageTitle   = 'Help - Expenses';
$pageSection = 'help';
$breadcrumbs = ['Dashboard' => '/', 'Help' => '/help', 'Expenses' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- Expenses Guide -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-receipt me-2"></i>Expenses Guide</h1>
        <p class="text-secondary mb-0">Learn how to submit, track, and manage your expense claims.</p>
    </div>
    <a href="/help" class="btn btn-outline-secondary btn-sm mt-2 mt-md-0">
        <i class="fa-solid fa-arrow-left me-1"></i>Back to Help Centre
    </a>
</div>

<!-- Table of contents -->
<div class="card mb-4 border-0 bg-body-tertiary">
    <div class="card-body">
        <h6 class="card-title mb-2"><i class="fa-solid fa-list me-1"></i>On this page</h6>
        <div class="d-flex flex-wrap gap-2">
            <a href="#submitting" class="badge text-bg-secondary text-decoration-none">Submitting a Claim</a>
            <a href="#after-submission" class="badge text-bg-secondary text-decoration-none">After Submission</a>
            <a href="#statuses" class="badge text-bg-secondary text-decoration-none">Status Meanings</a>
            <a href="#receipts" class="badge text-bg-secondary text-decoration-none">Uploading Receipts</a>
            <a href="#viewing" class="badge text-bg-secondary text-decoration-none">Viewing Your Claims</a>
            <a href="#withdrawing" class="badge text-bg-secondary text-decoration-none">Withdrawing a Claim</a>
        </div>
    </div>
</div>

<!-- Section 1: Submitting an Expense Claim -->
<div class="portal-card p-4 mb-4" id="submitting">
    <h2 class="h4 mb-3"><i class="fa-solid fa-file-invoice me-2 text-success"></i>Submitting an Expense Claim</h2>

    <p>Follow these steps to submit a new expense claim through the portal.</p>

    <div class="list-group list-group-flush mb-3">
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-success rounded-pill mt-1">1</span>
            <div>
                <strong>Navigate to the Expenses app</strong>
                <p class="mb-0 small text-secondary">Click <strong>Expenses</strong> in the navigation bar, or click the Expenses card on the dashboard.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-success rounded-pill mt-1">2</span>
            <div>
                <strong>Enter the Claim Title</strong>
                <p class="mb-0 small text-secondary">Give your claim a short, descriptive title (e.g., "Travel to London - January 2026").</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-success rounded-pill mt-1">3</span>
            <div>
                <strong>Select a Department</strong>
                <p class="mb-0 small text-secondary">Choose the department this expense should be charged to from the dropdown list.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-success rounded-pill mt-1">4</span>
            <div>
                <strong>Add line items</strong>
                <p class="mb-0 small text-secondary">
                    Each expense claim has one or more line items. For each item, enter:
                </p>
                <ul class="small text-secondary mt-1 mb-0">
                    <li><strong>Description</strong> -- what the expense was for</li>
                    <li><strong>Quantity</strong> -- how many units (default is 1)</li>
                    <li><strong>Unit price</strong> -- the cost per unit in pounds</li>
                </ul>
                <p class="mt-1 mb-0 small text-secondary">The line total and grand total are calculated automatically. Click <strong>+ Add item</strong> to add more rows, or click the <span class="text-danger">&times;</span> button to remove a row.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-success rounded-pill mt-1">5</span>
            <div>
                <strong>Upload supporting files</strong>
                <p class="mb-0 small text-secondary">Attach receipts, invoices, or other evidence. See the <a href="#receipts">Uploading Receipts</a> section below for details.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-success rounded-pill mt-1">6</span>
            <div>
                <strong>Complete the CAPTCHA (if shown)</strong>
                <p class="mb-0 small text-secondary">Some portals require a CAPTCHA verification step. Complete it if prompted.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-success rounded-pill mt-1">7</span>
            <div>
                <strong>Click "Submit Claim"</strong>
                <p class="mb-0 small text-secondary">Review your entries, then click the blue <strong>Submit Claim</strong> button. Your claim will be sent for approval.</p>
            </div>
        </div>
    </div>

    <div class="alert alert-warning d-flex gap-2" role="alert">
        <i class="fa-solid fa-triangle-exclamation mt-1"></i>
        <div>
            <strong>Important:</strong> Once submitted, a claim cannot be edited. Double-check all amounts and descriptions before submitting. If you need to make a correction, contact your approver.
        </div>
    </div>
</div>

<!-- Section 2: What Happens After Submission -->
<div class="portal-card p-4 mb-4" id="after-submission">
    <h2 class="h4 mb-3"><i class="fa-solid fa-route me-2 text-primary"></i>What Happens After Submission</h2>

    <p>After you submit an expense claim, it follows an approval workflow:</p>

    <div class="row g-3 mb-3">
        <div class="col-12 col-md-3">
            <div class="card text-center h-100 border-warning">
                <div class="card-body">
                    <i class="fa-solid fa-clock fa-2x text-warning mb-2"></i>
                    <h6>1. Pending</h6>
                    <p class="small text-secondary mb-0">Your claim is waiting for a designated approver to review it.</p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card text-center h-100 border-success">
                <div class="card-body">
                    <i class="fa-solid fa-check-circle fa-2x text-success mb-2"></i>
                    <h6>2. Approved</h6>
                    <p class="small text-secondary mb-0">The approver has accepted your claim. It now moves to treasury for payment.</p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card text-center h-100 border-info">
                <div class="card-body">
                    <i class="fa-solid fa-sterling-sign fa-2x text-info mb-2"></i>
                    <h6>3. Reimbursed</h6>
                    <p class="small text-secondary mb-0">Treasury has processed the payment. Check the payment reference for your records.</p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card text-center h-100 border-danger">
                <div class="card-body">
                    <i class="fa-solid fa-times-circle fa-2x text-danger mb-2"></i>
                    <h6>Rejected</h6>
                    <p class="small text-secondary mb-0">The approver has declined your claim. Check any comments for the reason.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-info d-flex gap-2" role="alert">
        <i class="fa-solid fa-circle-info mt-1"></i>
        <div>
            <strong>Tip:</strong> A PDF summary of your approved claim is generated automatically by the system and can be used for your personal records.
        </div>
    </div>
</div>

<!-- Section 3: Status Meanings -->
<div class="portal-card p-4 mb-4" id="statuses">
    <h2 class="h4 mb-3"><i class="fa-solid fa-tags me-2 text-primary"></i>Status Meanings</h2>

    <p>Each expense claim displays a status badge. Here is what each status means:</p>

    <div class="list-group list-group-flush">
        <div class="list-group-item d-flex align-items-center gap-3">
            <span class="portal-badge portal-badge-pending"><i class="fa-solid fa-clock"></i> Pending</span>
            <span>The claim has been submitted and is awaiting review by an approver. No action is required from you at this stage.</span>
        </div>
        <div class="list-group-item d-flex align-items-center gap-3">
            <span class="portal-badge portal-badge-approved"><i class="fa-solid fa-check"></i> Approved</span>
            <span>The approver has accepted the claim. It is now queued for payment by the treasury team.</span>
        </div>
        <div class="list-group-item d-flex align-items-center gap-3">
            <span class="portal-badge portal-badge-rejected"><i class="fa-solid fa-xmark"></i> Rejected</span>
            <span>The approver has declined the claim. Review any comments they left for an explanation. You may need to submit a corrected claim.</span>
        </div>
        <div class="list-group-item d-flex align-items-center gap-3">
            <span class="portal-badge portal-badge-paid"><i class="fa-solid fa-sterling-sign"></i> Reimbursed</span>
            <span>The treasury team has processed the payment. A payment reference number is attached for your records.</span>
        </div>
    </div>
</div>

<!-- Section 4: Uploading Receipts -->
<div class="portal-card p-4 mb-4" id="receipts">
    <h2 class="h4 mb-3"><i class="fa-solid fa-cloud-arrow-up me-2 text-primary"></i>Uploading Receipts</h2>

    <p>Supporting documents (receipts, invoices, etc.) can be attached to your expense claim when you submit it.</p>

    <h5 class="mt-3 mb-3">How to upload files</h5>

    <div class="list-group list-group-flush mb-3">
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">1</span>
            <div>
                <strong>Click the upload area</strong>
                <p class="mb-0 small text-secondary">In the "Supporting Files" section of the claim form, click the dashed dropzone or drag files directly onto it.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">2</span>
            <div>
                <strong>Select your files</strong>
                <p class="mb-0 small text-secondary">You can select multiple files at once. Accepted formats include images (JPEG, PNG) and PDF documents.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">3</span>
            <div>
                <strong>Files upload on submission</strong>
                <p class="mb-0 small text-secondary">Your files will be uploaded together with the claim when you click "Submit Claim".</p>
            </div>
        </div>
    </div>

    <div class="alert alert-warning d-flex gap-2" role="alert">
        <i class="fa-solid fa-triangle-exclamation mt-1"></i>
        <div>
            <strong>Best practice:</strong> Ensure receipts are legible and clearly show the amount, date, and vendor. Blurry or cropped images may cause your claim to be rejected.
        </div>
    </div>
</div>

<!-- Section 5: Viewing Your Claims -->
<div class="portal-card p-4 mb-4" id="viewing">
    <h2 class="h4 mb-3"><i class="fa-solid fa-list-check me-2 text-primary"></i>Viewing Your Claims</h2>

    <p>You can view all of your submitted expense claims and their current statuses at any time.</p>

    <div class="list-group list-group-flush mb-3">
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">1</span>
            <div>
                <strong>Navigate to the Expenses section</strong>
                <p class="mb-0 small text-secondary">Click <strong>Expenses</strong> in the navigation bar.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">2</span>
            <div>
                <strong>Review the claims list</strong>
                <p class="mb-0 small text-secondary">Each claim shows its title, department, total amount, date submitted, and current status badge.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">3</span>
            <div>
                <strong>Check for approver comments</strong>
                <p class="mb-0 small text-secondary">If your claim was rejected, look for the approver's comments to understand what needs to be corrected.</p>
            </div>
        </div>
    </div>

    <div class="alert alert-info d-flex gap-2" role="alert">
        <i class="fa-solid fa-circle-info mt-1"></i>
        <div>
            <strong>Tip:</strong> On mobile devices the claims list adapts to a stacked card layout for easy reading. Scroll down to see all your claims.
        </div>
    </div>
</div>

<!-- Section 6: Withdrawing a Claim -->
<div class="portal-card p-4 mb-4" id="withdrawing">
    <h2 class="h4 mb-3"><i class="fa-solid fa-rotate-left me-2 text-warning"></i>Withdrawing a Claim</h2>

    <p>If you have submitted a claim in error or need to make corrections, you can withdraw it while it is still pending approval.</p>

    <div class="list-group list-group-flush mb-3">
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-warning rounded-pill mt-1">1</span>
            <div>
                <strong>Navigate to your claims list</strong>
                <p class="mb-0 small text-secondary">Click <strong>Expenses</strong> in the navigation bar to view your submitted claims.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-warning rounded-pill mt-1">2</span>
            <div>
                <strong>Find the pending claim</strong>
                <p class="mb-0 small text-secondary">Look for the claim you wish to withdraw. It must still have a <span class="portal-badge portal-badge-pending"><i class="fa-solid fa-clock"></i> Pending</span> status.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-warning rounded-pill mt-1">3</span>
            <div>
                <strong>Click "Withdraw"</strong>
                <p class="mb-0 small text-secondary">Click the <strong>Withdraw</strong> button on the claim. You will be asked to confirm your decision.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-warning rounded-pill mt-1">4</span>
            <div>
                <strong>Confirm the withdrawal</strong>
                <p class="mb-0 small text-secondary">Once confirmed, the claim status changes to <strong>Withdrawn</strong> and it is removed from the approver's queue.</p>
            </div>
        </div>
    </div>

    <div class="alert alert-warning d-flex gap-2" role="alert">
        <i class="fa-solid fa-triangle-exclamation mt-1"></i>
        <div>
            <strong>Important:</strong> You can only withdraw claims that are still <strong>Pending</strong>. Once a claim has been approved, rejected, or reimbursed, it cannot be withdrawn. If you need to correct an approved claim, contact your administrator.
        </div>
    </div>
</div>

<!-- Navigation -->
<div class="d-flex justify-content-between">
    <a href="/help/getting-started" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i>Getting Started
    </a>
    <a href="/help/approvals" class="btn btn-primary">
        Approvals Guide<i class="fa-solid fa-arrow-right ms-1"></i>
    </a>
</div>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
