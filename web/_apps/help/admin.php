<?php
// Path: apps/help/admin.php
/**
 * -----------------------------------------------------------------------------
 * Help Centre -- Admin Guide
 * -----------------------------------------------------------------------------
 * Guide for portal administrators: managing settings, user roles, user groups
 * and departments (#517), Gatekeeper (dev/alpha/beta site access), and
 * viewing system logs.
 * -----------------------------------------------------------------------------
 * @package    Portal\Help
 * @license   All Rights Reserved
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Departments;
use Portal\Core\Roles;
use Portal\Core\Site;

$pageTitle   = 'Help - Admin Guide';
$pageSection = 'help';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Help' => Site::url('help'), 'Admin Guide' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- Admin Guide -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-gear me-2"></i>Admin Guide</h1>
        <p class="text-secondary mb-0">For administrators: managing settings, users, access control, and logs.</p>
    </div>
    <a href="<?php echo htmlspecialchars(Site::url('help'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary btn-sm mt-2 mt-md-0">
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
            <a href="#groups-departments" class="badge text-bg-secondary text-decoration-none">Groups and Departments</a>
            <a href="#gatekeeper" class="badge text-bg-secondary text-decoration-none">Dev Site Access (Gatekeeper)</a>
            <a href="#giving-records" class="badge text-bg-secondary text-decoration-none">Giving Records and Erasure</a>
            <a href="#logs" class="badge text-bg-secondary text-decoration-none">Viewing Logs</a>
            <a href="#csv-export" class="badge text-bg-secondary text-decoration-none">CSV Export</a>
            <a href="#web-addresses" class="badge text-bg-secondary text-decoration-none">The Portal&rsquo;s Web Address</a>
            <a href="#public-website-setup" class="badge text-bg-secondary text-decoration-none">Setting Up the Public Website</a>
            <a href="#time-zones" class="badge text-bg-secondary text-decoration-none">Time Zones</a>
            <a href="#smtp-mailbox" class="badge text-bg-secondary text-decoration-none">SMTP Mailbox</a>
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
                <strong>Open the "Admin" menu in the navigation bar and choose "Settings"</strong>
                <p class="mb-0 small text-secondary">The <i class="fa-solid fa-shield-halved"></i> Admin menu, and the <i class="fa-solid fa-gear"></i> Settings entry in it, appear only for admin users.</p>
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

    <?php
    // 🔐 Added 12 October 2026 (#597). Until then this page said only that
    //    sensitive values were "masked". A global administrator could in fact
    //    reveal them, and editing one stored it unprotected. The Settings page
    //    now never shows a secret, and an empty box no longer means "wipe it",
    //    so the three things an administrator can do are spelt out here.
    ?>
    <h5 class="mt-4 mb-3">Secret settings</h5>

    <p>
        A setting with a padlock beside its name is a <strong>secret</strong>, such as a password or a key from another service.
        It is stored encrypted and is <strong>never shown</strong> on the Settings page, to anybody. The page says only whether it is set.
    </p>
    <ul>
        <li><strong>To replace one</strong>, press its edit button and type the new value. The box always starts empty.</li>
        <li><strong>To keep it</strong>, leave the box empty. Nothing is changed.</li>
        <li><strong>To remove it</strong>, tick <strong>"Remove the stored value"</strong>. Whatever uses it stops working until a new value is entered.</li>
        <li>
            <strong>"Set, but needs entering again"</strong> means the value is not encrypted with this installation's key, so the portal
            uses it exactly as it is stored. Usually an older version of the Settings page stored it as readable text when it was edited:
            it still works, but it is unprotected. Less often the portal's key file has been replaced since it was saved, and whatever
            uses it will not work. Either way, enter the value again. If you no longer have it, make a new one where it came from (the
            other service's own website, or a new token for a scheduled job) and enter that. Upgrading the portal encrypts readable
            values by itself where it can, and its page says which.
        </li>
    </ul>
    <p>
        A secret that applies to the whole installation can only be changed by a global administrator. An administrator of one
        organisation is not told whether it is set.
    </p>

    <div class="alert alert-warning d-flex gap-2" role="alert">
        <i class="fa-solid fa-triangle-exclamation mt-1"></i>
        <div>
            <strong>Caution:</strong> Changing settings can affect the behaviour of the entire portal. Only Root Admins should modify authentication and OAuth settings.
        </div>
    </div>

    <h5 class="mt-4 mb-3">Common setting keys</h5>

    <?php /* 📱 THE KEY STACKS ABOVE ITS DESCRIPTION ON A PHONE (#569). Each key
             is `text-nowrap` so it is never split at a dot, which is right —
             but beside a description in one row it left too little room, and
             at 320px the alpha/beta rows below pushed the whole page to 391px
             wide, so the visitor had to scroll sideways. `flex-column
             flex-sm-row` keeps the two side by side from 576px up and puts
             the key on its own line below that. Nothing is hidden or cut. */ ?>
    <div class="list-group list-group-flush">
        <div class="list-group-item d-flex flex-column flex-sm-row gap-1 gap-sm-2">
            <code class="text-nowrap">site.name</code>
            <span class="text-secondary">-- The portal name shown in the browser tab and navigation bar.</span>
        </div>
        <div class="list-group-item d-flex flex-column flex-sm-row gap-1 gap-sm-2">
            <code class="text-nowrap">site.copyrightOrg</code>
            <span class="text-secondary">-- Organisation name shown in the footer copyright notice.</span>
        </div>
        <div class="list-group-item d-flex flex-column flex-sm-row gap-1 gap-sm-2">
            <code class="text-nowrap">expenses.enabled</code>
            <span class="text-secondary">-- Set to <code>true</code> to enable the Expenses app in navigation.</span>
        </div>
        <div class="list-group-item d-flex flex-column flex-sm-row gap-1 gap-sm-2">
            <code class="text-nowrap">expenses.displayName</code>
            <span class="text-secondary">-- The label shown in the nav bar for the Expenses app.</span>
        </div>
        <div class="list-group-item d-flex flex-column flex-sm-row gap-1 gap-sm-2">
            <code class="text-nowrap">expenses.displayIcon</code>
            <span class="text-secondary">-- The Font Awesome icon class used for the app (e.g., <code>fa-solid fa-receipt</code>).</span>
        </div>
    </div>
</div>

