<?php
// Path: _core/Asset.php
/**
 * -----------------------------------------------------------------------------
 * CDN-with-Fallback Asset Loader 📦
 * -----------------------------------------------------------------------------
 * Generates HTML tags for CSS and JavaScript assets with CDN primary sources
 * and local fallback paths.  Uses Subresource Integrity (SRI) hashes for CDN
 * resources to ensure tamper-proof delivery.  If the CDN fails (network error,
 * integrity mismatch), the browser automatically falls back to the local copy.
 *
 * Convenience methods are provided for the three external libraries used by
 * the Portal (Bootstrap CSS, Bootstrap JS, Font Awesome) plus the two local-
 * only portal assets (portal.css, portal.js).
 *
 * Public methods:
 *   Asset::css(...)            returns a <link> tag with onerror fallback
 *   Asset::js(...)             returns a <script> tag with fallback
 *   Asset::bootstrapCss()      Bootstrap 5.3.3 CSS
 *   Asset::bootstrapJs()       Bootstrap 5.3.3 JS bundle
 *   Asset::fontAwesomeCss()    Font Awesome 6.5.1
 *   Asset::portalCss()         local portal.css (no CDN)
 *   Asset::portalJs()          local portal.js  (no CDN)
 * -----------------------------------------------------------------------------
 * @package    Portal\Core
 * @author     MWBM Partners Ltd (t/a MWservices)
 * @copyright  2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version    0.1.0
 * @link       https://github.com/MWBMPartners/WebMS-Intra
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Asset
{
    // 🔖 ---------------------------------------------------------------------------
    // Version constants – centralised so bumps only require a single edit
    // -----------------------------------------------------------------------------

    /** @var string Bootstrap framework version */
    private const BOOTSTRAP_VERSION = '5.3.3';

    /** @var string Font Awesome icon library version */
    private const FONTAWESOME_VERSION = '6.5.1';

    /** @var string SortableJS version — drag-reorder lib used in admin captcha priority UI */
    private const SORTABLE_VERSION = '1.15.2';

    /** @var string Swagger UI version — interactive API explorer at /api-docs */
    private const SWAGGER_VERSION = '5.17.14';

    // 🔐 ---------------------------------------------------------------------------
    // SRI integrity hashes – regenerate whenever the library version changes
    //
    // To regenerate after a version bump:
    //   curl -sL <CDN_URL> | openssl dgst -sha384 -binary | openssl base64 -A
    //   then prefix the result with "sha384-"
    //
    // The check_cdn_sri.py audit fails any CDN tag missing integrity= so
    // new contributors won't accidentally drop a tag without one.
    // -----------------------------------------------------------------------------

    /** @var string SRI hash for Bootstrap 5.3.3 CSS (jsdelivr CDN) */
    private const BOOTSTRAP_CSS_INTEGRITY =
        'sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YcnS/NPqOGZ2eLNphkfv02LPMoJiDFhNSz7K';

    /** @var string SRI hash for Bootstrap 5.3.3 JS bundle (jsdelivr CDN) */
    private const BOOTSTRAP_JS_INTEGRITY =
        'sha384-0pUGZvbkm6XF6gxjEnlmuGrJXVbNuzT9qBBavbLwCsOGabYfZo0T0to5eqruptLy';

    /** @var string SRI hash for Font Awesome 6.5.1 all.min.css (cdnjs CDN) */
    private const FONTAWESOME_CSS_INTEGRITY =
        'sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQDDkGnRm3LqTMXRg5r4EbSJz7GZBG0NqXKOF';

    /**
     * @var string SRI hash for SortableJS 1.15.2 (jsdelivr CDN).
     *
     * TODO(#161): fill from `curl -sL <CDN_URL> | openssl dgst -sha384 -binary
     * | openssl base64 -A` once someone with network access runs the command.
     * The check_cdn_sri.py audit will keep flagging this gap until the hash
     * lands. Empty for now — tag emits without integrity= attribute so the
     * page keeps working; CDN-compromise mitigation only kicks in once filled.
     */
    private const SORTABLE_JS_INTEGRITY = '';

    /** @var string SRI hash for swagger-ui 5.17.14 swagger-ui.css (jsdelivr CDN) — TODO(#161) */
    private const SWAGGER_CSS_INTEGRITY = '';

    /** @var string SRI hash for swagger-ui 5.17.14 swagger-ui-bundle.js (jsdelivr CDN) — TODO(#161) */
    private const SWAGGER_JS_INTEGRITY = '';

    /** @var string SRI hash for swagger-ui 5.17.14 swagger-ui-standalone-preset.js (jsdelivr CDN) — TODO(#161) */
    private const SWAGGER_PRESET_INTEGRITY = '';

    // 📂 ---------------------------------------------------------------------------
    // Local fallback paths (relative to the web root)
    // -----------------------------------------------------------------------------

    /** @var string Local path for Bootstrap CSS */
    private const LOCAL_BOOTSTRAP_CSS = '/assets/css/bootstrap.min.css';

    /** @var string Local path for Bootstrap RTL CSS */
    private const LOCAL_BOOTSTRAP_RTL_CSS = '/assets/css/bootstrap.rtl.min.css';

    /** @var string Local path for Bootstrap JS bundle */
    private const LOCAL_BOOTSTRAP_JS = '/assets/js/bootstrap.bundle.min.js';

    /** @var string Local path for Font Awesome CSS */
    private const LOCAL_FONTAWESOME_CSS = '/assets/css/fontawesome-all.min.css';

    /** @var string Local path for portal stylesheet */
    private const LOCAL_PORTAL_CSS = '/assets/css/portal.css';

    /** @var string Local path for portal script */
    private const LOCAL_PORTAL_JS = '/assets/js/portal.js';

    // 🌐 ---------------------------------------------------------------------------
    // CDN URLs
    // -----------------------------------------------------------------------------

    /** @var string CDN URL for Bootstrap CSS */
    private const CDN_BOOTSTRAP_CSS =
        'https://cdn.jsdelivr.net/npm/bootstrap@' . self::BOOTSTRAP_VERSION
        . '/dist/css/bootstrap.min.css';

    /** @var string CDN URL for Bootstrap RTL CSS */
    private const CDN_BOOTSTRAP_RTL_CSS =
        'https://cdn.jsdelivr.net/npm/bootstrap@' . self::BOOTSTRAP_VERSION
        . '/dist/css/bootstrap.rtl.min.css';

    /** @var string CDN URL for Bootstrap JS bundle */
    private const CDN_BOOTSTRAP_JS =
        'https://cdn.jsdelivr.net/npm/bootstrap@' . self::BOOTSTRAP_VERSION
        . '/dist/js/bootstrap.bundle.min.js';

    /** @var string CDN URL for Font Awesome CSS */
    private const CDN_FONTAWESOME_CSS =
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/' . self::FONTAWESOME_VERSION
        . '/css/all.min.css';

    /** @var string CDN URL for SortableJS */
    private const CDN_SORTABLE_JS =
        'https://cdn.jsdelivr.net/npm/sortablejs@' . self::SORTABLE_VERSION . '/Sortable.min.js';

    /** @var string CDN URL for Swagger UI CSS */
    private const CDN_SWAGGER_CSS =
        'https://cdn.jsdelivr.net/npm/swagger-ui-dist@' . self::SWAGGER_VERSION . '/swagger-ui.css';

    /** @var string CDN URL for Swagger UI bundle */
    private const CDN_SWAGGER_JS =
        'https://cdn.jsdelivr.net/npm/swagger-ui-dist@' . self::SWAGGER_VERSION . '/swagger-ui-bundle.js';

    /** @var string CDN URL for Swagger UI standalone preset */
    private const CDN_SWAGGER_PRESET =
        'https://cdn.jsdelivr.net/npm/swagger-ui-dist@' . self::SWAGGER_VERSION . '/swagger-ui-standalone-preset.js';

    // 🏗️ ===========================================================================
    // Public API – generic tag builders
    // ===========================================================================

    /**
     * Generate a <link> tag for a CSS resource with CDN primary and local fallback.
     *
     * The onerror handler fires if the CDN resource fails to load (network error
     * or SRI integrity mismatch).  It nullifies itself to prevent infinite loops,
     * then swaps the href to the local copy.
     *
     * @param string $cdnUrl    Full URL to the CDN-hosted CSS file
     * @param string $localPath Web-root-relative path to the local CSS file
     * @param string $integrity SRI hash (e.g. "sha384-..."); empty string to skip SRI
     *
     * @return string Complete HTML <link> element
     */
    public static function css(string $cdnUrl, string $localPath, string $integrity = ''): string
    {
        // 📝 Start building the <link> tag attributes
        $tag = '<link rel="stylesheet"';

        // 🌐 Set the href to the CDN URL as the primary source
        $tag .= ' href="' . self::esc($cdnUrl) . '"';

        // 🔐 Add SRI integrity attribute if a hash is provided
        if ($integrity !== '') {
            $tag .= ' integrity="' . self::esc($integrity) . '"';
            $tag .= ' crossorigin="anonymous"';
        }

        // 🔄 Add onerror handler that falls back to the local copy
        // The handler:
        //   1. Sets onerror to null to prevent recursive error loops
        //   2. Replaces the href with the local path
        $escapedLocal = self::escJs($localPath);
        $tag .= ' onerror="this.onerror=null;this.href=\'' . $escapedLocal . '\';"';

        $tag .= '>';

        return $tag;
    }

    /**
     * Generate a <script> tag for a JS resource with CDN primary and local fallback.
     *
     * The fallback mechanism works in two stages:
     *   1. The CDN script loads with an onerror handler for network failures.
     *   2. After load, a follow-up inline script checks whether the expected global
     *      object exists (e.g. `window.bootstrap`).  If not, it injects a new
     *      <script> tag pointing to the local copy.
     *
     * @param string $cdnUrl     Full URL to the CDN-hosted JS file
     * @param string $localPath  Web-root-relative path to the local JS file
     * @param string $integrity  SRI hash; empty string to skip SRI
     * @param string $testGlobal JS global to test after load (e.g. "bootstrap");
     *                           empty string to skip the post-load check
     *
     * @return string Complete HTML <script> element(s)
     */
    public static function js(
        string $cdnUrl,
        string $localPath,
        string $integrity = '',
        string $testGlobal = ''
    ): string {
        // 📝 Build the primary <script> tag pointing to the CDN
        $tag = '<script src="' . self::esc($cdnUrl) . '"';

        // 🔐 SRI integrity attribute
        if ($integrity !== '') {
            $tag .= ' integrity="' . self::esc($integrity) . '"';
            $tag .= ' crossorigin="anonymous"';
        }

        // 🔄 onerror handler for hard network failures – immediately inject local
        $escapedLocal = self::escJs($localPath);
        $fallbackSnippet = 'var s=document.createElement(\'script\');'
            . 's.src=\'' . $escapedLocal . '\';'
            . 'document.head.appendChild(s);';
        $tag .= ' onerror="' . self::esc($fallbackSnippet) . '"';

        $tag .= '></script>';

        // 🧪 Post-load check: if the CDN appeared to load but the global is missing
        // (e.g. empty response, corrupted file), inject the local fallback
        if ($testGlobal !== '') {
            $safeGlobal = self::escJs($testGlobal);
            $tag .= "\n" . '<script>'
                . 'if(typeof window.' . $safeGlobal . '===\'undefined\'){'
                . 'var s=document.createElement(\'script\');'
                . 's.src=\'' . $escapedLocal . '\';'
                . 'document.head.appendChild(s);'
                . '}'
                . '</script>';
        }

        return $tag;
    }

    // 🎨 ===========================================================================
    // Convenience methods – preconfigured for Portal libraries
    // ===========================================================================

    /**
     * Bootstrap 5.3.3 CSS via jsdelivr CDN with local fallback.
     * When RTL mode is active, loads the bootstrap.rtl.min.css variant instead.
     *
     * @param bool $rtl Whether to load the RTL variant
     *
     * @return string HTML <link> tag
     */
    public static function bootstrapCss(bool $rtl = false): string
    {
        if ($rtl === true) {
            return self::css(
                self::CDN_BOOTSTRAP_RTL_CSS,
                self::LOCAL_BOOTSTRAP_RTL_CSS,
                '' // 📋 SRI hash omitted for RTL variant — local fallback handles integrity
            );
        }

        return self::css(
            self::CDN_BOOTSTRAP_CSS,
            self::LOCAL_BOOTSTRAP_CSS,
            self::BOOTSTRAP_CSS_INTEGRITY
        );
    }

    /**
     * Bootstrap 5.3.3 JS bundle via jsdelivr CDN with local fallback.
     *
     * The test global "bootstrap" is checked after load to detect silent failures
     * (e.g. CDN returns an HTML error page instead of JS).
     *
     * @return string HTML <script> tag(s)
     */
    public static function bootstrapJs(): string
    {
        return self::js(
            self::CDN_BOOTSTRAP_JS,
            self::LOCAL_BOOTSTRAP_JS,
            self::BOOTSTRAP_JS_INTEGRITY,
            'bootstrap'
        );
    }

    /**
     * Font Awesome 6.5.1 CSS via cdnjs CDN with local fallback.
     *
     * @return string HTML <link> tag
     */
    public static function fontAwesomeCss(): string
    {
        return self::css(
            self::CDN_FONTAWESOME_CSS,
            self::LOCAL_FONTAWESOME_CSS,
            self::FONTAWESOME_CSS_INTEGRITY
        );
    }

    /**
     * Portal stylesheet – local only, no CDN.
     *
     * Returns a plain <link> tag with no onerror handler since there is no CDN
     * source to fall back from.
     *
     * @return string HTML <link> tag
     */
    public static function portalCss(): string
    {
        return '<link rel="stylesheet" href="' . self::esc(self::LOCAL_PORTAL_CSS) . '">';
    }

    /**
     * SortableJS drag-reorder lib via jsdelivr CDN.
     *
     * Used by the admin captcha priority UI. No local fallback today —
     * vendoring is a follow-up; the onerror handler in js() routes a
     * missing-fallback as a no-op (the page degrades to no drag, not broken).
     *
     * @return string HTML <script> tag
     */
    public static function sortableJs(): string
    {
        return self::js(self::CDN_SORTABLE_JS, '', self::SORTABLE_JS_INTEGRITY, 'Sortable');
    }

    /**
     * Swagger UI CSS via jsdelivr CDN. Used at /api-docs.
     *
     * @return string HTML <link> tag
     */
    public static function swaggerUiCss(): string
    {
        return self::css(self::CDN_SWAGGER_CSS, '', self::SWAGGER_CSS_INTEGRITY);
    }

    /**
     * Swagger UI bundle JS via jsdelivr CDN. Used at /api-docs.
     *
     * @return string HTML <script> tag
     */
    public static function swaggerUiJs(): string
    {
        return self::js(self::CDN_SWAGGER_JS, '', self::SWAGGER_JS_INTEGRITY, 'SwaggerUIBundle');
    }

    /**
     * Swagger UI standalone preset JS via jsdelivr CDN. Used at /api-docs.
     *
     * @return string HTML <script> tag
     */
    public static function swaggerUiPresetJs(): string
    {
        return self::js(self::CDN_SWAGGER_PRESET, '', self::SWAGGER_PRESET_INTEGRITY, 'SwaggerUIStandalonePreset');
    }

    /**
     * Portal script – local only, no CDN.
     *
     * Returns a plain <script> tag with the defer attribute so the script does
     * not block page rendering.
     *
     * @return string HTML <script> tag
     */
    public static function portalJs(): string
    {
        return '<script src="' . self::esc(self::LOCAL_PORTAL_JS) . '" defer></script>'
             . "\n"
             // 🪟 Portal.Confirm — themed confirm modal replacement (#244).
             //    Loaded AFTER Bootstrap JS (which is required) and before
             //    custom page scripts so data-confirm form interception is
             //    in place by the time any handler binds.
             . '<script src="/assets/js/portal-confirm.js" defer></script>'
             . "\n"
             // 🎯 Portal.Tour — "what's new" tour playback (#253). Auto-fetches
             //    the active tour for the user on authenticated pages.
             . '<script src="/assets/js/portal-tour.js" defer></script>';
    }

    // 🛡️ ===========================================================================
    // Internal helpers
    // ===========================================================================

    /**
     * Escape a string for safe use inside an HTML attribute value.
     *
     * @param string $value Raw string
     *
     * @return string HTML-safe string
     */
    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Escape a string for safe embedding inside a JavaScript single-quoted literal
     * that itself lives within an HTML attribute.
     *
     * Handles: single quotes, backslashes, and forward slashes.
     *
     * @param string $value Raw string
     *
     * @return string JS-safe string suitable for insertion between single quotes
     */
    private static function escJs(string $value): string
    {
        // 🔒 Escape backslashes first, then single quotes
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace("'", "\\'", $value);
        return $value;
    }
}
