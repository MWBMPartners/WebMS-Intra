<?php
// Path: _apps/admin/reports/builder/preview.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Reports Builder: preview AJAX endpoint 🔎📊 (#156)
 * -----------------------------------------------------------------------------
 * Session-authed AJAX POST — deliberately NOT under `api/*` (the same
 * pattern as `_apps/geo/lookup.php` / `w3w-suggest.php`, #456). CSRF'd,
 * JSON in / JSON out. Builds the definition array from the posted
 * `definition` JSON field (byte-capped + depth-capped BEFORE decode —
 * ReportBuilder::decodeDefinitionJson()), compiles it, and runs the FIRST
 * 25 rows only. Never echoes SQL, never echoes the thrown exception's
 * class or trace — only a user-safe message string.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/156
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AppRegistry;
use Portal\Core\Auth;
use Portal\Core\ReportBuilder;
use Portal\Core\Site;

Auth::ensureSession();

header('Content-Type: application/json; charset=utf-8');

/** Small helper — always the exact response shape the JS expects. */
$fail = static function (string $message, int $httpCode = 400): never {
    http_response_code($httpCode);
    echo json_encode(['status' => 'error', 'error' => $message]);
    exit();
};

if (Auth::check() === false || App::isAdmin() !== true) {
    $fail('Unauthorized', 403);
}

if (AppRegistry::isEnabled('reports') === false) {
    $fail('Reports is disabled for this site.', 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $fail('Method not allowed', 405);
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $fail('Invalid or expired form token.', 419);
}

// 🔄 Auth::verifyCsrf() rotated the session token above — hand the fresh
// one back so the JS can keep making further Preview calls without a
// full page reload (event-hub-upload.js syncCsrf() precedent).
$freshCsrf = Auth::csrfToken();

$siteId = Site::id();

try {
    $definition = ReportBuilder::decodeDefinitionJson((string) ($_POST['definition'] ?? ''));
    $compiled   = ReportBuilder::compile($definition, $siteId);
    $result     = ReportBuilder::run($compiled, 25, 0);

    echo json_encode([
        'status'  => 'ok',
        'csrf'    => $freshCsrf,
        'columns' => $compiled['columns'],
        'rows'    => $result['rows'],
        'hasMore' => $result['hasMore'],
    ]);
} catch (\InvalidArgumentException $e) {
    echo json_encode(['status' => 'error', 'csrf' => $freshCsrf, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    echo json_encode(['status' => 'error', 'csrf' => $freshCsrf, 'error' => 'An unexpected error occurred while previewing this report.']);
}
exit();
