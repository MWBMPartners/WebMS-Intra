<?php
// Path: core/templates/header.php
/**
 * -----------------------------------------------------------------------------
 * Shared Page Header Template 📄
 * -----------------------------------------------------------------------------
 * Outputs the DOCTYPE, <head> section, opening <body>, navbar, and opens the
 * main content container. Each app page sets a few variables before including
 * this template:
 *
 *   $pageTitle   = 'Submit Expense Claim';   // Browser tab title
 *   $pageSection = 'expenses';               // Nav highlighting key
 *   $breadcrumbs = ['Expenses' => '/expenses', 'Submit' => ''];  // Optional
 *
 * @package   Portal\Core\Templates
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Asset;
use Portal\Core\Auth;
use Portal\Core\I18n;
use Portal\Core\Site;

// 🛡️ Ensure session is started (needed for CSRF meta tag and nav user info)
Auth::ensureSession();

// 📌 Defaults for page variables (app files override these before include)
$pageTitle   = $pageTitle   ?? 'Portal';
$pageSection = $pageSection ?? '';
$breadcrumbs = $breadcrumbs ?? [];

// 🌐 Site branding — use Site::branding() for multi-site, fallback to settings
$siteName    = Site::branding('name') ?? App::settings('site.name') ?? 'Portal';
$siteColor   = Site::branding('color') ?? '#0d6efd';

// 🔒 Security headers
header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://challenges.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; font-src 'self' https://cdnjs.cloudflare.com; img-src 'self' data:; connect-src 'self'; frame-src https://challenges.cloudflare.com; base-uri 'self'; form-action 'self'");

// 🎨 Determine initial theme from localStorage (handled by JS, default light)
?>
<!doctype html>
<html lang="<?php echo htmlspecialchars(I18n::locale(), ENT_QUOTES, 'UTF-8'); ?>" dir="<?php echo I18n::dir(); ?>" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="theme-color" content="<?php echo htmlspecialchars($siteColor, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/assets/images/icon-192.svg">
    <title><?php echo htmlspecialchars($pageTitle . ' - ' . $siteName, ENT_QUOTES, 'UTF-8'); ?></title>

    <!-- 🎨 Stylesheets (CDN with local fallback, RTL variant if needed) -->
    <?php echo Asset::bootstrapCss(I18n::isRtl()); ?>

    <?php echo Asset::fontAwesomeCss(); ?>

    <?php echo Asset::portalCss(); ?>

    <!-- 🌙 Prevent FOUC: apply saved theme before first paint -->
    <script>
    (function(){
        var t = localStorage.getItem('portal-theme');
        if (t === 'dark' || t === 'light') {
            document.documentElement.setAttribute('data-bs-theme', t);
        }
    })();
    </script>
</head>
<body>

<!-- ♿ Skip to main content link (WCAG 2.4.1) -->
<a href="#main-content" class="portal-skip-link">Skip to main content</a>

<?php
// 🧭 Include the navigation bar
require __DIR__ . DIRECTORY_SEPARATOR . 'nav.php';
?>

<main class="portal-main" id="main-content" role="main">
<div class="container">

<?php
// 🍞 Render breadcrumbs if provided
if (count($breadcrumbs) > 0) {
    echo '<nav aria-label="breadcrumb" class="portal-breadcrumb">';
    echo '<ol class="breadcrumb">';
    $i = 0;
    $total = count($breadcrumbs);
    foreach ($breadcrumbs as $label => $url) {
        $i++;
        if ($i === $total || $url === '') {
            // Active (current) breadcrumb
            echo '<li class="breadcrumb-item active" aria-current="page">';
            echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            echo '</li>';
        } else {
            echo '<li class="breadcrumb-item">';
            echo '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
            echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            echo '</a></li>';
        }
    }
    echo '</ol>';
    echo '</nav>';
}
?>
