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
    };

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

        var tour = state.tour;
        state.tour = null;
        state.index = 0;
        if (tour === null) { return; }

        // Persist completion (best-effort).
        var fd = new FormData();
        fd.append('csrf_token', csrfToken());
        fd.append('tourID', String(tour.tourID));
        fetch('/api/tours/complete', { method: 'POST', body: fd, credentials: 'same-origin' })
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
        fetch('/api/tours/active', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
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
