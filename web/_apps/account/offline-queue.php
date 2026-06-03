<?php
// Path: _apps/account/offline-queue.php
/**
 * Offline Queue Inspector — user-facing view of pending offline writes.
 *
 * The queue itself lives entirely in the user's browser (IndexedDB);
 * this page renders a thin shell into which portal-offline.js hydrates
 * a live list. No server-side data to render — everything happens
 * client-side via JS.
 *
 * @package   Portal\Account
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/233
 */

declare(strict_types=1);

use Portal\Core\Auth;

Auth::ensureSession();
Auth::requireLogin();

$pageTitle   = 'Offline queue';
$pageSection = 'account';
$breadcrumbs = ['Dashboard' => '/', 'My Account' => '/account', 'Offline queue' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-3"><i class="fa-solid fa-cloud-arrow-up me-2"></i>Offline queue</h1>
<p class="text-secondary">
    Submissions you made while offline. Each one re-tries automatically when
    you're back online; this page shows what's still queued and lets you
    discard items you no longer want to sync.
</p>

<div id="portal-queue-status" class="alert alert-info">Loading queue…</div>

<div id="portal-queue-list" class="card mb-3" hidden>
    <div class="card-body">
        <div id="portal-queue-rows" class="portal-data-list"></div>
    </div>
</div>

<div class="d-flex gap-2">
    <button id="portal-queue-sync" class="btn btn-primary btn-sm" type="button" hidden>
        <i class="fa-solid fa-rotate me-1"></i>Sync now
    </button>
    <button id="portal-queue-clear" class="btn btn-outline-danger btn-sm" type="button" hidden
            data-confirm="Discard every queued submission? This cannot be undone.">
        <i class="fa-solid fa-trash me-1"></i>Clear queue
    </button>
</div>

<script nonce="<?php echo htmlspecialchars(\Portal\Core\App::cspNonce(), ENT_QUOTES, 'UTF-8'); ?>">
(function () {
    if (!window.Portal || !window.Portal.OfflineQueue) {
        document.getElementById('portal-queue-status').textContent =
            'Offline queue is not available in this browser (no IndexedDB).';
        return;
    }
    var Q = window.Portal.OfflineQueue;
    var statusEl = document.getElementById('portal-queue-status');
    var listEl   = document.getElementById('portal-queue-list');
    var rowsEl   = document.getElementById('portal-queue-rows');
    var syncBtn  = document.getElementById('portal-queue-sync');
    var clearBtn = document.getElementById('portal-queue-clear');

    function fmtDate(iso) {
        if (!iso) return '';
        try { return new Date(iso).toLocaleString(); } catch (e) { return iso; }
    }
    function escape(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function render(entries) {
        if (entries.length === 0) {
            statusEl.textContent = 'Queue is empty — every submission is synced.';
            statusEl.className = 'alert alert-success';
            listEl.hidden = true;
            syncBtn.hidden = true;
            clearBtn.hidden = true;
            return;
        }
        statusEl.textContent = entries.length + ' queued submission' +
            (entries.length === 1 ? '' : 's') +
            (navigator.onLine === true ? ' — syncing automatically when network is reachable.' : ' — sync paused; you appear to be offline.');
        statusEl.className = 'alert ' + (navigator.onLine === true ? 'alert-info' : 'alert-warning');
        listEl.hidden = false;
        syncBtn.hidden = false;
        clearBtn.hidden = false;
        rowsEl.innerHTML = entries.map(function (e) {
            var attempts = e.attempts || 0;
            var attemptsLabel = attempts > 0
                ? '<span class="badge bg-warning ms-2">' + attempts + ' attempt' + (attempts === 1 ? '' : 's') + '</span>'
                : '';
            var errorRow = e.lastError
                ? '<div class="small text-danger">' + escape(e.lastError) + '</div>'
                : '';
            return '<div class="row py-2 border-bottom small align-items-center">'
                +   '<div class="col-md-6">'
                +     '<strong>' + escape(e.method) + '</strong> '
                +     '<code>' + escape(e.url) + '</code>'
                +     attemptsLabel
                +     errorRow
                +   '</div>'
                +   '<div class="col-md-4 text-muted">' + escape(fmtDate(e.queuedAt)) + '</div>'
                +   '<div class="col-md-2 text-end">'
                +     '<button class="btn btn-outline-danger btn-sm" data-discard="' + escape(e.id) + '">Discard</button>'
                +   '</div>'
                + '</div>';
        }).join('');
        // Attach discard handlers (delegated since markup re-renders).
        rowsEl.querySelectorAll('[data-discard]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                Q.remove(btn.getAttribute('data-discard')).then(refresh);
            });
        });
    }

    function refresh() { Q.list().then(render); }

    syncBtn.addEventListener('click', function () {
        if (navigator.onLine === false) {
            statusEl.textContent = 'Still offline — connect to the internet first.';
            statusEl.className = 'alert alert-warning';
            return;
        }
        statusEl.textContent = 'Syncing…';
        statusEl.className = 'alert alert-info';
        Q.drain().then(function (s) {
            refresh();
        });
    });

    clearBtn.addEventListener('click', function () {
        // The data-confirm attribute is intercepted by Portal.Confirm modal
        // (it dispatches a synthetic click after the user confirms). When
        // that fires we get here without re-confirming.
        Q.list().then(function (entries) {
            Promise.all(entries.map(function (e) { return Q.remove(e.id); })).then(refresh);
        });
    });

    window.addEventListener('portal-queue-synced', refresh);
    window.addEventListener('online',  refresh);
    window.addEventListener('offline', refresh);

    refresh();
}());
</script>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
