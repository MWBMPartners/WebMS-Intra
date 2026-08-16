<?php
// Path: _apps/assets/loan.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — New Loan Request 🔄
 * -----------------------------------------------------------------------------
 * GET form to REQUEST a loan (`?assetID=`) — either lending this asset out
 * (direction='out') or recording that we're borrowing it in (direction='in').
 * Any logged-in user who can see this asset may submit a request; the
 * gated step is APPROVAL (AssetRegister::canApproveLoan()), not the request
 * itself — see AssetRegister::createLoanRequest()'s doc (#398).
 *
 * Posts to `_apps/assets/loan-save.php`, which validates/coerces and calls
 * AssetRegister::createLoanRequest(). The counterparty-type selector
 * (Person / External organisation / Free text) reveals the matching picker
 * client-side — PROGRESSIVE ENHANCEMENT ONLY, mirrors item.php's owner-
 * party-type toggle: hiding the non-matching pickers does not stop their
 * fields being submitted; loan-save.php/createLoanRequest() read only the
 * field matching the posted counterpartyType.
 *
 * Confidential-asset gate: mirrors item.php's ACCESS MODEL note exactly —
 * a plain 404 (never 403) for a confidential asset the viewer isn't
 * privileged for, so this page can't be used as an existence oracle.
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
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db      = App::db();
$siteId  = Site::id();
$userId  = (int) ($_SESSION['user_id'] ?? 0);
$assetId = (int) ($_GET['assetID'] ?? 0);

$asset = $assetId > 0 ? AssetRegister::get($assetId) : null;
if ($asset === null) {
    Router::renderError(404);
    return;
}

// 🔒 Confidential-asset gate — identical rule to item.php's ACCESS MODEL.
$canManage     = App::isAdmin() === true || App::hasRole('asset_manager') === true;
$isResponsible = $canManage === false && AssetRegister::isResponsibleFor($assetId, $userId);
$privileged    = $canManage === true || $isResponsible === true;
if ((int) $asset['isConfidential'] === 1 && $privileged === false) {
    Router::renderError(404);
    return;
}

// 👤 Counterparty pickers — site-scoped active users (mirrors item.php's
// owner-party user picker) and active external organisations.
$counterpartyUsers = [];
$uStmt = $db->prepare(
    'SELECT u.userID, u.fullName FROM tblUsers u '
    . 'INNER JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
    . 'WHERE u.isActive = 1 ORDER BY u.fullName ASC'
);
if ($uStmt !== false) {
    $uStmt->bind_param('i', $siteId);
    $uStmt->execute();
    $uResult = $uStmt->get_result();
    while ($row = $uResult->fetch_assoc()) {
        $counterpartyUsers[] = $row;
    }
    $uStmt->close();
}
$counterpartyOrgs = AssetRegister::listOrgs($siteId, true);

$csrf = Auth::csrfToken();

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$pageTitle   = 'New Loan Request';
$pageSection = 'assets';
$breadcrumbs = [
    'Dashboard' => '/',
    'Assets'    => '/assets',
    (string) $asset['name'] => '/assets/item?id=' . $assetId,
    'New loan'  => '',
];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$nonce = htmlspecialchars(App::cspNonce(), ENT_QUOTES, 'UTF-8');
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<h1 class="mb-1"><i class="fa-solid fa-right-left me-2"></i>New loan request</h1>
<p class="text-muted mb-4">
    For <a href="/assets/item?id=<?php echo $assetId; ?>"><?php echo htmlspecialchars((string) $asset['name'], ENT_QUOTES, 'UTF-8'); ?></a>
    — submitting only REQUESTS the loan; someone with lending authority for this asset must approve it before it can be checked out.
</p>

