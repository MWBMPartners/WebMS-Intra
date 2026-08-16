<?php
// Path: _apps/assets/identifiers-save.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Save Asset Identifiers 🆔
 * -----------------------------------------------------------------------------
 * POST handler for the item.php Identifiers panel (#397). One route, three
 * actions (`action` field — mirrors owners-save.php's own single-route-
 * multi-action house pattern rather than adding two more tblRoutes rows):
 *
 *   - `add`         — record a new tblAssetIdentifiers row via
 *                      AssetRegister::addIdentifier(). Validation
 *                      (AssetRegister::validateIdentifier(), reused
 *                      unchanged from the #394/#395 pass) is NON-BLOCKING —
 *                      a save always succeeds once the required fields
 *                      (type, value) are present; any format/check-digit/
 *                      unknown-type warnings are surfaced as an
 *                      informational flash, never a rejection. The one
 *                      case addIdentifier() reports with nothing actually
 *                      written is the `uq_asset_ident` duplicate guard
 *                      (id === 0, see below).
 *   - `remove`      — delete an existing row via
 *                      AssetRegister::removeIdentifier() (IDOR-checked
 *                      there — the row must belong to THIS asset on THIS
 *                      site).
 *   - `set-primary` — promote one row to the asset's single primary
 *                      identifier via AssetRegister::setPrimaryIdentifier()
 *                      (IDOR-checked there; clears every other row on this
 *                      asset first, inside one DB transaction).
 *
 * Gate: admin OR asset_manager role ONLY — same manager-only gate as
 * owners-save.php (deliberately NOT widened to isResponsibleFor(); see
 * that file's header for the rationale, which applies identically here:
 * recording/curating identifiers is a manager action, not something every
 * responsible owner-party can do unsupervised).
 *
 * Every branch hands its raw ids straight to an AssetRegister method that
 * re-validates/IDOR-guards them itself — this controller's own job is
 * limited to CSRF, the manager gate, site-scoping the asset up front, and
 * coercing $_POST into the shape each method expects; it does not
 * duplicate that validation.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/397
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AssetRegister;
use Portal\Core\Auth;

Auth::ensureSession();
Auth::requireLogin();

// 🔐 CSRF FIRST — before any side-effect, per house convention (mirrors
// owners-save.php/save.php/categories.php).
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
// (Every AssetRegister::*Identifier() method ALSO re-checks assetID+siteID
// itself before touching a row — this is defence in depth, not the only
// guard.)
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
    // 🗑️ Remove an identifier row.
    // -------------------------------------------------------------------
    case 'remove':
        $identifierId = (int) ($_POST['identifierID'] ?? 0);
        $ok = $identifierId > 0 && AssetRegister::removeIdentifier($identifierId, $assetId, $userId);
        $_SESSION['flash_msg']  = $ok === true
            ? 'Identifier removed.'
            : 'Could not remove that identifier — it may no longer belong to this asset.';
        $_SESSION['flash_type'] = $ok === true ? 'success' : 'danger';
        break;

    // -------------------------------------------------------------------
    // ⭐ Promote one row to the asset's single primary identifier.
    // -------------------------------------------------------------------
    case 'set-primary':
        $identifierId = (int) ($_POST['identifierID'] ?? 0);
        $ok = $identifierId > 0 && AssetRegister::setPrimaryIdentifier($identifierId, $assetId, $userId);
        $_SESSION['flash_msg']  = $ok === true
            ? 'Primary identifier updated.'
            : 'Could not update the primary identifier — it may no longer belong to this asset.';
        $_SESSION['flash_type'] = $ok === true ? 'success' : 'danger';
        break;

    // -------------------------------------------------------------------
    // ➕ Add an identifier (default action). AssetRegister::addIdentifier()
    // re-validates everything itself (required fields, length caps, the
    // uq_asset_ident duplicate guard) — this branch only coerces $_POST
    // into that method's expected shape.
    // -------------------------------------------------------------------
    case 'add':
    default:
        $data = [
            'typeCode'  => (string) ($_POST['typeCode'] ?? ''),
            'value'     => (string) ($_POST['value'] ?? ''),
            'subScheme' => (string) ($_POST['subScheme'] ?? ''),
            'isPrimary' => isset($_POST['isPrimary']),
            'notes'     => (string) ($_POST['notes'] ?? ''),
        ];

        $result = AssetRegister::addIdentifier($assetId, $data, $userId);

        if ($result['id'] > 0) {
            // ✅ Saved. Any warnings (unknown type, check-digit mismatch,
            // non-numeric value for a mod-10 scheme, …) are PURELY
            // informational — the identifier is on the record either way,
            // per #397's non-blocking-validation requirement — so this is
            // a 'warning' flash, not 'danger'.
            if (count($result['warnings']) > 0) {
                $_SESSION['flash_msg']  = 'Identifier added, with warnings: ' . implode(' ', $result['warnings']);
                $_SESSION['flash_type'] = 'warning';
            } else {
                $_SESSION['flash_msg']  = 'Identifier added.';
                $_SESSION['flash_type'] = 'success';
            }
        } else {
            // ❌ Nothing was written — either a required-field failure
            // (blank type/value) or the uq_asset_ident duplicate guard.
            // Either way addIdentifier() already put a human-readable
            // reason in $result['warnings'][0] — see that method's doc.
            $_SESSION['flash_msg']  = $result['warnings'][0] ?? 'Could not add that identifier.';
            $_SESSION['flash_type'] = 'danger';
        }
        break;
}

header('Location: /assets/item?id=' . $assetId);
exit();
