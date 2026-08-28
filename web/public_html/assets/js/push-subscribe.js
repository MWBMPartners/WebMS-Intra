/**
 * Web Push — subscribe/unsubscribe UI (#322)
 *
 * Single vanilla JS file. No build step. Boots automatically on
 * DOMContentLoaded and renders into every `[data-push-optin]` container on
 * the page (used on /account/notifications and /live). Each container
 * carries the server-rendered PUBLIC VAPID key only:
 *
 *   <div data-push-optin
 *        data-vapid-key="<?php echo htmlspecialchars(WebPush::publicKey(), ENT_QUOTES, 'UTF-8'); ?>"
 *        data-channels="livestream,reminders"></div>
 *
 * An empty `data-vapid-key` means the install is unconfigured
 * (WebPush::isConfigured() === false) — the container is left showing a
 * quiet "not available" note and no subscribe button is rendered at all.
 *
 * Responsibilities:
 *   • Feature-detect serviceWorker + PushManager + Notification.
 *   • Request Notification permission ONLY on an explicit button click
 *     (never on page load) — browser UX policy AND basic decency.
 *   • `registration.pushManager.subscribe({ userVisibleOnly: true,
 *     applicationServerKey })` using the container's public key.
 *   • POST the resulting PushSubscription + chosen channels to
 *     /api/push/subscribe with the X-CSRF-Token header (from the
 *     `csrf-token` <meta> header.php already emits on every page).
 *   • Unsubscribe = subscription.unsubscribe() then POST the endpoint to
 *     /api/push/unsubscribe.
 *
 * @see https://github.com/MWBMPartners/WebMS-Intra/issues/322
 */
(function () {
    'use strict';

    // 🔄 In-memory cache of the current CSRF token (#322 F5). Auth::verifyCsrf()
    // rotates the session token on EVERY successful verify, so the <meta>
    // tag's value goes stale after the first POST in a page load — an
    // immediate enable-then-disable click would otherwise submit the old
    // token and get a 400. subscribe.php/unsubscribe.php return the freshly
    // rotated token as `csrf_token` in their JSON response; updateCsrfToken()
    // below keeps this in sync so the NEXT POST always uses the live token.
    var cachedCsrfToken = null;

    function csrfToken() {
        if (cachedCsrfToken === null) {
            var meta = document.querySelector('meta[name="csrf-token"]');
            cachedCsrfToken = meta ? meta.getAttribute('content') : '';
        }
        return cachedCsrfToken;
    }

    function updateCsrfToken(token) {
        if (typeof token === 'string' && token !== '') {
            cachedCsrfToken = token;
        }
    }

    /** Standard applicationServerKey conversion (base64url -> Uint8Array). */
    function urlBase64ToUint8Array(base64url) {
        var padding = '='.repeat((4 - (base64url.length % 4)) % 4);
        var base64 = (base64url + padding).replace(/-/g, '+').replace(/_/g, '/');
        var raw = window.atob(base64);
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) {
            out[i] = raw.charCodeAt(i);
        }
        return out;
    }

    function postJson(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
            credentials: 'same-origin',
            body: JSON.stringify(body)
        }).then(function (r) { return r.json().catch(function () { return {}; }); })
            .then(function (json) {
                // 🔄 Pick up the rotated token (see updateCsrfToken() comment
                // above) so a second POST later in this page load succeeds.
                if (json && typeof json.csrf_token === 'string') {
                    updateCsrfToken(json.csrf_token);
                }
                return json;
            });
    }

    function renderState(container, state, message) {
        var statusEl = container.querySelector('.push-optin-status');
        var btn = container.querySelector('.push-optin-btn');
        if (!statusEl || !btn) { return; }
        statusEl.textContent = message || '';
        if (state === 'subscribed') {
            btn.textContent = 'Disable notifications on this device';
            btn.dataset.pushAction = 'unsubscribe';
            btn.disabled = false;
        } else if (state === 'unsubscribed') {
            btn.textContent = 'Enable notifications on this device';
            btn.dataset.pushAction = 'subscribe';
            btn.disabled = false;
        } else if (state === 'unsupported') {
            btn.style.display = 'none';
        } else if (state === 'denied') {
            btn.textContent = 'Blocked in browser settings';
            btn.disabled = true;
        } else {
            btn.disabled = true;
        }
    }

    function initContainer(container) {
        var vapidKey = container.getAttribute('data-vapid-key') || '';
        var channelsAttr = container.getAttribute('data-channels') || 'livestream,reminders';
        var channels = channelsAttr.split(',').map(function (c) { return c.trim(); }).filter(Boolean);

        container.innerHTML =
            '<button type="button" class="btn btn-outline-primary btn-sm push-optin-btn" disabled>Checking…</button>'
            + '<div class="small text-muted mt-1 push-optin-status"></div>';
        var btn = container.querySelector('.push-optin-btn');

        if (vapidKey === '') {
            renderState(container, 'unsupported', 'Push notifications are not configured on this site yet.');
            return;
        }
        if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
            renderState(container, 'unsupported', 'Push notifications are not supported in this browser.');
            return;
        }
        if (Notification.permission === 'denied') {
            renderState(container, 'denied', 'You previously blocked notifications for this site — re-enable them in your browser\'s site settings.');
            return;
        }

        navigator.serviceWorker.ready.then(function (registration) {
            return registration.pushManager.getSubscription().then(function (sub) {
                renderState(container, sub ? 'subscribed' : 'unsubscribed', '');

                btn.addEventListener('click', function () {
                    btn.disabled = true;
                    var action = btn.dataset.pushAction;

                    if (action === 'subscribe') {
                        Notification.requestPermission().then(function (permission) {
                            if (permission !== 'granted') {
                                renderState(container, 'denied', 'Permission was not granted.');
                                return;
                            }
                            registration.pushManager.subscribe({
                                userVisibleOnly: true,
                                applicationServerKey: urlBase64ToUint8Array(vapidKey)
                            }).then(function (newSub) {
                                var json = newSub.toJSON();
                                return postJson('/api/push/subscribe', {
                                    endpoint: json.endpoint,
                                    keys: json.keys,
                                    channels: channels
                                }).then(function (resp) {
                                    if (resp && resp.ok === true) {
                                        renderState(container, 'subscribed', 'Notifications enabled on this device.');
                                    } else {
                                        renderState(container, 'unsubscribed', (resp && resp.error) || 'Could not save the subscription.');
                                    }
                                });
                            }).catch(function () {
                                renderState(container, 'unsubscribed', 'Could not enable notifications on this device.');
                            });
                        });
                    } else {
                        registration.pushManager.getSubscription().then(function (existing) {
                            if (!existing) {
                                renderState(container, 'unsubscribed', '');
                                return;
                            }
                            var endpoint = existing.endpoint;
                            existing.unsubscribe().finally(function () {
                                postJson('/api/push/unsubscribe', { endpoint: endpoint }).finally(function () {
                                    renderState(container, 'unsubscribed', 'Notifications disabled on this device.');
                                });
                            });
                        });
                    }
                });
            });
        }).catch(function () {
            renderState(container, 'unsupported', 'Could not reach the service worker.');
        });
    }

    function boot() {
        var containers = document.querySelectorAll('[data-push-optin]');
        for (var i = 0; i < containers.length; i++) {
            initContainer(containers[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
