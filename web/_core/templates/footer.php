<?php
// Path: _core/templates/footer.php
/**
 * -----------------------------------------------------------------------------
 * Shared Page Footer Template 📄
 * -----------------------------------------------------------------------------
 * Closes the main content container, renders the site footer with copyright,
 * includes JavaScript assets (Bootstrap JS + portal.js), renders the debug
 * panel (if active), and closes <body> and <html> tags.
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
use Portal\Core\Debug;
use Portal\Core\Site;

// 📌 Copyright information — site branding overrides global setting
$copyrightOrg  = Site::branding('copyright') ?? App::settings('site.copyrightOrg') ?? 'MWBM Partners Ltd';
$copyrightYear = App::settings('site.copyrightStartYear') ?? '2025';
$currentYear   = date('Y');

// 📅 Format the copyright year range
$yearDisplay = $copyrightYear;
if ($copyrightYear !== $currentYear) {
    $yearDisplay = $copyrightYear . '-' . $currentYear;
}

// 🏷️ "Powered by WebMS Intra" attribution — shown when the active site uses
// CUSTOM branding (any branding field differs from the WebMS Intra default)
// AND the admin has not opted out via the `branding.hidePoweredBy` setting.
$hidePoweredBy = (App::settings('branding.hidePoweredBy') === 'true');
$showPoweredBy = ($hidePoweredBy === false) && (Site::usesCustomBranding() === true);
?>

</div><!-- /.container -->
</main>

<!-- 📌 Site Footer -->
<footer class="portal-footer">
    <div class="container text-center">
        <span>&copy; <?php echo htmlspecialchars($yearDisplay, ENT_QUOTES, 'UTF-8'); ?>
              <?php echo htmlspecialchars($copyrightOrg, ENT_QUOTES, 'UTF-8'); ?>.
              <?php echo htmlspecialchars(t('common.all_rights_reserved'), ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="d-none d-md-inline ms-2 text-muted">
            v<?php echo htmlspecialchars(App::version(), ENT_QUOTES, 'UTF-8'); ?>
        </span>
        <?php if ($showPoweredBy === true): ?>
            <span class="portal-powered-by ms-md-2">
                <span class="portal-powered-by-prefix">Powered by</span>
                <span class="portal-powered-by-mark">WebMS Intra</span>
            </span>
        <?php endif; ?>
    </div>
</footer>

<!-- 📦 JavaScript (CDN with local fallback) -->
<?php echo Asset::bootstrapJs(); ?>

<?php echo Asset::portalJs(); ?>

<?php
// 🐛 Debug panel (only renders for admins with ?debug=true)
echo Debug::renderPanel();

// 🍪 Cookie consent banner — only renders when:
//   • The privacy.cookieBannerEnabled setting is 'true' (default), AND
//   • The visitor has no existing 'portal_consent_cookies' cookie
$cookieBannerEnabled = (App::settings('privacy.cookieBannerEnabled') ?? 'true') === 'true';
if ($cookieBannerEnabled === true && isset($_COOKIE['portal_consent_cookies']) === false):
    $bannerCsrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
?>
<aside id="portal-cookie-banner"
       class="position-fixed bottom-0 start-0 end-0 m-3 p-3 shadow-lg rounded-3"
       style="background: var(--portal-surface); border: 1px solid var(--portal-border); z-index: 1080; max-width: 640px; margin-inline: auto !important;"
       role="dialog"
       aria-labelledby="portal-cookie-banner-title"
       aria-describedby="portal-cookie-banner-desc">
    <h2 id="portal-cookie-banner-title" class="h6 mb-2">
        <i class="fa-solid fa-cookie-bite me-1"></i>Cookies on this portal
    </h2>
    <p id="portal-cookie-banner-desc" class="small text-secondary mb-2">
        We use functionally-necessary cookies (sign-in, CSRF, theme + language preferences). No third-party tracking.
        Read the full <a href="/privacy">Privacy &amp; Data Protection</a> page.
    </p>
    <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-sm btn-primary"   data-portal-consent="accept">Accept</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-portal-consent="reject">Necessary only</button>
        <a class="btn btn-sm btn-link ms-auto" href="/privacy">More info</a>
    </div>
</aside>
<script>
(function () {
    var banner = document.getElementById('portal-cookie-banner');
    if (!banner) { return; }
    var csrf = <?php echo json_encode($bannerCsrf); ?>;

    function record(decision) {
        var fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('type', 'cookies');
        fd.append('decision', decision);
        fetch('/privacy/consent', { method: 'POST', body: fd, credentials: 'same-origin' })
            .finally(function () {
                banner.parentNode && banner.parentNode.removeChild(banner);
            });
    }
    banner.querySelectorAll('[data-portal-consent]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            record(btn.getAttribute('data-portal-consent'));
        });
    });
})();
</script>
<?php endif; ?>

<!-- 📱 PWA: Service Worker registration -->
<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () {});
}
</script>

<!-- 🍪 Cookie consent banner (#224). UK ICO compliance.
     Banner only renders client-side when no consent cookie is present.
     Storage: consent cookie (12-month expiry) with categories: essential
     (always on), functional, analytics. Granular page at /privacy/consent. -->
<div id="portalCookieBanner"
     class="portal-cookie-banner d-none"
     role="dialog"
     aria-label="Cookie consent"
     style="position:fixed;bottom:0;left:0;right:0;z-index:1050;padding:1rem;background:var(--bs-body-bg);border-top:1px solid var(--bs-border-color);box-shadow:0 -4px 12px rgba(0,0,0,0.08);">
    <div class="container d-flex flex-column flex-md-row align-items-md-center gap-3">
        <div class="flex-grow-1 small">
            <strong>Cookies.</strong> We use essential cookies to sign you in and remember preferences.
            Optional cookies help us improve the portal. See our
            <a href="/privacy/consent">consent settings</a> or our
            <a href="/privacy">privacy notice</a>.
        </div>
        <div class="d-flex gap-2 flex-shrink-0">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-cookie-action="essential">Essential only</button>
            <button type="button" class="btn btn-primary btn-sm" data-cookie-action="all">Accept all</button>
        </div>
    </div>
</div>
<script>
(function () {
    var name = 'portal_consent';
    var existing = document.cookie.split(';').some(function (c) {
        return c.trim().indexOf(name + '=') === 0;
    });
    if (existing === true) {
        return;
    }
    var banner = document.getElementById('portalCookieBanner');
    if (banner === null) {
        return;
    }
    banner.classList.remove('d-none');
    function setConsent(level) {
        // 12-month expiry
        var d = new Date();
        d.setTime(d.getTime() + (365 * 24 * 60 * 60 * 1000));
        document.cookie = name + '=' + encodeURIComponent(level)
                        + '; expires=' + d.toUTCString()
                        + '; path=/; SameSite=Lax; Secure';
        banner.classList.add('d-none');
    }
    banner.querySelectorAll('[data-cookie-action]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            setConsent(btn.getAttribute('data-cookie-action'));
        });
    });
})();
</script>

</body>
</html>
