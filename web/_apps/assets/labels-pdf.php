<?php
// Path: _apps/assets/labels-pdf.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Label PDF Generator 🏷️🖨️
 * -----------------------------------------------------------------------------
 * POST-only handler (#402). Rebuilds the label sheet set entirely from the
 * POSTED asset ids + options via `AssetRegister::buildLabelSheets()` — the
 * SAME rendering path `labels.php`'s preview uses — then either streams a
 * generated PDF (`Pdf::create()`, dompdf) or, when dompdf isn't installed
 * on this server, falls back to rendering the identical HTML/CSS as a
 * browser print-CSS page (mirrors `_apps/service-plans/print.php`'s own
 * `window.print()` fallback pattern) so the feature degrades gracefully
 * rather than 500ing.
 *
 * TRUST BOUNDARY: this file NEVER trusts `labels.php`'s own working-out —
 * every option is re-derived from raw `$_POST` and re-validated against
 * the same allow-lists/caps `AssetRegister` exposes as public constants,
 * exactly as if the POST had come from a hand-crafted request with no
 * preview behind it at all:
 *   - `preset`   must be a real `AssetRegister::LABEL_PRESETS` key, else
 *                falls back to 'L7160' (never trusted verbatim).
 *   - `fields[]` is intersected against `AssetRegister::LABEL_FIELDS` —
 *                anything else is silently dropped, never rendered.
 *   - `offset`   clamped to `[0, cellsPerSheet-1]` for the RESOLVED preset.
 *   - `copies`   clamped to `[1, AssetRegister::MAX_LABEL_COPIES]`.
 *   - `assetIds[]` is resolved via `AssetRegister::assetsForLabels()`,
 *                which is itself site-scoped — an id from another tenant,
 *                or a deleted/non-existent asset, simply never appears in
 *                the result, regardless of what was posted.
 *   - The resulting TOTAL label count (`offset + count($assets) × copies`)
 *                is checked against `AssetRegister::MAX_TOTAL_LABELS` and
 *                the request is REJECTED (not silently truncated) if it
 *                would exceed that — an OOM/DoS guard against a request
 *                asking dompdf to lay out an unbounded number of
 *                absolutely-positioned cells across an unbounded number of
 *                sheets. Truncating silently was considered and rejected:
 *                a manager who asked for 900 labels and silently got 500
 *                without being told would print an incomplete run without
 *                realising it.
 *
 * QR PAYLOAD: every QR code embedded in the output encodes
 * `AssetRegister::labelPublicUrl()`'s result — ALWAYS our own request host
 * plus the asset's own stored `publicToken`, NEVER anything derived from
 * request input beyond the asset's own database row. See that method's
 * own doc (and class header point 9) for the full guarantee.
 *
 * GATE ORDER: CSRF FIRST, then the manager gate — mirrors
 * `owners-save.php`/`license-action.php`'s established order for this
 * app's dedicated POST-only mutating endpoints (unlike `save.php`, which
 * checks the manager gate first — both orders appear in this app; this
 * file follows the more common owners-save.php/license-action.php one).
 *
 * OUTPUT FILE LIFECYCLE: `Pdf::create()` writes the PDF to
 * `_uploads/assets/labels/` (outside the webroot; this file ensures that
 * directory exists via `mkdir(…, 0755, true)` before rendering) with a
 * timestamp+random filename so concurrent requests can never collide. The
 * file is streamed via `Content-Disposition: attachment` (never linked)
 * and then DELETED immediately after streaming — unlike
 * `ExpensePdf`/`Giving`'s generated PDFs (which are the canonical stored
 * record for a claim/statement, referenced by a DB column), a label batch
 * has no such reference anywhere and would otherwise accumulate forever
 * under `_uploads/assets/labels/` with no cleanup path in this pass's
 * scope; deleting it once streamed keeps that directory from growing
 * unbounded.
 *
 * AUDIT: `AssetRegister::recordLabelPrint()` is called once the batch is
 * fully validated and about to be rendered — for BOTH the dompdf-success
 * path and the print-CSS fallback path, since either one represents a
 * genuine "a label run was generated for these assets" event that should
 * show up in each asset's own activity timeline.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/402
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AssetRegister;
use Portal\Core\Auth;
use Portal\Core\Pdf;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

// 🔐 CSRF FIRST — before any side-effect, per house convention (mirrors
// owners-save.php/license-action.php's order for this app's dedicated
// POST-only mutating endpoints).
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /assets/labels');
    exit();
}

