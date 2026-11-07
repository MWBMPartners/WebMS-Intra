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
 * The two links to the privacy page, and the address the cookie banner posts
 * a choice to, are built by Site::url() and Router::urlForScript() (#536), so
 * they stay inside the organisation's own address. The script files and the
 * service worker are real files in the web folder, the same for every
 * organisation, and keep their plain addresses.
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
use Portal\Core\Debug;
use Portal\Core\Router;
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

// 🏷️ "Powered by <product>" attribution — shown when the active site uses
// CUSTOM branding (any branding field differs from the active product brand
// default) AND the admin has not opted out via `branding.hidePoweredBy`.
// The product name (WebMS Intra / ChurchMS / etc.) comes from the brand
// layer added in issue #296. See Site::productName().
$hidePoweredBy = (App::settings('branding.hidePoweredBy') === 'true');
$showPoweredBy = ($hidePoweredBy === false) && (Site::usesCustomBranding() === true);
$productName   = Site::productName();
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
                <span class="portal-powered-by-mark"><?php echo htmlspecialchars($productName, ENT_QUOTES, 'UTF-8'); ?></span>
            </span>
        <?php endif; ?>
    </div>
</footer>

<!-- 📦 JavaScript (CDN with local fallback) -->
<?php echo Asset::bootstrapJs(); ?>

<?php echo Asset::portalJs(); ?>

<!-- 📥 Offline queue (#233) — IndexedDB-backed write buffer for forms with
     data-offline-queueable. Auto-drains on `online` event + visibilitychange. -->
<script defer src="/assets/js/portal-offline.js"></script>

<!-- 🖱️ Small actions asked for by a data- attribute (#625): "send this form
     when the choice changes" and "select this text on click". They used to be
     handlers written on the element (onchange="...", onclick="..."), which
     browsers have refused on every page with this header since #144. A script
     file from the portal's own address is allowed. -->
<script defer src="/assets/js/portal-actions.js"></script>

<?php
// 🌐 The "Translate" control (#485, #761). Does nothing at all unless the
//    page carries the control's own attribute, which only
//    `_core/templates/translate-control.php` writes, and only where
//    `ContentTranslation::whyNotOffered()` allows it. A file rather than a
//    script in the page, for the nonce reason given in its own header.
//
//    ⚠️ A PHP COMMENT, NOT AN HTML ONE, AND THAT IS THE POINT. The first
//    version was an HTML comment naming the attribute, so EVERY page the
//    portal served carried that name in its source — which both shipped an
//    implementation note to every visitor and made four checks in
//    `tools/content-translation-selftest.php` wrong, because searching a page
//    for the attribute then found the comment on pages that have no control
//    at all. The repo's own #647 lesson, in a new place: a check that looks
//    for a name in a page can be answered by a comment mentioning it. Keep
//    notes for developers out of what is served.
?>
<script defer src="/assets/js/portal-translate.js"></script>

<?php
// 🐛 Debug panel (only renders for admins with ?debug=true)
echo Debug::renderPanel();

// 📚 ONE PLACE AT THE FOOT OF THE SCREEN FOR BOTH NOTICES (#822). The cookie
//    banner and the "Install app" prompt were each `position-fixed bottom-0`,
//    so when both were showing they sat exactly on top of each other —
//    measured in Edge at every width, the two boxes occupying the same pixels.
//    pwa-install.js did try to lift the prompt above the banner, by setting
//    `style.bottom`; it never worked, because Bootstrap's `bottom-0` is
//    `bottom: 0 !important` and an inline style without `!important` loses
//    to it. Rather than fight that with a sharper rule (it would still have
//    been wrong the moment the banner's height changed with the screen
//    width, or the banner was answered and removed), the two now sit inside
//    one fixed column that stacks them: `.portal-bottom-notices` in
//    portal.css. Neither can cover the other however tall it is, and when one
//    goes the other simply moves down.
?>
<div class="portal-bottom-notices">
<?php
// 🍪 Cookie consent banner — only renders when:
//   • The privacy.cookieBannerEnabled setting is 'true' (default), AND
//   • The visitor has no existing 'portal_consent_cookies' cookie
$cookieBannerEnabled = (App::settings('privacy.cookieBannerEnabled') ?? 'true') === 'true';
if ($cookieBannerEnabled === true && isset($_COOKIE['portal_consent_cookies']) === false):
    $bannerCsrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
?>
<aside id="portal-cookie-banner"
       class="portal-bottom-notice p-3 shadow-lg rounded-3"
       style="max-width: 640px;"
       role="dialog"
       aria-labelledby="portal-cookie-banner-title"
       aria-describedby="portal-cookie-banner-desc">
    <h2 id="portal-cookie-banner-title" class="h6 mb-2">
        <i class="fa-solid fa-cookie-bite me-1"></i>Cookies on this portal
    </h2>
    <p id="portal-cookie-banner-desc" class="small text-secondary mb-2">
        We use functionally-necessary cookies (sign-in, CSRF, theme + language preferences). No third-party tracking.
        Read the full <a href="<?php echo htmlspecialchars(Site::url('privacy'), ENT_QUOTES, 'UTF-8'); ?>">Privacy &amp; Data Protection</a> page.
    </p>
    <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-sm btn-primary"   data-portal-consent="accept">Accept</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-portal-consent="reject">Necessary only</button>
        <a class="btn btn-sm btn-link ms-auto" href="<?php echo htmlspecialchars(Site::url('privacy'), ENT_QUOTES, 'UTF-8'); ?>">More info</a>
    </div>
</aside>
<script nonce="<?php echo htmlspecialchars(\Portal\Core\App::cspNonce(), ENT_QUOTES, 'UTF-8'); ?>">
(function () {
    var banner = document.getElementById('portal-cookie-banner');
    if (!banner) { return; }
    var csrf = <?php echo json_encode($bannerCsrf); ?>;

    function record(decision) {
        var fd = new FormData();
        // 🔑 READ THE TOKEN AT THE MOMENT OF POSTING, NEVER THE ONE CAPTURED AT
        //    RENDER (#767). `Auth::verifyCsrf()` REPLACES the token every time
        //    it accepts one, so anything else on the page that posts — a
        //    Translate press, a guided-tour step, a push subscription — leaves
        //    the copy baked into this script dead. The member then presses
        //    Accept or Reject, nothing is recorded, and **the banner comes
        //    back on the next page as though they had never answered**: their
        //    choice about cookies is silently lost, which is the one kind of
        //    answer a portal must not mislay.
        //
        //    `Portal.csrf()` reads whichever carrier the page has (#768) and
        //    is always current, because `Portal.writeCsrf()` keeps every
        //    carrier in step. The captured value stays as the fallback for a
        //    page where portal.js somehow did not load.
        var live = (window.Portal && typeof window.Portal.csrf === 'function')
            ? window.Portal.csrf()
            : null;
        fd.append('csrf_token', live || csrf);
        fd.append('type', 'cookies');
        fd.append('decision', decision);
<?php
// 🏢 The address on the next line is this organisation's own, printed by PHP
//    (#536). It was the bare '/privacy/consent', which under "the first part
//    of the address" sent the choice to the DEFAULT organisation.
//    Router::urlForScript() escapes it for a script and leaves the quotes to
//    the line itself. This note is a PHP comment on purpose: a comment
//    written in the script would be sent to every browser, and the page
//    would no longer be byte for byte what it was.
?>
        fetch('<?php echo Router::urlForScript('privacy/consent'); ?>', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (answer) {
                // 🔑 This request was accepted, so the token has rotated again.
                //    Hand the answer to the one helper that keeps every carrier
                //    in step (#768), or the next thing on this page to post
                //    inherits the problem this banner just had.
                return answer.json().catch(function () { return null; }).then(function (body) {
                    if (body !== null && window.Portal
                        && typeof window.Portal.rememberCsrf === 'function') {
                        window.Portal.rememberCsrf(body);
                    }
                    return answer.ok;
                });
            })
            .then(function (worked) {
                // ⚠️ ONLY TAKE THE BANNER AWAY IF THE CHOICE WAS ACTUALLY
                //    RECORDED. This used to be `.finally()`, which removed it
                //    whatever happened — so a refused post left the member
                //    believing they had answered while nothing was stored, and
                //    the banner returned on the next page with no explanation.
                //    Leaving it up is not a nice outcome, but it is an honest
                //    one: the member can press again, and pressing again now
                //    works, because the token is read at the moment of posting.
                if (worked === true && banner.parentNode) {
                    banner.parentNode.removeChild(banner);
                }
            })
            .catch(function () {
                // The request never arrived. Same reasoning: say nothing and
                // leave the choice unanswered rather than pretend.
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

<!-- 📱 PWA: "Install app" bottom-sheet prompt (#141 residual) — hidden by
     default (d-none); assets/js/pwa-install.js reveals it once the browser
     fires `beforeinstallprompt` AND the visitor hasn't dismissed it within
     the last 30 days (localStorage — see DISMISS_KEY in that file).
     Chromium-based browsers only: iOS Safari never fires this event, so the
     banner never shows there — iOS's own "Add to Home Screen" flow is
     covered by the apple-mobile-web-app-* meta tags in header.php instead. -->
<aside id="portal-install-prompt"
       class="portal-bottom-notice p-3 shadow-lg rounded-3 d-none"
       style="max-width: 420px;"
       role="dialog"
       aria-labelledby="portal-install-prompt-title"
       aria-describedby="portal-install-prompt-desc">
    <h2 id="portal-install-prompt-title" class="h6 mb-2">
        <i class="fa-solid fa-download me-1"></i>Install <?php echo htmlspecialchars($productName, ENT_QUOTES, 'UTF-8'); ?>
    </h2>
    <p id="portal-install-prompt-desc" class="small text-secondary mb-2">
        Add it to your home screen for quick, full-screen access — works offline too.
    </p>
    <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-sm btn-primary" data-portal-install="accept">Install</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-portal-install="dismiss">Not now</button>
    </div>
</aside>
</div><?php // closes .portal-bottom-notices, opened above the cookie banner ?>
<script defer src="/assets/js/pwa-install.js"></script>

<!-- 📱 PWA: Service Worker registration -->
<script nonce="<?php echo htmlspecialchars(\Portal\Core\App::cspNonce(), ENT_QUOTES, 'UTF-8'); ?>">
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () {});
}
</script>

</body>
</html>
