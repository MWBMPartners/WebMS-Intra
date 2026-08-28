<?php
// Path: _apps/venues/manage.php
/**
 * -----------------------------------------------------------------------------
 * Venue Bookings — Manage Venues 🏛️
 * -----------------------------------------------------------------------------
 * The venue admin hub: list every hired building for this site (name,
 * landlord, active state, booking count) with an inline add/edit form
 * (`?edit=ID` prefill — orgs.php l.84-99 shape) posting to `venue-save.php`.
 *
 * Landlord organisations are NOT duplicated here — they live in the shared
 * `tblAssetOrgs` registry (01-data-model §1.1) via `AssetRegister::listOrgs()`
 * / `::saveOrg()` (§3.3 of the build plan). This screen offers a picker from
 * existing orgs PLUS a collapsible "New landlord…" fieldset so venue creation
 * works end-to-end even when the Assets app is toggled off; when Assets IS
 * enabled we additionally surface a deep-link to the fuller `/assets/orgs`
 * management screen.
 *
 * @package   Portal\Venues
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/429
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AppRegistry;
use Portal\Core\AssetRegister;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\Venues;

Auth::ensureSession();
Auth::requireLogin();

// 🛡️ Manager gate — admins or the venue_manager role only.
if (Venues::canManage() !== true) {
    Router::renderError(403);
    return;
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$db     = App::db();

// 📋 Venue list + per-venue booking count (read-only decoration — no
// Venues:: helper returns this, so a small site-scoped prepared SELECT is
// the pragmatic choice, same spirit as rooms.php's "future bookings" count).
$venues = Venues::listVenues($siteId, false);
foreach ($venues as &$v) {
    $v['bookingCount'] = 0;
    $cnt = $db->prepare('SELECT COUNT(*) AS c FROM tblVenueBookings WHERE venueID = ? AND siteID = ? AND isDeleted = 0');
    if ($cnt !== false) {
        $venueIdForCount = (int) $v['venueID'];
        $cnt->bind_param('ii', $venueIdForCount, $siteId);
        $cnt->execute();
        $v['bookingCount'] = (int) ($cnt->get_result()->fetch_assoc()['c'] ?? 0);
        $cnt->close();
    }
}
unset($v);

// 🏢 Landlord pickers — active-only for the "choose existing" select
// (§4.2), full list (incl. inactive) indexed by id so an already-assigned
// (possibly retired) landlord can still be shown/edited on the edit form.
$activeOrgs = AssetRegister::listOrgs($siteId, true);
$allOrgs    = AssetRegister::listOrgs($siteId, false);
$orgsById   = [];
foreach ($allOrgs as $org) {
    $orgsById[(int) $org['orgID']] = $org;
}
$assetsEnabled = AppRegistry::isEnabled('assets');

// ✏️ Inline "edit" — prefill the form from ?edit=ID (no JS required).
$editId     = (int) ($_GET['edit'] ?? 0);
$editVenue  = $editId > 0 ? Venues::getVenue($editId, $siteId) : null;
$editLandlord = ($editVenue !== null && (int) ($editVenue['landlordOrgID'] ?? 0) > 0)
    ? ($orgsById[(int) $editVenue['landlordOrgID']] ?? null)
    : null;

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

// 🌐 All IANA timezones the system knows about, for the venue timezone field.
$allTimezones = \DateTimeZone::listIdentifiers();

$pageTitle   = 'Manage Venues';
$pageSection = 'venues';
$breadcrumbs = ['Dashboard' => '/', 'Venues' => '/venues', 'Manage' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="mb-0"><i class="fa-solid fa-building-columns me-2"></i>Manage Venues</h1>
    <a href="/venues" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to schedule</a>
</div>
<p class="text-muted">Rented external buildings — the landlord, address, timezone and on-site contact for each hire arrangement.</p>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5"><?php echo $editVenue !== null ? 'Edit venue' : 'Add venue'; ?></h2>
        <form method="post" action="/venues/venue-save" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="venueID" value="<?php echo $editVenue !== null ? (int) $editVenue['venueID'] : 0; ?>">

            <div class="col-md-5">
                <label class="form-label small" for="venueName">Venue name *</label>
                <input type="text" class="form-control form-control-sm" id="venueName" name="venueName" required maxlength="255"
                       value="<?php echo $editVenue !== null ? htmlspecialchars((string) $editVenue['venueName'], ENT_QUOTES, 'UTF-8') : ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small" for="landlordOrgID">Landlord</label>
                <select class="form-select form-select-sm" id="landlordOrgID" name="landlordOrgID">
                    <option value="0">— none —</option>
                    <?php foreach ($activeOrgs as $org): ?>
                        <option value="<?php echo (int) $org['orgID']; ?>"
                            <?php echo ($editVenue !== null && (int) ($editVenue['landlordOrgID'] ?? 0) === (int) $org['orgID']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) $org['orgName'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small" for="timezone">Timezone</label>
                <input type="text" class="form-control form-control-sm" id="timezone" name="timezone" list="venueTzList" maxlength="64"
                       value="<?php echo htmlspecialchars($editVenue !== null ? (string) $editVenue['timezone'] : 'Europe/London', ENT_QUOTES, 'UTF-8'); ?>">
                <datalist id="venueTzList">
                    <?php foreach ($allTimezones as $tz): ?>
                        <option value="<?php echo htmlspecialchars($tz, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="col-12">
                <button class="btn btn-link btn-sm p-0" type="button" data-bs-toggle="collapse" data-bs-target="#newLandlordFieldset">
                    <i class="fa-solid fa-plus me-1"></i>New landlord…
                </button>
                <?php if ($assetsEnabled === true): ?>
                    <a href="/assets/orgs" class="small ms-3"><i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Manage organisations</a>
                <?php endif; ?>
            </div>
            <div class="collapse col-12" id="newLandlordFieldset">
                <fieldset class="border rounded p-2 mt-1">
                    <legend class="fs-6 mb-2">Create a new landlord (leave blank to use the selection above)</legend>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label small" for="landlord_orgName">Organisation name</label>
                            <input type="text" class="form-control form-control-sm" id="landlord_orgName" name="landlord_orgName" maxlength="255">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small" for="landlord_contactName">Contact name</label>
                            <input type="text" class="form-control form-control-sm" id="landlord_contactName" name="landlord_contactName" maxlength="150">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small" for="landlord_contactEmail">Contact email</label>
                            <input type="email" class="form-control form-control-sm" id="landlord_contactEmail" name="landlord_contactEmail" maxlength="255">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small" for="landlord_contactPhone">Contact phone</label>
                            <input type="text" class="form-control form-control-sm" id="landlord_contactPhone" name="landlord_contactPhone" maxlength="50">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small" for="landlord_agreementRef">Agreement reference</label>
                            <input type="text" class="form-control form-control-sm" id="landlord_agreementRef" name="landlord_agreementRef" maxlength="100">
                        </div>
                    </div>
                </fieldset>
            </div>

            <div class="col-md-6">
                <label class="form-label small" for="addressLine1">Address line 1</label>
                <input type="text" class="form-control form-control-sm" id="addressLine1" name="addressLine1" maxlength="255"
                       value="<?php echo $editVenue !== null ? htmlspecialchars((string) ($editVenue['addressLine1'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label small" for="addressLine2">Address line 2</label>
                <input type="text" class="form-control form-control-sm" id="addressLine2" name="addressLine2" maxlength="255"
                       value="<?php echo $editVenue !== null ? htmlspecialchars((string) ($editVenue['addressLine2'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small" for="city">City / town</label>
                <input type="text" class="form-control form-control-sm" id="city" name="city" maxlength="100"
                       value="<?php echo $editVenue !== null ? htmlspecialchars((string) ($editVenue['city'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small" for="region">County / region</label>
                <input type="text" class="form-control form-control-sm" id="region" name="region" maxlength="100"
                       value="<?php echo $editVenue !== null ? htmlspecialchars((string) ($editVenue['region'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small" for="postcode">Postcode</label>
                <input type="text" class="form-control form-control-sm" id="postcode" name="postcode" maxlength="20"
                       value="<?php echo $editVenue !== null ? htmlspecialchars((string) ($editVenue['postcode'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small" for="countryCode">Country</label>
                <input type="text" class="form-control form-control-sm text-uppercase" id="countryCode" name="countryCode" maxlength="2"
                       value="<?php echo htmlspecialchars($editVenue !== null ? (string) $editVenue['countryCode'] : 'GB', ENT_QUOTES, 'UTF-8'); ?>">
            </div>

            <div class="col-md-4">
                <label class="form-label small" for="caretakerName">On-site caretaker name</label>
                <input type="text" class="form-control form-control-sm" id="caretakerName" name="caretakerName" maxlength="150"
                       value="<?php echo $editVenue !== null ? htmlspecialchars((string) ($editVenue['caretakerName'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small" for="caretakerPhone">Caretaker phone</label>
                <input type="text" class="form-control form-control-sm" id="caretakerPhone" name="caretakerPhone" maxlength="50"
                       value="<?php echo $editVenue !== null ? htmlspecialchars((string) ($editVenue['caretakerPhone'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?>">
            </div>
            <div class="col-12">
                <label class="form-label small" for="notes">Notes</label>
                <textarea class="form-control form-control-sm" id="notes" name="notes" rows="2"><?php echo $editVenue !== null ? htmlspecialchars((string) ($editVenue['notes'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?></textarea>
                <div class="form-text text-warning-emphasis">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i>Never store alarm codes or key-safe numbers here — attach them to a hire agreement document instead.
                </div>
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fa-solid fa-<?php echo $editVenue !== null ? 'check' : 'plus'; ?> me-1"></i><?php echo $editVenue !== null ? 'Update venue' : 'Add venue'; ?>
                </button>
                <?php if ($editVenue !== null): ?>
                    <a href="/venues/manage" class="btn btn-outline-secondary btn-sm">Cancel edit</a>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($editLandlord !== null): ?>
            <hr>
            <h3 class="h6">Edit landlord contact — <?php echo htmlspecialchars((string) $editLandlord['orgName'], ENT_QUOTES, 'UTF-8'); ?></h3>
            <form method="post" action="/venues/venue-save" class="row g-2">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="landlord_edit">
                <input type="hidden" name="orgID" value="<?php echo (int) $editLandlord['orgID']; ?>">
                <input type="hidden" name="returnVenueID" value="<?php echo (int) $editVenue['venueID']; ?>">
                <div class="col-md-4">
                    <label class="form-label small" for="le_orgName">Organisation name</label>
                    <input type="text" class="form-control form-control-sm" id="le_orgName" name="landlord_orgName" maxlength="255"
                           value="<?php echo htmlspecialchars((string) $editLandlord['orgName'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small" for="le_contactName">Contact name</label>
                    <input type="text" class="form-control form-control-sm" id="le_contactName" name="landlord_contactName" maxlength="150"
                           value="<?php echo htmlspecialchars((string) ($editLandlord['contactName'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small" for="le_contactEmail">Contact email</label>
                    <input type="email" class="form-control form-control-sm" id="le_contactEmail" name="landlord_contactEmail" maxlength="255"
                           value="<?php echo htmlspecialchars((string) ($editLandlord['contactEmail'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small" for="le_contactPhone">Contact phone</label>
                    <input type="text" class="form-control form-control-sm" id="le_contactPhone" name="landlord_contactPhone" maxlength="50"
                           value="<?php echo htmlspecialchars((string) ($editLandlord['contactPhone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small" for="le_agreementRef">Agreement reference</label>
                    <input type="text" class="form-control form-control-sm" id="le_agreementRef" name="landlord_agreementRef" maxlength="100"
                           value="<?php echo htmlspecialchars((string) ($editLandlord['agreementRef'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-check me-1"></i>Save landlord contact</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if (count($venues) === 0): ?>
    <div class="alert alert-info">No venues yet — add one above to get started.</div>
<?php else: ?>
    <div class="portal-data-list">
        <div class="portal-data-header">
            <div class="col-3">Venue</div>
            <div class="col-2">Landlord</div>
            <div class="col-1">Bookings</div>
            <div class="col-6 text-end">Actions</div>
        </div>
        <?php foreach ($venues as $venue): ?>
            <div class="portal-data-row align-items-center">
                <div class="col-3">
                    <strong><?php echo htmlspecialchars((string) $venue['venueName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <?php if ((int) $venue['isActive'] === 0): ?><span class="badge bg-secondary ms-1">inactive</span><?php endif; ?>
                    <br><small class="text-muted"><?php echo htmlspecialchars((string) $venue['timezone'], ENT_QUOTES, 'UTF-8'); ?></small>
                </div>
                <div class="col-2 small text-muted"><?php echo htmlspecialchars((string) ($venue['landlordName'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="col-1"><span class="badge bg-light text-dark border"><?php echo (int) $venue['bookingCount']; ?></span></div>
                <div class="col-6 text-end">
                    <a href="/venues/manage?edit=<?php echo (int) $venue['venueID']; ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                    <a href="/venues/rooms?venue=<?php echo (int) $venue['venueID']; ?>" class="btn btn-sm btn-outline-secondary" title="Rooms"><i class="fa-solid fa-door-open"></i></a>
                    <a href="/venues/usage-types?venue=<?php echo (int) $venue['venueID']; ?>" class="btn btn-sm btn-outline-secondary" title="Usage types"><i class="fa-solid fa-clock"></i></a>
                    <form method="post" action="/venues/venue-save" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="venueID" value="<?php echo (int) $venue['venueID']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-<?php echo (int) $venue['isActive'] === 1 ? 'warning' : 'success'; ?>"
                                title="<?php echo (int) $venue['isActive'] === 1 ? 'Deactivate' : 'Activate'; ?>">
                            <i class="fa-solid fa-toggle-<?php echo (int) $venue['isActive'] === 1 ? 'on' : 'off'; ?>"></i>
                        </button>
                    </form>
                    <?php if (App::isAdmin() === true): ?>
                        <form method="post" action="/venues/venue-save" class="d-inline" data-confirm="Delete this venue permanently? Only possible when it has no bookings, invoices or agreements attached." data-confirm-destructive="true">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="venueID" value="<?php echo (int) $venue['venueID']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="mt-3">
    <a href="/venues/statuses" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-flag me-1"></i>Site-wide booking statuses</a>
    <a href="/venues/settings" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-gear me-1"></i>Settings</a>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
