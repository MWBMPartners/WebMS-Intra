<?php
// Path: _apps/admin/settings/outside-services/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Outside services: what may be sent about a member to another company 🛂 (#778)
 * -----------------------------------------------------------------------------
 * Two levels on one page, because the owner's rule has two administrators in
 * it (D-058): the INSTALLATION decides what is allowed at all and the default,
 * and each ORGANISATION may hold itself to less, never more. A member may then
 * tighten again for themselves at /account/outside-services.
 *
 * WHO SEES WHAT
 *   - Any administrator of the open organisation (`App::isAdmin()`) sees the
 *     organisation's card and can change its OWN values.
 *   - The installation's card is drawn only for a global administrator, and
 *     the handler refuses anybody else whatever they post: the two levels may
 *     be different parties, so the boundary is stated at the page, the handler
 *     AND the writer (`OutsideServices::saveForInstallation()`).
 *
 * WHERE EACH BOX IS FILLED FROM, AND WHY IT MATTERS (#674, #677)
 *   - The installation's boxes from the INSTALLATION'S OWN ROWS. Never from the
 *     merged `$SETTINGS`: by the time a page runs that holds the open
 *     organisation's value laid over the installation's, so an untouched Save
 *     would make one organisation's choice everybody's.
 *   - The organisation's boxes from ITS OWN ROWS. With no row the box says
 *     "follow the installation" — never the installation's value written into
 *     the organisation's box, or one press of Save would pin it.
 *
 * WHAT THIS PAGE OFFERS AN ORGANISATION
 *   Only what the installation allows, drawn as ordinary options — never as
 *   `disabled` options, because a disabled control is not submitted (#667). A
 *   value the organisation stored BEFORE the installation tightened is still
 *   offered, selected and labelled as held down, because a `<select>` with no
 *   selected option is a vote for its first option (#763): an untouched Save
 *   must leave it alone, and the handler does not judge a value that did not
 *   change (#687).
 *
 * All the rules are in Portal\Core\OutsideServices; this file only shows them.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/778
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\OutsideServices;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$siteId        = Site::id();
$isGlobalAdmin = App::isRootAdmin();

// 📖 Both levels' OWN rows. A read that fails is said out loud rather than
//    drawn as a form that would save over what is there.
$readError    = '';
$installation = [];
$own          = [];
try {
    $installation = OutsideServices::storedAt($mysqli, null);
    $own          = OutsideServices::storedAt($mysqli, $siteId);
} catch (\Throwable $e) {
    error_log('[WebMS-Intra] outside-services page: the settings could not be read: ' . $e->getMessage());
    $readError = 'The outside-services settings could not be read, so nothing can be changed here right now. '
        . 'Until they can be read, every outside service is treated as not allowed.';
}

$flashMsg  = (string) ($_SESSION['flash_msg'] ?? '');
$flashType = (string) ($_SESSION['flash_type'] ?? '');
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

/** One short helper, because every value on this page goes through it. */
$e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

$csrf    = $e(Auth::csrfToken());
$saveUrl = $e(Site::url('admin/settings/outside-services/save'));

$pageTitle       = 'Outside services';
$pageDescription = 'Choose which outside companies may be sent something about a member: profile pictures and maps.';
$pageSection     = 'admin';
$breadcrumbs     = ['Dashboard' => Site::url(''), 'Admin' => Site::url('admin'), 'Outside services' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo $e($flashType !== '' ? $flashType : 'info'); ?>" role="status">
        <?php echo $e($flashMsg); ?>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <h1 class="mb-0 text-break"><i class="fa-solid fa-shield-halved me-2" aria-hidden="true"></i>Outside services</h1>
    <a href="<?php echo $e(Site::url('admin')); ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>Back to Admin
    </a>
</div>

<p class="text-secondary">
    Some pictures are not stored on this portal but fetched from another company's servers, and fetching
    one tells that company something about a member. This page decides which of them may be used.
</p>
<p class="text-secondary">
    <strong>The strictest choice always wins.</strong> Whoever runs this portal decides what is allowed at all.
    Each organisation may allow less, never more. Each member may then switch a service off for themselves,
    or on where it is allowed. A member who belongs to several organisations is held to the strictest of all
    of them, whichever organisation's pages they are reading.
</p>

<?php if ($readError !== ''): ?>
    <div class="alert alert-danger" role="alert"><?php echo $e($readError); ?></div>
<?php else: ?>

    <?php if ($isGlobalAdmin === true): ?>
        <!-- 🏢 The installation's values: a global administrator only. -->
        <form method="post" action="<?php echo $saveUrl; ?>" class="card mb-4">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="action" value="installation">
            <div class="card-body">
                <h2 class="h5">For the whole installation</h2>
                <p class="small text-secondary">
                    What is allowed at all, and how it starts for a member who has not chosen. Every organisation
                    on this installation is held to this.
                </p>
                <div class="portal-data-list">
                    <?php foreach (OutsideServices::SERVICES as $service => $about): ?>
                        <?php
                        // Held to what the service allows at all: a map is never on by
                        // default (D-072), so a stored "on" for it shows as "off".
                        $current = OutsideServices::word(OutsideServices::rank(OutsideServices::capFor($service, $installation[$service] ?? null)));
                        ?>
                        <div class="row g-2 py-2 border-bottom align-items-center">
                            <div class="col-12 col-md-7">
                                <label class="fw-semibold" for="inst-<?php echo $e($service); ?>"><?php echo $e($about['name']); ?></label>
                                <div class="small text-secondary">Sent to <?php echo $e($about['company']); ?>: <?php echo $e($about['sends']); ?></div>
                            </div>
                            <div class="col-12 col-md-5">
                                <select class="form-select form-select-sm" id="inst-<?php echo $e($service); ?>" name="installation_<?php echo $e($service); ?>">
                                    <?php foreach (OutsideServices::levelValuesFor($service) as $value): ?>
                                        <option value="<?php echo $e($value); ?>"<?php echo $value === $current ? ' selected' : ''; ?>><?php echo $e(ucfirst(OutsideServices::longWord($value))); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (isset($installation[$service]) === false): ?>
                                    <div class="small text-danger mt-1">Nothing is stored, so this counts as not allowed.</div>
                                <?php endif; ?>
                                <?php if (isset($installation[$service]) === true && OutsideServices::rank($installation[$service]) > OutsideServices::rank($current)): ?>
                                    <div class="small text-warning-emphasis mt-1">
                                        Stored: "<?php echo $e(OutsideServices::longWord(OutsideServices::word(OutsideServices::rank($installation[$service])))); ?>".
                                        A map is never on by default, so it counts as "<?php echo $e(OutsideServices::longWord($current)); ?>".
                                        Saving leaves the stored value as it is unless you choose something else.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="btn btn-primary btn-sm mt-3">Save for the whole installation</button>
            </div>
        </form>
    <?php endif; ?>

    <!-- 🏛️ This organisation's own values. -->
    <form method="post" action="<?php echo $saveUrl; ?>" class="card mb-4">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="action" value="organisation">
        <div class="card-body">
            <h2 class="h5">For <?php echo $e((string) (Site::branding('name') ?? 'this organisation')); ?></h2>
            <p class="small text-secondary">
                Your organisation may allow less than the installation, never more. Choices above what the
                installation allows are not offered.
            </p>
            <div class="portal-data-list">
                <?php foreach (OutsideServices::SERVICES as $service => $about): ?>
                    <?php
                    // Both levels are read through capFor(): a map is never on by
                    // default (D-072), whatever a hand-written row says.
                    $allowedRank = OutsideServices::rank(OutsideServices::capFor($service, $installation[$service] ?? null));
                    $rawOwn      = $own[$service] ?? null;
                    $ownValue    = OutsideServices::capFor($service, $rawOwn);
                    // A stored value above what the SERVICE allows at all (a map
                    // "on", only reachable by hand-written SQL or a restore). Said
                    // out loud below, so the card never claims the organisation
                    // chose what it shows (#778, round 2).
                    $cappedByService = $rawOwn !== null && OutsideServices::rank($rawOwn) > OutsideServices::rank((string) $ownValue);
                    $ownRank     = $ownValue === null ? null : OutsideServices::rank($ownValue);
                    // What is in force for this organisation's members before their
                    // own choices and their OTHER organisations: the stricter of
                    // the two levels on this page.
                    $inForce = $ownRank === null ? $allowedRank : min($allowedRank, $ownRank);
                    $options = [OutsideServices::INHERIT];
                    foreach (OutsideServices::levelValuesFor($service) as $value) {
                        if (OutsideServices::rank($value) <= $allowedRank) {
                            $options[] = $value;
                        }
                    }
                    // The value already stored stays selectable even if the
                    // installation has since tightened below it (see the header).
                    $selected = $ownValue === null ? OutsideServices::INHERIT : OutsideServices::word((int) $ownRank);
                    $heldDown = $ownValue !== null && in_array($selected, $options, true) === false;
                    if ($heldDown === true) {
                        $options[] = $selected;
                    }
                    ?>
                    <div class="row g-2 py-2 border-bottom align-items-center">
                        <div class="col-12 col-md-7">
                            <label class="fw-semibold" for="org-<?php echo $e($service); ?>"><?php echo $e($about['name']); ?></label>
                            <div class="small text-secondary">
                                The installation allows: <strong><?php echo $e(OutsideServices::longWord(OutsideServices::word($allowedRank))); ?></strong>.
                                In force for your members now: <strong><?php echo $e(OutsideServices::longWord(OutsideServices::word($inForce))); ?></strong>
                                (a member who also belongs to another organisation may be held to less).
                            </div>
                            <?php if ($cappedByService === true): ?>
                                <div class="small text-warning-emphasis mt-1">
                                    Stored for your organisation: "<?php echo $e(OutsideServices::longWord(OutsideServices::word(OutsideServices::rank($rawOwn)))); ?>".
                                    A map is never on by default, so that counts as "<?php echo $e(OutsideServices::longWord((string) $ownValue)); ?>"<?php
                                    if ($heldDown === true): ?>, and the installation now allows only "<?php echo $e(OutsideServices::longWord(OutsideServices::word($allowedRank))); ?>", so that applies<?php endif; ?>.
                                    Saving leaves the stored value as it is unless you choose something else.
                                </div>
                            <?php elseif ($heldDown === true): ?>
                                <div class="small text-warning-emphasis mt-1">
                                    Your organisation chose "<?php echo $e(OutsideServices::longWord($selected)); ?>", but the installation
                                    has since allowed less, so the installation's choice applies. Saving leaves your choice as it is.
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12 col-md-5">
                            <select class="form-select form-select-sm" id="org-<?php echo $e($service); ?>" name="organisation_<?php echo $e($service); ?>">
                                <?php foreach ($options as $value): ?>
                                    <?php
                                    $label = $value === OutsideServices::INHERIT
                                        ? 'Follow the installation (' . OutsideServices::longWord(OutsideServices::word($allowedRank)) . ')'
                                        : ucfirst(OutsideServices::longWord($value));
                                    if ($value === $selected && $heldDown === true) {
                                        $label .= ' (held down by the installation)';
                                    }
                                    ?>
                                    <option value="<?php echo $e($value); ?>"<?php echo $value === $selected ? ' selected' : ''; ?>><?php echo $e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-primary btn-sm mt-3">Save for this organisation</button>
        </div>
    </form>

    <div class="card mb-4">
        <div class="card-body small text-secondary">
            <h2 class="h6">Maps of a member's home</h2>
            <p class="mb-0">
                A map on a member's directory profile involves two people: the member whose home it shows, and the
                person looking. It is drawn only when the member shown has switched it on THEMSELVES and the person
                looking has not switched it off. Otherwise the location is shown as text. That is why maps can be
                allowed ("off until a member switches it on") or not allowed, and never "on" by default. Members choose at
                <a href="<?php echo $e(Site::url('account/outside-services')); ?>">My account, Outside services</a>.
            </p>
        </div>
    </div>

<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
