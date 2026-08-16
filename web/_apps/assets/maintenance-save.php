<?php
// Path: _apps/assets/maintenance-save.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Save Asset Maintenance 🔧
 * -----------------------------------------------------------------------------
 * POST handler for both maintenance.php's per-asset log and item.php's
 * Maintenance panel. One route, three actions (`action` field — mirrors
 * owners-save.php's/resource-save.php's own single-route-multi-action
 * house pattern rather than adding separate tblRoutes rows per verb):
 *
 *   - `add`    — create a new tblAssetMaintenance row via
 *                AssetRegister::addMaintenance().
 *   - `update` — edit an existing row via AssetRegister::updateMaintenance()
 *                (IDOR-checked there — maintID+assetID+siteID).
 *   - `delete` — remove a row via AssetRegister::deleteMaintenance()
 *                (IDOR-checked there, same scoping).
 *
 * Gate: AssetRegister::canManageMaintenance($assetId) — admin/asset_manager
 * OR a maintenance-authority owner-party for THIS asset (direct/dept/
 * group). Deliberately NOT the general $canManage-only gate that
 * owners-save.php/save.php use (see AssetRegister::canManageMaintenance()'s
 * own doc) — a maintenance-authority owner who is neither an admin nor
 * holds the asset_manager role may still log/edit/remove this asset's
 * service history, exactly as #399's spec requires.
 *
 * Every branch hands its raw ids/values straight to an AssetRegister
 * method that re-validates them itself (maintType/status ENUM membership,
 * costPence >= 0, date parsing, performedByUserID FK existence, the IDOR
 * scoping) — this controller's own job is limited to CSRF, the
 * maintenance-authority gate, the pounds→pence conversion for costPence
 * (the house minor-units convention, #266 — every other money field in
 * this app converts at the controller boundary the same way), and
 * coercing $_POST into the shape each method expects; it does not
 * duplicate that validation.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/399
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\AssetRegister;
use Portal\Core\Auth;

Auth::ensureSession();
Auth::requireLogin();

// 🔐 CSRF FIRST — before any side-effect, per house convention (mirrors
// owners-save.php/loan-save.php).
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /assets');
    exit();
}

$userId  = (int) ($_SESSION['user_id'] ?? 0);
$assetId = (int) ($_POST['assetID'] ?? 0);

// 🔒 Site-scope the asset up front — AssetRegister::get() is itself
// site-scoped via Site::id(), so an assetID belonging to another tenant
// resolves to null here and this controller bails out before the
// authority check even runs.
$asset = $assetId > 0 ? AssetRegister::get($assetId) : null;
if ($asset === null) {
    $_SESSION['flash_msg']  = 'Asset not found.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /assets');
    exit();
}

// 🛡️ Maintenance-authority gate — see file header for why this is
// deliberately NOT the general $canManage-only gate.
if (AssetRegister::canManageMaintenance($assetId, $userId) === false) {
    http_response_code(403);
    exit('Forbidden');
}

// 🔙 Where to redirect back to afterwards — item.php's Maintenance panel
// (default) or the standalone maintenance.php per-asset log page. Mirrors
// loan-action.php's own returnTo convention exactly — never trusted for
// anything beyond "which of these two known pages to redirect back to",
// no open-redirect surface, only these two literals are ever recognised.
$returnTo = (string) ($_POST['returnTo'] ?? 'item');
$redirect = $returnTo === 'maintenance' ? ('/assets/maintenance?assetID=' . $assetId) : ('/assets/item?id=' . $assetId);

$action = (string) ($_POST['action'] ?? 'add');

// -----------------------------------------------------------------------
// 💷 Pounds → pence — the house minor-units convention (#266). The
// add/edit forms collect pounds (human-friendly); AssetRegister persists
// pence only, matching tblAssetMaintenance.costPence and every other
// money column in this app. is_numeric() guards against a non-numeric
// submission ever reaching (float) below; a blank/invalid input is simply
// treated as "no cost recorded" (null) rather than rejecting the whole
// entry over an optional field.
// -----------------------------------------------------------------------
$costPoundsRaw = trim((string) ($_POST['costPounds'] ?? ''));
$costPence = null;
if ($costPoundsRaw !== '' && is_numeric($costPoundsRaw) === true) {
    $costPence = (int) round(((float) $costPoundsRaw) * 100);
}

// 📋 Shared $data shape for both add/update — AssetRegister::
// addMaintenance()/updateMaintenance() re-validate every one of these
// fields from scratch (ENUM membership, date parsing, FK existence — see
// file header), so this controller does not need per-action branching
// here, only in the dispatch switch below.
$data = [
    'maintType'         => (string) ($_POST['maintType'] ?? ''),
    'title'             => (string) ($_POST['title'] ?? ''),
    'details'           => (string) ($_POST['details'] ?? ''),
    'performedByUserID' => (int) ($_POST['performedByUserID'] ?? 0),
    'performedByName'   => (string) ($_POST['performedByName'] ?? ''),
    'costPence'         => $costPence,
    'performedAt'       => (string) ($_POST['performedAt'] ?? ''),
    'nextDueDate'       => (string) ($_POST['nextDueDate'] ?? ''),
    'status'            => (string) ($_POST['status'] ?? 'completed'),
];

switch ($action) {
    // -------------------------------------------------------------------
    // ✏️ Update an existing entry.
    // -------------------------------------------------------------------
    case 'update':
        $maintId = (int) ($_POST['maintID'] ?? 0);
        $ok = $maintId > 0 && AssetRegister::updateMaintenance($maintId, $assetId, $data, $userId);
        $_SESSION['flash_msg']  = $ok === true
            ? 'Maintenance entry updated.'
            : 'Could not update that maintenance entry — check the values and try again.';
        $_SESSION['flash_type'] = $ok === true ? 'success' : 'danger';
        break;

    // -------------------------------------------------------------------
    // 🗑️ Remove an entry.
    // -------------------------------------------------------------------
    case 'delete':
        $maintId = (int) ($_POST['maintID'] ?? 0);
        $ok = $maintId > 0 && AssetRegister::deleteMaintenance($maintId, $assetId, $userId);
        $_SESSION['flash_msg']  = $ok === true
            ? 'Maintenance entry removed.'
            : 'Could not remove that maintenance entry — it may no longer belong to this asset.';
        $_SESSION['flash_type'] = $ok === true ? 'success' : 'danger';
        break;

    // -------------------------------------------------------------------
    // ➕ Add a new entry (default action).
    // -------------------------------------------------------------------
    case 'add':
    default:
        $newId = AssetRegister::addMaintenance($assetId, $data, $userId);
        $_SESSION['flash_msg']  = $newId > 0
            ? 'Maintenance entry added.'
            : 'Could not add that maintenance entry — check the values (type/title/status/dates) and try again.';
        $_SESSION['flash_type'] = $newId > 0 ? 'success' : 'danger';
        break;
}

header('Location: ' . $redirect);
exit();
