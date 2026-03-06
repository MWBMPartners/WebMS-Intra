<?php
// Path: apps/dashboard/index.php
/**
 * -----------------------------------------------------------------------------
 * Portal Home Dashboard 🏠
 * -----------------------------------------------------------------------------
 * Displays available apps as cards.  Reads app list from settings table keys
 * ending in `.enabled` = true and uses displayName, displayIcon, brandColor.
 * Cards adapt via Bootstrap grid; hidden on small devices collapse to single
 * column.
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;

// 📌 Page metadata for the template system
$pageTitle   = 'Dashboard';
$pageSection = 'dashboard';

/* -------------------------------------------------------------------------- */
/* 🏗️ Build app list from $SETTINGS                                          */
/* -------------------------------------------------------------------------- */
$apps = [];
foreach ($SETTINGS as $key => $arr) {
    if (is_array($arr) === true && isset($arr['enabled']) === true && $arr['enabled'] === 'true') {
        $apps[] = [
            'key'   => $key,
            'name'  => $arr['displayName'] ?? ucfirst($key),
            'icon'  => $arr['displayIcon'] ?? 'app.svg',
            'color' => $arr['brandColor']  ?? '#0d6efd',
            'url'   => '/' . $key,
        ];
    }
}

// 📄 Include shared header template (DOCTYPE, <head>, navbar, breadcrumbs)
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 🎴 App Cards Grid -->
<div class="row g-4">
    <?php foreach ($apps as $app): ?>
        <div class="col-12 col-sm-6 col-md-4 col-lg-3">
            <a href="<?php echo htmlspecialchars($app['url'], ENT_QUOTES, 'UTF-8'); ?>" class="text-decoration-none text-reset">
                <div class="card app-card h-100 shadow-sm" style="border-top:4px solid <?php echo htmlspecialchars($app['color'], ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="card-body text-center">
                        <img src="/assets/images/<?php echo htmlspecialchars($app['icon'], ENT_QUOTES, 'UTF-8'); ?>" alt="" width="48" class="mb-3">
                        <h5 class="card-title mb-0"><?php echo htmlspecialchars($app['name'], ENT_QUOTES, 'UTF-8'); ?></h5>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<style>
    .app-card { transition: transform .1s; }
    .app-card:hover { transform: translateY(-4px); }
</style>

<?php
// 📄 Include shared footer template (close container, footer bar, JS, debug panel)
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
