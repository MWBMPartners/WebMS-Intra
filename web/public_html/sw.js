/**
 * =============================================================================
 * Portal Service Worker — PWA Offline Support
 * =============================================================================
 * Path: web/public_html/sw.js
 *
 * Caching strategy:
 *   - Static files (CSS, JS, images, fonts): Cache-first with network fallback
 *   - HTML pages: Network-first with cache fallback
 *   - Offline fallback page shown when network unavailable and no cache match
 *
 * Cache is versioned via CACHE_VERSION — bump to invalidate old caches on deploy.
 *
 * -----------------------------------------------------------------------------
 * 🔒 WHAT THIS WORKER IS ALLOWED TO KEEP (#507)
 * -----------------------------------------------------------------------------
 * WHAT WAS WRONG: networkFirst() kept a copy of EVERY successful HTML page,
 * including pages only a signed-in person may see, and signing out never
 * removed them. On a shared computer the next person could open, say, the
 * expenses queue while the connection was down and be shown the previous
 * person's copy. Two further ways in were found while fixing it:
 *   1. The Asset Tracker's own pages live at addresses such as /assets/my and
 *      /assets/item. isStaticAsset() treats anything under /assets/ as a
 *      static file, so those signed-in pages went down the cache-FIRST path:
 *      after sign-out they were served from storage even while ONLINE,
 *      without the portal ever being asked.
 *   2. '/' was in the install list. The worker installs from the footer of a
 *      signed-in page, so '/' was fetched with the sign-in cookie and the
 *      signed-in dashboard was stored at install time, with no check at all.
 *
 * THE RULE NOW (see mayKeepCopy()):
 *   - A page (text/html) is kept only when the portal marked the response
 *     "X-Offline-Copy: allow". web/_core/Auth.php sends that marker only when
 *     the session that built the page held nothing that identifies a person.
 *     The worker cannot see the sign-in cookie, so it has to rely on something
 *     the server says — and it relies on a positive "yes", so a page that
 *     arrives without the marker (an older server, an entry point that never
 *     starts a session, a future bug) is simply not kept.
 *   - Nothing marked "private" in Cache-Control is ever kept by the fetch
 *     handler. Auth.php adds "private" to responses for signed-in visitors.
 *     The install step is the exception: it stores the install list
 *     (PRECACHE_ASSETS) with cache.add(), which does not look at
 *     Cache-Control. So the copies of the manifest and the offline page it
 *     fetches while signed in are kept even though the portal labels them
 *     "private" (the offline page only on a server that sends it through
 *     PHP). See PRECACHE_ASSETS for why that is safe and what it relies on.
 *   - A static file is kept unless Cache-Control says "no-store" or "private".
 *   - Redirected responses are never kept. A navigation that the browser
 *     follows through a redirect (for example /tasks → /login when signed
 *     out) must not be stored under the first address: browsers refuse to
 *     show a stored redirected response for a page load, so the copy was
 *     useless as well as misfiled.
 *
 * TRIED AND REJECTED: "refuse anything whose Cache-Control contains no-store".
 * That is the obvious rule, but PHP's session start (session.cache_limiter =
 * nocache, the default) already sends "Cache-Control: no-store, no-cache,
 * must-revalidate" on EVERY page the front controller serves, signed in or
 * not — checked on 14 September 2026 against /help, /login and /offline. So
 * that rule would have stopped every public page working offline too. That
 * is why pages need the explicit marker, and why no-store is only used as a
 * refusal for static files (which the web server serves directly, without it).
 *
 * WHAT THIS CANNOT DO:
 *   - It cannot tell a page that is personal for some other reason (a page
 *     reached by a private link, such as an invitation) from a public one,
 *     if the visitor was signed out when it was built. Those are kept.
 *   - It cannot protect copies that an OLDER worker stored. Those are removed
 *     in two ways: the activate handler deletes every old store when this
 *     version takes over, and the sign-out page (Auth::logout()) removes
 *     stored pages itself, which works even while an old worker is running.
 *     An older worker cannot store a NEW signed-in page, even one that
 *     finishes downloading after sign-out: the portal sends those with
 *     "Vary: *", which browsers refuse to put into Cache Storage (see
 *     Auth::oldServiceWorkerWouldStore()). Of the signed-in responses an
 *     older worker would store, the fixed offline page is the one sent
 *     without it, because it holds nothing about the visitor
 *     (Auth::offlinePageAnswered()). Signed-in responses an older worker
 *     would never store (JSON answers, photos, QR codes, the calendar feed)
 *     are also sent without it, on purpose. The fetch handler below does not
 *     need that header. The install step DOES: it is the only thing that
 *     stops the install storing a signed-in page that answers '/offline/'
 *     (see PRECACHE_ASSETS).
 *
 * Also handles Web Push (#322): `push` shows a notification for every
 * message (a push with an unparseable/missing body still shows a generic
 * notification — a `push` event SHOULD always display something when
 * `userVisibleOnly: true` was promised at subscribe time), and
 * `notificationclick` focuses an existing tab or opens a new one at the
 * payload's `url` (defence-in-depth same-origin-relative check even though
 * the payload is server-authored).
 *
 * @package   Portal
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.9.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/507
 * =============================================================================
 */

