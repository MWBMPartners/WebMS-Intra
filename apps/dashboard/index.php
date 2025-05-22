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

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\Auth;

Auth::ensureSession();

/* -------------------------------------------------------------------------- */
/* Build app list from $SETTINGS                                              */
/* -------------------------------------------------------------------------- */
$apps = [];
foreach ($SETTINGS as $key => $arr) {
    if (isset($arr['enabled']) && $arr['enabled'] === 'true') {
        $apps[] = [
            'key'   => $key,
            'name'  => $arr['displayName'] ?? ucfirst($key),
            'icon'  => $arr['displayIcon'] ?? 'app.svg',
            'color' => $arr['brandColor'] ?? '#0d6efd',
            'url'   => '/' . $key,
        ];
    }
}
?>
<!doctype html>
<html lang="en" data-bs-theme="<?php echo ($SETTINGS['features']['darkModeEnabled'] ?? 'false') === 'true' ? 'dark' : 'light'; ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($SETTINGS['site']['name']); ?></title>
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    <script src="/assets/js/bootstrap.bundle.min.js" defer></script>
    <style>
        .app-card{transition:transform .1s}
        .app-card:hover{transform:translateY(-4px)}
        .avatar{width:32px;height:32px;border-radius:50%;object-fit:cover}
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light bg-light mb-4">
  <div class="container-fluid">
    <a class="navbar-brand" href="/">Portal</a>
    <div class="d-flex align-items-center ms-auto">
        <span class="me-2"><?php echo htmlspecialchars($_SESSION['user_name']??''); ?></span>
        <img src="<?php echo '/assets/images/avatar-placeholder.png'; // TODO avatar logic ?>" class="avatar" alt="avatar">
    </div>
  </div>
</nav>
<div class="container">
    <div class="row g-4">
        <?php foreach($apps as $app):?>
            <div class="col-12 col-sm-6 col-md-4 col-lg-3">
                <a href="<?php echo $app['url']; ?>" class="text-decoration-none text-reset">
                    <div class="card app-card h-100 shadow-sm" style="border-top:4px solid <?php echo $app['color']; ?>">
                        <div class="card-body text-center">
                            <img src="/assets/images/<?php echo $app['icon']; ?>" alt="" width="48" class="mb-3">
                            <h5 class="card-title mb-0"><?php echo htmlspecialchars($app['name']); ?></h5>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</div>
</body>
</html>