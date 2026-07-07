<?php
// Path: _core/templates/header.php
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

// 🌐 Site branding — use Site::branding() for multi-site, fallback to settings.
// $siteColor is injected as --portal-primary on the <html> element below so
// portal.css's design tokens shift to the site's brand colour automatically.
$siteName    = Site::branding('name') ?? App::settings('site.name') ?? 'Portal';
$siteColor   = Site::branding('color') ?? '#5e6ad2';
// 🏷️ Brand-aware favicon (Claude Design brand kit). Resolution chain:
//   1. Tenant override (Site::branding('favicon'))
//   2. Active brand's icon.svg (resolved from brand-defaults.php assetFolder)
//   3. Top-level fallback at /assets/images/icon.svg.
$activeAssetFolder = (function (): string {
    $industry = (string) (App::settings('portal.industry') ?? '');
    $presets  = (array) (require PORTAL_CORE . DIRECTORY_SEPARATOR . 'brand-defaults.php');
    $preset   = $presets[$industry] ?? $presets[''] ?? [];
    return (string) ($preset['assetFolder'] ?? 'webms-intra');
})();
$brandIconPath = '/assets/images/brandkit/assets/' . $activeAssetFolder . '/icon.svg';
$siteFavicon   = Site::branding('favicon') ?? $brandIconPath;

// 🏷️ "Powered by <product>" attribution — same rule as the footer span.
// Custom-branded sites get a <meta name="generator"> tag so site analysers
// (Wappalyzer, browser dev tools, etc.) can attribute the platform.
// Admins opt out via the `branding.hidePoweredBy` setting.
// The product name (WebMS Intra / ChurchMS / etc.) comes from the brand
// layer — see issue #296 and Site::productName().
$showPoweredByMeta = (App::settings('branding.hidePoweredBy') !== 'true')
    && (Site::usesCustomBranding() === true);
$productName = Site::productName();

// 🤖 Robots / AI-crawler policy.
// Internal-facing portal by default — meta-robots emits noindex,nofollow
// for both general search engines AND AI training crawlers unless the
// site has explicitly opted in. The settings are:
//   site.allowIndexing    = 'true' to allow general search engines
//   site.allowAiIndexing  = 'true' to allow AI/LLM training crawlers
// Both default to 'false'. robots.txt at /robots.txt is the belt-and-braces
// version for bots that don't read HTML meta tags.
$allowIndexing   = (App::settings('site.allowIndexing')   ?? 'false') === 'true';
$allowAiIndexing = (App::settings('site.allowAiIndexing') ?? 'false') === 'true';
$robotsContent   = $allowIndexing === true ? 'index, follow' : 'noindex, nofollow';
$aiRobotsContent = $allowAiIndexing === true ? 'index, follow' : 'noai, noimageai';

// 🎨 Derive --portal-primary-rgb (comma-separated R,G,B) from the hex colour
// so portal.css's rgba()-based focus rings and shadows tint correctly.
// Accepts #RGB / #RRGGBB; falls back to the indigo default on bad input.
$hex = ltrim((string) $siteColor, '#');
if (strlen($hex) === 3) {
    $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
}
if (preg_match('/^[0-9a-fA-F]{6}$/', $hex) === 1) {
    $siteColorRgb = (int) hexdec(substr($hex, 0, 2)) . ', '
                  . (int) hexdec(substr($hex, 2, 2)) . ', '
                  . (int) hexdec(substr($hex, 4, 2));
} else {
    $siteColor    = '#5e6ad2';
    $siteColorRgb = '94, 106, 210';
}

