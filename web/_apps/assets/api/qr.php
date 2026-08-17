<?php
// Path: _apps/assets/api/qr.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker API — Label Image 📦🔳 (#406, Phase 2 Pass 4)
 * -----------------------------------------------------------------------------
 * Streams a single label image (QR or barcode) for one asset. Resolves the
 * migration-159 orphan `api.assets.qr.enabled` seed — that flag existed with
 * no matching handler until this pass.
 *
 *   GET /api/assets/qr?id=N&format=png|svg&symbology=qr|code128|ean13|upca|itf14
 *
 *   id          required — the asset's id (site-scoped; cross-site/missing
 *               → 404, same as detail.php).
 *   format      optional, "svg" (default) | "png" (falls back to svg if the
 *               gd extension isn't loaded — Qr::generate()/Barcode::generate()
 *               handle that internally).
 *   symbology   optional — defaults to the asset's OWN `labelSymbology`
 *               column. `qr+code128` (a "print both" combo, not a single
 *               renderable image) resolves to `qr` when it's the DEFAULT
 *               (i.e. the asset's stored preference), but 400s if a caller
 *               explicitly asks for it — an integration should request `qr`
 *               and `code128` as two separate calls instead.
 *
 * NEVER FATAL for a missing barcode source value — same house convention as
 * `AssetRegister::buildLabelSheets()` (labels.php's PDF renderer): a Code
 * 128 request with no `assetTagCode`, or a GS1 (EAN-13/UPC-A/ITF-14)
 * request with no matching PRIMARY `tblAssetIdentifiers` row, silently
 * falls back to the QR image + an `X-Label-Fallback` response header
 * explaining why, rather than a 4xx/5xx.
 *
 * NO ASSET DATA LEAKS beyond what the physical label itself would show: the
 * QR encodes ONLY the public lost-and-found URL (`AssetRegister::
 * labelPublicUrl()` — same "our own host, never attacker-controlled" value
 * `labels.php`/`labels-pdf.php` already encode), and a barcode encodes only
 * the asset's own tag code or a single GS1 identifier value. No JSON, no
 * asset name/serial/owner/financial fields, no `licenseKey`/`publicToken`.
 *
 * @package   Portal\API\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/406
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/159
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiAuth;
use Portal\Core\ApiResponse;
use Portal\Core\App;
use Portal\Core\AssetRegister;
use Portal\Core\Barcode;
use Portal\Core\Qr;
use Portal\Core\Site;

// api.assets.qr.enabled is already gated per-site by
// ApiRouter::resolveEnabledFlag() before this handler ever runs (#323 Phase
// 2) — no second App::settings() check needed here (that would read the
// frozen host-site snapshot and could wrongly 403 a valid bearer request
// pinned to a DIFFERENT site — same reasoning as noticeboard/api/qr.php).
ApiAuth::requireRead('assets:read');

$siteId  = Site::id();
$assetId = (int) ($_GET['id'] ?? 0);
if ($assetId <= 0) {
    ApiResponse::error('id is required', 400);
}

// 🔒 Site-scoped, non-deleted — cross-tenant probe 404s, never confirms
// another site's asset exists (same contract as detail.php).
$asset = AssetRegister::get($assetId);
if ($asset === null) {
    ApiResponse::error('Asset not found', 404);
}

// 🔒 A confidential asset's label image is gated the SAME way its detail is
// (admin/asset_manager/responsible SESSION user only; never a bearer key) —
// so a non-privileged caller can't even confirm a confidential asset exists
// by requesting its label. Uniform 404, matching detail.php/item.php.
$privileged = ApiAuth::isBearer() === false
    && (App::isAdmin() === true
        || App::hasRole('asset_manager') === true
        || AssetRegister::isResponsibleFor($assetId) === true);
if ((int) ($asset['isConfidential'] ?? 0) === 1 && $privileged === false) {
    ApiResponse::error('Asset not found', 404);
}

// 🖼️ format
$format = strtolower(trim((string) ($_GET['format'] ?? 'svg')));
if (in_array($format, ['svg', 'png'], true) === false) {
    ApiResponse::error("format must be 'svg' or 'png'", 400);
}

// 🏷️ symbology — explicit request vs the asset's own stored default. See
// file header for the qr+code128 explicit-vs-default distinction.
$requestedSymbology = trim((string) ($_GET['symbology'] ?? ''));
if ($requestedSymbology !== '') {
    if ($requestedSymbology === 'qr+code128') {
        ApiResponse::error(
            "symbology 'qr+code128' is not a single renderable image — request 'qr' and 'code128' separately",
            400
        );
    }
    if (in_array($requestedSymbology, AssetRegister::LABEL_SYMBOLOGIES, true) === false) {
        ApiResponse::error('Unsupported symbology', 400);
    }
    $symbology = $requestedSymbology;
} else {
    // 🛡️ Defensive fallback to 'qr' for anything not recognised — should
    // never happen for a genuine ENUM column value, but mirrors
    // buildLabelSheets()'s own belt-and-braces re-check.
    $stored = (string) ($asset['labelSymbology'] ?? 'qr');
    $symbology = in_array($stored, AssetRegister::LABEL_SYMBOLOGIES, true) === true ? $stored : 'qr';
    if ($symbology === 'qr+code128') {
        $symbology = 'qr'; // 🔁 "print both" combo → the QR half by default
    }
}

/**
 * Render the universal QR fallback (the asset's public lost-and-found URL)
 * — used both for symbology='qr' itself AND as the safety net for any
 * barcode symbology whose source value turns out missing/invalid below.
 *
 * @return array{mime: string, bytes: string}
 */
$renderQr = static function () use ($asset, $format): array {
    $url = AssetRegister::labelPublicUrl($asset);
    if ($url === '') {
        // 🛡️ Defence in depth only — labelPublicUrl() rejects a
        // malformed publicToken shape, which should never happen for a
        // genuine DB-generated token (AssetRegister::generatePublicToken()).
        ApiResponse::error('Could not generate the label image', 500);
    }
    return Qr::generate($url, ['format' => $format, 'size' => 256, 'ecc' => 'M']);
};

if ($symbology === 'qr') {
    $img = $renderQr();
} else {
    // 🏷️ Barcode symbologies — resolve the source value the SAME way
    // buildLabelSheets() does: Code 128 encodes the asset's own tag code
    // (falling back to a synthetic 'AST-{id}' code when unset, same as
    // that method); the GS1 symbologies (EAN-13/UPC-A/ITF-14) encode the
    // asset's PRIMARY matching `tblAssetIdentifiers` row, resolved via the
    // SAME `assetsForLabels()` lookup labels.php/labels-pdf.php already
    // use — reusing it here means the value can never drift from what a
    // printed sheet would show for this asset.
    if ($symbology === 'code128') {
        $value = (string) ($asset['assetTagCode'] ?? '');
        if ($value === '') {
            $value = 'AST-' . $assetId;
        }
    } else {
        $labelRows = AssetRegister::assetsForLabels($siteId, [$assetId]);
        $value = (string) ($labelRows[0]['barcodeIdentifierValue'] ?? '');
    }

    $barcode = $value !== ''
        ? Barcode::generate($symbology, $value, ['format' => $format, 'height' => 200, 'moduleWidth' => 3, 'quietModules' => 6])
        : ['valid' => false];

    if (($barcode['valid'] ?? false) === true && (string) ($barcode['bytes'] ?? '') !== '') {
        $img = $barcode;
    } else {
        // 🔁 Never fatal (file header) — fall back to the QR image and
        // tell the caller why via a response header, not the image body.
        $img = $renderQr();
        header('X-Label-Fallback: qr');
        header('X-Label-Fallback-Reason: ' . rawurlencode('No valid ' . strtoupper($symbology) . ' source value for this asset'));
    }
}

header('Content-Type: ' . $img['mime']);
header('Cache-Control: private, max-age=300'); // 🔒 "private" — a confidential asset's label must never be shared-cached
header('X-Content-Type-Options: nosniff');
echo $img['bytes'];
exit();
