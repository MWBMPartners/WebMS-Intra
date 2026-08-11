<?php
// Path: _apps/admin/integrations/cloudflare-stream/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Cloudflare Stream credentials 🎥🔑 (#386 Phase 1)
 * -----------------------------------------------------------------------------
 * Configuration page for the Event Team Hub's video playback (and the
 * Phase 1.5 direct-upload follow-up). Two DISTINCT credentials, kept
 * separate on purpose:
 *
 *   - Signing key (`signingKeyID` + `signingKeyPem`) — used TODAY by
 *     `Portal\Core\VideoEmbed::signedToken()` to mint short-lived RS256
 *     playback tokens for Cloudflare Stream videos whose
 *     `requireSignedURLs` flag is on. Create it once via
 *     `POST /accounts/{id}/stream/keys` (the Cloudflare dashboard has no
 *     UI for this — see DEV_NOTES.md for the full runbook).
 *   - API token (`apiToken`) — the Cloudflare *management* API credential
 *     (mint/poll/edit/delete direct-creator-uploads). NOT used by
 *     anything in Phase 1 — the field is captured now purely so the
 *     Phase 1.5 upload build needs no follow-up migration or admin-page
 *     change, only new POST handlers gated behind
 *     `CloudflareStream::isConfigured()`.
 *
 * All fields are global (`siteID IS NULL`), matching the Zoom integration
 * precedent — one Cloudflare Stream account per install.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/386
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$settings = App::settings()['cfstream'] ?? [];

$enabled                  = (string) ($settings['enabled'] ?? 'false') === 'true';
$accountId                = (string) ($settings['accountID'] ?? '');
$customerCode             = (string) ($settings['customerCode'] ?? '');
$hasApiToken              = ((string) ($settings['apiToken'] ?? '')) !== '';
$signingKeyId             = (string) ($settings['signingKeyID'] ?? '');
$hasSigningKeyPem         = ((string) ($settings['signingKeyPem'] ?? '')) !== '';
$tokenTtlSeconds          = (string) ($settings['tokenTtlSeconds'] ?? '21600');
$maxUploadDurationSeconds = (string) ($settings['maxUploadDurationSeconds'] ?? '3600');
$uploadMintPerHour        = (string) ($settings['uploadMintPerHour'] ?? '20');
$defaultRequireSigned     = (string) ($settings['defaultRequireSignedUrls'] ?? 'true') === 'true';
$allowedOrigins           = (string) ($settings['allowedOrigins'] ?? '');

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');

