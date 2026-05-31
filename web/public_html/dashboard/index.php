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

/* -------------------------------------------------------------------------- */
/* 📢 Pinned announcements for dashboard                                      */
/* -------------------------------------------------------------------------- */
$pinnedAnnouncements = [];
$now = date('Y-m-d H:i:s');
$pnStmt = $mysqli->prepare(
    'SELECT announcementID, title, slug, body, priority, createdAt FROM tblAnnouncements '
    . 'WHERE siteID = ? AND isPinned = 1 AND isPublished = 1 AND isDeleted = 0 '
    . 'AND (publishAt IS NULL OR publishAt <= ?) '
    . 'AND (expiresAt IS NULL OR expiresAt > ?) '
    . 'ORDER BY priority = \'urgent\' DESC, priority = \'important\' DESC, createdAt DESC LIMIT 5'
);
if ($pnStmt !== false) {
    $pnStmt->bind_param('iss', $siteId, $now, $now);
    $pnStmt->execute();
    $pnResult = $pnStmt->get_result();
    while ($pnRow = $pnResult->fetch_assoc()) {
        $pinnedAnnouncements[] = $pnRow;
    }
    $pnStmt->close();
}

// 🌅 Build greeting for the hero. UK-style "Good morning/afternoon/evening".
$hour = (int) date('G');
if ($hour < 12) {
    $greeting = 'Good morning';
} elseif ($hour < 17) {
    $greeting = 'Good afternoon';
} elseif ($hour < 22) {
    $greeting = 'Good evening';
} else {
    $greeting = 'Hello';
}
$userFirstName = $_SESSION['user_name'] ?? 'there';
// First word of the full name — friendlier than "Lance Manasse" in a greeting
$userFirstName = preg_split('/\s+/', trim($userFirstName), 2)[0] ?? 'there';
$heroSiteName  = Site::branding('name') ?? ($SETTINGS['site']['name'] ?? 'Portal');
$todayDate     = date('l, j F Y');

// 📄 Include shared header template (DOCTYPE, <head>, navbar, breadcrumbs)
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- 🌅 Dashboard Hero -->
<section class="portal-dashboard-hero mb-4">
    <p class="portal-dashboard-hero-eyebrow">
        <?php echo htmlspecialchars($todayDate, ENT_QUOTES, 'UTF-8'); ?>
    </p>
    <h1 class="portal-dashboard-hero-title">
        <?php echo htmlspecialchars($greeting . ', ' . $userFirstName, ENT_QUOTES, 'UTF-8'); ?>
    </h1>
    <p class="portal-dashboard-hero-subtitle">
        Welcome to <?php echo htmlspecialchars($heroSiteName, ENT_QUOTES, 'UTF-8'); ?>.
    </p>
</section>

<?php
// 🎯 First-run empty-state panel (#222). Shown to admins only, on the
//    dashboard, until the admin has completed (or dismissed) the
//    initial setup checklist. Detection is heuristic — looks at a
//    handful of "has the portal been customised?" signals.
$showFirstRun = false;
if (\Portal\Core\App::isAdmin() === true) {
    $dismissed = (string) ($SETTINGS['portal']['first_run']['dismissed'] ?? '0');
    if ($dismissed !== '1') {
        // Signal 1: site name still at default
        $siteNameDefault = (string) ($SETTINGS['site']['name'] ?? '') === ''
                        || (string) ($SETTINGS['site']['name'] ?? '') === 'WebMS Intra';
        // Signal 2: no email.from configured
        $noEmailFrom = (string) ($SETTINGS['email']['from'] ?? '') === '';
        // Signal 3: no announcements posted yet
        $noAnnouncements = count($pinnedAnnouncements) === 0;
        $signals = (int) $siteNameDefault + (int) $noEmailFrom + (int) $noAnnouncements;
        if ($signals >= 2) {
            $showFirstRun = true;
        }
    }
}

