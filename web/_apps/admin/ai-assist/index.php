<?php
// Path: public_html/admin/ai-assist/index.php
/**
 * Admin — AI Assist config + usage dashboard.
 *
 * @package   Portal\Admin
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/277
 */

declare(strict_types=1);

use Portal\Core\AiAssistant;
use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$db       = App::db();
$siteId   = Site::id();
$settings = App::settings()['ai_assist'] ?? [];
$enabled  = (string) ($settings['enabled'] ?? '0') === '1';
$provider = (string) ($settings['provider'] ?? 'anthropic');
$cap      = (int) ($settings['monthCapPence'] ?? 5000);
$dailyCap = (int) ($settings['userDailyCap'] ?? 20);
$audience = (string) ($settings['audience'] ?? 'congregation');

$hasAnth = ((string) ($settings['anthropic']['apiKey'] ?? '')) !== '';
$hasOa   = ((string) ($settings['openai']['apiKey']    ?? '')) !== '';
$localBase = (string) ($settings['local']['baseUrl'] ?? '');

$spend = AiAssistant::monthSpendPence($siteId);

$callsToday = 0;
$rs = $db->query('SELECT COUNT(*) AS n FROM tblAiUsage WHERE siteID = ' . (int) $siteId . ' AND DATE(occurredAt) = CURDATE()');
if ($rs !== false) {
    $row = $rs->fetch_assoc();
    $callsToday = (int) ($row['n'] ?? 0);
    $rs->free();
}

$recent = [];
$stmt = $db->prepare(
    'SELECT u.usageID, u.promptKind, u.provider, u.inputTokens, u.outputTokens, u.costPence, u.occurredAt, '
    . '       us.fullName '
    . 'FROM tblAiUsage u LEFT JOIN tblUsers us ON us.userID = u.userID '
    . 'WHERE u.siteID = ? ORDER BY u.occurredAt DESC LIMIT 30'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $recent[] = $r;
    }
    $stmt->close();
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'AI Assist';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'AI Assist' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-wand-magic-sparkles me-2"></i>AI Assist</h1>

<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card"><div class="card-body">
        <div class="small text-muted">This month spend</div>
        <div class="display-6">£<?php echo number_format($spend / 100, 2); ?></div>
        <div class="small text-muted">of £<?php echo number_format($cap / 100, 2); ?> cap</div>
    </div></div></div>
    <div class="col-md-4"><div class="card"><div class="card-body">
        <div class="small text-muted">Calls today</div>
        <div class="display-6"><?php echo (int) $callsToday; ?></div>
    </div></div></div>
    <div class="col-md-4"><div class="card"><div class="card-body">
        <div class="small text-muted">Active provider</div>
        <div class="display-6"><?php echo htmlspecialchars($provider, ENT_QUOTES, 'UTF-8'); ?></div>
    </div></div></div>
</div>

<div class="card mb-3">
    <div class="card-header"><strong>Configuration</strong></div>
    <div class="card-body">
        <form method="post" action="/admin/ai-assist/save" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="col-md-3">
                <label class="form-label">Provider</label>
                <select class="form-select" name="provider">
                    <option value="anthropic" <?php echo $provider === 'anthropic' ? 'selected' : ''; ?>>Anthropic (Claude)</option>
                    <option value="openai"    <?php echo $provider === 'openai'    ? 'selected' : ''; ?>>OpenAI</option>
                    <option value="local"     <?php echo $provider === 'local'     ? 'selected' : ''; ?>>Local (ollama)</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Monthly cap (£)</label>
                <input type="number" min="0" step="0.01" class="form-control" name="monthCap" value="<?php echo number_format($cap / 100, 2, '.', ''); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Per-user daily cap</label>
                <input type="number" min="1" max="200" class="form-control" name="userDailyCap" value="<?php echo (int) $dailyCap; ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Audience</label>
                <input type="text" class="form-control" name="audience" value="<?php echo htmlspecialchars($audience, ENT_QUOTES, 'UTF-8'); ?>" maxlength="60">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="aiEnabled" name="enabled" value="1" <?php echo $enabled === true ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="aiEnabled">Enable</label>
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
            <div class="col-md-3">
                <h6 class="text-muted">OpenAI</h6>
                <label class="form-label small">API key <?php echo $hasOa === true ? '<span class="badge bg-success">set</span>' : ''; ?></label>
                <input type="password" class="form-control form-control-sm" name="oa_key" placeholder="<?php echo $hasOa === true ? 'Leave blank to keep' : ''; ?>" autocomplete="off">
                <label class="form-label small mt-2">Model</label>
                <input type="text" class="form-control form-control-sm" name="oa_model" value="<?php echo htmlspecialchars((string) ($settings['openai']['model'] ?? 'gpt-4o-mini'), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-3">
                <h6 class="text-muted">Local (ollama)</h6>
                <label class="form-label small">Base URL</label>
                <input type="text" class="form-control form-control-sm" name="local_base" value="<?php echo htmlspecialchars($localBase, ENT_QUOTES, 'UTF-8'); ?>">
                <label class="form-label small mt-2">Model</label>
                <input type="text" class="form-control form-control-sm" name="local_model" value="<?php echo htmlspecialchars((string) ($settings['local']['model'] ?? 'llama3.2'), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-12">
                <button class="btn btn-primary" type="submit">Save</button>
            </div>
        </form>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><strong>Prompt templates</strong></div>
    <div class="card-body">
        <div class="row g-2">
            <?php foreach (AiAssistant::KINDS as $k): ?>
                <div class="col-md-6">
                    <a href="/admin/ai-assist/prompt?kind=<?php echo urlencode($k); ?>" class="btn btn-outline-secondary w-100 text-start">
                        <i class="fa-solid fa-pen me-1"></i><?php echo htmlspecialchars($k, ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><strong>Recent usage</strong></div>
    <div class="card-body">
        <?php if (count($recent) === 0): ?>
            <p class="text-muted">No AI calls recorded yet.</p>
        <?php else: ?>
            <div class="portal-data-list">
                <?php foreach ($recent as $r): ?>
                    <div class="row py-1 border-bottom small">
                        <div class="col-md-2 text-muted"><?php echo htmlspecialchars(date('d/m H:i', (int) strtotime((string) $r['occurredAt'])), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-3"><?php echo htmlspecialchars((string) ($r['fullName'] ?? 'system'), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-2"><span class="badge bg-light text-dark"><?php echo htmlspecialchars((string) $r['promptKind'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div class="col-md-2 text-muted"><?php echo htmlspecialchars((string) $r['provider'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-md-2 text-muted"><?php echo (int) $r['inputTokens']; ?> in / <?php echo (int) $r['outputTokens']; ?> out</div>
                        <div class="col-md-1 text-end">p<?php echo (int) $r['costPence']; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