// 📋 v2 → v3 (#507): changing the name makes the activate handler below
//    delete the whole 'portal-v2' store, which may hold signed-in pages kept
//    before this fix.
//    NEVER reuse the names 'portal-v1' or 'portal-v2' for a future version.
//    The sign-out page's script (Auth::logout()) treats a store carrying
//    either of those names as one a worker from before #507 wrote, and
//    relaxes its own rules for it accordingly (see ALWAYS_KEEP /
//    PRE_507_STORES there). Reusing one of those two names for a CURRENT
//    worker's store would wrongly apply that relaxed, pre-#507 handling to
//    it.
var CACHE_VERSION = 'portal-v3';

// 📋 The offline page's address WITH the trailing slash (#507 follow-up).
//    WHAT WAS WRONG: this used to be '/offline'. On Apache (our own hosting,
//    and the usual shared-hosting server) there is a real folder
//    web/public_html/offline/, so .htaccess lets the web server answer
//    directly, and Apache's folder handling (mod_dir) replies to '/offline'
//    with "301 moved to /offline/". The install step stored that REDIRECTED
//    answer, and browsers refuse to show a redirected answer for a page load,
//    so the offline page never appeared on Apache: every fallback ended in a
//    bare browser error. '/offline/' is answered directly by Apache (200),
//    and on a server that sends everything through the front controller
//    instead, Router strips the trailing slash, so the same page is served.
//    offlinePageResponse() below still repairs a redirected copy, because a
//    customer's server may redirect in some way we have not seen.
//    The sign-out page script in Auth::logout() keeps this address (and the
//    old '/offline') when it clears stored pages; change both together.
var OFFLINE_PAGE  = '/offline/';

// 📋 The response header the portal sends on a page built for a visitor who
//    is not signed in. Must match Auth::OFFLINE_COPY_HEADER in
//    web/_core/Auth.php, and the check in the sign-out page that
//    Auth::logout() sends.
var OFFLINE_COPY_HEADER = 'X-Offline-Copy';