// 🔒 Security headers
header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
// 🔐 Content Security Policy (#144 — nonce-based tightening).
//    Per-request nonce on script-src so modern browsers strictly enforce
//    the nonce against inline <script> tags (XSS-injected ones lacking
//    the matching nonce are refused). 'unsafe-inline' kept as a
//    fallback for browsers that don't understand nonces — CSP3 specifies
//    that nonce overrides 'unsafe-inline' on supporting browsers, so
//    this is purely additive defence-in-depth.
//
//    style-src retains 'unsafe-inline' for now — style nonce-ing is a
//    larger refactor scoped as a follow-up to #144.
$csp_nonce = \Portal\Core\App::cspNonce();
// 🔐 Page-scoped CSP extensions — a controller may set
//    $cspImgExtra / $cspMediaExtra / $cspFrameExtra (space-separated
//    source lists) BEFORE requiring header.php to widen those directives
//    for that page only.
//
//    HARDENING: the extension string is interpolated verbatim into the CSP
//    header, so we REFUSE any value containing characters that could smuggle
//    additional directives (';', CR/LF) or break out of the token stream
//    (single/double quote). trim() alone would be insufficient — a future
//    caller passing tainted data (settings value, query param, per-site
//    config) could otherwise add 'sandbox', 'worker-src *', 'report-uri
//    https://attacker.tld/x', etc. Silent-drop keeps the base policy intact.
//
//    OPT-IN: media-src is only emitted when a page sets $cspMediaExtra, so
//    pages that don't opt in continue to fall through to default-src 'self'
//    exactly as before — behaviour-neutral for every existing page.
$cspExtSanitise = static function ($raw): string {
    if (isset($raw) === false) {
        return '';
    }
    $s = trim((string) $raw);
    if ($s === '') {
        return '';
    }
    if (preg_match('/[;\r\n\'"]/', $s) === 1) {
        return '';
    }
    return ' ' . $s;
};
$cspImgExtra   = $cspExtSanitise($cspImgExtra   ?? null);
$cspMediaExtra = $cspExtSanitise($cspMediaExtra ?? null);
$cspFrameExtra = $cspExtSanitise($cspFrameExtra ?? null);
$mediaSrcDirective = $cspMediaExtra !== '' ? "media-src 'self'{$cspMediaExtra}; " : '';
header("Content-Security-Policy: default-src 'self'; "
    . "script-src 'self' 'nonce-{$csp_nonce}' 'unsafe-inline' https://cdn.jsdelivr.net https://challenges.cloudflare.com; "
    . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
    . "font-src 'self' https://cdnjs.cloudflare.com; "
    . "img-src 'self' data:{$cspImgExtra}; "
    . "connect-src 'self'; "
    . $mediaSrcDirective
    . "frame-src https://challenges.cloudflare.com{$cspFrameExtra}; "
    . "base-uri 'self'; "
    . "form-action 'self'");

