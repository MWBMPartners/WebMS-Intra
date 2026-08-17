<?php
// Path: _apps/tours/api/complete.php
/**
 * -----------------------------------------------------------------------------
 * Tours API — Mark a tour completed for the current user ✅
 * -----------------------------------------------------------------------------
 * POST { tourID } (+ csrf_token). Inserts a tblUserTours row (idempotent
 * via the uq_user_tour unique key). Called when the user finishes OR skips
 * a tour so it doesn't pester them again.
 *
 * 🚚 Relocated from the unreachable `_apps/api/tours/complete.php` (ApiRouter
 * never consults tblRoutes for api/* paths — it always resolves
 * `api/{appName}/{action}` straight to `_apps/{appName}/api/{action}.php`;
 * see .claude/CLAUDE.md → "ApiRouter routing trap") to this convention path,
 * gated by `api.tours.complete.enabled` (migration 163). Same bug class + fix
 * as the worship live-sync relocation in migration 158.
 *
 * @package   Portal\API
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/253
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiResponse;
use Portal\Core\App;
use Portal\Core\Auth;

ApiResponse::requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('POST required', 405);
}
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    ApiResponse::error('Invalid CSRF token', 403);
}

$db     = App::db();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$tourId = (int) ($_POST['tourID'] ?? 0);

if ($tourId <= 0) {
    ApiResponse::error('tourID required', 400);
}

try {
    $stmt = $db->prepare(
        'INSERT INTO tblUserTours (userID, tourID) VALUES (?, ?) '
        . 'ON DUPLICATE KEY UPDATE completedAt = completedAt'
    );
    if ($stmt === false) {
        ApiResponse::error('Database error', 500);
    }
    $stmt->bind_param('ii', $userId, $tourId);
    $stmt->execute();
    $stmt->close();
} catch (\Throwable $e) {
    ApiResponse::error('Could not record completion', 500);
}

ApiResponse::success(['completed' => true]);
