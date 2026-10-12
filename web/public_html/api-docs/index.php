<?php
// Path: public_html/api-docs/index.php
/**
 * -----------------------------------------------------------------------------
 * API Docs — Swagger UI viewer 📚
 * -----------------------------------------------------------------------------
 * Shows the portal's REST API in a form you can read and try out in a browser,
 * built from the description served at /openapi.json.
 *
 * WHERE THE SWAGGER UI FILES COME FROM
 * ------------------------------------
 * Swagger UI is a third-party library of one stylesheet and two scripts. The
 * page asks for them from a public content delivery network first (a CDN — a
 * set of servers on the public internet that host popular libraries), because
 * a visitor who has already loaded them on another site gets them instantly.
 *
 * The very same files are ALSO committed inside this portal, under
 * /assets/vendor/swagger-ui/. If the CDN cannot be reached — no internet on
 * the server's network, or a firewall blocking public CDNs — the browser falls
 * back to those local copies automatically and the page still works. Nothing
 * has to be installed on the server to make that happen, which matters because
 * this portal runs on shared hosting with no command line and no package
 * manager. See Portal\Core\Asset for how the fallback is wired up.
 *
 * On a network that blocks the CDN outright, the browser still has to try it
 * and wait for the attempt to fail before using the local copy, so the page
 * looks broken for a few seconds. Turning the setting
 * `api.docs.local_assets_only` on skips the CDN entirely. It is off by default.
 *
 * WHO CAN SEE THIS PAGE
 * ---------------------
 * Public on the live portal — the description at /openapi.json is public too,
 * and only the endpoints it describes require a login. On a pre-release copy
 * (beta, alpha, dev) it is behind the staff sign-in gate like every other
 * page, and while the portal is closed for an upgrade it shows the holding
 * page.
 *
 * This comment used to say "set isProtected = 1 on the `api-docs` row in
 * tblRoutes" to put the page behind the login wall. That never worked: this
 * folder really exists in the web folder, so the web server answers
 * /api-docs/ with this file directly and the router, which reads tblRoutes,
 * never sees the request. There is no setting that puts this page behind a
 * sign-in on the live portal.
 *
 * HOW IT STARTS, AND WHAT WAS WRONG (found 12 October 2026)
 * ---------------------------------------------------------
 * Because the web server runs this file directly, nothing has loaded the
 * portal before the first line below. The file used to go straight to
 * `Asset::swaggerUiLocalOnly()`, a class nothing had loaded, so the page was
 * a fatal error on every installation: a blank error page, or, wherever the
 * host's own PHP settings show errors, "Class Portal\Core\Asset not found"
 * with this file's full path, printed to any visitor. It was the one address
 * where the release channel had no say over error text.
 *
 * It now loads start-up itself when nothing has, the way error.php does, and
 * then asks the same two gates index.php asks. PHP's own error text is
 * switched off before start-up and again after it, on every channel:
 * start-up switches it ON for a pre-release copy, and this page has no
 * reason to print it.
 *
 * WHAT IT CANNOT DO: ask the organisation gate (SiteGate). The page is the
 * same for every organisation, and SiteGate's own header lists it as one of
 * the addresses it does not cover.
 *
 * @package   Portal\API
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.2.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Asset;
use Portal\Core\Auth;
use Portal\Core\Gatekeeper;
use Portal\Core\Maintenance;
use Portal\Core\Router;

// 🙈 Never print PHP's own error text from this page (see the header).
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

// 🚪 TWO WAYS IN. On Apache the web server runs this file directly, with
//    nothing loaded. Where a server hands every address to index.php instead,
//    the router finds this file through the `api-docs` row in tblRoutes and
//    includes it: start-up has run and index.php has already asked the gates.
//    PORTAL_ROOT is the first thing start-up defines, so it tells the two
//    apart.
if (defined('PORTAL_ROOT') === false) {
    // 🚀 Load the portal. The web folder is the folder ABOVE this one,
    //    whatever the hosting calls it; said before start-up, as index.php
    //    and error.php say it. A portal that is not installed yet has
    //    nothing to describe.
    $portalStartUp = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';
    $portalCreds   = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_auth_keys' . DIRECTORY_SEPARATOR . 'auth_creds.php';
    if (is_readable($portalStartUp) === false || is_readable($portalCreds) === false) {
        http_response_code(404);
        exit('Not found.');
    }
    define('PORTAL_WEBROOT', dirname(__DIR__));
    require_once $portalStartUp;
    // Start-up switches error text ON for a pre-release copy; off again.
    ini_set('display_errors', '0');

    // 🚧 The same two gates, in the same order, as index.php: closed for an
    //    upgrade, then the staff gate on a pre-release copy. Without them
    //    this page would be a way round both.
    Auth::ensureSession();
    if (Maintenance::blocks(Router::extractPath()) === true) {
        Maintenance::renderAndExit();
    }
    if (Gatekeeper::shouldEnforce(PORTAL_ENV, PORTAL_ENV_SOURCE) === true) {
        Gatekeeper::enforce(PORTAL_ENV);
    }
}

// 📚 Decide which copies of Swagger UI to load. Local-only mode is opt-in per
//    site; the default keeps the CDN-first behaviour with the local copies as
//    an automatic fallback.
$localOnly = Asset::swaggerUiLocalOnly();

if ($localOnly === true) {
    $tags      = Asset::swaggerUiLocalTags();
    $cssTag    = $tags['css'];
    $jsTag     = $tags['js'];
    $presetTag = $tags['preset'];
} else {
    $cssTag    = Asset::swaggerUiCss();
    $jsTag     = Asset::swaggerUiJs();
    $presetTag = Asset::swaggerUiPresetJs();
}

// 🔑 A token that proves a form submission came from this page and not from
//    another website. The portal requires one on every request that changes
//    data. This page draws its own <head> rather than using the shared page
//    template, so it has to publish the token itself — without it, every
//    "Try it out" on a POST/PUT/PATCH/DELETE endpoint is rejected.
//
//    Reading the token starts a session for the visitor. That is the same
//    thing every other page in the portal does, and the token is useless to
//    anyone who does not already hold this visitor's session cookie.
$csrfToken = Auth::csrfToken();

// 🛡️ This page draws its own <head>, so it sets its own security headers.
//    Keep the policy tight: allow the jsdelivr CDN only when we are actually
//    going to ask it for something. Inline script and style are needed for the
//    small start-up script at the bottom of this file and the few style rules
//    in the <head>.
$cdnSource  = $localOnly === true ? '' : ' https://cdn.jsdelivr.net';
header("Content-Security-Policy: default-src 'self'; "
     . "script-src 'self' 'unsafe-inline'" . $cdnSource . '; '
     . "style-src 'self' 'unsafe-inline'" . $cdnSource . '; '
     . "img-src 'self' data:; "
     . "font-src 'self' data:; "
     . "connect-src 'self'; "
     . "base-uri 'self'; "
     . "form-action 'self'; "
     . "frame-ancestors 'none'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
    <title>WebMS Intra REST API — Documentation</title>
    <?php echo $cssTag; ?>
    <style>
        body { margin: 0; background: #fafafa; }
        .topbar { display: none !important; }
        .swagger-ui .info { margin: 24px 0; }
    </style>
</head>
<body>

<div id="swagger-ui"></div>

<?php echo $jsTag; ?>
<?php echo $presetTag; ?>
<script>
(function () {
    'use strict';

    /* ------------------------------------------------------------------
       The anti-forgery token, and why it has to be kept up to date.

       The portal changes this token every time it accepts one, so an
       intercepted token can never be replayed. That means the copy this page
       is holding is stale the moment a "Try it out" write succeeds, and the
       NEXT write would be rejected.

       Endpoints that expect to be called repeatedly hand the fresh token back
       in their JSON reply, under the name "csrfToken" — the same convention
       the event hub upload widget already uses. We watch every reply for that
       field and keep our copy current.
       ------------------------------------------------------------------ */

    function readToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta !== null ? (meta.getAttribute('content') || '') : '';
    }

    function storeToken(token) {
        if (!token) { return; }
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta !== null) { meta.setAttribute('content', token); }
    }

    function init() {
        window.ui = SwaggerUIBundle({
            url: '/openapi.json',
            dom_id: '#swagger-ui',
            deepLinking: true,
            presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
            layout: 'BaseLayout',

            // 🚫 Do not send this portal's API description to Swagger's public
            //    online validator. It would tell an outside service the address
            //    of this install, and the page's own security policy blocks the
            //    call anyway, which leaves a broken badge and console errors.
            validatorUrl: null,

            requestInterceptor: function (req) {
                var token = readToken();
                if (token && /^(POST|PUT|PATCH|DELETE)$/i.test(req.method || '')) {
                    req.headers = req.headers || {};
                    req.headers['X-CSRF-TOKEN'] = token;
                }
                // Same origin, so the login cookie travels automatically.
                // An API key can be supplied instead through the "Authorize"
                // button; requests made that way ignore the token above.
                req.credentials = 'same-origin';
                return req;
            },

            responseInterceptor: function (res) {
                try {
                    var body = res && res.body;
                    if (body && typeof body === 'object') {
                        // The standard API envelope puts it in `meta`; a few
                        // older, hand-written endpoints return it at the top
                        // level. Accept either.
                        storeToken(
                            (body.meta && body.meta.csrfToken) || body.csrfToken
                        );
                    }
                } catch (e) {
                    // A reply that is not JSON is perfectly normal (a CSV
                    // download, for example). Nothing to do.
                }
                return res;
            },
        });
    }

    if (window.SwaggerUIBundle) { init(); } else { window.addEventListener('load', init); }
})();
</script>

</body>
</html>
