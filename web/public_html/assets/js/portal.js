/**
 * =============================================================================
 * Portal Shared JavaScript - portal.js
 * =============================================================================
 * Shared client-side utilities for the WebMS Intra portal. Loaded on every
 * page via the footer template with the `defer` attribute.
 *
 * Features:
 *   - Dark mode toggle with localStorage persistence
 *   - AJAX helper for JSON API requests with CSRF token
 *   - File upload dropzone interactivity
 *   - Toast notification display
 *
 * @package   Portal
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @version   0.1.0
 * =============================================================================
 */

/* ============================================================================
   1. Dark Mode Toggle
   ============================================================================ */

(function () {
    'use strict';

    /**
     * Initialise the theme toggle button(s).
     *
     * Three-state cycle: light → dark → auto → light. The actual data-bs-theme
     * value applied to <html> is always 'light' or 'dark' — when the saved
     * preference is 'auto' the script resolves via prefers-color-scheme and
     * listens for system theme changes.
     *
     * The FOUC script in header.php applies the initial value before this
     * runs; this function attaches click handlers and updates the icon state.
     */
    function initThemeToggle() {
        var html = document.documentElement;
        var mediaQuery = window.matchMedia
            ? window.matchMedia('(prefers-color-scheme: dark)')
            : null;

        function savedPreference() {
            var v = localStorage.getItem('portal-theme');
            return (v === 'light' || v === 'dark' || v === 'auto') ? v : 'auto';
        }
        function applyAuto() {
            var prefersDark = mediaQuery && mediaQuery.matches;
            html.setAttribute('data-bs-theme', prefersDark ? 'dark' : 'light');
        }

        // When in 'auto' mode, keep the resolved theme in sync with system pref.
        if (mediaQuery && typeof mediaQuery.addEventListener === 'function') {
            mediaQuery.addEventListener('change', function () {
                if (savedPreference() === 'auto') {
                    applyAuto();
                    updateToggleIcons(savedPreference());
                }
            });
        }

        var toggleButtons = document.querySelectorAll('.portal-theme-toggle');
        for (var i = 0; i < toggleButtons.length; i++) {
            toggleButtons[i].addEventListener('click', function () {
                var current = savedPreference();
                var next = (current === 'light')
                    ? 'dark'
                    : (current === 'dark' ? 'auto' : 'light');

                localStorage.setItem('portal-theme', next);
                if (next === 'auto') {
                    applyAuto();
                } else {
                    html.setAttribute('data-bs-theme', next);
                }
                updateToggleIcons(next);
            });
        }

        // Set initial icon state to reflect the saved preference (not resolved)
        updateToggleIcons(savedPreference());
    }

    /**
     * Initialise the colour-blind-safe palette toggle button(s).
     * Two states (on / off). Persists in localStorage as 'portal-cb'.
     * The FOUC script in header.php applies the attribute before paint.
     */
    function initCbToggle() {
        var html = document.documentElement;
        function savedCb() {
            return localStorage.getItem('portal-cb') === 'on';
        }
        function apply(on) {
            if (on) {
                html.setAttribute('data-portal-cb', 'on');
            } else {
                html.removeAttribute('data-portal-cb');
            }
        }

        var buttons = document.querySelectorAll('.portal-cb-toggle');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].addEventListener('click', function () {
                var next = !savedCb();
                if (next) {
                    localStorage.setItem('portal-cb', 'on');
                } else {
                    localStorage.removeItem('portal-cb');
                }
                apply(next);
                updateCbIcons(next);
            });
        }
        updateCbIcons(savedCb());
    }

    /**
     * Update theme toggle icons + aria labels to reflect the saved preference.
     * Saved preference is one of: 'light', 'dark', 'auto'.
     *
     * @param {string} preference - Saved theme preference (not the resolved value)
     */
    function updateToggleIcons(preference) {
        var toggleButtons = document.querySelectorAll('.portal-theme-toggle');
        for (var i = 0; i < toggleButtons.length; i++) {
            var icon = toggleButtons[i].querySelector('i');
            if (icon) {
                if (preference === 'dark') {
                    icon.className = 'fa-solid fa-moon';
                    toggleButtons[i].setAttribute('title', 'Theme: dark — click for auto');
                } else if (preference === 'auto') {
                    icon.className = 'fa-solid fa-circle-half-stroke';
                    toggleButtons[i].setAttribute('title', 'Theme: auto (system) — click for light');
                } else {
                    icon.className = 'fa-solid fa-sun';
                    toggleButtons[i].setAttribute('title', 'Theme: light — click for dark');
                }
            }
        }
    }

    /**
     * Update CB toggle icons + aria labels to reflect the current state.
     *
     * @param {boolean} on - Whether CB-safe mode is enabled
     */
    function updateCbIcons(on) {
        var buttons = document.querySelectorAll('.portal-cb-toggle');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].setAttribute('aria-pressed', on ? 'true' : 'false');
            buttons[i].setAttribute(
                'title',
                on
                    ? 'Colour-blind safe palette: on — click to turn off'
                    : 'Colour-blind safe palette: off — click to turn on'
            );
        }
    }

    /* ========================================================================
       2. AJAX Helper
       ======================================================================== */

    /**
     * Send a JSON API request with CSRF token support.
     *
     * @param {string}   url      - The API endpoint URL
     * @param {Object}   options  - Request options:
     *   @param {string}  [options.method='GET']  - HTTP method
     *   @param {Object}  [options.body]           - Request body (will be JSON-encoded)
     *   @param {string}  [options.csrfToken]      - CSRF token (auto-read from meta tag if not provided)
     * @param {Function} onSuccess - Callback with parsed JSON response
     * @param {Function} [onError] - Error callback with error message string
     */
    function portalFetch(url, options, onSuccess, onError) {
        options = options || {};
        var method = options.method || 'GET';
        var body = options.body || null;

        var xhr = new XMLHttpRequest();
        xhr.open(method, url, true);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        // Add CSRF token header for state-changing requests
        if (method !== 'GET' && method !== 'HEAD') {
            var csrfToken = options.csrfToken || getMetaCsrf();
            if (csrfToken) {
                xhr.setRequestHeader('X-CSRF-Token', csrfToken);
            }

            if (body !== null) {
                xhr.setRequestHeader('Content-Type', 'application/json');
            }
        }

        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) {
                return;
            }

            var response;
            try {
                response = JSON.parse(xhr.responseText);
            } catch (e) {
                if (onError) {
                    onError('Invalid JSON response');
                }
                return;
            }

            if (xhr.status >= 200 && xhr.status < 300) {
                if (onSuccess) {
                    onSuccess(response);
                }
            } else {
                if (onError) {
                    onError(response.message || ('HTTP ' + xhr.status));
                }
            }
        };

        if (body !== null && typeof body === 'object') {
            xhr.send(JSON.stringify(body));
        } else {
            xhr.send();
        }
    }

    /**
     * Read the CSRF token from the page's meta tag.
     *
     * @returns {string|null} The CSRF token, or null if not found
     */
    function getMetaCsrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) {
            return meta.getAttribute('content');
        }
        return null;
    }

    /* ========================================================================
       3. File Dropzone
       ======================================================================== */

    /**
     * Initialise all file dropzones on the page.
     * Adds drag-and-drop visual feedback (the .dragover class).
     */
    function initDropzones() {
        var dropzones = document.querySelectorAll('.portal-dropzone');

        for (var i = 0; i < dropzones.length; i++) {
            (function (zone) {
                zone.addEventListener('dragenter', function (e) {
                    e.preventDefault();
                    zone.classList.add('dragover');
                });

                zone.addEventListener('dragover', function (e) {
                    e.preventDefault();
                    zone.classList.add('dragover');
                });

                zone.addEventListener('dragleave', function () {
                    zone.classList.remove('dragover');
                });

                zone.addEventListener('drop', function () {
                    zone.classList.remove('dragover');
                });
            })(dropzones[i]);
        }
    }

    /* ========================================================================
       4. Toast Notifications
       ======================================================================== */

    /**
     * Display a Bootstrap toast notification.
     *
     * @param {string} message - The message to display
     * @param {string} [type='info'] - Type: 'success', 'danger', 'warning', 'info'
     * @param {number} [duration=4000] - Auto-hide delay in milliseconds
     */
    function showToast(message, type, duration) {
        type = type || 'info';
        duration = duration || 4000;

        // Create the toast container if it doesn't exist
        var container = document.getElementById('portal-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'portal-toast-container';
            container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
            container.style.zIndex = '9999';
            document.body.appendChild(container);
        }

        // Build the toast element
        var toast = document.createElement('div');
        toast.className = 'toast align-items-center text-bg-' + type + ' border-0';
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'assertive');
        toast.setAttribute('aria-atomic', 'true');

        toast.innerHTML =
            '<div class="d-flex">' +
                '<div class="toast-body">' + escapeHtml(message) + '</div>' +
                '<button type="button" class="btn-close btn-close-white me-2 m-auto" ' +
                    'data-bs-dismiss="toast" aria-label="Close"></button>' +
            '</div>';

        container.appendChild(toast);

        // Show via Bootstrap Toast API (if available) or simple timeout
        if (typeof bootstrap !== 'undefined' && bootstrap.Toast) {
            var bsToast = new bootstrap.Toast(toast, { delay: duration });
            bsToast.show();

            toast.addEventListener('hidden.bs.toast', function () {
                toast.remove();
            });
        } else {
            toast.style.display = 'block';
            toast.style.opacity = '1';
            setTimeout(function () {
                toast.remove();
            }, duration);
        }
    }

    /**
     * Escape HTML entities to prevent XSS in dynamic content.
     *
     * @param {string} text - Raw text to escape
     * @returns {string} HTML-safe string
     */
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    /* ========================================================================
       5. Initialisation
       ======================================================================== */

    // Run on DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initThemeToggle();
            initCbToggle();
            initDropzones();
        });
    } else {
        initThemeToggle();
        initCbToggle();
        initDropzones();
    }

    // Expose public API on window.Portal
    window.Portal = window.Portal || {};
    window.Portal.fetch = portalFetch;
    window.Portal.toast = showToast;
})();
