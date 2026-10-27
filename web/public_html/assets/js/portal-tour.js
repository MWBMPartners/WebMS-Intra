/* =============================================================================
 * WebMS Intra — Portal.Tour 🎯
 * =============================================================================
 * "What's new" tour playback. Completes the engine scaffolded in #237.
 *
 * On every authenticated page load (where the body carries the tour mount
 * marker) it asks /api/tours/active for the highest-priority tour the user
 * hasn't completed. If one comes back, it renders a centred modal stepper:
 * title, body, step indicator, Skip / Back / Next / Done. On finish OR skip
 * it POSTs /api/tours/complete so the tour doesn't reappear.
 *
 * Each step may carry a `selector` — if the element exists, the page scrolls
 * to it and a pulsing highlight ring is drawn around it.
 *
 * Mobile: swipe left/right advances/retreats. Desktop: arrow keys.
 *
 * No framework — vanilla JS + Bootstrap modal markup (Bootstrap JS optional;
 * falls back to a manually-toggled overlay if bootstrap.Modal is absent).
 *
 * The two addresses it asks are built by Portal.url() (portal.js), so that
 * where organisations are told apart by the first part of the address the
 * tour is fetched from, and marked as seen in, the organisation being
 * looked at (#536). They were written bare, which asked the default
 * organisation. Portal.url() is there by the time this file runs because
 * Asset::portalJs() loads portal.js first, and both are deferred, which
 * keeps them in that order. Do not load this file without portal.js.
 *
 * @see   https://github.com/MWBMPartners/WebMS-Intra/issues/253
 * ============================================================================= */
