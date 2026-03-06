<?php
// Path: apps/help/treasury.php
/**
 * -----------------------------------------------------------------------------
 * Help Centre -- Treasury Guide
 * -----------------------------------------------------------------------------
 * Guide for treasury staff: accessing the treasury dashboard, recording
 * reimbursements, entering payment references, and PDF generation.
 * -----------------------------------------------------------------------------
 * @package    Portal\Help
 * @license   All Rights Reserved
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$pageTitle   = 'Help - Treasury';
$pageSection = 'help';
$breadcrumbs = ['Dashboard' => '/', 'Help' => '/help', 'Treasury' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- Treasury Guide -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-sterling-sign me-2"></i>Treasury Guide</h1>
        <p class="text-secondary mb-0">For treasury staff: processing reimbursements and recording payments.</p>
    </div>
    <a href="/help" class="btn btn-outline-secondary btn-sm mt-2 mt-md-0">
        <i class="fa-solid fa-arrow-left me-1"></i>Back to Help Centre
    </a>
</div>

<!-- Who is this for? -->
<div class="alert alert-info d-flex gap-2 mb-4" role="alert">
    <i class="fa-solid fa-building-columns mt-1"></i>
    <div>
        <strong>Who is this for?</strong> This guide is for treasury or finance team members responsible for processing reimbursements. If you need to submit claims, see the <a href="/help/expenses" class="alert-link">Expenses Guide</a>. If you need to approve claims, see the <a href="/help/approvals" class="alert-link">Approvals Guide</a>.
    </div>
</div>

<!-- Table of contents -->
<div class="card mb-4 border-0 bg-body-tertiary">
    <div class="card-body">
        <h6 class="card-title mb-2"><i class="fa-solid fa-list me-1"></i>On this page</h6>
        <div class="d-flex flex-wrap gap-2">
            <a href="#accessing" class="badge text-bg-secondary text-decoration-none">Accessing the Dashboard</a>
            <a href="#reimbursement" class="badge text-bg-secondary text-decoration-none">Recording Reimbursement</a>
            <a href="#payment-refs" class="badge text-bg-secondary text-decoration-none">Payment References</a>
            <a href="#pdf" class="badge text-bg-secondary text-decoration-none">PDF Generation</a>
        </div>
    </div>
</div>

<!-- Section 1: Accessing the Treasury Dashboard -->
<div class="portal-card p-4 mb-4" id="accessing">
    <h2 class="h4 mb-3"><i class="fa-solid fa-table-columns me-2 text-primary"></i>Accessing the Treasury Dashboard</h2>

    <p>The treasury dashboard lists all expense claims that have been <strong>approved</strong> and are awaiting reimbursement.</p>

    <div class="list-group list-group-flush mb-3">
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">1</span>
            <div>
                <strong>Navigate to the Treasury section</strong>
                <p class="mb-0 small text-secondary">Access the treasury page via the Expenses navigation. You will see the heading <strong>"Treasury -- Approved Claims"</strong>.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">2</span>
            <div>
                <strong>Review the approved claims list</strong>
                <p class="mb-0 small text-secondary">Each row displays the claim ID, title, claimant name, department, total amount, and submission date. Only claims with an <span class="portal-badge portal-badge-approved"><i class="fa-solid fa-check"></i> Approved</span> status appear here.</p>
            </div>
        </div>
    </div>

    <div class="alert alert-info d-flex gap-2" role="alert">
        <i class="fa-solid fa-circle-info mt-1"></i>
        <div>
            <strong>Note:</strong> If there are no approved claims awaiting payment, you will see a blue information banner stating "No approved claims awaiting reimbursement."
        </div>
    </div>
</div>

<!-- Section 2: Recording a Reimbursement -->
<div class="portal-card p-4 mb-4" id="reimbursement">
    <h2 class="h4 mb-3"><i class="fa-solid fa-money-check-dollar me-2 text-success"></i>Recording a Reimbursement</h2>

    <p>When you have processed a payment for an approved claim, record the reimbursement in the portal.</p>

    <div class="list-group list-group-flush mb-3">
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-success rounded-pill mt-1">1</span>
            <div>
                <strong>Click the "Pay" button</strong>
                <p class="mb-0 small text-secondary">Each approved claim has a green <span class="badge text-bg-success">Pay</span> button. Click it to open the reimbursement modal.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-success rounded-pill mt-1">2</span>
            <div>
                <strong>Verify the claim details</strong>
                <p class="mb-0 small text-secondary">The modal displays a summary of the claim (title, claimant, department, total, date). Verify these details match your payment records.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-success rounded-pill mt-1">3</span>
            <div>
                <strong>Enter the payment reference</strong>
                <p class="mb-0 small text-secondary">Type the payment reference number (e.g., BACS reference, bank transfer ID) into the "Payment Reference(s)" field. See the <a href="#payment-refs">Payment References</a> section for details.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-success rounded-pill mt-1">4</span>
            <div>
                <strong>Add optional comments</strong>
                <p class="mb-0 small text-secondary">You may leave a note about the payment (e.g., payment date, method, or any special circumstances).</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-success rounded-pill mt-1">5</span>
            <div>
                <strong>Click "Mark as Reimbursed"</strong>
                <p class="mb-0 small text-secondary">The claim status will change to <span class="portal-badge portal-badge-paid"><i class="fa-solid fa-sterling-sign"></i> Reimbursed</span> and it will be removed from the treasury queue.</p>
            </div>
        </div>
    </div>

    <div class="alert alert-warning d-flex gap-2" role="alert">
        <i class="fa-solid fa-triangle-exclamation mt-1"></i>
        <div>
            <strong>Important:</strong> Only mark a claim as reimbursed <em>after</em> the payment has been processed through your bank or payment system. This action is logged and auditable.
        </div>
    </div>
</div>

<!-- Section 3: Payment References -->
<div class="portal-card p-4 mb-4" id="payment-refs">
    <h2 class="h4 mb-3"><i class="fa-solid fa-hashtag me-2 text-primary"></i>Payment References</h2>

    <p>Payment references link the portal record to your organisation's banking or payment system. They are essential for audit trails and reconciliation.</p>

    <h5 class="mt-3 mb-3">What to enter</h5>

    <ul class="list-group list-group-flush mb-3">
        <li class="list-group-item d-flex gap-2">
            <i class="fa-solid fa-building-columns text-primary mt-1"></i>
            <div>
                <strong>BACS reference:</strong> If you paid via BACS, enter the BACS transaction reference (e.g., <code>BACS-20260215-001</code>).
            </div>
        </li>
        <li class="list-group-item d-flex gap-2">
            <i class="fa-solid fa-credit-card text-primary mt-1"></i>
            <div>
                <strong>Bank transfer ID:</strong> For direct bank transfers, enter the transaction ID from your banking portal.
            </div>
        </li>
        <li class="list-group-item d-flex gap-2">
            <i class="fa-solid fa-file-invoice text-primary mt-1"></i>
            <div>
                <strong>Cheque number:</strong> If paying by cheque, enter the cheque number.
            </div>
        </li>
        <li class="list-group-item d-flex gap-2">
            <i class="fa-solid fa-ellipsis text-primary mt-1"></i>
            <div>
                <strong>Other:</strong> Any other unique identifier that links this portal record to your payment system.
            </div>
        </li>
    </ul>

    <div class="alert alert-info d-flex gap-2" role="alert">
        <i class="fa-solid fa-circle-info mt-1"></i>
        <div>
            <strong>Tip:</strong> Use a consistent naming convention for payment references across all reimbursements. This makes reconciliation and auditing much easier.
        </div>
    </div>
</div>

<!-- Section 4: PDF Generation -->
<div class="portal-card p-4 mb-4" id="pdf">
    <h2 class="h4 mb-3"><i class="fa-solid fa-file-pdf me-2 text-danger"></i>PDF Generation</h2>

    <p>The portal automatically generates PDF summaries for expense claims. These PDFs serve as official records for filing and audit purposes.</p>

    <h5 class="mt-3 mb-3">What the PDF includes</h5>

    <ul class="list-group list-group-flush mb-3">
        <li class="list-group-item"><i class="fa-solid fa-user me-2 text-primary"></i>Claimant name and department</li>
        <li class="list-group-item"><i class="fa-solid fa-list me-2 text-primary"></i>Full list of expense line items with descriptions, quantities, and amounts</li>
        <li class="list-group-item"><i class="fa-solid fa-calculator me-2 text-primary"></i>Grand total</li>
        <li class="list-group-item"><i class="fa-solid fa-check-double me-2 text-primary"></i>Approval status and approver details</li>
        <li class="list-group-item"><i class="fa-solid fa-calendar me-2 text-primary"></i>Submission and approval dates</li>
        <li class="list-group-item"><i class="fa-solid fa-hashtag me-2 text-primary"></i>Payment reference (once reimbursed)</li>
    </ul>

    <h5 class="mt-3 mb-3">When PDFs are generated</h5>

    <div class="list-group list-group-flush mb-3">
        <div class="list-group-item d-flex gap-2">
            <i class="fa-solid fa-paper-plane text-primary mt-1"></i>
            <div>
                <strong>On submission:</strong> A PDF summary is generated when the claim is submitted, capturing all claim details for the record.
            </div>
        </div>
        <div class="list-group-item d-flex gap-2">
            <i class="fa-solid fa-check text-success mt-1"></i>
            <div>
                <strong>On approval/rejection:</strong> The PDF may be updated to reflect the decision and any comments left by the approver.
            </div>
        </div>
    </div>

    <div class="alert alert-info d-flex gap-2" role="alert">
        <i class="fa-solid fa-circle-info mt-1"></i>
        <div>
            <strong>Tip:</strong> PDFs can be downloaded and saved to your local records. They are also useful for sharing with external auditors or finance teams who may not have portal access.
        </div>
    </div>
</div>

<!-- Navigation -->
<div class="d-flex justify-content-between">
    <a href="/help/approvals" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i>Approvals Guide
    </a>
    <a href="/help/admin" class="btn btn-primary">
        Admin Guide<i class="fa-solid fa-arrow-right ms-1"></i>
    </a>
</div>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
