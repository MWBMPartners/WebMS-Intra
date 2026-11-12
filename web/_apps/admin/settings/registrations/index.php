<?php
// Path: _apps/admin/settings/registrations/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — how long event registrations are kept 🧒⏳ (#816)
 * -----------------------------------------------------------------------------
 * Two cards, for two different people:
 *
 *   1. YOUR ORGANISATION'S PERIOD — any administrator of this organisation.
 *      How many days after an event its registrations (a child's name, date of
 *      birth, allergies and medical notes, a parent's telephone number and
 *      e-mail address) are kept before the nightly clear-out deletes them.
 *   2. THE INSTALLATION — a global administrator only. The period every
 *      organisation follows until it chooses its own, and the OPTIONAL
 *      ceiling on every organisation's and every event's period.
 *
 * WHY A PAGE OF ITS OWN
 * ---------------------
 * Until #816 the period was written only by the general settings editor at
 * `/settings`, which cannot judge it against a ceiling and cannot ADD an
 * organisation's own row at all (the installation holds the key). That editor
 * now refuses both keys, and this page is where they are written, through
 * `Portal\Core\RegistrationRetention`, the ONE place either is decided.
 *
 * THE CEILING IS OPT-IN (the owner, 7 November 2026 — D-074)
 * ----------------------------------------------------------
 * It ships switched off and nothing changes until a global administrator sets
 * one. A registration period can be a legal requirement, so the portal never
 * shortens one: a period above the ceiling is refused, a ceiling below a period
 * somebody already holds is refused and that somebody is named, and the
 * clear-out obeys a stored period even where it is above the ceiling — it keeps
 * the details longer rather than deleting them early (D-032's reasoning).
 *
 * THREE TRAPS THIS PAGE IS WRITTEN AGAINST, each paid for already
 * ---------------------------------------------------------------
 *   - #674/#677: the organisation's box shows ITS OWN row, read at its own
 *     level, and the installation's boxes show the INSTALLATION's rows, read
 *     live — never the merged `$SETTINGS`, which would show one level's number
 *     in a form that saves at another.
 *   - #668: a box is never prefilled above its own `max`. Here `max` is the
 *     hard maximum (one hundred years), or the stored figure if somehow larger,
 *     so a stored period is always shown as it is and an untouched Save passes;
 *     the HANDLER is the boundary, not the box (#687).
 *   - #687: an organisation's refusal never says who set the ceiling — the two
 *     levels may be different parties.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/816
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\RegistrationRetention;
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

/** One short helper, because every value on this page goes through it. */
$e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

// 🏢 THIS organisation's own period (null = it follows the installation), and
//    what the nightly clear-out will really use for it — asked of the same
//    method the job asks, so the page and the job cannot disagree.
$ownDays  = RegistrationRetention::ownDays($mysqli, $siteId);
$inForce  = RegistrationRetention::daysForSite($siteId);
// 🔛 And whether the clear-out runs for this organisation at all. Without this
//    the card below said "kept for 90 days" for an organisation whose
//    clear-out is switched off, where nothing is deleted — untrue in the
//    direction an administrator relies on (#816 round 1). Asked of the same
//    method the job asks.
$runs     = RegistrationRetention::clearOutRunsForSite($siteId);

// 🏛️ The installation's two figures, read LIVE from their own rows. Not the
//    request's snapshot: a global administrator who has just saved must see
//    what is stored, and these boxes save installation-wide (#674).
$installationDays = RegistrationRetention::installationDays($mysqli);
$ceiling          = RegistrationRetention::ceilingNow($mysqli);

// 🚧 Is the period in force longer than the ceiling? Only SQL or a restored
//    backup can produce that (both save paths refuse it), and the job then
//    keeps the details LONGER. Said plainly, because otherwise the page would
//    show a period above a ceiling with nothing to explain why it is obeyed.
//
//    #840: asked WHATEVER the clear-out switch says. This used to be
//    `$runs === true && …`, so with the clear-out switched off the page showed
//    the ceiling and, right beside it, a longer period with nothing saying why
//    the two disagreed — which an administrator could read as the ceiling being
//    in force on that period. Nothing is deleted while the switch is off, so no
//    data was at risk; the page was simply silent about a fact that is still
//    true. The switch now chooses only the WORDS (two complete sentences below,
//    one per state), never whether the warning appears.
$inForceAbove = RegistrationRetention::isAboveCeiling($inForce, $ceiling);

// The box: the organisation's own figure exactly as stored, never altered to
// fit a `max` — `max` is widened instead (see the header).
$ownShown = $ownDays === null ? '' : (string) $ownDays;
$ownMax   = max(RegistrationRetention::HARD_MAX_DAYS, $ownDays ?? 0);
$instMax  = max(RegistrationRetention::HARD_MAX_DAYS, $installationDays);

$flashMsg  = (string) ($_SESSION['flash_msg'] ?? '');
$flashType = (string) ($_SESSION['flash_type'] ?? '');
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$csrf    = $e(Auth::csrfToken());
$saveUrl = $e(Site::url('admin/settings/registrations/save'));

$pageTitle       = 'Registration retention';
$pageDescription = 'How long the details people give when they register for an event are kept after it.';
$pageSection     = 'admin';
$breadcrumbs     = ['Dashboard' => Site::url(''), 'Admin' => Site::url('admin'), 'Registration retention' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo $e($flashType !== '' ? $flashType : 'info'); ?>" role="status">
        <?php echo $e($flashMsg); ?>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <h1 class="mb-0 text-break"><i class="fa-solid fa-child-reaching me-2" aria-hidden="true"></i>Registration retention</h1>
    <a href="<?php echo $e(Site::url('admin')); ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>Back to Admin
    </a>
</div>

<p class="text-secondary">
    When somebody registers for an event they can give a child's name, date of birth, allergies and
    medical notes, and a parent's telephone number and e-mail address. Most people who register have
    no account, so a request to erase somebody's details has no account to find these by. Instead, the
    portal deletes a registration a set number of days after its event. This page sets that number.
    Once deleted, a registration is gone from the portal; only a restored backup could bring it back.
</p>

<!-- 📊 What is in force right now, and where it came from. -->
<div class="card mb-4 border-primary" data-registration-in-force="<?php echo $runs === true ? (int) $inForce : 'off'; ?>">
    <div class="card-body">
        <h2 class="h6 mb-2"><i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i>In force for your organisation now</h2>
        <?php if ($runs === false): ?>
            <?php /* 🔛 The job skips this organisation entirely, so no period below is
                     acted on. Said FIRST, because it is the fact that matters. */ ?>
            <p class="mb-1" data-registration-clear-out-off="1">
                <strong>The clear-out is switched off for your organisation, so no registrations are being
                deleted at all.</strong> They are kept until it is switched back on (the setting
                <code><?php echo $e(RegistrationRetention::KEY_RUN); ?></code>). The period below is the one
                that will apply when it is.
            </p>
        <?php endif; ?>
        <p class="mb-1">
            <?php echo $runs === true ? 'Registrations are' : 'When the clear-out runs, registrations will be'; ?>
            <strong><?php echo $inForce === 0 ? 'kept indefinitely' : 'kept for ' . $e(RegistrationRetention::daysInWords($inForce)) . ' after the event'; ?></strong>
            &mdash;
            <?php echo $ownDays !== null
                ? 'your organisation\'s own choice.'
                : 'the period this installation sets, because your organisation has not chosen one.'; ?>
            An event can have a period of its own, which wins over this one.
        </p>
        <?php if ($ceiling !== RegistrationRetention::NO_CAP): ?>
            <p class="mb-1 small text-muted">
                A ceiling of <strong><?php echo $e(RegistrationRetention::daysInWords($ceiling)); ?></strong> is in force
                on this installation, so a period longer than that cannot be chosen, and neither can
                keeping registrations indefinitely.
            </p>
        <?php endif; ?>
        <?php if ($inForceAbove === true): ?>
            <?php /* 🚧 Reached only by a row written in SQL or restored from a backup. It
                     never names WHO set the ceiling: the two levels may be different parties. */ ?>
            <p class="mb-0 small text-warning-emphasis" data-registration-above-ceiling="1"
               data-registration-above-ceiling-clear-out="<?php echo $runs === true ? 'on' : 'off'; ?>">
                <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                <?php if ($runs === true): ?>
                    The period in force is longer than the ceiling. It was not set on this page, which would
                    have refused it. The ceiling does not shorten it: the portal keeps registrations for the
                    longer period rather than deleting them early, because a period like this can be a legal
                    requirement and a deletion cannot be undone. Choose a period within the ceiling below if
                    your organisation does not need the longer one.
                <?php else: ?>
                    The period that will apply is longer than the ceiling. It was not set on this page, which
                    would have refused it. The ceiling does not shorten it: when the clear-out is switched back
                    on, the portal will keep registrations for the longer period rather than deleting them
                    early, because a period like this can be a legal requirement and a deletion cannot be
                    undone. Choose a period within the ceiling below if your organisation does not need the
                    longer one.
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
</div>

<!-- ✏️ The organisation's own period. -->
<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3">Your organisation's own period</h2>
        <form method="post" action="<?php echo $saveUrl; ?>">
            <?php // $csrf is ALREADY escaped where it is set; escaping it again would mangle it. ?>
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="action" value="organisation">
            <div class="mb-3" style="max-width: 22rem;">
                <label class="form-label" for="registrationDays">Keep registrations for this many days after the event</label>
                <input type="number" min="0" max="<?php echo (int) $ownMax; ?>"
                       class="form-control form-control-sm" id="registrationDays" name="registrationDays"
                       value="<?php echo $e($ownShown); ?>" aria-describedby="registrationDaysHelp">
                <div class="form-text" id="registrationDaysHelp">
                    Leave it empty to follow the period this installation sets
                    (<?php echo $e(RegistrationRetention::daysInWords($installationDays)); ?> at present).
                    Your organisation's own figure is then removed, and it will follow any later change.
                    <?php if ($ceiling === RegistrationRetention::NO_CAP): ?>
                        Enter <strong>0</strong> to keep registrations indefinitely.
                    <?php else: ?>
                        <br><strong>At most <?php echo (int) $ceiling; ?> days can be chosen here</strong>,
                        and keeping registrations indefinitely is not available. Ask whoever runs this
                        installation if you need longer.
                    <?php endif; ?>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="fa-solid fa-check me-1" aria-hidden="true"></i>Save for my organisation
            </button>
        </form>
    </div>
</div>

<?php // 🏛️ THE INSTALLATION. Its own form, for a global administrator only,
      //    because both rows bind every organisation at once. ?>
<?php if ($isGlobalAdmin === true): ?>
<div class="card mb-4 border-primary" data-registration-installation-card="1">
    <div class="card-body">
        <h2 class="h5 card-title">For every organisation on this installation</h2>
        <p>
            You are seeing this because you are a global administrator of this installation.
        </p>
        <p class="mb-3">
            <strong>The ceiling starts switched off, and it is yours to leave off.</strong> It is the longest
            period any organisation, or any single event, may keep registrations for. A registration period
            can be a legal requirement, so the portal never shortens one: if an organisation or an event
            already keeps registrations for longer than the ceiling you ask for, the change is refused and
            they are named, so you can talk to them first. Removing the ceiling (leaving the box empty) is
            always allowed.
        </p>
        <form method="post" action="<?php echo $saveUrl; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="action" value="installation">
            <div class="mb-3" style="max-width: 26rem;">
                <label class="form-label" for="installationDays">Period for organisations that have not chosen one (days)</label>
                <input type="number" min="0" max="<?php echo (int) $instMax; ?>"
                       class="form-control form-control-sm" id="installationDays" name="installationDays"
                       value="<?php echo (int) $installationDays; ?>" aria-describedby="installationDaysHelp" required>
                <div class="form-text" id="installationDaysHelp">
                    <strong>0</strong> keeps registrations indefinitely, which is allowed only with no ceiling.
                </div>
            </div>
            <div class="mb-3" style="max-width: 26rem;">
                <label class="form-label" for="maxDays">Ceiling (days)</label>
                <input type="number" min="0" max="<?php echo RegistrationRetention::HARD_MAX_DAYS; ?>"
                       class="form-control form-control-sm" id="maxDays" name="maxDays"
                       value="<?php echo $ceiling === RegistrationRetention::NO_CAP ? '' : (int) $ceiling; ?>"
                       aria-describedby="maxDaysHelp">
                <div class="form-text" id="maxDaysHelp">
                    Leave it empty, or enter 0, for no ceiling, which is how it starts.
                    <?php if ($ceiling === RegistrationRetention::NO_CAP): ?>
                        There is no ceiling at present.
                    <?php endif; ?>
                </div>
            </div>
            <button type="submit" class="btn btn-outline-primary btn-sm">Save for every organisation</button>
        </form>
    </div>
</div>
<?php endif; ?>

<p class="small text-muted">
    The clear-out runs as part of the nightly retention job, and the count of registrations it would
    delete now is shown at
    <a href="<?php echo $e(Site::url('admin/maintenance/retention')); ?>">Admin, Retention</a>.
</p>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
