<?php
// Path: public_html/admin/integrations/email-templates.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Email Template Preview 📨
 * -----------------------------------------------------------------------------
 * List templates under web/_core/templates/email/ (excluding base.html.php),
 * render any of them with sample data, display the result in an iframe so
 * the admin can verify what recipients will see.
 *
 * @package   Portal\Admin
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/243
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

$emailDir = PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'email';
$templates = [];
if (is_dir($emailDir) === true) {
    foreach ((array) glob($emailDir . DIRECTORY_SEPARATOR . '*.html.php') as $file) {
        $name = basename((string) $file, '.html.php');
        if ($name === 'base') {
            continue;
        }
        $templates[] = $name;
    }
    sort($templates);
}

// 🪞 Render mode — output the iframe content only.
if (isset($_GET['render']) === true) {
    $tpl = (string) $_GET['render'];
    if (preg_match('/^[A-Za-z0-9_\-]+$/', $tpl) !== 1
        || in_array($tpl, $templates, true) === false
    ) {
        http_response_code(404);
        exit('Template not found.');
    }
    // Sample vars per template — extend as more templates ship.
    $samples = [
        'password-reset'  => ['name' => 'Jane Volunteer', 'resetUrl' => 'https://example.invalid/auth/reset/TOKEN', 'expiresInMinutes' => 60],
        'invite'          => ['inviterName' => 'Lance', 'portalName' => (string) (App::settings()['site']['name'] ?? 'WebMS Intra'), 'inviteUrl' => 'https://example.invalid/auth/invite/TOKEN', 'expiresAt' => '7 days from now', 'message' => 'Welcome aboard!'],
        'critical-alert'  => ['severity' => 'Critical', 'platform' => 'PHP', 'code' => '500', 'title' => 'Uncaught Error: Call to undefined function foo()', 'detail' => '#0 /var/www/portal/file.php(42): bar()\n#1 {main}', 'url' => '/dashboard'],
    ];
    $vars = $samples[$tpl] ?? [];

    // Invoke the renderTemplate logic via reflection — quick and contained.
    $reflection = new \ReflectionMethod('Portal\\Core\\Mailer', 'renderBase');
    $reflection->setAccessible(true);
    $partial   = new \ReflectionMethod('Portal\\Core\\Mailer', 'renderTemplate');
    $partial->setAccessible(true);
    $rendered  = (string) $partial->invoke(null, $tpl, $vars);
    echo $reflection->invoke(null, $rendered);
    exit();
}

$pageTitle   = 'Email Templates';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Integrations' => '/admin/integrations', 'Email Templates' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-3"><i class="fa-solid fa-paint-roller me-2"></i>Email Templates</h1>
<p class="text-muted">Preview transactional email templates with sample data.</p>

<div class="row g-3">
    <div class="col-md-3">
        <div class="list-group">
            <?php foreach ($templates as $t):
                $active = (($_GET['select'] ?? '') === $t) ? ' active' : '';
            ?>
                <a href="?select=<?php echo urlencode($t); ?>" class="list-group-item list-group-item-action<?php echo $active; ?>">
                    <?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="col-md-9">
        <?php if (isset($_GET['select']) && in_array($_GET['select'], $templates, true)): ?>
            <div class="card">
                <div class="card-body p-0">
                    <iframe src="?render=<?php echo urlencode($_GET['select']); ?>"
                            style="width:100%;height:600px;border:none;border-radius:.5rem;"></iframe>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info mb-0">Select a template on the left to preview it.</div>
        <?php endif; ?>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
