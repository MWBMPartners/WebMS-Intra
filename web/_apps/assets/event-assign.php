<?php
// Path: _apps/assets/event-assign.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Assign/Unassign Asset ↔ Event 📦📅 (#409)
 * -----------------------------------------------------------------------------
 * POST handler for item.php's "Assigned events" panel (and the calendar
 * event page's own "Assigned assets" section, for its Unassign button) —
 * two actions (`action` field, mirrors owners-save.php's/loan-action.php's
 * single-route-multi-action house pattern):
 *
 *   - `assign`   — create a tblAssetEventAssignments row via
 *                  AssetRegister::assignToEvent(). That method re-validates
 *                  BOTH the asset AND the posted eventID against Site::id()
 *                  independently from scratch — see its own doc for the
 *                  "never trust a posted eventID" rationale (mirrors
 *                  _apps/documents/upload.php's own eventID re-validation)
 *                  — this is the DOUBLE IDOR guard #409 calls for.
 *   - `unassign` — delete an existing row via
 *                  AssetRegister::unassignFromEvent() (IDOR-checked there:
 *                  the row must belong to BOTH the posted assetID AND
 *                  assignmentID on this site).
 *
 * Gate: $canManage (admin/asset_manager) OR AssetRegister::isResponsibleFor()
 * for THIS asset — deliberately WIDER than owners-save.php's manager-only
 * gate (assigning an asset to an event day-to-day is closer to a
 * custodianship action than an ownership-register edit), mirroring
 * maintenance-save.php's/loan-action.php's own authority-based gates.
 * AssetRegister::assignToEvent()/unassignFromEvent() do NOT re-derive this
 * particular gate themselves (unlike the IDOR/eventID checks above, which
 * they DO re-derive) — same division of responsibility as loanAction()'s
 * own doc describes: the state-machine/IDOR/FK-existence checks live in
 * the model, the AUTHORITY gate lives in the controller, computed once,
 * here.
 *
 * Every branch below hands its raw ids straight to an AssetRegister method
 * that re-validates them itself — this controller's own job is limited to
 * CSRF, the authority gate, and coercing $_POST into the shape each method
 * expects.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   2.0.1
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/404
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/409
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/620
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AssetRegister;
use Portal\Core\Auth;
use Portal\Core\Capabilities;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

// 🔐 CSRF FIRST — before any side-effect, per house convention (mirrors
// owners-save.php/loan-action.php).
//
// #512 — Site::url() below gives the plain address outside path mode, so
// nothing changes for a portal that does not use it; in path mode it adds
// the organisation's own prefix, which a bare '/assets' here used to
// drop, sending a path-mode visitor to organisation 1's Asset Tracker
// instead of their own.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('assets'));
    exit();
}

$userId  = (int) ($_SESSION['user_id'] ?? 0);
$assetId = (int) ($_POST['assetID'] ?? 0);

// 🔒 Site-scope the asset up front — AssetRegister::get() is itself
// site-scoped via Site::id(), so an assetID belonging to another tenant
// resolves to null here and every branch below bails out identically
// (mirrors owners-save.php's own up-front asset lookup).
$asset = $assetId > 0 ? AssetRegister::get($assetId) : null;
if ($asset === null) {
    $_SESSION['flash_msg']  = 'Asset not found.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('assets'));
    exit();
}

// 🛡️ $canManage OR a responsible owner-party for THIS asset — see file
// header for why this is deliberately wider than owners-save.php's
// manager-only gate. AssetRegister's own methods re-derive the IDOR/
// eventID-existence checks independently regardless (see file header) —
// this is purely the AUTHORITY gate, computed once, here.
$canManage = App::isAdmin() === true || App::can(Capabilities::ASSETS_MANAGE) === true;
if ($canManage === false && AssetRegister::isResponsibleFor($assetId, $userId) === false) {
    http_response_code(403);
    exit('Forbidden');
}

$action = (string) ($_POST['action'] ?? 'assign');

switch ($action) {
    // -------------------------------------------------------------------
    // 🗑️ Unassign — remove an existing assignment row. IDOR-guarded
    // inside unassignFromEvent() (assignmentID + assetID + siteID).
    // -------------------------------------------------------------------
    case 'unassign':
        $assignmentId = (int) ($_POST['assignmentID'] ?? 0);
        $ok = $assignmentId > 0 && AssetRegister::unassignFromEvent($assignmentId, $assetId, $userId);
        $_SESSION['flash_msg']  = $ok === true
            ? 'Asset unassigned from the event.'
            : 'Could not unassign — it may no longer belong to this asset.';
        $_SESSION['flash_type'] = $ok === true ? 'success' : 'danger';
        break;

    // -------------------------------------------------------------------
    // ➕ Assign — default action. AssetRegister::assignToEvent() re-
    // validates everything from scratch (asset + eventID both on this
    // site, optional window, advisory overlap warnings, the friendly
    // "already assigned" duplicate catch) — see that method's own doc.
    // -------------------------------------------------------------------
    case 'assign':
    default:
        $data = [
            'eventID'       => (int) ($_POST['eventID'] ?? 0),
            'assignedFrom'  => (string) ($_POST['assignedFrom'] ?? ''),
            'assignedUntil' => (string) ($_POST['assignedUntil'] ?? ''),
            'notes'         => (string) ($_POST['notes'] ?? ''),
        ];
        $result   = AssetRegister::assignToEvent($assetId, $data, $userId);
        $warnings = $result['warnings'];

        if ($result['id'] > 0) {
            $_SESSION['flash_msg']  = 'Asset assigned to the event.' . (count($warnings) > 0 ? ' ' . implode(' ', $warnings) : '');
            $_SESSION['flash_type'] = count($warnings) > 0 ? 'warning' : 'success';
        } else {
            $_SESSION['flash_msg']  = count($warnings) > 0 ? implode(' ', $warnings) : 'Could not assign this asset to the event.';
            $_SESSION['flash_type'] = 'danger';
        }
        break;
}

// 🔙 Return to wherever the form was rendered from — item.php's Assigned-
// events panel (the default, which posts no `returnTo`, or posts "item") or
// the calendar event page's own section, which posts
// `Site::url('calendar/event') . '?slug=…'`.
//
// The posted value is judged by Auth::safeRedirectUrl(), the ONE judge of a
// "come back to" address (the one every sign-in page uses). Refused, it falls
// back to this asset's own page, not the organisation's front page, because
// that is where the message about what just happened is printed: the assign
// or unassign above HAS happened, and the front page (dashboard/index.php)
// prints no such message.
//
// WHAT WAS WRONG (#620). This page had a judge of its own: the value had to
// begin with Site::url('assets') or Site::url('calendar') followed by "/",
// "?" or nothing, and must not hold "//" or "://". It accepted
// "/north/assets/../../outside", which a browser works out to "/outside" —
// still this site, but outside the organisation the person was working in
// (#536). The one judge refuses a "." or ".." part in every set-up, so the
// page's own judge is deleted rather than mended: two judges are two places
// to get one rule wrong, and this one had.
//
// It is also WIDER than the old judge in one way, said plainly: any address
// inside this organisation is now honoured, not only the asset and calendar
// pages. No such address leaves the organisation, and the one judge is what
// every sign-in page already trusts with the same question.
$returnTo = $_POST['returnTo'] ?? 'item';
$itemPage = Site::url('assets/item') . '?id=' . $assetId; // 🛟 the default, prefixed in path mode
$redirect = $returnTo === 'item' ? $itemPage : Auth::safeRedirectUrl($returnTo, $itemPage);

header('Location: ' . $redirect);
exit();
