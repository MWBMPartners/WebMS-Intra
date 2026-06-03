<?php
// Path: public_html/privacy/index.php
/**
 * -----------------------------------------------------------------------------
 * Privacy & Data Protection 🇪🇺
 * -----------------------------------------------------------------------------
 * Public-facing page listing the data the portal stores about visitors and
 * registered users, the legal basis, retention windows, and rights under
 * GDPR / UK Data Protection Act 2018. Reads admin-editable settings under
 * the `privacy.*` prefix so each site can drop in their own controller name
 * + contact email without code changes.
 *
 * Closes #47.
 *
 * @package   Portal\Privacy
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;

$pageTitle   = 'Privacy & Data Protection';
$pageSection = '';
$breadcrumbs = ['Home' => '/', 'Privacy' => ''];

Auth::ensureSession();

$controllerName  = (string) (App::settings('privacy.controllerName')    ?? '');
$contactEmail    = (string) (App::settings('privacy.contactEmail')      ?? '');
$externalURL     = (string) (App::settings('privacy.policyURL')         ?? '');
$retentionDays   = (int)    (App::settings('privacy.dataRetentionDays') ?? '730');
$allowDelete     = (App::settings('privacy.allowAccountDelete') ?? 'true') === 'true';

// 🔀 If the admin has set an external policy URL, just redirect.
if ($externalURL !== '') {
    header('Location: ' . $externalURL, true, 302);
    exit();
}

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-3"><i class="fa-solid fa-shield-halved me-2"></i>Privacy &amp; Data Protection</h1>

<?php if ($controllerName === '' && $contactEmail === ''): ?>
    <div class="alert alert-warning small">
        <i class="fa-solid fa-triangle-exclamation me-1"></i>
        <strong>Admin notice:</strong> the data controller name and contact email are not yet
        configured. Set <code>privacy.controllerName</code> and <code>privacy.contactEmail</code>
        in Site Settings.
    </div>
<?php endif; ?>

<p class="lead">
    This page describes what personal data we hold, why we hold it, how long we keep it for,
    and the rights you have under the UK Data Protection Act 2018 / EU GDPR.
</p>

<h2 class="h4 mt-4">Data controller</h2>
<p>
    The data controller is
    <strong><?php echo $controllerName !== '' ? htmlspecialchars($controllerName, ENT_QUOTES, 'UTF-8') : '<em>not configured</em>'; ?></strong>.
    <?php if ($contactEmail !== ''): ?>
        Privacy enquiries:
        <a href="mailto:<?php echo htmlspecialchars($contactEmail, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($contactEmail, ENT_QUOTES, 'UTF-8'); ?>
        </a>.
    <?php endif; ?>
</p>

<h2 class="h4 mt-4">What we store</h2>
<ul>
    <li><strong>Account data</strong> — name, email, optional phone, hashed password (we never store passwords in plain text).</li>
    <li><strong>Authentication events</strong> — login successes, failures, password-reset requests, 2FA verifications. IP address + user agent for security audit.</li>
    <li><strong>Activity logs</strong> — what you did inside the portal (e.g. submitted a prayer request, recorded attendance, approved an expense). Used for incident investigation and reverting accidental changes.</li>
    <li><strong>Linked SSO accounts</strong> — if you signed in with Microsoft 365 or Google, the provider's "sub" identifier and the email they returned.</li>
    <li><strong>Uploaded content</strong> — anything you create inside the portal (event RSVPs, expense receipts, document library uploads, etc.).</li>
</ul>

<h2 class="h4 mt-4">Legal basis</h2>
<ul>
    <li><strong>Contract / Legitimate interest</strong> — your account exists because you're a member or staff member; running the portal is necessary to deliver the service.</li>
    <li><strong>Consent</strong> — for any analytics / marketing communications, we ask separately.</li>
    <li><strong>Legal obligation</strong> — financial records (e.g. expense audit trails) may be retained beyond the standard window where statute requires.</li>
</ul>

<h2 class="h4 mt-4">Retention</h2>
<p>
    Activity logs and error logs are pruned after
    <strong><?php echo (int) $retentionDays; ?> days</strong>
    by an automatic sweeper (see <code>audit.retentionDays</code> /
    <code>errors.retentionDays</code> settings). Account records remain
    while your account is active. When you delete your account, your name
    is removed and any logs that referenced you are detached.
</p>

<h2 class="h4 mt-4">Your rights</h2>
<ul>
    <li><strong>Access</strong> — request a copy of your data via
        <a href="/account/data-export">Account → Data Export</a>.</li>
    <li><strong>Rectification</strong> — update inaccurate data via
        <a href="/account">your Account page</a> or by contacting an admin.</li>
    <li><strong>Erasure</strong> —
        <?php if ($allowDelete === true): ?>
            request deletion via <a href="/account/delete">Account → Delete my account</a>.
        <?php else: ?>
            currently disabled on this site; contact an admin.
        <?php endif; ?>
    </li>
    <li><strong>Portability</strong> — the data export above is a JSON document you can take to another provider.</li>
    <li><strong>Object / Withdraw consent</strong> — at any time, for any processing that's based on consent.</li>
    <li><strong>Complain</strong> — to the ICO (UK) at
        <a href="https://ico.org.uk/" target="_blank" rel="noopener noreferrer">ico.org.uk</a>
        if you believe we're handling your data improperly.</li>
</ul>

<h2 class="h4 mt-4">Cookies</h2>
<p>
    The portal uses functionally-necessary cookies only: a session cookie for
    sign-in, a CSRF token, the "trust this device" 2FA cookie (only set if you
    explicitly tick the box), and small UI preferences (theme, language).
    No third-party tracking cookies. No analytics cookies are set without
    your consent.
</p>

<p class="text-muted small mt-5">
    This page is auto-generated from the portal's privacy settings. To customise
    the wording further, point <code>privacy.policyURL</code> at an external
    page and that will replace this one.
</p>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
