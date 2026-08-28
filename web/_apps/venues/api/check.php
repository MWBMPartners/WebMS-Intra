<?php
// Path: _apps/venues/api/check.php
/**
 * -----------------------------------------------------------------------------
 * Venue Bookings API — "Is it booked?" event coverage check 🔌
 * -----------------------------------------------------------------------------
 * `GET /api/venues/check` — Surface A's backend (02-app-design.md §5.3):
 * the calendar event form's inline "is it booked?" panel calls this while
 * a leader is drafting an event, to warn BEFORE saving.
 *
 * ApiRouter convention path (`_apps/venues/api/check.php`), gated by the
 * `api.venues.check.enabled` settings flag ApiRouter itself checks before
 * ever requiring this file — nothing is registered in `tblRoutes` for it
 * (the #372 dead-route lesson: Router never consults tblRoutes for `api/*`
 * paths). `global $mysqli, $SETTINGS;` are already imported by
 * `ApiRouter::dispatch()` (#373) — this handler doesn't need them itself,
 * using `App::db()`/`Settings::get()` throughout instead.
 *
 * Params: `start`, `end` (both `Y-m-d\TH:i` wall-clock — the exact shape a
 * `datetime-local` input posts, and what `calendar/manage/save.php` stores
 * verbatim into `tblEvents`), `tz` (IANA string, default `Europe/London`),
 * `venueID` (optional int), `roomID` (optional int, #436). WALL-CLOCK RULE
 * (see `Venues.php`'s own class header): these strings are passed straight
 * through to `Venues::classifyEventCoverage()` as the pseudo-event's
 * startDateTime/endDateTime/timezone — never converted to UTC here or
 * anywhere downstream.
 *
 * `venueID` resolution: an explicit positive `venueID` is used as-is;
 * `classifyEventCoverage()` re-validates it against `Site::id()` itself
 * (via `Venues::getVenue()`) and returns the dormant `venue-missing`
 * sentinel (HTTP 200, not an error) for anything that doesn't resolve —
 * so this handler does not duplicate that site-scope check. Omitted/zero
 * falls back to the `venues.calendar_default_venue` setting, which is
 * '0' (⇒ also dormant) until an admin configures one.
 *
 * `roomID` resolution (#436): an explicit positive `roomID` is passed
 * straight through, unvalidated here. `classifyEventCoverage()`
 * re-validates it against the resolved venue+site itself (via the private
 * `validateRoomForVenue()`) and silently degrades to venue-level coverage
 * for anything that doesn't resolve — same "no existence oracle"
 * philosophy as the venueID paragraph above, so this handler does not
 * duplicate that check either. Omitted/zero ⇒ venue-level coverage
 * (today's exact behaviour).
 *
 * @package   Portal\Venues\Api
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/429
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/436
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ApiResponse;
use Portal\Core\Settings;
use Portal\Core\Venues;

// 🔐 Session OR bearer — read-only, no CSRF surface (02 §12 item 6).
ApiResponse::requireAuth();

/**
 * Strict `Y-m-d\TH:i` validator (the exact `datetime-local` input shape) —
 * a hand-rolled regex + checkdate()/range check rather than
 * DateTime::createFromFormat(), which silently accepts several malformed
 * variants (overflowing hour/minute components, trailing garbage) that
 * would otherwise reach DateTimeImmutable inside classifyEventCoverage().
 */
$isValidLocalDateTime = static function (string $s): bool {
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/', $s, $m) !== 1) {
        return false;
    }
    if (checkdate((int) $m[2], (int) $m[3], (int) $m[1]) === false) {
        return false;
    }
    return (int) $m[4] <= 23 && (int) $m[5] <= 59;
};

$startRaw = trim((string) ($_GET['start'] ?? ''));
$endRaw   = trim((string) ($_GET['end'] ?? ''));
$tzRaw    = trim((string) ($_GET['tz'] ?? ''));
if ($tzRaw === '') {
    $tzRaw = 'Europe/London';
}

if ($isValidLocalDateTime($startRaw) === false) {
    ApiResponse::error('invalid-range', 400);
}
if ($endRaw !== '' && $isValidLocalDateTime($endRaw) === false) {
    ApiResponse::error('invalid-range', 400);
}
try {
    new \DateTimeZone($tzRaw);
} catch (\Throwable $e) {
    ApiResponse::error('invalid-range', 400);
}

$venueIdParam = (int) ($_GET['venueID'] ?? 0);
$venueId = $venueIdParam > 0 ? $venueIdParam : (int) Settings::get('venues.calendar_default_venue', '0');

// 🚪 #436 — optional room narrowing. Re-validated inside
// classifyEventCoverage() itself (site+venue scoped) — no duplicate check
// here, same philosophy as venueID above.
$roomIdParam = (int) ($_GET['roomID'] ?? 0);
$roomId = $roomIdParam > 0 ? $roomIdParam : null;

$event = [
    'startDateTime' => $startRaw,
    'endDateTime'   => $endRaw !== '' ? $endRaw : null,
    'timezone'      => $tzRaw,
];

$result = Venues::classifyEventCoverage($event, $venueId, $roomId);

ApiResponse::success($result);
