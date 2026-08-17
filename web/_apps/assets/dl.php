<?php
// Path: _apps/assets/dl.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — GS1 Digital Link Resolver 🔗🔍 (#415, Phase 3 Pass 6 — FINAL)
 * -----------------------------------------------------------------------------
 * PUBLIC handler reached ONLY via `Router::handleSpecialRoutes()`'s
 * `01/`|`8003/`|`8004/` special-route block (web/_core/Router.php —
 * modelled on that file's existing `a/{token}` block), NOT a tblRoutes
 * row. `$_GET['dl_ai']`/`['dl_value']`/`['dl_serial']` are pre-validated
 * by the Router's own anchored regexes before this file is even
 * required, but the shapes are re-validated here defensively rather than
 * trusted further — same "never trust an upstream guarantee for
 * something this security-relevant" convention `tag.php` uses for its
 * own Router-validated token.
 *
 * ACCESS MODEL (mirrors `tag.php` — read before changing):
 *   1. `assets.digital_link_enabled` (global/host-site-merged setting,
 *      migration 161, defaults `'true'`) gates the WHOLE resolver. Off
 *      -> 404, no signal distinguishing that from an unknown key.
 *   2. `AssetRegister::resolveDigitalLink()` does the real work — a
 *      GLOBAL, cross-site lookup that NEVER matches a confidential asset
 *      (excluded in the SQL itself, not a post-filter) and treats an
 *      ambiguous match as not-found. A null result -> 404, the EXACT
 *      SAME 404 as a disabled feature or a malformed request — no oracle
 *      distinguishes "feature off" from "key not found" from "key
 *      belongs to a confidential asset" from "value shape rejected".
 *   3. A hit 302-redirects into the EXISTING `/a/{token}` public page
 *      (`tag.php`), which owns EVERY further access-model decision
 *      (confidential-asset gate / public-page-disabled gate /
 *      logged-in-privileged redirect / …) from there. This file makes NO
 *      access decision beyond the two gates above and renders nothing of
 *      its own — it is purely a key-to-token translation step in front
 *      of an already-hardened page.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/415
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AssetRegister;
use Portal\Core\Auth;
use Portal\Core\Router;

// 🔓 Public page — session started purely for parity with every other
// public Asset Tracker entry point (tag.php/kiosk.php); this file never
// renders anything of its own and issues no CSRF token, but
// Auth::ensureSession() is the house convention every public controller
// opens with regardless of whether it strictly needs the session.
Auth::ensureSession();

// 🚦 Feature gate FIRST — checked before touching $_GET at all. Reads the
// GLOBAL/host-site-merged settings snapshot via App::settings(): this
// resolver is deliberately cross-site (see file header + AssetRegister::
// resolveDigitalLink()'s own doc), so there is no session-selected site
// to scope a per-site override lookup (App::settingForSite()) to.
$digitalLinkEnabled = (string) (App::settings('assets.digital_link_enabled') ?? 'true') === 'true';
if ($digitalLinkEnabled === false) {
    Router::renderError(404);
    return;
}

// 🔍 Defensive re-validation of the Router-set values — the Router
// already matched these against its own anchored regexes, but this file
// must never trust an upstream guarantee for something this
// security-relevant (mirrors tag.php's own token re-check even after the
// Router has already validated it).
$ai     = (string) ($_GET['dl_ai'] ?? '');
$value  = (string) ($_GET['dl_value'] ?? '');
$serial = (string) ($_GET['dl_serial'] ?? '');

$aiValid = in_array($ai, ['01', '8003', '8004'], true) === true;
$valueValid = match ($ai) {
    '01'    => preg_match('/^\d{8,14}$/', $value) === 1,
    '8003'  => preg_match('/^[A-Za-z0-9\-_.]{14,30}$/', $value) === 1,
    '8004'  => preg_match('/^[A-Za-z0-9\-_.]{1,30}$/', $value) === 1,
    default => false,
};
$serialValid = $serial === '' || preg_match('/^[A-Za-z0-9\-_.]{1,20}$/', $serial) === 1;

if ($aiValid === false || $valueValid === false || $serialValid === false) {
    Router::renderError(404);
    return;
}

// 🔗 Resolve. A null result covers EVERY rejection reason (unknown key,
// confidential asset, ambiguous match) — see AssetRegister::
// resolveDigitalLink()'s own doc for why collapsing all of those into
// one outcome here is deliberate (no oracle).
$token = AssetRegister::resolveDigitalLink($ai, $value, $serial !== '' ? $serial : null);
if ($token === null) {
    Router::renderError(404);
    return;
}

// ✅ Hit — redirect into the EXISTING public tag page, which owns every
// further gate from here (confidential/disabled/privileged-viewer/…).
// $token is always a DB-sourced, shape-validated publicToken (re-checked
// inside resolveDigitalLink() itself) — safe to concatenate, but never
// anything other than that already-validated value regardless.
header('Location: /a/' . $token, true, 302);
exit();
