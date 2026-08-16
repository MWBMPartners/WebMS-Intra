<?php
// Path: _apps/assets/loan-action.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Loan Action 🔄
 * -----------------------------------------------------------------------------
 * POST handler for every loan state-change button on item.php's Loans panel
 * and loans.php's register — approve / decline / checkout / checkin /
 * cancel. One route, dispatched entirely to
 * AssetRegister::loanAction() (mirrors owners-save.php's/identifiers-
 * save.php's single-route-multi-action house pattern).
 *
 * Every bit of authority-gating and state-machine enforcement lives inside
 * AssetRegister::loanAction() and its five private per-verb helpers — this
 * controller's own job is limited to CSRF, coercing $_POST into the shape
 * loanAction() expects, and flashing whatever `msg` it returns; it does NOT
 * duplicate the approve/decline/checkout gate (canApproveLoan()) or the
 * checkin/cancel "requester OR canApproveLoan()" gate itself. The IDOR
 * guard (loan must belong to BOTH $loanID AND $assetID on this site) also
 * lives entirely inside loanAction() — this controller passes the raw
 * posted ids straight through without pre-checking them, exactly like
 * owners-save.php does for ownerID/removeOwner().
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

use Portal\Core\AssetRegister;
use Portal\Core\Auth;

Auth::ensureSession();
Auth::requireLogin();

// 🔐 CSRF FIRST — before any side-effect, per house convention.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /assets');
    exit();
}

$userId  = (int) ($_SESSION['user_id'] ?? 0);
$assetId = (int) ($_POST['assetID'] ?? 0);
$loanId  = (int) ($_POST['loanID'] ?? 0);
$action  = (string) ($_POST['action'] ?? '');

if ($assetId <= 0 || $loanId <= 0) {
    $_SESSION['flash_msg']  = 'Missing loan reference.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /assets');
    exit();
}

// 📋 Only the fields relevant to the posted $action are ever consulted by
// AssetRegister::loanAction()'s dispatch (see that method's doc) — every
// other key here is simply ignored by whichever private per-verb helper
// handles the action, exactly like owners-save.php's picker fields.
$data = [
    'declineReason'      => (string) ($_POST['declineReason'] ?? ''),
    'conditionOut'        => (string) ($_POST['conditionOut'] ?? ''),
    'conditionOutNotes'   => (string) ($_POST['conditionOutNotes'] ?? ''),
    'conditionIn'         => (string) ($_POST['conditionIn'] ?? ''),
    'conditionInNotes'    => (string) ($_POST['conditionInNotes'] ?? ''),
];

$result = AssetRegister::loanAction($loanId, $assetId, $action, $data, $userId);

$_SESSION['flash_msg']  = $result['msg'];
$_SESSION['flash_type'] = $result['ok'] === true ? 'success' : 'danger';

// 🔙 Return to wherever the form was rendered from — item.php's Loans
// panel (default) or loans.php's register, via a same-page hidden field
// (never trusted for anything beyond "which of these two known pages to
// redirect back to" — no open-redirect surface, only these two literals
// are ever recognised).
$returnTo = (string) ($_POST['returnTo'] ?? 'item');
$redirect = $returnTo === 'loans' ? '/assets/loans' : ('/assets/item?id=' . $assetId);

header('Location: ' . $redirect);
exit();
