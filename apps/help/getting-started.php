<?php
// Path: apps/help/getting-started.php
/**
 * -----------------------------------------------------------------------------
 * Help Centre -- Getting Started Guide
 * -----------------------------------------------------------------------------
 * Covers logging in with Microsoft 365 SSO, first-time setup, navigating the
 * portal (dashboard, sidebar, breadcrumbs), and the dark mode toggle.
 * -----------------------------------------------------------------------------
 * @package    Portal\Help
 * @license    MIT
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$pageTitle   = 'Help - Getting Started';
$pageSection = 'help';
$breadcrumbs = ['Dashboard' => '/', 'Help' => '/help', 'Getting Started' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- Getting Started Guide -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-rocket me-2"></i>Getting Started</h1>
        <p class="text-secondary mb-0">Everything you need to know to start using the portal.</p>
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
            <a href="#logging-in" class="badge text-bg-secondary text-decoration-none">Logging In</a>
            <a href="#first-time" class="badge text-bg-secondary text-decoration-none">First-Time Setup</a>
            <a href="#navigating" class="badge text-bg-secondary text-decoration-none">Navigating the Portal</a>
            <a href="#dark-mode" class="badge text-bg-secondary text-decoration-none">Dark Mode</a>
        </div>
    </div>
</div>

<!-- Section 1: Logging In -->
<div class="portal-card p-4 mb-4" id="logging-in">
    <h2 class="h4 mb-3"><i class="fa-solid fa-right-to-bracket me-2 text-primary"></i>Logging In</h2>

    <p>The portal supports two ways to sign in. Most users will use the Microsoft 365 SSO button for a seamless, password-free experience.</p>

    <h5 class="mt-4 mb-3">Option A: Microsoft 365 Single Sign-On (recommended)</h5>

    <div class="list-group list-group-flush mb-3">
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">1</span>
            <div>
                <strong>Open the portal</strong>
                <p class="mb-0 small text-secondary">Navigate to the portal URL in your browser. You will be presented with the sign-in page.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">2</span>
            <div>
                <strong>Click "Sign in with Microsoft 365"</strong>
                <p class="mb-0 small text-secondary">
                    The blue <span class="badge text-bg-primary"><i class="fa-brands fa-microsoft me-1"></i>Sign in with Microsoft 365</span> button will redirect you to Microsoft's login page.
                </p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">3</span>
            <div>
                <strong>Authenticate with your organisation account</strong>
                <p class="mb-0 small text-secondary">Enter your organisational email and password (or approve a multi-factor authentication prompt if enabled by your IT team).</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">4</span>
            <div>
                <strong>You are signed in</strong>
                <p class="mb-0 small text-secondary">You will be redirected back to the portal dashboard automatically. Your name and avatar will appear in the navigation bar.</p>
            </div>
        </div>
    </div>

    <div class="alert alert-info d-flex gap-2 mb-3" role="alert">
        <i class="fa-solid fa-circle-info mt-1"></i>
        <div>
            <strong>Tip:</strong> If you are already signed in to Microsoft 365 in your browser, you may be logged in automatically without needing to re-enter your credentials.
        </div>
    </div>

    <h5 class="mt-4 mb-3">Option B: Local account login</h5>
    <p>If you have been issued a local username and password (separate from Microsoft 365), you can use the form below the SSO button.</p>

    <div class="list-group list-group-flush mb-3">
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-success rounded-pill mt-1">1</span>
            <div>Enter your <strong>username or email</strong> and <strong>password</strong> in the fields provided.</div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-success rounded-pill mt-1">2</span>
            <div>Complete any <strong>CAPTCHA</strong> challenge if one is displayed.</div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-success rounded-pill mt-1">3</span>
            <div>Click the <strong>Login</strong> button.</div>
        </div>
    </div>

    <div class="alert alert-warning d-flex gap-2" role="alert">
        <i class="fa-solid fa-triangle-exclamation mt-1"></i>
        <div>
            <strong>Lockout protection:</strong> After several failed login attempts, your account will be temporarily locked for security. Wait a few minutes and try again, or contact your administrator.
        </div>
    </div>
</div>

<!-- Section 2: First-Time Setup -->
<div class="portal-card p-4 mb-4" id="first-time">
    <h2 class="h4 mb-3"><i class="fa-solid fa-wand-magic-sparkles me-2 text-primary"></i>First-Time Setup</h2>

    <p>There is no manual setup required. When you sign in for the first time using Microsoft 365, the portal automatically creates your account using information from your Microsoft profile:</p>

    <ul class="list-group list-group-flush mb-3">
        <li class="list-group-item"><i class="fa-solid fa-user me-2 text-primary"></i><strong>Display name</strong> -- pulled from your Microsoft 365 profile</li>
        <li class="list-group-item"><i class="fa-solid fa-envelope me-2 text-primary"></i><strong>Email address</strong> -- your organisational email</li>
        <li class="list-group-item"><i class="fa-solid fa-image me-2 text-primary"></i><strong>Avatar</strong> -- your Microsoft profile picture (if available)</li>
    </ul>

    <div class="alert alert-info d-flex gap-2" role="alert">
        <i class="fa-solid fa-circle-info mt-1"></i>
        <div>
            <strong>Tip:</strong> If your name or avatar is incorrect, update your Microsoft 365 profile. The changes will be reflected in the portal the next time you sign in.
        </div>
    </div>
</div>

<!-- Section 3: Navigating the Portal -->
<div class="portal-card p-4 mb-4" id="navigating">
    <h2 class="h4 mb-3"><i class="fa-solid fa-compass me-2 text-primary"></i>Navigating the Portal</h2>

    <p>The portal uses a consistent layout with a top navigation bar, breadcrumb trail, and a content area. Here is what each part does:</p>

    <div class="accordion mb-3" id="navAccordion">

        <!-- Dashboard -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#navDashboard" aria-expanded="true">
                    <i class="fa-solid fa-house-chimney me-2"></i>Dashboard
                </button>
            </h2>
            <div id="navDashboard" class="accordion-collapse collapse show" data-bs-parent="#navAccordion">
                <div class="accordion-body">
                    <p class="mb-1">The <strong>Dashboard</strong> is your home page. It displays a grid of application cards for every app that is enabled for your portal (e.g., Expenses). Click any card to open that application.</p>
                    <p class="mb-0 small text-secondary">You can always return to the dashboard by clicking the portal logo or brand name in the top-left corner of the navigation bar.</p>
                </div>
            </div>
        </div>

        <!-- Navigation Bar -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#navNavbar">
                    <i class="fa-solid fa-bars me-2"></i>Navigation Bar
                </button>
            </h2>
            <div id="navNavbar" class="accordion-collapse collapse" data-bs-parent="#navAccordion">
                <div class="accordion-body">
                    <p class="mb-1">The top navigation bar contains links to all enabled applications, plus a <strong>Settings</strong> link for administrators. On mobile devices, tap the hamburger menu icon (<i class="fa-solid fa-bars"></i>) to expand the navigation.</p>
                    <p class="mb-0 small text-secondary">The active page is highlighted with bold text so you always know where you are.</p>
                </div>
            </div>
        </div>

        <!-- Breadcrumbs -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#navBreadcrumbs">
                    <i class="fa-solid fa-angles-right me-2"></i>Breadcrumbs
                </button>
            </h2>
            <div id="navBreadcrumbs" class="accordion-collapse collapse" data-bs-parent="#navAccordion">
                <div class="accordion-body">
                    <p class="mb-1">Below the navigation bar, a <strong>breadcrumb trail</strong> shows your current location in the portal hierarchy (e.g., <code>Dashboard &gt; Expenses &gt; Submit Claim</code>).</p>
                    <p class="mb-0 small text-secondary">Click any earlier breadcrumb to navigate back to that level.</p>
                </div>
            </div>
        </div>

        <!-- User Menu -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#navUserMenu">
                    <i class="fa-solid fa-circle-user me-2"></i>User Menu
                </button>
            </h2>
            <div id="navUserMenu" class="accordion-collapse collapse" data-bs-parent="#navAccordion">
                <div class="accordion-body">
                    <p class="mb-1">In the top-right corner you will see your <strong>avatar and name</strong>. Click to open a dropdown showing your email and a <strong>Sign Out</strong> option.</p>
                    <p class="mb-0 small text-secondary">On smaller screens, your name is hidden but your avatar remains visible.</p>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Section 4: Dark Mode -->
<div class="portal-card p-4 mb-4" id="dark-mode">
    <h2 class="h4 mb-3"><i class="fa-solid fa-moon me-2 text-primary"></i>Dark Mode</h2>

    <p>The portal includes a built-in dark mode for comfortable viewing in low-light environments.</p>

    <h5 class="mt-3 mb-3">How to toggle dark mode</h5>

    <div class="list-group list-group-flush mb-3">
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">1</span>
            <div>
                <strong>Locate the moon icon</strong>
                <p class="mb-0 small text-secondary">Look for the <i class="fa-solid fa-moon"></i> button in the navigation bar (top-right area, near your avatar).</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">2</span>
            <div>
                <strong>Click the toggle</strong>
                <p class="mb-0 small text-secondary">The theme switches instantly between light and dark modes. No page reload is required.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">3</span>
            <div>
                <strong>Your preference is saved</strong>
                <p class="mb-0 small text-secondary">The portal remembers your choice in your browser's local storage. It will be applied automatically on your next visit.</p>
            </div>
        </div>
    </div>

    <div class="alert alert-info d-flex gap-2" role="alert">
        <i class="fa-solid fa-circle-info mt-1"></i>
        <div>
            <strong>Note:</strong> Dark mode is per-browser. If you use multiple browsers or devices, you will need to set it on each one separately.
        </div>
    </div>
</div>

<!-- Navigation -->
<div class="d-flex justify-content-between">
    <a href="/help" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i>Help Centre
    </a>
    <a href="/help/expenses" class="btn btn-primary">
        Expenses Guide<i class="fa-solid fa-arrow-right ms-1"></i>
    </a>
</div>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
