<?php
// Path: apps/help/admin-first-steps.php
/**
 * -----------------------------------------------------------------------------
 * Help Centre — Admin First Steps 🛠️
 * -----------------------------------------------------------------------------
 * Walkthrough for new portal administrators completing initial setup.
 * Pairs with the dashboard first-run panel (#222).
 *
 * @package   Portal\Help
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/223
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$pageTitle   = 'Help — Admin First Steps';
$pageSection = 'help';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Help' => Site::url('help'), 'Admin First Steps' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

// 🪞 Show full content only to admins — non-admins get a "this page is for
//    admins" friendly redirect to /help/getting-started.
if (App::isAdmin() === false): ?>
    <div class="alert alert-info">
        This guide is for portal administrators. The <a href="<?php echo htmlspecialchars(Site::url('help/getting-started'), ENT_QUOTES, 'UTF-8'); ?>">Getting Started guide</a> is the right page for you.
    </div>
<?php
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
    return;
endif;
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-screwdriver-wrench me-2"></i>Admin First Steps</h1>
        <p class="text-secondary mb-0">Eight steps to take a fresh portal install from "logged in" to "ready for users".</p>
    </div>
    <a href="<?php echo htmlspecialchars(Site::url('help'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary btn-sm">&larr; Help Centre</a>
</div>

<div class="alert alert-info">
    <i class="fa-solid fa-lightbulb me-1"></i>
    The dashboard shows a condensed version of this checklist that auto-detects completion as you go. This page is the full reference.
</div>

<div class="accordion" id="adminFirstSteps">

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#step1">
                1. Branding &amp; site identity
            </button>
        </h2>
        <div id="step1" class="accordion-collapse collapse show" data-bs-parent="#adminFirstSteps">
            <div class="accordion-body">
                <p>Make the portal feel like yours.</p>
                <ul>
                    <li><a href="<?php echo htmlspecialchars(Site::url('admin/settings'), ENT_QUOTES, 'UTF-8'); ?>">/admin/settings</a> → set <code>site.name</code> to your congregation's name.</li>
                    <li>Upload a logo and adjust <code>branding.primaryColor</code> to match.</li>
                    <li>Add a tagline / strapline that will appear on the dashboard header.</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#step2">
                2. Email delivery
            </button>
        </h2>
        <div id="step2" class="accordion-collapse collapse" data-bs-parent="#adminFirstSteps">
            <div class="accordion-body">
                <p>Without e-mail, password resets and notifications do not work. The portal can send in one of three ways, and one way serves every organisation on the installation:</p>
                <ul>
                    <li><strong>An SMTP mailbox</strong> — an ordinary mailbox on a mail server, of the kind most hosting and e-mail providers include. Usually the quickest to set up. A global administrator types the server&rsquo;s name, the port, the mailbox&rsquo;s user name and its password at <a href="<?php echo htmlspecialchars(Site::url('admin/integrations/email'), ENT_QUOTES, 'UTF-8'); ?>">Admin, Integrations, Email</a>. The connection is always encrypted and the mail server&rsquo;s certificate is always checked; if the certificate fails that check, nothing is sent and the page says why, until the certificate is put right or a global administrator deliberately lets mail past the check (the Admin Guide says how, and what that risks).</li>
                    <li><strong>Microsoft 365</strong> — for an organisation that already has it. Mail can go out from a shared mailbox. Its settings are typed on the Settings and Integrations pages.</li>
                    <li><strong>Google</strong> — for an organisation on Google Workspace. It needs a key file put on the server.</li>
                </ul>
                <p>Choose the route, and see what it still needs, at <a href="<?php echo htmlspecialchars(Site::url('admin/integrations/email'), ENT_QUOTES, 'UTF-8'); ?>">Admin, Integrations, Email</a>. Then use &ldquo;Send test&rdquo; there to send yourself a message.</p>
                <p>Two things to know. A message the portal cannot send straight away is <strong>kept and tried again</strong> for about a day by the mail queue; until it goes it is listed on that page as not sent, with the reason. The mail queue needs its own scheduled job, which you add once: the administrator&rsquo;s guide says how, under the SMTP mailbox. And if you set a limit on how many recipients an hour the portal may send to, mail over the limit waits in the same queue until the hour has room.</p>
                <p>Also check the three DNS records (SPF, DKIM and DMARC) on the domain you send from. They tell other mail systems that the portal may send as your domain, and without them mail tends to land in junk folders. The same page shows which it found.</p>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#step3">
                3. Authentication providers
            </button>
        </h2>
        <div id="step3" class="accordion-collapse collapse" data-bs-parent="#adminFirstSteps">
            <div class="accordion-body">
                <p>Decide how users will sign in.</p>
                <ul>
                    <li>Local username + password (always on; password policy enforced).</li>
                    <li>Microsoft 365 SSO — recommended for orgs already on 365.</li>
                    <li>Google SSO — for users with personal Google accounts.</li>
                    <li>Passkeys (WebAuthn) — phishing-resistant; works on all modern devices.</li>
                </ul>
                <p>Configure each at <a href="<?php echo htmlspecialchars(Site::url('admin/integrations'), ENT_QUOTES, 'UTF-8'); ?>">/admin/integrations</a>.</p>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#step4">
                4. Captcha
            </button>
        </h2>
        <div id="step4" class="accordion-collapse collapse" data-bs-parent="#adminFirstSteps">
            <div class="accordion-body">
                <p>Captcha protects sign-in and public forms (e.g. anonymous prayer requests) from bots.</p>
                <p>At <a href="<?php echo htmlspecialchars(Site::url('admin/captcha'), ENT_QUOTES, 'UTF-8'); ?>">/admin/captcha</a>, choose your provider (Cloudflare Turnstile / Google reCAPTCHA / hCaptcha) and set the priority order.</p>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#step5">
                5. Retention cron
            </button>
        </h2>
        <div id="step5" class="accordion-collapse collapse" data-bs-parent="#adminFirstSteps">
            <div class="accordion-body">
                <p>Activity logs and error logs grow unbounded. A retention sweeper deletes old rows nightly.</p>
                <ol>
                    <li>Choose a long random token and save it as <code>maintenance.cronToken</code> in Settings. It is a portal-wide setting, so a global administrator has to do this. The <a href="<?php echo htmlspecialchars(Site::url('admin/maintenance/retention'), ENT_QUOTES, 'UTF-8'); ?>">retention page</a> shows what the sweep will remove.</li>
                    <li>Add this to DreamHost's cron scheduler (or your shared-hosting equivalent), replacing YOUR-PORTAL with your portal's address:
                        <pre class="bg-body-tertiary p-2 rounded"><code>0 3 * * * curl -fsS "https://YOUR-PORTAL/cron/retention-sweep?token=YOUR_TOKEN" &gt; /dev/null</code></pre>
                    </li>
                </ol>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#step6">
                6. Backups
            </button>
        </h2>
        <div id="step6" class="accordion-collapse collapse" data-bs-parent="#adminFirstSteps">
            <div class="accordion-body">
                <p>The portal takes a JSON snapshot of every table automatically before each upgrade. To verify it works:</p>
                <ol>
                    <li>Go to <a href="<?php echo htmlspecialchars(Site::url('admin/maintenance/backup'), ENT_QUOTES, 'UTF-8'); ?>">/admin/maintenance/backup</a>.</li>
                    <li>Click "Run snapshot now".</li>
                    <li>Confirm a snapshot directory appears under <code>web/_backups/</code>.</li>
                </ol>
                <p>Also wire the backup freshness check to cron (similar to retention) — see <a href="<?php echo htmlspecialchars(Site::url('admin/maintenance/backup-check'), ENT_QUOTES, 'UTF-8'); ?>">/admin/maintenance/backup-check</a>.</p>
                <p><strong>Print this for the 3am drawer:</strong>
                <a href="<?php echo htmlspecialchars(Site::url('help/disaster-recovery'), ENT_QUOTES, 'UTF-8'); ?>">/help/disaster-recovery</a> — quick reference + link to the full command-by-command runbook.</p>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#step7">
                7. Users &amp; roles
            </button>
        </h2>
        <div id="step7" class="accordion-collapse collapse" data-bs-parent="#adminFirstSteps">
            <div class="accordion-body">
                <p>Add your first volunteers at <a href="<?php echo htmlspecialchars(Site::url('admin/users'), ENT_QUOTES, 'UTF-8'); ?>">/admin/users</a>.</p>
                <p>For bulk onboarding, use the invite workflow (issue #239 — implementation pending). Generate an invite link, send it to the recipient, they self-register with their chosen password.</p>
            </div>
        </div>
    </div>

    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#step8">
                8. First content
            </button>
        </h2>
        <div id="step8" class="accordion-collapse collapse" data-bs-parent="#adminFirstSteps">
            <div class="accordion-body">
                <p>Seed the portal with a few entries so it doesn't look empty on day 1:</p>
                <ul>
                    <li>Post a welcome announcement: <a href="<?php echo htmlspecialchars(Site::url('announcements'), ENT_QUOTES, 'UTF-8'); ?>">/announcements</a> → New.</li>
                    <li>Create the next 4 weeks of calendar events: <a href="<?php echo htmlspecialchars(Site::url('calendar'), ENT_QUOTES, 'UTF-8'); ?>">/calendar</a>.</li>
                    <li>Set the current leadership roster: <a href="<?php echo htmlspecialchars(Site::url('leadership'), ENT_QUOTES, 'UTF-8'); ?>">/leadership</a>.</li>
                    <li>Upload your church constitution / safeguarding policy: <a href="<?php echo htmlspecialchars(Site::url('documents'), ENT_QUOTES, 'UTF-8'); ?>">/documents</a>.</li>
                </ul>
            </div>
        </div>
    </div>

</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
