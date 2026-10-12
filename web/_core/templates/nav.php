<?php
// Path: _core/templates/nav.php
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
 * EVERY LINK HERE IS BUILT BY Site::url() (#536). Twenty of them used to be
 * written out bare: the logo, the dashboard, the whole administration menu,
 * "My account", "Sign out" and "Sign in". Where organisations are told apart
 * by the first part of the address ("/north/calendar"), a bare "/admin" is
 * the DEFAULT organisation's, so one click on the menu took a person out of
 * the organisation they were working in, and nothing on the page they
 * arrived at said so. Site::url() adds the organisation's part, and adds
 * nothing when none is in use, so an ordinary installation gets exactly the
 * addresses it always had.
 *
 * The two `href="#"` are not addresses: Bootstrap needs them on the buttons
 * that open a drop-down menu. The logo and the placeholder picture are files
 * in the web folder, the same for every organisation, and stay as they are.
 * `tools/audit-checks/check_site_aware_addresses.py` fails if a bare address
 * comes back, and `tools/site-addresses-selftest.php` reads the real menu.
 *
 * @package   Portal\Core\Templates
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.8.1
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AppRegistry;
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

// 🌐 The organisation switcher: shown when several organisations are on and
//    the person has two or more. The list is Site::organisationsFor(), the
//    same list the chooser page and the members-only gate use. It used to be
//    Site::userSites(), membership rows only, so a global administrator with
//    no row of their own never saw a switcher (#502). If the list cannot be
//    read the switcher is simply left out; a menu must not fail the page.
$navShowSiteSwitcher = false;
$navUserSites        = [];
$navSiteMode         = Site::detectionMode();
if ($isLoggedIn === true && Site::isMultisiteEnabled() === true && $navUser !== null) {
    try {
        $navUserSites = Site::organisationsFor((int) $navUser['userID'], App::db());
    } catch (\Throwable) {
        $navUserSites = [];
    }
    $navShowSiteSwitcher = (count($navUserSites) > 1);
}
?>

<nav class="navbar navbar-expand-lg portal-navbar bg-body-tertiary">
    <div class="container">
        <!-- 🏠 Brand / Logo -->
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?php echo htmlspecialchars(Site::url(''), ENT_QUOTES, 'UTF-8'); ?>">
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
                       href="<?php echo htmlspecialchars(Site::url(''), ENT_QUOTES, 'UTF-8'); ?>"
                       <?php echo ($navSection === 'dashboard' || $navSection === '') ? 'aria-current="page"' : ''; ?>>
                        <i class="fa-solid fa-house-chimney me-1"></i> <?php echo htmlspecialchars(t('nav.dashboard'), ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                </li>

                <?php
                // 📋 One menu entry per app that is switched on.
                //
                //    Two things used to go wrong here, both fixed below.
                //
                //    First, this only accepted the exact word 'true'. But the
                //    "Apps" screen at /admin/apps writes '1' when you switch an
                //    app on, and AppRegistry::isEnabled() has always accepted
                //    either. So an app switched on through that screen worked
                //    if you typed its address, but never appeared in this menu.
                //    Whether an app was visible depended on HOW it was switched
                //    on, which is not something anyone could have guessed.
                //
                //    Second, this linked to "/" plus the setting's name, with
                //    nothing checking that a page exists there. Several apps
                //    keep their entry page somewhere else — Decision Card is at
                //    /decision-card, Reports at /admin/reports, Kids has no
                //    front page at all, only /kids/checkin — so those menu
                //    entries led straight to "page not found". A few settings
                //    groups in that list, such as "webhooks", are not apps at
                //    all and never had a page.
                //
                //    Now the real address comes from the app registry, which is
                //    the list that actually knows it, and any entry whose
                //    address does not exist is left out rather than drawn as a
                //    dead link.
                $allSettings = App::settings();
                if (is_array($allSettings) === true) {
                    $registry = AppRegistry::all();
                    foreach ($allSettings as $appKey => $appConf) {
                        if (is_array($appConf) === false) {
                            continue;
                        }
                        $enabledValue = (string) ($appConf['enabled'] ?? '');
                        if ($enabledValue !== 'true' && $enabledValue !== '1') {
                            continue;
                        }
                        // Skip meta-settings that aren't real apps
                        if (in_array($appKey, ['site', 'auth', 'portal', 'features', 'api', 'email', 'i18n'], true) === true) {
                            continue;
                        }

                        // 🎯 The registry knows the real address. 'landing' is
                        //    the page to open; 'route' is only a prefix used to
                        //    work out which app owns a page, and for a few apps
                        //    that prefix is not a page at all (Expenses, Kids,
                        //    Worship). Fall back to the setting's own name when
                        //    there is no registry entry.
                        $appRoute = (string) (
                            $registry[$appKey]['landing']
                            ?? $registry[$appKey]['route']
                            ?? $appKey
                        );

                        // 🚧 Never draw a link to a page that does not exist.
                        if (Router::routeExists($appRoute) === false) {
                            continue;
                        }

                        $appName = $appConf['displayName'] ?? ($registry[$appKey]['name'] ?? ucfirst($appKey));
                        $appIcon = $appConf['displayIcon'] ?? ($registry[$appKey]['icon'] ?? 'fa-solid fa-cube');
                        $isActive = ($navSection === $appKey);
                        ?>
                        <li class="nav-item">
                            <a class="nav-link<?php echo $isActive ? ' active' : ''; ?>"
                               href="<?php echo htmlspecialchars(Router::url($appRoute), ENT_QUOTES, 'UTF-8'); ?>"
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
                            <a class="dropdown-item" href="<?php echo htmlspecialchars(Site::url('admin'), ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="fa-solid fa-gauge me-1"></i> <?php echo htmlspecialchars(t('nav.admin_dashboard'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="<?php echo htmlspecialchars(Site::url('admin/errors'), ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="fa-solid fa-triangle-exclamation me-1"></i> <?php echo htmlspecialchars(t('nav.error_log'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="<?php echo htmlspecialchars(Site::url('admin/activity'), ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="fa-solid fa-clock-rotate-left me-1"></i> <?php echo htmlspecialchars(t('nav.activity_log'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="<?php echo htmlspecialchars(Site::url('admin/audit'), ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="fa-solid fa-shield-halved me-1"></i> <?php echo htmlspecialchars(t('nav.audit_trail'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="<?php echo htmlspecialchars(Site::url('admin/users'), ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="fa-solid fa-users me-1"></i> <?php echo htmlspecialchars(t('nav.user_management'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="<?php echo htmlspecialchars(Site::url('admin/migrations'), ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="fa-solid fa-database me-1"></i> <?php echo htmlspecialchars(t('nav.migrations'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="<?php echo htmlspecialchars(Site::url('settings'), ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="fa-solid fa-gear me-1"></i> <?php echo htmlspecialchars(t('nav.settings'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </li>
                        <!-- 🧩 App marketplace + reporting/workflow config (#gap-fix D3 — previously
                             reachable only by typing the URL directly). -->
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="<?php echo htmlspecialchars(Site::url('admin/apps'), ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="fa-solid fa-cubes me-1"></i> <?php echo htmlspecialchars(t('nav.apps'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="<?php echo htmlspecialchars(Site::url('admin/reports'), ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="fa-solid fa-chart-bar me-1"></i> <?php echo htmlspecialchars(t('nav.reports'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="<?php echo htmlspecialchars(Site::url('admin/workflows'), ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="fa-solid fa-diagram-project me-1"></i> <?php echo htmlspecialchars(t('nav.workflows'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </li>
                        <!-- 📣 Notification/compliance admin screens (#gap-fix D3). -->
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="<?php echo htmlspecialchars(Site::url('admin/sms'), ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="fa-solid fa-comment-sms me-1"></i> <?php echo htmlspecialchars(t('nav.sms'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="<?php echo htmlspecialchars(Site::url('admin/transcription'), ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="fa-solid fa-closed-captioning me-1"></i> <?php echo htmlspecialchars(t('nav.transcription'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="<?php echo htmlspecialchars(Site::url('admin/safeguarding/dbs'), ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="fa-solid fa-user-shield me-1"></i> <?php echo htmlspecialchars(t('nav.safeguarding'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="<?php echo htmlspecialchars(Site::url('admin/decision-cards'), ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="fa-solid fa-hand-holding-heart me-1"></i> <?php echo htmlspecialchars(t('nav.decision_cards'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </li>
                        <?php if (App::isUmbrellaAdmin() === true): ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="<?php echo htmlspecialchars(Site::url('admin/sites'), ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="fa-solid fa-sitemap me-1"></i> <?php echo htmlspecialchars(t('nav.sites'), ENT_QUOTES, 'UTF-8'); ?>
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

                <?php
                // 🌐 The organisation switcher (#502). Three shapes, one per way of
                //    telling organisations apart: a form that posts to /site/switch
                //    (sign-in session), or a plain link to the other organisation's
                //    own prefix or own address. It used to post to /site/switch in
                //    every way, which in the two address ways changed nothing the
                //    person could see.
                //    The current organisation's name is shown at EVERY screen width.
                //    It was hidden below desktop width, so on a phone nothing said
                //    which organisation you were in. A long name is cut short with
                //    "…" (at most 10rem, and at most 30% of a narrow screen) rather
                //    than pushing the other buttons off the bar.
                //    (A PHP comment on purpose: an HTML one would be sent to every
                //    browser.)
                if ($navShowSiteSwitcher === true): ?>
                <!-- 🌐 Organisation switcher -->
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"
                            data-bs-toggle="dropdown" aria-expanded="false" aria-label="Switch organisation">
                        <i class="fa-solid fa-building me-1"></i>
                        <span class="d-inline-block text-truncate align-bottom" style="max-width: min(10rem, 30vw);"><?php echo htmlspecialchars($navSiteName, ENT_QUOTES, 'UTF-8'); ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <?php foreach ($navUserSites as $navSiteItem): ?>
                        <?php
                        $navSiteIsCurrent = ((int) $navSiteItem['siteID'] === Site::id());
                        $navSiteAddress   = Site::addressOf($navSiteItem);
                        $navSiteColour    = htmlspecialchars((string) ($navSiteItem['primaryColor'] ?? '#0d6efd'), ENT_QUOTES, 'UTF-8');
                        ?>
                        <li>
                            <?php if ($navSiteMode === Site::MODE_SESSION): ?>
                            <form method="post" action="<?php echo htmlspecialchars(Site::url('site/switch'), ENT_QUOTES, 'UTF-8'); ?>" class="d-inline w-100">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="site_id" value="<?php echo (int) $navSiteItem['siteID']; ?>">
                                <button type="submit" class="dropdown-item<?php echo ($navSiteIsCurrent === true) ? ' active' : ''; ?>"<?php echo ($navSiteIsCurrent === true) ? ' aria-current="true"' : ''; ?>>
                                    <i class="fa-solid fa-building me-1" style="color:<?php echo $navSiteColour; ?>"></i>
                                    <?php echo htmlspecialchars($navSiteItem['siteName'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if ($navSiteIsCurrent === true): ?>
                                    <i class="fa-solid fa-check ms-2 text-success"></i>
                                    <?php endif; ?>
                                </button>
                            </form>
                            <?php elseif ($navSiteIsCurrent === true || $navSiteAddress !== ''): ?>
                            <a class="dropdown-item<?php echo ($navSiteIsCurrent === true) ? ' active' : ''; ?>"<?php echo ($navSiteIsCurrent === true) ? ' aria-current="true"' : ''; ?>
                               href="<?php echo htmlspecialchars($navSiteIsCurrent === true ? Site::url('') : $navSiteAddress, ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="fa-solid fa-building me-1" style="color:<?php echo $navSiteColour; ?>"></i>
                                <?php echo htmlspecialchars($navSiteItem['siteName'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php if ($navSiteIsCurrent === true): ?>
                                <i class="fa-solid fa-check ms-2 text-success"></i>
                                <?php elseif ($navSiteMode === Site::MODE_HOST): ?>
                                <span class="d-block small text-secondary">You will sign in again there</span>
                                <?php endif; ?>
                            </a>
                            <?php else: ?>
                            <span class="dropdown-item disabled" aria-disabled="true">
                                <i class="fa-solid fa-building me-1" style="color:<?php echo $navSiteColour; ?>"></i>
                                <?php echo htmlspecialchars($navSiteItem['siteName'], ENT_QUOTES, 'UTF-8'); ?>
                                <span class="d-block small">No address set yet</span>
                            </span>
                            <?php endif; ?>
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

                <!-- 📖 Dyslexia-friendly reading mode toggle -->
                <button type="button" class="portal-read-toggle" aria-label="Toggle dyslexia-friendly reading mode" aria-pressed="false">
                    <i class="fa-solid fa-book-open-reader"></i>
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
                            <a class="dropdown-item" href="<?php echo htmlspecialchars(Site::url('account'), ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="fa-solid fa-user-gear me-1"></i> <?php echo htmlspecialchars(t('nav.my_account'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="<?php echo htmlspecialchars(Site::url('logout'), ENT_QUOTES, 'UTF-8'); ?>">
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
                    <a class="nav-link" href="<?php echo htmlspecialchars(Site::url('login'), ENT_QUOTES, 'UTF-8'); ?>">
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
                <li class="nav-item">
                    <button type="button" class="portal-read-toggle" aria-label="Toggle dyslexia-friendly reading mode" aria-pressed="false">
                        <i class="fa-solid fa-book-open-reader"></i>
                    </button>
                </li>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</nav>
