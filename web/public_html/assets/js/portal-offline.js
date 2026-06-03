/**
 * =============================================================================
 * Portal.OfflineQueue — IndexedDB-backed write queue (#233)
 * =============================================================================
 * Forms tagged with `data-offline-queueable` use this queue: on submit, when
 * navigator.onLine === false the FormData payload is serialised into
 * IndexedDB and the form's success indicator changes to "Queued — will sync
 * when online". When connectivity returns (online event, Background Sync, or
 * visibilitychange poll) the queue drains by re-issuing each request with an
 * X-Offline-Queued-At header carrying the original submission timestamp.
 *
 * IDB schema:
 *   db:    portal-offline-queue   (version 1)
 *   store: writes
 *     keyPath: id  (string — uuid4)
 *     value: {
 *       id, url, method, contentType, queuedAt (ISO),
 *       payload (string|Object), files (Array<{name, type, size, blob}>),
 *       attempts, lastTried, lastError
 *     }
 *
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/233
 * =============================================================================
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.Portal = root.Portal || {};
        root.Portal.OfflineQueue = factory();
    }
}(typeof window !== 'undefined' ? window : this, function () {
    'use strict';

    var DB_NAME    = 'portal-offline-queue';
    var DB_VERSION = 1;
    var STORE      = 'writes';

    function uuid4() {
        // RFC-4122 v4 — sufficient client-side; the server still issues its
        // own canonical IDs on insert. Used here purely as IDB key.
        var b = new Uint8Array(16);
        (window.crypto || window.msCrypto).getRandomValues(b);
        b[6] = (b[6] & 0x0f) | 0x40;
        b[8] = (b[8] & 0x3f) | 0x80;
        var h = Array.prototype.map.call(b, function (n) {
            return ('0' + n.toString(16)).slice(-2);
        }).join('');
        return h.substr(0,8)+'-'+h.substr(8,4)+'-'+h.substr(12,4)+'-'+h.substr(16,4)+'-'+h.substr(20,12);
    }

    function openDb() {
        return new Promise(function (resolve, reject) {
            var req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onerror   = function () { reject(req.error); };
            req.onupgradeneeded = function () {
                var db = req.result;
                if (!db.objectStoreNames.contains(STORE)) {
                    db.createObjectStore(STORE, { keyPath: 'id' });
                }
            };
            req.onsuccess = function () { resolve(req.result); };
        });
    }

    function tx(mode) {
        return openDb().then(function (db) {
            return db.transaction(STORE, mode).objectStore(STORE);
        });
    }

    /**
     * Enqueue a FormData submission. Resolves with the queue entry id.
     */
    function enqueue(url, method, formData) {
        return new Promise(function (resolve, reject) {
            var payload = {};
            var files   = [];
            var pendingFiles = [];

            formData.forEach(function (value, key) {
                if (value instanceof File) {
                    pendingFiles.push(new Promise(function (resolveBlob) {
                        var reader = new FileReader();
                        reader.onload = function () {
                            files.push({
                                key:  key,
                                name: value.name,
                                type: value.type,
                                size: value.size,
                                blob: reader.result  // ArrayBuffer
                            });
                            resolveBlob();
                        };
                        reader.readAsArrayBuffer(value);
                    }));
                } else {
                    // Multi-value keys (e.g. checkboxes) — accumulate as arrays.
                    if (Object.prototype.hasOwnProperty.call(payload, key)) {
                        if (Array.isArray(payload[key]) === false) {
                            payload[key] = [payload[key]];
                        }
                        payload[key].push(value);
                    } else {
                        payload[key] = value;
                    }
                }
            });

            Promise.all(pendingFiles).then(function () {
                var entry = {
                    id:          uuid4(),
                    url:         url,
                    method:      method || 'POST',
                    queuedAt:    new Date().toISOString(),
                    payload:     payload,
                    files:       files,
                    attempts:    0,
                    lastTried:   null,
                    lastError:   null
                };
                tx('readwrite').then(function (store) {
                    var req = store.add(entry);
                    req.onsuccess = function () { resolve(entry.id); };
                    req.onerror   = function () { reject(req.error); };
                });
            }, reject);
        });
    }

    function list() {
        return tx('readonly').then(function (store) {
            return new Promise(function (resolve, reject) {
                var req = store.getAll();
                req.onsuccess = function () { resolve(req.result || []); };
                req.onerror   = function () { reject(req.error); };
            });
        });
    }

    function remove(id) {
        return tx('readwrite').then(function (store) {
            return new Promise(function (resolve, reject) {
                var req = store.delete(id);
                req.onsuccess = function () { resolve(); };
                req.onerror   = function () { reject(req.error); };
            });
        });
    }

    function update(entry) {
        return tx('readwrite').then(function (store) {
            return new Promise(function (resolve, reject) {
                var req = store.put(entry);
                req.onsuccess = function () { resolve(); };
                req.onerror   = function () { reject(req.error); };
            });
        });
    }

    /**
     * Re-issue one queued entry to the server. Builds a FormData from the
     * stored payload + files. Resolves on success, rejects on transport
     * failure (caller bumps attempts + lastError).
     */
    function dispatch(entry) {
        return new Promise(function (resolve, reject) {
            var fd = new FormData();
            Object.keys(entry.payload || {}).forEach(function (k) {
                var v = entry.payload[k];
                if (Array.isArray(v) === true) {
                    v.forEach(function (item) { fd.append(k, item); });
                } else {
                    fd.append(k, v);
                }
            });
            (entry.files || []).forEach(function (f) {
                var blob = new Blob([f.blob], { type: f.type });
                fd.append(f.key, new File([blob], f.name, { type: f.type }));
            });
            var headers = {
                'X-Offline-Queued-At': entry.queuedAt,
                'X-Requested-With':    'PortalOfflineQueue'
            };
            fetch(entry.url, {
                method:      entry.method,
                body:        fd,
                credentials: 'same-origin',
                headers:     headers
            }).then(function (response) {
                if (response.ok === true || (response.status >= 300 && response.status < 400)) {
                    resolve(response);
                } else {
                    reject(new Error('HTTP ' + response.status));
                }
            }, reject);
        });
    }

    /**
     * Drain the queue. Each entry is dispatched; on success it's removed,
     * on failure its attempts counter is bumped and lastError stored.
     * Returns a summary object.
     */
    function drain() {
        return list().then(function (entries) {
            var sent = 0, failed = 0;
            return entries.reduce(function (p, e) {
                return p.then(function () {
                    return dispatch(e).then(function () {
                        sent++;
                        return remove(e.id);
                    }, function (err) {
                        failed++;
                        e.attempts = (e.attempts || 0) + 1;
                        e.lastTried = new Date().toISOString();
                        e.lastError = String(err && err.message ? err.message : err);
                        return update(e);
                    });
                });
            }, Promise.resolve()).then(function () {
                return { sent: sent, failed: failed, total: entries.length };
            });
        });
    }

    // -------------------------------------------------------------------------
    // Form interceptor — auto-wire any <form data-offline-queueable>.
    // -------------------------------------------------------------------------

    function flashQueued(form) {
        var hint = form.querySelector('[data-offline-hint]');
        if (hint === null) {
            hint = document.createElement('div');
            hint.setAttribute('data-offline-hint', '');
            hint.className = 'alert alert-warning small mt-2 mb-0';
            form.appendChild(hint);
        }
        hint.textContent = 'Queued — will sync when you\'re back online.';
        hint.style.display = '';
    }

    function attachIntercept() {
        document.addEventListener('submit', function (ev) {
            var form = ev.target;
            if (!(form instanceof HTMLFormElement)) { return; }
            if (form.hasAttribute('data-offline-queueable') === false) { return; }
            if (navigator.onLine === true) { return; }
            ev.preventDefault();
            var url    = form.action || window.location.href;
            var method = (form.method || 'POST').toUpperCase();
            var fd     = new FormData(form);
            enqueue(url, method, fd).then(function () {
                flashQueued(form);
                form.reset();
            }, function (err) {
                console.error('OfflineQueue enqueue failed:', err);
            });
        }, true);
    }

    function attachReconnect() {
        var doDrain = function () {
            if (navigator.onLine === false) { return; }
            drain().then(function (s) {
                if (s.sent > 0) {
                    var ev = new CustomEvent('portal-queue-synced', { detail: s });
                    window.dispatchEvent(ev);
                }
            });
        };
        window.addEventListener('online', doDrain);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') { doDrain(); }
        });
        // First-page-load attempt — in case the queue carries items from a
        // previous session.
        if (navigator.onLine === true) {
            setTimeout(doDrain, 1500);
        }
    }

    function attachIndicator() {
        if (document.getElementById('portal-conn-indicator') !== null) {
            return; // already rendered server-side
        }
        var dot = document.createElement('span');
        dot.id = 'portal-conn-indicator';
        dot.className = 'portal-conn-indicator';
        dot.title = 'Connection status';
        dot.style.cssText =
            'display:inline-block;width:10px;height:10px;border-radius:50%;' +
            'background:#22c55e;margin-left:8px;vertical-align:middle;';
        document.body.appendChild(dot);
        function refresh() {
            list().then(function (entries) {
                if (navigator.onLine === false) {
                    dot.style.background = '#ef4444'; // red — offline
                    dot.title = 'Offline';
                } else if (entries.length > 0) {
                    dot.style.background = '#f59e0b'; // amber — queueing
                    dot.title = entries.length + ' queued — syncing';
                } else {
                    dot.style.background = '#22c55e'; // green — online
                    dot.title = 'Online';
                }
            });
        }
        window.addEventListener('online',  refresh);
        window.addEventListener('offline', refresh);
        window.addEventListener('portal-queue-synced', refresh);
        document.addEventListener('visibilitychange', refresh);
        setInterval(refresh, 15000);
        refresh();
    }

    function init() {
        if (typeof indexedDB === 'undefined') { return; }
        attachIntercept();
        attachReconnect();
        attachIndicator();
    }

    // Auto-init on DOMContentLoaded.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    return {
        enqueue: enqueue,
        list:    list,
        drain:   drain,
        remove:  remove,
        update:  update,
        init:    init
    };
}));
