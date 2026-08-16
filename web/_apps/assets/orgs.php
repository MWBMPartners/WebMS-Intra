<?php
// Path: _apps/assets/orgs.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — External Organisations 🏢
 * -----------------------------------------------------------------------------
 * Manage the site's register of external organisations — hire companies,
 * partner charities, suppliers, or anyone who can co-own, lend to, or
 * borrow from this site. List + add/edit (inline, via `?edit=`) + active/
 * inactive toggle. Self-posting (GET renders, POST mutates, both on this
 * same route) — mirrors `_apps/assets/categories.php`/`locations.php`'s
 * established house pattern for this shape of small reference-data CRUD
 * screen (see that file's header for the rationale).
 *
 * Organisations are never hard-deleted (tblAssetOwners.orgID and
 * tblAssetLoans.counterpartyOrgID are both `ON DELETE CASCADE`/`SET NULL`
 * respectively, so nothing stops a real delete technically — but an
 * active/inactive toggle preserves the organisation's name and contact
 * details on historical owner/loan rows instead of quietly removing them).
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
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

// 🛡️ Manager gate — admins or the asset_manager role only.
if (App::isAdmin() !== true && App::hasRole('asset_manager') !== true) {
    Router::renderError(403);
    return;
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🔐 CSRF FIRST — before any side-effect.
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /assets/orgs');
        exit();
    }

    $action = (string) ($_POST['action'] ?? 'save');
    $orgId  = (int) ($_POST['orgID'] ?? 0);

    if ($action === 'toggle') {
        AssetRegister::toggleOrgActive($orgId, $siteId, $userId);
        $_SESSION['flash_msg']  = 'Organisation updated.';
        $_SESSION['flash_type'] = 'success';
    } else {
        $saved = AssetRegister::saveOrg($siteId, $orgId, $_POST, $userId);
        $_SESSION['flash_msg']  = $saved > 0 ? 'Organisation saved.' : 'Organisation name is required.';
        $_SESSION['flash_type'] = $saved > 0 ? 'success' : 'danger';
    }

    header('Location: /assets/orgs');
    exit();
}

$orgs = AssetRegister::listOrgs($siteId, false);

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

// ✏️ Inline "edit" — prefill the add form from ?edit=ID (no JS required).
$editId  = (int) ($_GET['edit'] ?? 0);
$editOrg = null;
if ($editId > 0) {
    foreach ($orgs as $o) {
        if ((int) $o['orgID'] === $editId) {
            $editOrg = $o;
            break;
        }
    }
}

