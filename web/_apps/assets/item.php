<?php
// Path: _apps/assets/item.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — View Asset 📦
 * -----------------------------------------------------------------------------
 * Full detail view for a single asset — core fields, the Resources panel
 * (list + add-link/upload + delete), a manager-only licence-key reveal for
 * digital assets, the Owners &amp; custodianship panel (#396), the
 * restricted Ownership &amp; legal vault panel (#396), a compact
 * recent-audit strip, and placeholder cards for the sub-features that
 * arrive in later Asset Tracker sub-issues (Loans / Maintenance /
 * Identifiers / Licence seats / Labels).
 *
 * OWNERS vs VAULT — two DIFFERENT visibility rules on this one page (#396):
 *   - The Owners panel's LIST is visible (read-only) to any logged-in
 *     viewer who reaches this page at all (i.e. already past the
 *     confidential-asset gate below) — knowing WHO owns/is-accountable-for
 *     an asset is not itself confidential. Editing it (add/remove/toggle
 *     authority/set terms) is manager-gated (`$canManage`), mirroring
 *     owners-save.php's own gate — deliberately NOT extended to
 *     `isResponsibleFor()` the way the Resources panel below is (see that
 *     controller's header for the rationale).
 *   - The Ownership & legal vault panel is RESTRICTED end-to-end
 *     (`$privileged` — admin/asset_manager/isResponsibleFor()) and is
 *     simply never rendered for anyone else, matching resource-save.php's
 *     own gate for the uploads it contains. Its contents
 *     (ownership-agreement/insurance/legal resources) are ALSO excluded
 *     from the general Resources panel's listing further down, however
 *     they were uploaded — see the `$resources` filtering below — so they
 *     can never leak to an ordinary logged-in viewer through that panel
 *     either.
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
 * @version   1.1.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/394
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

// 📎 Resources — split into "general" (any viewer who can see this asset)
// and the confidential vault subset (ownership-agreement/insurance/legal —
// #396). The vault types are excluded from $resources below no matter
// which upload form originally created them (this panel's own, or the
// vault panel's — both post to the same resource-save.php) so they can
// never render outside the $privileged-gated vault panel further down —
// see this file's header for the full OWNERS vs VAULT rationale.
$allResources = AssetRegister::listResources($assetId);
$vaultResourceTypes = AssetRegister::AGREEMENT_VAULT_RESOURCE_TYPES;
$resources = array_values(array_filter(
    $allResources,
    static fn (array $r): bool => in_array((string) $r['resourceType'], $vaultResourceTypes, true) === false
));

// 👥 Owners/custodians (#396) — list is visible to any viewer reaching this
// page; edit affordances below are gated on $canManage, not $privileged.
$owners = AssetRegister::listOwners($assetId);

// 📜 Ownership & legal vault contents — ONLY fetched when $privileged, so a
// non-privileged render never even holds these rows in memory (defence in
// depth on top of the panel itself never being rendered for anyone else).
$agreementDocs = $privileged === true ? AssetRegister::listAgreementDocs($assetId) : [];

// 🧑‍🤝‍🧑 Party pickers for the "add an owner" form — only fetched for a
// manager, since only a manager ever sees that form (owners-save.php's
// `add` action is manager-gated the same way as every other owner edit).
$ownerCandidateUsers  = [];
$ownerCandidateDepts  = [];
$ownerCandidateGroups = [];
$ownerCandidateOrgs   = [];
if ($canManage === true) {
    // 👤 Site-scoped active users — mirrors _apps/leadership/assign.php's
    // own "active users for this site" picker query.
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
            $ownerCandidateUsers[] = $row;
        }
        $uStmt->close();
    }

    // 🏢 Site-scoped active departments.
    $dStmt = $db->prepare('SELECT deptID, deptName FROM tblDepts WHERE siteID = ? AND isActive = 1 ORDER BY deptName ASC');
    if ($dStmt !== false) {
        $dStmt->bind_param('i', $siteId);
        $dStmt->execute();
        $dResult = $dStmt->get_result();
        while ($row = $dResult->fetch_assoc()) {
            $ownerCandidateDepts[] = $row;
        }
        $dStmt->close();
    }

    // 👥 Groups — GLOBAL reference data, no siteID column (see
    // AssetRegister::partyExistsOnSite()'s matching comment), so no site
    // filter applies here, unlike users/depts/orgs above.
    $gResult = $db->query('SELECT groupID, groupName FROM tblGroups ORDER BY groupName ASC');
    if ($gResult !== false) {
        while ($row = $gResult->fetch_assoc()) {
            $ownerCandidateGroups[] = $row;
        }
    }

    // 🏢 External organisations (#396) — active only, mirrors edit.php's
    // own "active-only for pickers" convention for categories/locations.
    $ownerCandidateOrgs = AssetRegister::listOrgs($siteId, true);
}

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
// 👥 Owners panel lookups (#396).
$partyIcon = [
    'user' => 'fa-user', 'dept' => 'fa-building', 'group' => 'fa-people-group', 'org' => 'fa-handshake',
];
$roleKindBadge = [
    'owner' => 'primary', 'co-owner' => 'info', 'custodian' => 'secondary', 'stakeholder' => 'dark',
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
                            <?php if (in_array($rt, AssetRegister::AGREEMENT_VAULT_RESOURCE_TYPES, true) === true) { continue; /* 🔒 vault types are added via the restricted vault panel below, not here */ } ?>
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
                    <small class="text-muted">Provide EITHER a link OR a file — not both. Max size and allowed file types are set by an admin. For ownership agreements, insurance, or legal documents, use the confidential Ownership &amp; legal vault below instead.</small>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus me-1"></i>Add resource</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- 👥 Owners & custodianship (#396) -->
<div class="card mb-3">
    <div class="card-header"><h2 class="h5 mb-0">Owners &amp; custodianship</h2></div>
    <div class="card-body">
        <?php if (count($owners) === 0): ?>
            <p class="text-muted">No owners recorded yet.</p>
        <?php else: ?>
            <div class="portal-data-list mb-3">
                <?php foreach ($owners as $o): ?>
                    <div class="portal-data-row align-items-center">
                        <div class="col-6 col-md-4">
                            <i class="fa-solid <?php echo htmlspecialchars($partyIcon[(string) $o['partyType']] ?? 'fa-question', ENT_QUOTES, 'UTF-8'); ?> me-2 text-muted"></i>
                            <?php echo htmlspecialchars((string) $o['partyName'], ENT_QUOTES, 'UTF-8'); ?>
                            <br>
                            <span class="badge bg-<?php echo htmlspecialchars($roleKindBadge[(string) $o['roleKind']] ?? 'secondary', ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars(ucwords(str_replace('-', ' ', (string) $o['roleKind'])), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <?php if ($o['notes'] !== null && (string) $o['notes'] !== ''): ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars((string) $o['notes'], ENT_QUOTES, 'UTF-8'); ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="col-3 col-md-3">
                            <?php
                            $authorityFlags = [
                                'isLendingAuthority'     => ['icon' => 'fa-key',                  'label' => 'Lending authority'],
                                'isMaintenanceAuthority' => ['icon' => 'fa-screwdriver-wrench',    'label' => 'Maintenance authority'],
                            ];
                            foreach ($authorityFlags as $field => $meta):
                                $isOn = (int) ($o[$field] ?? 0) === 1;
                                if ($canManage === true):
                            ?>
                                <form method="post" action="/assets/owners-save" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="toggle-authority">
                                    <input type="hidden" name="assetID" value="<?php echo $assetId; ?>">
                                    <input type="hidden" name="ownerID" value="<?php echo (int) $o['ownerID']; ?>">
                                    <input type="hidden" name="field" value="<?php echo htmlspecialchars($field, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="value" value="<?php echo $isOn === true ? '0' : '1'; ?>">
                                    <button type="submit" class="btn btn-sm <?php echo $isOn === true ? 'btn-warning' : 'btn-outline-secondary'; ?> mb-1"
                                            title="<?php echo htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8'); ?> — click to <?php echo $isOn === true ? 'revoke' : 'grant'; ?>">
                                        <i class="fa-solid <?php echo htmlspecialchars($meta['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
                                    </button>
                                </form>
                            <?php elseif ($isOn === true): ?>
                                <span class="badge bg-warning text-dark mb-1" title="<?php echo htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <i class="fa-solid <?php echo htmlspecialchars($meta['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
                                </span>
                            <?php endif; endforeach; ?>
                        </div>
                        <div class="col-1 col-md-2">
                            <?php echo $o['sharePercent'] !== null ? htmlspecialchars((string) $o['sharePercent'], ENT_QUOTES, 'UTF-8') . '%' : '<span class="text-muted">—</span>'; ?>
                        </div>
                        <div class="col-2 col-md-3 text-end">
                            <?php if ($canManage === true): ?>
                                <form method="post" action="/assets/owners-save" class="d-inline"
                                      data-confirm="Remove this owner?" data-confirm-destructive="true">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="assetID" value="<?php echo $assetId; ?>">
                                    <input type="hidden" name="ownerID" value="<?php echo (int) $o['ownerID']; ?>">
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

        <!-- 📜 Ownership terms -->
        <hr>
        <h3 class="h6">Ownership terms</h3>
        <?php if ($canManage === true): ?>
            <form method="post" action="/assets/owners-save" class="row g-2">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="set-terms">
                <input type="hidden" name="assetID" value="<?php echo $assetId; ?>">
                <div class="col-12">
                    <textarea class="form-control form-control-sm" name="ownershipTerms" rows="2" maxlength="65535"
                              placeholder="e.g. Loaned in from Riverside Trust under a 12-month renewable agreement — see the vault below for the signed copy."><?php echo htmlspecialchars((string) ($asset['ownershipTerms'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-floppy-disk me-1"></i>Save terms</button>
                </div>
            </form>
        <?php else: ?>
            <?php if ($asset['ownershipTerms'] !== null && (string) $asset['ownershipTerms'] !== ''): ?>
                <p class="mb-0"><?php echo nl2br(htmlspecialchars((string) $asset['ownershipTerms'], ENT_QUOTES, 'UTF-8')); ?></p>
            <?php else: ?>
                <p class="text-muted mb-0">No ownership terms recorded.</p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($canManage === true): ?>
            <!-- ➕ Add an owner — party-type selector reveals the matching picker (progressive enhancement — see script below; owners-save.php reads only the field matching the posted partyType regardless of which pickers were visible). -->
            <hr>
            <h3 class="h6">Add an owner / custodian</h3>
            <form method="post" action="/assets/owners-save" class="row g-2">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="assetID" value="<?php echo $assetId; ?>">
                <div class="col-md-3">
                    <label class="form-label small" for="ownerPartyType">Party type</label>
                    <select class="form-select form-select-sm" id="ownerPartyType" name="partyType">
                        <option value="user">Person</option>
                        <option value="dept">Department</option>
                        <option value="group">Group</option>
                        <option value="org">External organisation</option>
                    </select>
                </div>
                <div class="col-md-3" id="partyPickerWrap-user">
                    <label class="form-label small" for="partyUserID">Person</label>
                    <select class="form-select form-select-sm" id="partyUserID" name="partyUserID">
                        <option value="">Select…</option>
                        <?php foreach ($ownerCandidateUsers as $u): ?>
                            <option value="<?php echo (int) $u['userID']; ?>"><?php echo htmlspecialchars((string) ($u['fullName'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3" id="partyPickerWrap-dept" hidden>
                    <label class="form-label small" for="partyDeptID">Department</label>
                    <select class="form-select form-select-sm" id="partyDeptID" name="partyDeptID">
                        <option value="">Select…</option>
                        <?php foreach ($ownerCandidateDepts as $d): ?>
                            <option value="<?php echo (int) $d['deptID']; ?>"><?php echo htmlspecialchars((string) ($d['deptName'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3" id="partyPickerWrap-group" hidden>
                    <label class="form-label small" for="partyGroupID">Group</label>
                    <select class="form-select form-select-sm" id="partyGroupID" name="partyGroupID">
                        <option value="">Select…</option>
                        <?php foreach ($ownerCandidateGroups as $g): ?>
                            <option value="<?php echo (int) $g['groupID']; ?>"><?php echo htmlspecialchars((string) ($g['groupName'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3" id="partyPickerWrap-org" hidden>
                    <label class="form-label small" for="partyOrgID">External organisation</label>
                    <select class="form-select form-select-sm" id="partyOrgID" name="partyOrgID">
                        <option value="">Select…</option>
                        <?php foreach ($ownerCandidateOrgs as $org): ?>
                            <option value="<?php echo (int) $org['orgID']; ?>"><?php echo htmlspecialchars((string) $org['orgName'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted"><a href="/assets/orgs">Manage organisations</a></small>
                </div>
                <div class="col-md-2">
                    <label class="form-label small" for="roleKind">Role</label>
                    <select class="form-select form-select-sm" id="roleKind" name="roleKind">
                        <?php foreach (AssetRegister::OWNER_ROLE_KINDS as $rk): ?>
                            <option value="<?php echo htmlspecialchars($rk, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucwords(str_replace('-', ' ', $rk)), ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small" for="sharePercent">Share %</label>
                    <input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm" id="sharePercent" name="sharePercent" placeholder="optional">
                </div>
                <div class="col-md-4">
                    <label class="form-label small" for="ownerNotes">Notes</label>
                    <input type="text" class="form-control form-control-sm" id="ownerNotes" name="notes" maxlength="500">
                </div>
                <div class="col-md-8">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="isLendingAuthority" name="isLendingAuthority">
                        <label class="form-check-label small" for="isLendingAuthority"><i class="fa-solid fa-key me-1"></i>Lending authority</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="isMaintenanceAuthority" name="isMaintenanceAuthority">
                        <label class="form-check-label small" for="isMaintenanceAuthority"><i class="fa-solid fa-screwdriver-wrench me-1"></i>Maintenance authority</label>
                    </div>
                </div>
                <div class="col-md-4 text-end">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus me-1"></i>Add owner</button>
                </div>
            </form>
            <script nonce="<?php echo $nonce; ?>">
            (function () {
                'use strict';
                // 🎛️ Progressive-enhancement toggle only — mirrors edit.php's
                // digitalFieldsCard script. Hiding the non-matching pickers
                // does NOT stop their fields being submitted; owners-save.php
                // reads only the field matching the posted partyType.
                var typeSelect = document.getElementById('ownerPartyType');
                var wraps = {
                    user: document.getElementById('partyPickerWrap-user'),
                    dept: document.getElementById('partyPickerWrap-dept'),
                    group: document.getElementById('partyPickerWrap-group'),
                    org: document.getElementById('partyPickerWrap-org')
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
        <?php endif; ?>
    </div>
</div>

<?php if ($privileged === true): ?>
<!-- 🔒 Ownership & legal vault (#396) — RESTRICTED, see file header's OWNERS vs VAULT note. -->
<div class="card mb-3 border-warning-subtle">
    <div class="card-header bg-warning-subtle">
        <h2 class="h5 mb-0">
            <i class="fa-solid fa-vault me-2"></i>Ownership &amp; legal vault
            <span class="badge bg-danger ms-2"><i class="fa-solid fa-lock me-1"></i>Confidential</span>
        </h2>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            <i class="fa-solid fa-triangle-exclamation me-1"></i>
            Ownership agreements, insurance documents, and legal paperwork stored here are visible ONLY to admins, asset managers, and responsible owner-parties for this asset. They are <strong>never</strong> shown on the public lost-and-found page, in the general Resources panel above, or to any other viewer.
        </p>
        <?php if (count($agreementDocs) === 0): ?>
            <p class="text-muted">No ownership/legal documents attached yet.</p>
        <?php else: ?>
            <div class="portal-data-list mb-3">
                <?php foreach ($agreementDocs as $doc): ?>
                    <div class="portal-data-row align-items-center">
                        <div class="col-6 col-md-5">
                            <i class="fa-solid <?php echo htmlspecialchars($resourceIcon[(string) $doc['resourceType']] ?? 'fa-paperclip', ENT_QUOTES, 'UTF-8'); ?> me-2 text-muted"></i>
                            <?php echo htmlspecialchars((string) $doc['title'], ENT_QUOTES, 'UTF-8'); ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars(ucwords(str_replace('-', ' ', (string) $doc['resourceType'])), ENT_QUOTES, 'UTF-8'); ?></small>
                        </div>
                        <div class="col-4 col-md-4">
                            <?php if ($doc['linkUrl'] !== null): ?>
                                <a href="<?php echo htmlspecialchars((string) $doc['linkUrl'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">
                                    <i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Open link
                                </a>
                            <?php else: ?>
                                <a href="/assets/resource-download?id=<?php echo (int) $doc['resourceID']; ?>">
                                    <i class="fa-solid fa-download me-1"></i><?php echo htmlspecialchars((string) $doc['fileName'], ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="col-2 col-md-3 text-end">
                            <form method="post" action="/assets/resource-save" class="d-inline"
                                  data-confirm="Remove this confidential document?" data-confirm-destructive="true">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="assetID" value="<?php echo $assetId; ?>">
                                <input type="hidden" name="resourceID" value="<?php echo (int) $doc['resourceID']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <hr>
        <h3 class="h6">Upload a confidential document</h3>
        <form method="post" action="/assets/resource-save" enctype="multipart/form-data" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="assetID" value="<?php echo $assetId; ?>">
            <div class="col-md-3">
                <label class="form-label small" for="vaultResourceType">Type</label>
                <select class="form-select form-select-sm" id="vaultResourceType" name="resourceType">
                    <?php foreach (AssetRegister::AGREEMENT_VAULT_RESOURCE_TYPES as $rt): ?>
                        <option value="<?php echo htmlspecialchars($rt, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucwords(str_replace('-', ' ', $rt)), ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small" for="vaultTitle">Title</label>
                <input type="text" class="form-control form-control-sm" id="vaultTitle" name="title" required maxlength="255">
            </div>
            <div class="col-md-3">
                <label class="form-label small" for="vaultLinkUrl">Link URL (or use file below)</label>
                <input type="url" class="form-control form-control-sm" id="vaultLinkUrl" name="linkUrl" maxlength="500" placeholder="https://…">
            </div>
            <div class="col-md-3">
                <label class="form-label small" for="vaultFile">File (or use link above)</label>
                <input type="file" class="form-control form-control-sm" id="vaultFile" name="file"
                       accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,.mp4,.webm,.txt,.docx,.xlsx">
            </div>
            <div class="col-12">
                <small class="text-muted">Provide EITHER a link OR a file — not both. This upload is <strong>never</strong> shown publicly, regardless of file type (enforced at the model, not just this form).</small>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-warning btn-sm"><i class="fa-solid fa-lock me-1"></i>Upload confidential document</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- 🚧 Placeholders for later sub-issues -->
<div class="row g-3 mb-3">
    <?php
    $placeholders = [
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
