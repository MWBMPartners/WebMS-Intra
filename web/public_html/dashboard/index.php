<?php
// Path: public_html/dashboard/index.php
/**
 * -----------------------------------------------------------------------------
 * Portal Home Dashboard 🏠
 * -----------------------------------------------------------------------------
 * Displays summary stat cards (role-aware) and available apps as cards.
 * Stats show key operational metrics with links to relevant sections.
 * App list reads from settings table keys ending in `.enabled` = true.
 *
 * @package   Portal\Dashboard
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.8.2
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/85
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Site;

// 📌 Page metadata for the template system
$pageTitle   = 'Dashboard';
$pageSection = 'dashboard';

$userId  = (int) ($_SESSION['user_id'] ?? 0);
$siteId  = Site::id();
$isAdmin = App::isAdmin();

/* -------------------------------------------------------------------------- */
/* 📊 Build stat widgets (role-aware)                                         */
/* -------------------------------------------------------------------------- */
$widgets = [];

// 📋 Pending expense claims (user's own, or all for admins/approvers)
if (isset($SETTINGS['expenses']['enabled']) === true && $SETTINGS['expenses']['enabled'] === 'true') {
    if ($isAdmin === true) {
        $expStmt = $mysqli->prepare(
            'SELECT COUNT(*) AS cnt FROM tblExpenseClaims WHERE status = \'Pending\' AND siteID = ?'
        );
        if ($expStmt !== false) {
            $expStmt->bind_param('i', $siteId);
            $expStmt->execute();
            $cnt = (int) ($expStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
            $expStmt->close();
            $widgets[] = [
                'label' => 'Pending Claims',
                'value' => $cnt,
                'icon'  => 'fa-solid fa-file-invoice-dollar',
                'color' => $cnt > 0 ? 'warning' : 'success',
                'url'   => '/expenses/approve',
            ];
        }
    } else {
        $expStmt = $mysqli->prepare(
            'SELECT COUNT(*) AS cnt FROM tblExpenseClaims WHERE status = \'Pending\' AND userID = ? AND siteID = ?'
        );
        if ($expStmt !== false) {
            $expStmt->bind_param('ii', $userId, $siteId);
            $expStmt->execute();
            $cnt = (int) ($expStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
            $expStmt->close();
            if ($cnt > 0) {
                $widgets[] = [
                    'label' => 'My Pending Claims',
                    'value' => $cnt,
                    'icon'  => 'fa-solid fa-file-invoice-dollar',
                    'color' => 'warning',
                    'url'   => '/expenses',
                ];
            }
        }
    }
}

// 📅 Upcoming events this week
if (isset($SETTINGS['calendar']['enabled']) === true && $SETTINGS['calendar']['enabled'] === 'true') {
    $evStmt = $mysqli->prepare(
        'SELECT COUNT(*) AS cnt FROM tblEvents '
        . 'WHERE isDeleted = 0 AND status = \'published\' AND siteID = ? '
        . 'AND startDateTime >= NOW() AND startDateTime <= DATE_ADD(NOW(), INTERVAL 7 DAY)'
    );
    if ($evStmt !== false) {
        $evStmt->bind_param('i', $siteId);
        $evStmt->execute();
        $cnt = (int) ($evStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $evStmt->close();
        $widgets[] = [
            'label' => 'Events This Week',
            'value' => $cnt,
            'icon'  => 'fa-solid fa-calendar-week',
            'color' => 'primary',
            'url'   => '/calendar',
        ];
    }
}

// 🔢 Total active users (admin only)
if ($isAdmin === true) {
    $usrStmt = $mysqli->prepare(
        'SELECT COUNT(*) AS cnt FROM tblUsers u '
        . 'JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
        . 'WHERE u.isActive = 1'
    );
    if ($usrStmt !== false) {
        $usrStmt->bind_param('i', $siteId);
        $usrStmt->execute();
        $cnt = (int) ($usrStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $usrStmt->close();
        $widgets[] = [
            'label' => 'Active Users',
            'value' => $cnt,
            'icon'  => 'fa-solid fa-users',
            'color' => 'info',
            'url'   => '/admin/users',
        ];
    }
}

// 📋 Recent activity count (last 24h, admin only)
if ($isAdmin === true) {
    $actStmt = $mysqli->prepare(
        'SELECT COUNT(*) AS cnt FROM tblActivityLogs '
        . 'WHERE createdAt >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND (siteID = ? OR siteID IS NULL)'
    );
    if ($actStmt !== false) {
        $actStmt->bind_param('i', $siteId);
        $actStmt->execute();
        $cnt = (int) ($actStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $actStmt->close();
        $widgets[] = [
            'label' => 'Activity (24h)',
            'value' => $cnt,
            'icon'  => 'fa-solid fa-chart-line',
            'color' => 'secondary',
            'url'   => '/admin/activity',
        ];
    }
}

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

<!-- 📊 Stat Widgets -->
<?php if (count($widgets) > 0): ?>
<div class="row g-3 mb-4">
    <?php foreach ($widgets as $w): ?>
        <div class="col-6 col-md-3">
            <a href="<?php echo htmlspecialchars($w['url'], ENT_QUOTES, 'UTF-8'); ?>" class="text-decoration-none">
                <div class="card border-<?php echo htmlspecialchars($w['color'], ENT_QUOTES, 'UTF-8'); ?> h-100">
                    <div class="card-body py-3 d-flex align-items-center">
                        <div class="me-3 text-<?php echo htmlspecialchars($w['color'], ENT_QUOTES, 'UTF-8'); ?>">
                            <i class="<?php echo htmlspecialchars($w['icon'], ENT_QUOTES, 'UTF-8'); ?> fa-2x" aria-hidden="true"></i>
                        </div>
                        <div>
                            <div class="h4 mb-0 fw-bold"><?php echo (int) $w['value']; ?></div>
                            <small class="text-muted"><?php echo htmlspecialchars($w['label'], ENT_QUOTES, 'UTF-8'); ?></small>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

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
