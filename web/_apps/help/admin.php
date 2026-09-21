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

use Portal\Core\Roles;

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
            <a href="#ms365-shared-mailbox" class="badge text-bg-secondary text-decoration-none">MS365 Shared Mailbox</a>
            <a href="#server-info" class="badge text-bg-secondary text-decoration-none">Server Information</a>
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

    <div class="alert alert-warning d-flex align-items-start gap-2">
        <i class="fa-solid fa-triangle-exclamation mt-1"></i>
        <div>
            <strong>Check your own minimum length before relying on the figure below.</strong>
            The values here are what a newly installed portal uses. On a portal
            that was upgraded rather than freshly installed, the minimum length
            may still be sitting at <strong>8</strong>.
            <br><br>
            The reason: the change that raised it from 8 to 12 could only alter
            the recorded <em>default</em>, so as not to overwrite a length an
            administrator had deliberately chosen. A separate fault meant an
            older copy of the setting kept winning, so the working value stayed
            at 8 while everything else said 12. That fault is fixed, but the
            value itself was left alone on purpose, because there is no way to
            tell a leftover from a deliberate choice.
            <br><br>
            Look at <code>auth.password.minLength</code> in
            <a href="/settings">Settings</a>. If it says 8 and you did not
            choose that, change it to 12.
        </div>
    </div>

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

    <p>
        The portal uses a role-based access control system. Administrator and Root Administrator are given
        by a checkbox on the account itself, and reach the whole portal. Every other role is an <strong>access
        role, held per organisation</strong> (since #516) &mdash; a person can be Treasurer of one organisation
        without being Treasurer of another they also belong to.
    </p>

    <h5 class="mt-3 mb-3">Standard User, Admin and Root Admin</h5>

    <div class="list-group list-group-flush mb-3">
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-secondary rounded-pill mt-1"><i class="fa-solid fa-user"></i></span>
            <div>
                <strong>Standard User</strong>
                <p class="mb-0 small text-secondary">Can access the dashboard and any enabled apps. Can submit expense claims and view their own claims.</p>
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

    <h5 class="mt-4 mb-3">The standard access roles every organisation starts with</h5>

    <p>
        Every organisation gets the same starting set of roles, listed below straight from the code that
        defines them &mdash; so this page can never drift out of date with what the portal actually offers.
        An organisation may rename any of these to suit its own language (Admin &rarr; Roles), and may add
        roles of its own; the description below always describes what the role is FOR, whatever it is
        currently called.
    </p>

    <div class="list-group list-group-flush mb-3">
        <?php foreach (Roles::STANDARD as $roleKey => $role): ?>
            <div class="list-group-item d-flex gap-3 align-items-start">
                <span class="badge text-bg-info rounded-pill mt-1"><i class="fa-solid fa-user-tag"></i></span>
                <div>
                    <strong><?php echo htmlspecialchars($role['label'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <code class="ms-1 small text-secondary"><?php echo htmlspecialchars($roleKey, ENT_QUOTES, 'UTF-8'); ?></code>
                    <p class="mb-0 small text-secondary"><?php echo htmlspecialchars($role['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <h5 class="mt-4 mb-3">How roles are assigned</h5>

    <p>
        A role is held per organisation, in the <code>tblUserRoles</code> table, linked to that
        organisation's own copy of the role in <code>tblRoles</code>. Grant or remove a role for somebody
        from the <strong>Roles</strong> button on their row at Admin &rarr; Users &mdash; available to any
        administrator of the organisation currently open, or a global administrator anywhere. An
        organisation's administrators rename roles, or add roles of their own, at
        <strong>Admin &rarr; Roles</strong>. The Admin and Root Admin flags are different: they stay checkboxes
        directly on the account itself (<code>isAdmin</code>, <code>isRootAdmin</code> columns in
        <code>tblUsers</code>), not something granted per organisation.
    </p>

    <div class="alert alert-info d-flex gap-2" role="alert">
        <i class="fa-solid fa-circle-info mt-1"></i>
        <div>
            <strong>&ldquo;Roles awaiting placement&rdquo;.</strong> On a portal with more than one
            organisation, a role that was written directly into the database by hand before this feature
            existed cannot be placed automatically &mdash; a <strong>global administrator</strong> sees a
            warning above the Users list whenever this has happened, with a link to place each one into the
            right organisation. On an ordinary installation this never appears.
        </div>
    </div>

    <h5 class="mt-4 mb-3">User management list</h5>

    <p>The user management page now includes <strong>pagination</strong> and a <strong>search bar</strong> for easier navigation of large user lists. Use the search field to filter users by name or email, and use the page controls at the bottom to browse through results.</p>

    <h5 class="mt-4 mb-3">Accounts that belong to no organisation</h5>

    <p>
        On a portal running more than one organisation, an account can occasionally have no organisation
        recorded against it at all &mdash; usually because it was created before a fix shipped in September
        2026, or because &ldquo;Remove from site&rdquo; was used without adding the account anywhere else.
        Such an account's calendar subscription stops working, it cannot check in to an internal event, and
        no organisation's administrator can see it.
    </p>
    <p class="mb-0">
        A <strong>global administrator</strong> sees a warning above the Users list whenever this has
        happened, with a link to <code>/admin/users/unplaced</code>, where each affected account can be
        placed into the right organisation. Only a global administrator can see this list or place anybody
        from it &mdash; the portal deliberately does not guess which organisation an account belongs to.
    </p>
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

<!-- Section: Sending email via a Microsoft 365 shared mailbox (#234) -->
<div class="portal-card p-4 mb-4" id="ms365-shared-mailbox">
    <h2 class="h4 mb-3"><i class="fa-solid fa-users-rectangle me-2 text-primary"></i>Sending Email via a Microsoft 365 Shared Mailbox</h2>

    <p>By default the portal sends every email through Microsoft Graph as <code>mail.defaultFromAddress</code>. An admin can instead route sending through a dedicated <strong>shared mailbox</strong> — e.g. <code>office@yourchurch.org</code> — so mail arrives with that identity and, optionally, keeps a copy in the shared mailbox's own Sent Items.</p>

    <div class="alert alert-info d-flex gap-2" role="alert">
        <i class="fa-solid fa-circle-info mt-1"></i>
        <div>
            <strong>Off by default.</strong> Leaving the shared-mailbox address blank keeps today's behaviour completely unchanged — this is an opt-in feature, not a migration.
        </div>
    </div>

    <h5 class="mt-4 mb-3">Owner setup (Azure AD / Microsoft 365 admin)</h5>

    <ol class="mb-3">
        <li class="mb-2">
            <strong>Create or identify the shared mailbox</strong> in the Microsoft 365 admin centre
            (e.g. <code>office@yourchurch.org</code>). No licence is required for a shared mailbox.
        </li>
        <li class="mb-2">
            <strong>Confirm the app registration has <code>Mail.Send</code>.</strong> The portal already
            uses an app-wide Azure AD registration for mail (<code>auth.ms365.appwide.clientID</code>) —
            in Entra ID → App registrations → API permissions, confirm the <strong>Application</strong>
            permission <code>Microsoft Graph → Mail.Send</code> is present with <strong>admin consent
            granted</strong>. If portal email already works today, this step is already done — there is
            nothing further to change here.
        </li>
        <li class="mb-2">
            <strong>Recommended: scope the permission with an Application Access Policy.</strong>
            Application <code>Mail.Send</code> lets the app send as <em>any</em> mailbox in the tenant —
            no Exchange "Send As" grant on the mailbox itself is needed. Because that is deliberately
            broad, scope it down with either the legacy PowerShell cmdlet:
            <pre class="p-2 bg-body-tertiary rounded small mb-2" style="white-space:pre-wrap;">New-ApplicationAccessPolicy -AppId &lt;clientID&gt; -PolicyScopeGroupId &lt;mail-enabled security group containing the shared mailbox&gt; -AccessRight RestrictAccess -Description "WebMS Intra mail"
Test-ApplicationAccessPolicy -AppId &lt;clientID&gt; -Identity office@yourchurch.org</pre>
            or its successor, <strong>RBAC for Applications</strong> in Exchange Online (a
            management-scope-restricted <code>Mail.Send</code> role assignment). Without this step the
            app token can technically send as any tenant mailbox, not just the one configured below.
        </li>
        <li class="mb-2">
            <strong>Configure the portal</strong> — go to
            <a href="/admin/integrations">Admin → Integrations</a>, find the
            <strong>Shared-Mailbox Sending</strong> section inside the MS365 Graph API card, and set the
            shared mailbox address + display name. Optionally leave "Keep a copy in Sent Items" checked
            (the default) and leave the fallback provider blank (recommended — see below). Save, then use
            <strong>Send Test Email</strong> on the same page.
        </li>
        <li class="mb-2">
            <strong>Confirm</strong> the test email arrives showing the shared mailbox as sender with the
            configured display name, that the page shows a <code>ms365-shared</code> mode badge and a
            "sent" last-send indicator, and — if enabled — that a copy landed in the shared mailbox's own
            Sent Items.
        </li>
    </ol>

    <h5 class="mt-4 mb-3">Deliverability note</h5>
    <p>Mail sent this way leaves Microsoft's own infrastructure, so the organisation's standard Microsoft 365 SPF (<code>include:spf.protection.outlook.com</code>), DKIM (selector1/selector2 CNAMEs enabled in the Defender portal), and DMARC records are what make it align — the web server's own IP reputation is not involved. The <a href="/admin/integrations/email">Email Deliverability</a> page's DNS probe checks these records against the effective sender's domain.</p>

    <h5 class="mt-4 mb-3">Error handling and fallback</h5>
    <ul class="list-group list-group-flush mb-3">
        <li class="list-group-item d-flex gap-2">
            <i class="fa-solid fa-rotate text-info mt-1"></i>
            <div><strong>Expired token (401):</strong> the portal clears its cached token and retries once automatically — no admin action needed.</div>
        </li>
        <li class="list-group-item d-flex gap-2">
            <i class="fa-solid fa-clock text-info mt-1"></i>
            <div><strong>Throttled (429):</strong> a short, bounded retry (5 seconds or less, following Microsoft's own <code>Retry-After</code> header) is attempted once; a longer wait fails the send rather than blocking the page.</div>
        </li>
        <li class="list-group-item d-flex gap-2">
            <i class="fa-solid fa-ban text-danger mt-1"></i>
            <div><strong>Access denied / mailbox not found (403/404):</strong> usually means the mailbox is outside the Application Access Policy scope, was deleted, or consent is missing — the Integration Diagnostics page shows Microsoft's own error code as a targeted hint.</div>
        </li>
        <li class="list-group-item d-flex gap-2">
            <i class="fa-solid fa-arrows-turn-right text-warning mt-1"></i>
            <div><strong>Fallback provider:</strong> off by default — a failed send fails loudly and is logged rather than silently switching sender identity (which can break DMARC alignment). An admin can opt into a one-time Google (Gmail API) fallback attempt if Google sending is also configured.</div>
        </li>
    </ul>

    <p>Every send attempt — successful or failed, either provider — is recorded to a send log visible on the <a href="/admin/integrations/email">Email Deliverability</a> page's "Recent sends" list, kept for 90 days by default.</p>
</div>

<!-- Section 6: Developer Tools -->
<!-- Section: Server Information -->
<div class="portal-card p-4 mb-4" id="server-info">
    <h2 class="h4 mb-3"><i class="fa-solid fa-server me-2 text-primary"></i>Server Information</h2>

    <p>
        <a href="/admin/system-info">/admin/system-info</a> answers one question:
        what is this portal actually running on? It is the page to open when a
        hosting company asks you which version of something you are using, or
        when you are trying to work out why an upload will not go through.
    </p>

    <p>Everything on it is gathered in one place so you do not have to hunt:</p>

    <ul>
        <li>
            <strong>The database.</strong> Which database software your hosting
            runs, which version, and &mdash; the useful part &mdash; whether that
            version is still supported by the people who make it. A version that
            no longer receives security fixes still runs the portal perfectly
            well, but it is worth knowing about and worth asking your hosting
            company about.
        </li>
        <li>
            <strong>The database connection.</strong> Which server, which
            database, which username, which character set, and so on. These are
            the details a support desk asks for.
        </li>
        <li>
            <strong>PHP.</strong> The version, whether it is still supported, and
            the limits your hosting has set &mdash; how large a file you may
            upload, how long a page is allowed to take, how much memory it may
            use. A surprising number of puzzling problems turn out to be one of
            these limits.
        </li>
        <li>
            <strong>Optional parts of PHP.</strong> A list of the add-ons this
            portal relies on and whether your hosting installed them. Each one
            says what it is used for, so if something is missing you can tell
            your hosting company exactly which feature it breaks.
        </li>
    </ul>

    <div class="alert alert-success d-flex gap-2" role="alert">
        <i class="fa-solid fa-lock mt-1"></i>
        <div>
            <strong>Your database password is not on that page.</strong> It is
            not hidden behind dots or stars either &mdash; it is genuinely not
            there. All the connection details shown are asked of the live
            connection itself rather than read out of the file that holds the
            password, so the password never reaches the page in the first place.
            That is deliberate, and it is safer than showing it and covering it up.
        </div>
    </div>

    <h3 class="h5 mt-4 mb-2">The full PHP report</h3>

    <p>
        At the bottom of the page there is a link to PHP&rsquo;s own complete
        report about itself. That is the report a hosting company will usually
        ask you to send them when they are diagnosing an awkward problem. It
        opens in a new tab.
    </p>

    <p>
        Two differences from the rest of the page are worth knowing about:
    </p>

    <ul>
        <li>
            <strong>Only umbrella administrators can open it.</strong> The rest
            of the Server Information page is open to any administrator. This one
            report describes the whole server rather than your one organisation,
            so on an install shared by several organisations it is limited to the
            people who look after the whole thing. If your portal is used by one
            organisation, you are the umbrella administrator and this makes no
            difference to you.
        </li>
        <li>
            <strong>Parts of it are deliberately left out.</strong> PHP will
            happily print the server&rsquo;s stored passwords and your own
            sign-in token along with everything else. Those parts are removed
            before the report is shown to you. This matters precisely because
            people paste this report into support tickets &mdash; which is the
            main reason the page exists, and would otherwise be a good way to
            hand your sign-in token to a stranger.
        </li>
    </ul>

    <div class="alert alert-info d-flex gap-2" role="alert">
        <i class="fa-solid fa-circle-info mt-1"></i>
        <div>
            <strong>If the report will not open</strong>, some hosting companies
            switch off the PHP feature that produces it, across their whole
            server. The page will tell you if that is what has happened. It
            cannot be changed from inside the portal, and everything else on the
            Server Information page still works.
        </div>
    </div>
</div>

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
