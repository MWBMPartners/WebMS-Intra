/**
 * Event Team Hub — direct-to-Cloudflare upload widget (#386 Phase 1.5)
 *
 * Single vanilla JS file, no build step, no external dependencies (matches
 * the CSP — script-src has no CDN allowance for page-specific JS). Boots
 * automatically on DOMContentLoaded when a `#hubUploadForm` element is
 * present (only rendered when the viewer canManage AND Cloudflare Stream
 * is configured — see event-hub.php).
 *
 * Flow:
 *   1. Submit → POST /calendar/event/hub/upload-url (form-encoded,
 *      csrf_token from the page's <meta name="csrf-token">). Server mints
 *      a one-time Cloudflare direct-upload URL and an `uploadStatus =
 *      'pending'` row.
 *   2. XHR the picked File straight to that uploadURL (field name "file"
 *      per Cloudflare's basic direct-upload contract). Basic upload only —
 *      capped at 200MB client-side; larger files are refused with a clear
 *      message (tus resumable upload is deferred, see DEV_NOTES.md).
 *   3. On xhr.onload, poll /calendar/event/hub/video-status every ~4s
 *      until the video reaches 'ready' or 'error', then reload the page so
 *      the server-rendered (playable / errored) tile replaces the
 *      placeholder — the simplest correct approach, and consistent with
 *      every other mutation on this page already being redirect+re-render.
 *
 * CSRF token rotation: Auth::verifyCsrf() rotates the session token on
 * EVERY successful check (see _core/Auth.php). Because this widget makes
 * several CSRF-protected POSTs in a row without a full page reload (mint,
 * then repeated status polls), every response includes the freshly
 * rotated `csrfToken` — syncCsrf() below writes it back into BOTH the
 * <meta name="csrf-token"> tag and every `input[name="csrf_token"]` on the
 * page, so the OTHER plain HTML forms on this page (add/edit/reorder/
 * remove resource or video, Stream-settings) keep working even if the
 * coordinator submits one of them mid-poll without reloading first.
 *
 * @see https://github.com/MWBMPartners/WebMS-Intra/issues/386
 */
