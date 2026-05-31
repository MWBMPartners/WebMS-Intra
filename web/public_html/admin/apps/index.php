<?php
// Path: public_html/admin/apps/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Apps Marketplace 📦
 * -----------------------------------------------------------------------------
 * Browse, enable, and disable installable apps. Apps are registered via
 * `web/_core/apps/{slug}.php` (see Portal\Core\AppRegistry).
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/255
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AppRegistry;
use Portal\Core\Auth;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

$flash = '';
$flashType = 'info';

// 🛠️ Action handler — enable / disable
if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::verifyCsrf($_POST['csrf_token'] ?? '') === true) {
    $action = (string) ($_POST['action'] ?? '');
    $slug   = (string) ($_POST['slug'] ?? '');
    $registry = AppRegistry::all();

    if ($slug === '' || isset($registry[$slug]) === false) {
        $flash = 'Unknown app.';
        $flashType = 'danger';
    } elseif (($registry[$slug]['isCore'] ?? false) === true) {
        $flash = 'Core apps cannot be disabled.';
        $flashType = 'danger';
    } else {
        $settingKey = (string) $registry[$slug]['settingKey'];
        $value = $action === 'enable' ? '1' : '0';
        try {
            $db = App::db();
            $stmt = $db->prepare(
                "INSERT INTO `tblSettings` "
                . "(`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) "
                . "VALUES (NULL, ?, ?, '0', 0) "
                . "ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`)"
            );
            if ($stmt !== false) {
                $stmt->bind_param('ss', $settingKey, $value);
                $stmt->execute();
                $stmt->close();
            }
            AppRegistry::invalidate();
            $flash = sprintf(
                '%s %s.',
                htmlspecialchars((string) $registry[$slug]['name'], ENT_QUOTES, 'UTF-8'),
                $action === 'enable' ? 'enabled' : 'disabled'
            );
            $flashType = 'success';
        } catch (\Throwable $e) {
            $flash = 'Failed: ' . $e->getMessage();
            $flashType = 'danger';
        }
    }
}

// 🪞 Industry filter — admin can set portal.industry to hide irrelevant apps.
$orgIndustry = (string) (App::settings()['portal']['industry'] ?? '');
$apps        = AppRegistry::all();

if ($orgIndustry !== '') {
    $apps = array_filter(
        $apps,
        static function (array $meta) use ($orgIndustry): bool {
            $industries = (array) ($meta['industries'] ?? []);
            return count($industries) === 0 || in_array($orgIndustry, $industries, true);
        }
    );
}

// 🪞 Group by category
$grouped = [];
foreach ($apps as $slug => $meta) {
    $cat = (string) ($meta['category'] ?? 'other');
    $grouped[$cat][$slug] = $meta;
}
ksort($grouped);

$pageTitle   = 'Apps';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Apps' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-cubes me-2"></i>Apps</h1>
        <p class="text-secondary mb-0">Enable or disable installable apps. Core apps are always on.</p>
    </div>
    <a href="/admin" class="btn btn-outline-secondary btn-sm">&larr; Admin</a>
</div>

<?php if ($flash !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h6 mb-2">Organisation profile</h2>
        <p class="small text-muted mb-2">Setting your organisation's industry filters the app list to relevant apps only. Setting key: <code>portal.industry</code>.</p>
        <p class="mb-0">
            <strong>Current:</strong>
            <code><?php echo $orgIndustry !== '' ? htmlspecialchars($orgIndustry, ENT_QUOTES, 'UTF-8') : '(unset — all apps shown)'; ?></code>
            &middot; <a href="/admin/settings">Change in /admin/settings</a>
        </p>
    </div>
</div>

<?php foreach ($grouped as $category => $apps): ?>
    <h2 class="h5 mt-4 mb-2 text-capitalize"><?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?></h2>
    <div class="row g-3 mb-2">
        <?php foreach ($apps as $slug => $meta):
            $enabled = AppRegistry::isEnabled($slug);
            $isCore  = (bool) ($meta['isCore'] ?? false);
        ?>
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 <?php echo $enabled === true ? 'border-success' : ''; ?>">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-2">
                            <span style="color:<?php echo htmlspecialchars((string) $meta['color'], ENT_QUOTES, 'UTF-8'); ?>;font-size:1.25rem;margin-right:.5rem;">
                                <i class="<?php echo htmlspecialchars((string) $meta['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
                            </span>
                            <strong><?php echo htmlspecialchars((string) $meta['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            <?php if ($isCore === true): ?>
                                <span class="badge bg-secondary ms-auto">Core</span>
                            <?php elseif ($enabled === true): ?>
                                <span class="badge bg-success ms-auto">Enabled</span>
                            <?php else: ?>
                                <span class="badge bg-light text-dark ms-auto">Disabled</span>
                            <?php endif; ?>
                        </div>
                        <p class="small text-muted mb-2"><?php echo htmlspecialchars((string) $meta['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php if (count((array) $meta['industries']) > 0): ?>
                            <p class="small mb-2">
                                <?php foreach ((array) $meta['industries'] as $ind): ?>
                                    <span class="badge bg-light text-muted me-1"><?php echo htmlspecialchars((string) $ind, ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endforeach; ?>
                            </p>
                        <?php endif; ?>
                        <?php if ($isCore === false): ?>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="slug" value="<?php echo htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php if ($enabled === true): ?>
                                    <input type="hidden" name="action" value="disable">
                                    <button type="submit" class="btn btn-outline-danger btn-sm"
                                            data-confirm="Disable <?php echo htmlspecialchars((string) $meta['name'], ENT_QUOTES, 'UTF-8'); ?>? Users will no longer be able to access this app." data-confirm-destructive="true">
                                        Disable
                                    </button>
                                <?php else: ?>
                                    <input type="hidden" name="action" value="enable">
                                    <button type="submit" class="btn btn-success btn-sm">Enable</button>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
