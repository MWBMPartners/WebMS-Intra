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
 *
 * Included by header.php. Uses $pageSection to determine which nav item
 * is active. User information comes from App::user() and the session.
 *
 * @package   Portal\Core\Templates
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   MIT
 * @version   0.1.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Avatar;
use Portal\Core\Router;

// 📌 Get current user and section for nav state
$navUser    = App::user();
$navSection = $pageSection ?? (defined('PORTAL_CURRENT_APP') ? PORTAL_CURRENT_APP : '');
$isLoggedIn = Auth::check();

// 🏷️ Site name for navbar brand
$navSiteName = App::settings('site.name') ?? 'Portal';
?>

<nav class="navbar navbar-expand-lg portal-navbar bg-body-tertiary">
    <div class="container">
        <!-- 🏠 Brand / Logo -->
        <a class="navbar-brand d-flex align-items-center gap-2" href="/">
            <img src="/assets/images/logo.svg" alt="" width="28" height="28">
            <span><?php echo htmlspecialchars($navSiteName, ENT_QUOTES, 'UTF-8'); ?></span>
        </a>

        <!-- 📱 Mobile toggle -->
        <button class="navbar-toggler" type="button"
                data-bs-toggle="collapse" data-bs-target="#portalNav"
                aria-controls="portalNav" aria-expanded="false" aria-label="Toggle navigation">
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
                        <i class="fa-solid fa-house-chimney me-1"></i> Dashboard
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
                        if (in_array($appKey, ['site', 'auth', 'portal', 'features', 'api', 'email'], true) === true) {
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
                <li class="nav-item">
                    <a class="nav-link<?php echo ($navSection === 'settings') ? ' active' : ''; ?>"
                       href="/settings"
                       <?php echo ($navSection === 'settings') ? 'aria-current="page"' : ''; ?>>
                        <i class="fa-solid fa-gear me-1"></i> Settings
                    </a>
                </li>
                <?php endif; ?>
            </ul>

            <!-- 👤 User area (right side) -->
            <div class="d-flex align-items-center gap-2">
                <!-- 🌙 Dark mode toggle -->
                <button type="button" class="portal-theme-toggle" aria-label="Toggle dark mode">
                    <i class="fa-solid fa-moon"></i>
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
                                <i class="fa-solid fa-user-gear me-1"></i> My Account
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="/logout">
                                <i class="fa-solid fa-right-from-bracket me-1"></i> Sign Out
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
                        <i class="fa-solid fa-right-to-bracket me-1"></i> Sign In
                    </a>
                </li>
                <li class="nav-item">
                    <button type="button" class="portal-theme-toggle" aria-label="Toggle dark mode">
                        <i class="fa-solid fa-moon"></i>
                    </button>
                </li>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</nav>
