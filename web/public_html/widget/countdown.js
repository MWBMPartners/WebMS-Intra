/* ============================================================================
 * WebMS Intra — Embeddable Countdown Widget (#319)
 * ============================================================================
 * Drop-in countdown to the next service for an external church website.
 *
 * USAGE
 * -----
 * Paste this anywhere in your page:
 *
 *   <div id="webms-countdown"></div>
 *   <script src="https://your-portal.example.org/widget/countdown.js"
 *           data-portal="https://your-portal.example.org"
 *           data-target="#webms-countdown"
 *           defer></script>
 *
 * Options on the <script> tag:
 *   data-portal   — base URL of your portal install (required).
 *   data-target   — CSS selector of the container to render into. Defaults
 *                   to "#webms-countdown".
 *   data-poll-min — polling interval in minutes. Defaults to 15.
 *   data-theme    — 'light' | 'dark' | 'auto'. Defaults to 'auto' (matches
 *                   the host page via prefers-color-scheme).
 *
 * The widget polls /widget/countdown.json on the portal every 15 min for
 * the next upcoming event and ticks the countdown locally between fetches.
 * Renders in light + dark via CSS-in-JS — no external stylesheet needed.
 *
 * Brand-aware: the JSON feed returns the active product name (WebMS Intra
 * / ChurchMS / etc.) which the widget shows as a tiny attribution footer.
 *
 * @link  https://github.com/MWBMPartners/webMS-Intra/issues/319
 * ============================================================================ */
(function () {
    'use strict';

    // 🛟 IE11 / very-old-browser guard. Modern features only past this point.
    if (!window.fetch || !document.querySelector) {
        return;
    }

    // 📋 Read config from the script tag that loaded us.
    var thisScript = document.currentScript;
    if (!thisScript) {
        var scripts = document.getElementsByTagName('script');
        for (var i = scripts.length - 1; i >= 0; i--) {
            if (scripts[i].src && scripts[i].src.indexOf('countdown.js') !== -1) {
                thisScript = scripts[i];
                break;
            }
        }
    }
    if (!thisScript) return;

    var PORTAL  = thisScript.getAttribute('data-portal') || '';
    var TARGET  = thisScript.getAttribute('data-target') || '#webms-countdown';
    var POLL    = parseInt(thisScript.getAttribute('data-poll-min') || '15', 10) * 60 * 1000;
    var THEME   = thisScript.getAttribute('data-theme') || 'auto';

    if (!PORTAL) {
        console.warn('[webms-countdown] data-portal attribute is required');
        return;
    }

    var container = document.querySelector(TARGET);
    if (!container) {
        console.warn('[webms-countdown] target ' + TARGET + ' not found');
        return;
    }

    // 🎨 Inject our scoped styles once (idempotent).
    if (!document.getElementById('webms-countdown-styles')) {
        var style = document.createElement('style');
        style.id = 'webms-countdown-styles';
        style.textContent =
            '.webms-cd{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;' +
            'border-radius:.75rem;padding:1.5rem 1.25rem;text-align:center;' +
            'background:linear-gradient(135deg,#5e6ad2,#7a85e8);color:#fff;}' +
            '.webms-cd--dark{background:linear-gradient(135deg,#3d4ba0,#5e6ad2);}' +
            '.webms-cd__title{font-size:1rem;opacity:.85;margin:0 0 .25rem;font-weight:500;}' +
            '.webms-cd__event{font-size:1.5rem;font-weight:700;margin:0 0 1rem;letter-spacing:-.01em;}' +
            '.webms-cd__clock{display:flex;gap:.5rem;justify-content:center;font-variant-numeric:tabular-nums;}' +
            '.webms-cd__unit{background:rgba(255,255,255,.18);padding:.5rem .75rem;border-radius:.5rem;min-width:3.5rem;}' +
            '.webms-cd__num{font-size:1.75rem;font-weight:700;line-height:1;display:block;}' +
            '.webms-cd__lbl{font-size:.7rem;opacity:.75;text-transform:uppercase;letter-spacing:.1em;margin-top:.25rem;display:block;}' +
            '.webms-cd__attr{font-size:.7rem;opacity:.6;margin-top:1rem;}' +
            '.webms-cd__attr a{color:inherit;text-decoration:underline;}' +
            '.webms-cd--empty{opacity:.6;font-size:.95rem;}' +
            '@media (prefers-color-scheme: dark){' +
            '  .webms-cd[data-theme="auto"]{background:linear-gradient(135deg,#3d4ba0,#5e6ad2);}' +
            '}';
        document.head.appendChild(style);
    }

    var state = { nextEvent: null, productName: 'Portal', siteName: '' };

    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    function render() {
        var themeClass = THEME === 'dark' ? ' webms-cd--dark' : '';
        if (!state.nextEvent) {
            container.innerHTML =
                '<div class="webms-cd webms-cd--empty' + themeClass + '" data-theme="' + THEME + '">' +
                '<p class="webms-cd__title">No upcoming services</p>' +
                '<p class="webms-cd__event">Check back soon</p>' +
                attributionHtml() +
                '</div>';
            return;
        }
        var startMs = Date.parse(state.nextEvent.startsAt);
        var diff = Math.max(0, Math.floor((startMs - Date.now()) / 1000));
        var d = Math.floor(diff / 86400);
        var h = Math.floor((diff % 86400) / 3600);
        var m = Math.floor((diff % 3600) / 60);
        var s = diff % 60;
        container.innerHTML =
            '<div class="webms-cd' + themeClass + '" data-theme="' + THEME + '">' +
            '<p class="webms-cd__title">Next service at ' + escapeHtml(state.siteName) + '</p>' +
            '<p class="webms-cd__event">' + escapeHtml(state.nextEvent.title) + '</p>' +
            '<div class="webms-cd__clock">' +
            unit(d, 'days') + unit(h, 'hrs') + unit(m, 'min') + unit(s, 'sec') +
            '</div>' +
            attributionHtml() +
            '</div>';
    }

    function unit(n, lbl) {
        return '<div class="webms-cd__unit"><span class="webms-cd__num">' + pad(n) + '</span><span class="webms-cd__lbl">' + lbl + '</span></div>';
    }

    function attributionHtml() {
        return '<p class="webms-cd__attr">Powered by <a href="' + PORTAL + '">' + escapeHtml(state.productName) + '</a></p>';
    }

    function escapeHtml(s) {
        var div = document.createElement('div');
        div.textContent = s == null ? '' : String(s);
        return div.innerHTML;
    }

    function fetchAndUpdate() {
        fetch(PORTAL + '/widget/countdown.json', { credentials: 'omit' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data) return;
                state.nextEvent   = data.nextEvent;
                state.siteName    = data.siteName    || '';
                state.productName = data.productName || 'Portal';
                render();
            })
            .catch(function () { /* swallow — next poll will retry */ });
    }

    // 🔄 Initial fetch + slow poll for updates; local tick keeps the clock fresh.
    fetchAndUpdate();
    setInterval(fetchAndUpdate, POLL);
    setInterval(render, 1000);
})();
