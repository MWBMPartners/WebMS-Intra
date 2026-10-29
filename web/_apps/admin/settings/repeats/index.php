<?php
// Path: _apps/admin/settings/repeats/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — How big a repeating series may be 🔁 (#569, migration 227)
 * -----------------------------------------------------------------------------
 * Two numbers: the most dates one repeating event may create, and how far ahead
 * it may reach. Settable for this organisation, or — by a global administrator
 * — for the whole installation.
 *
 * WHY THESE ARE SETTINGS AND NOT NUMBERS IN THE CODE
 * -----------------------------------------------------------------------------
 * `Portal\Core\EventRecurrence` first hard-coded 200 dates and three years.
 * They are sensible numbers for a church with one Sunday service and the wrong
 * ones for a school with a daily registration period. The owner asked on
 * 29 October 2026 for the ceiling to be configurable per organisation and per
 * installation, which is what this page is.
 *
 * WHY THERE IS STILL A LIMIT ON THE LIMIT
 * -----------------------------------------------------------------------------
 * A configurable ceiling with no ceiling of its own is not a ceiling. A series
 * is created inside ONE web request, on shared hosting, under whatever time
 * limit the host sets — and there is no bulk undo for a runaway series. So this
 * page refuses anything above `EventRecurrence::HARD_MAX_OCCURRENCES` and
 * `HARD_MAX_YEARS`, and the generator refuses to honour more than those even if
 * the row is edited straight in the database. The reasons are written beside
 * those constants rather than repeated here.
 *
 * THE INSTALLATION HAS THE LAST WORD, AND THAT IS A BOUNDARY BETWEEN PEOPLE
 * -----------------------------------------------------------------------------
 * For almost every setting an organisation's own choice overrides the
 * installation-wide one. A LIMIT is the exception (the owner, 29 October 2026):
 * an organisation may hold itself to a LOWER ceiling, but can never raise its
 * own above what the installation allows.
 *
 * This is not a tidy-up of the settings rules. The person who administers the
 * INSTALLATION may be a different person from the administrators of the
 * organisations on it — a hosting provider and its customers, say. So the
 * installation's limit is a boundary between two parties, and it is enforced
 * in the SAVE HANDLER rather than only by the `max` on the box here. A control
 * that is merely absent from a page is a courtesy; the handler is the control.
 *
 * It follows that an organisation's administrator is TOLD what the
 * installation allows, and told who can change it, rather than having a bigger
 * number quietly reduced. Silently clamping somebody else's number is how a
 * person comes to believe a limit is in force when it is not.
 *
 * WHY BOTH LEVELS ARE SHOWN
 * -----------------------------------------------------------------------------
 * "It says 200" and "it says 200 because nobody here has chosen anything" are
 * different things to know. An administrator who cannot see which level
 * answered has no way to tell whether changing the installation-wide default
 * would affect them.
 *
 * WHAT THIS PAGE DOES NOT DO
 * -----------------------------------------------------------------------------
 * Changing either number affects series created AFTERWARDS. Dates already in
 * the diary are real event rows and are left exactly alone — lowering the
 * ceiling never deletes anything. That is said on the page too, because the
 * opposite assumption would be a frightening one to make silently.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/569
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\EventRecurrence;
use Portal\Core\InstallationSettings;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$siteId = Site::id();

// 🌍 Only a global administrator may change the row every other organisation
//    on this installation inherits. Worked out once here and tested again in
//    the save handler, so hiding the control stays a courtesy rather than
//    being the control itself.
$mayChangeInstallationWide = App::isRootAdmin();

/**
 * Read one settings row at one exact level.
 *
 * The ordinary settings reader deliberately hides which level answered,
 * because almost every caller wants only the effective value. This page has to
 * SHOW the difference, so it asks each level separately.
 *
 * @param \mysqli  $db     An open connection.
 * @param string   $key    The settings key.
 * @param int|null $siteId The organisation, or null for the installation-wide row.
 *
 * @return string|null The stored value, or null when there is no row at that level.
 */
