<?php
// Path: _apps/service-plans/api/hymn-search.php
/**
 * -----------------------------------------------------------------------------
 * Service Plans — hymn/song picker search endpoint (gap #128 residual) 🔎
 * -----------------------------------------------------------------------------
 * ApiRouter convention path: `_apps/service-plans/api/hymn-search.php`,
 * gated by `api.service-plans.hymn-search.enabled` (seeded 'true' by
 * migration 178 — CLAUDE.md "ApiRouter routing trap": NOT registered in
 * tblRoutes, the settings flag is the only gate). Session-authenticated
 * (this is an internal editor typeahead, not part of the public v1 REST
 * facade) and deliberately never accepts a siteID from the client — always
 * `Site::id()` from the current session.
 *
 * GET /api/service-plans/hymn-search?q=…&limit=…
 * -> {"results":[{"source":"hymnal"|"song"|"remote", …}], "remoteAttempted":bool}
 *
 * Results are grouped by source: the local hymnal index (Tier 1), the
 * existing song library (already-canonical tblSongs rows), and — only when
 * `hymns.remote.enabled` is on — the configured remote provider (Tier 2).
 * A remote failure of any kind degrades silently to local-only results
 * (`Portal\Core\Hymnal::searchRemote()` never throws).
 *
 * @package   Portal\ServicePlans
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/128
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiResponse;
use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Hymnal;
use Portal\Core\Site;

Auth::ensureSession();
ApiResponse::requireAuth();

$q     = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$limit = max(1, min(25, (int) ($_GET['limit'] ?? 15)));

if ($q === '') {
    ApiResponse::success(['results' => [], 'remoteAttempted' => false]);
}

$siteId = Site::id();

// 🔎 Tier 1 — local hymnal index.
$hymnalResults = Hymnal::searchLocal($siteId, $q, null, $limit);

// 🔎 Existing song library — already-canonical tblSongs rows (title/author
// match), so picking one links directly with no promotion needed.
$songResults = [];
$db   = App::db();
$stmt = $db->prepare(
    'SELECT songID, title, author, ccliNumber, hymnalCode, hymnNumber '
    . 'FROM tblSongs WHERE siteID = ? AND isActive = 1 AND (title LIKE ? OR author LIKE ?) '
    . 'ORDER BY title LIMIT ?'
);
if ($stmt !== false) {
    $needle = '%' . $q . '%';
    $stmt->bind_param('issi', $siteId, $needle, $needle, $limit);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $r['source'] = 'song';
        $songResults[] = $r;
    }
    $stmt->close();
}

// 🌐 Tier 2 — remote, only when enabled. Never blocks/throws.
$remoteAttempted = (string) App::settings('hymns.remote.enabled') === 'true';
$remoteResults   = $remoteAttempted === true ? Hymnal::searchRemote($siteId, $q, $limit) : [];

ApiResponse::success([
    'results'         => array_values(array_merge($hymnalResults, $songResults, $remoteResults)),
    'remoteAttempted' => $remoteAttempted,
]);
