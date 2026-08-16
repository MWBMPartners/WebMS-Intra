<?php
// Path: _apps/assets/found-save.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Report Found Item (stub) 🔍
 * -----------------------------------------------------------------------------
 * PUBLIC POST handler shape for the "I found this" submission form shown
 * (as a disabled placeholder) on the public lost-and-found page
 * (_apps/assets/tag.php). The real handler — validating input, writing a
 * tblAssetFoundReports row, rate-limiting, optional captcha — is a later
 * Asset Tracker sub-issue.
 *
 * FOUNDATION-PASS STUB (#393). This file exists so the seeded PUBLIC
 * `assets/found-save` route (isProtected=0 — see migration 159) resolves
 * to a real, safe file rather than a 404, and so check_route_targets.py
 * stays green. It deliberately does NOT write to tblAssetFoundReports yet
 * — it just redirects back to the referring token page with nothing
 * persisted, so an early/misdirected POST can never corrupt data or leak
 * anything. No auth is required (mirrors the public form pattern in
 * _apps/salvation/card-save.php) since found-save is reachable by
 * anonymous visitors by design.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;

// 🔓 Public — no Auth::requireLogin(). Session is still started so a CSRF
// token / flash message can be set once the real form ships.
Auth::ensureSession();

// 🚧 No-op by design (see file header) — nothing is read from $_POST and
// nothing is written to the database in this foundation pass. Send the
// visitor back wherever they came from (falling back to the portal home)
// rather than rendering a confusing blank success page for a feature that
// isn't wired up yet.
// 🛡️ Referer is attacker-controlled and always a FULL URL (scheme+host+
// path) in a real browser request, never a bare path — so it must be
// parsed and checked against the CURRENT host before reuse. Redirecting
// straight to an unparsed Referer value would be an open-redirect bug.
// Only the path+query of a same-host Referer is ever reused; anything
// else (missing header, cross-host, unparsable) falls back to the
// public token page itself, or the portal home as a last resort.
$safeLocation = '/';
$referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
$currentHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
if ($referer !== '' && $currentHost !== '') {
    $parts = parse_url($referer);
    if (is_array($parts) === true
        && isset($parts['host']) === true
        && strcasecmp((string) $parts['host'], $currentHost) === 0
        && isset($parts['path']) === true
        && str_starts_with((string) $parts['path'], '/') === true
    ) {
        $safeLocation = (string) $parts['path'] . (isset($parts['query']) === true ? '?' . (string) $parts['query'] : '');
    }
}
header('Location: ' . $safeLocation, true, 303);
exit();
