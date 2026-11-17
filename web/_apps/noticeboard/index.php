<?php
// Path: _apps/noticeboard/index.php
/**
 * Noticeboard — pinboard of event posters.
 *
 * Renders the self-contained Noticeboard app (assets under
 * public_html/assets/noticeboard/) and hands it the portal's auth state +
 * data API through the window.NoticeboardHost bridge. The board itself is
 * client-side; this page only supplies identity and the API URLs.
 *
 * @package   Portal\Apps\Noticeboard
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\PublicWebsite;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$isAdmin = App::isSiteAdmin();          // 4-tier site admin check
$csrf    = Auth::csrfToken();
$nonce   = App::cspNonce();

$pageTitle   = 'Noticeboard';
$pageSection = 'noticeboard';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Noticeboard' => ''];

// 🔐 Board needs Canva iframes + externally-hosted poster media on THIS page only.
//    See _core/templates/header.php CSP extension contract.
$cspImgExtra   = 'https:';
$cspMediaExtra = 'https:';
$cspFrameExtra = 'https://www.canva.com';

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if (PublicWebsite::mayPublish($mysqli, (int) ($_SESSION['user_id'] ?? 0), Site::id()) === true): ?>
<?php // 🌐 #493 P2a: the board's own editor cannot set the website tick (it is a generated bundle), so it lives on its own page. ?>
<p class="small mb-2"><a href="<?php echo htmlspecialchars(Site::url('noticeboard/website'), ENT_QUOTES, 'UTF-8'); ?>"><i class="fa-solid fa-globe me-1"></i>Choose which posters are on the public website</a></p>
<?php endif; ?>
<?php if ($isAdmin === true): ?>
<?php // 📺 #493 P2b: the foyer screen's link and choice, for whoever manages the board. ?>
<p class="small mb-2"><a href="<?php echo htmlspecialchars(Site::url('noticeboard/foyer/setup'), ENT_QUOTES, 'UTF-8'); ?>"><i class="fa-solid fa-tv me-1"></i>Set up the foyer screen</a></p>
<?php endif; ?>
<!-- The board mounts full-bleed inside the portal content area. -->
<div id="noticeboard-root" style="position:relative; min-height:70vh;"></div>

<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>">
  // Bridge the portal into the standalone board (see assets/noticeboard/README).
  window.NoticeboardHost = {
    mode: 'host',                                  // portal decides admin; no demo password
    isAdmin: <?php echo $isAdmin === true ? 'true' : 'false'; ?>,
    csrf: <?php echo json_encode($csrf); ?>,

    // Load all posters for the current site.
    load: async function () {
      const r = await fetch('<?php echo Router::urlForScript('api/noticeboard/list'); ?>', { credentials: 'same-origin' });
      const j = await r.json();
      return (j && j.data) ? j.data : [];
    },

    // 🔑 #1141 / #768: the form token is replaced by the server on every accepted
    //    post, so a copy captured at page load works ONCE. The live token is read
    //    at the moment of posting (Portal.csrf(), which reads every carrier) and
    //    every answer is handed to Portal.rememberCsrf() so the next post carries
    //    the replacement. `this.csrf` is only the first-ever fallback, and is kept
    //    current too because upload() below reads it.
    liveToken: function () {
      var live = (window.Portal && typeof window.Portal.csrf === 'function') ? window.Portal.csrf() : '';
      return (typeof live === 'string' && live !== '') ? live : window.NoticeboardHost.csrf;
    },
    keepToken: function (answer) {
      if (window.Portal && typeof window.Portal.rememberCsrf === 'function') {
        window.Portal.rememberCsrf(answer);
      }
      var next = answer && answer.meta && typeof answer.meta.csrfToken === 'string' ? answer.meta.csrfToken : '';
      if (next !== '') { window.NoticeboardHost.csrf = next; }
    },

    // 📣 The generated board calls save() and throws the answer away, so this host
    //    is the only place a failed save can be told to the person. A plain notice
    //    above the board (textContent, no handler, no inline style that needs the
    //    nonce): it says what is known, which is that the change is shown on
    //    screen but may not be stored.
    tellSaveFailed: function (why) {
      var root = document.getElementById('noticeboard-root');
      if (!root || !root.parentNode) { return; }
      var note = document.getElementById('noticeboard-save-failed');
      if (!note) {
        note = document.createElement('div');
        note.id = 'noticeboard-save-failed';
        note.className = 'alert alert-danger small';
        note.setAttribute('role', 'alert');
        root.parentNode.insertBefore(note, root);
      }
      note.textContent = 'Your last change could not be saved (' + why + '). It is still showing here, but it may be lost when you leave this page. Reload the page and try again.';
    },
    clearSaveFailed: function () {
      var note = document.getElementById('noticeboard-save-failed');
      if (note && note.parentNode) { note.parentNode.removeChild(note); }
    },

    // Persist the full poster array (called on add / edit / delete).
    // ONE save at a time: each call waits for the one before, so the token the
    // earlier answer handed back is the one the next post carries. Two posts in
    // flight with the same token could never both be accepted. The array sent is
    // always the whole desired state, so the last one queued wins, as before.
    // The returned promise never rejects (the board ignores it anyway); failure is
    // shown by tellSaveFailed().
    saveQueue: Promise.resolve(),
    save: function (posters) {
      var host = window.NoticeboardHost;
      host.saveQueue = host.saveQueue.then(async function () {
        try {
          const r = await fetch('<?php echo Router::urlForScript('api/noticeboard/save'); ?>', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': host.liveToken() },
            body: JSON.stringify({ posters: posters })
          });
          const j = await r.json().catch(function () { return null; });
          host.keepToken(j);
          if (r.ok !== true || !j || j.status !== 'ok') {
            host.tellSaveFailed((j && j.message) ? j.message : ('error ' + r.status));
            return;
          }
          host.clearSaveFailed();
        } catch (e) {
          host.tellSaveFailed('the server could not be reached');
        }
      });
      return host.saveQueue;
    },

    // 📤 Real media upload (#363) — replaces the data: URI fallback the board
    // uses when this host method is absent. Multipart POST so the CSRF token
    // MUST travel via the X-CSRF-TOKEN header (the JSON csrf_token body field
    // only exists for JSON requests — see api/upload.php's docblock). Throws
    // on any non-2xx response so the bundle's .catch() can surface an error.
    upload: async function (file) {
      const fd = new FormData();
      fd.append('file', file);
      const r = await fetch('<?php echo Router::urlForScript('api/noticeboard/upload'); ?>', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': window.NoticeboardHost.liveToken() },
        body: fd
      });
      const j = await r.json().catch(function () { return null; });
      window.NoticeboardHost.keepToken(j);
      if (!r.ok || !j || j.status !== 'ok') {
        throw new Error((j && j.message) ? j.message : ('Upload failed (' + r.status + ')'));
      }
      return j.data.url;
    },

    // QR for the share/deep-link panel — served by Portal\Core\Qr (or CueRCode).
    qrUrl: function (text) {
      return '<?php echo Router::urlForScript('api/noticeboard/qr'); ?>?data=' + encodeURIComponent(text);
    }
  };
</script>

<!-- Mount the board. The EVAL-FREE bundle registers the precompiled component
     and reads window.NoticeboardHost on mount. React 18.3.1 UMD is self-hosted
     under /assets/vendor/react/ and loaded before the bundle (nonce'd, deferred);
     loadReactUmd() in noeval.js short-circuits when window.React / window.ReactDOM
     pre-exist so no unpkg fetch happens. -->
<!-- #361 — self-hosted @font-face for the board's four Google-sourced
     families (hand-maintained CSS, not part of the generated bundle). Must
     load BEFORE noticeboard.css so the faces exist before anything renders
     text with them; supersedes the generated CSS's CSP-blocked Google Fonts
     @import (see the fonts-selfhost.css header). -->
<link rel="stylesheet" href="/assets/noticeboard/fonts-selfhost.css">
<link rel="stylesheet" href="/assets/noticeboard/noticeboard.css">
<!-- 🔒 #575: the bundle below can fetch Babel (a compiler) from a public server
     (unpkg.com) with NO fingerprint, but only when window.Babel is missing and a
     template uses an <x-import> of a .jsx or .tsx file. Nothing in the portal
     does, and this page's script rule would refuse the fetch anyway; this stub
     makes it impossible whatever that rule says now or later: with window.Babel
     already defined the bundle never creates the outside script tag.
     COST, said plainly: an <x-import> of .jsx now fails with the message below
     instead of failing silently in the browser (it could not run before either).
     The bundle is GENERATED outside this repository and must not be hand-edited;
     the lasting fix is a BABEL_SRI fingerprint in its source (see
     tools/audit-checks/check_cdn_sri.py, which checks this stub is still here).
     It is inline, carries the nonce, and sits BEFORE the deferred tags so it runs
     first. -->
<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>">
  window.Babel = {
    transform: function () {
      throw new Error('Templates that need compiling in the browser are not supported on this portal.');
    }
  };
</script>
<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>" src="/assets/vendor/react/react-18.3.1.production.min.js" defer></script>
<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>" src="/assets/vendor/react/react-dom-18.3.1.production.min.js" defer></script>
<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>" src="/assets/noticeboard/noticeboard.noeval.js" defer></script>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