// 🎨 Determine initial theme from localStorage (handled by JS, default light)
?>
<!doctype html>
<html lang="<?php echo htmlspecialchars(I18n::locale(), ENT_QUOTES, 'UTF-8'); ?>"
      dir="<?php echo I18n::dir(); ?>"
      data-bs-theme="light"
      style="--portal-primary: <?php echo htmlspecialchars($siteColor, ENT_QUOTES, 'UTF-8'); ?>; --portal-primary-rgb: <?php echo htmlspecialchars($siteColorRgb, ENT_QUOTES, 'UTF-8'); ?>;">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="theme-color" content="<?php echo htmlspecialchars($siteColor, ENT_QUOTES, 'UTF-8'); ?>">

    <!-- 🤖 Robots / AI-crawler policy (defaults to deny; admin opt-in) -->
    <meta name="robots"    content="<?php echo htmlspecialchars($robotsContent,   ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="googlebot" content="<?php echo htmlspecialchars($robotsContent,   ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="bingbot"   content="<?php echo htmlspecialchars($robotsContent,   ENT_QUOTES, 'UTF-8'); ?>">
    <!-- AI crawlers: separate opt-in so a site can be indexable by search engines
         while still blocking LLM training crawlers. -->
    <meta name="ai-robots"     content="<?php echo htmlspecialchars($aiRobotsContent, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="GPTBot"        content="<?php echo htmlspecialchars($aiRobotsContent, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="ChatGPT-User"  content="<?php echo htmlspecialchars($aiRobotsContent, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="anthropic-ai"  content="<?php echo htmlspecialchars($aiRobotsContent, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="ClaudeBot"     content="<?php echo htmlspecialchars($aiRobotsContent, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="Google-Extended" content="<?php echo htmlspecialchars($aiRobotsContent, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="PerplexityBot" content="<?php echo htmlspecialchars($aiRobotsContent, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="CCBot"         content="<?php echo htmlspecialchars($aiRobotsContent, ENT_QUOTES, 'UTF-8'); ?>">

    <?php if ($showPoweredByMeta === true): ?>
    <meta name="generator" content="<?php echo htmlspecialchars($productName, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars($siteFavicon, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="apple-touch-icon" href="<?php echo htmlspecialchars($brandIconPath, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="apple-touch-icon" href="/assets/images/icon-192.svg">
    <title><?php echo htmlspecialchars($pageTitle . ' - ' . $siteName, ENT_QUOTES, 'UTF-8'); ?></title>

    <!-- 🎨 Stylesheets (CDN with local fallback, RTL variant if needed) -->
    <?php echo Asset::brandFontsCss(); ?>
    <?php echo Asset::bootstrapCss(I18n::isRtl()); ?>

    <?php echo Asset::fontAwesomeCss(); ?>

    <?php echo Asset::portalCss(); ?>

    <!-- 🌙 Prevent FOUC: apply saved theme + accessibility prefs before paint -->
    <script nonce="<?php echo htmlspecialchars(\Portal\Core\App::cspNonce(), ENT_QUOTES, 'UTF-8'); ?>">
    (function(){
        var html = document.documentElement;
        // Theme: 'light' / 'dark' / 'auto' (or null/missing = 'auto' default).
        var t = localStorage.getItem('portal-theme');
        if (t === 'auto' || t === null) {
            var prefersDark = window.matchMedia
                && window.matchMedia('(prefers-color-scheme: dark)').matches;
            html.setAttribute('data-bs-theme', prefersDark ? 'dark' : 'light');
        } else if (t === 'dark' || t === 'light') {
            html.setAttribute('data-bs-theme', t);
        }
        // Colour-blind safe palette (toggleable, opt-in)
        if (localStorage.getItem('portal-cb') === 'on') {
            html.setAttribute('data-portal-cb', 'on');
        }
    })();
    </script>

    <!-- 🖨️ Print stylesheet (#241). Activated by @media print + .print-view body class. -->
    <link rel="stylesheet" href="/assets/css/print.css" media="print">
    <link rel="stylesheet" href="/assets/css/print.css" media="screen" onload="this.media='not all'" disabled>
</head>
<body data-portal-name="<?php echo htmlspecialchars((string) ($SETTINGS['site']['name'] ?? 'Portal'), ENT_QUOTES, 'UTF-8'); ?>" data-print-date="<?php echo htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" data-portal-authenticated="<?php echo \Portal\Core\Auth::check() === true ? '1' : '0'; ?>">

<!-- ⚠️ Global noscript banner — visible only when JS is disabled -->
<noscript>
    <div class="portal-noscript-banner" role="alert"
         style="background:#fff3cd;color:#664d03;border-bottom:2px solid #ffc107;padding:.75rem 1rem;text-align:center;font-size:.9rem;">
        <strong>JavaScript is disabled.</strong>
        Core features (navigation, forms, login) will still work, but some interactive
        features (passkeys, dynamic form rows, dark mode) require JavaScript.
    </div>
    <style>
        /* 📌 No-JS global overrides */
        .accordion-collapse { display: block !important; }
        .navbar .dropdown:hover > .dropdown-menu,
        .navbar .dropdown:focus-within > .dropdown-menu { display: block; margin-top: 0; }
        .collapse:not(.navbar-collapse) { display: block !important; }
    </style>
</noscript>

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
