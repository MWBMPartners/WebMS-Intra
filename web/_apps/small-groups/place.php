<?php
// Path: _apps/small-groups/place.php
/**
 * -----------------------------------------------------------------------------
 * Small Groups — Add / Edit a host's home the group takes turns in 🏠
 * -----------------------------------------------------------------------------
 * `?id=` (groupID) + optional `?placeID=` for edit mode. Gate:
 * `SmallGroups::canManage()` — the same people who may change the group's usual
 * meeting place (its administrators, whoever the organisation points "Manage
 * every small group" at, and the group's own leaders).
 *
 * WHY THIS PAGE EXISTS (#809, D-123). The owner, 11 November 2026: "Small
 * Groups could also rotate who's home they meet in". So a group keeps a list
 * of hosts' homes beside its usual place, and each meeting can say which one
 * it was held in. Each home is its OWN host's (D-120): its map is shown only
 * where the person who entered THAT home agreed, and one host's agreement
 * never stands for another's. That is why the agreement box here is about
 * this one place, and why the record lives on this place's own row.
 *
 * The address fields are the shared #456 partial with its canonical names, so
 * `place-save.php` reads them back exactly as `save.php` does for the usual
 * place.
 *
 * @package   Portal\SmallGroups
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/809
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\SmallGroups;

require_once PORTAL_CORE . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'location-input.php';
require_once PORTAL_CORE . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'place-map-agreement.php';

Auth::ensureSession();
Auth::requireLogin();

if (SmallGroups::isEnabled() === false) {
    Router::renderError(404);
    return;
}

$siteId  = Site::id();
$groupId = (int) ($_GET['id'] ?? 0);
$group   = SmallGroups::getGroup($siteId, $groupId);
if ($group === null) {
    Router::renderError(404);
    return;
}
if (SmallGroups::canManage($siteId, $groupId) === false) {
    Router::renderError(403);
    return;
}

$placeId = (int) ($_GET['placeID'] ?? 0);
$place   = $placeId > 0 ? SmallGroups::getPlace($siteId, $groupId, $placeId) : null;
if ($placeId > 0 && ($place === null || (int) $place['isActive'] !== 1)) {
    // ✏️ A place of another group, another organisation, or one already
    //    removed: indistinguishable from a missing one.
    Router::renderError(404);
    return;
}
$isNew = $place === null;

$viewerId  = (int) (App::user()['userID'] ?? 0);
$enteredBy = $place !== null ? (int) ($place['placeEnteredByID'] ?? 0) : 0;
$agreed    = $place !== null && (int) ($place['placeMapAgreed'] ?? 0) === 1;

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$pageTitle   = ($isNew === true ? 'Add a host\'s home' : 'Edit a host\'s home') . ' — ' . $group['groupName'];
$pageSection = 'small-groups';
$breadcrumbs = [
    'Dashboard'                  => Site::url(''),
    'Small Groups'               => Site::url('small-groups'),
    (string) $group['groupName'] => Site::url('small-groups/group') . '?id=' . $groupId,
    ($isNew === true ? 'Add a host\'s home' : 'Edit a host\'s home') => '',
];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

$esc    = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$values = $place ?? [];
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo $esc($flashType); ?> alert-dismissible fade show">
        <?php echo $esc($flashMsg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-house me-2"></i><?php echo $esc($isNew === true ? 'Add a host\'s home' : 'Edit a host\'s home'); ?></h1>
    <a href="<?php echo htmlspecialchars(Site::url('small-groups/group'), ENT_QUOTES, 'UTF-8'); ?>?id=<?php echo $groupId; ?>" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i>Back</a>
</div>

<p class="text-secondary small">
    For a group that takes turns meeting in different members' homes. Each home here is its own host's: its map is
    shown only where the person who entered it agreed, and nobody else's agreement counts for it. Who can see the
    address follows the group's own setting, "Who can see the meeting address?".
</p>

<form method="post" action="<?php echo htmlspecialchars(Site::url('small-groups/place-save'), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="csrf_token" value="<?php echo $esc(Auth::csrfToken()); ?>">
    <input type="hidden" name="action" value="<?php echo $isNew === true ? 'create' : 'update'; ?>">
    <input type="hidden" name="groupID" value="<?php echo $groupId; ?>">
    <?php if ($isNew === false): ?>
        <input type="hidden" name="placeID" value="<?php echo $placeId; ?>">
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">The home</h5></div>
        <div class="card-body">
            <div class="row g-3 mb-3">
                <div class="col-12 col-md-6">
                    <label for="placeLabel" class="form-label">Short name <small class="text-muted">(optional, to choose it by)</small></label>
                    <input type="text" name="placeLabel" id="placeLabel" class="form-control" maxlength="100"
                           value="<?php echo $esc($values['placeLabel'] ?? ''); ?>">
                </div>
            </div>
            <?php
            portal_location_input([
                'values' => [
                    'line1'       => $values['addressLine1'] ?? '',
                    'line2'       => $values['addressLine2'] ?? '',
                    'city'        => $values['city'] ?? '',
                    'region'      => $values['region'] ?? '',
                    'postcode'    => $values['postcode'] ?? '',
                    'countryCode' => $values['countryCode'] ?? '',
                    'lat'         => $values['latitude'] ?? '',
                    'lng'         => $values['longitude'] ?? '',
                    'w3w'         => $values['what3words'] ?? '',
                ],
                'lookup'     => true,
                'w3wSuggest' => true,
            ]);
            portal_place_map_agreement([
                'isNew'       => $isNew,
                'enteredBy'   => $enteredBy,
                'agreed'      => $agreed,
                'viewerId'    => $viewerId,
                'entererName' => $enteredBy > 0 && $enteredBy !== $viewerId ? SmallGroups::entererName($enteredBy) : '',
                'what'        => 'this home',
            ]);
            ?>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i>Save</button>
        <a href="<?php echo htmlspecialchars(Site::url('small-groups/group'), ENT_QUOTES, 'UTF-8'); ?>?id=<?php echo $groupId; ?>" class="btn btn-secondary">Cancel</a>
    </div>
</form>

<?php if ($isNew === false): ?>
    <form method="post" action="<?php echo htmlspecialchars(Site::url('small-groups/place-save'), ENT_QUOTES, 'UTF-8'); ?>" class="mt-4">
        <input type="hidden" name="csrf_token" value="<?php echo $esc(Auth::csrfToken()); ?>">
        <input type="hidden" name="action" value="remove">
        <input type="hidden" name="groupID" value="<?php echo $groupId; ?>">
        <input type="hidden" name="placeID" value="<?php echo $placeId; ?>">
        <p class="small text-secondary mb-2">
            Removing this home takes it off the group's list and empties its address, who entered it and the agreement
            to a map. Past meetings held there will say they met somewhere no longer recorded.
        </p>
        <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Remove this home from the group's list?">
            <i class="fa-solid fa-trash me-1"></i>Remove this home
        </button>
    </form>
<?php endif; ?>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
