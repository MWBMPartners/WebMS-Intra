<?php
// Path: apps/help/faq.php
/**
 * -----------------------------------------------------------------------------
 * Help Centre -- Frequently Asked Questions
 * -----------------------------------------------------------------------------
 * Common questions and answers covering login issues, expenses, permissions,
 * dark mode, and general portal usage. Uses Bootstrap accordions for a clean,
 * collapsible layout.
 * -----------------------------------------------------------------------------
 * @package    Portal\Help
 * @license    MIT
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$pageTitle   = 'Help - FAQ';
$pageSection = 'help';
$breadcrumbs = ['Dashboard' => '/', 'Help' => '/help', 'FAQ' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- FAQ Page -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-comments me-2"></i>Frequently Asked Questions</h1>
        <p class="text-secondary mb-0">Quick answers to common questions about the portal.</p>
    </div>
    <a href="/help" class="btn btn-outline-secondary btn-sm mt-2 mt-md-0">
        <i class="fa-solid fa-arrow-left me-1"></i>Back to Help Centre
    </a>
</div>

<!-- Category navigation -->
<div class="card mb-4 border-0 bg-body-tertiary">
    <div class="card-body">
        <h6 class="card-title mb-2"><i class="fa-solid fa-filter me-1"></i>Jump to a category</h6>
        <div class="d-flex flex-wrap gap-2">
            <a href="#faq-login" class="badge text-bg-secondary text-decoration-none">Login &amp; Account</a>
            <a href="#faq-expenses" class="badge text-bg-secondary text-decoration-none">Expenses</a>
            <a href="#faq-approvals" class="badge text-bg-secondary text-decoration-none">Approvals &amp; Treasury</a>
            <a href="#faq-portal" class="badge text-bg-secondary text-decoration-none">Portal &amp; General</a>
        </div>
    </div>
</div>

<!-- ================================================================== -->
<!-- Login & Account -->
<!-- ================================================================== -->
<h2 class="h5 mt-4 mb-3" id="faq-login">
    <i class="fa-solid fa-right-to-bracket me-2 text-primary"></i>Login &amp; Account
</h2>

<div class="accordion mb-4" id="accordionLogin">

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqLogin1">
                I cannot sign in with Microsoft 365. What should I do?
            </button>
        </h2>
        <div id="faqLogin1" class="accordion-collapse collapse" data-bs-parent="#accordionLogin">
            <div class="accordion-body">
                <p>Try the following steps:</p>
                <ol>
                    <li>Ensure you are using your <strong>organisational</strong> Microsoft 365 account, not a personal Microsoft account.</li>
                    <li>Clear your browser cache and cookies, then try again.</li>
                    <li>If you see "Invalid OAuth state", your session may have expired. Refresh the login page and try again.</li>
                    <li>Check with your IT team that your Microsoft 365 account is active and that the portal's Azure AD app registration is correctly configured.</li>
                </ol>
                <p class="mb-0">If the problem persists, contact your system administrator.</p>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqLogin2">
                I am locked out after too many failed login attempts.
            </button>
        </h2>
        <div id="faqLogin2" class="accordion-collapse collapse" data-bs-parent="#accordionLogin">
            <div class="accordion-body">
                <p>The portal has a rate-limiting feature to protect against brute-force attacks. If you have been locked out:</p>
                <ul>
                    <li><strong>Wait a few minutes</strong> -- the lockout is temporary and will expire automatically.</li>
                    <li>Try the <strong>Microsoft 365 SSO</strong> button instead, which bypasses the local login rate limiter.</li>
                    <li>If you have forgotten your local password, contact your administrator to have it reset.</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqLogin3">
                My name or profile picture is wrong in the portal.
            </button>
        </h2>
        <div id="faqLogin3" class="accordion-collapse collapse" data-bs-parent="#accordionLogin">
            <div class="accordion-body">
                <p>Your display name and avatar are pulled from your Microsoft 365 profile each time you sign in. To update them:</p>
                <ol>
                    <li>Update your name and photo in your <strong>Microsoft 365 account settings</strong>.</li>
                    <li><strong>Sign out</strong> of the portal.</li>
                    <li><strong>Sign back in</strong> -- the portal will fetch your updated profile information.</li>
                </ol>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqLogin4">
                Do I need to sign in every time I visit the portal?
            </button>
        </h2>
        <div id="faqLogin4" class="accordion-collapse collapse" data-bs-parent="#accordionLogin">
            <div class="accordion-body">
                <p>Your session lasts as long as your browser is open. The portal uses <strong>session cookies</strong> which expire when you close your browser. If you keep the browser open, you should remain signed in. If your session expires, you will be redirected to the login page automatically.</p>
            </div>
        </div>
    </div>

</div>

<!-- ================================================================== -->
<!-- Expenses -->
<!-- ================================================================== -->
<h2 class="h5 mt-4 mb-3" id="faq-expenses">
    <i class="fa-solid fa-receipt me-2 text-success"></i>Expenses
</h2>

<div class="accordion mb-4" id="accordionExpenses">

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqExp1">
                Can I edit or delete a submitted expense claim?
            </button>
        </h2>
        <div id="faqExp1" class="accordion-collapse collapse" data-bs-parent="#accordionExpenses">
            <div class="accordion-body">
                <p>No. Once a claim has been submitted, it cannot be edited or deleted by the claimant. This is by design to maintain an audit trail.</p>
                <p class="mb-0">If you made an error, contact your approver and ask them to <strong>reject</strong> the claim with a comment explaining the issue. You can then submit a corrected claim.</p>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqExp2">
                What file types can I upload as receipts?
            </button>
        </h2>
        <div id="faqExp2" class="accordion-collapse collapse" data-bs-parent="#accordionExpenses">
            <div class="accordion-body">
                <p>The upload field accepts:</p>
                <ul>
                    <li><strong>Images</strong> -- JPEG, PNG, and other common image formats</li>
                    <li><strong>PDF documents</strong></li>
                </ul>
                <p class="mb-0">Ensure your files are clear and legible. Blurry or cropped images may cause the claim to be rejected.</p>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqExp3">
                How long does it take for my claim to be approved?
            </button>
        </h2>
        <div id="faqExp3" class="accordion-collapse collapse" data-bs-parent="#accordionExpenses">
            <div class="accordion-body">
                <p>Approval times depend on your organisation's processes and the availability of your designated approver. The portal does not enforce any time limits on approvals. If your claim has been pending for an unusually long time, contact your approver directly or check with your department head.</p>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqExp4">
                What does each expense status mean?
            </button>
        </h2>
        <div id="faqExp4" class="accordion-collapse collapse" data-bs-parent="#accordionExpenses">
            <div class="accordion-body">
                <div class="list-group list-group-flush">
                    <div class="list-group-item d-flex align-items-center gap-3 px-0">
                        <span class="portal-badge portal-badge-pending"><i class="fa-solid fa-clock"></i> Pending</span>
                        <span>Submitted and awaiting approver review.</span>
                    </div>
                    <div class="list-group-item d-flex align-items-center gap-3 px-0">
                        <span class="portal-badge portal-badge-approved"><i class="fa-solid fa-check"></i> Approved</span>
                        <span>Accepted by the approver. Awaiting reimbursement from treasury.</span>
                    </div>
                    <div class="list-group-item d-flex align-items-center gap-3 px-0">
                        <span class="portal-badge portal-badge-rejected"><i class="fa-solid fa-xmark"></i> Rejected</span>
                        <span>Declined by the approver. Check comments for the reason.</span>
                    </div>
                    <div class="list-group-item d-flex align-items-center gap-3 px-0">
                        <span class="portal-badge portal-badge-paid"><i class="fa-solid fa-sterling-sign"></i> Reimbursed</span>
                        <span>Payment has been processed by treasury.</span>
                    </div>
                </div>
                <p class="mt-2 mb-0 small text-secondary">For more detail, see the <a href="/help/expenses#statuses">Expenses Guide -- Status Meanings</a>.</p>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqExp5">
                I need to claim for multiple receipts. Should I submit one claim or many?
            </button>
        </h2>
        <div id="faqExp5" class="accordion-collapse collapse" data-bs-parent="#accordionExpenses">
            <div class="accordion-body">
                <p>You can add <strong>multiple line items</strong> to a single claim. This is recommended when the receipts relate to the same trip, event, or project. Use the <strong>+ Add item</strong> button to add more rows to the items list.</p>
                <p class="mb-0">If the expenses are for entirely different purposes or departments, it is better to submit them as separate claims for clearer record-keeping.</p>
            </div>
        </div>
    </div>

</div>

<!-- ================================================================== -->
<!-- Approvals & Treasury -->
<!-- ================================================================== -->
<h2 class="h5 mt-4 mb-3" id="faq-approvals">
    <i class="fa-solid fa-check-double me-2 text-warning"></i>Approvals &amp; Treasury
</h2>

<div class="accordion mb-4" id="accordionApprovals">

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqAppr1">
                How do I know if I am an approver?
            </button>
        </h2>
        <div id="faqAppr1" class="accordion-collapse collapse" data-bs-parent="#accordionApprovals">
            <div class="accordion-body">
                <p>If you have been designated as an approver, you will see pending claims on the approval dashboard when you navigate to the Expenses approval page. If you do not have approver access, contact your administrator.</p>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqAppr2">
                Can I change my decision after approving or rejecting a claim?
            </button>
        </h2>
        <div id="faqAppr2" class="accordion-collapse collapse" data-bs-parent="#accordionApprovals">
            <div class="accordion-body">
                <p>Approval decisions are <strong>final</strong> and are recorded in the system audit log. If you need to reverse a decision, contact your system administrator who may be able to assist at the database level.</p>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqAppr3">
                When should I mark a claim as "Reimbursed" in treasury?
            </button>
        </h2>
        <div id="faqAppr3" class="accordion-collapse collapse" data-bs-parent="#accordionApprovals">
            <div class="accordion-body">
                <p>Only mark a claim as reimbursed <strong>after</strong> the payment has been successfully processed through your bank or payment system. Do not mark it as reimbursed in advance -- the status change is permanent and auditable.</p>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqAppr4">
                What should I enter as a payment reference?
            </button>
        </h2>
        <div id="faqAppr4" class="accordion-collapse collapse" data-bs-parent="#accordionApprovals">
            <div class="accordion-body">
                <p>Enter any unique identifier that links the portal record to your payment system, such as:</p>
                <ul>
                    <li>BACS transaction reference (e.g., <code>BACS-20260215-001</code>)</li>
                    <li>Bank transfer reference number</li>
                    <li>Cheque number</li>
                </ul>
                <p class="mb-0">Use a consistent format across all reimbursements for easier reconciliation.</p>
            </div>
        </div>
    </div>

</div>

<!-- ================================================================== -->
<!-- Portal & General -->
<!-- ================================================================== -->
<h2 class="h5 mt-4 mb-3" id="faq-portal">
    <i class="fa-solid fa-globe me-2 text-info"></i>Portal &amp; General
</h2>

<div class="accordion mb-4" id="accordionPortal">

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqPortal1">
                How do I switch to dark mode?
            </button>
        </h2>
        <div id="faqPortal1" class="accordion-collapse collapse" data-bs-parent="#accordionPortal">
            <div class="accordion-body">
                <p>Click the <i class="fa-solid fa-moon"></i> moon icon in the top navigation bar (near your avatar). The theme toggles between light and dark mode instantly. Your preference is saved in your browser's local storage and will persist across visits.</p>
                <p class="mb-0">See the <a href="/help/getting-started#dark-mode">Getting Started -- Dark Mode</a> section for more details.</p>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqPortal2">
                Does the portal work on mobile devices?
            </button>
        </h2>
        <div id="faqPortal2" class="accordion-collapse collapse" data-bs-parent="#accordionPortal">
            <div class="accordion-body">
                <p>Yes. The portal is built with a responsive design using Bootstrap 5. All pages automatically adapt to different screen sizes, including phones, tablets, and desktops.</p>
                <p class="mb-0">On mobile devices, the navigation bar collapses into a hamburger menu (<i class="fa-solid fa-bars"></i>), and data lists switch to a stacked card layout for easy reading.</p>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqPortal3">
                Which browsers are supported?
            </button>
        </h2>
        <div id="faqPortal3" class="accordion-collapse collapse" data-bs-parent="#accordionPortal">
            <div class="accordion-body">
                <p>The portal supports all modern browsers that are compatible with Bootstrap 5.3, including:</p>
                <ul>
                    <li>Google Chrome (latest)</li>
                    <li>Mozilla Firefox (latest)</li>
                    <li>Microsoft Edge (latest)</li>
                    <li>Apple Safari (latest)</li>
                </ul>
                <p class="mb-0">Internet Explorer is <strong>not</strong> supported.</p>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqPortal4">
                I see a 403 "Access Denied" error. What does this mean?
            </button>
        </h2>
        <div id="faqPortal4" class="accordion-collapse collapse" data-bs-parent="#accordionPortal">
            <div class="accordion-body">
                <p>A 403 error means you do not have permission to access the requested page. This can happen if:</p>
                <ul>
                    <li>You are trying to access the <strong>Settings</strong> page without admin privileges.</li>
                    <li>You are trying to access an <strong>alpha, beta, or dev</strong> site without the required role.</li>
                    <li>Your user account does not have the necessary role for that section.</li>
                </ul>
                <p class="mb-0">Contact your administrator to request the appropriate access level.</p>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqPortal5">
                How do I sign out of the portal?
            </button>
        </h2>
        <div id="faqPortal5" class="accordion-collapse collapse" data-bs-parent="#accordionPortal">
            <div class="accordion-body">
                <p>Click your <strong>avatar or name</strong> in the top-right corner of the navigation bar to open the user dropdown menu, then click <strong><i class="fa-solid fa-right-from-bracket me-1"></i>Sign Out</strong>.</p>
                <p class="mb-0">You will be redirected to the home page. Your session is fully destroyed and the session cookie is deleted.</p>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqPortal6">
                Who do I contact for support?
            </button>
        </h2>
        <div id="faqPortal6" class="accordion-collapse collapse" data-bs-parent="#accordionPortal">
            <div class="accordion-body">
                <p class="mb-0">For any issues not covered in this FAQ or the help guides, please contact your system administrator or raise a support request through your organisation's IT helpdesk.</p>
            </div>
        </div>
    </div>

</div>

<!-- Still need help -->
<div class="card mt-4 mb-4 border-0 bg-body-tertiary">
    <div class="card-body text-center py-4">
        <i class="fa-solid fa-headset fa-2x text-secondary mb-3"></i>
        <h5>Didn't find your answer?</h5>
        <p class="text-secondary mb-2">Try the detailed guides for step-by-step instructions:</p>
        <div class="d-flex flex-wrap justify-content-center gap-2">
            <a href="/help/getting-started" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-rocket me-1"></i>Getting Started</a>
            <a href="/help/expenses" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-receipt me-1"></i>Expenses</a>
            <a href="/help/approvals" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-check-double me-1"></i>Approvals</a>
            <a href="/help/treasury" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-sterling-sign me-1"></i>Treasury</a>
            <a href="/help/admin" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-gear me-1"></i>Admin</a>
        </div>
    </div>
</div>

<!-- Navigation -->
<div class="d-flex justify-content-between">
    <a href="/help/admin" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i>Admin Guide
    </a>
    <a href="/help" class="btn btn-primary">
        <i class="fa-solid fa-house me-1"></i>Help Centre Home
    </a>
</div>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