<div class="card">
    <div class="card-body">
        <form method="post" action="/assets/loan-save">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="assetID" value="<?php echo $assetId; ?>">

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="direction">Direction <span class="text-danger">*</span></label>
                    <select class="form-select" id="direction" name="direction" required>
                        <option value="out">Lend out — we are lending this asset TO someone</option>
                        <option value="in">Borrow in — we are borrowing this FROM someone</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="dueDate">Due back date</label>
                    <input type="date" class="form-control" id="dueDate" name="dueDate" min="<?php echo date('Y-m-d'); ?>">
                    <small class="text-muted">Optional — leave blank for no fixed return date.</small>
                </div>

                <div class="col-12"><hr></div>

                <div class="col-md-4">
                    <label class="form-label" for="counterpartyType">Counterparty <span class="text-danger">*</span></label>
                    <select class="form-select" id="counterpartyType" name="counterpartyType" required>
                        <option value="user">A person in this portal</option>
                        <option value="org">An external organisation</option>
                        <option value="other">Someone else (free text)</option>
                    </select>
                </div>
                <div class="col-md-8" id="counterpartyPickerWrap-user">
                    <label class="form-label" for="counterpartyUserID">Person</label>
                    <select class="form-select" id="counterpartyUserID" name="counterpartyUserID">
                        <option value="">Select…</option>
                        <?php foreach ($counterpartyUsers as $u): ?>
                            <option value="<?php echo (int) $u['userID']; ?>"><?php echo htmlspecialchars((string) ($u['fullName'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-8" id="counterpartyPickerWrap-org" hidden>
                    <label class="form-label" for="counterpartyOrgID">External organisation</label>
                    <select class="form-select" id="counterpartyOrgID" name="counterpartyOrgID">
                        <option value="">Select…</option>
                        <?php foreach ($counterpartyOrgs as $org): ?>
                            <option value="<?php echo (int) $org['orgID']; ?>"><?php echo htmlspecialchars((string) $org['orgName'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted"><a href="/assets/orgs">Manage organisations</a></small>
                </div>
                <div class="col-md-8" id="counterpartyPickerWrap-other" hidden>
                    <label class="form-label" for="counterpartyName">Name</label>
                    <input type="text" class="form-control" id="counterpartyName" name="counterpartyName" maxlength="255">
                </div>
                <div class="col-md-8">
                    <label class="form-label" for="counterpartyContact">Contact details <span class="text-muted">(optional)</span></label>
                    <input type="text" class="form-control" id="counterpartyContact" name="counterpartyContact" maxlength="255" placeholder="Phone / email">
                </div>

                <div class="col-12"><hr></div>

                <div class="col-md-6">
                    <label class="form-label" for="conditionOut">Condition at hand-over <span class="text-muted">(optional)</span></label>
                    <select class="form-select" id="conditionOut" name="conditionOut">
                        <option value="">Not recorded yet</option>
                        <?php foreach (AssetRegister::CONDITION_STATES as $c): ?>
                            <option value="<?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucwords($c), ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Can also be confirmed/updated when the loan is actually checked out.</small>
                </div>
                <div class="col-12">
                    <label class="form-label" for="notes">Notes <span class="text-muted">(optional)</span></label>
                    <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-paper-plane me-1"></i>Submit request
                </button>
                <a href="/assets/item?id=<?php echo $assetId; ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script nonce="<?php echo $nonce; ?>">
(function () {
    'use strict';
    // 🎛️ Progressive-enhancement toggle only — see file header docblock.
    // Hiding the non-matching pickers does NOT stop their fields being
    // submitted; loan-save.php/createLoanRequest() read only the field
    // matching the posted counterpartyType.
    var typeSelect = document.getElementById('counterpartyType');
    var wraps = {
        user: document.getElementById('counterpartyPickerWrap-user'),
        org: document.getElementById('counterpartyPickerWrap-org'),
        other: document.getElementById('counterpartyPickerWrap-other')
    };
    function sync() {
        if (typeSelect === null) {
            return;
        }
        Object.keys(wraps).forEach(function (key) {
            if (wraps[key] !== null) {
                wraps[key].hidden = (typeSelect.value !== key);
            }
        });
    }
    if (typeSelect !== null) {
        typeSelect.addEventListener('change', sync);
        sync();
    }
})();
</script>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
