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
 * @package   Portal\Admin
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/275
 * @link      https://github.com/MWBMPartners/CueRCode
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Qr;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

$flash = '';
$flashType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::verifyCsrf($_POST['csrf_token'] ?? '') === true) {
    $db = App::db();
    $values = [
        'portal.qr.provider'              => in_array($_POST['provider'] ?? 'local', ['local','cuercode'], true) ? (string) $_POST['provider'] : 'local',
        'portal.qr.cuercode.api_endpoint' => trim((string) ($_POST['endpoint'] ?? '')),
        'portal.qr.cuercode.api_key'      => trim((string) ($_POST['api_key']  ?? '')),
    ];
    try {
        foreach ($values as $key => $val) {
            $isSensitive = $key === 'portal.qr.cuercode.api_key' ? 1 : 0;
            $stmt = $db->prepare(
                'INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) '
                . 'VALUES (NULL, ?, ?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue), isSensitive = VALUES(isSensitive)'
            );
            if ($stmt !== false) {
                $stmt->bind_param('sssi', $key, $val, $val, $isSensitive);
                $stmt->execute();
                $stmt->close();
            }
        }
        $flash = 'QR settings saved.';
        $flashType = 'success';
    } catch (\Throwable $e) {
        $flash = 'Save failed: ' . $e->getMessage();
        $flashType = 'danger';
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

<div class="row g-3">
    <div class="col-md-8">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="card mb-3">
                <div class="card-body">
                    <h2 class="h6 mb-3">Provider</h2>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="provider" id="p_local" value="local" <?php echo $provider === 'local' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="p_local">
                            <strong>Local</strong> — generate in-portal with no external dependency.
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="provider" id="p_cuer" value="cuercode" <?php echo $provider === 'cuercode' ? 'checked' : ''; ?>>
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
                               value="<?php echo htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">API key</label>
                        <input type="password" name="api_key" class="form-control form-control-sm" autocomplete="new-password"
                               placeholder="<?php echo $apiKeyMasked !== '' ? htmlspecialchars($apiKeyMasked, ENT_QUOTES, 'UTF-8') . ' (currently set)' : 'unset'; ?>">
                        <div class="form-text">Stored encrypted via the existing settings sensitive-value pipeline. Leave empty to keep current.</div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Save</button>
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
