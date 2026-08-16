<?php
// Path: _apps/assets/loan-save.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Save Loan Request 🔄
 * -----------------------------------------------------------------------------
 * POST handler for loan.php — creates a new tblAssetLoans row via
 * AssetRegister::createLoanRequest(). Any logged-in user who can see this
 * asset may submit a request (the gated step is approval, handled entirely
 * by loan-action.php/AssetRegister::canApproveLoan() — see that method's
 * doc) — this controller's own job is limited to CSRF, the confidential-
 * asset visibility gate, and coercing $_POST into the shape
 * createLoanRequest() expects; every actual validation rule (direction,
 * counterpartyType + matching field, dueDate, conditionOut, the "one
 * unresolved loan at a time" guard) lives in that method, not here.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/398
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AssetRegister;
use Portal\Core\Auth;

Auth::ensureSession();
Auth::requireLogin();

// 🔐 CSRF FIRST — before any side-effect, per house convention (mirrors
// owners-save.php/identifiers-save.php).
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
// resolves to null here.
$asset = $assetId > 0 ? AssetRegister::get($assetId) : null;
if ($asset === null) {
    $_SESSION['flash_msg']  = 'Asset not found.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /assets');
    exit();
}

// 🔒 Confidential-asset gate — a non-privileged viewer can't submit a loan
// request for an asset they aren't allowed to even see. Mirrors item.php's
// ACCESS MODEL note (canManage OR isResponsibleFor).
$canManage     = App::isAdmin() === true || App::hasRole('asset_manager') === true;
$isResponsible = $canManage === false && AssetRegister::isResponsibleFor($assetId, $userId);
$privileged    = $canManage === true || $isResponsible === true;
if ((int) $asset['isConfidential'] === 1 && $privileged === false) {
    http_response_code(404);
    exit('Asset not found');
}

// 🔀 Only the ONE counterparty field matching counterpartyType is ever
// read by createLoanRequest() — see that method's own re-validation of
// the exactly-one-field rule; every other candidate field posted here is
// simply ignored rather than trusted.
$data = [
    'direction'            => (string) ($_POST['direction'] ?? ''),
    'counterpartyType'      => (string) ($_POST['counterpartyType'] ?? ''),
    'counterpartyUserID'    => (int) ($_POST['counterpartyUserID'] ?? 0),
    'counterpartyOrgID'     => (int) ($_POST['counterpartyOrgID'] ?? 0),
    'counterpartyName'      => (string) ($_POST['counterpartyName'] ?? ''),
    'counterpartyContact'   => (string) ($_POST['counterpartyContact'] ?? ''),
    'dueDate'               => (string) ($_POST['dueDate'] ?? ''),
    'conditionOut'          => (string) ($_POST['conditionOut'] ?? ''),
    'notes'                 => (string) ($_POST['notes'] ?? ''),
];

$newLoanId = AssetRegister::createLoanRequest($assetId, $data, $userId);

$_SESSION['flash_msg']  = $newLoanId > 0
    ? 'Loan request submitted — awaiting approval from someone with lending authority for this asset.'
    : 'Could not submit that loan request — check the counterparty selection and try again (this asset may already have an unresolved loan).';
$_SESSION['flash_type'] = $newLoanId > 0 ? 'success' : 'danger';

header('Location: /assets/item?id=' . $assetId);
exit();
