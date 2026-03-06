<?php
// Path: apps/help/approvals.php
/**
 * -----------------------------------------------------------------------------
 * Help Centre -- Approvals Guide
 * -----------------------------------------------------------------------------
 * Guide for approvers: accessing the approval dashboard, reviewing claims,
 * making decisions (approve / reject), and adding comments.
 * -----------------------------------------------------------------------------
 * @package    Portal\Help
 * @license   All Rights Reserved
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$pageTitle   = 'Help - Approvals';
$pageSection = 'help';
$breadcrumbs = ['Dashboard' => '/', 'Help' => '/help', 'Approvals' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- Approvals Guide -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-check-double me-2"></i>Approvals Guide</h1>
        <p class="text-secondary mb-0">For approvers: how to review, approve, or reject expense claims.</p>
    </div>
    <a href="/help" class="btn btn-outline-secondary btn-sm mt-2 mt-md-0">
        <i class="fa-solid fa-arrow-left me-1"></i>Back to Help Centre
    </a>
</div>

<!-- Who is this for? -->
<div class="alert alert-info d-flex gap-2 mb-4" role="alert">
    <i class="fa-solid fa-user-shield mt-1"></i>
    <div>
        <strong>Who is this for?</strong> This guide is for users who have been designated as expense approvers. If you only need to submit claims, see the <a href="/help/expenses" class="alert-link">Expenses Guide</a> instead.
    </div>
</div>

<!-- Table of contents -->
<div class="card mb-4 border-0 bg-body-tertiary">
    <div class="card-body">
        <h6 class="card-title mb-2"><i class="fa-solid fa-list me-1"></i>On this page</h6>
        <div class="d-flex flex-wrap gap-2">
            <a href="#accessing" class="badge text-bg-secondary text-decoration-none">Accessing the Dashboard</a>
            <a href="#reviewing" class="badge text-bg-secondary text-decoration-none">Reviewing a Claim</a>
            <a href="#deciding" class="badge text-bg-secondary text-decoration-none">Approving or Rejecting</a>
            <a href="#comments" class="badge text-bg-secondary text-decoration-none">Adding Comments</a>
        </div>
    </div>
</div>

<!-- Section 1: Accessing the Approval Dashboard -->
<div class="portal-card p-4 mb-4" id="accessing">
    <h2 class="h4 mb-3"><i class="fa-solid fa-table-columns me-2 text-primary"></i>Accessing the Approval Dashboard</h2>

    <p>The approval dashboard shows all expense claims that are currently waiting for your review.</p>

    <div class="list-group list-group-flush mb-3">
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">1</span>
            <div>
                <strong>Navigate to Expenses</strong>
                <p class="mb-0 small text-secondary">Click <strong>Expenses</strong> in the top navigation bar.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">2</span>
            <div>
                <strong>Open the Approve section</strong>
                <p class="mb-0 small text-secondary">Navigate to the approval page. You will see the heading <strong>"Pending Expense Approvals"</strong>.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">3</span>
            <div>
                <strong>View the pending claims list</strong>
                <p class="mb-0 small text-secondary">Each row displays the claim ID, title, claimant name, department, total amount, and submission date.</p>
            </div>
        </div>
    </div>

    <div class="alert alert-info d-flex gap-2" role="alert">
        <i class="fa-solid fa-circle-info mt-1"></i>
        <div>
            <strong>Note:</strong> If there are no pending claims, you will see a blue information banner stating "No claims awaiting your approval."
        </div>
    </div>
</div>

<!-- Section 2: Reviewing a Claim -->
<div class="portal-card p-4 mb-4" id="reviewing">
    <h2 class="h4 mb-3"><i class="fa-solid fa-magnifying-glass me-2 text-primary"></i>Reviewing a Claim</h2>

    <p>To review a specific claim, open its details in the review modal.</p>

    <div class="list-group list-group-flush mb-3">
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">1</span>
            <div>
                <strong>Click the "Review" button</strong>
                <p class="mb-0 small text-secondary">Each claim row has a <span class="badge text-bg-primary">Review</span> button on the right side. Click it to open the review modal.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">2</span>
            <div>
                <strong>Examine the claim details</strong>
                <p class="mb-0 small text-secondary">The modal displays a summary of the claim including:</p>
                <ul class="small text-secondary mt-1 mb-0">
                    <li><strong>Title</strong> -- the claim description</li>
                    <li><strong>Claimant</strong> -- who submitted the claim</li>
                    <li><strong>Department</strong> -- which department is being charged</li>
                    <li><strong>Total</strong> -- the total amount claimed</li>
                    <li><strong>Submitted</strong> -- when the claim was filed</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="alert alert-warning d-flex gap-2" role="alert">
        <i class="fa-solid fa-triangle-exclamation mt-1"></i>
        <div>
            <strong>Best practice:</strong> Always verify that the claimed amount seems reasonable for the description provided, and check that any attached receipts match the amounts shown.
        </div>
    </div>
</div>

<!-- Section 3: Approving or Rejecting -->
<div class="portal-card p-4 mb-4" id="deciding">
    <h2 class="h4 mb-3"><i class="fa-solid fa-gavel me-2 text-primary"></i>Approving or Rejecting a Claim</h2>

    <p>After reviewing the claim details in the modal, you can make your decision.</p>

    <div class="row g-4 mb-3">
        <div class="col-12 col-md-6">
            <div class="card border-success h-100">
                <div class="card-body">
                    <h5 class="card-title text-success"><i class="fa-solid fa-thumbs-up me-1"></i>Approving a Claim</h5>
                    <div class="list-group list-group-flush list-group-numbered">
                        <div class="list-group-item border-0 px-0">Ensure <strong>"Approve"</strong> is selected in the Decision dropdown.</div>
                        <div class="list-group-item border-0 px-0">Optionally add a comment (e.g., "Looks good, approved").</div>
                        <div class="list-group-item border-0 px-0">Click <strong>"Submit"</strong>.</div>
                    </div>
                    <p class="small text-secondary mt-2 mb-0">The claim status changes to <span class="portal-badge portal-badge-approved"><i class="fa-solid fa-check"></i> Approved</span> and moves to the treasury queue for payment.</p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6">
            <div class="card border-danger h-100">
                <div class="card-body">
                    <h5 class="card-title text-danger"><i class="fa-solid fa-thumbs-down me-1"></i>Rejecting a Claim</h5>
                    <div class="list-group list-group-flush list-group-numbered">
                        <div class="list-group-item border-0 px-0">Select <strong>"Reject"</strong> from the Decision dropdown.</div>
                        <div class="list-group-item border-0 px-0">Add a clear comment explaining the reason for rejection.</div>
                        <div class="list-group-item border-0 px-0">Click <strong>"Submit"</strong>.</div>
                    </div>
                    <p class="small text-secondary mt-2 mb-0">The claim status changes to <span class="portal-badge portal-badge-rejected"><i class="fa-solid fa-xmark"></i> Rejected</span>. The claimant will see your comments.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-warning d-flex gap-2" role="alert">
        <i class="fa-solid fa-triangle-exclamation mt-1"></i>
        <div>
            <strong>Important:</strong> Approval decisions are final and recorded in the system log. Please review claims carefully before making your decision.
        </div>
    </div>
</div>

<!-- Section 4: Adding Comments -->
<div class="portal-card p-4 mb-4" id="comments">
    <h2 class="h4 mb-3"><i class="fa-solid fa-comment-dots me-2 text-primary"></i>Adding Comments</h2>

    <p>The comments field in the review modal allows you to leave a message for the claimant and for audit purposes.</p>

    <h5 class="mt-3 mb-3">When to leave comments</h5>

    <ul class="list-group list-group-flush mb-3">
        <li class="list-group-item d-flex gap-2">
            <i class="fa-solid fa-check text-success mt-1"></i>
            <div>
                <strong>Rejections (required):</strong> Always explain why a claim was rejected so the claimant can correct and resubmit if appropriate.
            </div>
        </li>
        <li class="list-group-item d-flex gap-2">
            <i class="fa-solid fa-circle-info text-info mt-1"></i>
            <div>
                <strong>Approvals (optional):</strong> You may add a brief note (e.g., "Approved per policy") but it is not required.
            </div>
        </li>
        <li class="list-group-item d-flex gap-2">
            <i class="fa-solid fa-shield text-primary mt-1"></i>
            <div>
                <strong>Audit trail:</strong> All comments are saved as part of the claim record and may be reviewed during audits.
            </div>
        </li>
    </ul>

    <div class="alert alert-info d-flex gap-2" role="alert">
        <i class="fa-solid fa-circle-info mt-1"></i>
        <div>
            <strong>Tip:</strong> Keep comments professional and factual. They form part of the permanent record for each expense claim.
        </div>
    </div>
</div>

<!-- Navigation -->
<div class="d-flex justify-content-between">
    <a href="/help/expenses" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i>Expenses Guide
    </a>
    <a href="/help/treasury" class="btn btn-primary">
        Treasury Guide<i class="fa-solid fa-arrow-right ms-1"></i>
    </a>
</div>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
