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

Auth::ensureSession();
Auth::requireLogin();

$isAdmin = App::isSiteAdmin();          // 4-tier site admin check
$csrf    = Auth::csrfToken();
$nonce   = App::cspNonce();

$pageTitle   = 'Noticeboard';
$pageSection = 'noticeboard';
$breadcrumbs = ['Dashboard' => '/', 'Noticeboard' => ''];

// 🔐 Board needs Canva iframes + externally-hosted poster media on THIS page only.
//    See _core/templates/header.php CSP extension contract.
$cspImgExtra   = 'https:';
$cspMediaExtra = 'https:';
$cspFrameExtra = 'https://www.canva.com';

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

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
      const r = await fetch('/api/noticeboard/list', { credentials: 'same-origin' });
      const j = await r.json();
      return (j && j.data) ? j.data : [];
    },

    // Persist the full poster array (called on add / edit / delete).
    save: async function (posters) {
      await fetch('/api/noticeboard/save', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.NoticeboardHost.csrf },
        body: JSON.stringify({ posters: posters })
      });
    },

    // QR for the share/deep-link panel — served by Portal\Core\Qr (or CueRCode).
    qrUrl: function (text) {
      return '/api/noticeboard/qr?data=' + encodeURIComponent(text);
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
     @import (see fonts-selfhost.css header + DEV_NOTES.md). -->
<link rel="stylesheet" href="/assets/noticeboard/fonts-selfhost.css">
<link rel="stylesheet" href="/assets/noticeboard/noticeboard.css">
<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>" src="/assets/vendor/react/react-18.3.1.production.min.js" defer></script>
<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>" src="/assets/vendor/react/react-dom-18.3.1.production.min.js" defer></script>
<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>" src="/assets/noticeboard/noticeboard.noeval.js" defer></script>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
