<?php
// Path: _apps/assets/item.php
/**
 * -----------------------------------------------------------------------------
 * Asset Tracker — View Asset 📦
 * -----------------------------------------------------------------------------
 * Full detail view for a single asset — core fields, the Loans panel
 * (#398, lend &amp; borrow / approval / condition in-out), the Maintenance
 * panel (#399, service/repair/inspection/calibration history + a
 * depreciation/value readout in the Purchase &amp; warranty card), the
 * Resources panel (list + add-link/upload + delete), the Licence &amp;
 * seats panel for digital assets (#400, seat-count header + manager-only
 * licence-key reveal + active/released seat assignments + link-seat form),
 * the Owners &amp; custodianship panel (#396), the Identifiers panel
 * (#397, GS1 family / EPC-RFID), the restricted Ownership &amp; legal
 * vault panel (#396), a compact recent-audit strip, and a placeholder card
 * for the one sub-feature that still arrives in a later Asset Tracker
 * sub-issue (Labels).
 *
 * LICENCE &amp; SEATS panel (#400) — renders ONLY for `assetKind='digital'`
 * assets (`$isDigital`). Same read-visible/edit-manager-gated split as the
 * Loans/Maintenance panels above: the seat-count header and the active +
 * released assignment lists are visible to any viewer who reaches this
 * page at all; the "Link a seat" form and each row's Release button are
 * `$canManage`-only (manager-only, deliberately NOT extended to
 * `isMaintenanceAuthority`/`isLendingAuthority`/`isResponsibleFor()` — see
 * `AssetRegister::assignSeat()`'s class-header doc, point 7, and
 * `license-save.php`'s own header for the rationale) — `license-save.php`/
 * `license-action.php` re-derive and re-check that same gate independently
 * server-side, so a hidden control is never the only thing stopping an
 * unauthorised POST. The masked licence key + manager-only reveal
 * previously shown in the "Digital / licensing" card now lives inside this
 * panel instead (moved, not duplicated — see that card's own reduced
 * field-set below) so there is exactly ONE `licenseKeyMasked`/
 * `licenseKeyPlain`/`licenseKeyToggle` DOM triple on the page; it reuses
 * `AssetRegister::decryptLicenseKey()` completely unchanged from #394 (see
 * this file's own LICENCE-KEY REVEAL note further down) — this pass never
 * re-implements that decrypt path.
 *
 * LOANS panel (#398) — the list itself (current open loans + a collapsible
 * closed-loan history) is visible to any viewer who reaches this page at
 * all, same read-visible convention as Owners/Identifiers above. Every
 * action button (approve/decline/checkout/checkin/cancel) is gated
 * per-button, computed ONCE via `$canApproveThisLoan =
 * AssetRegister::canApproveLoan($assetId, $userId)` plus a per-loan
 * "is this viewer the original requester" check for checkin/cancel (which
 * that pair of actions also allows) — approve/decline/checkout show ONLY
 * for `$canApproveThisLoan`. `AssetRegister::loanAction()` re-derives and
 * re-checks every one of these gates independently server-side (see that
 * method's own doc) — a button this page chooses not to render is never
 * the ONLY thing stopping an unauthorised POST from succeeding.
 *
 * MAINTENANCE panel (#399) — same read-visible/edit-authority-gated split
 * as the Loans panel above: the history list is visible to any viewer who
 * reaches this page at all; the add-entry form and each row's edit/delete
 * controls are shown only for `$canManageMaintenance =
 * AssetRegister::canManageMaintenance($assetId, $userId)` — an EXACT
 * mirror of `canApproveLoan()` narrowed to the `isMaintenanceAuthority`
 * flag, deliberately NOT the general `$canManage`-only gate the Owners
 * panel uses (a maintenance-authority owner-party need not be an admin or
 * hold the asset_manager role). `maintenance-save.php` re-derives and
 * re-checks that same gate independently server-side, so a hidden control
 * is never the only thing stopping an unauthorised POST. The Depreciation
 * readout inside the "Purchase, warranty & depreciation" card
 * (`AssetRegister::computeStraightLineValue()`) is DISPLAY-ONLY and never
 * invents a value — it renders nothing when the asset's depreciation
 * method isn't 'straight-line' or any of purchaseCostPence/
 * usefulLifeMonths/purchaseDate is missing.
 *
 * IDENTIFIERS panel (#397) — same read-visible/edit-manager-gated split as
 * the Owners panel immediately above it (see that note below): the list
 * (grouped by category — GS1 keys / retail barcodes / tags &amp; carriers /
 * classification) is visible to any viewer who reaches this page at all;
 * add/remove/set-primary are `$canManage`-only, mirrored server-side in
 * identifiers-save.php. Format/check-digit validation
 * (`AssetRegister::validateIdentifier()`, reused unchanged from the #394/
 * #395 pass) is NON-BLOCKING — every add always saves; a failed/uncertain
 * validation only withholds the ✔ verified badge and surfaces an
 * informational warning, it never rejects the identifier. The 21 seeded
 * identifier TYPES (migration 159) are the only ones offered in the
 * add-form's dropdown — managing that vocabulary itself is intentionally
 * OUT of scope for #397 (no admin screen, no new migration this pass).
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
 * ASSIGNED EVENTS panel (#409, Phase 2 Pass 2) — same read-visible/
 * edit-gated split as the Loans/Maintenance panels above: the assignment
 * history is visible to any viewer reaching this page; the assign form
 * and each row's Unassign button are `$privileged`-only (reuses the SAME
 * admin/asset_manager/isResponsibleFor() flag the Ownership & legal vault
 * panel already computes above — assigning an asset to an event is a
 * custodianship action, not a manager-only register edit, so it's
 * deliberately NOT narrowed to `$canManage`). `event-assign.php`
 * re-derives and re-checks that same gate independently server-side. The
 * "assign to event" picker supports an optional `?eventID=` query-string
 * prefill (a deep-link from the calendar event page's own "Assigned
 * assets" section) — display-only convenience, never trusted as
 * authorisation; `AssetRegister::assignToEvent()` re-validates the
 * eventID against `Site::id()` regardless of what pre-selected an option.
 *
 * SCAN-ANALYTICS strip (#410, Phase 2 Pass 2) — `$canManage`-only (mirrors
 * the found-reports summary's own manager-only gate above), a 30-day
 * daily-count sparkbar built from `AssetRegister::scanStats()` with plain
 * flexbox + inline computed heights — no JS chart library.
 *
 * INSURANCE panel (#404 columns, first surfaced this pass — #408) —
 * `$privileged`-gated, the SAME admin/asset_manager/`isResponsibleFor()`
 * gate as the Ownership & legal vault panel immediately above it (a
 * policy number/insured value sits at roughly that same sensitivity —
 * never shown to a plain logged-in viewer who merely reached this page).
 * Only rendered when at least one of the four fields is actually set.
 * Read-only here — editing lives on `edit.php`/`save.php`. Flags an
 * "under-insured" badge when `insuredValuePence` is below the existing
 * Depreciation card's `$estimatedCurrentValuePence` readout.
 *
 * @package   Portal\Assets
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.6.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/393
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/394
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/396
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/397
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/398
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/399
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/400
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/404
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/408
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/409
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/410
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

// 🆔 Identifiers (#397) — GS1/barcode/RFID identifiers. Same read-visible/
// edit-manager-gated split as the Owners panel above: the list is shown to
// any viewer reaching this page; add/remove/set-primary are $canManage-only
// (identifiers-save.php enforces the same gate server-side).
$identifiers = AssetRegister::listIdentifiers($assetId);

// 🔢 Identifier-type picker for the "add an identifier" form — only
// fetched for a manager, since only a manager ever sees that form
// (identifiers-save.php's `add` action is manager-gated the same way as
// every other identifier edit).
$identifierTypes = $canManage === true ? AssetRegister::listIdentifierTypes() : [];

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

// 🎟️ Licence seat assignments (#400) — only meaningful for digital assets;
// fetched unconditionally (both active + released) when $isDigital so the
// panel below can render the active list AND the collapsible "Show
// history" section from one call, mirrors listMaintenance()'s "always
// fetch, template splits by field" convention. AssetRegister::seatSummary()
// is null-safe when the asset's licenseSeats column is unset (unlimited
// licence — no seat cap configured).
$licenseAssignments         = [];
$licenseActiveAssignments   = [];
$licenseReleasedAssignments = [];
$licenseSeatSummary         = ['seats' => null, 'used' => 0, 'free' => null, 'over' => false];
$licenseCandidateDevices    = [];
$licenseCandidateUsers      = [];
if ($isDigital === true) {
    $licenseAssignments = AssetRegister::listLicenseAssignments($assetId);
    $licenseActiveAssignments = array_values(array_filter(
        $licenseAssignments,
        static fn (array $la): bool => (string) $la['status'] === 'active'
    ));
    $licenseReleasedAssignments = array_values(array_filter(
        $licenseAssignments,
        static fn (array $la): bool => (string) $la['status'] !== 'active'
    ));
    $licenseSeatSummary = AssetRegister::seatSummary(
        $assetId,
        $asset['licenseSeats'] !== null ? (int) $asset['licenseSeats'] : null
    );

    // 🧑‍💻 Target pickers for the "link a seat" form — only fetched for a
    // manager, since only a manager ever sees that form (license-save.php's
    // gate is manager-only — see that file's header). Mirrors the Owners/
    // Maintenance panels' own manager-only picker-fetch pattern above.
    if ($canManage === true) {
        // 💻 Any other non-deleted asset on this site can be a "device" —
        // deliberately not restricted to assetKind='physical' (a licence
        // seat could legitimately sit on another digital asset, e.g. a VM);
        // AssetRegister::assignSeat() re-validates it's a real tblAssets
        // row on this site regardless of what this picker offers.
        $ldStmt = $db->prepare(
            'SELECT assetID, name FROM tblAssets WHERE siteID = ? AND isDeleted = 0 AND assetID != ? ORDER BY name ASC'
        );
        if ($ldStmt !== false) {
            $ldStmt->bind_param('ii', $siteId, $assetId);
            $ldStmt->execute();
            $ldResult = $ldStmt->get_result();
            while ($row = $ldResult->fetch_assoc()) {
                $licenseCandidateDevices[] = $row;
            }
            $ldStmt->close();
        }

        $luStmt = $db->prepare(
            'SELECT u.userID, u.fullName FROM tblUsers u '
            . 'INNER JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
            . 'WHERE u.isActive = 1 ORDER BY u.fullName ASC'
        );
        if ($luStmt !== false) {
            $luStmt->bind_param('i', $siteId);
            $luStmt->execute();
            $luResult = $luStmt->get_result();
            while ($row = $luResult->fetch_assoc()) {
                $licenseCandidateUsers[] = $row;
            }
            $luStmt->close();
        }
    }
}

// 🔄 Loans (#398) — this asset's full loan history via
// AssetRegister::listLoansForAsset(), split into "open" (requested/
// approved/active — the ones that can still change state) and "closed"
// (declined/returned/cancelled — historical, shown in a collapsible
// history section) so the panel below can render each group differently
// without re-querying. canApproveLoan() is computed ONCE here for the
// current viewer/asset pair and reused for every action button in the
// panel — AssetRegister::loanAction() re-checks it independently server
// side regardless, so a hidden button never becomes a security boundary.
$assetLoans = AssetRegister::listLoansForAsset($assetId);
$openLoans = array_values(array_filter(
    $assetLoans,
    static fn (array $l): bool => in_array((string) $l['status'], AssetRegister::LOAN_OPEN_STATUSES, true) === true
));
$closedLoans = array_values(array_filter(
    $assetLoans,
    static fn (array $l): bool => in_array((string) $l['status'], AssetRegister::LOAN_OPEN_STATUSES, true) === false
));
$hasOpenLoan = count($openLoans) > 0;
$canApproveThisLoan = AssetRegister::canApproveLoan($assetId, $userId);

// 📅 Event assignments (#409) — this asset's assignment history via
// AssetRegister::listEventAssignments(). Read-visible to any viewer
// reaching this page (mirrors the Loans/Owners/Maintenance panels above);
// assign/unassign are gated on $privileged (admin/asset_manager OR a
// responsible owner-party for THIS asset, already computed above) —
// event-assign.php re-derives and re-checks that same gate independently
// server-side, so a hidden control is never the only thing stopping an
// unauthorised POST (same rationale as $canApproveThisLoan above).
$eventAssignments = AssetRegister::listEventAssignments($assetId);

// 📅 Upcoming-event picker for the "assign to event" form — only fetched
// when the viewer can actually assign, mirrors the Owners/Maintenance
// panels' own privileged-only picker-fetch pattern above. Deliberately a
// plain server-rendered <select> (house convention — no JS chart/
// autocomplete lib in this app), not a live search box.
$eventCandidates = [];
if ($privileged === true) {
    $evStmt = $db->prepare(
        'SELECT eventID, eventName, startDateTime FROM tblEvents '
        . 'WHERE siteID = ? AND isDeleted = 0 AND startDateTime >= DATE_SUB(NOW(), INTERVAL 1 DAY) '
        . 'ORDER BY startDateTime ASC LIMIT 100'
    );
    if ($evStmt !== false) {
        $evStmt->bind_param('i', $siteId);
        $evStmt->execute();
        $evResult = $evStmt->get_result();
        while ($row = $evResult->fetch_assoc()) {
            $eventCandidates[] = $row;
        }
        $evStmt->close();
    }
}

// 🔗 ?eventID= prefill (#409) — deep-link from the calendar event page's
// "Assign an asset" action pre-selects this event in the picker below.
// Purely a UX convenience — NOT trusted as authorisation or as a real
// eventID: the form still posts through event-assign.php, which (via
// AssetRegister::assignToEvent()) re-validates the eventID against
// Site::id() from scratch regardless of what pre-selected an option here.
$prefillEventId = (int) ($_GET['eventID'] ?? 0);

// 🔧 Maintenance (#399) — this asset's full maintenance/service history via
// AssetRegister::listMaintenance(). canManageMaintenance() is computed ONCE
// here for the current viewer/asset pair and reused for both the
// add-entry form's visibility and each row's edit/delete controls —
// maintenance-save.php re-derives and re-checks that same gate
// independently server-side regardless, so a hidden control is never the
// only thing stopping an unauthorised POST (same rationale as
// $canApproveThisLoan above).
$maintenanceRows = AssetRegister::listMaintenance($assetId);
$canManageMaintenance = AssetRegister::canManageMaintenance($assetId, $userId);

// 👤 "Performed by" user picker for the maintenance add/edit forms — only
// fetched when the viewer can actually manage maintenance, mirrors the
// Owners panel's own manager-only picker-fetch pattern above.
$maintCandidateUsers = [];
if ($canManageMaintenance === true) {
    $muStmt = $db->prepare(
        'SELECT u.userID, u.fullName FROM tblUsers u '
        . 'INNER JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
        . 'WHERE u.isActive = 1 ORDER BY u.fullName ASC'
    );
    if ($muStmt !== false) {
        $muStmt->bind_param('i', $siteId);
        $muStmt->execute();
        $muResult = $muStmt->get_result();
        while ($row = $muResult->fetch_assoc()) {
            $maintCandidateUsers[] = $row;
        }
        $muStmt->close();
    }
}

// 💷 Depreciation / value readout (#399) — straight-line only, DISPLAY
// ONLY (see AssetRegister::computeStraightLineValue()'s own doc for why
// reducing-balance + persisting tblAssets.currentValuePence are Phase-3
// cron work, out of scope here). Returns null — never rendered, NEVER
// invented — when the asset's depreciation fields aren't set up for a
// computable estimate (method isn't 'straight-line', or purchaseCostPence/
// usefulLifeMonths/purchaseDate is missing).
$estimatedCurrentValuePence = AssetRegister::computeStraightLineValue($asset);

// 🔍 Found-reports summary (#401) — manager-only. A small "how many
// public 'I found this' submissions has this asset received" surfacing
// on top of the full triage queue (_apps/assets/found-reports.php,
// linked from the panel below) — not fetched for a non-manager, mirrors
// $identifierTypes' own manager-only fetch above.
$foundReportsForAsset = $canManage === true
    ? AssetRegister::listFoundReports($siteId, ['assetID' => $assetId])
    : [];
$newFoundReportCount = count(array_filter(
    $foundReportsForAsset,
    static fn (array $r): bool => (string) $r['status'] === 'new'
));

// 📊 Scan-analytics strip (#410) — manager-only (mirrors the found-reports
// summary's own $canManage-only fetch immediately above — analytics, not
// asset custody, so the gate is the general manager one, not $privileged).
// 30 days of daily scan counts (public lost-and-found views + internal
// re-scans) for the CSS-only sparkbar further down — no JS chart library
// in this app.
$scanStats = $canManage === true ? AssetRegister::scanStats($assetId, 30) : [];
$scanStatsMax = 0;
foreach ($scanStats as $s) {
    $scanStatsMax = max($scanStatsMax, (int) $s['count']);
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
// 🔄 Loans panel lookups (#398).
$loanStatusBadge = [
    'requested' => 'warning', 'approved' => 'info', 'declined' => 'secondary',
    'active' => 'primary', 'returned' => 'success', 'cancelled' => 'secondary',
];
$loanDirectionLabel = ['out' => 'Lending out', 'in' => 'Borrowing in'];
// 🔧 Maintenance panel lookups (#399).
$maintTypeIcon = [
    'service' => 'fa-screwdriver-wrench', 'repair' => 'fa-wrench', 'inspection' => 'fa-magnifying-glass',
    'calibration' => 'fa-sliders', 'upgrade' => 'fa-arrow-up', 'other' => 'fa-clipboard-list',
];
$maintStatusBadge = ['scheduled' => 'info', 'completed' => 'success', 'cancelled' => 'secondary'];
// 👥 Owners panel lookups (#396).
$partyIcon = [
    'user' => 'fa-user', 'dept' => 'fa-building', 'group' => 'fa-people-group', 'org' => 'fa-handshake',
];
$roleKindBadge = [
    'owner' => 'primary', 'co-owner' => 'info', 'custodian' => 'secondary', 'stakeholder' => 'dark',
];
// 🆔 Identifiers panel lookups (#397). Order matters here — it drives both
// the display grouping below and the add-form's <optgroup> order, so GS1
// keys (the family GIAI/GRAI/GTIN/etc belong to) always list first.
$identCategoryLabel = [
    'gs1-key'        => 'GS1 keys',
    'retail-barcode' => 'Retail barcodes',
    'carrier'        => 'Tags & carriers',
    'classification' => 'Classification',
    'other'          => 'Other',
];
$identCategoryOrder = array_keys($identCategoryLabel);
// 📦 Group the already-fetched $identifiers list by category for display —
// listIdentifiers() orders by category via SQL FIELD() already, so this is
// a single linear pass, not a re-sort.
$identifiersByCategory = array_fill_keys($identCategoryOrder, []);
foreach ($identifiers as $identRow) {
    $identCat = in_array((string) $identRow['typeCategory'], $identCategoryOrder, true) === true
        ? (string) $identRow['typeCategory']
        : 'other';
    $identifiersByCategory[$identCat][] = $identRow;
}

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
            <!-- 🏷️ Print label (#402) — preselects this one asset in the
                 label designer (labels.php's own `?assetID=` first-load
                 handling; see that file's header). Manager-only, same gate
                 as Edit/Delete — printing a QR label is an administrative
                 action, not something a merely-responsible owner-party
                 needs (mirrors the "Public page & lost-and-found" panel's
                 own $canManage-only gate above, since the label encodes
                 that same public token). -->
            <a href="/assets/labels?assetID=<?php echo $assetId; ?>" class="btn btn-outline-secondary btn-sm">
                <i class="fa-solid fa-tag me-1"></i>Print label
            </a>
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

<!-- 💷 Purchase, warranty & depreciation -->
<div class="card mb-3">
    <div class="card-header"><h2 class="h5 mb-0">Purchase, warranty &amp; depreciation</h2></div>
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
        <?php /* 💷 Depreciation / value readout (#399) — only shown once a
                 depreciation method is actually configured on this asset
                 (edit.php); "Estimated current value" only ever appears
                 when AssetRegister::computeStraightLineValue() actually
                 returned a number — see this panel's PHP setup for why a
                 non-computable case (reducing-balance, or a straight-line
                 asset missing one of cost/life/purchase-date) NEVER
                 invents a value here, it simply omits the line. */ ?>
        <?php if ((string) $asset['depreciationMethod'] !== 'none'): ?>
            <div class="col-md-3">
                <strong>Depreciation method</strong><br>
                <?php echo htmlspecialchars(ucwords(str_replace('-', ' ', (string) $asset['depreciationMethod'])), ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>
        <?php if ($estimatedCurrentValuePence !== null): ?>
            <div class="col-md-3">
                <strong>Estimated current value</strong><br>
                <?php echo htmlspecialchars((string) $asset['currency'], ENT_QUOTES, 'UTF-8') . ' ' . number_format($estimatedCurrentValuePence / 100, 2); ?>
                <br><small class="text-muted">Straight-line estimate, as of today &mdash; not a persisted valuation.</small>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- 🔗 Public page & lost-and-found (#401) — manager-only: this panel
     shows/links the SECRET public token (whoever holds it reaches the
     public page — see tag.php's own access-model header) and offers the
     "Regenerate public token" control, so it is gated on $canManage, not
     the wider $privileged (a responsible owner-party who isn't a manager
     can view/act on Loans/Maintenance elsewhere on this page, but does
     NOT get to rotate or read this asset's public token). The
     confidential/public-page-enabled TOGGLE itself already lives on
     edit.php (isConfidential + publicPageEnabled checkboxes, wired
     through save.php → AssetRegister::updateAsset() — #394); this panel
     links there rather than duplicating that form. -->
<?php if ($canManage === true): ?>
<div class="card mb-3">
    <div class="card-header"><h2 class="h5 mb-0">Public page &amp; lost-and-found</h2></div>
    <div class="card-body row g-3">
        <div class="col-md-6">
            <strong>Public page status</strong><br>
            <?php if ((int) $asset['isConfidential'] === 1): ?>
                <span class="badge bg-secondary"><i class="fa-solid fa-lock me-1"></i>Confidential — never public</span>
            <?php elseif ((int) $asset['publicPageEnabled'] === 1): ?>
                <span class="badge bg-success"><i class="fa-solid fa-globe me-1"></i>Enabled</span>
            <?php else: ?>
                <span class="badge bg-secondary">Disabled</span>
            <?php endif; ?>
            <br><small class="text-muted">Change this on the <a href="/assets/edit?id=<?php echo $assetId; ?>">Edit asset</a> page.</small>
        </div>
        <div class="col-md-6">
            <strong>Lost-and-found link</strong><br>
            <?php if ((int) $asset['isConfidential'] === 1 || (int) $asset['publicPageEnabled'] !== 1): ?>
                <span class="text-muted">Not publicly reachable while disabled/confidential.</span>
            <?php else: ?>
                <a href="/a/<?php echo htmlspecialchars((string) $asset['publicToken'], ENT_QUOTES, 'UTF-8'); ?>"
                   target="_blank" rel="noopener noreferrer" class="small">
                    /a/<?php echo htmlspecialchars((string) $asset['publicToken'], ENT_QUOTES, 'UTF-8'); ?>
                    <i class="fa-solid fa-arrow-up-right-from-square fa-xs"></i>
                </a>
            <?php endif; ?>
        </div>
        <div class="col-12 d-flex flex-wrap align-items-center gap-2">
            <a href="/assets/found-reports?assetID=<?php echo $assetId; ?>" class="btn btn-outline-secondary btn-sm">
                <i class="fa-solid fa-flag me-1"></i>Found-item reports
                <?php if ($newFoundReportCount > 0): ?>
                    <span class="badge bg-danger ms-1"><?php echo (int) $newFoundReportCount; ?> new</span>
                <?php elseif (count($foundReportsForAsset) > 0): ?>
                    <span class="badge bg-secondary ms-1"><?php echo count($foundReportsForAsset); ?></span>
                <?php endif; ?>
            </a>
            <!-- ⚠️ Destructive — EVERY printed label/QR code encoding the
                 CURRENT token stops resolving the instant this runs (see
                 AssetRegister::regeneratePublicToken()'s own doc). Folded
                 into save.php's action= dispatch rather than a new route
                 — see that file's header (#401 scope: no new routes). -->
            <form method="post" action="/assets/save" class="d-inline"
                  data-confirm="Regenerate this asset's public token? Any printed labels or QR codes using the CURRENT link will stop working immediately."
                  data-confirm-destructive="true">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="regenerate-token">
                <input type="hidden" name="assetID" value="<?php echo $assetId; ?>">
                <button type="submit" class="btn btn-outline-warning btn-sm">
                    <i class="fa-solid fa-rotate me-1"></i>Regenerate public token
                </button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($isDigital === true): ?>
<!-- 💻 Digital / licensing — general metadata only; the licence key and
     seat management now live in the "Licence & seats" panel below (#400). -->
<div class="card mb-3">
    <div class="card-header"><h2 class="h5 mb-0">Digital / licensing</h2></div>
    <div class="card-body row g-3">
        <div class="col-md-6"><strong>Renewal date</strong><br><?php echo $asset['renewalDate'] !== null ? htmlspecialchars((string) $asset['renewalDate'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>'; ?></div>
        <div class="col-md-6"><strong>Access URL</strong><br>
            <?php if ($asset['accessUrl'] !== null): ?>
                <a href="<?php echo htmlspecialchars((string) $asset['accessUrl'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">Open <i class="fa-solid fa-arrow-up-right-from-square fa-xs"></i></a>
            <?php else: ?>
                <span class="text-muted">—</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 🎟️ Licence & seats (#400) — seat-count header, manager-only licence-key
     reveal (moved here from the card above — see file header note), the
     active seat-assignment list + collapsible released history, and a
     manager-gated "Link a seat" form. Read-visible/edit-manager-gated
     split identical to the Loans/Maintenance panels above — see this
     file's LICENCE & SEATS panel header note. -->
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h2 class="h5 mb-0">Licence &amp; seats</h2>
        <div>
            <span class="badge bg-secondary" title="Total seats configured for this licence">
                Seats: <?php echo $licenseSeatSummary['seats'] !== null ? (int) $licenseSeatSummary['seats'] : 'Unlimited'; ?>
            </span>
            <span class="badge bg-info" title="Seats currently in use">Used: <?php echo (int) $licenseSeatSummary['used']; ?></span>
            <?php if ($licenseSeatSummary['free'] !== null): ?>
                <span class="badge bg-success" title="Seats still available">Free: <?php echo (int) $licenseSeatSummary['free']; ?></span>
            <?php endif; ?>
            <?php if ($licenseSeatSummary['over'] === true): ?>
                <span class="badge bg-warning text-dark" title="More seats are in use than this licence is configured for">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i>Over-allocated
                </span>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <!-- 🔑 Licence key — masked, manager-only reveal. Reuses
             AssetRegister::decryptLicenseKey() completely unchanged
             (#394) — see file header's LICENCE-KEY REVEAL note; the
             plaintext is only ever embedded in the response when
             $canManage === true. -->
        <p class="mb-3">
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
        </p>

        <?php if (count($licenseActiveAssignments) === 0): ?>
            <p class="text-muted">No seats currently linked.</p>
        <?php else: ?>
            <div class="portal-data-list mb-3">
                <?php foreach ($licenseActiveAssignments as $la): ?>
                    <div class="portal-data-row align-items-center">
                        <div class="col-6 col-md-4">
                            <?php echo htmlspecialchars((string) $la['assignedToDisplay'], ENT_QUOTES, 'UTF-8'); ?>
                            <?php if ($la['seatLabel'] !== null && (string) $la['seatLabel'] !== ''): ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars((string) $la['seatLabel'], ENT_QUOTES, 'UTF-8'); ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="col-4 col-md-4 small text-muted">
                            Linked <?php echo htmlspecialchars((string) $la['linkedAt'], ENT_QUOTES, 'UTF-8'); ?>
                            <?php if ($la['linkedByName'] !== null): ?>
                                by <?php echo htmlspecialchars((string) $la['linkedByName'], ENT_QUOTES, 'UTF-8'); ?>
                            <?php endif; ?>
                        </div>
                        <div class="col-2 col-md-4 text-end">
                            <?php if ($canManage === true): ?>
                                <form method="post" action="/assets/license-action" class="d-inline"
                                      data-confirm="Release this seat?" data-confirm-destructive="true">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="licenseAssetID" value="<?php echo $assetId; ?>">
                                    <input type="hidden" name="assignmentID" value="<?php echo (int) $la['assignmentID']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Release this seat">
                                        <i class="fa-solid fa-right-from-bracket me-1"></i>Release
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (count($licenseReleasedAssignments) > 0): ?>
            <details class="mb-3">
                <summary class="text-muted small">Show history (<?php echo count($licenseReleasedAssignments); ?> released)</summary>
                <div class="portal-data-list mt-2">
                    <?php foreach ($licenseReleasedAssignments as $la): ?>
                        <div class="portal-data-row align-items-center text-muted">
                            <div class="col-6 col-md-4">
                                <?php echo htmlspecialchars((string) $la['assignedToDisplay'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php if ($la['seatLabel'] !== null && (string) $la['seatLabel'] !== ''): ?>
                                    <br><small><?php echo htmlspecialchars((string) $la['seatLabel'], ENT_QUOTES, 'UTF-8'); ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="col-6 col-md-8 small">
                                Linked <?php echo htmlspecialchars((string) $la['linkedAt'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php if ($la['linkedByName'] !== null): ?> by <?php echo htmlspecialchars((string) $la['linkedByName'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
                                <br>Released <?php echo $la['releasedAt'] !== null ? htmlspecialchars((string) $la['releasedAt'], ENT_QUOTES, 'UTF-8') : '—'; ?>
                                <?php if ($la['releasedByName'] !== null): ?> by <?php echo htmlspecialchars((string) $la['releasedByName'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>

        <?php if ($canManage === true): ?>
            <hr>
            <h3 class="h6">Link a seat</h3>
            <form method="post" action="/assets/license-save" class="row g-2">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="licenseAssetID" value="<?php echo $assetId; ?>">
                <div class="col-md-3">
                    <label class="form-label small" for="licenseTargetType">Assign to</label>
                    <select class="form-select form-select-sm" id="licenseTargetType" name="targetType">
                        <option value="device">Tracked device asset</option>
                        <option value="user">Portal user</option>
                        <option value="other">Free-text device name</option>
                    </select>
                </div>
                <div class="col-md-4" id="licenseTargetWrap-device">
                    <label class="form-label small" for="licenseDeviceAssetID">Device asset</label>
                    <select class="form-select form-select-sm" id="licenseDeviceAssetID" name="deviceAssetID">
                        <option value="">Select…</option>
                        <?php foreach ($licenseCandidateDevices as $d): ?>
                            <option value="<?php echo (int) $d['assetID']; ?>"><?php echo htmlspecialchars((string) $d['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4" id="licenseTargetWrap-user" hidden>
                    <label class="form-label small" for="licenseUserID">Portal user</label>
                    <select class="form-select form-select-sm" id="licenseUserID" name="userID">
                        <option value="">Select…</option>
                        <?php foreach ($licenseCandidateUsers as $u): ?>
                            <option value="<?php echo (int) $u['userID']; ?>"><?php echo htmlspecialchars((string) $u['fullName'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4" id="licenseTargetWrap-other" hidden>
                    <label class="form-label small" for="licenseDeviceName">Device name</label>
                    <input type="text" class="form-control form-control-sm" id="licenseDeviceName" name="deviceName" maxlength="255" placeholder="e.g. Front-of-house laptop">
                </div>
                <div class="col-md-3">
                    <label class="form-label small" for="licenseSeatLabel">Seat label <span class="text-muted">(optional)</span></label>
                    <input type="text" class="form-control form-control-sm" id="licenseSeatLabel" name="seatLabel" maxlength="100" placeholder="e.g. Seat 3">
                </div>
                <div class="col-md-5">
                    <label class="form-label small" for="licenseSeatNotes">Notes <span class="text-muted">(optional)</span></label>
                    <input type="text" class="form-control form-control-sm" id="licenseSeatNotes" name="notes" maxlength="500">
                </div>
                <div class="col-12">
                    <small class="text-muted">Choose exactly ONE of a tracked device asset, a portal user, or a free-text device name.</small>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus me-1"></i>Link seat</button>
                </div>
            </form>
            <script nonce="<?php echo $nonce; ?>">
            (function () {
                'use strict';
                // 🎛️ Progressive-enhancement toggle only — mirrors the
                // Owners panel's ownerPartyType script above. Hiding the
                // non-matching pickers does NOT stop their fields being
                // submitted; license-save.php reads only the field
                // matching the posted targetType.
                var typeSelect = document.getElementById('licenseTargetType');
                var wraps = {
                    device: document.getElementById('licenseTargetWrap-device'),
                    user: document.getElementById('licenseTargetWrap-user'),
                    other: document.getElementById('licenseTargetWrap-other')
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

<!-- 🔄 Loans (#398) — lend & borrow, approval, condition in/out. Any viewer
     reaching this page can see the panel; management actions (approve/
     decline/checkout/checkin/cancel) are gated per-button by
     $canApproveThisLoan (canApproveLoan()) or, for checkin/cancel, that OR
     the loan's own original requester — AssetRegister::loanAction()
     re-enforces every one of these gates independently server-side, so a
     hidden button is never the only thing standing between a viewer and
     an action they're not allowed to take. -->
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="h5 mb-0">Loans</h2>
        <?php if ($hasOpenLoan === false): ?>
            <a href="/assets/loan?assetID=<?php echo $assetId; ?>" class="btn btn-outline-primary btn-sm">
                <i class="fa-solid fa-right-left me-1"></i>Lend out / Record borrowing
            </a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <!-- 🚦 Current status strip. -->
        <p class="mb-3">
            <?php if (in_array((string) $asset['status'], ['on-loan', 'borrowed'], true) === true): ?>
                <span class="badge bg-<?php echo htmlspecialchars($statusBadge[(string) $asset['status']] ?? 'info', ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo (string) $asset['status'] === 'on-loan' ? 'Currently on loan' : 'Currently borrowed in'; ?>
                </span>
            <?php else: ?>
                <span class="badge bg-success">Available</span>
            <?php endif; ?>
        </p>

        <?php if (count($openLoans) === 0 && count($closedLoans) === 0): ?>
            <p class="text-muted">No loans recorded yet for this asset.</p>
        <?php endif; ?>

        <?php if (count($openLoans) > 0): ?>
            <div class="portal-data-list mb-3">
                <?php foreach ($openLoans as $loan): ?>
                    <?php
                    $loanStatus = (string) $loan['status'];
                    $loanIsOverdue = (bool) $loan['isOverdue'];
                    $loanIsRequester = (int) $loan['requestedByID'] === $userId;
                    ?>
                    <div class="portal-data-row align-items-start <?php echo $loanIsOverdue === true ? 'bg-danger-subtle' : ''; ?>">
                        <div class="col-6 col-md-4">
                            <i class="fa-solid fa-<?php echo (string) $loan['direction'] === 'out' ? 'arrow-right' : 'arrow-left'; ?> me-2 text-muted"></i>
                            <?php echo htmlspecialchars($loanDirectionLabel[(string) $loan['direction']] ?? (string) $loan['direction'], ENT_QUOTES, 'UTF-8'); ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars((string) $loan['counterpartyDisplayName'], ENT_QUOTES, 'UTF-8'); ?></small>
                        </div>
                        <div class="col-3 col-md-2">
                            <span class="badge bg-<?php echo htmlspecialchars($loanStatusBadge[$loanStatus] ?? 'secondary', ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars(ucwords($loanStatus), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>
                        <div class="col-3 col-md-2">
                            <?php if ($loan['dueDate'] !== null): ?>
                                <?php echo htmlspecialchars((string) $loan['dueDate'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php if ($loanIsOverdue === true): ?>
                                    <br><span class="badge bg-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i>Overdue</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </div>
                        <div class="col-12 col-md-4 text-md-end">
                            <?php if ($loanStatus === 'requested' && $canApproveThisLoan === true): ?>
                                <form method="post" action="/assets/loan-action" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="assetID" value="<?php echo $assetId; ?>">
                                    <input type="hidden" name="loanID" value="<?php echo (int) $loan['loanID']; ?>">
                                    <button type="submit" class="btn btn-sm btn-success mb-1"><i class="fa-solid fa-check me-1"></i>Approve</button>
                                </form>
                                <form method="post" action="/assets/loan-action" class="d-inline"
                                      data-confirm="Decline this loan request?" data-confirm-destructive="true">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="decline">
                                    <input type="hidden" name="assetID" value="<?php echo $assetId; ?>">
                                    <input type="hidden" name="loanID" value="<?php echo (int) $loan['loanID']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger mb-1"><i class="fa-solid fa-xmark me-1"></i>Decline</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($loanStatus === 'approved' && $canApproveThisLoan === true): ?>
                                <details class="d-inline-block mb-1 text-start">
                                    <summary class="btn btn-sm btn-primary"><i class="fa-solid fa-dolly me-1"></i>Check out</summary>
                                    <form method="post" action="/assets/loan-action" class="mt-2">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="action" value="checkout">
                                        <input type="hidden" name="assetID" value="<?php echo $assetId; ?>">
                                        <input type="hidden" name="loanID" value="<?php echo (int) $loan['loanID']; ?>">
                                        <label class="form-label small">Condition at hand-over</label>
                                        <select class="form-select form-select-sm mb-1" name="conditionOut">
                                            <option value="">Keep as recorded</option>
                                            <?php foreach (AssetRegister::CONDITION_STATES as $c): ?>
                                                <option value="<?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?>"<?php echo ((string) ($loan['conditionOut'] ?? '')) === $c ? ' selected' : ''; ?>><?php echo htmlspecialchars(ucwords($c), ENT_QUOTES, 'UTF-8'); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-primary">Confirm check-out</button>
                                    </form>
                                </details>
                            <?php endif; ?>
                            <?php if ($loanStatus === 'active' && ($canApproveThisLoan === true || $loanIsRequester === true)): ?>
                                <details class="d-inline-block mb-1 text-start">
                                    <summary class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-dolly-flatbed me-1"></i>Check in</summary>
                                    <form method="post" action="/assets/loan-action" class="mt-2">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="action" value="checkin">
                                        <input type="hidden" name="assetID" value="<?php echo $assetId; ?>">
                                        <input type="hidden" name="loanID" value="<?php echo (int) $loan['loanID']; ?>">
                                        <label class="form-label small">Condition on return <span class="text-danger">*</span></label>
                                        <select class="form-select form-select-sm mb-1" name="conditionIn" required>
                                            <option value="">Select…</option>
                                            <?php foreach (AssetRegister::CONDITION_STATES as $c): ?>
                                                <option value="<?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucwords($c), ENT_QUOTES, 'UTF-8'); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <label class="form-label small">Notes <span class="text-muted">(optional)</span></label>
                                        <input type="text" class="form-control form-control-sm mb-1" name="conditionInNotes" maxlength="500">
                                        <button type="submit" class="btn btn-sm btn-primary">Confirm check-in</button>
                                    </form>
                                </details>
                            <?php endif; ?>
                            <?php if (in_array($loanStatus, ['requested', 'approved'], true) === true && ($canApproveThisLoan === true || $loanIsRequester === true)): ?>
                                <form method="post" action="/assets/loan-action" class="d-inline"
                                      data-confirm="Cancel this loan?" data-confirm-destructive="true">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="assetID" value="<?php echo $assetId; ?>">
                                    <input type="hidden" name="loanID" value="<?php echo (int) $loan['loanID']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary mb-1"><i class="fa-solid fa-ban me-1"></i>Cancel</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (count($closedLoans) > 0): ?>
            <details>
                <summary class="text-muted small">Loan history (<?php echo count($closedLoans); ?>)</summary>
                <div class="portal-data-list mt-2">
                    <?php foreach ($closedLoans as $loan): ?>
                        <div class="portal-data-row align-items-center">
                            <div class="col-6 col-md-4">
                                <i class="fa-solid fa-<?php echo (string) $loan['direction'] === 'out' ? 'arrow-right' : 'arrow-left'; ?> me-2 text-muted"></i>
                                <?php echo htmlspecialchars($loanDirectionLabel[(string) $loan['direction']] ?? (string) $loan['direction'], ENT_QUOTES, 'UTF-8'); ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars((string) $loan['counterpartyDisplayName'], ENT_QUOTES, 'UTF-8'); ?></small>
                            </div>
                            <div class="col-3 col-md-3">
                                <span class="badge bg-<?php echo htmlspecialchars($loanStatusBadge[(string) $loan['status']] ?? 'secondary', ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars(ucwords((string) $loan['status']), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <?php if ((string) $loan['status'] === 'declined' && $loan['declineReason'] !== null && (string) $loan['declineReason'] !== ''): ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars((string) $loan['declineReason'], ENT_QUOTES, 'UTF-8'); ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="col-3 col-md-5 small text-muted">
                                <?php if ($loan['dateOut'] !== null): ?>Out: <?php echo htmlspecialchars((string) $loan['dateOut'], ENT_QUOTES, 'UTF-8'); ?><br><?php endif; ?>
                                <?php if ($loan['dateIn'] !== null): ?>In: <?php echo htmlspecialchars((string) $loan['dateIn'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
                                <?php if ($loan['conditionIn'] !== null): ?> (<?php echo htmlspecialchars(ucwords((string) $loan['conditionIn']), ENT_QUOTES, 'UTF-8'); ?>)<?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>
    </div>
</div>

<!-- 🔧 Maintenance (#399) — service/repair/inspection/calibration history.
     History is visible to any viewer reaching this page (mirrors the
     Loans/Owners/Identifiers panels above); the add-entry form and each
     row's edit/delete controls are shown only when $canManageMaintenance
     (admin/asset_manager OR a maintenance-authority owner-party for THIS
     asset — AssetRegister::canManageMaintenance(), an exact mirror of
     canApproveLoan() narrowed to isMaintenanceAuthority) —
     maintenance-save.php re-derives and re-checks that same gate
     independently server-side, so a hidden control is never the only
     thing stopping an unauthorised POST. -->
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="h5 mb-0">Maintenance</h2>
        <a href="/assets/maintenance?assetID=<?php echo $assetId; ?>" class="btn btn-outline-secondary btn-sm">
            <i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Full page
        </a>
    </div>
    <div class="card-body">
        <?php if (count($maintenanceRows) === 0): ?>
            <p class="text-muted">No maintenance recorded yet for this asset.</p>
        <?php else: ?>
            <div class="portal-data-list mb-3">
                <?php foreach ($maintenanceRows as $m): ?>
                    <?php
                    $mStatus     = (string) $m['status'];
                    $mIsOverdue  = (bool) ($m['isOverdue'] ?? false);
                    $mIsUpcoming = (bool) ($m['isUpcoming'] ?? false);
                    ?>
                    <div class="portal-data-row align-items-start <?php echo $mIsOverdue === true ? 'bg-danger-subtle' : ''; ?>">
                        <div class="col-6 col-md-3">
                            <i class="fa-solid <?php echo htmlspecialchars($maintTypeIcon[(string) $m['maintType']] ?? 'fa-clipboard-list', ENT_QUOTES, 'UTF-8'); ?> me-2 text-muted"></i>
                            <?php echo htmlspecialchars((string) $m['title'], ENT_QUOTES, 'UTF-8'); ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars(ucwords((string) $m['maintType']), ENT_QUOTES, 'UTF-8'); ?></small>
                        </div>
                        <div class="col-6 col-md-3">
                            <?php echo $m['performedByDisplay'] !== null ? htmlspecialchars((string) $m['performedByDisplay'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>'; ?>
                            <?php if ($m['performedAt'] !== null): ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars((string) $m['performedAt'], ENT_QUOTES, 'UTF-8'); ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="col-3 col-md-2">
                            <?php echo $m['costPence'] !== null
                                ? htmlspecialchars((string) $asset['currency'], ENT_QUOTES, 'UTF-8') . ' ' . number_format(((int) $m['costPence']) / 100, 2)
                                : '<span class="text-muted">—</span>'; ?>
                        </div>
                        <div class="col-3 col-md-2">
                            <span class="badge bg-<?php echo htmlspecialchars($maintStatusBadge[$mStatus] ?? 'secondary', ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars(ucwords($mStatus), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <?php if ($m['nextDueDate'] !== null): ?>
                                <br><small class="text-muted">Due <?php echo htmlspecialchars((string) $m['nextDueDate'], ENT_QUOTES, 'UTF-8'); ?></small>
                                <?php if ($mIsOverdue === true): ?>
                                    <br><span class="badge bg-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i>Overdue</span>
                                <?php elseif ($mIsUpcoming === true): ?>
                                    <br><span class="badge bg-info">Upcoming</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($canManageMaintenance === true): ?>
                        <div class="col-12 col-md-2 text-md-end">
                            <details class="d-inline-block mb-1 text-start">
                                <summary class="btn btn-sm btn-outline-secondary" title="Edit"><i class="fa-solid fa-pen"></i></summary>
                                <form method="post" action="/assets/maintenance-save" class="mt-2 row g-1" style="min-width: 260px;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="assetID" value="<?php echo $assetId; ?>">
                                    <input type="hidden" name="maintID" value="<?php echo (int) $m['maintID']; ?>">
                                    <div class="col-12">
                                        <label class="form-label small">Type</label>
                                        <select class="form-select form-select-sm" name="maintType">
                                            <?php foreach (AssetRegister::MAINTENANCE_TYPES as $mt): ?>
                                                <option value="<?php echo htmlspecialchars($mt, ENT_QUOTES, 'UTF-8'); ?>"<?php echo (string) $m['maintType'] === $mt ? ' selected' : ''; ?>><?php echo htmlspecialchars(ucwords($mt), ENT_QUOTES, 'UTF-8'); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label small">Title</label>
                                        <input type="text" class="form-control form-control-sm" name="title" maxlength="255" required value="<?php echo htmlspecialchars((string) $m['title'], ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label small">Details</label>
                                        <textarea class="form-control form-control-sm" name="details" rows="2"><?php echo htmlspecialchars((string) ($m['details'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small">Performed by</label>
                                        <select class="form-select form-select-sm" name="performedByUserID">
                                            <option value="0">— Free text below —</option>
                                            <?php foreach ($maintCandidateUsers as $mu): ?>
                                                <option value="<?php echo (int) $mu['userID']; ?>"<?php echo (int) ($m['performedByUserID'] ?? 0) === (int) $mu['userID'] ? ' selected' : ''; ?>><?php echo htmlspecialchars((string) $mu['fullName'], ENT_QUOTES, 'UTF-8'); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small">Or name</label>
                                        <input type="text" class="form-control form-control-sm" name="performedByName" maxlength="255" value="<?php echo htmlspecialchars((string) ($m['performedByName'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small">Cost (£)</label>
                                        <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="costPounds" value="<?php echo $m['costPence'] !== null ? number_format(((int) $m['costPence']) / 100, 2, '.', '') : ''; ?>">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small">Status</label>
                                        <select class="form-select form-select-sm" name="status">
                                            <?php foreach (AssetRegister::MAINTENANCE_STATUSES as $ms): ?>
                                                <option value="<?php echo htmlspecialchars($ms, ENT_QUOTES, 'UTF-8'); ?>"<?php echo (string) $m['status'] === $ms ? ' selected' : ''; ?>><?php echo htmlspecialchars(ucwords($ms), ENT_QUOTES, 'UTF-8'); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small">Performed on</label>
                                        <input type="date" class="form-control form-control-sm" name="performedAt" value="<?php echo htmlspecialchars((string) ($m['performedAt'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small">Next due</label>
                                        <input type="date" class="form-control form-control-sm" name="nextDueDate" value="<?php echo htmlspecialchars((string) ($m['nextDueDate'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div class="col-12 mt-1">
                                        <button type="submit" class="btn btn-sm btn-primary">Save changes</button>
                                    </div>
                                </form>
                            </details>
                            <form method="post" action="/assets/maintenance-save" class="d-inline"
                                  data-confirm="Remove this maintenance entry?" data-confirm-destructive="true">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="assetID" value="<?php echo $assetId; ?>">
                                <input type="hidden" name="maintID" value="<?php echo (int) $m['maintID']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($canManageMaintenance === true): ?>
            <hr>
            <h3 class="h6">Add a maintenance entry</h3>
            <form method="post" action="/assets/maintenance-save" class="row g-2">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="assetID" value="<?php echo $assetId; ?>">
                <div class="col-md-3">
                    <label class="form-label small" for="maintType">Type</label>
                    <select class="form-select form-select-sm" id="maintType" name="maintType">
                        <?php foreach (AssetRegister::MAINTENANCE_TYPES as $mt): ?>
                            <option value="<?php echo htmlspecialchars($mt, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucwords($mt), ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label small" for="title">Title</label>
                    <input type="text" class="form-control form-control-sm" id="title" name="title" required maxlength="255" placeholder="e.g. Annual PAT test">
                </div>
                <div class="col-md-4">
                    <label class="form-label small" for="status">Status</label>
                    <select class="form-select form-select-sm" id="status" name="status">
                        <?php foreach (AssetRegister::MAINTENANCE_STATUSES as $ms): ?>
                            <option value="<?php echo htmlspecialchars($ms, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $ms === 'completed' ? ' selected' : ''; ?>><?php echo htmlspecialchars(ucwords($ms), ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label small" for="details">Details <span class="text-muted">(optional)</span></label>
                    <textarea class="form-control form-control-sm" id="details" name="details" rows="2"></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label small" for="performedByUserID">Performed by (portal user)</label>
                    <select class="form-select form-select-sm" id="performedByUserID" name="performedByUserID">
                        <option value="0">— Use free text instead —</option>
                        <?php foreach ($maintCandidateUsers as $mu): ?>
                            <option value="<?php echo (int) $mu['userID']; ?>"><?php echo htmlspecialchars((string) $mu['fullName'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small" for="performedByName">…or free text <span class="text-muted">(external contractor)</span></label>
                    <input type="text" class="form-control form-control-sm" id="performedByName" name="performedByName" maxlength="255">
                </div>
                <div class="col-md-4">
                    <label class="form-label small" for="costPounds">Cost (£) <span class="text-muted">(optional)</span></label>
                    <input type="number" step="0.01" min="0" class="form-control form-control-sm" id="costPounds" name="costPounds" placeholder="0.00">
                </div>
                <div class="col-md-6">
                    <label class="form-label small" for="performedAt">Performed on <span class="text-muted">(optional)</span></label>
                    <input type="date" class="form-control form-control-sm" id="performedAt" name="performedAt">
                </div>
                <div class="col-md-6">
                    <label class="form-label small" for="nextDueDate">Next due <span class="text-muted">(optional)</span></label>
                    <input type="date" class="form-control form-control-sm" id="nextDueDate" name="nextDueDate">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-sm btn-primary"><i class="fa-solid fa-plus me-1"></i>Add entry</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- 📅 Assigned events (#409) — links this asset to calendar events (e.g.
     "the PA system is assigned to Sunday's service"), with an optional
     assignment window distinct from the event's own start/end. History is
     visible to any viewer reaching this page (mirrors the Loans/Owners/
     Maintenance panels above); the assign form and each row's Unassign
     button are $privileged-only (admin/asset_manager OR a responsible
     owner-party for THIS asset) — event-assign.php re-derives and
     re-checks that same gate independently server-side, so a hidden
     control is never the only thing stopping an unauthorised POST. -->
<div class="card mb-3">
    <div class="card-header"><h2 class="h5 mb-0">Assigned events</h2></div>
    <div class="card-body">
        <?php if (count($eventAssignments) === 0): ?>
            <p class="text-muted">This asset isn't assigned to any events yet.</p>
        <?php else: ?>
            <div class="portal-data-list mb-3">
                <?php foreach ($eventAssignments as $ea): ?>
                    <div class="portal-data-row align-items-start">
                        <div class="col-6 col-md-5">
                            <a href="/calendar/event?slug=<?php echo htmlspecialchars((string) ($ea['eventSlug'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars((string) $ea['eventName'], ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                            <br><small class="text-muted"><?php echo htmlspecialchars(date('j M Y, H:i', strtotime((string) $ea['startDateTime'])), ENT_QUOTES, 'UTF-8'); ?></small>
                        </div>
                        <div class="col-4 col-md-4 small text-muted">
                            <?php if ($ea['assignedFrom'] !== null || $ea['assignedUntil'] !== null): ?>
                                <?php if ($ea['assignedFrom'] !== null): ?>From: <?php echo htmlspecialchars(date('j M Y H:i', strtotime((string) $ea['assignedFrom'])), ENT_QUOTES, 'UTF-8'); ?><br><?php endif; ?>
                                <?php if ($ea['assignedUntil'] !== null): ?>Until: <?php echo htmlspecialchars(date('j M Y H:i', strtotime((string) $ea['assignedUntil'])), ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">Uses the event's own window</span>
                            <?php endif; ?>
                            <?php if ($ea['notes'] !== null && (string) $ea['notes'] !== ''): ?>
                                <br><?php echo htmlspecialchars((string) $ea['notes'], ENT_QUOTES, 'UTF-8'); ?>
                            <?php endif; ?>
                        </div>
                        <div class="col-2 col-md-3 text-end">
                            <?php if ($privileged === true): ?>
                                <form method="post" action="/assets/event-assign" class="d-inline"
                                      data-confirm="Remove this asset's assignment to this event?" data-confirm-destructive="true">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="unassign">
                                    <input type="hidden" name="assetID" value="<?php echo $assetId; ?>">
                                    <input type="hidden" name="assignmentID" value="<?php echo (int) $ea['assignmentID']; ?>">
                                    <input type="hidden" name="returnTo" value="item">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Unassign">
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
            <h3 class="h6">Assign to an event</h3>
            <?php if (count($eventCandidates) === 0): ?>
                <p class="text-muted small">No upcoming events found on this site.</p>
            <?php else: ?>
                <form method="post" action="/assets/event-assign" class="row g-2">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="assign">
                    <input type="hidden" name="assetID" value="<?php echo $assetId; ?>">
                    <input type="hidden" name="returnTo" value="item">
                    <div class="col-md-4">
                        <label class="form-label small" for="eventID">Event</label>
                        <select class="form-select form-select-sm" id="eventID" name="eventID" required>
                            <option value="">Select an event…</option>
                            <?php foreach ($eventCandidates as $ec): ?>
                                <option value="<?php echo (int) $ec['eventID']; ?>"<?php echo $prefillEventId === (int) $ec['eventID'] ? ' selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string) $ec['eventName'] . ' — ' . date('j M Y H:i', strtotime((string) $ec['startDateTime'])), ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small" for="assignedFrom">From <span class="text-muted">(optional — defaults to the event's own start)</span></label>
                        <input type="datetime-local" class="form-control form-control-sm" id="assignedFrom" name="assignedFrom">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small" for="assignedUntil">Until <span class="text-muted">(optional — defaults to the event's own end)</span></label>
                        <input type="datetime-local" class="form-control form-control-sm" id="assignedUntil" name="assignedUntil">
                    </div>
                    <div class="col-12">
                        <label class="form-label small" for="eventAssignNotes">Notes <span class="text-muted">(optional)</span></label>
                        <input type="text" class="form-control form-control-sm" id="eventAssignNotes" name="notes" maxlength="500">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-sm btn-primary"><i class="fa-solid fa-calendar-plus me-1"></i>Assign to event</button>
                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

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

<!-- 🆔 Identifiers (#397) — GS1 family (GIAI/GRAI/GTIN/…), retail barcodes,
     RFID/EPC carriers, and classification codes. List is visible to any
     viewer reaching this page (mirrors the Owners panel above); add/
     remove/set-primary are $canManage-only, gated identically server-side
     in identifiers-save.php. -->
<div class="card mb-3">
    <div class="card-header"><h2 class="h5 mb-0">Identifiers</h2></div>
    <div class="card-body">
        <?php if (count($identifiers) === 0): ?>
            <p class="text-muted">No identifiers recorded yet.</p>
        <?php else: ?>
            <?php foreach ($identCategoryOrder as $identCat): ?>
                <?php if (count($identifiersByCategory[$identCat]) === 0) { continue; } ?>
                <h3 class="h6 text-muted mb-2"><?php echo htmlspecialchars($identCategoryLabel[$identCat], ENT_QUOTES, 'UTF-8'); ?></h3>
                <div class="portal-data-list mb-3">
                    <?php foreach ($identifiersByCategory[$identCat] as $ident): ?>
                        <div class="portal-data-row align-items-center">
                            <div class="col-6 col-md-4">
                                <?php echo htmlspecialchars((string) $ident['typeLabel'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php if ((int) $ident['isPrimary'] === 1): ?>
                                    <span class="badge bg-warning text-dark" title="Primary identifier for this asset"><i class="fa-solid fa-star"></i></span>
                                <?php endif; ?>
                                <?php if ((int) $ident['isVerified'] === 1): ?>
                                    <span class="badge bg-success" title="Verified — format/check-digit confirmed"><i class="fa-solid fa-check"></i></span>
                                <?php endif; ?>
                                <?php if ($ident['subScheme'] !== null && (string) $ident['subScheme'] !== ''): ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars((string) $ident['subScheme'], ENT_QUOTES, 'UTF-8'); ?></small>
                                <?php endif; ?>
                                <?php if ($ident['notes'] !== null && (string) $ident['notes'] !== ''): ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars((string) $ident['notes'], ENT_QUOTES, 'UTF-8'); ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="col-4 col-md-5">
                                <code><?php echo htmlspecialchars((string) $ident['value'], ENT_QUOTES, 'UTF-8'); ?></code>
                            </div>
                            <div class="col-2 col-md-3 text-end">
                                <?php if ($canManage === true): ?>
                                    <?php if ((int) $ident['isPrimary'] !== 1): ?>
                                        <form method="post" action="/assets/identifiers-save" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="set-primary">
                                            <input type="hidden" name="assetID" value="<?php echo $assetId; ?>">
                                            <input type="hidden" name="identifierID" value="<?php echo (int) $ident['identifierID']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary" title="Make this the primary identifier">
                                                <i class="fa-regular fa-star"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" action="/assets/identifiers-save" class="d-inline"
                                          data-confirm="Remove this identifier?" data-confirm-destructive="true">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="action" value="remove">
                                        <input type="hidden" name="assetID" value="<?php echo $assetId; ?>">
                                        <input type="hidden" name="identifierID" value="<?php echo (int) $ident['identifierID']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($canManage === true): ?>
            <!-- ➕ Add an identifier. Type <select> is grouped into <optgroup>s
                 matching $identCategoryOrder above; GIAI/GRAI (the two GS1
                 keys purpose-built for identifying assets — see migration
                 159's seed comment) are called out inline as recommended. -->
            <hr>
            <h3 class="h6">Add an identifier</h3>
            <form method="post" action="/assets/identifiers-save" class="row g-2">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="assetID" value="<?php echo $assetId; ?>">
                <div class="col-md-4">
                    <label class="form-label small" for="identTypeCode">Type</label>
                    <select class="form-select form-select-sm" id="identTypeCode" name="typeCode" required>
                        <?php foreach ($identCategoryOrder as $identCat): ?>
                            <?php
                            $typesInCat = array_values(array_filter(
                                $identifierTypes,
                                static fn (array $t): bool => (string) $t['category'] === $identCat
                            ));
                            if (count($typesInCat) === 0) { continue; }
                            ?>
                            <optgroup label="<?php echo htmlspecialchars($identCategoryLabel[$identCat], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php foreach ($typesInCat as $t): ?>
                                    <?php
                                    // ⭐ GIAI/GRAI are the two GS1 keys purpose-built for
                                    // identifying assets — the seeded description already
                                    // says so (migration 159); surface it in the visible
                                    // option label too, not only a tooltip a keyboard/
                                    // screen-reader user might miss.
                                    $identRecommended = in_array((string) $t['typeCode'], ['GIAI', 'GRAI'], true) === true;
                                    ?>
                                    <option value="<?php echo htmlspecialchars((string) $t['typeCode'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars((string) $t['label'], ENT_QUOTES, 'UTF-8'); ?><?php echo $identRecommended === true ? ' ★ recommended for assets' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small" for="identValue">Value</label>
                    <input type="text" class="form-control form-control-sm" id="identValue" name="value" required maxlength="255">
                </div>
                <div class="col-md-4">
                    <label class="form-label small" for="identSubScheme">Sub-scheme <span class="text-muted">(optional)</span></label>
                    <input type="text" class="form-control form-control-sm" id="identSubScheme" name="subScheme" maxlength="30" placeholder="e.g. issuing agency">
                </div>
                <div class="col-md-8">
                    <label class="form-label small" for="identNotes">Notes <span class="text-muted">(optional)</span></label>
                    <input type="text" class="form-control form-control-sm" id="identNotes" name="notes" maxlength="500">
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="identIsPrimary" name="isPrimary">
                        <label class="form-check-label small" for="identIsPrimary">Make this the primary identifier</label>
                    </div>
                </div>
                <div class="col-12">
                    <small class="text-muted">Format/check-digit validation is informational only — an unusual or unrecognised value still saves; it just won't show the <i class="fa-solid fa-check text-success"></i> verified badge.</small>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus me-1"></i>Add identifier</button>
                </div>
            </form>
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

<!-- 🛡️ Insurance (#404 columns, first surfaced this pass — #408).
     $privileged-gated — the SAME admin/asset_manager/isResponsibleFor()
     gate as the Ownership & legal vault immediately above, since a
     policy number/insured value sits at roughly that same sensitivity
     (never shown to a plain logged-in viewer who merely reached this
     page). Only rendered at all when at least ONE insurance field is
     actually set, so an asset with no insurance recorded doesn't show an
     empty card. -->
<?php if ($privileged === true
    && ($asset['insurerName'] !== null || $asset['insurancePolicyNumber'] !== null
        || $asset['insuredValuePence'] !== null || $asset['insuranceRenewalDate'] !== null)
): ?>
<div class="card mb-3">
    <div class="card-header"><h2 class="h5 mb-0"><i class="fa-solid fa-shield-halved me-2"></i>Insurance</h2></div>
    <div class="card-body row g-3">
        <div class="col-md-3"><strong>Insurer</strong><br><?php echo $asset['insurerName'] !== null ? htmlspecialchars((string) $asset['insurerName'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>'; ?></div>
        <div class="col-md-3"><strong>Policy number</strong><br><?php echo $asset['insurancePolicyNumber'] !== null ? htmlspecialchars((string) $asset['insurancePolicyNumber'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>'; ?></div>
        <div class="col-md-3"><strong>Insured value</strong><br>
            <?php echo $asset['insuredValuePence'] !== null
                ? htmlspecialchars((string) $asset['currency'], ENT_QUOTES, 'UTF-8') . ' ' . number_format(((int) $asset['insuredValuePence']) / 100, 2)
                : '<span class="text-muted">—</span>'; ?>
        </div>
        <div class="col-md-3"><strong>Renewal date</strong><br><?php echo $asset['insuranceRenewalDate'] !== null ? htmlspecialchars((string) $asset['insuranceRenewalDate'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>'; ?></div>
        <?php if ($estimatedCurrentValuePence !== null && $asset['insuredValuePence'] !== null && (int) $asset['insuredValuePence'] < $estimatedCurrentValuePence): ?>
            <div class="col-12">
                <span class="badge bg-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i>Under-insured — insured value is below the estimated current value</span>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- 📊 Scan-analytics strip (#410) — manager-only. 30-day daily-count
     sparkbar built from plain flexbox + inline heights (house
     convention — no JS chart library anywhere in this app), reading
     AssetRegister::scanStats(), which folds together BOTH scan sources
     (the public /a/{token} page + an internal manager re-scan). -->
<?php if ($canManage === true): ?>
<div class="card mb-3">
    <div class="card-header"><h2 class="h5 mb-0"><i class="fa-solid fa-chart-column me-2"></i>Scan activity (last 30 days)</h2></div>
    <div class="card-body">
        <?php if ($scanStatsMax === 0): ?>
            <p class="text-muted mb-0">No scans recorded in the last 30 days.</p>
        <?php else: ?>
            <div class="d-flex align-items-end gap-1" style="height:60px;">
                <?php foreach ($scanStats as $s): ?>
                    <?php $barPct = (int) round(($s['count'] / $scanStatsMax) * 100); ?>
                    <div class="flex-fill bg-primary rounded-top"
                         style="height:<?php echo $s['count'] > 0 ? max(4, $barPct) : 0; ?>%; min-height:<?php echo $s['count'] > 0 ? '2px' : '0'; ?>;"
                         title="<?php echo htmlspecialchars((string) $s['date'] . ': ' . (string) $s['count'] . ' scan' . ((int) $s['count'] === 1 ? '' : 's'), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="text-muted small mt-2 mb-0">
                Total: <?php echo array_sum(array_column($scanStats, 'count')); ?> scans over the last 30 days (public lost-and-found page views + internal re-scans).
            </p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

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