// 📋 Files to store when the worker installs.
//    '/' is deliberately NOT here any more (#507). It used to be. The worker
//    is registered from the footer of signed-in pages, so this list is
//    fetched with the sign-in cookie, and '/' is the dashboard: the signed-in
//    dashboard was being stored with no check at all. The offline page is
//    safe to store this way because web/public_html/offline/index.php is a
//    fixed page that reads nothing about the visitor, and so is
//    /manifest.json (web/public_html/manifest.php reads only the brand and
//    which apps the site has switched on).
//    Every address on this list is fetched with the sign-in cookie, because
//    the worker installs from the footer of a signed-in page. A portal web
//    page built for a signed-in visitor carries `Vary: *`
//    (Auth::oldServiceWorkerWouldStore()), which the browser refuses to
//    store. The offline page is the one exception — it is exempted by
//    Auth::offlinePageAnswered(), which checks which FILE PHP ran, not
//    the address — so do not add another portal page to this list. Once
//    the matching Auth.php is live it would fail to store for a signed-in
//    visitor, and before then it would be stored with no check at all.
//    On a server that sends addresses through the front controller instead
//    of Apache answering the /offline/ folder directly, '/offline/' can be
//    a DIFFERENT page: the signed-in dashboard of an organisation whose
//    site key is "offline". Once the matching Auth.php is live, that copy
//    carries `Vary: *` and is refused (see Auth::offlinePageAnswered()).
//    WHAT THIS CANNOT DO: the install step checks nothing itself, so it
//    relies on that header. If this sw.js installs while an OLDER Auth.php
//    is still live, that dashboard IS stored, with the person's name, and
//    the sign-out page keeps it until CACHE_VERSION next changes. The
//    window is the deploy that FIRST brings this sw.js and its matching
//    Auth.php to a server: later deploys find that Auth.php already live,
//    and it already sends `Vary: *` (unless the server is rolled back to an
//    older Auth.php). When this was written, deploy.yml uploaded
//    public_html/ before _core/, so that first deploy opens the window
//    while _core/ uploads; it stays open if that deploy stops half way, and
//    a customer uploading by hand can open it too. Reproduced 15 September
//    2026 in Edge 153, Firefox 155 and WebKit 26.6. The cause is the site
//    key taking over a fixed portal address, which belongs where site keys
//    are saved (web/_apps/admin/sites/save.php), not here. See "TRIED AND
//    REJECTED" at the install step below.
var PRECACHE_ASSETS = [
    OFFLINE_PAGE,
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
        // 📋 One file at a time, not cache.addAll() (#507 follow-up).
        //    WHAT WAS WRONG: addAll() is all-or-nothing — one response the
        //    browser refuses to store (for example a signed-in visitor's
        //    copy of the offline page on a front-controller server, which
        //    the portal used to mark with `Vary: *` until
        //    Auth::offlinePageAnswered() exempted it) failed the WHOLE call, so
        //    nothing on this list was stored: not the offline page, not
        //    portal.css or portal.js, not the manifest. The .catch below
        //    hid the failure, so the worker still finished installing, and
        //    only later — when actually offline — did anyone find that
        //    nothing had been kept at all.
        //    TRIED AND REJECTED: letting the whole install fail, so the
        //    problem is at least visible somewhere. A failed install leaves
        //    the PREVIOUS worker in charge, which can be a worker from
        //    before #507 whose 'portal-v2' store still holds signed-in
        //    pages — only THIS version's activate handler deletes those. So
        //    the install has to go ahead with whatever COULD be stored,
        //    not fail outright.
        //    TRIED AND REJECTED: Promise.allSettled() wrapped around the
        //    cache.add() calls below. It behaves the same way here, so it
        //    is not needed: the .catch on each individual add() already
        //    stops one file's failure from taking down the rest, and
        //    allSettled() would only add a layer with nothing left for it
        //    to do.
        //    TRIED AND REJECTED: fetching the offline page WITHOUT the sign-in
        //    cookie (new Request(OFFLINE_PAGE, { credentials: 'omit' })), so
        //    that no Auth.php version and no site key could make that copy
        //    personal. Tested 15 September 2026, with a front controller and
        //    an organisation keyed "offline":
        //      - It did stop that organisation's dashboard being stored
        //        during the changeover window, in Edge 153, Firefox 155 and
        //        WebKit 26.6.
        //      - But that organisation's sign-in page (a redirected copy)
        //        became its offline page, and an offline visit to /offline/
        //        failed with a browser error in all three.
        //      - A request without cookies also leaves out a stored HTTP
        //        basic-authentication password. Behind such a password (an
        //        .htpasswd file, which deploy.yml deliberately leaves in
        //        place on the server), Edge 153 got 401 for the offline page
        //        and stored nothing. So every visitor on a password-protected
        //        channel lost the offline page, to fix a case only a badly
        //        chosen site key creates. Firefox and WebKit were not tried
        //        behind basic authentication.
        //    WHAT IT CANNOT DO: a file that could not be stored is reported
        //    only to this console. Nothing is sent to the server, so this
        //    gives no visibility into how often it happens in practice.
        caches.open(CACHE_VERSION).then(function (cache) {
            return Promise.all(PRECACHE_ASSETS.map(function (address) {
                return cache.add(address).catch(function (err) {
                    console.warn('[SW] Could not store ' + address + ' for offline use:', err);
                });
            }));
        })
    );
    // 📋 Activate immediately without waiting for existing tabs to close
    self.skipWaiting();
});

