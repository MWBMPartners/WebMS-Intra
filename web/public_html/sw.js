/**
 * =============================================================================
 * Portal Service Worker — PWA Offline Support
 * =============================================================================
 * Caching strategy:
 *   - Static assets (CSS, JS, images, fonts): Cache-first with network fallback
 *   - HTML pages / API calls: Network-first with cache fallback
 *   - Offline fallback page shown when network unavailable and no cache match
 *
 * Cache is versioned via CACHE_VERSION — bump to invalidate old caches on deploy.
 *
 * @package   Portal
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @version   0.7.0
 * =============================================================================
 */

var CACHE_VERSION = 'portal-v1';
var OFFLINE_PAGE  = '/offline';

// 📋 Static assets to pre-cache on install
var PRECACHE_ASSETS = [
    '/',
    '/offline',
    '/assets/css/portal.css',
    '/assets/js/portal.js',
    '/assets/images/logo.svg',
    '/assets/images/avatar-placeholder.svg',
    '/manifest.json'
];

// =========================================================================
// 📦 Install — pre-cache core assets
// =========================================================================
self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(CACHE_VERSION).then(function (cache) {
            return cache.addAll(PRECACHE_ASSETS).catch(function (err) {
                // 📋 Non-fatal: some assets might not be available yet
                console.warn('[SW] Pre-cache partial failure:', err);
            });
        })
    );
    // 📋 Activate immediately without waiting for existing tabs to close
    self.skipWaiting();
});

// =========================================================================
// 🧹 Activate — clean up old cache versions
// =========================================================================
self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (cacheNames) {
            return Promise.all(
                cacheNames.filter(function (name) {
                    return name !== CACHE_VERSION;
                }).map(function (name) {
                    return caches.delete(name);
                })
            );
        })
    );
    // 📋 Claim all open tabs immediately
    self.clients.claim();
});

// =========================================================================
// 🌐 Fetch — routing strategy
// =========================================================================
self.addEventListener('fetch', function (event) {
    var request = event.request;

    // 📋 Only handle GET requests (skip POST, PUT, etc.)
    if (request.method !== 'GET') {
        return;
    }

    var url = new URL(request.url);

    // 📋 Skip cross-origin requests (CDN resources handle their own caching)
    if (url.origin !== self.location.origin) {
        return;
    }

    // 📋 Skip API routes (should never be served from cache)
    if (url.pathname.indexOf('/api/') === 0) {
        return;
    }

    // 📋 Determine strategy based on resource type
    if (isStaticAsset(url.pathname)) {
        // 🎨 Static assets: Cache-first, network fallback
        event.respondWith(cacheFirst(request));
    } else {
        // 📄 HTML pages: Network-first, cache fallback, then offline page
        event.respondWith(networkFirst(request));
    }
});

/**
 * Check if a pathname is a static asset (CSS, JS, image, font).
 *
 * @param {string} pathname
 * @returns {boolean}
 */
function isStaticAsset(pathname) {
    return /\.(css|js|png|jpg|jpeg|gif|svg|ico|woff2?|ttf|eot|webp)$/i.test(pathname)
        || pathname.indexOf('/assets/') === 0;
}

/**
 * Cache-first strategy: serve from cache if available, else fetch and cache.
 *
 * @param {Request} request
 * @returns {Promise<Response>}
 */
function cacheFirst(request) {
    return caches.match(request).then(function (cached) {
        if (cached) {
            // 📋 Update cache in background for freshness (stale-while-revalidate)
            fetch(request).then(function (response) {
                if (response && response.ok) {
                    caches.open(CACHE_VERSION).then(function (cache) {
                        cache.put(request, response);
                    });
                }
            }).catch(function () {
                // 📋 Network unavailable — cached version is fine
            });
            return cached;
        }

        return fetch(request).then(function (response) {
            if (response && response.ok) {
                var clone = response.clone();
                caches.open(CACHE_VERSION).then(function (cache) {
                    cache.put(request, clone);
                });
            }
            return response;
        });
    });
}

/**
 * Network-first strategy: try network, fall back to cache, then offline page.
 *
 * @param {Request} request
 * @returns {Promise<Response>}
 */
function networkFirst(request) {
    return fetch(request).then(function (response) {
        // 📋 Cache successful HTML responses for offline fallback
        if (response && response.ok && response.headers.get('content-type')
            && response.headers.get('content-type').indexOf('text/html') !== -1
        ) {
            var clone = response.clone();
            caches.open(CACHE_VERSION).then(function (cache) {
                cache.put(request, clone);
            });
        }
        return response;
    }).catch(function () {
        // 📋 Network failed — try cache
        return caches.match(request).then(function (cached) {
            if (cached) {
                return cached;
            }
            // 📋 No cache match — show offline page
            return caches.match(OFFLINE_PAGE).then(function (offlinePage) {
                if (offlinePage) {
                    return offlinePage;
                }
                // 📋 Last resort: minimal offline response
                return new Response(
                    '<!doctype html><html><body style="font-family:system-ui;text-align:center;padding:4rem;">'
                    + '<h1>Offline</h1><p>You appear to be offline. Please check your connection.</p></body></html>',
                    { headers: { 'Content-Type': 'text/html' } }
                );
            });
        });
    });
}

// =========================================================================
// 📤 Background Sync — drain Portal.OfflineQueue when connectivity returns (#233)
// =========================================================================
// Pages register the 'portal-offline-sync' tag via:
//   navigator.serviceWorker.ready.then(r => r.sync.register('portal-offline-sync'))
// The browser dispatches `sync` events to this worker when network returns.
// The worker posts a message back to all client pages asking them to drain
// the IndexedDB queue (clients hold the DB connection; SW can but ours
// chooses to delegate to the page so the queue UI updates).
self.addEventListener('sync', function (event) {
    if (event.tag === 'portal-offline-sync') {
        event.waitUntil((async function () {
            var clients = await self.clients.matchAll({ includeUncontrolled: true });
            clients.forEach(function (client) {
                client.postMessage({ type: 'portal-drain-queue' });
            });
        }()));
    }
});

self.addEventListener('message', function (event) {
    if (event.data && event.data.type === 'portal-skip-waiting') {
        self.skipWaiting();
    }
});
