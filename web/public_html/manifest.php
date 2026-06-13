<?php
// Path: public_html/manifest.php
/**
 * -----------------------------------------------------------------------------
 * PWA Manifest Controller — Brand-aware /manifest.json 📱
 * -----------------------------------------------------------------------------
 * Replaces the previously-static `manifest.json` with a brand-aware controller
 * so the PWA "Install app" prompt shows the correct product name (e.g.
 * "ChurchMS Portal" on a church install, "WebMS Intra Portal" on a generic
 * install) — see issue #296 for the product brand layer.
 *
 * Routed via tblRoutes:
 *   routeKey   = 'manifest.json'
 *   targetFile = 'manifest.php'
 *   isProtected = 0  (public — browsers fetch this before login)
 *
 * The static manifest.json file is deliberately deleted so Apache's
 * "skip rewrite if static file exists" check falls through to index.php,
 * which then dispatches here via the Router.
 *
 * @package   Portal\App
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/296
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Site;

// 🏷️ Resolve the brand-aware fields. Site::productName() / productTagline()
// fall back through (DB settings → bootstrap constants → hardcoded defaults).
$productName    = Site::productName();
$productTagline = Site::productTagline();

// 🎨 Pull through the per-tenant primary colour if set (matches header.php).
//    Falls back to Bootstrap's primary so PWA shells render consistently
//    with what the user already sees in the browser.
$siteColor = Site::branding('color') ?? '#0d6efd';

// 🖼️ Resolve per-brand asset paths.
//    Folder = $brandPresets[<industry>]['assetFolder'] (e.g. 'church').
//    Each folder is expected to contain logo.svg + icon-{192,512}.svg.
//    If the folder is missing on disk, fall back to the existing
//    /assets/images/* placeholders so PWA install never breaks.
$industry  = (string) (App::settings('portal.industry') ?? '');
$presets   = (array) (require PORTAL_CORE . DIRECTORY_SEPARATOR . 'brand-defaults.php');
$preset    = $presets[$industry] ?? $presets[''] ?? [];
$assetDir  = (string) ($preset['assetFolder'] ?? 'generic');
$brandRoot = PORTAL_APPS . DIRECTORY_SEPARATOR . 'assets'
           . DIRECTORY_SEPARATOR . 'images'
           . DIRECTORY_SEPARATOR . 'brands'
           . DIRECTORY_SEPARATOR . $assetDir;

$icon192 = is_readable($brandRoot . DIRECTORY_SEPARATOR . 'icon-192.svg')
    ? '/assets/images/brands/' . $assetDir . '/icon-192.svg'
    : '/assets/images/icon-192.svg';
$icon512 = is_readable($brandRoot . DIRECTORY_SEPARATOR . 'icon-512.svg')
    ? '/assets/images/brands/' . $assetDir . '/icon-512.svg'
    : '/assets/images/icon-512.svg';
$logo    = is_readable($brandRoot . DIRECTORY_SEPARATOR . 'logo.svg')
    ? '/assets/images/brands/' . $assetDir . '/logo.svg'
    : '/assets/images/logo.svg';

// 🧱 Build the manifest. Field order kept stable so diff-driven debugging
//    (Chrome DevTools → Application → Manifest) stays readable.
$manifest = [
    'name'             => $productName . ' Portal',
    'short_name'       => 'Portal',
    'description'      => $productTagline,
    'start_url'        => '/',
    'scope'            => '/',
    'display'          => 'standalone',
    'orientation'      => 'any',
    'theme_color'      => $siteColor,
    'background_color' => '#f8f9fa',
    'lang'             => 'en',
    'dir'              => 'auto',
    'categories'       => ['business', 'productivity'],
    'icons'            => [
        [
            'src'     => $icon192,
            'sizes'   => '192x192',
            'type'    => 'image/svg+xml',
            'purpose' => 'any maskable',
        ],
        [
            'src'     => $icon512,
            'sizes'   => '512x512',
            'type'    => 'image/svg+xml',
            'purpose' => 'any maskable',
        ],
        [
            'src'   => $logo,
            'sizes' => 'any',
            'type'  => 'image/svg+xml',
        ],
    ],
];

// 📡 Emit. Use the official PWA manifest MIME type so browsers recognise
//    this as a manifest even when served via PHP.
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
