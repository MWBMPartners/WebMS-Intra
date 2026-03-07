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

// 🛡️ Ensure session is started (needed for CSRF meta tag and nav user info)
Auth::ensureSession();

// 📌 Defaults for page variables (app files override these before include)
$pageTitle   = $pageTitle   ?? 'Portal';
$pageSection = $pageSection ?? '';
$breadcrumbs = $breadcrumbs ?? [];

// 🌐 Site name from settings for the title suffix
$siteName = App::settings('site.name') ?? 'Portal';

// 🔒 Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

// 🎨 Determine initial theme from localStorage (handled by JS, default light)
?>
<!doctype html>
<html lang="<?php echo htmlspecialchars(I18n::locale(), ENT_QUOTES, 'UTF-8'); ?>" dir="<?php echo I18n::dir(); ?>" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
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

<?php
// 🧭 Include the navigation bar
require __DIR__ . DIRECTORY_SEPARATOR . 'nav.php';
?>

<main class="portal-main">
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
