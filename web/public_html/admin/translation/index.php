<?php
// Path: public_html/admin/translation/index.php
/**
 * Admin — Translation provider config + cost dashboard.
 *
 * @package   Portal\Admin
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/278
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Translation;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$db       = App::db();
$settings = App::settings()['translation'] ?? [];
$enabled  = (string) ($settings['enabled'] ?? '0') === '1';
$provider = (string) ($settings['provider'] ?? 'anthropic');
$cap      = (int) ($settings['monthCapPence'] ?? 5000);

$hasAnth = ((string) ($settings['anthropic']['apiKey'] ?? '')) !== '';
$hasOa   = ((string) ($settings['openai']['apiKey']    ?? '')) !== '';
$hasGoog = ((string) ($settings['google']['apiKey']    ?? '')) !== '';
$hasDeep = ((string) ($settings['deepl']['apiKey']     ?? '')) !== '';
$libBase = (string) ($settings['libre']['baseUrl'] ?? '');
$hasLib  = ((string) ($settings['libre']['apiKey']     ?? '')) !== '';

$spend = Translation::monthSpendPence();

$cacheStats = ['total' => 0, 'languages' => 0];
$rs = $db->query('SELECT COUNT(*) AS n, COUNT(DISTINCT targetLanguage) AS langs FROM tblContentTranslation');
if ($rs !== false) {
    $cacheStats = $rs->fetch_assoc() ?? $cacheStats;
    $rs->free();
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'Translation';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Translation' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-language me-2"></i>Translation</h1>

<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card"><div class="card-body">
        <div class="small text-muted">Cached translations</div>
        <div class="display-6"><?php echo (int) $cacheStats['total']; ?></div>
        <div class="small text-muted"><?php echo (int) ($cacheStats['langs'] ?? $cacheStats['languages']); ?> target language<?php echo ((int) ($cacheStats['langs'] ?? 0)) === 1 ? '' : 's'; ?></div>
    </div></div></div>
    <div class="col-md-4"><div class="card"><div class="card-body">
        <div class="small text-muted">This month spend</div>
        <div class="display-6">£<?php echo number_format($spend / 100, 2); ?></div>
        <?php if ($cap > 0): ?>
            <div class="small text-muted">of £<?php echo number_format($cap / 100, 2); ?> cap</div>
        <?php endif; ?>
    </div></div></div>
    <div class="col-md-4"><div class="card"><div class="card-body">
        <div class="small text-muted">Active provider</div>
        <div class="display-6"><?php echo htmlspecialchars($provider, ENT_QUOTES, 'UTF-8'); ?></div>
    </div></div></div>
</div>

<div class="card">
    <div class="card-header"><strong>Configuration</strong></div>
    <div class="card-body">
        <form method="post" action="/admin/translation/save" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="col-md-3">
                <label class="form-label">Provider</label>
                <select class="form-select" name="provider">
                    <option value="anthropic" <?php echo $provider === 'anthropic' ? 'selected' : ''; ?>>Anthropic (Claude)</option>
                    <option value="openai"    <?php echo $provider === 'openai'    ? 'selected' : ''; ?>>OpenAI</option>
                    <option value="google"    <?php echo $provider === 'google'    ? 'selected' : ''; ?>>Google Cloud Translation</option>
                    <option value="deepl"     <?php echo $provider === 'deepl'     ? 'selected' : ''; ?>>DeepL</option>
                    <option value="libre"     <?php echo $provider === 'libre'     ? 'selected' : ''; ?>>LibreTranslate (self-hosted)</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Monthly cap (£)</label>
                <input type="number" min="0" step="0.01" class="form-control" name="monthCap" value="<?php echo number_format($cap / 100, 2, '.', ''); ?>">
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="trEnabled" name="enabled" value="1" <?php echo $enabled === true ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="trEnabled">Enable translation</label>
                </div>
            </div>
            <hr>
            <div class="col-md-6">
                <h6 class="text-muted">Anthropic (recommended)</h6>
                <label class="form-label small">API key <?php echo $hasAnth === true ? '<span class="badge bg-success">set</span>' : ''; ?></label>
                <input type="password" class="form-control form-control-sm" name="anth_key" placeholder="<?php echo $hasAnth === true ? 'Leave blank to keep' : 'sk-ant-…'; ?>" autocomplete="off">
                <label class="form-label small mt-2">Model</label>
                <input type="text" class="form-control form-control-sm" name="anth_model" value="<?php echo htmlspecialchars((string) ($settings['anthropic']['model'] ?? 'claude-haiku-4-5-20251001'), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-6">
                <h6 class="text-muted">OpenAI</h6>
                <label class="form-label small">API key <?php echo $hasOa === true ? '<span class="badge bg-success">set</span>' : ''; ?></label>
                <input type="password" class="form-control form-control-sm" name="oa_key" placeholder="<?php echo $hasOa === true ? 'Leave blank to keep' : ''; ?>" autocomplete="off">
                <label class="form-label small mt-2">Model</label>
                <input type="text" class="form-control form-control-sm" name="oa_model" value="<?php echo htmlspecialchars((string) ($settings['openai']['model'] ?? 'gpt-4o-mini'), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-4">
                <h6 class="text-muted">Google</h6>
                <label class="form-label small">API key <?php echo $hasGoog === true ? '<span class="badge bg-success">set</span>' : ''; ?></label>
                <input type="password" class="form-control form-control-sm" name="goog_key" placeholder="<?php echo $hasGoog === true ? 'Leave blank to keep' : ''; ?>" autocomplete="off">
            </div>
            <div class="col-md-4">
                <h6 class="text-muted">DeepL</h6>
                <label class="form-label small">API key <?php echo $hasDeep === true ? '<span class="badge bg-success">set</span>' : ''; ?></label>
                <input type="password" class="form-control form-control-sm" name="deepl_key" placeholder="<?php echo $hasDeep === true ? 'Leave blank to keep' : ''; ?>" autocomplete="off">
            </div>
            <div class="col-md-4">
                <h6 class="text-muted">LibreTranslate</h6>
                <label class="form-label small">Base URL</label>
                <input type="text" class="form-control form-control-sm" name="libre_base" value="<?php echo htmlspecialchars($libBase, ENT_QUOTES, 'UTF-8'); ?>">
                <label class="form-label small mt-2">API key (if required) <?php echo $hasLib === true ? '<span class="badge bg-success">set</span>' : ''; ?></label>
                <input type="password" class="form-control form-control-sm" name="libre_key" placeholder="<?php echo $hasLib === true ? 'Leave blank to keep' : 'optional'; ?>" autocomplete="off">
            </div>
            <div class="col-12">
                <button class="btn btn-primary" type="submit">Save</button>
            </div>
        </form>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
