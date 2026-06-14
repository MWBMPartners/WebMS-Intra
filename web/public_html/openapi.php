<?php
// Path: public_html/openapi.php
/**
 * -----------------------------------------------------------------------------
 * OpenAPI Spec Controller — Brand-aware /openapi.json 📋 (#307)
 * -----------------------------------------------------------------------------
 * Replaces the previously-static `openapi.json` with a brand-aware controller
 * so the Swagger UI title + contact info reflect the active sub-brand (e.g.
 * "ChurchMS REST API" on a church install, "WebMS Intra REST API" on a
 * generic install). Mirror of the manifest.php pattern shipped in PR #297.
 *
 * The full spec body — paths, schemas, tags, every endpoint definition — is
 * loaded from `web/_core/api-spec.json` (out of webroot). Only the `info`
 * block is rewritten at request time. Everything else is byte-identical to
 * the static spec, so Swagger UI + curl-based integration test scripts that
 * pinned to the old URL keep working.
 *
 * Routed via tblRoutes:
 *   routeKey   = 'openapi.json'
 *   targetFile = 'openapi.php'
 *   isProtected = 0  (public — API docs viewers need it before login)
 *
 * The static `openapi.json` file is deliberately deleted so Apache's
 * "skip rewrite if static file exists" check falls through to index.php,
 * which dispatches here via the Router.
 *
 * @package   Portal\App
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/307
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Site;

// 🏷️ Resolve the brand-aware fields.
$productName      = Site::productName();
$productPublisher = Site::productPublisher();

// 📋 Load the static spec template (out of webroot — only this controller
//    can reach it). The file is plain JSON and read-only here; the file
//    on disk is the canonical source-of-truth for paths + schemas.
$specPath = PORTAL_CORE . DIRECTORY_SEPARATOR . 'api-spec.json';
$raw      = is_readable($specPath) === true ? file_get_contents($specPath) : false;

if ($raw === false) {
    // Spec missing — emit a minimal valid OpenAPI doc so Swagger UI
    // shows an empty-but-valid page rather than a hard 500.
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'openapi' => '3.0.3',
        'info'    => [
            'title'       => $productName . ' REST API',
            'description' => 'Spec template not found at expected path.',
            'version'     => '0.0.0',
        ],
        'paths' => new \stdClass(),
    ]);
    exit();
}

$spec = json_decode($raw, true);
if (is_array($spec) === false) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Spec template is not valid JSON']);
    exit();
}

// 🏷️ Rewrite the info block — leave description, version, license,
//    and everything else alone. Only the fields that name the product
//    or its publisher are overridden.
if (isset($spec['info']) === false || is_array($spec['info']) === false) {
    $spec['info'] = [];
}
$spec['info']['title'] = $productName . ' REST API';

// 📞 contact.name = active publisher; contact.url = product's own repo URL.
//    The repo URL stays as the WebMS-Intra GitHub address (the codebase
//    identity, regardless of brand) — see DEV_NOTES "Where the brand is NOT
//    applied" for the same rationale used for the `[WebMS-Intra]` log prefix.
if (isset($spec['info']['contact']) === false || is_array($spec['info']['contact']) === false) {
    $spec['info']['contact'] = [];
}
$spec['info']['contact']['name'] = $productPublisher;

// 📡 Emit.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