<!-- Section: Site Branding -->
<div class="portal-card p-4 mb-4" id="site-branding">
    <h2 class="h4 mb-3"><i class="fa-solid fa-palette me-2 text-primary"></i>Site Branding</h2>

    <p>Each site in this install can have its own visual identity. Branding values are configured at <a href="<?php echo htmlspecialchars(Site::url('admin/sites'), ENT_QUOTES, 'UTF-8'); ?>">/admin/sites/</a> by umbrella admins.</p>

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

    <p>To hide the "Powered by" attribution across all sites in this install, set the global setting <code>branding.hidePoweredBy</code> to <code>true</code> at <a href="<?php echo htmlspecialchars(Site::url('settings'), ENT_QUOTES, 'UTF-8'); ?>">/settings/</a>. Default is <code>false</code> (show attribution on custom-branded sites).</p>

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
        <a href="<?php echo htmlspecialchars(Site::url('admin/captcha'), ENT_QUOTES, 'UTF-8'); ?>">/admin/captcha</a>:
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

    <?php
    // 🚫 Added 13 October 2026 (#497, the owner's decision of 13 September
    //    2026). What it says the forms do, where the portal says so, and
    //    that typing the keys in again puts it right, are each run by
    //    tools/settings-secrets-selftest.php, part J. If Captcha.php changes
    //    what the forms do, change this with it.
    //    Corrected the same day: it said every other form "says so where
    //    the challenge would be", which was untrue of the visitor form and
    //    live chat. They have no challenge, and answered "Verification
    //    failed". Both now say why, and the sentence says where.
    ?>
    <h5 class="mt-3 mb-2">If the stored keys cannot be read</h5>
    <p>
        The keys are stored encrypted with the portal's key file (<code>enc.key</code>). If that file is lost or replaced, the keys
        of the provider in use can no longer be read. Until that is put right:
    </p>
    <ul>
        <li><strong>Sign-in and password reset go on without the bot check.</strong> Their own limits on wrong tries still apply.</li>
        <li>
            <strong>Every other form the bot check protects is refused</strong>, and says so in plain words: where its challenge
            would be, or, on the two forms that have no challenge (the visitor form and live chat), in place of the form and in
            the chat box. A visitor is told that it cannot be sent at the moment and to try again later or get in touch another
            way; nothing is said about keys.
        </li>
        <li>
            The portal tells you on <a href="<?php echo htmlspecialchars(Site::url('admin/captcha'), ENT_QUOTES, 'UTF-8'); ?>">/admin/captcha</a>, on the health page, on the Settings page and in the
            error log (which sends the alert e-mail, where one is set up).
        </li>
    </ul>
    <p>
        To put it right, restore the key file from your backup, or type the provider's two keys in again at
        <a href="<?php echo htmlspecialchars(Site::url('admin/captcha'), ENT_QUOTES, 'UTF-8'); ?>">/admin/captcha</a>. A key left under a provider that is not the one in use affects nothing.
    </p>
    <p>
        <strong>Saving that page with the boxes empty changes nothing.</strong> The boxes of a provider whose keys cannot be read
        are shown empty, and an empty box never removes a key the portal cannot read, so the bot check cannot be switched off
        by pressing Save. To stop using such a provider, remove its two keys on the Settings page instead (edit each one and
        tick "Remove the stored value").
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
        Configurable via <a href="<?php echo htmlspecialchars(Site::url('settings'), ENT_QUOTES, 'UTF-8'); ?>">Settings</a> under the <code>auth.password.*</code> prefix.
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
            <a href="<?php echo htmlspecialchars(Site::url('settings'), ENT_QUOTES, 'UTF-8'); ?>">Settings</a>. If it says 8 and you did not
            choose that, change it to 12.
        </div>
    </div>

    <?php /* 📱 A SETTING AND ITS VALUE, WRAPPING ON A PHONE (#569). These were
             one row each with no way to wrap, and a long key such as
             `auth.password.requireUppercase` has no place to break, so the
             value was pushed past the right-hand edge at 320px. `flex-wrap`
             moves the value onto the next line only when the two do not fit.
             `<wbr>` after each dot lets a key break at a DOT and nowhere
             else — a first version used `text-break` alone, and the screenshot
             at 320px showed "requireUppe / rcase", a setting name cut in the
             middle of a word, which reads as broken. `<wbr>` adds no character,
             so a key copied off the page is still the exact setting name.
             `text-break` stays only as the last resort on a screen narrower
             still, rather than widening the page. */ ?>
    <div class="list-group list-group-flush">
        <div class="list-group-item d-flex flex-wrap justify-content-between column-gap-3"><span class="text-break"><code>auth.<wbr>password.<wbr>minLength</code></span><strong>12</strong></div>
        <div class="list-group-item d-flex flex-wrap justify-content-between column-gap-3"><span class="text-break"><code>auth.<wbr>password.<wbr>maxLength</code></span><strong>128</strong></div>
        <div class="list-group-item d-flex flex-wrap justify-content-between column-gap-3"><span class="text-break"><code>auth.<wbr>password.<wbr>requireUppercase</code></span><strong>true</strong></div>
        <div class="list-group-item d-flex flex-wrap justify-content-between column-gap-3"><span class="text-break"><code>auth.<wbr>password.<wbr>requireLowercase</code></span><strong>true</strong></div>
        <div class="list-group-item d-flex flex-wrap justify-content-between column-gap-3"><span class="text-break"><code>auth.<wbr>password.<wbr>requireNumber</code></span><strong>true</strong></div>
        <div class="list-group-item d-flex flex-wrap justify-content-between column-gap-3"><span class="text-break"><code>auth.<wbr>password.<wbr>requireSpecial</code></span><strong>true</strong></div>
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

