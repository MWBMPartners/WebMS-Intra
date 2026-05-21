<?php
// Path: core/templates/nav.php
/**
 * -----------------------------------------------------------------------------
 * Navigation Bar Component 🧭
 * -----------------------------------------------------------------------------
 * Bootstrap 5 responsive navbar with:
 *   - Site logo and brand name
 *   - Navigation links with active state highlighting
 *   - User avatar (cascade), name, and logout dropdown
 *   - Dark mode toggle button
 *   - Language switcher (when multiple locales enabled)
 *
 * Included by header.php. Uses $pageSection to determine which nav item
 * is active. User information comes from App::user() and the session.
 *
 * @package   Portal\Core\Templates
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.7.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Avatar;
use Portal\Core\I18n;
use Portal\Core\Router;
use Portal\Core\Site;

// 📌 Get current user and section for nav state
$navUser    = App::user();
$navSection = $pageSection ?? (defined('PORTAL_CURRENT_APP') ? PORTAL_CURRENT_APP : '');
$isLoggedIn = Auth::check();

// 🏷️ Site branding — use Site::branding() for multi-site, fallback to settings
$navSiteName = Site::branding('name') ?? App::settings('site.name') ?? 'Portal';
$navSiteLogo = Site::branding('logo') ?? '/assets/images/logo.svg';

// 🌐 Multi-site switcher data (only when multisite enabled and user has 2+ sites)
$navShowSiteSwitcher = false;
$navUserSites        = [];
if ($isLoggedIn === true && Site::isMultisiteEnabled() === true && $navUser !== null) {
    $navUserSites = Site::userSites((int) $navUser['userID'], App::db());
    $navShowSiteSwitcher = (count($navUserSites) > 1);
}
?>

<nav class="navbar navbar-expand-lg portal-navbar bg-body-tertiary">
    <div class="container">
        <!-- 🏠 Brand / Logo -->
        <a class="navbar-brand d-flex align-items-center gap-2" href="/">
            <img src="<?php echo htmlspecialchars($navSiteLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="" width="28" height="28">
            <span><?php echo htmlspecialchars($navSiteName, ENT_QUOTES, 'UTF-8'); ?></span>
        </a>

        <!-- 📱 Mobile toggle -->
        <button class="navbar-toggler" type="button"
                data-bs-toggle="collapse" data-bs-target="#portalNav"
                aria-controls="portalNav" aria-expanded="false" aria-label="<?php echo htmlspecialchars(t('nav.toggle_navigation'), ENT_QUOTES, 'UTF-8'); ?>">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="portalNav">
            <?php if ($isLoggedIn === true): ?>
            <!-- 🔗 Main navigation links -->
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link<?php echo ($navSection === 'dashboard' || $navSection === '') ? ' active' : ''; ?>"
                       href="/"
                       <?php echo ($navSection === 'dashboard' || $navSection === '') ? 'aria-current="page"' : ''; ?>>
                        <i class="fa-solid fa-house-chimney me-1"></i> <?php echo htmlspecialchars(t('nav.dashboard'), ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                </li>

                <?php
                // 📋 Dynamic app links from settings (apps with *.enabled = 'true')
                $allSettings = App::settings();
                if (is_array($allSettings) === true) {
                    foreach ($allSettings as $appKey => $appConf) {
                        if (is_array($appConf) === false) {
                            continue;
                        }
                        if (($appConf['enabled'] ?? '') !== 'true') {
                            continue;
                        }
                        // Skip meta-settings that aren't real apps
                        if (in_array($appKey, ['site', 'auth', 'portal', 'features', 'api', 'email', 'i18n'], true) === true) {
                            continue;
                        }
                        $appName = $appConf['displayName'] ?? ucfirst($appKey);
                        $appIcon = $appConf['displayIcon'] ?? 'fa-solid fa-cube';
                        $isActive = ($navSection === $appKey);
                        ?>
                        <li class="nav-item">
                            <a class="nav-link<?php echo $isActive ? ' active' : ''; ?>"
                               href="/<?php echo htmlspecialchars($appKey, ENT_QUOTES, 'UTF-8'); ?>"
                               <?php echo $isActive ? 'aria-current="page"' : ''; ?>>
                                <i class="<?php echo htmlspecialchars($appIcon, ENT_QUOTES, 'UTF-8'); ?> me-1"></i>
                                <?php echo htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </li>
                        <?php
                    }
                }
                ?>

                <?php if (App::isAdmin() === true): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle<?php echo ($navSection === 'admin' || $navSection === 'settings') ? ' active' : ''; ?>"
                       href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"
                       <?php echo ($navSection === 'admin' || $navSection === 'settings') ? 'aria-current="page"' : ''; ?>>
                        <i class="fa-solid fa-shield-halved me-1"></i> <?php echo htmlspecialchars(t('nav.admin'), ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                    <ul class="dropdown-menu">
                        <li>
                            <a class="dropdown-item" href="/admin">
                                <i class="fa-solid fa-gauge me-1"></i> <?php echo htmlspecialchars(t('nav.admin_dashboard'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="/admin/errors">
                                <i class="fa-solid fa-triangle-exclamation me-1"></i> <?php echo htmlspecialchars(t('nav.error_log'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="/admin/activity">
                                <i class="fa-solid fa-clock-rotate-left me-1"></i> <?php echo htmlspecialchars(t('nav.activity_log'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="/admin/users">
                                <i class="fa-solid fa-users me-1"></i> <?php echo htmlspecialchars(t('nav.user_management'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="/admin/migrations">
                                <i class="fa-solid fa-database me-1"></i> <?php echo htmlspecialchars(t('nav.migrations'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="/settings">
                                <i class="fa-solid fa-gear me-1"></i> <?php echo htmlspecialchars(t('nav.settings'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </li>
                        <?php if (App::isUmbrellaAdmin() === true): ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="/admin/sites">
                                <i class="fa-solid fa-sitemap me-1"></i> Sites
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>

            <!-- 👤 User area (right side) -->
            <div class="d-flex align-items-center gap-2">
                <!-- 🌐 Language switcher -->
                <?php echo I18n::languageSwitcher(); ?>

                <?php if ($navShowSiteSwitcher === true): ?>
                <!-- 🌐 Site switcher -->
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"
                            data-bs-toggle="dropdown" aria-expanded="false" aria-label="Switch site">
                        <i class="fa-solid fa-building me-1"></i>
                        <span class="d-none d-lg-inline"><?php echo htmlspecialchars($navSiteName, ENT_QUOTES, 'UTF-8'); ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <?php foreach ($navUserSites as $navSiteItem): ?>
                        <li>
                            <form method="post" action="/site/switch" class="d-inline w-100">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="site_id" value="<?php echo (int) $navSiteItem['siteID']; ?>">
                                <button type="submit" class="dropdown-item<?php echo ((int) $navSiteItem['siteID'] === Site::id()) ? ' active' : ''; ?>">
                                    <i class="fa-solid fa-building me-1" style="color:<?php echo htmlspecialchars($navSiteItem['primaryColor'] ?? '#0d6efd', ENT_QUOTES, 'UTF-8'); ?>"></i>
                                    <?php echo htmlspecialchars($navSiteItem['siteName'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if ((int) $navSiteItem['siteID'] === Site::id()): ?>
                                    <i class="fa-solid fa-check ms-2 text-success"></i>
                                    <?php endif; ?>
                                </button>
                            </form>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <!-- 🌙 Theme toggle (cycles light → dark → auto) -->
                <button type="button" class="portal-theme-toggle" aria-label="<?php echo htmlspecialchars(t('nav.toggle_dark_mode'), ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="fa-solid fa-circle-half-stroke"></i>
                </button>

                <!-- 🎨 Colour-blind safe palette toggle -->
                <button type="button" class="portal-cb-toggle" aria-label="Toggle colour-blind safe palette" aria-pressed="false">
                    <i class="fa-solid fa-eye-low-vision"></i>
                </button>

                <!-- 👤 User dropdown -->
                <div class="dropdown">
                    <a class="d-flex align-items-center gap-2 text-decoration-none dropdown-toggle"
                       href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <?php
                        if ($navUser !== null) {
                            echo Avatar::img($navUser, 32, 'portal-avatar');
                        } else {
                            echo '<img src="/assets/images/avatar-placeholder.svg" class="portal-avatar" alt="" width="32" height="32">';
                        }
                        ?>
                        <span class="d-none d-lg-inline">
                            <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User', ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text small text-muted">
                            <?php echo htmlspecialchars($_SESSION['user_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                        </span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="/account">
                                <i class="fa-solid fa-user-gear me-1"></i> <?php echo htmlspecialchars(t('nav.my_account'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="/logout">
                                <i class="fa-solid fa-right-from-bracket me-1"></i> <?php echo htmlspecialchars(t('nav.sign_out'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
            <?php else: ?>
            <!-- 🔑 Login link for unauthenticated users -->
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link" href="/login">
                        <i class="fa-solid fa-right-to-bracket me-1"></i> <?php echo htmlspecialchars(t('nav.sign_in'), ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                </li>
                <li class="nav-item d-flex align-items-center">
                    <!-- 🌐 Language switcher (unauthenticated) -->
                    <?php echo I18n::languageSwitcher(); ?>
                </li>
                <li class="nav-item">
                    <button type="button" class="portal-theme-toggle" aria-label="<?php echo htmlspecialchars(t('nav.toggle_dark_mode'), ENT_QUOTES, 'UTF-8'); ?>">
                        <i class="fa-solid fa-circle-half-stroke"></i>
                    </button>
                </li>
                <li class="nav-item">
                    <button type="button" class="portal-cb-toggle" aria-label="Toggle colour-blind safe palette" aria-pressed="false">
                        <i class="fa-solid fa-eye-low-vision"></i>
                    </button>
                </li>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</nav>