$pageTitle   = 'Cloudflare Stream Integration';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Integrations' => '/admin/integrations', 'Cloudflare Stream' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-video me-2"></i>Cloudflare Stream Integration</h1>
<p class="text-secondary">
    Powers video playback on the Calendar <a href="/calendar">Team Hub</a> (#386) — pasted YouTube / Vimeo links
    play with no configuration needed here; Cloudflare Stream links require the signing key below for
    signed-URL playback.
</p>

<div class="alert alert-info small">
    <strong>Two separate credentials:</strong>
    <ul class="mb-0 mt-1">
        <li><strong>Signing key</strong> — mints short-lived playback tokens for signed Cloudflare Stream videos. Required for any Cloudflare video with "Requires Signed URL" ticked.</li>
        <li><strong>API token</strong> — reserved for the direct-upload feature (coming soon); not used by anything in this release.</li>
    </ul>
</div>

<div class="card mb-4">
    <div class="card-header"><strong>Account</strong></div>
    <div class="card-body">
        <form method="post" action="/admin/integrations/cloudflare-stream/save" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">

            <div class="col-12">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="cfEnabled" name="enabled" value="1" <?php echo $enabled === true ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="cfEnabled">Enable Cloudflare Stream video embeds</label>
                </div>
            </div>

            <div class="col-md-6">
                <label class="form-label">Account ID</label>
                <input type="text" name="accountID" class="form-control font-monospace" maxlength="32"
                       value="<?php echo htmlspecialchars($accountId, ENT_QUOTES, 'UTF-8'); ?>" placeholder="32-character hex account ID">
            </div>
            <div class="col-md-6">
                <label class="form-label">Customer code</label>
                <input type="text" name="customerCode" class="form-control font-monospace" maxlength="60"
                       value="<?php echo htmlspecialchars($customerCode, ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. 9qos900atmxxxxxx">
                <small class="text-muted">From your <code>customer-&lt;code&gt;.cloudflarestream.com</code> embed URLs. Public — appears in every video's page CSP.</small>
            </div>

            <div class="col-md-6">
                <label class="form-label">API token <?php echo $hasApiToken === true ? '<span class="badge bg-success">configured</span>' : '<span class="badge bg-secondary">not set</span>'; ?></label>
                <input type="password" name="apiToken" class="form-control" autocomplete="off"
                       placeholder="<?php echo $hasApiToken === true ? 'Leave blank to keep current' : 'Reserved for the direct-upload feature'; ?>">
                <small class="text-muted">Stream:Edit custom token, scoped to this account only. Never re-displayed once saved.</small>
            </div>
            <div class="col-md-6"></div>

            <div class="col-md-6">
                <label class="form-label">Signing key ID</label>
                <input type="text" name="signingKeyID" class="form-control font-monospace"
                       value="<?php echo htmlspecialchars($signingKeyId, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Signing key PEM <?php echo $hasSigningKeyPem === true ? '<span class="badge bg-success">configured</span>' : '<span class="badge bg-secondary">not set</span>'; ?></label>
                <textarea name="signingKeyPem" class="form-control font-monospace" rows="3" autocomplete="off"
                          placeholder="<?php echo $hasSigningKeyPem === true ? 'Leave blank to keep current' : '-----BEGIN PRIVATE KEY-----…'; ?>"></textarea>
                <small class="text-muted">Private half of the signing key pair. Never re-displayed once saved.</small>
            </div>

            <div class="col-md-4">
                <label class="form-label">Token TTL (seconds)</label>
                <input type="number" name="tokenTtlSeconds" class="form-control" min="60" max="86400"
                       value="<?php echo htmlspecialchars($tokenTtlSeconds, ENT_QUOTES, 'UTF-8'); ?>">
                <small class="text-muted">Default 21600 (6h) — long enough for a training evening, short enough that a leaked link dies same-day.</small>
            </div>
            <div class="col-md-4">
                <label class="form-label">Max upload duration (seconds)</label>
                <input type="number" name="maxUploadDurationSeconds" class="form-control" min="1" max="21600"
                       value="<?php echo htmlspecialchars($maxUploadDurationSeconds, ENT_QUOTES, 'UTF-8'); ?>">
                <small class="text-muted">Reserved for the direct-upload feature.</small>
            </div>
            <div class="col-md-4">
                <label class="form-label">Upload mints per hour (per user)</label>
                <input type="number" name="uploadMintPerHour" class="form-control" min="1" max="1000"
                       value="<?php echo htmlspecialchars($uploadMintPerHour, ENT_QUOTES, 'UTF-8'); ?>">
                <small class="text-muted">Reserved for the direct-upload feature.</small>
            </div>

            <div class="col-md-6 d-flex align-items-end">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="cfDefaultSigned" name="defaultRequireSignedUrls" value="1" <?php echo $defaultRequireSigned === true ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="cfDefaultSigned">Pre-tick "Requires Signed URL" on the upload form (Phase 1.5)</label>
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Allowed origins</label>
                <input type="text" name="allowedOrigins" class="form-control font-monospace"
                       value="<?php echo htmlspecialchars($allowedOrigins, ENT_QUOTES, 'UTF-8'); ?>" placeholder="portal.millrdsdacambridge.uk (blank = any)">
                <small class="text-muted">Comma-separated hostnames, no scheme. Reserved for the direct-upload feature.</small>
            </div>

            <div class="col-12">
                <button class="btn btn-primary" type="submit">Save settings</button>
            </div>
        </form>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