$pageTitle   = 'External Organisations';
$pageSection = 'assets';
$breadcrumbs = ['Dashboard' => '/', 'Assets' => '/assets', 'Organisations' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-building me-2"></i>External Organisations</h1>
<p class="text-muted">Hire companies, partner charities, suppliers — anyone external who can co-own, lend to, or borrow from this site. Used by the Owners panel and (in a later sub-issue) Loans.</p>

<div class="card mb-3">
    <div class="card-body">
        <h2 class="h5"><?php echo $editOrg !== null ? 'Edit organisation' : 'Add organisation'; ?></h2>
        <form method="post" action="/assets/orgs" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="orgID" value="<?php echo $editOrg !== null ? (int) $editOrg['orgID'] : 0; ?>">
            <div class="col-md-4">
                <label class="form-label small" for="orgName">Organisation name</label>
                <input type="text" class="form-control form-control-sm" id="orgName" name="orgName" required maxlength="255"
                       value="<?php echo $editOrg !== null ? htmlspecialchars((string) $editOrg['orgName'], ENT_QUOTES, 'UTF-8') : ''; ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small" for="contactName">Contact name</label>
                <input type="text" class="form-control form-control-sm" id="contactName" name="contactName" maxlength="150"
                       value="<?php echo $editOrg !== null ? htmlspecialchars((string) ($editOrg['contactName'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small" for="contactEmail">Contact email</label>
                <input type="email" class="form-control form-control-sm" id="contactEmail" name="contactEmail" maxlength="255"
                       value="<?php echo $editOrg !== null ? htmlspecialchars((string) ($editOrg['contactEmail'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small" for="contactPhone">Contact phone</label>
                <input type="text" class="form-control form-control-sm" id="contactPhone" name="contactPhone" maxlength="50"
                       value="<?php echo $editOrg !== null ? htmlspecialchars((string) ($editOrg['contactPhone'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small" for="agreementRef">Loan/hire agreement reference</label>
                <input type="text" class="form-control form-control-sm" id="agreementRef" name="agreementRef" maxlength="100"
                       value="<?php echo $editOrg !== null ? htmlspecialchars((string) ($editOrg['agreementRef'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?>">
            </div>
            <div class="col-md-5">
                <label class="form-label small" for="notes">Notes</label>
                <input type="text" class="form-control form-control-sm" id="notes" name="notes"
                       value="<?php echo $editOrg !== null ? htmlspecialchars((string) ($editOrg['notes'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?>">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="fa-solid fa-<?php echo $editOrg !== null ? 'check' : 'plus'; ?> me-1"></i><?php echo $editOrg !== null ? 'Update' : 'Add'; ?>
                </button>
                <?php if ($editOrg !== null): ?>
                    <a href="/assets/orgs" class="btn btn-outline-secondary btn-sm w-100 mt-1">Cancel edit</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if (count($orgs) === 0): ?>
    <div class="alert alert-info">No external organisations yet.</div>
<?php else: ?>
    <div class="portal-data-list">
        <div class="portal-data-header">
            <div class="col-3">Organisation</div>
            <div class="col-3">Contact</div>
            <div class="col-2">Agreement ref</div>
            <div class="col-4 text-end">Actions</div>
        </div>
        <?php foreach ($orgs as $org): ?>
            <div class="portal-data-row align-items-center">
                <div class="col-3">
                    <strong><?php echo htmlspecialchars((string) $org['orgName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <?php if ((int) $org['isActive'] === 0): ?><span class="badge bg-secondary ms-1">inactive</span><?php endif; ?>
                    <?php if ($org['notes'] !== null && (string) $org['notes'] !== ''): ?>
                        <br><small class="text-muted"><?php echo htmlspecialchars((string) $org['notes'], ENT_QUOTES, 'UTF-8'); ?></small>
                    <?php endif; ?>
                </div>
                <div class="col-3 small text-muted">
                    <?php echo htmlspecialchars((string) ($org['contactName'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                    <?php if ($org['contactEmail'] !== null && (string) $org['contactEmail'] !== ''): ?>
                        <br><?php echo htmlspecialchars((string) $org['contactEmail'], ENT_QUOTES, 'UTF-8'); ?>
                    <?php endif; ?>
                    <?php if ($org['contactPhone'] !== null && (string) $org['contactPhone'] !== ''): ?>
                        <br><?php echo htmlspecialchars((string) $org['contactPhone'], ENT_QUOTES, 'UTF-8'); ?>
                    <?php endif; ?>
                </div>
                <div class="col-2 small text-muted"><?php echo htmlspecialchars((string) ($org['agreementRef'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="col-4 text-end">
                    <a href="/assets/orgs?edit=<?php echo (int) $org['orgID']; ?>" class="btn btn-sm btn-outline-secondary" title="Edit">
                        <i class="fa-solid fa-pen"></i>
                    </a>
                    <form method="post" action="/assets/orgs" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="orgID" value="<?php echo (int) $org['orgID']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-<?php echo (int) $org['isActive'] === 1 ? 'warning' : 'success'; ?>"
                                title="<?php echo (int) $org['isActive'] === 1 ? 'Deactivate' : 'Activate'; ?>">
                            <i class="fa-solid fa-toggle-<?php echo (int) $org['isActive'] === 1 ? 'on' : 'off'; ?>"></i>
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<a href="/assets" class="btn btn-outline-secondary mt-3"><i class="fa-solid fa-arrow-left me-1"></i>Back to Asset Tracker</a>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