// Per-step completion tracked in tblSettings — each step key flips to '1'
// when the admin clicks "Mark done" via the JS handler below.
$firstRunSteps = [
    'site_branding' => [
        'label' => 'Configure site name + branding',
        'href'  => '/admin/settings',
        'done'  => (string) ($SETTINGS['portal']['first_run']['steps']['site_branding'] ?? '0') === '1'
                || (string) ($SETTINGS['site']['name'] ?? '') !== ''
                && (string) ($SETTINGS['site']['name'] ?? '') !== 'WebMS Intra',
    ],
    'email_delivery' => [
        'label' => 'Set up email delivery (SMTP or MS365 Graph)',
        'href'  => '/admin/integrations/email',
        'done'  => (string) ($SETTINGS['portal']['first_run']['steps']['email_delivery'] ?? '0') === '1'
                || (string) ($SETTINGS['email']['from'] ?? '') !== '',
    ],
    'test_backup' => [
        'label' => 'Run a test backup',
        'href'  => '/admin/maintenance/backup',
        'done'  => (string) ($SETTINGS['portal']['first_run']['steps']['test_backup'] ?? '0') === '1',
    ],
    'retention_cron' => [
        'label' => 'Configure retention cron',
        'href'  => '/admin/maintenance/retention',
        'done'  => (string) ($SETTINGS['portal']['first_run']['steps']['retention_cron'] ?? '0') === '1',
    ],
    'invite_users' => [
        'label' => 'Invite your first volunteers',
        'href'  => '/admin/users',
        'done'  => (string) ($SETTINGS['portal']['first_run']['steps']['invite_users'] ?? '0') === '1',
    ],
    'first_announcement' => [
        'label' => 'Post your first announcement',
        'href'  => '/announcements',
        'done'  => (string) ($SETTINGS['portal']['first_run']['steps']['first_announcement'] ?? '0') === '1'
                || count($pinnedAnnouncements) > 0,
    ],
];
?>
<?php if ($showFirstRun === true): ?>
<div class="card border-primary mb-4 portal-first-run">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-2">
            <div>
                <h2 class="h5 mb-1"><i class="fa-solid fa-wand-magic-sparkles me-1 text-primary"></i> Welcome to <?php echo htmlspecialchars((string) ($SETTINGS['site']['name'] ?? 'your portal'), ENT_QUOTES, 'UTF-8'); ?></h2>
                <p class="text-muted small mb-0">A short setup checklist — work through these to make your portal ready for users.</p>
            </div>
            <form method="post" action="/admin/settings/dismiss-first-run" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(\Portal\Core\Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="btn btn-sm btn-link text-muted">Dismiss</button>
            </form>
        </div>
        <ul class="list-unstyled mb-0">
            <?php foreach ($firstRunSteps as $key => $step): ?>
                <li class="py-1">
                    <?php if ($step['done']): ?>
                        <i class="fa-solid fa-check-circle text-success me-1"></i>
                        <s class="text-muted"><?php echo htmlspecialchars($step['label'], ENT_QUOTES, 'UTF-8'); ?></s>
                    <?php else: ?>
                        <i class="fa-regular fa-circle text-muted me-1"></i>
                        <a href="<?php echo htmlspecialchars($step['href'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($step['label'], ENT_QUOTES, 'UTF-8'); ?></a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

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

<!-- 📢 Pinned Announcements -->
<?php if (count($pinnedAnnouncements) > 0): ?>
<div class="mb-4">
    <?php foreach ($pinnedAnnouncements as $pAnn): ?>
        <?php
        $pColors = ['urgent' => 'danger', 'important' => 'warning', 'normal' => 'info'];
        $pColor  = $pColors[$pAnn['priority']] ?? 'info';
        ?>
        <div class="alert alert-<?php echo $pColor; ?> d-flex align-items-start mb-2" role="alert">
            <i class="fa-solid fa-thumbtack me-2 mt-1"></i>
            <div>
                <strong>
                    <a href="/announcements/view?slug=<?php echo htmlspecialchars(urlencode($pAnn['slug']), ENT_QUOTES, 'UTF-8'); ?>" class="alert-link text-decoration-none">
                        <?php echo htmlspecialchars($pAnn['title'], ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                </strong>
                <span class="d-block small">
                    <?php echo htmlspecialchars(mb_strimwidth(strip_tags($pAnn['body']), 0, 120, '...'), ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
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

<?php
// 📄 Include shared footer template (close container, footer bar, JS, debug panel)
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
