<?php
// Path: _apps/assets/owners-save.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Save Asset Owners 👥
 * -----------------------------------------------------------------------------
 * POST handler for the item.php Owners panel. One route, four actions
 * (`action` field — mirrors resource-save.php's/categories.php's own
 * single-route-multi-action house pattern rather than adding three more
 * tblRoutes rows):
 *
 *   - `add`             — create a new tblAssetOwners row via
 *                          AssetRegister::addOwner().
 *   - `remove`           — delete an existing row via
 *                          AssetRegister::removeOwner() (IDOR-checked there).
 *   - `toggle-authority` — flip ONE of isLendingAuthority/
 *                          isMaintenanceAuthority via
 *                          AssetRegister::setOwnerAuthority().
 *   - `set-terms`        — update tblAssets.ownershipTerms via
 *                          AssetRegister::updateOwnershipTerms().
 *
 * Gate: admin OR asset_manager role ONLY — managing ownership (who owns/
 * lends/is-accountable-for an asset) is a manager action, deliberately NOT
 * extended to a merely "responsible" owner-party the way resource-save.php's
 * gate is. This mirrors save.php/delete.php's own manager-only gate — see
 * that file's header for the same rationale applied to the asset record
 * itself.
 *
 * Every branch below hands its raw ids straight to an AssetRegister method
 * that re-validates them itself (exactly-one-party-FK, IDOR ownerID→assetID
 * scoping, the toggle-authority field allow-list) — this controller's own
 * job is limited to CSRF, the manager gate, and coercing $_POST into the
 * shape each method expects; it does not duplicate that validation.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/396
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AssetRegister;
use Portal\Core\Auth;

Auth::ensureSession();
Auth::requireLogin();

// 🔐 CSRF FIRST — before any side-effect, per house convention (mirrors
// save.php/categories.php).
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /assets');
    exit();
}

// 🛡️ Manager gate — admins or the asset_manager role only. See file header
// for why this is deliberately NOT widened to isResponsibleFor().
if (App::isAdmin() !== true && App::hasRole('asset_manager') !== true) {
    http_response_code(403);
    exit('Forbidden');
}

$userId  = (int) ($_SESSION['user_id'] ?? 0);
$assetId = (int) ($_POST['assetID'] ?? 0);

// 🔒 Site-scope the asset up front — AssetRegister::get() is itself
// site-scoped via Site::id(), so an assetID belonging to another tenant
// resolves to null here and every branch below bails out identically.
$asset = $assetId > 0 ? AssetRegister::get($assetId) : null;
if ($asset === null) {
    $_SESSION['flash_msg']  = 'Asset not found.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /assets');
    exit();
}

$action = (string) ($_POST['action'] ?? 'add');

switch ($action) {
    // -------------------------------------------------------------------
    // 🗑️ Remove an owner row.
    // -------------------------------------------------------------------
    case 'remove':
        $ownerId = (int) ($_POST['ownerID'] ?? 0);
        $ok = $ownerId > 0 && AssetRegister::removeOwner($ownerId, $assetId, $userId);
        $_SESSION['flash_msg']  = $ok === true
            ? 'Owner removed.'
            : 'Could not remove that owner — it may no longer belong to this asset.';
        $_SESSION['flash_type'] = $ok === true ? 'success' : 'danger';
        break;

    // -------------------------------------------------------------------
    // 🔑🔧 Toggle one authority flag on an existing owner row. The posted
    // `value` is whatever the button wants the NEW state to be (item.php
    // renders one form per current state, so the button always posts the
    // opposite of what's currently shown — see that file's markup).
    // -------------------------------------------------------------------
    case 'toggle-authority':
        $ownerId = (int) ($_POST['ownerID'] ?? 0);
        $field   = (string) ($_POST['field'] ?? '');
        $value   = (string) ($_POST['value'] ?? '1') === '1';
        // 🔒 $field is re-validated inside setOwnerAuthority() against
        // AssetRegister::OWNER_AUTHORITY_FIELDS before it ever reaches a
        // query — this controller forwards it, it does not trust it.
        $ok = $ownerId > 0 && $field !== '' && AssetRegister::setOwnerAuthority($ownerId, $assetId, $field, $value, $userId);
        $_SESSION['flash_msg']  = $ok === true ? 'Owner authority updated.' : 'Could not update owner authority.';
        $_SESSION['flash_type'] = $ok === true ? 'success' : 'danger';
        break;

    // -------------------------------------------------------------------
    // 📜 Update the asset's free-text ownership/agreement terms.
    // -------------------------------------------------------------------
    case 'set-terms':
        $terms = (string) ($_POST['ownershipTerms'] ?? '');
        if (mb_strlen($terms) > 65535) {
            // 🪞 TEXT column cap — mirrors save.php's own maxlength-style
            // guards on other free-text fields.
            $_SESSION['flash_msg']  = 'Ownership terms are too long.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: /assets/item?id=' . $assetId);
            exit();
        }
        $ok = AssetRegister::updateOwnershipTerms($assetId, $terms, $userId);
        $_SESSION['flash_msg']  = $ok === true ? 'Ownership terms updated.' : 'Could not update ownership terms.';
        $_SESSION['flash_type'] = $ok === true ? 'success' : 'danger';
        break;

    // -------------------------------------------------------------------
    // ➕ Add an owner/custodian row (default action). The party picker in
    // item.php submits ONE of partyUserID/partyDeptID/partyGroupID/
    // partyOrgID depending on the selected `partyType` — only the field
    // matching $partyType is ever read here, so the others (submitted but
    // unused, since all four selects are progressive-enhancement-hidden/
    // shown together, see item.php's script) are simply ignored rather
    // than trusted. AssetRegister::addOwner() re-validates the whole
    // exactly-one-FK rule from scratch regardless — see that method's doc.
    // -------------------------------------------------------------------
    case 'add':
    default:
        $partyType = (string) ($_POST['partyType'] ?? '');
        $partyFieldMap = [
            'user'  => 'partyUserID',
            'dept'  => 'partyDeptID',
            'group' => 'partyGroupID',
            'org'   => 'partyOrgID',
        ];
        $partyField = $partyFieldMap[$partyType] ?? null;
        $partyId    = $partyField !== null ? (int) ($_POST[$partyField] ?? 0) : 0;

        $sharePercentRaw = trim((string) ($_POST['sharePercent'] ?? ''));

        $data = [
            'partyType'              => $partyType,
            'userID'                 => $partyType === 'user'  ? $partyId : null,
            'deptID'                 => $partyType === 'dept'  ? $partyId : null,
            'groupID'                => $partyType === 'group' ? $partyId : null,
            'orgID'                  => $partyType === 'org'   ? $partyId : null,
            'roleKind'               => (string) ($_POST['roleKind'] ?? 'owner'),
            'sharePercent'           => $sharePercentRaw !== '' ? (float) $sharePercentRaw : null,
            'isLendingAuthority'     => isset($_POST['isLendingAuthority']),
            'isMaintenanceAuthority' => isset($_POST['isMaintenanceAuthority']),
            'notes'                  => (string) ($_POST['notes'] ?? ''),
        ];

        $newId = AssetRegister::addOwner($assetId, $data, $userId);
        $_SESSION['flash_msg']  = $newId > 0
            ? 'Owner added.'
            : 'Could not add owner — check the party selection (exactly one is required) and try again.';
        $_SESSION['flash_type'] = $newId > 0 ? 'success' : 'danger';
        break;
}

header('Location: /assets/item?id=' . $assetId);
exit();
