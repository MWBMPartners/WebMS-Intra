<?php
// Path: apps/help/index.php
/**
 * -----------------------------------------------------------------------------
 * Help Centre -- Home Page
 * -----------------------------------------------------------------------------
 * Overview page with cards linking to each help section. Provides a quick-links
 * area and a responsive grid of clickable topic cards with icons.
 * -----------------------------------------------------------------------------
 * @package    Portal\Help
 * @license   All Rights Reserved
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// Page metadata for the template system
$pageTitle   = 'Help Centre';
$pageSection = 'help';
$breadcrumbs = ['Dashboard' => '/', 'Help' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- Help Centre Home -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-circle-question me-2"></i>Help Centre</h1>
        <p class="text-secondary mb-0">Find guides, tips, and answers for every part of the portal.</p>
    </div>
</div>

<!-- Quick Links -->
<div class="alert alert-primary d-flex align-items-start gap-3 mb-4" role="alert">
    <i class="fa-solid fa-bolt fa-lg mt-1"></i>
    <div>
        <h6 class="alert-heading mb-1">Quick Links</h6>
        <div class="d-flex flex-wrap gap-2">
            <a href="/help/getting-started" class="badge text-bg-primary text-decoration-none">
                <i class="fa-solid fa-rocket me-1"></i>Getting Started
            </a>
            <a href="/help/expenses" class="badge text-bg-primary text-decoration-none">
                <i class="fa-solid fa-receipt me-1"></i>Submit an Expense
            </a>
            <a href="/help/approvals" class="badge text-bg-primary text-decoration-none">
                <i class="fa-solid fa-check-double me-1"></i>Approve a Claim
            </a>
            <a href="/help/faq" class="badge text-bg-primary text-decoration-none">
                <i class="fa-solid fa-comments me-1"></i>FAQ
            </a>
        </div>
    </div>
</div>

<!-- Help Topic Cards Grid -->
<div class="row g-4">

    <!-- Getting Started -->
    <div class="col-12 col-sm-6 col-lg-4">
        <a href="/help/getting-started" class="text-decoration-none text-reset">
            <div class="portal-card portal-card-branded h-100 p-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary bg-opacity-10 text-primary" style="width:48px;height:48px;">
                        <i class="fa-solid fa-rocket fa-lg"></i>
                    </span>
                    <h5 class="mb-0">Getting Started</h5>
                </div>
                <p class="text-secondary mb-0 small">Logging in, navigating the portal, first-time setup, and personalising your experience.</p>
            </div>
        </a>
    </div>

    <!-- Expenses -->
    <div class="col-12 col-sm-6 col-lg-4">
        <a href="/help/expenses" class="text-decoration-none text-reset">
            <div class="portal-card portal-card-branded h-100 p-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-success bg-opacity-10 text-success" style="width:48px;height:48px;">
                        <i class="fa-solid fa-receipt fa-lg"></i>
                    </span>
                    <h5 class="mb-0">Expenses</h5>
                </div>
                <p class="text-secondary mb-0 small">Submitting expense claims, uploading receipts, tracking statuses, and understanding the workflow.</p>
            </div>
        </a>
    </div>

    <!-- Approvals -->
    <div class="col-12 col-sm-6 col-lg-4">
        <a href="/help/approvals" class="text-decoration-none text-reset">
            <div class="portal-card portal-card-branded h-100 p-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-warning bg-opacity-10 text-warning" style="width:48px;height:48px;">
                        <i class="fa-solid fa-check-double fa-lg"></i>
                    </span>
                    <h5 class="mb-0">Approvals</h5>
                </div>
                <p class="text-secondary mb-0 small">For approvers: reviewing claims, making decisions, and leaving comments.</p>
            </div>
        </a>
    </div>

    <!-- Treasury -->
    <div class="col-12 col-sm-6 col-lg-4">
        <a href="/help/treasury" class="text-decoration-none text-reset">
            <div class="portal-card portal-card-branded h-100 p-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-info bg-opacity-10 text-info" style="width:48px;height:48px;">
                        <i class="fa-solid fa-sterling-sign fa-lg"></i>
                    </span>
                    <h5 class="mb-0">Treasury</h5>
                </div>
                <p class="text-secondary mb-0 small">For treasury staff: recording reimbursements, payment references, and generating PDFs.</p>
            </div>
        </a>
    </div>

    <!-- Admin -->
    <div class="col-12 col-sm-6 col-lg-4">
        <a href="/help/admin" class="text-decoration-none text-reset">
            <div class="portal-card portal-card-branded h-100 p-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-danger bg-opacity-10 text-danger" style="width:48px;height:48px;">
                        <i class="fa-solid fa-gear fa-lg"></i>
                    </span>
                    <h5 class="mb-0">Admin Guide</h5>
                </div>
                <p class="text-secondary mb-0 small">For administrators: managing settings, user roles, Gatekeeper access, and viewing logs.</p>
            </div>
        </a>
    </div>

    <!-- FAQ -->
    <div class="col-12 col-sm-6 col-lg-4">
        <a href="/help/faq" class="text-decoration-none text-reset">
            <div class="portal-card portal-card-branded h-100 p-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-secondary bg-opacity-10 text-secondary" style="width:48px;height:48px;">
                        <i class="fa-solid fa-comments fa-lg"></i>
                    </span>
                    <h5 class="mb-0">FAQ</h5>
                </div>
                <p class="text-secondary mb-0 small">Frequently asked questions covering login issues, expenses, permissions, and more.</p>
            </div>
        </a>
    </div>

</div>

<!-- Need more help -->
<div class="card mt-5 border-0 bg-body-tertiary">
    <div class="card-body text-center py-4">
        <i class="fa-solid fa-headset fa-2x text-secondary mb-3"></i>
        <h5>Still need help?</h5>
        <p class="text-secondary mb-0">Contact your system administrator or raise a support request through your organisation's IT helpdesk.</p>
    </div>
</div>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
