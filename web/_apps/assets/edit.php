<?php
// Path: _apps/assets/edit.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — Add / Edit Asset 📦
 * -----------------------------------------------------------------------------
 * GET form for creating a new asset, or editing an existing one via `?id=`.
 * Posts to `_apps/assets/save.php`, which does the actual validation/
 * coercion/persistence (see that file's header for the split of
 * responsibilities).
 *
 * Gate: admin OR the asset_manager role — mirrors save.php/delete.php.
 *
 * The digital-only fields (licence key, seats, renewal date, access URL)
 * are shown/hidden client-side based on the "Kind" select, but this is a
 * PROGRESSIVE ENHANCEMENT only — save.php accepts and stores whatever was
 * actually posted regardless of `assetKind`, so the toggle never gates
 * anything security-relevant; it's a UI convenience, not a validation rule.
 *
 * "Label symbology" (#404) picks which barcode `AssetRegister::buildLabelSheets()`
 * prints on this asset's Label Designer labels — see
 * `AssetRegister::LABEL_SYMBOLOGIES`/`LABEL_SYMBOLOGY_LABELS` for the
 * allow-list and `save.php` for the server-side validation (never trust
 * this `<select>`'s posted value alone).
 *
 * INSURANCE (#404 columns, first editable this pass — #408): insurerName/
 * insurancePolicyNumber/insuredValuePounds/insuranceRenewalDate. No EXTRA
 * gate beyond this whole page's own admin/asset_manager one — unlike
 * `item.php`'s read-only insurance readout (privileged-gated because that
 * page is reachable by any logged-in viewer), this page is already
 * manager-only end-to-end.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/394
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/396
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/404
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/408
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/423
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

$db     = App::db();
$siteId = Site::id();

$assetId = (int) ($_GET['id'] ?? 0);
$asset   = $assetId > 0 ? AssetRegister::get($assetId) : null;
if ($assetId > 0 && $asset === null) {
    $_SESSION['flash_msg']  = 'Asset not found.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /assets');
    exit();
}
$isEdit = $asset !== null;

// 📋 Dropdown data — all categories/locations (not active-only), so an
// asset already assigned to a since-deactivated category/location still
// shows its current value rather than silently losing it in the UI.
$categories = AssetRegister::listCategories($siteId, false);
$locations  = AssetRegister::listLocations($siteId, false);

// 🔗 Parent-asset candidates — every other non-deleted asset on this site.
$parentCandidates = [];
$pStmt = $db->prepare('SELECT assetID, name FROM tblAssets WHERE siteID = ? AND isDeleted = 0 AND assetID != ? ORDER BY name ASC');
if ($pStmt !== false) {
    $excludeId = $assetId > 0 ? $assetId : 0;
    $pStmt->bind_param('ii', $siteId, $excludeId);
    $pStmt->execute();
    $pResult = $pStmt->get_result();
    while ($row = $pResult->fetch_assoc()) {
        $parentCandidates[] = $row;
    }
    $pStmt->close();
}

// 🪞 Value helper — edit-mode pre-fill, blank on create. Never used for
// licenseKey (see the field itself below — always blank, write-only).
$val = static function (string $key, mixed $default = '') use ($asset): string {
    if ($asset === null) {
        return (string) $default;
    }
    return htmlspecialchars((string) ($asset[$key] ?? $default), ENT_QUOTES, 'UTF-8');
};
$poundsVal = static function (string $key) use ($asset): string {
    if ($asset === null || $asset[$key] === null) {
        return '';
    }
    return number_format(((int) $asset[$key]) / 100, 2, '.', '');
};
$selected = static function (string $actual, string $option): string {
    return $actual === $option ? ' selected' : '';
};
$checked = static function (bool $isChecked): string {
    return $isChecked === true ? ' checked' : '';
};

$csrf = Auth::csrfToken();

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$pageTitle   = $isEdit === true ? 'Edit Asset' : 'Add Asset';
$pageSection = 'assets';
$breadcrumbs = [
    'Dashboard' => '/',
    'Assets'    => '/assets',
    $pageTitle  => '',
];
if ($isEdit === true) {
    $breadcrumbs = [
        'Dashboard' => '/',
        'Assets'    => '/assets',
        (string) $asset['name'] => '/assets/item?id=' . $assetId,
        'Edit'      => '',
    ];
}

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$nonce = htmlspecialchars(App::cspNonce(), ENT_QUOTES, 'UTF-8');
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<h1 class="mb-4">
    <i class="fa-solid fa-boxes-stacked me-2"></i><?php echo $isEdit === true ? 'Edit Asset' : 'Add Asset'; ?>
</h1>

<form method="post" action="/assets/save">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="assetID" value="<?php echo $assetId; ?>">

    <!-- 🧾 Core details -->
    <div class="card mb-3">
        <div class="card-header"><h2 class="h5 mb-0">Core details</h2></div>
        <div class="card-body row g-3">
            <div class="col-md-3">
                <label class="form-label" for="assetKind">Kind</label>
                <select class="form-select" id="assetKind" name="assetKind">
                    <option value="physical"<?php echo $selected($val('assetKind', 'physical'), 'physical'); ?>>Physical</option>
                    <option value="digital"<?php echo $selected($val('assetKind', 'physical'), 'digital'); ?>>Digital</option>
                </select>
            </div>
            <div class="col-md-9">
                <label class="form-label" for="name">Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="name" name="name" required maxlength="255" value="<?php echo $val('name'); ?>">
            </div>
            <div class="col-12">
                <label class="form-label" for="description">Description</label>
                <textarea class="form-control" id="description" name="description" rows="2"><?php echo $val('description'); ?></textarea>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="categoryID">Category</label>
                <select class="form-select" id="categoryID" name="categoryID">
                    <option value="">Uncategorised</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo (int) $cat['categoryID']; ?>"<?php echo $selected($val('categoryID'), (string) $cat['categoryID']); ?>>
                            <?php echo htmlspecialchars((string) $cat['categoryName'], ENT_QUOTES, 'UTF-8'); ?>
                            <?php echo (int) $cat['isActive'] === 0 ? ' (inactive)' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted"><a href="/assets/categories">Manage categories</a></small>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="locationID">Location</label>
                <select class="form-select" id="locationID" name="locationID">
                    <option value="">Unassigned</option>
                    <?php foreach ($locations as $loc): ?>
                        <option value="<?php echo (int) $loc['locationID']; ?>"<?php echo $selected($val('locationID'), (string) $loc['locationID']); ?>>
                            <?php echo htmlspecialchars((string) $loc['locationName'], ENT_QUOTES, 'UTF-8'); ?>
                            <?php echo (int) $loc['isActive'] === 0 ? ' (inactive)' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted"><a href="/assets/locations">Manage locations</a></small>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="parentAssetID">Parent asset (bundle/kit)</label>
                <select class="form-select" id="parentAssetID" name="parentAssetID">
                    <option value="">None</option>
                    <?php foreach ($parentCandidates as $pa): ?>
                        <option value="<?php echo (int) $pa['assetID']; ?>"<?php echo $selected($val('parentAssetID'), (string) $pa['assetID']); ?>>
                            <?php echo htmlspecialchars((string) $pa['name'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- 🔍 Identification -->
    <div class="card mb-3">
        <div class="card-header"><h2 class="h5 mb-0">Identification</h2></div>
        <div class="card-body row g-3">
            <div class="col-md-3">
                <label class="form-label" for="manufacturer">Manufacturer</label>
                <input type="text" class="form-control" id="manufacturer" name="manufacturer" maxlength="150" value="<?php echo $val('manufacturer'); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="model">Model</label>
                <input type="text" class="form-control" id="model" name="model" maxlength="150" value="<?php echo $val('model'); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="serialNumber">Serial number</label>
                <input type="text" class="form-control" id="serialNumber" name="serialNumber" maxlength="150" value="<?php echo $val('serialNumber'); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="assetTagCode">Asset tag code</label>
                <input type="text" class="form-control" id="assetTagCode" name="assetTagCode" maxlength="50" value="<?php echo $val('assetTagCode'); ?>">
                <small class="text-muted">Must be unique per site — printed on the physical label as the Code 128 barcode value (falls back to "AST-{id}" when blank).</small>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="labelSymbology">Label symbology</label>
                <select class="form-select" id="labelSymbology" name="labelSymbology">
                    <?php foreach (AssetRegister::LABEL_SYMBOLOGIES as $sym): ?>
                        <option value="<?php echo htmlspecialchars($sym, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $selected($val('labelSymbology', 'qr'), $sym); ?>>
                            <?php echo htmlspecialchars(AssetRegister::LABEL_SYMBOLOGY_LABELS[$sym], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">
                    Which code prints on <a href="/assets/labels">Label Designer</a> labels (#404). EAN-13/EAN-8/UPC-A/UPC-E/ITF-14
                    need a matching primary identifier recorded below, or the label falls back to the QR code.
                </small>
            </div>
            <div class="col-12">
                <label class="form-label" for="features">Features / spec notes</label>
                <textarea class="form-control" id="features" name="features" rows="2"><?php echo $val('features'); ?></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="conditionState">Condition</label>
                <select class="form-select" id="conditionState" name="conditionState">
                    <?php foreach (AssetRegister::CONDITION_STATES as $c): ?>
                        <option value="<?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $selected($val('conditionState', 'good'), $c); ?>>
                            <?php echo htmlspecialchars(ucwords($c), ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <?php foreach (AssetRegister::ASSET_STATUSES as $s): ?>
                        <option value="<?php echo htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $selected($val('status', 'in-service'), $s); ?>>
                            <?php echo htmlspecialchars(ucwords(str_replace('-', ' ', $s)), ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- 💷 Purchase & warranty -->
    <div class="card mb-3">
        <div class="card-header"><h2 class="h5 mb-0">Purchase &amp; warranty</h2></div>
        <div class="card-body row g-3">
            <div class="col-md-3">
                <label class="form-label" for="purchaseDate">Purchase date</label>
                <input type="date" class="form-control" id="purchaseDate" name="purchaseDate" value="<?php echo $val('purchaseDate'); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="purchaseStore">Purchased from</label>
                <input type="text" class="form-control" id="purchaseStore" name="purchaseStore" maxlength="255" value="<?php echo $val('purchaseStore'); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="purchaseCostPounds">Purchase cost</label>
                <input type="number" step="0.01" min="0" class="form-control" id="purchaseCostPounds" name="purchaseCostPounds" value="<?php echo $poundsVal('purchaseCostPence'); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="currency">Currency</label>
                <input type="text" class="form-control text-uppercase" id="currency" name="currency" maxlength="3" placeholder="GBP" value="<?php echo $val('currency', 'GBP'); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="warrantyExpiry">Warranty expiry</label>
                <input type="date" class="form-control" id="warrantyExpiry" name="warrantyExpiry" value="<?php echo $val('warrantyExpiry'); ?>">
            </div>
            <div class="col-md-8">
                <label class="form-label" for="warrantyDetails">Warranty details</label>
                <input type="text" class="form-control" id="warrantyDetails" name="warrantyDetails" maxlength="500" value="<?php echo $val('warrantyDetails'); ?>">
            </div>
        </div>
    </div>

    <!-- 💻 Digital / licensing — shown only for assetKind = digital (client-side only, see file header) -->
    <div class="card mb-3" id="digitalFieldsCard">
        <div class="card-header"><h2 class="h5 mb-0">Digital / licensing</h2></div>
        <div class="card-body row g-3">
            <div class="col-md-6">
                <label class="form-label" for="licenseKey">Licence key</label>
                <input type="password" class="form-control" id="licenseKey" name="licenseKey" maxlength="500" autocomplete="new-password" value="">
                <?php if ($isEdit === true): ?>
                    <small class="text-muted">Write-only — never shown here. Leave blank to keep the current licence key unchanged.</small>
                <?php else: ?>
                    <small class="text-muted">Stored encrypted. Leave blank if this asset has no licence key.</small>
                <?php endif; ?>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="licenseSeats">Licence seats</label>
                <input type="number" min="0" class="form-control" id="licenseSeats" name="licenseSeats" value="<?php echo $val('licenseSeats'); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="renewalDate">Renewal date</label>
                <input type="date" class="form-control" id="renewalDate" name="renewalDate" value="<?php echo $val('renewalDate'); ?>">
            </div>
            <div class="col-12">
                <label class="form-label" for="accessUrl">Access / admin URL</label>
                <input type="url" class="form-control" id="accessUrl" name="accessUrl" maxlength="500" placeholder="https://…" value="<?php echo $val('accessUrl'); ?>">
            </div>
        </div>
    </div>

    <!-- 📉 Depreciation -->
    <div class="card mb-3">
        <div class="card-header"><h2 class="h5 mb-0">Depreciation</h2></div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label class="form-label" for="depreciationMethod">Method</label>
                <select class="form-select" id="depreciationMethod" name="depreciationMethod">
                    <?php foreach (AssetRegister::DEPRECIATION_METHODS as $m): ?>
                        <option value="<?php echo htmlspecialchars($m, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $selected($val('depreciationMethod', 'none'), $m); ?>>
                            <?php echo htmlspecialchars(ucwords(str_replace('-', ' ', $m)), ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="usefulLifeMonths">Useful life (months)</label>
                <input type="number" min="0" class="form-control" id="usefulLifeMonths" name="usefulLifeMonths" value="<?php echo $val('usefulLifeMonths'); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="salvageValuePounds">Salvage value</label>
                <input type="number" step="0.01" min="0" class="form-control" id="salvageValuePounds" name="salvageValuePounds" value="<?php echo $poundsVal('salvageValuePence'); ?>">
            </div>
        </div>
    </div>

    <!-- 🛡️ Insurance (#404 columns, first editable this pass — #408) -->
    <div class="card mb-3">
        <div class="card-header"><h2 class="h5 mb-0">Insurance</h2></div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label class="form-label" for="insurerName">Insurer</label>
                <input type="text" class="form-control" id="insurerName" name="insurerName" maxlength="150" value="<?php echo $val('insurerName'); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="insurancePolicyNumber">Policy number</label>
                <input type="text" class="form-control" id="insurancePolicyNumber" name="insurancePolicyNumber" maxlength="100" value="<?php echo $val('insurancePolicyNumber'); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="insuredValuePounds">Insured value</label>
                <input type="number" step="0.01" min="0" class="form-control" id="insuredValuePounds" name="insuredValuePounds" value="<?php echo $poundsVal('insuredValuePence'); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="insuranceRenewalDate">Renewal date</label>
                <input type="date" class="form-control" id="insuranceRenewalDate" name="insuranceRenewalDate" value="<?php echo $val('insuranceRenewalDate'); ?>">
                <small class="text-muted">Feeds the automated renewal reminder (Admin &rarr; Asset Tracker settings).</small>
            </div>
            <div class="col-12">
                <small class="text-muted">
                    Only visible here and on the asset's own page to admins, asset managers, and responsible owner-parties — see the <strong>Insurance</strong> panel on the asset's own page.
                </small>
            </div>
        </div>
    </div>

    <!-- 📜 Ownership terms (#396) -->
    <div class="card mb-3">
        <div class="card-header"><h2 class="h5 mb-0">Ownership terms</h2></div>
        <div class="card-body">
            <label class="form-label" for="ownershipTerms">Free-text ownership / agreement terms</label>
            <textarea class="form-control" id="ownershipTerms" name="ownershipTerms" rows="3" maxlength="65535"
                      placeholder="e.g. Loaned in from Riverside Trust under a 12-month renewable agreement — see the Ownership &amp; legal vault for the signed copy."><?php echo $val('ownershipTerms'); ?></textarea>
            <small class="text-muted">
                Co-owners/custodians, lending &amp; maintenance authority, and confidential agreement documents (ownership agreements, insurance, legal paperwork) are managed from the asset's own page — see the <strong>Owners</strong> and <strong>Ownership &amp; legal vault</strong> panels there, not here.
            </small>
        </div>
    </div>

    <!-- 👁️ Visibility -->
    <div class="card mb-3">
        <div class="card-header"><h2 class="h5 mb-0">Visibility</h2></div>
        <div class="card-body">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="isConfidential" name="isConfidential"<?php echo $checked($isEdit === true && (int) ($asset['isConfidential'] ?? 0) === 1); ?>>
                <label class="form-check-label" for="isConfidential">
                    Confidential — hide from the public lost-and-found page and from non-managers entirely
                </label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="publicPageEnabled" name="publicPageEnabled"<?php echo $checked($isEdit === false || (int) ($asset['publicPageEnabled'] ?? 1) === 1); ?>>
                <label class="form-check-label" for="publicPageEnabled">
                    Show this asset's lost-and-found page (still requires the global setting to be on, and is always hidden while Confidential is checked)
                </label>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-floppy-disk me-1"></i><?php echo $isEdit === true ? 'Save changes' : 'Create asset'; ?>
        </button>
        <a href="<?php echo $isEdit === true ? '/assets/item?id=' . $assetId : '/assets'; ?>" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>

<script nonce="<?php echo $nonce; ?>">
(function () {
    'use strict';
    // 🎛️ Progressive-enhancement toggle only — see file header docblock.
    // Hiding this card does NOT stop its fields being submitted; save.php
    // stores whatever arrives regardless of assetKind.
    var kindSelect = document.getElementById('assetKind');
    var digitalCard = document.getElementById('digitalFieldsCard');
    function sync() {
        if (kindSelect === null || digitalCard === null) {
            return;
        }
        digitalCard.style.display = kindSelect.value === 'digital' ? '' : 'none';
    }
    if (kindSelect !== null) {
        kindSelect.addEventListener('change', sync);
        sync();
    }
})();
</script>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