function repeats_read_level(\mysqli $db, string $key, ?int $siteId): ?string
{
    if ($siteId === null) {
        $stmt = $db->prepare(
            'SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID IS NULL LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('s', $key);
    } else {
        $stmt = $db->prepare(
            'SELECT settingValue FROM tblSettings WHERE settingKey = ? AND siteID = ? LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('si', $key, $siteId);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row !== null ? (string) $row['settingValue'] : null;
}

$ownDates    = repeats_read_level($mysqli, EventRecurrence::OCCURRENCES_KEY, $siteId);
$globalDates = repeats_read_level($mysqli, EventRecurrence::OCCURRENCES_KEY, null);
$ownYears    = repeats_read_level($mysqli, EventRecurrence::YEARS_KEY, $siteId);
$globalYears = repeats_read_level($mysqli, EventRecurrence::YEARS_KEY, null);

// 🧢 What is actually in force for this organisation, read through the ONE
//    place that reads these settings — so this page can never show a number
//    the generator would not actually honour.
//
//    BOTH LAYERS are passed. `$SETTINGS` is the merged answer; the settings
//    loader keeps `$SETTINGS_INSTALLATION` beside it holding the
//    installation-wide rows alone, precisely so a limit can be capped by the
//    installation rather than overridden by an organisation.
$installationLayer = InstallationSettings::layer();
$inForce           = EventRecurrence::limitsFrom($SETTINGS, InstallationSettings::layerOrNull());

// 🏢 And what the installation allows, on its own: the most this
//    organisation may ask for.
$allowed = EventRecurrence::installationAllows($installationLayer);

// 🏷️ WHERE EACH NUMBER IN FORCE ACTUALLY CAME FROM.
//
//    This used to be "a row exists for this organisation", which was WRONG and
//    was caught by the independent check: the number PRINTED is the smaller of
//    the organisation's and the installation's, so with the organisation's row
//    at 200 and the installation later lowered to 6, the page said
//    "At most 6 dates — your organisation's own setting". The organisation's
//    own setting was 200. The installation's was 6.
//
//    That state is not hypothetical: the installation form on this very page
//    tells a global administrator that lowering a number here lowers it for an
//    organisation that had chosen a higher one WITHOUT changing what that
//    organisation has stored. So the page has to be able to say "yours, but the
//    installation is holding it down".
//
//    Four answers, worked out from the numbers themselves rather than from
//    whether a row happens to exist.
$whereFrom = static function (?string $own, int $effective, int $allowed): string {
    // A row that holds nothing usable — empty, zero, negative, not a number —
    // is ignored by the reader, so the honest answer is the same as having no
    // row at all. Only reachable by editing the database directly: the save
    // handler refuses every one of those shapes.
    //    The pattern is the READER'S, not a stricter one of this page's own:
    //    `EventRecurrence` accepts `^-?\d{1,9}$` and then refuses anything
    //    below 1, so `07` counts as 7 there. A tighter pattern here would
    //    leave the page attributing a number the reader had honoured.
    $ownUsable = preg_match('/^-?\d{1,9}$/', trim((string) $own)) === 1 && (int) trim((string) $own) >= 1;
    if ($own === null || $ownUsable === false) {
        return 'the installation-wide setting, because your organisation has not chosen its own.';
    }
    $ownNumber = (int) $own;
    if ($ownNumber === $effective) {
        return 'your organisation\'s own setting.';
    }
    if ($effective === $allowed) {
        return 'the installation\'s limit. Your organisation has chosen ' . $ownNumber
            . ', which is more than the installation allows, so the installation\'s number applies.';
    }

    return 'the installation\'s limit.';
};

$datesFrom = $whereFrom($ownDates, (int) $inForce['occurrences'], (int) $allowed['occurrences']);
$yearsFrom = $whereFrom($ownYears, (int) $inForce['years'], (int) $allowed['years']);

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');

$pageTitle       = 'Repeating Events';
$pageDescription = 'How many dates a repeating event may create, and how far ahead it may reach.';
$pageSection     = 'admin';
$breadcrumbs     = [
    'Dashboard'         => Site::url(''),
    'Calendar'          => Site::url('calendar'),
    'Repeating events'  => '',
];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="mb-0"><i class="fa-solid fa-repeat me-2"></i>Repeating events</h1>
    <a href="<?php echo htmlspecialchars(Site::url('calendar'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fa-solid fa-arrow-left me-1"></i>Back to the calendar
    </a>
</div>

<p class="text-secondary">
    When somebody creates an event that repeats, the portal works out every date and puts each one in
    the diary as a real event. These two numbers decide how many it will make in one go. They exist
    because a repeat with no end date would otherwise go on for ever.
</p>

<!-- 📊 What is in force right now, and which level decided it. -->
<div class="card mb-4 border-primary">
    <div class="card-body">
        <h2 class="h5 card-title">In force for your organisation now</h2>
        <ul class="portal-data-list mb-0">
            <li>
                <strong>At most <?php echo (int) $inForce['occurrences']; ?> dates</strong> in one series
                &mdash; <?php echo htmlspecialchars($datesFrom, ENT_QUOTES, 'UTF-8'); ?>
            </li>
            <li>
                <strong>Reaching at most <?php echo (int) $inForce['years']; ?>
                <?php echo $inForce['years'] === 1 ? 'year' : 'years'; ?> ahead</strong>
                &mdash; <?php echo htmlspecialchars($yearsFrom, ENT_QUOTES, 'UTF-8'); ?>
            </li>
        </ul>
    </div>
</div>

<!-- 🏢 What the installation allows, and that it cannot be exceeded here. -->
<div class="alert alert-secondary">
    <i class="fa-solid fa-building me-2"></i>
    This installation allows at most <strong><?php echo (int) $allowed['occurrences']; ?> dates</strong>
    and <strong><?php echo (int) $allowed['years']; ?>
    <?php echo $allowed['years'] === 1 ? 'year' : 'years'; ?></strong> ahead. Your organisation can set
    <em>lower</em> numbers than that, but not higher
    <?php if ($mayChangeInstallationWide === false): ?>
        &mdash; only whoever administers the whole installation can raise them, and that may not be
        somebody in your organisation.
    <?php else: ?>
        &mdash; you can change what the installation allows in the second form below.
    <?php endif; ?>
</div>

<?php
// 📏 WHICH LIMIT A WEEKLY EVENT MEETS FIRST, worked out rather than asserted.
//
//    This sentence used to say flatly that a weekly event stops on the years
//    limit and a twice-weekly one on the number of dates. That is true of the
//    DEFAULTS and false for exactly the settings this page exists to allow —
//    with a ceiling of 5 dates a weekly event plainly stops on the dates. The
//    independent check flagged it. 52 weeks a year is near enough for a
//    sentence of guidance, and no claim is made beyond the weekly case.
$weeklyDatesInHorizon = 52 * (int) $inForce['years'];
$weeklyStopsOnDates   = $weeklyDatesInHorizon > (int) $inForce['occurrences'];
?>
<p class="text-secondary small">
    Whichever of the two is reached first stops the series, and the person creating it is told it was
    cut short so they can extend it later. With
    <?php echo (int) $inForce['occurrences']; ?> dates and
    <?php echo (int) $inForce['years']; ?> <?php echo $inForce['years'] === 1 ? 'year' : 'years'; ?>,
    a weekly event would stop on
    <?php if ($weeklyStopsOnDates === true): ?>
        the number of dates, after about
        <?php echo (int) $inForce['occurrences']; ?> weeks.
    <?php else: ?>
        how far ahead it may reach, after about <?php echo (int) $weeklyDatesInHorizon; ?> dates.
    <?php endif; ?>
</p>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info me-2"></i>
    Changing either number affects series created <strong>afterwards</strong>. Dates already in your
    diary are ordinary events and are left exactly as they are &mdash; lowering a limit never deletes
    anything.
</div>

<!-- ✏️ This organisation's own setting. -->
<form method="post" action="<?php echo htmlspecialchars(Site::url('admin/settings/repeats/save'), ENT_QUOTES, 'UTF-8'); ?>" class="card mb-4">
    <div class="card-body">
        <h2 class="h5 card-title">Your organisation</h2>
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="scope" value="site">

        <div class="row g-3">
            <div class="col-md-6">
                <label for="site-dates" class="form-label">Most dates in one series</label>
                <input type="number" class="form-control" id="site-dates" name="maxOccurrences"
                       min="1" max="<?php echo (int) $allowed['occurrences']; ?>" required
                       value="<?php echo htmlspecialchars((string) ($ownDates ?? $inForce['occurrences']), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-text">
                    Between 1 and <?php echo (int) $allowed['occurrences']; ?>, which is what this
                    installation allows.
                </div>
            </div>
            <div class="col-md-6">
                <label for="site-years" class="form-label">How far ahead, in years</label>
                <input type="number" class="form-control" id="site-years" name="maxYears"
                       min="1" max="<?php echo (int) $allowed['years']; ?>" required
                       value="<?php echo htmlspecialchars((string) ($ownYears ?? $inForce['years']), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-text">
                    Between 1 and <?php echo (int) $allowed['years']; ?>, which is what this
                    installation allows.
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary mt-3">
            <i class="fa-solid fa-floppy-disk me-1"></i>Save for my organisation
        </button>
    </div>
</form>

<!-- 🌍 The installation-wide default. Shown to every administrator, saveable
     only by a global one — see the note at the top of this file.

     The fields below are `readonly`, NOT `disabled`, and that is deliberate:
     a disabled control is not submitted at all, which this project has already
     been caught by once (disabling a payment provider option would have made
     Save switch the provider silently). Readonly keeps the value in the form
     while refusing typing. There is no submit button for anybody who may not
     save, and the handler refuses them as well, so neither is load-bearing on
     its own. -->
<form method="post" action="<?php echo htmlspecialchars(Site::url('admin/settings/repeats/save'), ENT_QUOTES, 'UTF-8'); ?>" class="card">
    <div class="card-body">
        <h2 class="h5 card-title">The whole installation</h2>
        <p class="text-secondary small">
            Every organisation that has not chosen its own numbers follows these. These are also the
            MOST any organisation may set for itself: lowering a number here lowers it for an
            organisation that had chosen a higher one, without changing what that organisation has
            stored.
        </p>
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="scope" value="global">

        <?php if ($mayChangeInstallationWide === false): ?>
            <div class="alert alert-secondary">
                <i class="fa-solid fa-lock me-2"></i>
                These are the installation-wide defaults, which every organisation on this server
                follows and not only yours. Only a global administrator can change them, so they are
                shown here to read rather than to fill in.
            </div>
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-md-6">
                <label for="global-dates" class="form-label">Most dates in one series</label>
                <input type="number" class="form-control" id="global-dates" name="maxOccurrences"
                       min="1" max="<?php echo EventRecurrence::HARD_MAX_OCCURRENCES; ?>" required
                       <?php echo $mayChangeInstallationWide === false ? 'readonly' : ''; ?>
                       value="<?php echo htmlspecialchars((string) ($globalDates ?? EventRecurrence::DEFAULT_MAX_OCCURRENCES), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-6">
                <label for="global-years" class="form-label">How far ahead, in years</label>
                <input type="number" class="form-control" id="global-years" name="maxYears"
                       min="1" max="<?php echo EventRecurrence::HARD_MAX_YEARS; ?>" required
                       <?php echo $mayChangeInstallationWide === false ? 'readonly' : ''; ?>
                       value="<?php echo htmlspecialchars((string) ($globalYears ?? EventRecurrence::DEFAULT_MAX_YEARS), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
        </div>

        <?php if ($mayChangeInstallationWide === true): ?>
            <button type="submit" class="btn btn-outline-primary mt-3">
                <i class="fa-solid fa-earth-europe me-1"></i>Save for the whole installation
            </button>
        <?php endif; ?>
    </div>
</form>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
