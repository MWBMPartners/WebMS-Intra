<?php
// Path: _apps/assets/api/list.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker API — List Assets 📦📋 (#406, Phase 2 Pass 4)
 * -----------------------------------------------------------------------------
 * Returns a paginated JSON list of assets for the current site via
 * `AssetRegister::listForSite()`.
 *
 *   GET /api/assets/list
 *   GET /api/v1/assets
 *   ?status=in-service&categoryID=3&assetKind=physical&search=projector&page=1&limit=20
 *
 * 🔒 CONFIDENTIAL ASSETS are kept NO WIDER than the HTML register
 * (`_apps/assets/index.php`): only a logged-in SESSION admin/asset_manager
 * sees them here. A bearer key never does. `ApiAuth` is dual-mode, so
 * `requireRead()` alone passes for ANY logged-in user regardless of role —
 * the API must NOT become a broader exposure path for safeguarding-
 * sensitive assets than the UI, so the confidential gate below is applied
 * explicitly on top of the scope check.
 *
 * `licenseKey`/`publicToken` are stripped from every row regardless
 * (`ApiResponse::filterSensitive()`) — confidentiality only ever gates
 * WHICH rows are returned, never which COLUMNS are.
 *
 * @package   Portal\API\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/406
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiAuth;
use Portal\Core\ApiResponse;
use Portal\Core\App;
use Portal\Core\AssetRegister;
use Portal\Core\Site;

ApiAuth::requireRead('assets:read');

$siteId = Site::id();
$page   = max(1, (int) ($_GET['page'] ?? 1));
$limit  = min(100, max(1, (int) ($_GET['limit'] ?? $_GET['perPage'] ?? 20)));

// 📋 Filters — same allow-list AssetRegister::listForSite() itself accepts;
// anything else in the querystring is simply ignored (no error) so an
// integration can pass extra diagnostic params without breaking.
$filters = [];
$status = trim((string) ($_GET['status'] ?? ''));
if ($status !== '') {
    $filters['status'] = $status;
}
$categoryId = (int) ($_GET['categoryID'] ?? 0);
if ($categoryId > 0) {
    $filters['categoryID'] = $categoryId;
}
$assetKind = trim((string) ($_GET['assetKind'] ?? ''));
if ($assetKind !== '') {
    $filters['assetKind'] = $assetKind;
}
$search = trim((string) ($_GET['search'] ?? ''));
if ($search !== '') {
    $filters['search'] = mb_substr($search, 0, 255);
}

// 🔒 Confidential rows only for a logged-in SESSION admin/asset_manager —
// NEVER a bearer key, and never an ordinary logged-in user (see file
// header). Matches index.php's own $canManage gate exactly.
$canSeeConfidential = ApiAuth::isBearer() === false
    && (App::isAdmin() === true || App::hasRole('asset_manager') === true);
$allMatching = AssetRegister::listForSite($siteId, $filters, $canSeeConfidential);

$totalItems = count($allMatching);
$totalPages = max(1, (int) ceil($totalItems / $limit));
$offset     = ($page - 1) * $limit;
$pageItems  = array_slice($allMatching, $offset, $limit);

// 🔒 licenseKey never appears in listForSite()'s own SELECT, but
// publicToken DOES — strip it (+ licenseKey defensively) on every row
// before it ever reaches json_encode(), same choke-point as detail.php.
$assets = array_map(
    static fn (array $row): array => ApiResponse::filterSensitive($row, ['licenseKey', 'publicToken']),
    $pageItems
);

ApiResponse::success([
    'assets' => $assets,
    'pagination' => [
        'page'       => $page,
        'limit'      => $limit,
        'totalItems' => $totalItems,
        'totalPages' => $totalPages,
        'hasNext'    => $page < $totalPages,
        'hasPrev'    => $page > 1,
    ],
]);