// 🛡️ Manager gate — admins or the asset_manager role only. See file header.
if (App::isAdmin() !== true && App::hasRole('asset_manager') !== true) {
    http_response_code(403);
    exit('Forbidden');
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

// -----------------------------------------------------------------------------
// 🧮 Re-validate EVERY option from scratch — see file header's TRUST
// BOUNDARY note. Nothing below assumes labels.php's own preview did this
// correctly; a hand-crafted POST with no preview at all must be validated
// identically.
// -----------------------------------------------------------------------------
$postedAssetIds = array_map('intval', (array) ($_POST['assetIds'] ?? []));

$presetKey = (string) ($_POST['preset'] ?? '');
if (array_key_exists($presetKey, AssetRegister::LABEL_PRESETS) === false) {
    $presetKey = 'L7160';
}
$preset = AssetRegister::LABEL_PRESETS[$presetKey];
$cellsPerSheet = $preset['cols'] * $preset['rows'];

$fields = array_values(array_intersect((array) ($_POST['fields'] ?? []), AssetRegister::LABEL_FIELDS));

$qrOn = (string) ($_POST['qr'] ?? '0') === '1';

$offset = (int) ($_POST['offset'] ?? 0);
$offset = max(0, min($offset, $cellsPerSheet - 1));

$copies = (int) ($_POST['copies'] ?? 1);
$copies = max(1, min(AssetRegister::MAX_LABEL_COPIES, $copies));

// 🔒 Site-scoped resolution — ids from another tenant, or a deleted/
// non-existent asset, simply never appear in the result (see
// assetsForLabels()'s own doc); NOT an error on its own.
$assets = AssetRegister::assetsForLabels($siteId, $postedAssetIds);
if (count($assets) === 0) {
    $_SESSION['flash_msg']  = 'No matching assets were selected — pick at least one asset and try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /assets/labels');
    exit();
}

// 🛡️ OOM/DoS guard — REJECT, don't silently truncate. See file header.
$totalLabels = $offset + (count($assets) * $copies);
if ($totalLabels > AssetRegister::MAX_TOTAL_LABELS) {
    $_SESSION['flash_msg']  = 'That would print ' . $totalLabels . ' labels, which is more than the '
        . AssetRegister::MAX_TOTAL_LABELS . '-label limit per batch. Reduce the number of assets, '
        . 'copies, or start offset and try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /assets/labels');
    exit();
}

// -----------------------------------------------------------------------------
// 🖨️ Build the sheet set — SAME call labels.php's preview makes (see file
// header). $sheets['html']/['css'] are already fully HTML-escaped
// field-by-field inside buildLabelSheets()/renderLabelCellInner() — no
// further escaping needed here.
// -----------------------------------------------------------------------------
$sheets = AssetRegister::buildLabelSheets($assets, $presetKey, $fields, $qrOn, $offset, $copies);

// 📜 Audit — one row per asset actually included in the batch (post
// site-scoping), for BOTH the PDF-success and print-CSS-fallback paths
// below. See file header's AUDIT note.
AssetRegister::recordLabelPrint($siteId, array_column($assets, 'assetID'), $presetKey, $userId);

// -----------------------------------------------------------------------------
// 📁 Ensure the output directory exists (outside the webroot) before
// attempting to render — Pdf::create() also does this internally, but the
// design calls for this file to guarantee it independently too.
// -----------------------------------------------------------------------------
$labelsDir = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'labels';
if (is_dir($labelsDir) === false) {
    mkdir($labelsDir, 0755, true);
}

$pdfHtml = '<!doctype html><html><head><meta charset="utf-8"><title>Asset labels</title>'
    . '<style>' . $sheets['css'] . '</style>'
    . '</head><body>' . $sheets['html'] . '</body></html>';

// 🎲 Timestamp + random suffix — never collides across concurrent
// requests, and carries no guessable/enumerable identifier.
$pdfFilename = 'labels_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
$pdfPath     = $labelsDir . DIRECTORY_SEPARATOR . $pdfFilename;

$generatedPath = Pdf::create($pdfHtml, $pdfPath);

if ($generatedPath !== false) {
    // ✅ dompdf available — stream the PDF, then delete the on-disk copy
    // (see file header's OUTPUT FILE LIFECYCLE note).
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="asset-labels-' . addcslashes($presetKey, '"') . '.pdf"');
    header('Content-Length: ' . (string) filesize($generatedPath));
    header('Cache-Control: no-cache, must-revalidate');
    readfile($generatedPath);
    @unlink($generatedPath);
    exit();
}

// -----------------------------------------------------------------------------
// 🛟 FALLBACK — dompdf isn't installed (Pdf::create() already logged
// NOT_INSTALLED via Logger::errorPlatform()). Render the SAME HTML/CSS as
// a browser print-CSS page instead of 500ing — mirrors
// service-plans/print.php's window.print() pattern.
// -----------------------------------------------------------------------------
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Asset labels</title>
<style><?php echo $sheets['css']; ?></style>
<style>
@media screen { .print-controls { position: fixed; top: 1rem; right: 1rem; z-index: 100; font-family: system-ui, sans-serif; } }
@media print { .print-controls { display: none; } }
</style>
</head>
<body>
<div class="print-controls">
    <button onclick="window.print()" style="padding: 0.5rem 1rem; background:#198754; color:#fff; border:none; border-radius:0.375rem; cursor:pointer;">
        Print
    </button>
    <a href="/assets/labels" style="margin-left:0.5rem;">Back to designer</a>
    <p style="max-width:220px; font-size:0.8rem; color:#555;">
        A PDF library isn't installed on this server, so use your browser's print dialog
        (set paper size to A4 and margins to "None") instead.
    </p>
</div>
<?php echo $sheets['html']; ?>
</body>
</html>
