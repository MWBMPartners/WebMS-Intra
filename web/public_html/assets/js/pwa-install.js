/**
 * PWA "Install app" prompt (#141 residual)
 *
 * Captures `beforeinstallprompt`, suppresses the browser's own mini-infobar
 * (`event.preventDefault()`), and reveals the bottom-sheet markup
 * footer.php already renders (`#portal-install-prompt`, hidden via the
 * `d-none` class until this file removes it). Chromium-based browsers
 * only — Safari/iOS never fire this event, so the banner simply never
 * appears there; iOS's own "Add to Home Screen" flow is covered instead by
 * the apple-mobile-web-app-* meta tags header.php already emits.
 *
 * Dismissal is remembered in localStorage for DISMISS_DAYS (#141's
 * acceptance criteria: 30 days). A successful install is remembered
 * permanently via the `appinstalled` event so the banner never returns on
 * that device. Both reads/writes are try/catched — private browsing or a
 * disabled storage API just means the prompt won't remember state across
 * page loads, never a thrown error.
 *
 * Single vanilla JS file, no build step, self-hosted (CSP-safe — no new
 * external origin). Boots automatically on DOMContentLoaded.
 *
 * @package   Portal
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @see       https://github.com/MWBMPartners/WebMS-Intra/issues/141
 */
(function () {
    'use strict';

    var DISMISS_KEY   = 'portal-pwa-install-dismissed-at';
    var INSTALLED_KEY = 'portal-pwa-installed';
    var DISMISS_DAYS  = 30;

    // 🔄 The event Chromium fires ~asks us to hold onto so we can trigger
    // its native `.prompt()` later, from our OWN "Install" button click
    // (must happen inside a user-gesture handler — see wireBanner() below).
    var deferredPrompt = null;

    function readStorage(key) {
        try {
            return window.localStorage.getItem(key);
        } catch (err) {
            return null;
        }
    }

    function writeStorage(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch (err) {
            // 📋 Non-fatal — see file header.
        }
    }

    function isDismissed() {
        var raw = readStorage(DISMISS_KEY);
        if (!raw) {
            return false;
        }
        var dismissedAt = parseInt(raw, 10);
        if (isNaN(dismissedAt)) {
            return false;
        }
        var elapsedDays = (Date.now() - dismissedAt) / (1000 * 60 * 60 * 24);
        return elapsedDays < DISMISS_DAYS;
    }

    /** Already running as an installed app — standalone display-mode (most
     *  browsers) or the legacy `navigator.standalone` flag (iOS Safari). */
    function isStandalone() {
        return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches === true)
            || window.navigator.standalone === true;
    }

    function hideBanner(banner) {
        banner.classList.add('d-none');
    }

    function showBanner(banner) {
        // 📋 Nudge the banner above the cookie-consent banner if both
        // happen to be visible at once (rare in practice — a browser only
        // fires beforeinstallprompt after some engagement heuristic, by
        // which point the cookie banner has usually already been decided).
        var cookieBanner = document.getElementById('portal-cookie-banner');
        if (cookieBanner && cookieBanner.classList.contains('d-none') === false) {
            banner.style.bottom = (cookieBanner.offsetHeight + 24) + 'px';
        }
        banner.classList.remove('d-none');
    }

    function wireBanner(banner) {
        var buttons = banner.querySelectorAll('[data-portal-install]');
        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var action = btn.getAttribute('data-portal-install');

                if (action === 'accept' && deferredPrompt) {
                    // 🚀 Trigger the browser's real native install prompt.
                    deferredPrompt.prompt();
                    deferredPrompt.userChoice.finally(function () {
                        // Whether accepted or dismissed, our banner's job
                        // is done — the browser owns the rest of the flow.
                        // `appinstalled` (below) records a permanent
                        // suppression if the user actually installs.
                        deferredPrompt = null;
                        hideBanner(banner);
                    });
                } else {
                    writeStorage(DISMISS_KEY, String(Date.now()));
                    hideBanner(banner);
                }
            });
        });
    }

    function boot() {
        var banner = document.getElementById('portal-install-prompt');
        if (!banner) {
            return;
        }

        // 📋 Already installed (this device) or already running standalone
        // — never worth showing the banner at all.
        if (readStorage(INSTALLED_KEY) === '1' || isStandalone() === true) {
            return;
        }

        wireBanner(banner);

        window.addEventListener('beforeinstallprompt', function (event) {
            // 🛡️ Suppress the browser's own mini-infobar/UI (#141) — we
            // show our own tasteful, dismissible banner instead.
            event.preventDefault();
            deferredPrompt = event;

            if (isDismissed() === false) {
                showBanner(banner);
            }
        });

        window.addEventListener('appinstalled', function () {
            writeStorage(INSTALLED_KEY, '1');
            deferredPrompt = null;
            hideBanner(banner);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
