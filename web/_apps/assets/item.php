<?php
// Path: _apps/assets/item.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — View Asset 📦
 * -----------------------------------------------------------------------------
 * Full detail view for a single asset — core fields, the Resources panel
 * (list + add-link/upload + delete), a manager-only licence-key reveal for
 * digital assets, a compact recent-audit strip, and placeholder cards for
 * the sub-features that arrive in later Asset Tracker sub-issues (Owners /
 * Loans / Maintenance / Identifiers / Licence seats / Labels).
 *
 * ACCESS MODEL (#395-style — read before changing):
 *   `Auth::requireLogin()` gates every viewer. On top of that, when the
 *   asset is `isConfidential`, only an admin, the asset_manager role, or a
 *   responsible owner-party (`AssetRegister::isResponsibleFor()`) may view
 *   it — everyone else gets a plain `Router::renderError(404)`, the SAME
 *   response as "this assetID doesn't exist", so a logged-in-but-
 *   unprivileged user can't use this page as an oracle to learn which
 *   asset ids are confidential. `resource-download.php` applies the
 *   identical rule for resource files belonging to a confidential asset.
 *
 * LICENCE-KEY REVEAL: the decrypted licence key is only ever computed
 * (`AssetRegister::decryptLicenseKey()`) and embedded in the response when
 * `$canManage === true` — a non-manager viewer never causes a decrypt call
 * at all, and the markup for a non-manager never contains the plaintext in
 * any form (masked placeholder text only). For a manager, the plaintext IS
 * present in the server-rendered HTML (behind a client-side show/hide
 * toggle) rather than fetched via a separate on-demand endpoint — there is
 * no such endpoint registered for this sub-issue, so this is the simplest
 * option that still keeps the value off the page for every non-manager.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/394
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
$assetId = (int) ($_GET['id'] ?? 0);

$asset = $assetId > 0 ? AssetRegister::get($assetId) : null;
if ($asset === null || (int) $asset['siteID'] !== $siteId) {
    Router::renderError(404);
    return;
}

$canManage    = App::isAdmin() === true || App::hasRole('asset_manager') === true;
$isResponsible = $canManage === false && AssetRegister::isResponsibleFor($assetId, $userId);
$privileged   = $canManage === true || $isResponsible === true;

// 🔒 Confidential-asset gate — see file header's ACCESS MODEL note. Uniform
// 404, never a 403, so this page can't be used as an existence oracle.
if ((int) $asset['isConfidential'] === 1 && $privileged === false) {
    Router::renderError(404);
    return;
}

// 📋 Category / location names — small inline lookups rather than a whole
// AssetRegister method for a single-row-by-id fetch.
$categoryName = null;
if ($asset['categoryID'] !== null) {
    $cStmt = $db->prepare('SELECT categoryName FROM tblAssetCategories WHERE categoryID = ? LIMIT 1');
    if ($cStmt !== false) {
        $catId = (int) $asset['categoryID'];
        $cStmt->bind_param('i', $catId);
        $cStmt->execute();
        $cRow = $cStmt->get_result()->fetch_assoc();
        $cStmt->close();
        $categoryName = $cRow !== null ? (string) $cRow['categoryName'] : null;
    }
}
$locationName = null;
if ($asset['locationID'] !== null) {
    $lStmt = $db->prepare('SELECT locationName FROM tblAssetLocations WHERE locationID = ? LIMIT 1');
    if ($lStmt !== false) {
        $locId = (int) $asset['locationID'];
        $lStmt->bind_param('i', $locId);
        $lStmt->execute();
        $lRow = $lStmt->get_result()->fetch_assoc();
        $lStmt->close();
        $locationName = $lRow !== null ? (string) $lRow['locationName'] : null;
    }
}
$parentAssetName = null;
if ($asset['parentAssetID'] !== null) {
    $paStmt = $db->prepare('SELECT name FROM tblAssets WHERE assetID = ? AND isDeleted = 0 LIMIT 1');
    if ($paStmt !== false) {
        $paId = (int) $asset['parentAssetID'];
        $paStmt->bind_param('i', $paId);
        $paStmt->execute();
        $paRow = $paStmt->get_result()->fetch_assoc();
        $paStmt->close();
        $parentAssetName = $paRow !== null ? (string) $paRow['name'] : null;
    }
}

// 📎 Resources.
$resources = AssetRegister::listResources($assetId);

// 🔐 Licence key — decrypted ONLY for managers, ONLY when set. See file
// header's LICENCE-KEY REVEAL note.
$isDigital     = (string) $asset['assetKind'] === 'digital';
$hasLicenseKey = $asset['licenseKey'] !== null && (string) $asset['licenseKey'] !== '';
$licenseKeyPlain = '';
if ($canManage === true && $hasLicenseKey === true) {
    $licenseKeyPlain = AssetRegister::decryptLicenseKey((string) $asset['licenseKey']);
}

// 📜 Recent audit strip — last 8 rows for this asset, actor name resolved
// via a LEFT JOIN (tblAssetAudit carries no FK by design — see migration
// 159's header — so the actor row may no longer exist).
$auditRows = [];
$aStmt = $db->prepare(
    'SELECT a.entityType, a.action, a.createdAt, a.actorType, u.fullName '
    . 'FROM tblAssetAudit a LEFT JOIN tblUsers u ON u.userID = a.actorUserID '
    . 'WHERE a.assetID = ? ORDER BY a.createdAt DESC LIMIT 8'
);
if ($aStmt !== false) {
    $aStmt->bind_param('i', $assetId);
    $aStmt->execute();
    $aResult = $aStmt->get_result();
    while ($row = $aResult->fetch_assoc()) {
        $auditRows[] = $row;
    }
    $aStmt->close();
}

$csrf = Auth::csrfToken();

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$statusBadge = [
    'in-service' => 'success',
    'in-repair'  => 'warning',
    'on-loan'    => 'info',
    'borrowed'   => 'info',
    'in-storage' => 'secondary',
    'retired'    => 'secondary',
    'disposed'   => 'dark',
    'lost'       => 'danger',
    'stolen'     => 'danger',
];
$resourceIcon = [
    'manual' => 'fa-book', 'guide' => 'fa-circle-question', 'video' => 'fa-video',
    'photo' => 'fa-image', 'receipt' => 'fa-receipt', 'ownership-agreement' => 'fa-file-signature',
    'insurance' => 'fa-shield-halved', 'legal' => 'fa-gavel', 'other' => 'fa-paperclip',
];

$pageTitle   = (string) $asset['name'];
$pageSection = 'assets';
$breadcrumbs = ['Dashboard' => '/', 'Assets' => '/assets', (string) $asset['name'] => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$nonce = htmlspecialchars(App::cspNonce(), ENT_QUOTES, 'UTF-8');
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3">
    <div>
        <h1 class="mb-1">
            <i class="fa-solid <?php echo $isDigital === true ? 'fa-cloud' : 'fa-box'; ?> me-2"></i>
            <?php echo htmlspecialchars((string) $asset['name'], ENT_QUOTES, 'UTF-8'); ?>
            <?php if ((int) $asset['isConfidential'] === 1): ?>
                <span class="badge bg-secondary" title="Confidential — hidden from the public lost-and-found page and non-managers">
                    <i class="fa-solid fa-lock"></i> Confidential
                </span>
            <?php endif; ?>
        </h1>
        <span class="badge bg-<?php echo htmlspecialchars($statusBadge[(string) $asset['status']] ?? 'secondary', ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars(ucwords(str_replace('-', ' ', (string) $asset['status'])), ENT_QUOTES, 'UTF-8'); ?>
        </span>
        <span class="text-muted small ms-2"><?php echo htmlspecialchars(ucwords((string) $asset['conditionState']), ENT_QUOTES, 'UTF-8'); ?> condition</span>
    </div>
    <?php if ($canManage === true): ?>
        <div class="d-flex gap-2 mt-2 mt-md-0">
            <a href="/assets/edit?id=<?php echo $assetId; ?>" class="btn btn-outline-primary btn-sm">
                <i class="fa-solid fa-pen me-1"></i>Edit
            </a>
            <form method="post" action="/assets/delete" data-confirm="Delete this asset? This can't be undone from the UI." data-confirm-destructive="true">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="assetID" value="<?php echo $assetId; ?>">
                <button type="submit" class="btn btn-outline-danger btn-sm">
                    <i class="fa-solid fa-trash me-1"></i>Delete
                </button>
            </form>
        </div>
    <?php endif; ?>
</div>

<!-- 🧾 Core details -->
<div class="card mb-3">
    <div class="card-header"><h2 class="h5 mb-0">Details</h2></div>
    <div class="card-body row g-3">
        <?php if ($asset['description'] !== null && (string) $asset['description'] !== ''): ?>
            <div class="col-12"><?php echo nl2br(htmlspecialchars((string) $asset['description'], ENT_QUOTES, 'UTF-8')); ?></div>
        <?php endif; ?>
        <div class="col-md-3"><strong>Category</strong><br><?php echo $categoryName !== null ? htmlspecialchars($categoryName, ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>'; ?></div>
        <div class="col-md-3"><strong>Location</strong><br><?php echo $locationName !== null ? htmlspecialchars($locationName, ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>'; ?></div>
        <div class="col-md-3"><strong>Manufacturer</strong><br><?php echo $asset['manufacturer'] !== null ? htmlspecialchars((string) $asset['manufacturer'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>'; ?></div>
        <div class="col-md-3"><strong>Model</strong><br><?php echo $asset['model'] !== null ? htmlspecialchars((string) $asset['model'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>'; ?></div>
        <div class="col-md-3"><strong>Serial number</strong><br><?php echo $asset['serialNumber'] !== null ? htmlspecialchars((string) $asset['serialNumber'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>'; ?></div>
        <div class="col-md-3"><strong>Asset tag code</strong><br><?php echo $asset['assetTagCode'] !== null ? htmlspecialchars((string) $asset['assetTagCode'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>'; ?></div>
        <div class="col-md-3"><strong>Parent asset</strong><br>
            <?php if ($parentAssetName !== null): ?>
                <a href="/assets/item?id=<?php echo (int) $asset['parentAssetID']; ?>"><?php echo htmlspecialchars($parentAssetName, ENT_QUOTES, 'UTF-8'); ?></a>
            <?php else: ?>
                <span class="text-muted">—</span>
            <?php endif; ?>
        </div>
        <?php if ($asset['features'] !== null && (string) $asset['features'] !== ''): ?>
            <div class="col-12"><strong>Features</strong><br><?php echo nl2br(htmlspecialchars((string) $asset['features'], ENT_QUOTES, 'UTF-8')); ?></div>
        <?php endif; ?>
    </div>
</div>

<!-- 💷 Purchase & warranty -->
<div class="card mb-3">
    <div class="card-header"><h2 class="h5 mb-0">Purchase &amp; warranty</h2></div>
    <div class="card-body row g-3">
        <div class="col-md-3"><strong>Purchase date</strong><br><?php echo $asset['purchaseDate'] !== null ? htmlspecialchars((string) $asset['purchaseDate'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>'; ?></div>
        <div class="col-md-3"><strong>Purchased from</strong><br><?php echo $asset['purchaseStore'] !== null ? htmlspecialchars((string) $asset['purchaseStore'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>'; ?></div>
        <div class="col-md-3"><strong>Cost</strong><br>
            <?php echo $asset['purchaseCostPence'] !== null
                ? htmlspecialchars((string) $asset['currency'], ENT_QUOTES, 'UTF-8') . ' ' . number_format(((int) $asset['purchaseCostPence']) / 100, 2)
                : '<span class="text-muted">—</span>'; ?>
        </div>
        <div class="col-md-3"><strong>Warranty expiry</strong><br><?php echo $asset['warrantyExpiry'] !== null ? htmlspecialchars((string) $asset['warrantyExpiry'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>'; ?></div>
        <?php if ($asset['warrantyDetails'] !== null && (string) $asset['warrantyDetails'] !== ''): ?>
            <div class="col-12"><strong>Warranty details</strong><br><?php echo htmlspecialchars((string) $asset['warrantyDetails'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
    </div>
</div>

<?php if ($isDigital === true): ?>
<!-- 💻 Digital / licensing -->
<div class="card mb-3">
    <div class="card-header"><h2 class="h5 mb-0">Digital / licensing</h2></div>
    <div class="card-body row g-3">
        <div class="col-md-6">
            <strong>Licence key</strong><br>
            <?php if ($hasLicenseKey === false): ?>
                <span class="text-muted">Not set</span>
            <?php elseif ($canManage === false): ?>
                <span class="text-muted"><i class="fa-solid fa-lock me-1"></i>Hidden — manager access required</span>
            <?php else: ?>
                <span id="licenseKeyMasked">••••••••••••••••</span>
                <span id="licenseKeyPlain" hidden><?php echo htmlspecialchars($licenseKeyPlain, ENT_QUOTES, 'UTF-8'); ?></span>
                <button type="button" class="btn btn-sm btn-outline-secondary ms-1" id="licenseKeyToggle">
                    <i class="fa-solid fa-eye me-1"></i>Reveal
                </button>
            <?php endif; ?>
        </div>
        <div class="col-md-2"><strong>Seats</strong><br><?php echo $asset['licenseSeats'] !== null ? (int) $asset['licenseSeats'] : '<span class="text-muted">—</span>'; ?></div>
        <div class="col-md-2"><strong>Renewal date</strong><br><?php echo $asset['renewalDate'] !== null ? htmlspecialchars((string) $asset['renewalDate'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>'; ?></div>
        <div class="col-md-2"><strong>Access URL</strong><br>
            <?php if ($asset['accessUrl'] !== null): ?>
                <a href="<?php echo htmlspecialchars((string) $asset['accessUrl'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">Open <i class="fa-solid fa-arrow-up-right-from-square fa-xs"></i></a>
            <?php else: ?>
                <span class="text-muted">—</span>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php if ($canManage === true && $hasLicenseKey === true): ?>
<script nonce="<?php echo $nonce; ?>">
(function () {
    'use strict';
    var toggle = document.getElementById('licenseKeyToggle');
    var masked = document.getElementById('licenseKeyMasked');
    var plain  = document.getElementById('licenseKeyPlain');
    if (toggle === null || masked === null || plain === null) {
        return;
    }
    toggle.addEventListener('click', function () {
        var revealed = plain.hidden === false;
        plain.hidden  = revealed;
        masked.hidden = !revealed;
        toggle.innerHTML = revealed
            ? '<i class="fa-solid fa-eye me-1"></i>Reveal'
            : '<i class="fa-solid fa-eye-slash me-1"></i>Hide';
    });
})();
</script>
<?php endif; ?>
<?php endif; ?>

<!-- 📎 Resources -->
<div class="card mb-3">
    <div class="card-header"><h2 class="h5 mb-0">Resources</h2></div>
    <div class="card-body">
        <?php if (count($resources) === 0): ?>
            <p class="text-muted">No resources attached yet.</p>
        <?php else: ?>
            <div class="portal-data-list mb-3">
                <?php foreach ($resources as $res): ?>
                    <div class="portal-data-row align-items-center">
                        <div class="col-6 col-md-5">
                            <i class="fa-solid <?php echo htmlspecialchars($resourceIcon[(string) $res['resourceType']] ?? 'fa-paperclip', ENT_QUOTES, 'UTF-8'); ?> me-2 text-muted"></i>
                            <?php echo htmlspecialchars((string) $res['title'], ENT_QUOTES, 'UTF-8'); ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars(ucwords(str_replace('-', ' ', (string) $res['resourceType'])), ENT_QUOTES, 'UTF-8'); ?></small>
                        </div>
                        <div class="col-4 col-md-4">
                            <?php if ($res['linkUrl'] !== null): ?>
                                <a href="<?php echo htmlspecialchars((string) $res['linkUrl'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">
                                    <i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Open link
                                </a>
                            <?php else: ?>
                                <a href="/assets/resource-download?id=<?php echo (int) $res['resourceID']; ?>">
                                    <i class="fa-solid fa-download me-1"></i><?php echo htmlspecialchars((string) $res['fileName'], ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="col-2 col-md-3 text-end">
                            <?php if ($privileged === true): ?>
                                <form method="post" action="/assets/resource-save" class="d-inline"
                                      data-confirm="Remove this resource?" data-confirm-destructive="true">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="assetID" value="<?php echo $assetId; ?>">
                                    <input type="hidden" name="resourceID" value="<?php echo (int) $res['resourceID']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($privileged === true): ?>
            <hr>
            <h3 class="h6">Add a resource</h3>
            <form method="post" action="/assets/resource-save" enctype="multipart/form-data" class="row g-2">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="assetID" value="<?php echo $assetId; ?>">
                <div class="col-md-3">
                    <label class="form-label small" for="resourceType">Type</label>
                    <select class="form-select form-select-sm" id="resourceType" name="resourceType">
                        <?php foreach (AssetRegister::RESOURCE_TYPES as $rt): ?>
                            <option value="<?php echo htmlspecialchars($rt, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucwords(str_replace('-', ' ', $rt)), ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small" for="title">Title</label>
                    <input type="text" class="form-control form-control-sm" id="title" name="title" required maxlength="255">
                </div>
                <div class="col-md-3">
                    <label class="form-label small" for="linkUrl">Link URL (or use file below)</label>
                    <input type="url" class="form-control form-control-sm" id="linkUrl" name="linkUrl" maxlength="500" placeholder="https://…">
                </div>
                <div class="col-md-3">
                    <label class="form-label small" for="file">File (or use link above)</label>
                    <input type="file" class="form-control form-control-sm" id="file" name="file"
                           accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,.mp4,.webm,.txt,.docx,.xlsx">
                </div>
                <div class="col-12">
                    <small class="text-muted">Provide EITHER a link OR a file — not both. Max size and allowed file types are set by an admin.</small>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus me-1"></i>Add resource</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- 🚧 Placeholders for later sub-issues -->
<div class="row g-3 mb-3">
    <?php
    $placeholders = [
        ['icon' => 'fa-users',        'title' => 'Owners'],
        ['icon' => 'fa-right-left',   'title' => 'Loans'],
        ['icon' => 'fa-screwdriver-wrench', 'title' => 'Maintenance'],
        ['icon' => 'fa-barcode',      'title' => 'Identifiers'],
        ['icon' => 'fa-key',          'title' => 'Licence seats'],
        ['icon' => 'fa-tag',          'title' => 'Labels'],
    ];
    ?>
    <?php foreach ($placeholders as $p): ?>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card h-100 text-center text-muted">
                <div class="card-body">
                    <i class="fa-solid <?php echo htmlspecialchars($p['icon'], ENT_QUOTES, 'UTF-8'); ?> fa-lg mb-2"></i>
                    <div class="small fw-semibold"><?php echo htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="small">Arrives in a later sub-issue</div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- 📜 Recent activity -->
<div class="card mb-3">
    <div class="card-header"><h2 class="h5 mb-0">Recent activity</h2></div>
    <div class="card-body">
        <?php if (count($auditRows) === 0): ?>
            <p class="text-muted mb-0">No activity recorded yet.</p>
        <?php else: ?>
            <ul class="list-unstyled mb-0 small">
                <?php foreach ($auditRows as $row): ?>
                    <li class="mb-1">
                        <span class="text-muted"><?php echo htmlspecialchars((string) $row['createdAt'], ENT_QUOTES, 'UTF-8'); ?></span>
                        — <?php echo htmlspecialchars(ucwords(str_replace('-', ' ', (string) $row['entityType'])), ENT_QUOTES, 'UTF-8'); ?>
                        <?php echo htmlspecialchars((string) $row['action'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php if ($row['fullName'] !== null): ?>
                            by <?php echo htmlspecialchars((string) $row['fullName'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php elseif ((string) $row['actorType'] !== 'user'): ?>
                            (<?php echo htmlspecialchars((string) $row['actorType'], ENT_QUOTES, 'UTF-8'); ?>)
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<a href="/assets" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i>Back to Asset Tracker</a>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
