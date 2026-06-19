/**
 * Portal embeddable widget loader (#336)
 *
 * Drop-in script for embedding a portal event widget on any page:
 *
 *   <div data-portal-widget data-slug="vbs-2026"></div>
 *   <div data-portal-widget data-upcoming="5"></div>
 *   <script src="https://YOURPORTAL.example/assets/js/widget.js" async></script>
 *
 * Each matching element is replaced with an iframe pointing at
 * /widget on the same origin as the script tag.
 */
(function () {
    'use strict';

    function originFromScript() {
        var scripts = document.getElementsByTagName('script');
        for (var i = scripts.length - 1; i >= 0; i--) {
            var src = scripts[i].src || '';
            if (src.indexOf('/assets/js/widget.js') !== -1) {
                var url = new URL(src);
                return url.protocol + '//' + url.host;
            }
        }
        return location.protocol + '//' + location.host;
    }

    function render() {
        var origin = originFromScript();
        var els = document.querySelectorAll('[data-portal-widget]');
        for (var i = 0; i < els.length; i++) {
            var el = els[i];
            if (el.dataset.portalRendered === '1') { continue; }
            el.dataset.portalRendered = '1';

            var qs = [];
            if (el.dataset.slug) { qs.push('slug=' + encodeURIComponent(el.dataset.slug)); }
            if (el.dataset.upcoming) { qs.push('upcoming=' + encodeURIComponent(el.dataset.upcoming)); }
            if (qs.length === 0) { qs.push('upcoming=5'); }

            var iframe = document.createElement('iframe');
            iframe.src = origin + '/widget?' + qs.join('&');
            iframe.style.width = '100%';
            iframe.style.minHeight = (el.dataset.height || '300') + 'px';
            iframe.style.border = '0';
            iframe.loading = 'lazy';
            iframe.title = 'Events';
            el.appendChild(iframe);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', render);
    } else {
        render();
    }
})();
