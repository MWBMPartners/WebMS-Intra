<?php
// Path: _apps/geo/w3w-suggest.php
/**
 * -----------------------------------------------------------------------------
 * Geo — what3words autosuggest AJAX proxy 🔤 (#456 Chunk A)
 * -----------------------------------------------------------------------------
 * Session-authed UI helper — deliberately outside `api/*` so ApiRouter, key
 * auth, and `api.*.enabled` gating are untouched (precedent: `qr.php`
 * utility route). The browser NEVER sees the what3words API key —
 * `What3Words::autosuggest()` is the only caller of the adapter.
 *
 * POST-only, CSRF-checked, session-authed. Per-session soft rate limit
 * (>=400ms between calls) guards against a fast-typing client hammering
 * the upstream API.
 *
 * @package   Portal\Geo
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/456
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\GeoLocation;
use Portal\Core\What3Words;

Auth::ensureSession();
Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'reason' => 'method_not_allowed']);
    exit;
}
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    echo json_encode(['ok' => false, 'reason' => 'csrf']);
    exit;
}

// 🔑 THE TOKEN WAS JUST SPENT (#1134). Auth::verifyCsrf() replaces the session's
//    one form token every time it accepts one (#768), and the page this answer
//    goes to goes on to post an ordinary form (Save). Every answer from here
//    on therefore carries the replacement as a top-level `csrfToken`, which the
//    page script hands to Portal.rememberCsrf() so every carrier on the page
//    is written. ONE closure makes every answer, so an exit added later cannot
//    forget it. The two answers ABOVE (wrong method, refused token) are given
//    before anything was spent, so they stay as they were. This endpoint stays
//    outside `api/*`, so it does not use ApiResponse's `meta.csrfToken`.
$answer = static function (array $body): never {
    $body['csrfToken'] = Auth::csrfToken();
    echo json_encode($body);
    exit;
};

if (What3Words::isConfigured() === false) {
    $answer(['ok' => false, 'reason' => 'disabled']);
}

// 🚦 Soft per-session rate limit — >=400ms between calls.
$now = microtime(true);
$last = (float) ($_SESSION['w3w_suggest_last'] ?? 0);
if (($now - $last) < 0.4) {
    $answer(['ok' => false, 'reason' => 'throttled']);
}
$_SESSION['w3w_suggest_last'] = $now;

$q = trim((string) ($_POST['q'] ?? ''));
if ($q === '') {
    $answer(['ok' => true, 'suggestions' => []]);
}

$focus = null;
$focusLat = $_POST['lat'] ?? null;
$focusLng = $_POST['lng'] ?? null;
if ($focusLat !== null && $focusLng !== null) {
    $coords = GeoLocation::validateCoords($focusLat, $focusLng);
    if ($coords !== null) {
        $focus = $coords;
    }
}

$suggestions = What3Words::autosuggest($q, $focus);

$answer(['ok' => true, 'suggestions' => $suggestions]);
