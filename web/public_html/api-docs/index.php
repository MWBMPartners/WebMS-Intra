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
 * Public by default — the description at /openapi.json is public too, and only
 * the endpoints it describes require a login. To put the documentation behind
 * the login wall, set isProtected = 1 on the `api-docs` row in tblRoutes.
 *
 * @package   Portal\API
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Asset;
use Portal\Core\Auth;

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
