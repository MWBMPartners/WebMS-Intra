<?php
// Path: _core/Asset.php
/**
 * -----------------------------------------------------------------------------
 * CDN-with-Fallback Asset Loader 📦
 * -----------------------------------------------------------------------------
 * Generates HTML tags for CSS and JavaScript assets with CDN primary sources
 * and local fallback paths.  Uses Subresource Integrity (SRI) hashes for CDN
 * resources to ensure tamper-proof delivery.  If the CDN fails (network error,
 * integrity mismatch), the browser falls back to the portal's own copy.
 *
 * HOW THE FALLBACK WORKS SINCE #625. Each tag that has an own copy names it in
 * a data- attribute (data-asset-fallback), and ONE small script, written just
 * before the first such tag of a page, listens for a tag failing and switches
 * it over. It used to be a handler written on each tag (onerror="...") plus a
 * bare script after the Bootstrap one. Since 3 June 2026 (#144) the page
 * header tells browsers to run only scripts that carry the page's one-time
 * mark (a nonce), and a browser then refuses every handler written on an
 * element and every script without the mark. So for four months the fallback
 * could not run on any page that uses the standard header: on a network that
 * blocks the content delivery network, pages had no styling, no icons, no
 * menus and no boxes. Do not bring the handlers back. See fallbackListener().
 *
 * Convenience methods are provided for the three external libraries used by
 * the Portal (Bootstrap CSS, Bootstrap JS, Font Awesome) plus the two local-
 * only portal assets (portal.css, portal.js).
 *
 * Public methods:
 *   Asset::css(...)            returns a <link> tag that names its own copy
 *   Asset::js(...)             returns a <script> tag that names its own copy
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

    /** @var string Chart.js version — bar/line charts on the Reports Builder (#156) */
    private const CHARTJS_VERSION = '4.4.4';

    // 🔐 ---------------------------------------------------------------------------
    // SRI integrity hashes – regenerate whenever the library version changes
    //
    // To regenerate after a version bump:
    //   curl -sL <CDN_URL> | openssl dgst -sha384 -binary | openssl base64 -A
    //   then prefix the result with "sha384-"
    //
    // The check_cdn_sri.py audit fails any CDN tag missing integrity= so
    // new contributors won't accidentally drop a tag without one. That only
    // proves a hash is PRESENT. tools/cdn-sri-selftest.php downloads each file
    // and works the hash out again, so a WRONG value fails too (#568: three of
    // these were wrong, so browsers refused the file and every page lost its
    // styling and logged a console error).
    // -----------------------------------------------------------------------------

    /** @var string SRI hash for Bootstrap 5.3.3 CSS (jsdelivr CDN) */
    private const BOOTSTRAP_CSS_INTEGRITY =
        'sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH';

    /** @var string SRI hash for Bootstrap 5.3.3 RTL CSS (jsdelivr CDN) */
    private const BOOTSTRAP_RTL_CSS_INTEGRITY =
        'sha384-dpuaG1suU0eT09tx5plTaGMLBsfDLzUCCUXOY2j/LSvXYuG6Bqs43ALlhIqAJVRb';

    /** @var string SRI hash for Bootstrap 5.3.3 JS bundle (jsdelivr CDN) */
    private const BOOTSTRAP_JS_INTEGRITY =
        'sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz';

    /** @var string SRI hash for Font Awesome 6.5.1 all.min.css (cdnjs CDN) */
    private const FONTAWESOME_CSS_INTEGRITY =
        'sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g';

    /** @var string SRI hash for SortableJS 1.15.2 (jsdelivr CDN) */
    private const SORTABLE_JS_INTEGRITY =
        'sha384-BSxuMLxX+FCbTdYec3TbXlnMGEEM2QXTFdtDaveen71o+jswm2J36+xFqp8k4VHM';

    /**
     * @var string SRI hash for Chart.js 4.4.4 dist/chart.umd.js (jsdelivr
     * CDN). Deliberately the UNMINIFIED UMD build, not `chart.umd.min.js` —
     * the 4.x npm package ships no pre-minified UMD file at all (verified
     * by inspecting the extracted tarball's dist/ listing), and jsdelivr's
     * on-the-fly ".min.js" minification pipeline would make the served
     * bytes NOT independently reproducible from the npm tarball. Hash
     * derived exactly like every other CDN asset in this file when
     * jsdelivr itself is unreachable from the build sandbox (location-
     * chunkA Leaflet / #456 precedent): downloaded the real npm tarball
     * (registry.npmjs.org, which IS reachable) and hashed
     * dist/chart.umd.js directly —
     *   curl -sL https://registry.npmjs.org/chart.js/-/chart.js-4.4.4.tgz
     *     | tar -xzO package/dist/chart.umd.js
     *     | openssl dgst -sha384 -binary | openssl base64 -A
     * — since jsdelivr's npm CDN serves package files byte-identical to
     * the published tarball at their real path, this hash matches what
     * https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.js serves.
     */
    private const CHARTJS_INTEGRITY =
        'sha384-G436+Z2nlA8+PNoeRvWdxKbvOf8E/y+lYxqht2iBwNHTQDV5CJr3+AGVj8fGZi5t';

    /** @var string SRI hash for swagger-ui 5.17.14 swagger-ui.css (jsdelivr CDN) */
    private const SWAGGER_CSS_INTEGRITY =
        'sha384-wxLW6kwyHktdDGr6Pv1zgm/VGJh99lfUbzSn6HNHBENZlCN7W602k9VkGdxuFvPn';

    /** @var string SRI hash for swagger-ui 5.17.14 swagger-ui-bundle.js (jsdelivr CDN) */
    private const SWAGGER_JS_INTEGRITY =
        'sha384-wmyclcVGX/WhUkdkATwhaK1X1JtiNrr2EoYJ+diV3vj4v6OC5yCeSu+yW13SYJep';

    /** @var string SRI hash for swagger-ui 5.17.14 swagger-ui-standalone-preset.js (jsdelivr CDN) */
    private const SWAGGER_PRESET_INTEGRITY =
        'sha384-2YH8WDRaj7V2OqU/trsmzSagmk/E2SutiCsGkdgoQwC9pNUJV1u/141DHB6jgs8t';

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

    /** @var string Local brand fonts CSS (Plus Jakarta Sans @font-face) */
    private const LOCAL_BRAND_FONTS_CSS = '/assets/css/brand-fonts.css';

    /** @var string Local Plus Jakarta Sans WOFF2 (regular weight) — used as <link rel="preload"> hint */
    private const LOCAL_BRAND_FONT_PRELOAD = '/assets/fonts/plus-jakarta-sans/plus-jakarta-sans-400.woff2';

    /** @var string Local path for portal script */
    private const LOCAL_PORTAL_JS = '/assets/js/portal.js';

    /*  📚 Swagger UI — self-hosted copies, so the API documentation page at
     *  /api-docs still works when the jsdelivr CDN is unreachable.
     *
     *  Why this matters here more than for the other libraries: the portal is
     *  sold to organisations that often run it behind a restrictive corporate
     *  or school network, and some of those networks block public CDNs
     *  outright. Without a local copy the /api-docs page renders as a blank
     *  white screen with no explanation. The three files below are committed
     *  to the repository and deploy with the rest of public_html/, so no
     *  command line, package manager or build step is ever needed on the
     *  server — which matters because the portal runs on shared hosting where
     *  none of those are available.
     *
     *  The committed files are byte-identical to what the CDN serves: each one
     *  hashes to the matching SRI constant above. To refresh after a version
     *  bump, see DEV_NOTES.md → "Refreshing the self-hosted Swagger UI files".
     */

    /** @var string Local path for Swagger UI CSS */
    private const LOCAL_SWAGGER_CSS =
        '/assets/vendor/swagger-ui/swagger-ui-' . self::SWAGGER_VERSION . '.css';

    /** @var string Local path for Swagger UI bundle */
    private const LOCAL_SWAGGER_JS =
        '/assets/vendor/swagger-ui/swagger-ui-bundle-' . self::SWAGGER_VERSION . '.js';

    /** @var string Local path for Swagger UI standalone preset */
    private const LOCAL_SWAGGER_PRESET =
        '/assets/vendor/swagger-ui/swagger-ui-standalone-preset-' . self::SWAGGER_VERSION . '.js';

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

    /** @var string CDN URL for Chart.js — unminified UMD build, see CHARTJS_INTEGRITY doc comment */
    private const CDN_CHARTJS =
        'https://cdn.jsdelivr.net/npm/chart.js@' . self::CHARTJS_VERSION . '/dist/chart.umd.js';

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
     * If the CDN resource fails to load (network error or SRI integrity
     * mismatch), the listener written by fallbackListener() swaps the href to
     * the local copy named in data-asset-fallback. It does that once for each
     * tag, so a missing local copy cannot start a loop.
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

        // 🔄 Name the local copy. This was an onerror="..." handler, which
        //    browsers refuse on a page with the standard header (#625, and
        //    the file heading). The listener reads this attribute instead.
        $tag .= ' data-asset-fallback="' . self::esc($localPath) . '"';

        $tag .= '>';

        return self::fallbackListener() . $tag;
    }

    /**
     * Generate a <script> tag for a JS resource with CDN primary and local fallback.
     *
     * The fallback has two triggers, both heard by the listener that
     * fallbackListener() writes:
     *   1. The CDN script fails to load (network failure, or its fingerprint
     *      does not match).
     *   2. The CDN script loads but the expected global object is missing
     *      (e.g. `window.bootstrap`): an empty or wrong file was sent.
     * Either way a new <script> tag pointing to the local copy is added to the
     * page's head. It is added, not written in place, so it runs when it has
     * arrived: a page script that needs the library the instant the page is
     * read may run first. That was so before #625 as well.
     *
     * With no local copy ($localPath is '', as for SortableJS and Chart.js)
     * nothing is named and nothing happens on failure. Before #625 the handler
     * added a script tag with an empty address, which also did nothing.
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

        // 🔄 Name the local copy, and the global to look for once the file
        //    has loaded. These were an onerror="..." handler and a bare
        //    <script> after the tag; browsers refuse both on a page with the
        //    standard header (#625, and the file heading). With no local copy
        //    there is nothing to fall back to, so nothing is named.
        if ($localPath === '') {
            return $tag . '></script>';
        }
        $tag .= ' data-asset-fallback="' . self::esc($localPath) . '"';
        if ($testGlobal !== '') {
            $tag .= ' data-asset-global="' . self::esc($testGlobal) . '"';
        }

        $tag .= '></script>';

        return self::fallbackListener() . $tag;
    }

    /** @var bool Whether this request has already been given the listener. */
    private static bool $fallbackListenerWritten = false;

    /**
     * The one small script that makes the local fallback work (#625).
     *
     * Returned the FIRST time a tag with a local copy is made in a request,
     * and an empty string after that, so a page carries it once, just before
     * its first such tag. It has to come BEFORE the tags it serves: a failure
     * that happens before anything is listening is never heard. So print tags
     * in the order they were made (every caller does: header.php prints each
     * as it makes it, and /api-docs makes and prints the stylesheet first).
     *
     * WHAT IT DOES. It listens on the document for two things a <link> or a
     * <script> tells only itself about, `error` and `load`. Neither travels up
     * the page, which is why both are caught on the way DOWN (the `true` at
     * the end of each addEventListener). For a tag that names a local copy:
     *   - a stylesheet that failed gets the local copy as its address. Its
     *     fingerprint stays on the tag, as it always did, so the local copy
     *     must be the same file byte for byte. The copies that
     *     tools/download-cdn-fallbacks.sh fetches are.
     *   - a script that failed, or that loaded without defining the global it
     *     names, gets a new script tag for the local copy added to the head.
     * Each tag is switched over once: its attribute is removed first.
     *
     * WHY IT CARRIES THE NONCE. It is a script written into the page, so on a
     * page with the standard header it runs only with the page's one-time mark.
     * On a page that sends no rule (sign-in, the public forms) or its own rule
     * without a nonce (/api-docs) the attribute does no harm. The script it
     * adds needs no mark: it comes from the portal's own address, which the
     * rule allows by name ('self').
     *
     * WHAT IT CANNOT DO. It cannot help when the local copy is missing: the
     * copies are not kept in git, they are fetched into an installation
     * package when it is built. It cannot make a late library early (see
     * js()). And it does nothing in a browser with scripts switched off, where
     * a failed stylesheet stays failed, as it did with the old handler.
     *
     * The two values it reads from the tag (an address and a name) are
     * constants of this class, never anything a person typed.
     *
     * @return string A <script> element, or '' when this request has it already
     */
    private static function fallbackListener(): string
    {
        if (self::$fallbackListenerWritten === true) {
            return '';
        }
        self::$fallbackListenerWritten = true;

        return '<script nonce="' . self::esc(App::cspNonce()) . '">'
            . '(function(){'
            . 'if(window.__portalAssetFallback){return;}'
            . 'window.__portalAssetFallback=true;'
            . 'function own(e){'
            . 'var t=e.target;'
            . 'if(!t||!t.getAttribute){return;}'
            . 'var f=t.getAttribute("data-asset-fallback");'
            . 'if(!f){return;}'
            . 'if(e.type==="load"){'
            . 'var g=t.getAttribute("data-asset-global");'
            . 'if(t.tagName!=="SCRIPT"||!g||typeof window[g]!=="undefined"){return;}'
            . '}'
            . 't.removeAttribute("data-asset-fallback");'
            . 'if(t.tagName==="LINK"){t.href=f;return;}'
            . 'var s=document.createElement("script");s.src=f;document.head.appendChild(s);'
            . '}'
            . 'document.addEventListener("error",own,true);'
            . 'document.addEventListener("load",own,true);'
            . '})();'
            . '</script>' . "\n";
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
                self::BOOTSTRAP_RTL_CSS_INTEGRITY
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
     * Returns a plain <link> tag that names no fallback, since there is no CDN
     * source to fall back from.
     *
     * @return string HTML <link> tag
     */
    public static function portalCss(): string
    {
        return '<link rel="stylesheet" href="' . self::esc(self::LOCAL_PORTAL_CSS) . '">';
    }

    /**
     * Brand fonts CSS — self-hosted Plus Jakarta Sans @font-face declarations
     * (5 weights, latin subset, ~12 KB each). Drives every page's body font
     * via the --portal-font-family CSS variable. Should be emitted in <head>
     * BEFORE portal.css so the variable is resolved when portal.css reads it.
     *
     * Includes a <link rel="preload"> hint for the regular weight so the
     * browser can start fetching the font in parallel with the CSS — fewer
     * milliseconds of fallback-font FOUT on first paint.
     *
     * @return string HTML <link> tag(s)
     */
    public static function brandFontsCss(): string
    {
        return '<link rel="preload" href="' . self::esc(self::LOCAL_BRAND_FONT_PRELOAD)
             . '" as="font" type="font/woff2" crossorigin="anonymous">'
             . '<link rel="stylesheet" href="' . self::esc(self::LOCAL_BRAND_FONTS_CSS) . '">';
    }

    /**
     * SortableJS drag-reorder lib via jsdelivr CDN.
     *
     * Used by the admin captcha priority UI. No local fallback today —
     * vendoring is a follow-up; with no local copy js() names no fallback, so
     * a failure does nothing (the page degrades to no drag, not broken).
     *
     * @return string HTML <script> tag
     */
    public static function sortableJs(): string
    {
        return self::js(self::CDN_SORTABLE_JS, '', self::SORTABLE_JS_INTEGRITY, 'Sortable');
    }

    /**
     * Chart.js 4.4.4 via jsdelivr CDN. Used by the Reports Builder (#156)
     * for the bar/line chart on a grouped report's run page. No local
     * fallback today (same posture as sortableJs() above) — a CDN failure
     * degrades to no chart, not a broken page (run.php still renders the
     * data as a portal-data-list table regardless of chart load success).
     *
     * @return string HTML <script> tag
     */
    public static function chartJs(): string
    {
        return self::js(self::CDN_CHARTJS, '', self::CHARTJS_INTEGRITY, 'Chart');
    }

    /**
     * Swagger UI CSS — jsdelivr CDN first, self-hosted copy as the fallback.
     * Used at /api-docs.
     *
     * @return string HTML <link> tag
     */
    public static function swaggerUiCss(): string
    {
        return self::css(self::CDN_SWAGGER_CSS, self::LOCAL_SWAGGER_CSS, self::SWAGGER_CSS_INTEGRITY);
    }

    /**
     * Swagger UI bundle JS — jsdelivr CDN first, self-hosted copy as the
     * fallback. Used at /api-docs.
     *
     * @return string HTML <script> tag
     */
    public static function swaggerUiJs(): string
    {
        return self::js(
            self::CDN_SWAGGER_JS,
            self::LOCAL_SWAGGER_JS,
            self::SWAGGER_JS_INTEGRITY,
            'SwaggerUIBundle'
        );
    }

    /**
     * Swagger UI standalone preset JS — jsdelivr CDN first, self-hosted copy
     * as the fallback. Used at /api-docs.
     *
     * @return string HTML <script> tag
     */
    public static function swaggerUiPresetJs(): string
    {
        return self::js(
            self::CDN_SWAGGER_PRESET,
            self::LOCAL_SWAGGER_PRESET,
            self::SWAGGER_PRESET_INTEGRITY,
            'SwaggerUIStandalonePreset'
        );
    }

    /**
     * Force the API documentation page to use only the self-hosted Swagger UI
     * files, never the CDN.
     *
     * Some organisations run the portal on a network that blocks public
     * content delivery networks. On those networks the browser has to try the
     * CDN, wait for it to fail, and only then load the local copy — which
     * makes /api-docs look broken for a few seconds on every visit. Turning
     * the setting `api.docs.local_assets_only` on removes that wait by
     * skipping the CDN entirely.
     *
     * Off by default, so nothing changes for the majority of installs that
     * can reach the CDN and benefit from its caching.
     *
     * @return bool True when the page should load local files only.
     */
    public static function swaggerUiLocalOnly(): bool
    {
        // Settings may be unavailable very early in bootstrap; default to the
        // CDN-first behaviour if we cannot read the setting.
        if (class_exists('\Portal\Core\Settings') === false) {
            return false;
        }

        return Settings::get('api.docs.local_assets_only', 'false') === 'true';
    }

    /**
     * The three self-hosted Swagger UI tags, with no CDN reference at all.
     *
     * Used by /api-docs when `api.docs.local_assets_only` is turned on.
     *
     * @return array{css: string, js: string, preset: string}
     */
    public static function swaggerUiLocalTags(): array
    {
        return [
            'css'    => '<link rel="stylesheet" href="' . self::esc(self::LOCAL_SWAGGER_CSS) . '">',
            'js'     => '<script src="' . self::esc(self::LOCAL_SWAGGER_JS) . '"></script>',
            'preset' => '<script src="' . self::esc(self::LOCAL_SWAGGER_PRESET) . '"></script>',
        ];
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
}
