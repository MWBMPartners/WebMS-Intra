<?php
// Path: _core/partials/location-map-assets.php
/**
 * -----------------------------------------------------------------------------
 * Shared partial — Leaflet map assets + init script 🗺️ (#456 Chunk A)
 * -----------------------------------------------------------------------------
 * `portal_location_map_assets(string $cspNonce): void` — emits the pinned
 * Leaflet 1.9.4 CDN tags (with SRI — see DEV_NOTES.md for the verification
 * evidence and the upgrade procedure) plus one nonce'd init script. Include
 * ONCE per map-bearing page, just before `footer.php`.
 *
 * CSP: `script-src`/`style-src` already allow `https://cdn.jsdelivr.net`
 * (header.php's hard-coded policy) — no header.php edit needed. Tiles need
 * the caller to set `$cspImgExtra = 'https://*.tile.openstreetmap.org';`
 * BEFORE requiring header.php (page-scoped widening, #386 precedent).
 *
 * Leaflet's default marker icon resolves relative to the CSS URL (jsdelivr)
 * — NOT covered by `img-src`. Fix: the init script below builds an inline
 * SVG `L.divIcon` marker instead of loading Leaflet's default PNG icons —
 * zero extra CSP surface, no header.php change required.
 *
 * @package   Portal\Core\Partials
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/456
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

if (function_exists('portal_location_map_assets') === false) {
    function portal_location_map_assets(string $cspNonce): void
    {
        $nonce = htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8');
        $attribution = htmlspecialchars(t('location.map_attribution'), ENT_QUOTES, 'UTF-8');
        ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha384-sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H"
      crossorigin="anonymous">
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha384-cxOPjt7s7Iz04uaHJceBmS+qpjv2JkIHNVcuOrM+YHwZOmJGBXI00mdUXEq65HTH"
        crossorigin="anonymous"></script>
<script nonce="<?php echo $nonce; ?>">
(function () {
    'use strict';

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    // 🗺️ Interactive maps — one per .portal-location-map[data-lat] element.
    //    Inline SVG divIcon marker (never Leaflet's default PNG icons) so
    //    img-src needs only the OSM tile host, never jsdelivr.
    function initMaps() {
        if (typeof L === 'undefined') { return; }
        var els = document.querySelectorAll('.portal-location-map[data-lat]');
        els.forEach(function (el) {
            if (el.dataset.portalMapInit === '1') { return; }
            el.dataset.portalMapInit = '1';

            var lat = parseFloat(el.dataset.lat);
            var lng = parseFloat(el.dataset.lng);
            if (isNaN(lat) || isNaN(lng)) { return; }

            var map = L.map(el, { scrollWheelZoom: false }).setView([lat, lng], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '<?php echo $attribution; ?>'
            }).addTo(map);

            if (el.dataset.approx === '1') {
                L.circle([lat, lng], { radius: 150, color: '#0d6efd', fillOpacity: 0.15 }).addTo(map);
            } else {
                var icon = L.divIcon({
                    html: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="32" height="32" fill="#dc3545">'
                        + '<path d="M12 0C7.6 0 4 3.6 4 8c0 5.4 6.8 14.7 7.1 15.1.2.3.6.3.8 0C12.2 22.7 20 13.4 20 8c0-4.4-3.6-8-8-8z"/></svg>',
                    className: 'portal-location-marker',
                    iconSize: [32, 32],
                    iconAnchor: [16, 32],
                    popupAnchor: [0, -30]
                });
                var marker = L.marker([lat, lng], { icon: icon }).addTo(map);
                if (el.dataset.popup) {
                    marker.bindPopup(el.dataset.popup).openPopup();
                }
            }
        });
    }

    // 📍 "Look up coordinates" button — POSTs to /geo/lookup, fills the
    //    lat/lng inputs named by the button's own data-*-target attrs.
    function bindLookupButtons() {
        document.querySelectorAll('.portal-geo-lookup[data-geo-lookup]').forEach(function (btn) {
            if (btn.dataset.portalLookupBound === '1') { return; }
            btn.dataset.portalLookupBound = '1';
            btn.addEventListener('click', function () {
                var form = btn.closest('form');
                if (!form) { return; }
                var statusEl = btn.parentElement ? btn.parentElement.querySelector('.portal-geo-lookup-status') : null;
                var get = function (attr) {
                    var fieldName = btn.dataset[attr];
                    if (!fieldName) { return ''; }
                    var input = form.querySelector('[name="' + fieldName + '"]');
                    return input ? input.value : '';
                };
                var body = new URLSearchParams();
                body.set('csrf_token', csrfToken());
                var w3wVal = get('w3wField');
                var addressVal;
                if (btn.dataset.addressFreetext) {
                    addressVal = get('addressFreetext');
                } else {
                    addressVal = [get('addressLine1'), get('addressLine2'), get('addressCity'), get('addressRegion'), get('addressPostcode')]
                        .filter(function (v) { return v !== ''; }).join(', ');
                }
                if (w3wVal) { body.set('w3w', w3wVal); }
                if (addressVal) { body.set('address', addressVal); }
                var countryVal = get('addressCountry');
                if (countryVal) { body.set('countryCode', countryVal); }

                btn.disabled = true;
                if (statusEl) { statusEl.textContent = '…'; }

                fetch('/geo/lookup', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                    body: body.toString()
                }).then(function (r) { return r.json(); }).then(function (data) {
                    btn.disabled = false;
                    if (data && data.ok === true) {
                        var latInput = form.querySelector('[name="' + btn.dataset.latTarget + '"]');
                        var lngInput = form.querySelector('[name="' + btn.dataset.lngTarget + '"]');
                        if (latInput) { latInput.value = data.lat; }
                        if (lngInput) { lngInput.value = data.lng; }
                        if (statusEl) { statusEl.textContent = data.source ? '✓ ' + data.source : '✓'; }
                    } else if (statusEl) {
                        statusEl.textContent = <?php echo json_encode(t('location.lookup_failed'), JSON_UNESCAPED_SLASHES); ?>;
                    }
                }).catch(function () {
                    btn.disabled = false;
                    if (statusEl) { statusEl.textContent = <?php echo json_encode(t('location.lookup_failed'), JSON_UNESCAPED_SLASHES); ?>; }
                });
            });
        });
    }

    // 🔤 W3W autosuggest — debounced 400ms fetch to /geo/w3w-suggest,
    //    populates the input's sibling <datalist>. Degrades silently
    //    (plain text input) when the API isn't configured — the endpoint
    //    replies {ok:false,reason:'disabled'} and this just does nothing.
    function bindW3wSuggest() {
        document.querySelectorAll('input[data-w3w-suggest]').forEach(function (input) {
            if (input.dataset.portalSuggestBound === '1') { return; }
            input.dataset.portalSuggestBound = '1';
            var timer = null;
            input.addEventListener('input', function () {
                clearTimeout(timer);
                var q = input.value;
                if (q.length < 3) { return; }
                timer = setTimeout(function () {
                    var body = new URLSearchParams();
                    body.set('csrf_token', csrfToken());
                    body.set('q', q);
                    fetch('/geo/w3w-suggest', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                        body: body.toString()
                    }).then(function (r) { return r.json(); }).then(function (data) {
                        var list = input.getAttribute('list');
                        var datalist = list ? document.getElementById(list) : null;
                        if (!datalist || !data || data.ok !== true) { return; }
                        datalist.innerHTML = '';
                        (data.suggestions || []).forEach(function (s) {
                            var opt = document.createElement('option');
                            opt.value = s.words;
                            opt.label = s.nearestPlace + (s.country ? ', ' + s.country : '');
                            datalist.appendChild(opt);
                        });
                    }).catch(function () { /* best-effort — silent */ });
                }, 400);
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { initMaps(); bindLookupButtons(); bindW3wSuggest(); });
    } else {
        initMaps(); bindLookupButtons(); bindW3wSuggest();
    }
})();
</script>
        <?php
    }
}
