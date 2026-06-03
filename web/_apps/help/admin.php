<?php
// Path: apps/help/admin.php
/**
 * -----------------------------------------------------------------------------
 * Help Centre -- Admin Guide
 * -----------------------------------------------------------------------------
 * Guide for portal administrators: managing settings, user roles, Gatekeeper
 * (dev/alpha/beta site access), and viewing system logs.
 * -----------------------------------------------------------------------------
 * @package    Portal\Help
 * @license   All Rights Reserved
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$pageTitle   = 'Help - Admin Guide';
$pageSection = 'help';
$breadcrumbs = ['Dashboard' => '/', 'Help' => '/help', 'Admin Guide' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- Admin Guide -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-gear me-2"></i>Admin Guide</h1>
        <p class="text-secondary mb-0">For administrators: managing settings, users, access control, and logs.</p>
    </div>
    <a href="/help" class="btn btn-outline-secondary btn-sm mt-2 mt-md-0">
        <i class="fa-solid fa-arrow-left me-1"></i>Back to Help Centre
    </a>
</div>

<!-- Who is this for? -->
<div class="alert alert-danger d-flex gap-2 mb-4" role="alert">
    <i class="fa-solid fa-shield-halved mt-1"></i>
    <div>
        <strong>Admin access required.</strong> The features described in this guide are only available to users with the <strong>Admin</strong> or <strong>Root Admin</strong> role. If you do not have admin access, you will see a 403 Access Denied page when attempting to visit the Settings area.
    </div>
</div>

<!-- Table of contents -->
<div class="card mb-4 border-0 bg-body-tertiary">
    <div class="card-body">
        <h6 class="card-title mb-2"><i class="fa-solid fa-list me-1"></i>On this page</h6>
        <div class="d-flex flex-wrap gap-2">
            <a href="#settings" class="badge text-bg-secondary text-decoration-none">Settings Management</a>
            <a href="#site-branding" class="badge text-bg-secondary text-decoration-none">Site Branding</a>
            <a href="#roles" class="badge text-bg-secondary text-decoration-none">User Roles</a>
            <a href="#gatekeeper" class="badge text-bg-secondary text-decoration-none">Dev Site Access (Gatekeeper)</a>
            <a href="#logs" class="badge text-bg-secondary text-decoration-none">Viewing Logs</a>
            <a href="#csv-export" class="badge text-bg-secondary text-decoration-none">CSV Export</a>
            <a href="#developer" class="badge text-bg-secondary text-decoration-none">Developer Tools</a>
        </div>
    </div>
</div>

<!-- Section 1: Settings Management -->
<div class="portal-card p-4 mb-4" id="settings">
    <h2 class="h4 mb-3"><i class="fa-solid fa-sliders me-2 text-primary"></i>Settings Management</h2>

    <p>The Settings page allows administrators to view and edit all portal configuration values stored in the database.</p>

    <h5 class="mt-3 mb-3">Accessing Settings</h5>

    <div class="list-group list-group-flush mb-3">
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">1</span>
            <div>
                <strong>Click "Settings" in the navigation bar</strong>
                <p class="mb-0 small text-secondary">The <i class="fa-solid fa-gear"></i> Settings link appears in the top navigation bar only for admin users.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">2</span>
            <div>
                <strong>Browse the settings list</strong>
                <p class="mb-0 small text-secondary">Settings are displayed in a responsive list showing the <strong>Key</strong>, <strong>Value</strong>, <strong>Last Updated</strong> date, and an <strong>Edit</strong> button.</p>
            </div>
        </div>
    </div>

    <h5 class="mt-4 mb-3">Editing a setting</h5>

    <div class="list-group list-group-flush mb-3">
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">1</span>
            <div>
                <strong>Click the "Edit" button</strong> next to the setting you want to change. A modal dialog will open.
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">2</span>
            <div>
                <strong>Modify the value</strong> in the text area. The key is read-only and cannot be changed.
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">3</span>
            <div>
                <strong>Click "Save changes"</strong> to apply. The page will reload with the updated value.
            </div>
        </div>
    </div>

    <h5 class="mt-4 mb-3">Adding a new setting</h5>

    <div class="list-group list-group-flush mb-3">
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-success rounded-pill mt-1">1</span>
            <div>
                <strong>Click the green "Add Setting" button</strong> below the settings list.
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-success rounded-pill mt-1">2</span>
            <div>
                <strong>Enter the Key</strong> using dot-notation (e.g., <code>site.name</code>, <code>expenses.enabled</code>).
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-success rounded-pill mt-1">3</span>
            <div>
                <strong>Enter the Value</strong> and optionally tick the <strong>"Sensitive"</strong> checkbox for values that should be encrypted (e.g., API keys, client secrets).
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-success rounded-pill mt-1">4</span>
            <div>
                <strong>Click "Add Setting"</strong> to save.
            </div>
        </div>
    </div>

    <div class="alert alert-warning d-flex gap-2" role="alert">
        <i class="fa-solid fa-triangle-exclamation mt-1"></i>
        <div>
            <strong>Caution:</strong> Changing settings can affect the behaviour of the entire portal. Sensitive values (marked with <code>isSensitive</code>) are masked in the settings list for security. Only Root Admins should modify authentication and OAuth settings.
        </div>
    </div>

    <h5 class="mt-4 mb-3">Common setting keys</h5>

    <div class="list-group list-group-flush">
        <div class="list-group-item d-flex gap-2">
            <code class="text-nowrap">site.name</code>
            <span class="text-secondary">-- The portal name shown in the browser tab and navigation bar.</span>
        </div>
        <div class="list-group-item d-flex gap-2">
            <code class="text-nowrap">site.copyrightOrg</code>
            <span class="text-secondary">-- Organisation name shown in the footer copyright notice.</span>
        </div>
        <div class="list-group-item d-flex gap-2">
            <code class="text-nowrap">expenses.enabled</code>
            <span class="text-secondary">-- Set to <code>true</code> to enable the Expenses app in navigation.</span>
        </div>
        <div class="list-group-item d-flex gap-2">
            <code class="text-nowrap">expenses.displayName</code>
            <span class="text-secondary">-- The label shown in the nav bar for the Expenses app.</span>
        </div>
        <div class="list-group-item d-flex gap-2">
            <code class="text-nowrap">expenses.displayIcon</code>
            <span class="text-secondary">-- The Font Awesome icon class used for the app (e.g., <code>fa-solid fa-receipt</code>).</span>
        </div>
    </div>
</div>

<!-- Section: Site Branding -->
<div class="portal-card p-4 mb-4" id="site-branding">
    <h2 class="h4 mb-3"><i class="fa-solid fa-palette me-2 text-primary"></i>Site Branding</h2>

    <p>Each site in this install can have its own visual identity. Branding values are configured at <a href="/admin/sites">/admin/sites/</a> by umbrella admins.</p>

    <h5 class="mt-3 mb-3">What you can customise per site</h5>

    <div class="list-group list-group-flush mb-3">
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1"><i class="fa-solid fa-signature"></i></span>
            <div>
                <strong>Site name</strong>
                <p class="mb-0 small text-secondary">Display name in the navbar, browser tab title, and footer. Required.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1"><i class="fa-solid fa-image"></i></span>
            <div>
                <strong>Logo path</strong>
                <p class="mb-0 small text-secondary">URL or path to the navbar logo. Default <code>/assets/images/logo.svg</code> shows the WebMS Intra mark.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1"><i class="fa-solid fa-droplet"></i></span>
            <div>
                <strong>Primary colour</strong>
                <p class="mb-0 small text-secondary">Hex colour for buttons, focus rings, active nav links, app card hover, and other accents. Default <code>#5e6ad2</code> (indigo). Hover and active variants auto-derive from this colour.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1"><i class="fa-solid fa-star"></i></span>
            <div>
                <strong>Favicon path</strong>
                <p class="mb-0 small text-secondary">URL or path to the browser-tab icon. Leave blank to use the WebMS Intra default.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1"><i class="fa-solid fa-copyright"></i></span>
            <div>
                <strong>Copyright organisation</strong>
                <p class="mb-0 small text-secondary">Name shown in the footer copyright line. Defaults to "MWBM Partners Ltd" if blank.</p>
            </div>
        </div>
    </div>

    <h5 class="mt-4 mb-3">"Powered by WebMS Intra" attribution</h5>

    <p>When a site uses <strong>custom branding</strong> (any of the fields above differs from the WebMS Intra default), the footer shows a small "Powered by WebMS Intra" attribution after the copyright line. A <code>&lt;meta name="generator"&gt;</code> tag is also added to the page <code>&lt;head&gt;</code> for site analysers.</p>

    <p>Sites still running the default WebMS Intra branding do <em>not</em> show the attribution &mdash; the copyright line already names the product.</p>

    <h6 class="mt-3 mb-2">Hiding the attribution</h6>

    <p>To hide the "Powered by" attribution across all sites in this install, set the global setting <code>branding.hidePoweredBy</code> to <code>true</code> at <a href="/settings">/settings/</a>. Default is <code>false</code> (show attribution on custom-branded sites).</p>

    <div class="alert alert-info d-flex gap-2 mt-3" role="alert">
        <i class="fa-solid fa-circle-info mt-1"></i>
        <div>
            <strong>Tip:</strong> Branding changes apply immediately on the next page load &mdash; no deploy or cache clear needed. The hover/active/subtle colour variants automatically shift with the primary colour in modern browsers (Chrome 111+, Safari 16.2+, Firefox 113+); older browsers fall back to the indigo defaults.
        </div>
    </div>
</div>

<!-- Section: Bot Protection / Captcha -->
<div class="portal-card p-4 mb-4" id="captcha">
    <h2 class="h4 mb-3"><i class="fa-solid fa-robot me-2 text-primary"></i>Bot Protection (Captcha)</h2>

    <p>
        The portal supports three captcha providers, configured at
        <a href="/admin/captcha">/admin/captcha</a>:
    </p>
    <ul>
        <li><strong>Cloudflare Turnstile</strong> — privacy-friendly default, no challenge for most users.</li>
        <li><strong>Google reCAPTCHA</strong> — v2 (visible checkbox) or v3 (invisible, score-based). Choose via the version dropdown.</li>
        <li><strong>hCaptcha</strong> — privacy-friendly alternative to reCAPTCHA.</li>
    </ul>

    <h5 class="mt-3 mb-2">Priority ordering</h5>
    <p>
        Drag the providers in the priority list to set the fallback order. The active provider is the
        <strong>first one in the list that has both site and secret keys configured</strong>. If nothing is
        configured, the captcha is silently skipped (forms still submit; no challenge shown).
    </p>

    <h5 class="mt-3 mb-2">reCAPTCHA v3 specifics</h5>
    <p>
        When using reCAPTCHA v3, you can set an <strong>action name</strong> (default: <code>submit</code>)
        and a <strong>score threshold</strong> (default: <code>0.5</code>). Server-side verification rejects
        any token whose action doesn't match (anti-replay) or whose score falls below the threshold.
    </p>
    <p class="text-muted small">
        Anonymous prayer-request submissions and the password-reset flow both use the active captcha provider — no per-form configuration needed.
    </p>
</div>

<!-- Section: Password Policy -->
<div class="portal-card p-4 mb-4" id="password-policy">
    <h2 class="h4 mb-3"><i class="fa-solid fa-shield-halved me-2 text-primary"></i>Password Policy</h2>

    <p>
        Configurable via <a href="/settings">Settings</a> under the <code>auth.password.*</code> prefix.
        Defaults follow OWASP ASVS L1.
    </p>

    <div class="list-group list-group-flush">
        <div class="list-group-item d-flex justify-content-between"><span><code>auth.password.minLength</code></span><strong>12</strong></div>
        <div class="list-group-item d-flex justify-content-between"><span><code>auth.password.maxLength</code></span><strong>128</strong></div>
        <div class="list-group-item d-flex justify-content-between"><span><code>auth.password.requireUppercase</code></span><strong>true</strong></div>
        <div class="list-group-item d-flex justify-content-between"><span><code>auth.password.requireLowercase</code></span><strong>true</strong></div>
        <div class="list-group-item d-flex justify-content-between"><span><code>auth.password.requireNumber</code></span><strong>true</strong></div>
        <div class="list-group-item d-flex justify-content-between"><span><code>auth.password.requireSpecial</code></span><strong>true</strong></div>
    </div>

    <p class="mt-3 mb-0 text-muted small">
        The policy is enforced server-side on every password-set flow: account change-password, password reset,
        admin user create / update, and the installation wizard. A client-side strength meter (5-step Bootstrap
        progress bar) appears on every password input as a visual aid; final validation always happens
        server-side.
    </p>
</div>

<!-- Section 2: User Roles -->
<div class="portal-card p-4 mb-4" id="roles">
    <h2 class="h4 mb-3"><i class="fa-solid fa-users-gear me-2 text-primary"></i>User Roles</h2>

    <p>The portal uses a role-based access control system. Each user can have one or more roles that determine what they can access.</p>

    <h5 class="mt-3 mb-3">Built-in role levels</h5>

    <div class="list-group list-group-flush mb-3">
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-secondary rounded-pill mt-1"><i class="fa-solid fa-user"></i></span>
            <div>
                <strong>Standard User</strong>
                <p class="mb-0 small text-secondary">Can access the dashboard and any enabled apps. Can submit expense claims and view their own claims.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-warning rounded-pill mt-1"><i class="fa-solid fa-user-check"></i></span>
            <div>
                <strong>Approver</strong>
                <p class="mb-0 small text-secondary">Can review and approve/reject expense claims in addition to standard user permissions.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-info rounded-pill mt-1"><i class="fa-solid fa-building-columns"></i></span>
            <div>
                <strong>Treasury</strong>
                <p class="mb-0 small text-secondary">Can access the treasury dashboard and record reimbursements for approved claims.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-danger rounded-pill mt-1"><i class="fa-solid fa-user-shield"></i></span>
            <div>
                <strong>Admin</strong>
                <p class="mb-0 small text-secondary">Can access the Settings page and manage portal configuration. Has access to alpha/beta/dev sites via the Gatekeeper.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-dark rounded-pill mt-1"><i class="fa-solid fa-crown"></i></span>
            <div>
                <strong>Root Admin</strong>
                <p class="mb-0 small text-secondary">Full access to all features including sensitive settings. This is the highest privilege level and should be limited to system maintainers only.</p>
            </div>
        </div>
    </div>

    <h5 class="mt-4 mb-3">How roles are assigned</h5>

    <p>Roles are stored in the <code>tblUserRoles</code> table and linked to role definitions in <code>tblRoles</code>. The Admin and Root Admin flags are stored directly on the user record (<code>isAdmin</code>, <code>isRootAdmin</code> columns in <code>tblUsers</code>).</p>

    <div class="alert alert-info d-flex gap-2" role="alert">
        <i class="fa-solid fa-circle-info mt-1"></i>
        <div>
            <strong>Note:</strong> Role assignment is currently managed at the database level. Contact your system administrator to change a user's role.
        </div>
    </div>

    <h5 class="mt-4 mb-3">User management list</h5>

    <p>The user management page now includes <strong>pagination</strong> and a <strong>search bar</strong> for easier navigation of large user lists. Use the search field to filter users by name or email, and use the page controls at the bottom to browse through results.</p>
</div>

<!-- Section 3: Gatekeeper (Dev Site Access) -->
<div class="portal-card p-4 mb-4" id="gatekeeper">
    <h2 class="h4 mb-3"><i class="fa-solid fa-door-open me-2 text-primary"></i>Dev Site Access (Gatekeeper)</h2>

    <p>The portal supports multiple deployment channels: <strong>production</strong>, <strong>beta</strong>, <strong>alpha</strong>, and <strong>dev</strong>. The Gatekeeper system restricts access to non-production channels.</p>

    <h5 class="mt-3 mb-3">How the Gatekeeper works</h5>

    <ul class="list-group list-group-flush mb-3">
        <li class="list-group-item d-flex gap-2">
            <i class="fa-solid fa-lock-open text-success mt-1"></i>
            <div>
                <strong>Production site:</strong> No gatekeeper restrictions. All authenticated users can access it.
            </div>
        </li>
        <li class="list-group-item d-flex gap-2">
            <i class="fa-solid fa-lock text-warning mt-1"></i>
            <div>
                <strong>Alpha / Beta / Dev sites:</strong> The Gatekeeper checks whether the user has permission before allowing access. By default, only <strong>Admin</strong> and <strong>Root Admin</strong> users are allowed.
            </div>
        </li>
    </ul>

    <h5 class="mt-4 mb-3">Granting access to additional users</h5>

    <p>You can allow non-admin users to access alpha or beta sites by adding their roles to the relevant settings:</p>

    <div class="list-group list-group-flush mb-3">
        <div class="list-group-item d-flex gap-2">
            <code class="text-nowrap">portal.alphaAccessRoles</code>
            <span class="text-secondary">-- Comma-separated role keys that can access the alpha site (e.g., <code>Admin,Developer</code>).</span>
        </div>
        <div class="list-group-item d-flex gap-2">
            <code class="text-nowrap">portal.betaAccessRoles</code>
            <span class="text-secondary">-- Comma-separated role keys that can access the beta site (e.g., <code>Admin,Tester</code>).</span>
        </div>
    </div>

    <div class="alert alert-warning d-flex gap-2" role="alert">
        <i class="fa-solid fa-triangle-exclamation mt-1"></i>
        <div>
            <strong>Security note:</strong> Dev and alpha sites may contain unstable or experimental features. Only grant access to users who understand the risks. Access denials are logged for audit purposes.
        </div>
    </div>

    <h5 class="mt-4 mb-3">What happens when access is denied</h5>

    <p>If a user attempts to access a gated channel without the required role, the system:</p>

    <div class="list-group list-group-flush">
        <div class="list-group-item d-flex gap-2">
            <span class="badge text-bg-danger rounded-pill mt-1">1</span>
            <div>Logs the denied access attempt (event type: <code>GatekeeperDenied</code>).</div>
        </div>
        <div class="list-group-item d-flex gap-2">
            <span class="badge text-bg-danger rounded-pill mt-1">2</span>
            <div>Displays the <strong>403 Access Denied</strong> error page.</div>
        </div>
    </div>
</div>

<!-- Section 4: Viewing Logs -->
<div class="portal-card p-4 mb-4" id="logs">
    <h2 class="h4 mb-3"><i class="fa-solid fa-clipboard-list me-2 text-primary"></i>Viewing Logs</h2>

    <p>The portal maintains two types of logs for monitoring and debugging:</p>

    <div class="row g-4 mb-3">
        <div class="col-12 col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title"><i class="fa-solid fa-person-walking me-1 text-primary"></i>Activity Logs</h5>
                    <p class="small text-secondary">Track user actions within the portal, such as:</p>
                    <ul class="small text-secondary mb-0">
                        <li>Login and logout events (LoginMS365, LoginLocal, Logout)</li>
                        <li>Failed login attempts (LoginFailed, LoginBlocked)</li>
                        <li>Expense submissions, approvals, and rejections</li>
                        <li>Gatekeeper access denials</li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title"><i class="fa-solid fa-bug me-1 text-danger"></i>Error Logs</h5>
                    <p class="small text-secondary">Track system errors and platform issues, such as:</p>
                    <ul class="small text-secondary mb-0">
                        <li>Database connection or query failures</li>
                        <li>OAuth/JWT verification errors</li>
                        <li>cURL request failures</li>
                        <li>File not found errors (Router target files)</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <h5 class="mt-3 mb-3">Debug panel</h5>

    <p>Admin users can enable the <strong>debug panel</strong> by appending <code>?debug=true</code> to any portal page URL. This displays a panel at the bottom of the page with useful diagnostic information such as request timing, loaded settings, and the current user session data.</p>

    <div class="alert alert-info d-flex gap-2" role="alert">
        <i class="fa-solid fa-circle-info mt-1"></i>
        <div>
            <strong>Tip:</strong> The debug panel is only visible to admin users for security. It will not appear for standard users even if the query parameter is present.
        </div>
    </div>
</div>

<!-- Section 5: CSV Export -->
<div class="portal-card p-4 mb-4" id="csv-export">
    <h2 class="h4 mb-3"><i class="fa-solid fa-file-csv me-2 text-primary"></i>CSV Export</h2>

    <p>Several areas of the admin interface now include <strong>CSV export</strong> buttons, allowing you to download data for reporting or record-keeping purposes.</p>

    <h5 class="mt-3 mb-3">Where CSV export is available</h5>

    <ul class="list-group list-group-flush mb-3">
        <li class="list-group-item d-flex gap-2">
            <i class="fa-solid fa-users text-primary mt-1"></i>
            <div>
                <strong>User management:</strong> Export the full list of portal users including their roles and status.
            </div>
        </li>
        <li class="list-group-item d-flex gap-2">
            <i class="fa-solid fa-clipboard-list text-primary mt-1"></i>
            <div>
                <strong>Activity and error logs:</strong> Export log entries for auditing or analysis.
            </div>
        </li>
        <li class="list-group-item d-flex gap-2">
            <i class="fa-solid fa-receipt text-primary mt-1"></i>
            <div>
                <strong>Expenses and treasury:</strong> Export expense claims and payment records.
            </div>
        </li>
    </ul>

    <p>To export, click the <span class="badge text-bg-success"><i class="fa-solid fa-file-csv me-1"></i>Export CSV</span> button located near the top of the relevant list. The file will download to your browser's default downloads folder.</p>
</div>

<!-- Section 6: Developer Tools -->
<div class="portal-card p-4 mb-4" id="developer">
    <h2 class="h4 mb-3"><i class="fa-solid fa-code me-2 text-primary"></i>Developer Tools</h2>

    <p>The portal framework includes utility classes that developers and advanced administrators should be aware of:</p>

    <ul class="list-group list-group-flush mb-3">
        <li class="list-group-item d-flex gap-2">
            <i class="fa-solid fa-box text-info mt-1"></i>
            <div>
                <strong>Container class:</strong> A lightweight dependency injection container used by the framework to manage service instances and shared resources across the application.
            </div>
        </li>
        <li class="list-group-item d-flex gap-2">
            <i class="fa-solid fa-check-double text-info mt-1"></i>
            <div>
                <strong>Validator class:</strong> A reusable input validation helper that provides common validation rules (required fields, email format, numeric ranges, etc.) used by forms throughout the portal.
            </div>
        </li>
    </ul>

    <div class="alert alert-info d-flex gap-2" role="alert">
        <i class="fa-solid fa-circle-info mt-1"></i>
        <div>
            <strong>Note:</strong> These classes are part of the <code>Portal\Core</code> namespace and are available for use when developing new portal apps or extending existing functionality.
        </div>
    </div>
</div>

<!-- Navigation -->
<div class="d-flex justify-content-between">
    <a href="/help/treasury" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i>Treasury Guide
    </a>
    <a href="/help/faq" class="btn btn-primary">
        FAQ<i class="fa-solid fa-arrow-right ms-1"></i>
    </a>
</div>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
