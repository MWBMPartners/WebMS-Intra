<?php
// Path: public_html/admin/ai-assist/prompt.php
/**
 * Admin — Edit a prompt template for one kind.
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

$siteId = Site::id();
$kind   = (string) ($_GET['kind'] ?? $_POST['kind'] ?? 'announcement');
if (in_array($kind, AiAssistant::KINDS, true) === false) {
    $kind = 'announcement';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        http_response_code(400);
        exit('Bad request');
    }
    $template = (string) ($_POST['template'] ?? '');
    $active   = isset($_POST['isActive']) === true;
    if (trim($template) !== '') {
        AiAssistant::upsertPrompt($siteId, $kind, $template, $active);
        $_SESSION['flash_msg']  = 'Prompt template saved.';
        $_SESSION['flash_type'] = 'success';
    }
    header('Location: /admin/ai-assist/prompt?kind=' . urlencode($kind));
    exit();
}

$current = AiAssistant::activeTemplate($siteId, $kind);
$isDefault = $current === (AiAssistant::defaultPrompts()[$kind] ?? '');

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'Prompt: ' . $kind;
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'AI Assist' => '/admin/ai-assist', $kind => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-pen me-2"></i>Prompt — <?php echo htmlspecialchars($kind, ENT_QUOTES, 'UTF-8'); ?></h1>

<p class="text-secondary">
    Placeholders <code>{user_input}</code>, <code>{audience}</code>, <code>{org_type}</code> are filled in at request time.
    <?php if ($isDefault === true): ?>
        <span class="badge bg-secondary ms-2">default (not yet customised)</span>
    <?php endif; ?>
</p>

<form method="post" class="card"><div class="card-body">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="kind" value="<?php echo htmlspecialchars($kind, ENT_QUOTES, 'UTF-8'); ?>">
    <textarea class="form-control font-monospace" name="template" rows="14" required><?php echo htmlspecialchars($current, ENT_QUOTES, 'UTF-8'); ?></textarea>
    <div class="form-check mt-2">
        <input type="checkbox" class="form-check-input" id="isActive" name="isActive" value="1" checked>
        <label class="form-check-label" for="isActive">Active (uncheck to fall back to default)</label>
    </div>
    <div class="mt-3">
        <button class="btn btn-primary" type="submit">Save template</button>
        <a href="/admin/ai-assist" class="btn btn-outline-secondary">Cancel</a>
    </div>
</div></form>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
