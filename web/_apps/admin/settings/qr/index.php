<?php
// Path: public_html/admin/settings/qr/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — QR provider configuration 🔳
 * -----------------------------------------------------------------------------
 * Pick local generation vs the external CueRCode tracked-QR service.
 *
 * CueRCode integration (#275 — repo: github.com/MWBMPartners/CueRCode):
 *   When provider='cuercode' and credentials are set, Portal\Core\Qr
 *   first calls CueRCode to register the content + receive a tracking
 *   URL, then encodes the tracking URL into the QR. Scans flow through
 *   CueRCode (analytics) before landing on the underlying portal page.
 *
 * The adapter shape in Portal\Core\Qr::resolveContent() targets a generic
 * { POST /register, body: {url, purpose}, response: {tracking_url} }
 * contract. Update the request shape to match CueRCode's actual API
 * once the project ships its public spec.
 *
 * WHO MAY SAVE HERE, AND WHY IT IS NARROWER THAN WHO MAY LOOK
 * -----------------------------------------------------------------------------
 * The form on this page saves portal-wide rows (the INSERT below puts NULL in
 * the siteID column), so the QR provider and the CueRCode account apply to
 * EVERY organisation on the installation.
 *
 * Until 13 September 2026 the only check was App::isAdmin(), which is true for
 * an administrator of a SINGLE organisation as well as for a global
 * administrator (see web/_core/App.php). So an administrator of one
 * organisation could point every organisation's QR codes at a tracking
 * service and account of their own choosing — and, as the note above
 * explains, scans then pass through that service before reaching the portal.
 * The owner decided that settings affecting every organisation are for a
 * global administrator only.
 *
 * An administrator of one organisation can still open the page. They see it
 * read-only: the reason on the page, every field disabled and no Save button.
 * That is a courtesy only. The refusal in the handler below is what enforces
 * the rule, because a form can be sent without ever opening the page.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/275
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/495
 * @link      https://github.com/MWBMPartners/CueRCode
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Qr;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

// 🛡️ Looking is allowed for any administrator; saving is not. See the note in
//    the file header for why. Worked out once and used by both the handler and
//    the form below, so the two can never disagree.
$mayChangePortalWideSettings = App::isRootAdmin();

$flash = '';
$flashType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🔑 The form token is checked exactly ONCE per request, and only then is
    //    the person's permission looked at.
    //
    //    WHAT WAS WRONG: the first version of this handler asked
    //    Auth::verifyCsrf() in the refusal branch AND again in the save branch.
    //    A successful check replaces the token with a new one (see
    //    Auth::verifyCsrf() in web/_core/Auth.php — it does that so a captured
    //    form cannot be replayed). So for a global administrator the first
    //    check passed and used the token up, and the second check then compared
    //    the form against a token that no longer existed and failed. The save
    //    never ran, nothing was written and no message appeared: Save looked as
    //    if it worked and did nothing.
    //
    //    Asking once and keeping the answer in a variable cannot go wrong that
    //    way, however the branches below are rearranged later.
    $tokenIsValid = Auth::verifyCsrf($_POST['csrf_token'] ?? '');

    if ($tokenIsValid === false) {
        // 🚫 Missing, wrong or expired token (for example the page was left
        //    open for a long time, or the request came from another website).
        //    This used to be ignored in silence, which reads as "Save is
        //    broken". Nothing is written and nothing is logged, so a forged
        //    request from another website cannot fill the activity log.
        $flash = 'This form had expired or was not sent from this page, so nothing '
            . 'has been changed. Please try again.';
        $flashType = 'danger';
    } elseif ($mayChangePortalWideSettings === false) {
        // 🚧 Refused BEFORE anything is written: every key on this page is
        //    portal-wide, so this one check covers the whole form. The refusal
        //    is worded rather than a bare "forbidden", because an administrator
        //    who presses Save and is told nothing assumes the portal is broken
        //    and tries again. It sits after the form-token check, so a forged
        //    request from another website cannot fill the activity log with
        //    refusals.
        $flash = 'These settings are portal-wide: they apply to every '
            . 'organisation on this installation, not only yours. Only a global '
            . 'administrator can change them. Nothing has been changed.';
        $flashType = 'danger';
        Logger::activity(
            'SettingsGroupSaveRefused',
            'Refused: portal-wide settings group "qr" may only be changed by a global administrator',
            $_SESSION['user_id'] ?? null
        );
    } else {
        // 💾 Only a global administrator with a valid token reaches this point.
        $db = App::db();

        // The two ordinary, non-secret settings. Saved as before.
        $values = [
            'portal.qr.provider'              => in_array($_POST['provider'] ?? 'local', ['local','cuercode'], true) ? (string) $_POST['provider'] : 'local',
            'portal.qr.cuercode.api_endpoint' => trim((string) ($_POST['endpoint'] ?? '')),
        ];

        // 🔐 The CueRCode API key is a secret, and is handled separately.
        //
        //    WHAT WAS WRONG, three things at once:
        //
        //    1. A blank box wiped the stored key. The key was written on every
        //       save whatever was typed, yet the form says "Leave empty to keep
        //       current" — and the box is always empty when the page opens,
        //       because a saved secret is never put back into the page. So
        //       changing only the provider or the address quietly deleted the
        //       key.
        //    2. It was stored as plain, readable text, even though the row is
        //       marked isSensitive = 1 ("encrypted at rest") and the form
        //       promised encryption.
        //    3. On an installation where the row did not already exist, the
        //       INSERT also copied the key into defaultValue, so a readable copy
        //       of the secret sat in the "default" column as well.
        //
        //    NOW: a blank box leaves the stored key alone (nothing is written to
        //    that row at all). A new key is encrypted with encrypt_setting()
        //    from web/_core/bootstrap.php — the same helper every other secret
        //    setting uses, for example web/_apps/payments/save.php and
        //    web/_apps/admin/captcha/save.php — and stored with isSensitive = 1.
        //    defaultValue is set to '' (what migration 085 seeds for this key),
        //    which also clears any copy an earlier save left there.
        //
        //    What this CANNOT do: a key saved as plain text by the old code is
        //    not converted. It stays as it is until somebody types the key in
        //    again. There is also no way to remove a key from this page; choose
        //    the Local provider and the key is simply not used.
        $newApiKey = trim((string) ($_POST['api_key'] ?? ''));
        $encryptedApiKey = null;

        try {
            // Encrypt FIRST, before anything is written, so that a missing
            // encryption key file stops the whole save instead of leaving the
            // provider changed and the secret unsaved. If the helper does not
            // exist at all, refuse rather than fall back to storing the secret
            // as readable text.
            if ($newApiKey !== '') {
                if (function_exists('encrypt_setting') === false) {
                    throw new \RuntimeException('the encryption helper is not available, so the API key was not stored.');
                }
                $encryptedApiKey = encrypt_setting($newApiKey);
            }

            foreach ($values as $key => $val) {
                $stmt = $db->prepare(
                    'INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) '
                    . 'VALUES (NULL, ?, ?, ?, 0) '
                    . 'ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue), isSensitive = VALUES(isSensitive)'
                );
                if ($stmt !== false) {
                    $stmt->bind_param('sss', $key, $val, $val);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            if ($encryptedApiKey !== null) {
                $stmt = $db->prepare(
                    "INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) "
                    . "VALUES (NULL, 'portal.qr.cuercode.api_key', ?, '', 1) "
                    . "ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue), defaultValue = '', isSensitive = 1"
                );
                if ($stmt !== false) {
                    $stmt->bind_param('s', $encryptedApiKey);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            $flash = $encryptedApiKey !== null
                ? 'QR settings saved, including the new API key.'
                : 'QR settings saved. The API key was left as it was.';
            $flashType = 'success';
        } catch (\Throwable $e) {
            $flash = 'Save failed: ' . $e->getMessage();
            $flashType = 'danger';
        }
    }
}

$settings = App::settings();
$provider = (string) ($settings['portal']['qr']['provider'] ?? 'local');
$endpoint = (string) ($settings['portal']['qr']['cuercode']['api_endpoint'] ?? '');
$apiKey   = (string) ($settings['portal']['qr']['cuercode']['api_key'] ?? '');
$apiKeyMasked = $apiKey !== '' ? str_repeat('•', max(8, min(strlen($apiKey), 24))) : '';

$pageTitle   = 'QR Code Settings';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Settings' => '/admin/settings', 'QR Codes' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();

// Sample QR for preview.
$portalUrl = (string) ($settings['site']['url'] ?? 'https://example.invalid/');
?>

<h1 class="mb-3"><i class="fa-solid fa-qrcode me-2"></i>QR Codes</h1>
<p class="text-muted">Pick local generation or the external <a href="https://github.com/MWBMPartners/CueRCode" target="_blank" rel="noopener">CueRCode</a> tracked-QR service.</p>

<?php if ($flash !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<?php
// 🔒 Added to every control when the person looking may not save. This is a
//    COURTESY, not the control: a disabled field is only a hint to the browser
//    and anybody can send the form anyway. The refusal in the save handler at
//    the top of this file is what actually enforces the rule.
$readOnlyAttr = $mayChangePortalWideSettings === false ? ' disabled' : '';
?>

<?php if ($mayChangePortalWideSettings === false): ?>
    <!-- 👀 Read-only notice. Shown instead of letting somebody fill the form in
         and only find out it was refused after they pressed Save. -->
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-2"></i>
        These settings apply to <strong>every organisation</strong> on this
        installation, not only yours, so only a global administrator can change
        them. You can see the current choice here.
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-md-8">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="card mb-3">
                <div class="card-body">
                    <h2 class="h6 mb-3">Provider</h2>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="provider" id="p_local" value="local" <?php echo $provider === 'local' ? 'checked' : ''; ?><?php echo $readOnlyAttr; ?>>
                        <label class="form-check-label" for="p_local">
                            <strong>Local</strong> — generate in-portal with no external dependency.
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="provider" id="p_cuer" value="cuercode" <?php echo $provider === 'cuercode' ? 'checked' : ''; ?><?php echo $readOnlyAttr; ?>>
                        <label class="form-check-label" for="p_cuer">
                            <strong>CueRCode</strong> — tracked QRs via the MWBM CueRCode service. Scans flow through CueRCode for analytics before landing on the portal page.
                        </label>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-body">
                    <h2 class="h6">CueRCode credentials</h2>
                    <p class="form-text mb-2">Required only when provider = CueRCode.</p>
                    <div class="mb-2">
                        <label class="form-label small">API endpoint</label>
                        <input type="url" name="endpoint" class="form-control form-control-sm" placeholder="https://api.cuercode.example/v1"
                               value="<?php echo htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $readOnlyAttr; ?>>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">API key</label>
                        <input type="password" name="api_key" class="form-control form-control-sm" autocomplete="new-password"
                               placeholder="<?php echo $apiKeyMasked !== '' ? htmlspecialchars($apiKeyMasked, ENT_QUOTES, 'UTF-8') . ' (currently set)' : 'unset'; ?>"<?php echo $readOnlyAttr; ?>>
                        <div class="form-text">Stored encrypted via the existing settings sensitive-value pipeline. Leave empty to keep current.</div>
                    </div>
                </div>
            </div>

            <?php if ($mayChangePortalWideSettings === true): ?>
                <button type="submit" class="btn btn-primary">Save</button>
            <?php endif; ?>
            <a href="/admin/settings" class="btn btn-outline-secondary">All settings</a>
        </form>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h2 class="h6">Sample QR (your portal URL)</h2>
                <object data="/qr?content=<?php echo urlencode($portalUrl); ?>&size=200" type="image/svg+xml" style="max-width:200px"></object>
                <p class="small text-muted mt-2 mb-0"><code><?php echo htmlspecialchars($portalUrl, ENT_QUOTES, 'UTF-8'); ?></code></p>
            </div>
        </div>
        <div class="card mt-3">
            <div class="card-body small">
                <h2 class="h6">Programmatic use</h2>
                <pre class="mb-0"><code>use Portal\Core\Qr;
$content = Qr::resolveContent($url, 'invite');
[$mime, $bytes] = array_values(Qr::generate($content));</code></pre>
            </div>
        </div>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
