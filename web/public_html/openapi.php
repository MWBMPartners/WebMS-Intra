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
 *   isProtected = 0  ON PURPOSE: the router's sign-in redirect would turn
 *                    away a program sending an API key. Who may read this is
 *                    decided by Portal\Core\ApiDescriptionAccess (D-215, #1131):
 *                    a signed-in administrator or a valid `Bearer wbms_` key;
 *                    a bad key gets 401, anybody else the 404 page. (On a
 *                    pre-release copy the staff gate answers first.)
 *
 * The static `openapi.json` file is deliberately deleted so Apache's
 * "skip rewrite if static file exists" check falls through to index.php,
 * which dispatches here via the Router.
 *
 * @package   Portal\App
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/307
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\PublisherWebsite;
use Portal\Core\ApiDescriptionAccess;
use Portal\Core\Site;

// 🔐 D-215 (#1131): administrators and API key holders only. Before anything
//    else is read or printed.
ApiDescriptionAccess::enforce(false);

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
    header('Cache-Control: private, no-store');
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
    header('Cache-Control: private, no-store');
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

// 📞 contact.name = the installation's publisher; contact.url = the
//    publisher's website, from the installation's own setting, or NO link.
//
//    #863 (D-160): the link used to be the product's own source repository,
//    fixed in api-spec.json, so an installation whose publisher was another
//    company showed that company's name linking to somebody else's site. The
//    owner decided the link follows a setting given during installation and
//    is left out when none is set; no address is built in. The file no longer
//    carries one, and any `url` it might carry again is removed here rather
//    than served, so the served description can only ever link to what this
//    installation chose.
//
//    Read through App::settings(), the same reading as the publisher's NAME
//    above, so the name and the link always come from the same place.
//    PublisherWebsite::usable() judges the stored text again on every read,
//    because the general settings editor can store anything in the row.
if (isset($spec['info']['contact']) === false || is_array($spec['info']['contact']) === false) {
    $spec['info']['contact'] = [];
}
$spec['info']['contact']['name'] = $productPublisher;
$publisherWebsite = PublisherWebsite::usable(App::settings(PublisherWebsite::SETTING));
if ($publisherWebsite !== '') {
    $spec['info']['contact']['url'] = $publisherWebsite;
} else {
    unset($spec['info']['contact']['url']);
}

// 📡 Emit.
header('Content-Type: application/json; charset=utf-8');
// 🚫 The answer now depends on WHO asked. `public` would let a shared cache (a
//    hosting company's proxy, a content delivery service) store an
//    administrator's copy and hand it to a stranger. This line also REPLACES
//    the no-cache header the session start sent, which is how the old
//    `public` line won.
header('Cache-Control: private, no-store');
echo json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