<!-- Groups and departments (#517) -->
<div class="portal-card p-4 mb-4" id="groups-departments">
    <h2 class="h4 mb-3"><i class="fa-solid fa-people-group me-2 text-primary"></i>Groups and Departments</h2>

    <p>
        Besides roles, an organisation can gather its people in two other ways. They are deliberately two
        different things, and both belong to <strong>one organisation</strong>: a group or department made in
        one organisation is never visible or usable in another.
    </p>

    <h5 class="mt-3 mb-2">User groups &mdash; committees and working groups</h5>
    <p>
        A user group is a committee or working group, such as a finance committee or a building committee.
        A group can be named as the approver of a <strong>workflow step</strong> (type the group's
        <strong>number</strong>, shown on the Groups page, into the step's &ldquo;Assignee value&rdquo; box), and
        it can <strong>own an asset</strong> in the asset register. Home groups, Bible studies and classes with
        meeting rolls are a different thing: the separate Small Groups app.
    </p>

    <h5 class="mt-3 mb-2">Departments &mdash; what expense claims are charged to</h5>
    <p>
        Every expense claim is charged to a department, and the flags on the department's members decide who
        approves it. The flags, listed here straight from the code that uses them:
    </p>
    <div class="list-group list-group-flush mb-3">
        <?php foreach (Departments::FLAGS as $flagKey => $flag): ?>
            <div class="list-group-item d-flex gap-3 align-items-start">
                <span class="badge text-bg-info rounded-pill mt-1"><i class="fa-solid fa-flag"></i></span>
                <div>
                    <strong><?php echo htmlspecialchars($flag['label'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <p class="mb-0 small text-secondary"><?php echo htmlspecialchars($flag['description'], ENT_QUOTES, 'UTF-8'); ?>.</p>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <!-- #542: this used to say a non-administrator ALSO needed the Expense Approver role. That was
         true, and it stranded claims: the role and the flags are set on different pages, so a lead or
         required approver without the role was refused while the claim still waited for them. -->
    <p>
        The flags are enough on their own: a person who is not an administrator does not also need the
        <strong>Expense Approver</strong> role to decide a department's claims, and the role on its own
        does not let anyone decide them. If the list of departments is empty, nobody can submit a claim:
        the claim form says so.
    </p>

    <h5 class="mt-3 mb-2">Who manages them</h5>
    <p>
        Any administrator of an organisation manages that organisation's groups at
        <strong>Admin &rarr; Groups</strong> and its departments at <strong>Admin &rarr; Departments</strong>:
        add, rename, retire, reinstate and delete, and on each one's <strong>Members</strong> page, add and
        remove people (and, for departments, set their flags). A global administrator can do this in any
        organisation. Only people who are active members of the organisation can be added. As with roles, a
        site administrator cannot change a global administrator's account; the page says &ldquo;That account
        could not be found&rdquo; exactly as it does for a number that does not exist. Every change is written
        to the audit trail.
    </p>

    <h5 class="mt-3 mb-2">Retire or delete?</h5>
    <ul>
        <li><strong>Retire</strong> is always allowed and keeps the history. A retired <strong>group</strong>
            counts for nothing anywhere &mdash; it approves no workflow step and its asset ownership grants
            nothing &mdash; until it is reinstated. A retired <strong>department</strong> takes no new expense
            claims, while claims already submitted to it are still finished by its own approvers.</li>
        <li><strong>Delete</strong> is refused while a group still has members, owns an asset or is named by a
            workflow step of the organisation, or while a department still has members, owns an asset or has
            any expense claim. The message says what is in the way; retire it instead.</li>
    </ul>

    <h5 class="mt-3 mb-2">When somebody leaves</h5>
    <p>
        Removing a person from one organisation (Admin &rarr; Sites &rarr; Users) removes their group and
        department memberships in that organisation only. Offboarding removes them in every organisation, and
        undoing an offboarding does not bring them back &mdash; add them again if needed.
    </p>

    <div class="alert alert-info d-flex gap-2 mb-0" role="alert">
        <i class="fa-solid fa-circle-info mt-1"></i>
        <div>
            <strong>&ldquo;Groups and departments awaiting placement&rdquo;.</strong> Before this feature, groups
            and memberships could only be written into the database by hand. On a portal with more than one
            organisation, anything the upgrade could not place without guessing is set aside, and a
            <strong>global administrator</strong> sees a warning above the Users list with a link to place each
            item. A parked group keeps its number when it is placed, so workflow steps that name it keep
            working; in the rare case that another group has taken that number since, the page gives it a
            new one and says so, so those steps can be updated. On an ordinary installation this never
            appears.
        </div>
    </div>
</div>

<!-- Section 3: Gatekeeper (Dev Site Access) -->
<div class="portal-card p-4 mb-4" id="gatekeeper">
    <h2 class="h4 mb-3"><i class="fa-solid fa-door-open me-2 text-primary"></i>Dev Site Access (Gatekeeper)</h2>

    <p>The portal supports multiple deployment channels: <strong>production</strong>, <strong>beta</strong>, <strong>alpha</strong>, and <strong>dev</strong>. The Gatekeeper system restricts access to non-production channels.</p>

    <p>Which copy a site is comes from the <code>PORTAL_ENV</code> setting, a <code>.channel</code> file beside the <code>_core</code> folder, or a web folder named <code>public_html</code>, <code>public_html_beta</code> or <code>public_html_dev</code>. A site that says nothing is treated as the live site, so this gate does not apply to it, and the admin dashboard says so.</p>

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
        <div class="list-group-item d-flex flex-column flex-sm-row gap-1 gap-sm-2">
            <code class="text-nowrap">portal.alphaAccessRoles</code>
            <span class="text-secondary">-- Comma-separated role keys that can access the alpha site (e.g., <code>Admin,Developer</code>).</span>
        </div>
        <div class="list-group-item d-flex flex-column flex-sm-row gap-1 gap-sm-2">
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

<!-- Giving records and the right to be forgotten (roll-out package A2b).
     Every sentence here was checked against web/_core/GivingRetention.php and
     the settings page it describes. It must never read as if the portal knows
     the law: the figures are suggestions. -->
<div class="portal-card p-4 mb-4" id="giving-records">
    <h2 class="h4 mb-3"><i class="fa-solid fa-receipt me-2 text-primary"></i>Giving Records and Erasure</h2>

    <p>
        When somebody asks to be erased, the portal erases their details. <strong>Some things are
        deliberately kept, and a few are not reached at all</strong> &mdash; the list below says
        which, so that nobody is promised more than the portal does. The best known is giving: tax
        and accounting rules make most organisations keep some giving records, with the donor's
        name, for a number of years. Each organisation sets what it keeps, and for how long, at
        <strong>Admin &rarr; Giving records</strong>.
    </p>

    <h3 class="h6 mt-3">What an erasure keeps, and what it does not reach</h3>
    <p>
        This was written out in full on 2 November 2026, by going through every table in the
        database rather than relying on memory. Three kinds of thing survive an erasure, and they
        are not the same kind of thing at all.
    </p>
    <ul>
        <li>
            <strong>Kept because the law or the organisation's duty requires it.</strong> Giving
            records as above. <strong>Expense claims, which HMRC requires for six years &mdash; and
            the claimant is kept with them.</strong> That was decided deliberately on 3 November
            2026: a record of a payment that cannot say who was paid is of little use as one. This
            page used to say the claimant's name came off the claim, and that was wrong in the
            reassuring direction &mdash; the erasure does try to remove the account link, and the
            database will not allow that column to be empty, so the report of the erasure records
            that it could do nothing there. What stops the claim naming anybody is that the account
            itself is emptied, so the claim points at an account with no name on it; the account
            number stays. The receipts attached to a claim also keep the filename they were
            uploaded with, which is often a person's name. The adults authorised to
            collect a child, because the organisation's duty of care to that child does not end
            because an adult asked to be erased. A Gift Aid declaration, with the network address
            it was made from, because that is part of the evidence it was made.
        </li>
        <li>
            <strong>Kept because it is the organisation's own record, not the person's.</strong> A
            note about a piece of equipment, the condition it was in, who serviced it. A group's
            description and its meeting notes. A venue's address. What was changed in a settings
            page. These stay and the account link is cut. <strong>The change history is a special
            case and was improved on 2 November 2026:</strong> an entry about the person's own
            account or their memberships now has its before-and-after values emptied too, because a
            change to an account recorded the row as it was &mdash; in practice their name and
            e-mail address. Who made each change, when, and which record it was are kept, so the
            history still shows the account was changed.
            <br>
            It now covers an entry about their account and about every kind of membership &mdash;
            which organisations, departments, groups and roles they belonged to. The last three were
            added on 2 November 2026; before that they were not reached, which cost nothing at the
            time because those entries record account numbers and no names, and the account itself
            is emptied anyway. They are covered so that the day one of them starts recording a name,
            it is already dealt with.
            <br>
            One limit, so that nothing here is read as more than it is. An entry about some
            <em>other</em> record whose text happens to mention them is <strong>not</strong>
            reached, because nothing on such a row says they are in it &mdash; and searching the
            text for a name would match somebody else of the same name and miss a nickname.
        </li>
        <li>
            <strong>Kept because emptying it would take something from somebody else.</strong>
            Two of these were decided deliberately on 1 November 2026, and they are not oversights.
            The address a small group meets at is very often a member's <strong>home</strong>
            &mdash; but nothing records whose, so emptying it would blank the meeting place of
            groups that have nothing to do with the person asking. A name typed in for a visitor
            with no account stays on the attendance register, because there is no account to match
            it to, which is the only reason it was typed in the first place.
        </li>
        <li>
            <strong>Emptied on request now, where it is the person's own words about themselves.</strong>
            A <strong>prayer request's subject and body</strong> and a <strong>prayer partner's note
            about them</strong> are emptied, and so is a <strong>pastoral case's summary</strong>
            &mdash; which is somebody else's description of the person's circumstances, and sat
            beside visit notes that were already emptied. The rows stay, so the request still shows
            that one was made and answered, and the case still shows who opened it and when.
        </li>
        <li>
            <strong>Cleared on a timer rather than on request, because nothing on the row says who
            it is about.</strong> Each of these is kept for a period you set, and then emptied or
            deleted by the nightly job. An erasure request cannot reach them, because there is no
            account number on the row to match &mdash; and a rule that searched the text for a name
            would match somebody else of the same name and miss a nickname.
            <ul>
                <li>
                    <strong>Messages from a payment provider.</strong> A payment message routinely
                    carries the payer's name, e-mail address and billing address. The body is now
                    kept only once the message has been proved genuine, and emptied after the
                    period you set. <strong>These used to be kept whole and for ever.</strong>
                </li>
                <li>
                    <strong>Messages the portal sent to another system</strong> &mdash; a
                    registration carries somebody's full name, an event sent in by a visitor
                    carries their e-mail address. Emptied after the same period, but
                    <strong>only once the message has been delivered or given up on</strong>: one
                    still waiting to be sent keeps its body, because that is what a retry sends.
                </li>
                <li>
                    <strong>A recording's written-out transcript</strong>, and a second searchable
                    copy of it &mdash; whatever was said aloud. Deleted after the period you set.
                </li>
                <li>
                    <strong>The fault log's detail and the address asked for</strong>, which can
                    quote a name or an e-mail address. These expire on the fault log's own timer.
                </li>
            </ul>
            <strong>If the period matters for somebody who has asked to be erased, shorten it, or
            deal with those rows directly and record that you have.</strong>
        </li>
        <li>
            <strong>Kept on purpose, because the words are about the organisation rather than the
            person.</strong> Decided on 3 November 2026: a <strong>presenter's name typed in by
            hand</strong> on a service plan or a recording, a licence seat's
            <strong>device name and label</strong>, and a small group's
            <strong>meeting notes</strong>. These describe how the organisation runs, and emptying
            them would take a record from the organisation to no benefit of the person asking.
        </li>
        <li>
            <strong>Emptied on request since 3 November 2026, because the person is the one the
            record is ABOUT.</strong> Two of these were decided that day, and both are the same
            decision: the portal had been treating the person as <em>somebody who acted on a
            record</em> when they were in fact its subject, and emptying text on an actor's name
            would wipe somebody else's details, so the portal would not do it.
            <ul>
                <li>
                    <strong>Why somebody wanted a room, and the note refusing them.</strong> A
                    booking is made by the person it is about, so their stated reason is emptied.
                    The booking itself stays, so the room's history is intact, and a booking
                    somebody else made and this person only <em>approved</em> keeps its reason.
                    (This page used to list this under "not reached"; that stopped being true when
                    the change was made and the page had not been brought into line.)
                </li>
                <li>
                    <strong>The contact details typed on to an equipment loan.</strong> The loan
                    form asks for a telephone number or e-mail address for whoever borrowed the
                    item, whether they are a member or not, so on a member's own loan that is their
                    own number. It is emptied now. The loan stays &mdash; which item went out, when
                    it went, when it came back &mdash; and a loan somebody else borrowed, which
                    this person only approved or asked for, keeps that borrower's details.
                </li>
            </ul>
        </li>
        <li>
            <strong>Not reached, which is a fault rather than a decision.</strong> One is left, with
            a number against it so it is not forgotten: <strong>a salvation card's prayer
            request</strong>. Nothing on the card says whose it is &mdash; the only account on it is
            the follow-up worker it was given to, and emptying on that would wipe a card about
            somebody else because a volunteer left.
            <strong>If somebody asks to be erased and this matters to them, deal with it directly
            and record that you have.</strong>
        </li>
        <li>
            <strong>Deleted, not merely unnamed &mdash; and since 3 November 2026 the portal asks
            whose record it is.</strong> An <strong>event registration</strong> holds the registered
            person's name, date of birth, allergies and medical notes, so when it is theirs the
            whole row is deleted. The portal used to find those by who <em>typed the form in</em>,
            with nothing to say whether that was the same person &mdash; so erasing a volunteer who
            had signed somebody else's child up <strong>deleted that child's registration</strong>,
            and that child's family had asked for nothing. The registration form now asks a
            signed-in person whether it is their own, and an erasure deletes only the ones that are;
            for anyone else it removes their link and leaves the registration alone.
            <br>
            Two things to know. For registrations made <em>before</em> that change, whoever typed
            one in is treated as the person it is for &mdash; true of anyone who registered
            themselves, which is most of them, and where a volunteer typed one for a child the
            child's name is still on it, so nothing was lost. And a registration made by somebody
            with no account at all still cannot be found by an erasure request; those are deleted on
            the event's own time limit instead.
        </li>
    </ul>
    <p>
        Everything above is written down, table by table and column by column, with the reason for
        each, in the portal's own list of where personal information lives. An automatic check
        refuses to let a new place holding somebody's contact details be added without a decision
        being written for it &mdash; but <strong>that check cannot judge free text</strong>, so a
        note somebody types a name into will not be caught by it.
    </p>

    <h3 class="h6 mt-3">What you set</h3>
    <ul>
        <li><strong>Your country.</strong> The portal uses it only to suggest a number of years. Until you choose one, it suggests a cautious 10 years.</li>
        <li><strong>Which gifts keep the donor's name.</strong> Only gifts with a tax declaration, a tax receipt or a giving statement (recommended), or every gift. Every other gift keeps its amount, date and method and loses the name straight away.</li>
        <li><strong>How many years.</strong> Counted from the end of the financial year of the donor's last such gift.</li>
        <li><strong>When your financial year ends.</strong> Until you say, the portal counts from one year after the last gift, which may keep a record up to a year longer than needed.</li>
        <li><strong>Hold.</strong> Switch this on while an audit or tax enquiry is open. Nothing is cleared until you switch it off. A global administrator can also hold the whole installation; the page shows that separately, and it cannot be changed from your organisation's page.</li>
    </ul>
    <p>
        These settings can only be changed on that page. The general settings list shows them and
        refuses to edit or delete them, because a change there could clear kept records with no
        warning.
    </p>

    <div class="alert alert-warning">
        <strong>The number of years is a suggestion, not legal advice.</strong> It comes from research
        of mixed strength. The page shows how strong the source is and links to what was read. Please
        check it with your accountant or adviser, then tick <em>We have confirmed this figure with
        our adviser</em>. <strong>Nothing is ever cleared until that box is ticked.</strong>
    </div>

    <h3 class="h6 mt-3">What happens when the time is up</h3>
    <p>
        The nightly retention job removes the donor's name, their home address, the link to them and
        the copies of statements kept with those records. The gift, the tax declaration and the
        statement record all stay, with their amounts and dates. A line is added to the report of the
        original erasure request, so one report tells the whole story. This cannot be undone.
    </p>
    <p>
        If the nightly job has not been switched on, nothing is cleared until a global administrator
        runs it from <strong>Admin &rarr; Retention</strong>. The Giving records page says so when that
        is the case.
    </p>

    <h3 class="h6 mt-3">Pastoral notes, and whose record they are</h3>
    <p>
        A pastoral visit is written up on the <strong>case</strong> it belongs to, and the note
        describes the person being cared for &mdash; not whoever wrote it. So the portal decides what
        to do with a note by asking who the case is about:
    </p>
    <ul>
        <li>
            <strong>If the case is about the person being erased</strong>, the notes of every visit on
            it are emptied, whoever wrote them. The visit itself stays, with its date, the kind of
            visit it was and its follow-up date, so the record that care was given is not lost.
        </li>
        <li>
            <strong>If the case is about somebody else</strong>, the notes stay exactly as they are
            &mdash; even on a visit the erased person made themselves, and even on a case they opened.
            Those notes are the other person's record, and that person has not asked for anything.
            Only the erased person's own name comes off the visit.
        </li>
    </ul>
    <p>
        The same goes for the case itself: it is kept, and the name of whoever it is about, or who
        opened it, is removed.
    </p>

    <h3 class="h6 mt-3">If an erasure stops part way</h3>
    <p>
        An erasure works through many steps. It deals with the giving records first and empties the
        account about half way through. If it stops part way, run the request again: every step is
        safe to repeat. Until the account has been emptied, the person is still treated as a member
        and nothing kept for them is cleared. Once a run has removed or changed anything, the request
        <strong>cannot be cancelled</strong>, because what it changed cannot be put back. A request
        that has never changed anything can be cancelled as before.
    </p>
    <p class="mb-0">
        There is <strong>one step that running the request again cannot always finish</strong>, and
        the portal says so rather than passing over it. Pastoral visit notes are found through the
        case they belong to, so if an earlier run emptied the name on the case before it reached the
        notes, there is no longer anything to find them by. When that happens the step
        <strong>fails and tells you the notes must be checked by hand</strong> &mdash; it does not
        report that nothing was held. Every other step still runs, so the rest of the erasure is
        done.
    </p>
    <p class="mb-0">
        <strong>What to do about it.</strong> Deal with those notes directly, then press
        <strong>Dealt with by hand</strong> on the request. That runs the request one final time and
        closes it <strong>only if every remaining failure is one no further run can fix</strong> &mdash;
        and its report records that you dealt with those rows yourself. <strong>It records your account
        number and the time, not your name</strong> &mdash; the report shows neither your name nor
        anybody else's, which is deliberate: that record is kept for ever and is never erased. If the
        request is <em>your own</em>, your account number is left out as well, because putting it in
        that record would put back the one thing the erasure had just taken away. <strong>Nothing goes
        into that report on a run it refuses to close</strong>, only into the activity log.
        If anything else failed in that run, nothing is closed and nothing is recorded: you are told
        how many failures were of each kind, because &ldquo;run it again&rdquo; is still the right
        answer for the rest.
        <br>
        Two things that follow from how it works. It <strong>cannot</strong> be used to close a
        request nobody has acted on, because the portal has to have just tried everything it can. And
        a request in this state still cannot be <em>cancelled</em>, because earlier steps have already
        changed records &mdash; cancelling would leave somebody with part of their records gone and a
        request saying nothing happened. We would rather leave you a request that plainly says it is
        unfinished than one that reports success it has not earned.
    </p>
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

    <p>On a <strong>development or beta</strong> portal, an administrator can switch on the
    <strong>debug panel</strong> by adding <code>?debug=true</code> to any page address. It shows a
    panel at the foot of the page with how long the request took, the settings that were loaded, and
    the current session.</p>
    <p class="small text-secondary"><strong>It does not work on a live portal</strong>, and that is
    deliberate: the panel would show settings and session details to anyone who talked an
    administrator into opening such a link. The request is refused and the attempt is recorded. A
    portal that has not been told which channel it is counts as live, so if this does nothing on
    what you believe is a test site, that is why.</p>

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

<!-- Section: The portal's own web address (#500).
     Every statement here is held true by tools/portal-address-selftest.php. If
     the "Web addresses" card at Admin, Settings, Organisation changes, change
     this with it. -->
<div class="portal-card p-4 mb-4" id="web-addresses">
    <h2 class="h4 mb-3"><i class="fa-solid fa-globe me-2 text-primary"></i>The Portal&rsquo;s Web Address</h2>

    <p>The portal needs to be told the address people type to reach it, for example <code>https://portal.example.org</code>. Every link in an e-mail it sends begins with that address: a password reset, an invitation, a reminder.</p>

    <h3 class="h5 mt-4 mb-3">Setting it</h3>
    <ol class="mb-3">
        <li class="mb-2">Go to <a href="<?php echo htmlspecialchars(Site::url('admin/settings/organisation'), ENT_QUOTES, 'UTF-8'); ?>">Admin, Settings, Organisation</a> and find <strong>Web addresses</strong>. Only a global administrator can save it.</li>
        <li class="mb-2">Type the address with nothing after the name: no folder and no page. The card shows the address you are using right now, which is usually the one to type.</li>
        <li class="mb-2">The same card holds the <strong>support e-mail address</strong> shown on the Help page &ldquo;Getting Support&rdquo; and at the foot of e-mails. Leave it empty to show none.</li>
    </ol>
    <p>There is a quicker way while no address is set. The Admin dashboard then shows a notice, and a global administrator sees in it the address they reached the dashboard at and a <strong>Use this address</strong> button. One press saves that address through the same checks as the card, and leaves the support e-mail address as it is. Check the address shown is the one people should use before pressing.</p>

    <h3 class="h5 mt-4 mb-3">Why it matters</h3>
    <ul class="mb-3">
        <li><strong>Until it is set</strong>, each link is built from the address of whatever request caused the e-mail. A visitor chooses that, so somebody could ask for a password reset for another person and have the link in that person&rsquo;s e-mail point at a site of their own. The admin dashboard shows a notice until the address is set.</li>
        <li><strong>If the portal moves</strong> to a new address, change it here. The dashboard says when the stored address is not the one you reached it at.</li>
        <li>Where each organisation has an address of its own, a link for an organisation uses that organisation&rsquo;s own name. The address here still decides whether links begin with <code>http</code> or <code>https</code>.</li>
    </ul>

    <h3 class="h5 mt-4 mb-3">What it does not do</h3>
    <ul class="mb-0">
        <li>It does not move the portal or change what address reaches it. That is set with your hosting company.</li>
        <li>It does not check that the address works. If it is mistyped, e-mails still go and their links open nothing.</li>
    </ul>
</div>

<!-- Section: Setting up the public website (#493 P3 and P4a; D-082, D-085,
     D-093, D-094, D-095, D-097). Every statement here is held true by section
     W of tools/public-website-tick-selftest.php and by
     tools/public-door-selftest.php (the public door). If Admin, Public website
     or the door changes, change this with it. -->
<div class="portal-card p-4 mb-4" id="public-website-setup">
    <h2 class="h4 mb-3"><i class="fa-solid fa-globe me-2 text-primary"></i>Setting Up the Public Website</h2>

    <p><strong>Nothing from the portal is shown on any outside website until you turn on the public door.</strong> It starts off for every organisation.</p>

    <h3 class="h5 mt-4 mb-3">The public door</h3>
    <ul class="mb-3">
        <li>The public door is how your own website reads the events and noticeboard posters you have put on the public website. Nothing else the portal holds goes through it: only items somebody ticked, only while their dates allow, never anything members-only, deleted or still a draft, and never an event that has already finished.</li>
        <li><strong>Turn on the public door</strong> on Admin, Public website. The page then shows two addresses: one for your events and one for your posters. Each gives the information as data (JSON) for your website, or whoever builds it, to read. A poster&rsquo;s picture or video is given at an address of the door&rsquo;s own, which stops answering the moment the poster is taken off the public website.</li>
        <li>The addresses use a random name, not your organisation&rsquo;s name. If you have set a public address (below), they use that instead.</li>
        <li>A change reaches websites within about a minute, because websites and browsers may keep a copy that long.</li>
        <li><strong>Replace the name</strong> if the addresses have gone somewhere they should not: every copy using the old name on the portal&rsquo;s own address stops at once. A public address carries no name, so <strong>replacing the name does not stop copies that use your public address</strong>; to stop those, switch the door off, or change or remove the public address. <strong>Switch the public door off</strong> to stop everything while it is off. Turning it on again gives it a new name, so copies using the old name on the portal&rsquo;s own address stay stopped &mdash; but your public address answers again the moment the door is back on; to stop a public address that has gone somewhere it should not, change or remove it. Each of these is recorded in the activity log.</li>
        <li>If the calendar or the noticeboard is switched off for your organisation, its half of the door answers nothing.</li>
        <li>On a test copy of the portal (alpha, beta or dev), the door answers only people signed in there who may use the test copy. Browsers do not send that sign-in to another website, so trying it from a real website shows nothing; open the addresses in your own browser instead.</li>
        <li>The pasted-in script and the page you can frame are not part of this version yet.</li>
    </ul>

    <h3 class="h5 mt-4 mb-3">Who can do it</h3>
    <ul class="mb-3">
        <li>A global administrator of your organisation, or of the whole installation. An ordinary administrator cannot open the page.</li>
        <li>Anybody a global administrator chooses: give them the role <strong>Website Set-up</strong> on the Users page, or point &ldquo;Set up the public website&rdquo; at another role on the <a href="<?php echo htmlspecialchars(Site::url('admin/roles'), ENT_QUOTES, 'UTF-8'); ?>">Roles</a> page. Only a global administrator can do either: an ordinary administrator cannot give this job to anybody, themselves included.</li>
    </ul>

    <h3 class="h5 mt-4 mb-3">What it holds</h3>
    <ul class="mb-3">
        <li><strong>Your main website</strong>, such as <code>https://www.ourchurch.org</code> &mdash; the website you already have, which the portal does not run.</li>
        <li><strong>A public address</strong> for the portal&rsquo;s own public pages, such as <code>events.ourchurch.org</code>. It is optional, and <strong>it does nothing until the installation&rsquo;s global administrator approves it</strong> on this page; saving a new or changed address asks again. The installation&rsquo;s administrator sees every organisation&rsquo;s address in one list, and can approve it or withdraw approval (which takes effect at once). It cannot be saved while the portal&rsquo;s own address is not set. You point it at the portal with whoever looks after your domain, so public visitors never reach the address members sign in at. No two organisations can use the same one, and it cannot be an address members already sign in at.</li>
        <li><strong>The websites allowed to show your public pages in a frame</strong> &mdash; one per line, each beginning <code>https://</code>, at most twenty. With none listed, no website may. A wildcard, a folder or page after the name, or plain <code>http://</code> is refused, and one bad line stops the whole list being saved, so you are told which line to fix.</li>
        <li>For the installation&rsquo;s global administrator only: whether any organisation may let outside websites frame its pages at all. When this says no, every organisation&rsquo;s list is ignored.</li>
    </ul>

    <h3 class="h5 mt-4 mb-3">Check my set-up</h3>
    <p class="mb-0">The button checks what can be known today: whether your main website answers over https (a website at a port other than the standard https one is not fetched, and it says so), whether your public address&rsquo;s name exists, whether each framing website is valid and allowed, how many events and posters are marked to appear on the public website today, whether the portal&rsquo;s own address is set, and whether the public door is on, with its addresses. It does not fetch the door itself, so it always says it cannot tell whether your website really shows it. It changes no setting, and its result is shown to you once and not kept. The portal does count how many times you have run it (at most six in ten minutes), and if your main website does not answer normally, the portal's error log (Admin, Error log) may note the website's name and the reply it gave.</p>
</div>

<!-- Section: Time zones (#604).
     Every statement here is held true by tools/time-zones-selftest.php. If the
     "Time zone" box at Admin, Organisations or on the event form changes,
     change this with it. -->
<div class="portal-card p-4 mb-4" id="time-zones">
    <h2 class="h4 mb-3"><i class="fa-solid fa-clock me-2 text-primary"></i>Time Zones</h2>

    <p>The portal is a diary, so every time in it belongs to a time zone. There are two kinds, and each is kept in one place.</p>

    <h3 class="h5 mt-4 mb-3">The organisation&rsquo;s time zone</h3>
    <ul class="mb-3">
        <li class="mb-2"><strong>Where it is chosen:</strong> <a href="<?php echo htmlspecialchars(Site::url('admin/sites'), ENT_QUOTES, 'UTF-8'); ?>">Admin, Organisations</a>, the organisation&rsquo;s <strong>Edit</strong> button, <strong>Time zone</strong>. Only a global administrator can open that page. It is not a setting on the general Settings page any more, and that page refuses it.</li>
        <li class="mb-2"><strong>What follows it:</strong> the times pages show, what counts as &ldquo;today&rdquo;, the dates in the mail queue and on the health page, and the zone a new event starts on.</li>
        <li class="mb-2"><strong>The box is a list of real zone names</strong>, such as <code>Pacific/Auckland</code>. Nothing else can be saved.</li>
        <li class="mb-2"><strong>If an organisation&rsquo;s card says its zone &ldquo;is not a time zone name the portal knows&rdquo;</strong>, the portal is using UTC for it until you choose one. That can happen to a zone typed long ago. Where the stored text could still be read as a zone, outside calendars that give no time zone of their own are not imported for that organisation until you choose: the portal will not guess which clock their times are on. Nothing already imported is changed.</li>
    </ul>

    <h3 class="h5 mt-4 mb-3">An event&rsquo;s time zone</h3>
    <ul class="mb-3">
        <li class="mb-2">Each event has its own, chosen from the same list in the <strong>Time zone</strong> box on the event form. A new event starts on the organisation&rsquo;s zone. Change it only for an event that happens somewhere else, or an online one run for another region.</li>
        <li class="mb-2">An event&rsquo;s start and end are times on <em>that</em> clock. The event page, the calendar feed people subscribe to, the downloaded calendar file, reminders and the hired-venue check all read them on it.</li>
        <li class="mb-2"><strong>If Manage Events lists events &ldquo;saved with a time zone the portal does not know&rdquo;</strong>, those were saved when the box was free text, or with the box emptied. Their times are read on the organisation&rsquo;s clock meanwhile. Open each one, choose its zone and save. The portal does not change them for you, because only you know what was meant.</li>
        <li class="mb-2"><strong>If Manage Events says events made before this version are marked &ldquo;Europe/London&rdquo;</strong>, and your organisation is not on London&rsquo;s clock: until this version the event form filled its box with Europe/London for every organisation, and the file import, the events API and members&rsquo; suggestions stored it too. Reminders, the calendar feed and the calendar download go by the event&rsquo;s own zone, so for those events they follow London&rsquo;s clock. The notice lists the ones still to come and has one button that sets them all, past and coming, to your organisation&rsquo;s zone. It changes the zone only, never the time stored. If one of them really is in London, do not press the button: open the others one by one.</li>
    </ul>

    <h3 class="h5 mt-4 mb-3">What it does not do</h3>
    <ul class="mb-0">
        <li>Changing an organisation&rsquo;s zone does not move its events: each keeps the zone it was saved with.</li>
        <li>It does not decide when an erased donor&rsquo;s name is cleared (Giving Records and Erasure). That date is taken at the last place on Earth to reach it, whatever your zone, so a name is never cleared early. It is cleared later instead: about half a day later for an organisation on London&rsquo;s clock, and up to 26 hours later for one in the countries furthest ahead of it. The &ldquo;Confirmed on&rdquo; date shown on the Giving Records page is your own organisation&rsquo;s date on the day the box was ticked.</li>
        <li>While new files are in place and their database upgrade has not been run, neither this box nor the Settings page takes a time zone: run the upgrade first.</li>
        <li>A start time inside the hour that happens twice on the night the clocks go back can mean either of two moments. The portal takes one and uses it for the reminder as well, so the two agree, but it cannot know which you meant. Avoid starting an event in that hour.</li>
    </ul>
</div>

<!-- Section: Sending e-mail through an SMTP mailbox (#592).
     Every statement here is held true by tools/mail-pages-selftest.php. If the
     page at Admin, Integrations, Email changes, change this with it. -->
<div class="portal-card p-4 mb-4" id="smtp-mailbox">
    <h2 class="h4 mb-3"><i class="fa-solid fa-envelope-open-text me-2 text-primary"></i>Sending E-mail Through an SMTP Mailbox</h2>

    <p>The portal can send its e-mail through an ordinary mailbox on a mail server, of the kind most hosting and e-mail providers include. (SMTP is the usual way a program hands mail to a mail server.) It is one of three routes; the others are Microsoft 365 and Google. <strong>One route serves every organisation on the installation</strong>, so only a global administrator can set it up, choose it, or send a test.</p>

    <h3 class="h5 mt-4 mb-3">Setting it up</h3>
    <ol class="mb-3">
        <li class="mb-2">Go to <a href="<?php echo htmlspecialchars(Site::url('admin/integrations/email'), ENT_QUOTES, 'UTF-8'); ?>">Admin, Integrations, Email</a> and find <strong>SMTP mailbox</strong>.</li>
        <li class="mb-2">Type in what your mail provider gives you: the mail server&rsquo;s name, the port, the kind of encryption, the mailbox&rsquo;s user name and its password. Add the address the mail should come from, and save.</li>
        <li class="mb-2">Use <strong>Send test</strong> and choose to send through the SMTP mailbox. This tests the mailbox <em>without</em> switching the portal over to it. The page shows how far it got (reached the server, encryption on and the certificate checked, signed in, message accepted) and the mail server&rsquo;s own last line.</li>
        <li class="mb-2">When the test is accepted and the message has arrived, choose <strong>An SMTP mailbox</strong> under <strong>Route in use</strong>.</li>
    </ol>

    <h3 class="h5 mt-4 mb-3">The password</h3>
    <ul class="mb-3">
        <li>It is stored encrypted, and it is never shown again, on that page or anywhere else. The page says only whether one is stored.</li>
        <li>Leave the password box empty when you save to keep the one that is stored.</li>
        <li><strong>Type it on that page</strong>, where it can be tested before the portal relies on it.</li>
        <li>Use a long password that is not an ordinary word.</li>
    </ul>

    <h3 class="h5 mt-4 mb-3">What the portal insists on</h3>
    <ul class="mb-3">
        <li><strong>Encryption, always.</strong> There is no unencrypted choice.</li>
        <li><strong>The mail server&rsquo;s certificate is always checked.</strong> A certificate is the document a mail server shows to prove it is the server it claims to be. If the check fails (the certificate has expired, is for another server name, or cannot be traced to a trusted authority), nothing is sent: every message is held in the mail queue, and goes by itself once the certificate passes the check. The e-mail page, the admin dashboard (for a global administrator) and the health page then show a notice saying what the check found. Ask whoever runs the mail server to put the certificate right, or correct the server name in the settings, then use Send test. Only a global administrator can let mail past a failed check, and only in the three deliberate ways described under &ldquo;When the certificate fails&rdquo; below.</li>
        <li><strong>If you change the mail server&rsquo;s name or its port, type the password again.</strong> A stored password was saved for one server. The e-mail page will not keep it for a different name or port: type it again, or tick the box to remove it. Certificate checking also goes back on if it had been switched off or one certificate accepted. The name is changed on the e-mail page only: the general Settings page shows it, with a link to the e-mail page, and refuses to change it. (The port can still be changed on the general Settings page, which keeps both; change it on the e-mail page.)</li>
    </ul>

    <h3 class="h5 mt-4 mb-3" id="mail-queue">The mail queue: mail that cannot go at once</h3>
    <ul class="mb-3">
        <li><strong>Mail that cannot be sent at once is kept and tried again.</strong> If the mail server is down, too slow, or says &ldquo;later&rdquo;, the message waits in the mail queue. It is tried again after 5 and 15 minutes, then after 1, 3, 6 and 12 hours: about a day in all. After that it is marked as failed and kept for 7 days, so that it can be looked at, and then deleted. A password-reset e-mail is tried sooner (after 1, 5, 15 and 30 minutes) and is never sent once its link has stopped working. Nothing older than 48 hours is ever sent. A message the mail server refuses outright, such as one for an address that does not exist, is not tried again.</li>
        <li><strong>The queue needs its scheduled job, which you add once.</strong> In your hosting company&rsquo;s scheduler, add a job that calls the address <code>/cron/mail-queue</code> on your portal every five minutes, with the same maintenance token the other scheduled jobs use (first steps, under scheduled jobs, says how to set the token). Replacing YOUR-PORTAL with your portal&rsquo;s address:
            <pre class="bg-body-tertiary p-2 rounded"><code>*/5 * * * * curl -fsS -H "Authorization: Bearer YOUR_TOKEN" "https://YOUR-PORTAL/cron/mail-queue" &gt; /dev/null</code></pre>
            If your scheduler can only fetch an address, use <code>https://YOUR-PORTAL/cron/mail-queue?token=YOUR_TOKEN</code>. <strong>Until the job is running, the portal sends mail as it did before there was a queue</strong>: inside each request, a password-reset e-mail included. What it cannot send at once then waits and is not tried again, and the health page&rsquo;s &ldquo;Mail queue&rdquo; line turns red and says how many messages are waiting. The portal counts the job as running when it has run in the last 15 minutes. This one job also works on a beta, alpha or dev copy of the portal; the other scheduled jobs do not run there.</li>
        <li><strong>A certificate that fails the check holds all mail</strong>, as above. Held mail goes by itself once the certificate passes; a held message is kept for 7 days.</li>
        <li><strong>A refused sign-in makes mail wait.</strong> If the mail server refuses the mailbox&rsquo;s user name and password, nothing is sent and mail waits. The portal does not keep offering a refused password, because that is what gets a mailbox locked: it tries again only after 15 minutes, then an hour, then 3 hours, then every 6. (The job makes that try; while no job is running, the next page that sends mail after the wait makes it.) The health page says so. Type the password in again on the e-mail page, or use Send test once the mailbox is put right, and mail starts again.</li>
        <li><strong>The hourly limit makes mail wait; it does not drop it.</strong> You can set the most recipients an hour the portal may send to, across every route; many hosting companies allow only so many. A message that would pass the limit waits in the queue until the hour has room. The last tenth of the limit (and at least 5 where the limit is 10 or more) is kept for urgent mail such as password-reset links, so a mailing cannot keep somebody&rsquo;s reset link waiting. Leave the limit empty for none. One message addressed to more people than the limit allows in a whole hour can never go, so it is refused at once and not kept: send it to fewer people at a time, or raise the limit.</li>
        <li><strong>A mailing goes a few at a time.</strong> A page that sends to many people (an event broadcast, a newsletter, giving statements) sends the first 25 at once, or as many as go in 20 seconds, and the queue sends the rest at the job&rsquo;s next runs. While no job is running there is no limit of 25, only the 20 seconds. The event broadcast page, the newsletter page and the giving statements page each say how many went and how many are waiting; a newsletter copy or a statement that is waiting is shown as waiting, and is marked as delivered or e-mailed only once it has gone. The reminder jobs print how many reminders went and how many are waiting. Pressing Send again within ten minutes on the same event broadcast sends nothing.</li>
        <li><strong>A reminder or an invitation is not sent late.</strong> A message that would be useless or misleading after a certain moment carries that moment, and is never sent at or after it: a password-reset link and a portal invitation when the link stops working, an event reminder and an event invitation when the event starts (the &ldquo;tomorrow&rdquo; reminder at the start of the event&rsquo;s day), worked out in the time zone typed on the event&rsquo;s own form, or in the organisation&rsquo;s time zone when what was typed there is not a time zone the portal knows, a rota reminder at the end of its last duty&rsquo;s day, the daily milestones message at the end of its day, and the e-mail that confirms an erasure request 23 hours after it was asked for. Such a message is recorded in Recent sends as expired.</li>
        <li><strong>On some web servers a password-reset e-mail waits for the job.</strong> The portal answers a request for a reset link before it sends the e-mail, so that the time the answer takes does not show whether an address has an account. Where the web server cannot let the answer go first (PHP run by Apache as its own module, through FastCGI or as a CGI program, which is ordinary on shared hosting) <em>and the scheduled job is running</em>, the e-mail is not sent in the request: it goes at the job&rsquo;s next run, up to five minutes later. While no job is running it is sent in the request, so that it arrives. A new request for a reset link removes that person&rsquo;s older reset e-mails if they are still waiting, because their links no longer work.</li>
        <li><strong>After a backup is restored, waiting mail is held.</strong> A message that was waiting when the backup was taken may have been sent since, and nobody can tell from the restored database. So a restore holds every such message, and none is sent by itself. A held message is kept for 7 days and then marked as failed.</li>
        <li><strong>The four numbers can be changed</strong> in Settings: <code>mail.queue.retryHours</code> (24), <code>mail.queue.maxAgeHours</code> (48), <code>mail.queue.keepFailedDays</code> (7) and <code>mail.queue.holdDays</code> (7). A global administrator changes the installation&rsquo;s values, which every organisation uses unless it has its own. An organisation&rsquo;s administrator (or a global administrator working in that organisation) can give the organisation its own value for any of the four: in Settings, add a setting with exactly that name and a whole number as its value. It then applies to that organisation&rsquo;s mail only. To change it, edit it; to remove it and go back to the installation&rsquo;s value, edit it, clear the box and save. Settings refuses a value outside the range and says what the installation&rsquo;s value is. A value that got in some other way and is not a sensible whole number is not used, and the health page says which.</li>
        <li><strong>Where to look.</strong> The health page has a &ldquo;Mail queue&rdquo; line: how much is waiting, held and failed, and when the scheduled job last ran. It is red when messages are waiting and the job is not running, and when the mail server&rsquo;s certificate or the mailbox&rsquo;s sign-in has failed. The e-mail page (Admin, Integrations, Email) has the <strong>Mail queue</strong> itself, described next, and <strong>Recent sends</strong>, which labels each of the last ten attempts as Sent, Waiting, Held, Expired or Not sent.</li>
        <li>A person who asks for a password-reset link is told it was sent whether or not it was, so that nobody can use that page to find out who has an account. The health page and the e-mail page are where an administrator finds out that mail is not going.</li>
    </ul>

    <h3 class="h5 mt-4 mb-3" id="mail-queue-page">The mail queue on the e-mail page</h3>
    <ul class="mb-3">
        <li><strong>What it lists.</strong> Every message that is waiting or held, most urgent first: the kind of message, who it is for, its subject, when it was kept, why it waits, how many times it has been tried, and the time after which it is not sent. A held password reset is listed first, with how long its link has left. Messages that were given up on are listed underneath until they are removed. <strong>The content of a message is never shown</strong>: it can hold a password-reset link. When the queue is empty it says &ldquo;Nothing is waiting.&rdquo; Times are in your organisation&rsquo;s time zone, which is named beside each.</li>
        <li><strong>Who sees what.</strong> A global administrator sees every organisation&rsquo;s messages and has the buttons. An organisation&rsquo;s administrator sees their own organisation&rsquo;s messages, with no buttons, and is not shown what the mail server said about a message (it can name somebody else&rsquo;s address, or the mailbox).</li>
        <li><strong>Send waiting mail now</strong> tries what is waiting and due, once, without waiting for the scheduled job: at most 25 messages, or 20 seconds. It never sends a message that is held for a person to decide about; mail held for the certificate goes only if the certificate now passes the check. It is also how waiting mail is sent on an installation that has no scheduled job yet; it does not make the health page read as if the job were running.</li>
        <li><strong>Send it again</strong> is offered for a message held because nobody can tell whether it went: the mail job was interrupted while handing it over, the mail server took the whole message and never answered, or it came back with a restored backup. <strong>The person it is for may get a second copy</strong>, and the page says so before the button can be pressed. If you can, ask whether it arrived first; if it did, delete it.</li>
        <li><strong>Delete</strong> removes one message. A waiting or held message is then never sent, and Recent sends says so. A message that was given up on can be deleted but not sent again from here: send it again from the page it was written on.</li>
        <?php // (This used to read "Each of these is one message, by one press ... There is no send all", in a list that includes "Send waiting mail now", which sends up to 25.) ?>
        <li><strong>Send it again</strong> and <strong>Delete</strong> each act on one message, by one press. <strong>Send waiting mail now</strong> is the only button here that can send more than one, and it never sends a message that is held for a person to decide about. All three are written to the activity log with your name and the time.</li>
    </ul>

    <h3 class="h5 mt-4 mb-3" id="mail-certificate">When the certificate fails: the three ways through</h3>
    <p>The right fix is always to put the certificate right; held mail then goes by itself. These are for when mail has to go before that. They are for an SMTP mailbox only (mail that goes by Microsoft 365 or Google never goes past a check), for a global administrator only, and each is written to the activity log with your name and the time. Mail is encrypted in all three; what each gives up is being sure <em>who</em> it is encrypted to.</p>
    <ul class="mb-3">
        <li><strong>The page shows the certificate first</strong>: what the check found, the names it is for, who issued it, its dates and its SHA-256 fingerprint (a short code worked out from the whole certificate; no two certificates share one). To find out whether it really is your mail server&rsquo;s, ask whoever runs the mail server to read you the fingerprint of its certificate, by telephone or from their own website, and compare.</li>
        <li><strong>1. Send one held message with the check skipped.</strong> For a message that cannot wait, such as a password-reset link. It is sent only if the mail server still shows exactly the certificate the page showed you; if it shows any other, nothing is sent and the message stays held. One message a press, and every other message stays held.</li>
        <li><strong>2. Accept only this certificate</strong> (use this before the next one). Mail then goes for as long as the mail server shows exactly that certificate, and is held again the moment it shows any other. You are deciding that the certificate is your server&rsquo;s own. If it is not, whoever is showing it receives the mailbox&rsquo;s user name and password and can read and change every e-mail the portal sends, password-reset links included.</li>
        <li><strong>3. Switch certificate checking off</strong>, confirmed by typing the mail server&rsquo;s name. The portal can then no longer tell your mail server from an impostor: somebody on the network path could read or change every e-mail the portal sends, including password-reset links (which let whoever holds one take over the account it is for), and capture the mailbox&rsquo;s password, and nothing on any page would show it. Use it only while the certificate is being put right, and only if the certificate keeps changing so that accepting one is no use.</li>
        <li><strong>Checking switches itself back on.</strong> While it is off, or one certificate is accepted, the portal asks the mail server at every run of the mail job, and before it sends through the SMTP mailbox from a page, whether its certificate would pass the full check now. When it would, checking goes back on and the activity log says so. The job asks whichever mail route is in use. A global administrator can switch it back on sooner from the warning on the e-mail page.</li>
        <li><strong>Testing an SMTP mailbox before switching to it.</strong> &ldquo;Send test&rdquo; can go through the SMTP mailbox while Microsoft 365 or Google still carries the portal&rsquo;s mail. If that test fails (the password is refused, or the certificate fails its check), <strong>no mail is stopped or held</strong>: it is still going by the other route. If it was the certificate, the e-mail page shows it, and a global administrator can accept it or switch checking off there before switching the portal over, then test again. Sending one held message past the check is offered only once the SMTP mailbox is the route in use. If you switch to the SMTP mailbox while its certificate still fails, the first message finds that out and is held.</li>
        <li><strong>If the certificate has been put right when you press &ldquo;Send this one&rdquo;</strong>, nothing is sent past the check, because nothing needs to be: the page says so, the fault comes off the record, and held mail goes back into the queue.</li>
        <li><strong>A standing warning</strong> shows on the e-mail page, the admin dashboard (to global administrators) and the health page for as long as checking is off or one certificate is accepted, with the date it was set. It stays if the portal is switched to Microsoft 365 or Google, and then says that it applies to the SMTP mailbox: the setting is still stored, and a Send test through the SMTP mailbox still goes under it. A global administrator is also shown who set it, on the e-mail page and the dashboard, for as long as the activity log keeps its line; the health page names nobody. The setting (<code>mail.smtp.certCheck</code>) is changed on the e-mail page only: the general Settings page shows it and refuses to change it.</li>
    </ul>
</div>

<!-- Section: Sending email via a Microsoft 365 shared mailbox (#234) -->
<div class="portal-card p-4 mb-4" id="ms365-shared-mailbox">
    <h2 class="h4 mb-3"><i class="fa-solid fa-users-rectangle me-2 text-primary"></i>Sending Email via a Microsoft 365 Shared Mailbox</h2>

    <p>Unless another route is chosen at <a href="<?php echo htmlspecialchars(Site::url('admin/integrations/email'), ENT_QUOTES, 'UTF-8'); ?>">Admin, Integrations, Email</a>, the portal sends every email through Microsoft Graph as <code>mail.defaultFromAddress</code>. An admin can instead route sending through a dedicated <strong>shared mailbox</strong> — e.g. <code>office@yourchurch.org</code> — so mail arrives with that identity and, optionally, keeps a copy in the shared mailbox's own Sent Items.</p>

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
            <a href="<?php echo htmlspecialchars(Site::url('admin/integrations'), ENT_QUOTES, 'UTF-8'); ?>">Admin → Integrations</a>, find the
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
    <p>Mail sent this way leaves Microsoft's own infrastructure, so the organisation's standard Microsoft 365 SPF (<code>include:spf.protection.outlook.com</code>), DKIM (selector1/selector2 CNAMEs enabled in the Defender portal), and DMARC records are what make it align — the web server's own IP reputation is not involved. The <a href="<?php echo htmlspecialchars(Site::url('admin/integrations/email'), ENT_QUOTES, 'UTF-8'); ?>">Email Deliverability</a> page's DNS probe checks these records against the effective sender's domain.</p>

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

    <p>Every send attempt — successful or failed, whichever route it went by — is recorded to a send log visible on the <a href="<?php echo htmlspecialchars(Site::url('admin/integrations/email'), ENT_QUOTES, 'UTF-8'); ?>">Email Deliverability</a> page's "Recent sends" list, kept for 90 days by default.</p>
</div>

<!-- Section 6: Developer Tools -->
<!-- Section: Server Information -->
<div class="portal-card p-4 mb-4" id="server-info">
    <h2 class="h4 mb-3"><i class="fa-solid fa-server me-2 text-primary"></i>Server Information</h2>

    <p>
        <a href="<?php echo htmlspecialchars(Site::url('admin/system-info'), ENT_QUOTES, 'UTF-8'); ?>">/admin/system-info</a> answers one question:
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
    <a href="<?php echo htmlspecialchars(Site::url('help/treasury'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i>Treasury Guide
    </a>
    <a href="<?php echo htmlspecialchars(Site::url('help/faq'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary">
        FAQ<i class="fa-solid fa-arrow-right ms-1"></i>
    </a>
</div>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
