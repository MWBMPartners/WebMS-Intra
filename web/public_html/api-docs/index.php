<?php
// Path: public_html/api-docs/index.php
/**
 * -----------------------------------------------------------------------------
 * API Docs — Swagger UI viewer 📚
 * -----------------------------------------------------------------------------
 * Renders the Swagger UI loaded from a CDN, pointed at /openapi.json.
 *
 * Public by default (the spec at /openapi.json is itself public — only the
 * endpoints it describes require auth). If you want the docs behind login,
 * flip the route to isProtected = 1 in tblRoutes.
 *
 * @package   Portal\API
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// 🛡️ The Swagger UI page bypasses the shared header template so it needs
// to set its own security headers. Keep the CSP minimal but allow the
// jsdelivr CDN that ships the UI bundle. inline scripts/styles are needed
// for the Swagger init code below.
header("Content-Security-Policy: default-src 'self'; "
     . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
     . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
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
    <title>WebMS Intra REST API — Documentation</title>
    <?php echo \Portal\Core\Asset::swaggerUiCss(); ?>
    <style>
        body { margin: 0; background: #fafafa; }
        .topbar { display: none !important; }
        .swagger-ui .info { margin: 24px 0; }
    </style>
</head>
<body>

<div id="swagger-ui"></div>

<?php echo \Portal\Core\Asset::swaggerUiJs(); ?>
<?php echo \Portal\Core\Asset::swaggerUiPresetJs(); ?>
<script>
(function () {
    function init() {
        // 🔍 Pull the CSRF token from the same place the rest of the portal
        // expects it — the <meta name="csrf-token"> in the header template.
        // We attach it to every request fired from "Try it out".
        var csrfToken = '';
        var csrfMeta  = document.querySelector('meta[name="csrf-token"]');
        if (csrfMeta) { csrfToken = csrfMeta.getAttribute('content') || ''; }

        window.ui = SwaggerUIBundle({
            url: '/openapi.json',
            dom_id: '#swagger-ui',
            deepLinking: true,
            presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
            layout: 'BaseLayout',
            requestInterceptor: function (req) {
                if (csrfToken && /^(POST|PUT|PATCH|DELETE)$/i.test(req.method || '')) {
                    req.headers = req.headers || {};
                    req.headers['X-CSRF-TOKEN'] = csrfToken;
                }
                // Same-origin: cookies (session) flow automatically. No need
                // for a Bearer token unless you add JWT later.
                req.credentials = 'same-origin';
                return req;
            },
        });
    }
    if (window.SwaggerUIBundle) { init(); } else { window.addEventListener('load', init); }
})();
</script>

</body>
</html>