(function () {
    'use strict';

    var MAX_BYTES  = 209715200; // 200MB — basic direct-upload ceiling (tus deferred)
    var POLL_MS    = 4000;
    var ALLOWED_UPLOAD_HOSTS = ['upload.videodelivery.net', 'upload.cloudflarestream.com'];

    /* ------------------------------------------------------------------ */
    /* CSRF helpers                                                       */
    /* ------------------------------------------------------------------ */

    function getCsrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta !== null ? (meta.getAttribute('content') || '') : '';
    }

    function syncCsrf(token) {
        if (!token) {
            return;
        }
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta !== null) {
            meta.setAttribute('content', token);
        }
        var inputs = document.querySelectorAll('input[name="csrf_token"]');
        for (var i = 0; i < inputs.length; i++) {
            inputs[i].value = token;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Small XHR helper for our own (same-origin, form-encoded) endpoints */
    /* ------------------------------------------------------------------ */

    function postForm(url, fields, cb) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', url, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) {
                return;
            }
            var resp = null;
            try {
                resp = JSON.parse(xhr.responseText);
            } catch (e) {
                resp = null;
            }
            cb(xhr.status, resp);
        };
        var parts = [];
        for (var key in fields) {
            if (Object.prototype.hasOwnProperty.call(fields, key)) {
                parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(fields[key]));
            }
        }
        xhr.send(parts.join('&'));
    }

    var ERROR_MESSAGES = {
        method:         'Unexpected request method.',
        csrf:           'Your session expired — please reload the page and try again.',
        forbidden:      'You do not have permission to upload video for this event.',
        event:          'Event not found.',
        not_configured: 'Cloudflare Stream is not configured for this site.',
        rate_limited:   'Too many uploads started in the last hour — please try again later.',
        title:          'Please enter a title for the video.',
        origins:        'Allowed origins must be bare hostnames (no scheme, no path).',
        cloudflare:     'Cloudflare rejected the upload request — please try again.'
    };

    function errorMessage(resp) {
        if (resp && resp.error && ERROR_MESSAGES[resp.error]) {
            return ERROR_MESSAGES[resp.error];
        }
        return 'Something went wrong — please try again.';
    }

    /* ------------------------------------------------------------------ */
    /* Status polling — shared by the just-uploaded flow AND by any        */
    /* pending/processing tiles already on the page at load time.         */
    /* ------------------------------------------------------------------ */

    function pollStatus(eventId, videoId, onUpdate) {
        postForm('/calendar/event/hub/video-status', {
            csrf_token: getCsrf(),
            eventID: eventId,
            videoID: videoId
        }, function (httpStatus, resp) {
            if (resp && resp.csrfToken) {
                syncCsrf(resp.csrfToken);
            }
            if (!resp || resp.ok !== true) {
                // Transient failure (network blip, rotated-token race) —
                // back off and try again rather than giving up silently.
                setTimeout(function () { pollStatus(eventId, videoId, onUpdate); }, POLL_MS * 2);
                return;
            }
            if (resp.status === 'ready' || resp.status === 'error') {
                if (typeof onUpdate === 'function') {
                    onUpdate(resp.status, resp.errorDetail);
                }
                // 🔄 Simplest correct redraw — reload so the server-rendered
                //    tile (playable iframe, or the error card with
                //    errorDetail) replaces the placeholder.
                window.location.reload();
                return;
            }
            setTimeout(function () { pollStatus(eventId, videoId, onUpdate); }, POLL_MS);
        });
    }

    /* ------------------------------------------------------------------ */
    /* Upload form                                                        */
    /* ------------------------------------------------------------------ */

    function initUploadForm() {
        var form = document.getElementById('hubUploadForm');
        if (form === null) {
            return;
        }

        var fileInput    = document.getElementById('hubUploadFile');
        var titleInput   = document.getElementById('hubUploadTitle');
        var signedInput  = document.getElementById('hubUploadSigned');
        var originsInput = document.getElementById('hubUploadOrigins');
        var submitBtn    = document.getElementById('hubUploadSubmit');
        var progressWrap = document.getElementById('hubUploadProgressWrap');
        var progressBar  = document.getElementById('hubUploadProgressBar');
        var statusLine   = document.getElementById('hubUploadStatusLine');
        var eventId      = form.getAttribute('data-event-id');

        function setStatus(message, isError) {
            if (statusLine === null) {
                return;
            }
            statusLine.textContent = message; // textContent only — never innerHTML
            statusLine.className = 'small mt-1 ' + (isError === true ? 'text-danger' : 'text-muted');
        }

        function setBusy(busy) {
            if (submitBtn !== null) {
                submitBtn.disabled = busy;
            }
            if (fileInput !== null) {
                fileInput.disabled = busy;
            }
        }

        function showProgress(pct) {
            if (progressWrap === null || progressBar === null) {
                return;
            }
            progressWrap.classList.remove('d-none');
            progressBar.style.width = pct + '%';
            progressBar.textContent = pct + '%';
        }

        function uploadFile(file, uploadUrl, videoId) {
            var parsedHost = '';
            try {
                parsedHost = new URL(uploadUrl).hostname;
            } catch (e) {
                setStatus('Cloudflare returned an invalid upload URL.', true);
                setBusy(false);
                return;
            }
            // 🛡️ Defence-in-depth — refuse to XHR anywhere outside the
            //    hosts this page's CSP connect-src was widened for.
            if (ALLOWED_UPLOAD_HOSTS.indexOf(parsedHost) === -1) {
                setStatus('Refused to upload — unexpected host.', true);
                setBusy(false);
                return;
            }

            var xhr = new XMLHttpRequest();
            xhr.open('POST', uploadUrl, true);
            xhr.upload.onprogress = function (e) {
                if (e.lengthComputable === true) {
                    showProgress(Math.round((e.loaded / e.total) * 100));
                }
            };
            xhr.onload = function () {
                if (xhr.status >= 200 && xhr.status < 300) {
                    setStatus('Uploaded — processing on Cloudflare…', false);
                    pollStatus(eventId, videoId);
                } else {
                    setStatus('Upload failed (HTTP ' + xhr.status + ').', true);
                    setBusy(false);
                }
            };
            xhr.onerror = function () {
                setStatus('Upload failed — network error.', true);
                setBusy(false);
            };
            var body = new FormData();
            body.append('file', file); // Cloudflare's expected field name for basic direct upload
            setStatus('Uploading…', false);
            xhr.send(body);
        }

        form.addEventListener('submit', function (ev) {
            ev.preventDefault();

            var file = (fileInput !== null && fileInput.files.length > 0) ? fileInput.files[0] : null;
            if (file === null) {
                setStatus('Choose a video file first.', true);
                return;
            }
            if (file.size > MAX_BYTES) {
                setStatus('That file is larger than 200MB — direct upload of larger files is not supported yet.', true);
                return;
            }

            setBusy(true);
            setStatus('Requesting an upload slot…', false);

            postForm('/calendar/event/hub/upload-url', {
                csrf_token: getCsrf(),
                eventID: eventId,
                title: titleInput !== null ? titleInput.value : '',
                requiresSignedUrl: (signedInput !== null && signedInput.checked === true) ? '1' : '0',
                allowedOrigins: originsInput !== null ? originsInput.value : ''
            }, function (httpStatus, resp) {
                if (resp && resp.csrfToken) {
                    syncCsrf(resp.csrfToken);
                }
                if (!resp || resp.ok !== true) {
                    setBusy(false);
                    setStatus(errorMessage(resp), true);
                    return;
                }
                if (typeof resp.maxBytes === 'number' && file.size > resp.maxBytes) {
                    setBusy(false);
                    setStatus('File exceeds the ' + Math.floor(resp.maxBytes / 1048576) + 'MB limit.', true);
                    return;
                }
                uploadFile(file, resp.uploadURL, resp.videoID);
            });
        });
    }

    /* ------------------------------------------------------------------ */
    /* Resume polling for tiles that were already pending/processing at    */
    /* page-load (belt-and-braces — a coordinator who closed the tab       */
    /* mid-encode still sees it converge on the next visit).               */
    /* ------------------------------------------------------------------ */

    function resumePendingTiles() {
        var tiles = document.querySelectorAll('[data-hub-video-pending]');
        for (var i = 0; i < tiles.length; i++) {
            var tile = tiles[i];
            var eventId = tile.getAttribute('data-event-id');
            var videoId = tile.getAttribute('data-video-id');
            if (eventId && videoId) {
                pollStatus(eventId, videoId);
            }
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        initUploadForm();
        resumePendingTiles();
    });
}());
