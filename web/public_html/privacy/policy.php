<?php
// Path: public_html/privacy/policy.php
/**
 * Public — Privacy policy page surfacing the GDPR erasure policy.
 *
 * @package   Portal\Privacy
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/235
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;

Auth::ensureSession();

$settings = App::settings();
$portalName = (string) ($settings['site']['name'] ?? 'Portal');
$contact    = (string) ($settings['privacy']['erasureContact'] ?? '');
$years      = (int) ($settings['privacy']['financialRetentionYears'] ?? 6);

$pageTitle   = 'Privacy policy';
$pageSection = 'privacy';
$breadcrumbs = ['Dashboard' => '/', 'Privacy policy' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-3"><i class="fa-solid fa-shield-halved me-2"></i>Privacy policy</h1>

<p>This portal is operated by <strong><?php echo htmlspecialchars($portalName, ENT_QUOTES, 'UTF-8'); ?></strong>.
We handle personal data under the UK GDPR and the Data Protection Act 2018.</p>

<h3>Your rights</h3>
<ul>
    <li><strong>Article 15 — Right of access</strong>: download everything we hold about you from <a href="/account/my-data">/account/my-data</a>.</li>
    <li><strong>Article 17 — Right to erasure</strong>: file a request at <a href="/account/erasure-request">/account/erasure-request</a>. We respond within one month.</li>
    <li><strong>Article 16 — Right to rectification</strong>: edit your profile via <a href="/account">/account</a>.</li>
    <li><strong>Article 21 — Right to object</strong>: unsubscribe from any newsletter via the link in its footer.</li>
</ul>

<h3>Data we hold</h3>
<p>Sign in to see the exact inventory at <a href="/account/my-data">/account/my-data</a>. In summary we hold: profile information (name, email), authentication tokens, app-specific records (attendance, expenses, giving, etc.), and audit logs.</p>

<h3>Retention policy</h3>
<ul>
    <li><strong>Financial records</strong> (expenses, giving, payments) are retained for <?php echo (int) $years; ?> years to comply with HMRC requirements. On erasure they are <em>anonymised</em>, not deleted: your `userID` is removed but aggregate totals remain.</li>
    <li><strong>Authentication artefacts</strong> (sessions, WebAuthn credentials, TOTP backup codes, OAuth tokens) are deleted on request.</li>
    <li><strong>User-generated content</strong> (announcements, recordings, prayer requests, etc.) is anonymised — the body remains but authorship is detached. Prayer requests additionally have submitter name/email blanked.</li>
    <li><strong>Your user row</strong> is anonymised with the tombstone <code>[Deleted User]</code> rather than deleted, so historical foreign-key links don't cascade-blow attribution we want to keep.</li>
</ul>

<h3>Contact</h3>
<p>Data protection enquiries: <?php if ($contact !== ''): ?><a href="mailto:<?php echo htmlspecialchars($contact, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($contact, ENT_QUOTES, 'UTF-8'); ?></a><?php else: ?><em>not configured — see your portal admin.</em><?php endif; ?></p>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