(function () {
    'use strict';

    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }
    window.Portal = window.Portal || {};

    // CSRF token is exposed on a <meta name="csrf-token"> tag by header.php.
    function csrfToken() {
        var el = document.querySelector('meta[name="csrf-token"]');
        return el !== null ? el.getAttribute('content') || '' : '';
    }

    var state = {
        tour: null,
        index: 0,
        overlay: null,
        highlightEl: null,
        previouslyFocused: null,
    };

    // ♿ Focusable elements inside the overlay, for the Tab trap below.
    function getFocusable() {
        if (state.overlay === null) {
            return [];
        }
        var nodes = state.overlay.querySelectorAll(
            'a[href], button:not([disabled]), textarea:not([disabled]), ' +
            'input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
        );
        return Array.prototype.slice.call(nodes);
    }

    // ♿ Cycle Tab/Shift+Tab within the overlay's own focusables so focus
    // never escapes to the page behind it while the tour is open.
    function trapFocus(e) {
        var focusable = getFocusable();
        if (focusable.length === 0) {
            return;
        }
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        var current = document.activeElement;
        if (e.shiftKey === true) {
            if (current === first || state.overlay.contains(current) === false) {
                e.preventDefault();
                last.focus();
            }
        } else {
            if (current === last || state.overlay.contains(current) === false) {
                e.preventDefault();
                first.focus();
            }
        }
    }

    function clearHighlight() {
        if (state.highlightEl !== null) {
            state.highlightEl.classList.remove('portal-tour-highlight');
            state.highlightEl = null;
        }
    }

    function highlightStep(step) {
        clearHighlight();
        if (typeof step.selector !== 'string' || step.selector === '') {
            return;
        }
        var target = null;
        try {
            target = document.querySelector(step.selector);
        } catch (e) {
            target = null;
        }
        if (target === null) {
            return;
        }
        target.classList.add('portal-tour-highlight');
        state.highlightEl = target;
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function render() {
        if (state.tour === null) {
            return;
        }
        var steps = state.tour.steps;
        var step = steps[state.index];
        var total = steps.length;

        if (state.overlay === null) {
            buildOverlay();
        }

        var titleEl = state.overlay.querySelector('[data-tour-title]');
        var bodyEl = state.overlay.querySelector('[data-tour-body]');
        var indEl = state.overlay.querySelector('[data-tour-indicator]');
        var backBtn = state.overlay.querySelector('[data-tour-back]');
        var nextBtn = state.overlay.querySelector('[data-tour-next]');

        titleEl.textContent = step.title || state.tour.title || '';
        bodyEl.textContent = step.body || '';
        indEl.textContent = (state.index + 1) + ' of ' + total;
        backBtn.style.visibility = state.index === 0 ? 'hidden' : 'visible';
        nextBtn.textContent = state.index === total - 1 ? 'Done' : 'Next';

        highlightStep(step);
    }

    function buildOverlay() {
        var o = document.createElement('div');
        o.className = 'portal-tour-overlay';
        o.setAttribute('role', 'dialog');
        o.setAttribute('aria-modal', 'true');
        o.setAttribute('aria-label', 'Feature tour');
        o.innerHTML =
            '<div class="portal-tour-card" role="document">' +
                '<button type="button" class="portal-tour-skip" data-tour-skip aria-label="Skip tour">&times;</button>' +
                '<h2 class="portal-tour-card-title" data-tour-title></h2>' +
                '<p class="portal-tour-card-body" data-tour-body></p>' +
                '<div class="portal-tour-footer">' +
                    '<span class="portal-tour-indicator" data-tour-indicator></span>' +
                    '<div class="portal-tour-actions">' +
                        '<button type="button" class="btn btn-sm btn-outline-secondary" data-tour-back>Back</button>' +
                        '<button type="button" class="btn btn-sm btn-primary" data-tour-next>Next</button>' +
                    '</div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(o);
        state.overlay = o;

        // ♿ Focus management (#253 follow-up) — this overlay is a hand-rolled
        // dialog (no bootstrap.Modal instance to do it for us, unlike
        // portal-confirm.js), so we replicate its focus handling manually:
        // remember what had focus before the tour opened, then move focus
        // into the dialog so it isn't left stranded on the page behind it.
        state.previouslyFocused = (document.activeElement !== null && typeof document.activeElement.focus === 'function')
            ? document.activeElement
            : null;
        var skipBtn = o.querySelector('[data-tour-skip]');
        if (skipBtn !== null) {
            skipBtn.focus();
        }

        o.querySelector('[data-tour-skip]').addEventListener('click', complete);
        o.querySelector('[data-tour-back]').addEventListener('click', function () {
            if (state.index > 0) { state.index--; render(); }
        });
        o.querySelector('[data-tour-next]').addEventListener('click', function () {
            if (state.index < state.tour.steps.length - 1) {
                state.index++;
                render();
            } else {
                complete();
            }
        });

        // Keyboard: arrows + Esc.
        document.addEventListener('keydown', onKeydown);

        // Touch swipe.
        var touchStartX = null;
        o.addEventListener('touchstart', function (e) {
            touchStartX = e.changedTouches[0].screenX;
        }, { passive: true });
        o.addEventListener('touchend', function (e) {
            if (touchStartX === null) { return; }
            var dx = e.changedTouches[0].screenX - touchStartX;
            if (Math.abs(dx) < 50) { return; }
            if (dx < 0 && state.index < state.tour.steps.length - 1) {
                state.index++; render();
            } else if (dx > 0 && state.index > 0) {
                state.index--; render();
            }
            touchStartX = null;
        }, { passive: true });
    }

    function onKeydown(e) {
        if (state.tour === null) { return; }
        if (e.key === 'Escape') { complete(); }
        else if (e.key === 'Tab') { trapFocus(e); }
        else if (e.key === 'ArrowRight') {
            if (state.index < state.tour.steps.length - 1) { state.index++; render(); }
            else { complete(); }
        } else if (e.key === 'ArrowLeft') {
            if (state.index > 0) { state.index--; render(); }
        }
    }

    function complete() {
        clearHighlight();
        if (state.overlay !== null) {
            state.overlay.remove();
            state.overlay = null;
        }
        document.removeEventListener('keydown', onKeydown);

        // ♿ Restore focus to whatever had it before the tour opened —
        // mirrors portal-confirm.js / Bootstrap Modal's own restore-on-hide
        // behaviour for its hand-rolled equivalent.
        if (state.previouslyFocused !== null) {
            state.previouslyFocused.focus();
            state.previouslyFocused = null;
        }

        var tour = state.tour;
        state.tour = null;
        state.index = 0;
        if (tour === null) { return; }

        // Persist completion (best-effort).
        var fd = new FormData();
        fd.append('csrf_token', csrfToken());
        fd.append('tourID', String(tour.tourID));
        // 🔄 Keep the page's token current from the answer. The handler replaced
        //    it the moment it accepted the old one (#634), and this script reads
        //    the token out of the page's meta tag EVERY time it posts (see
        //    csrfToken() above), so writing the replacement back into that tag
        //    is all it takes. Without this, completing one tour leaves the whole
        //    page holding a spent token and the next background post from any
        //    script on it is refused.
        //    This runs for a refusal as well as a success, because a request
        //    turned away after the check has still spent the token. It stays
        //    best-effort: nothing here is shown to the person, and a tour that
        //    fails to record simply appears again.
        fetch(window.Portal.url('api/tours/complete'), { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return null; }); })
            .then(function (answer) {
                if (window.Portal && typeof window.Portal.rememberCsrf === 'function') {
                    window.Portal.rememberCsrf(answer);
                }
            })
            .catch(function () { /* best-effort */ });
    }

    function startTour(tour) {
        if (tour === null || !tour.steps || tour.steps.length === 0) {
            return;
        }
        state.tour = tour;
        state.index = 0;
        render();
    }

    // Public API for the "replay" button on /account.
    window.Portal.Tour = {
        start: startTour,
        fetchAndStart: fetchActive,
    };

    function fetchActive() {
        fetch(window.Portal.url('api/tours/active'), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (json) {
                // ApiResponse wraps payload as { success, data }.
                var tour = json && json.data ? json.data : null;
                if (tour !== null) {
                    startTour(tour);
                }
            })
            .catch(function () { /* silent — tours are non-critical */ });
    }

    // Auto-trigger on authenticated pages (body carries the marker set by
    // header.php). Skip on the login screen and public pages.
    function init() {
        if (document.body.getAttribute('data-portal-authenticated') !== '1') {
            return;
        }
        fetchActive();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
