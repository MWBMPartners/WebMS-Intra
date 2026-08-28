<?php
// Path: _apps/venues/api/availability.php
/**
 * -----------------------------------------------------------------------------
 * Venue Bookings API — Raw availability overlay 🔌
 * -----------------------------------------------------------------------------
 * `GET /api/venues/availability` — the raw §8.1 overlay rows behind the
 * calendar's venue strip (02-app-design.md §5.1/§1.2). Read-only, same
 * data the viewer schedule shows minus costs.
 *
 * ApiRouter convention path, gated by `api.venues.availability.enabled`
 * (ApiRouter checks it before ever requiring this file — nothing
 * registered in tblRoutes). See `api/check.php`'s header for the shared
 * ApiRouter/#373 notes.
 *
 * Params: `from`, `to` (`Y-m-d`, inclusive, `to >= from`, range ≤ 400 days
 * else 400), optional `venueID`. UNLIKE `classifyEventCoverage()` (which
 * re-validates its own venueID internally), `Venues::availabilityForRange()`
 * takes venueID as a raw SQL filter with NO ownership check of its own —
 * so THIS handler performs the site-scope validation itself (security
 * item 1/2: IDOR) via `Venues::getVenue()` before ever calling it.
 *
 * Response: `{days: {"YYYY-MM-DD": [row, ...]}}` — the exact shape
 * `Venues::availabilityForRange()` returns. NO cost columns: enforced by
 * that method's own SELECT column list, which simply never selects
 * `costPence` (security item 12) — this handler adds no columns of its own.
 *
 * @package   Portal\Venues\Api
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/429
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiResponse;
use Portal\Core\Site;
use Portal\Core\Venues;

// 🔐 Session OR bearer — read-only, no CSRF surface (02 §12 item 6).
ApiResponse::requireAuth();

$siteId = Site::id();

$from = trim((string) ($_GET['from'] ?? ''));
$to   = trim((string) ($_GET['to'] ?? ''));

/** Strict `Y-m-d` validator — regex + checkdate(), not strtotime()'s laxer parsing. */
$isValidDate = static function (string $s): bool {
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) !== 1) {
        return false;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
};

if ($isValidDate($from) === false || $isValidDate($to) === false || $to < $from) {
    ApiResponse::error('invalid-range', 400);
}

$diffDays = (int) ((new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days);
if ($diffDays > 400) {
    ApiResponse::error('invalid-range', 400);
}

$venueIdParam = (int) ($_GET['venueID'] ?? 0);
$venueId = null;
if ($venueIdParam > 0) {
    // 🛡️ Explicit site-scope check — availabilityForRange() does not do
    // its own venue-ownership validation (unlike classifyEventCoverage()).
    if (Venues::getVenue($venueIdParam, $siteId) === null) {
        ApiResponse::error('invalid-range', 400);
    }
    $venueId = $venueIdParam;
}

$days = Venues::availabilityForRange($siteId, $venueId, $from, $to);

ApiResponse::success(['days' => $days]);