// =========================================================================
// 🧹 Activate — clean up old cache versions
// =========================================================================
// Deletes every store whose name is not CACHE_VERSION. This is what removes
// the pre-#507 'portal-v2' store, signed-in pages and all, the first time a
// browser runs this version.
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

    // 📋 Determine strategy based on resource type.
    //    A page load (mode 'navigate') never takes the static-file path, even
    //    when its address looks like one (#507): the Asset Tracker's pages
    //    live under /assets/, and taking the cache-first path meant a stored
    //    signed-in copy was shown even while online.
    if (request.mode !== 'navigate' && isStaticAsset(url.pathname)) {
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
 * The same file-ending list is repeated in the sign-out page script inside
 * Auth::logout() (web/_core/Auth.php). Change both together.
 *
 * @param {string} pathname
 * @returns {boolean}
 */
function isStaticAsset(pathname) {
    return /\.(css|js|png|jpg|jpeg|gif|svg|ico|woff2?|ttf|eot|webp)$/i.test(pathname)
        || pathname.indexOf('/assets/') === 0;
}

/**
 * Is this a web page (as opposed to a stylesheet, script, image, …)?
 *
 * @param {Response} response
 * @returns {boolean}
 */
function isHtml(response) {
    var type = response.headers.get('Content-Type');
    return typeof type === 'string' && type.toLowerCase().indexOf('text/html') !== -1;
}

/**
 * May this response be written to the worker's storage? (#507)
 *
 * The reasons behind each refusal are in the file header above. In short:
 * pages need the portal's explicit "allow" marker; nothing "private" is ever
 * kept; static files are kept unless the server said "no-store".
 *
 * @param {Response} response
 * @returns {boolean}
 */
function mayKeepCopy(response) {
    // 📋 Only complete, same-origin, non-redirected answers. 'basic' is the
    //    type a same-origin fetch gets; anything else cannot be inspected.
    if (!response || response.ok !== true || response.type !== 'basic' || response.redirected === true) {
        return false;
    }

    var cacheControl = (response.headers.get('Cache-Control') || '').toLowerCase();
    if (cacheControl.indexOf('private') !== -1) {
        return false;
    }

    if (isHtml(response) === true) {
        // 📋 No-store is NOT checked here on purpose: PHP marks every page
        //    no-store, public ones included (see the file header).
        return response.headers.get(OFFLINE_COPY_HEADER) === 'allow';
    }

    return cacheControl.indexOf('no-store') === -1;
}

/**
 * Look a request up in THIS version's store only.
 *
 * caches.match() on its own searches every store the site has. Reading only
 * CACHE_VERSION means a store left over from an older worker (for example,
 * if deleting it failed) is never used to answer a request.
 *
 * @param {Request|string} request
 * @returns {Promise<Response|undefined>}
 */
function matchCurrent(request) {
    return caches.open(CACHE_VERSION).then(function (cache) {
        return cache.match(request);
    });
}

/**
 * Make the stored offline page usable as the answer to a page load.
 *
 * WHY: browsers will not show a response that was reached through a redirect
 * as the answer to a page load; the Fetch standard treats it as a network
 * error when the page load did not itself ask to follow redirects, which page
 * loads never do. Chrome reports it as "a redirected response was used for a
 * request whose redirect mode is not follow". Before this, the offline page
 * stored on Apache was exactly such a response (see OFFLINE_PAGE above), so
 * every offline fallback failed with a bare browser error.
 *
 * A copy built with new Response() is not marked as redirected, so it is
 * accepted. The body is read into a Blob first, because every browser that
 * runs service workers can build a Response from a Blob.
 *
 * TRIED AND REJECTED: only changing the address to '/offline/'. That fixes
 * Apache as it is configured today, but it depends on how each customer's web
 * server answers that address, and a failure here is silent — the page a
 * visitor sees is the browser's own error, and nothing is logged anywhere.
 *
 * WHAT IT CANNOT DO: it only repairs the offline page. Other stored pages
 * never need it, because mayKeepCopy() refuses redirected responses.
 *
 * @see https://fetch.spec.whatwg.org/#http-fetch (the "redirected" check on a
 *      response returned by a service worker)
 * @param {Response} response  the stored offline page
 * @returns {Promise<Response>|Response}
 */
function offlinePageResponse(response) {
    if (response.redirected !== true) {
        return response;
    }
    return response.blob().then(function (body) {
        return new Response(body, {
            status: response.status,
            statusText: response.statusText,
            headers: response.headers
        });
    });
}

/**
 * Store a copy, but only if mayKeepCopy() agrees.
 *
 * @param {Request} request
 * @param {Response} response  a response the caller will not read again
 * @returns {Promise<void>}
 */
function keepCopy(request, response) {
    if (mayKeepCopy(response) === false) {
        return Promise.resolve();
    }
    return caches.open(CACHE_VERSION).then(function (cache) {
        return cache.put(request, response);
    });
}

/**
 * Cache-first strategy: serve from cache if available, else fetch and cache.
 *
 * @param {Request} request
 * @returns {Promise<Response>}
 */
function cacheFirst(request) {
    return matchCurrent(request).then(function (cached) {
        if (cached) {
            // 📋 Update cache in background for freshness (stale-while-revalidate)
            fetch(request).then(function (response) {
                return keepCopy(request, response);
            }).catch(function () {
                // 📋 Network unavailable — cached version is fine
            });
            return cached;
        }

        return fetch(request).then(function (response) {
            // 📋 Fire and forget: the page should not wait for the write.
            keepCopy(request, response.clone()).catch(function () {});
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
        // 📋 Only pages are kept on this path (as before #507); mayKeepCopy()
        //    then decides whether THIS page may be kept.
        if (response && isHtml(response) === true) {
            keepCopy(request, response.clone()).catch(function () {});
        }
        return response;
    }).catch(function () {
        // 📋 Network failed — try cache
        return matchCurrent(request).then(function (cached) {
            if (cached) {
                return cached;
            }
            // 📋 No cache match — show offline page
            return matchCurrent(OFFLINE_PAGE).then(function (offlinePage) {
                if (offlinePage) {
                    return offlinePageResponse(offlinePage);
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

// =========================================================================
// 🔔 Web Push (#322) — RFC 8291 decryption happens in the browser itself
// (the Push API spec, not this file); by the time `push` fires here the
// payload is already plaintext JSON on `event.data`.
// =========================================================================
self.addEventListener('push', function (event) {
    var data = { title: 'Notification', body: '', url: '/', tag: 'portal-push' };
    try {
        if (event.data) {
            var parsed = event.data.json();
            data.title = parsed.title || data.title;
            data.body  = parsed.body  || data.body;
            data.url   = parsed.url   || data.url;
            data.tag   = parsed.tag   || data.tag;
        }
    } catch (err) {
        // 📋 Unparseable body — still show SOMETHING. userVisibleOnly:true
        // was promised at subscribe time; a silent push erodes that promise
        // and browsers may revoke the subscription if it happens too often.
        data.body = 'You have a new notification.';
    }

    event.waitUntil(
        self.registration.showNotification(data.title, {
            body: data.body,
            icon: '/assets/images/icon-192.svg',
            badge: '/assets/images/icon-192.svg',
            tag: data.tag,
            data: { url: data.url }
        })
    );
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    // 🛡️ Defence-in-depth: only ever navigate to a same-origin-relative
    // path, even though the payload is server-authored (WebPush.php only
    // ever sends portal-internal URLs).
    var url = (event.notification.data && event.notification.data.url) || '/';
    if (typeof url !== 'string' || url.indexOf('/') !== 0 || url.indexOf('//') === 0) {
        url = '/';
    }

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
            for (var i = 0; i < clientList.length; i++) {
                var client = clientList[i];
                if (client.url.indexOf(self.location.origin) === 0 && 'focus' in client) {
                    if ('navigate' in client) {
                        client.navigate(url);
                    }
                    return client.focus();
                }
            }
            if (self.clients.openWindow) {
                return self.clients.openWindow(url);
            }
        })
    );
});
