<?php
// Path: _apps/admin/settings/giving-records/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Giving records and the right to be forgotten ⚙️🧾 (#574)
 * -----------------------------------------------------------------------------
 * One organisation's rule for what happens to its giving records when a donor
 * asks to be erased: which gifts keep the donor's name, for how many years,
 * counted from which financial year end, and whether a hold is on. Roll-out
 * package A2b, on Salem874's decisions of 11 October 2026.
 *
 * THE ONE THING THIS PAGE MUST NEVER DO
 * -------------------------------------
 * Read as if the portal knows the law. Every number of years it shows is
 * either the organisation's own figure or a SUGGESTION from research of mixed
 * strength. The page shows how strong the source is and links to it, says
 * plainly when it has no figure at all, and asks an administrator to confirm
 * the figure with their own adviser. Until somebody ticks "confirmed",
 * nothing is ever cleared.
 *
 * WHO MAY USE IT
 * --------------
 * An administrator of the organisation, for that organisation only. Not the
 * treasurer role: this decides what happens to personal records, not how the
 * accounts are kept. There is no installation-wide form here on purpose:
 * each organisation has its own country and its own adviser, and one
 * organisation's rule must never become another's by default. (The seven
 * settings do have installation-wide rows, seeded by migration 209 with the
 * cautious starting values. An organisation does NOT inherit them: its rule
 * is read from its own rows only, so that nobody can "confirm" a figure for
 * every organisation at once. The one exception is the hold, where an
 * installation-wide hold holds everybody. See GivingRetention::ruleForSite().)
 *
 * WHY THE COUNTRY IS ASKED FOR HERE
 * ---------------------------------
 * The portal's organisation address is one value for the whole installation
 * and its country is "GB" unless changed. Reading that would offer every
 * organisation the United Kingdom's figure as if it were theirs. So each
 * organisation says here which country's rules it follows, and until it does
 * it gets a cautious placeholder, clearly flagged.
 *
 * WHAT THIS PAGE CANNOT DO
 * ------------------------
 *   - It does not show the suggestion for a country until that country has
 *     been saved. The figure, its sources and its warnings are worked out on
 *     the server from the saved setting, so there is one answer and no copy
 *     of the data file in the page.
 *   - It clears nothing. The retention job does that, and only for a record
 *     whose keep-until date has passed, with no hold on and a confirmed
 *     figure (Portal\Core\GivingRetention::stateFor()).
 *   - It lists only donors whose names are still kept, not ones already
 *     cleared.
 *
 * All the rules are in Portal\Core\GivingRetention; this file only shows them.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/574
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\GivingRetention;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$siteId     = Site::id();
$rule       = GivingRetention::ruleForSite($siteId);
$suggestion = $rule['suggestion'];
$countries  = GivingRetention::countryChoices();
$kept       = GivingRetention::keptDonorsForSite($mysqli, $siteId, $rule);

/** One short helper, because every value on this page goes through it. */
$e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

$years       = (int) $rule['years'];
$yearsWords  = $years . ' ' . ($years === 1 ? 'year' : 'years');
$countryName = (string) $suggestion['countryName'];
$yearEndDay  = (string) $rule['yearEnd'] === '' ? 0 : (int) substr((string) $rule['yearEnd'], 3, 2);
$yearEndMon  = (string) $rule['yearEnd'] === '' ? 0 : (int) substr((string) $rule['yearEnd'], 0, 2);

// How many kept records are in each state, for the summary and the tick box.
$states = ['due' => 0, 'on-hold' => 0, 'not-confirmed' => 0, 'not-due' => 0, 'unreadable' => 0, 'not-erased' => 0];
foreach ($kept as $row) {
    $states[(string) $row['state']]++;
}

// What each state means, in the words shown beside a kept record.
$stateWords = [
    'due'           => 'Past its date: cleared at the next retention run',
    'on-hold'       => 'Held: a hold is switched on',
    'not-confirmed' => 'Waiting: the number of years is not confirmed',
    'not-due'       => 'Kept',
    'unreadable'    => 'The date could not be worked out, so it is kept',
    // A kept row is written near the start of an erasure and the account is
    // emptied about half way through it. This is a row whose erasure stopped
    // in between: the person is still a member.
    'not-erased'    => 'The account has not been emptied yet: nothing is cleared until it has been',
];

// The nightly job only runs once a global administrator has given it a token.
$jobIsOff = trim((string) (App::settings('maintenance.cronToken') ?? '')) === '';

$flashMsg  = (string) ($_SESSION['flash_msg'] ?? '');
$flashType = (string) ($_SESSION['flash_type'] ?? '');
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$csrf    = $e(Auth::csrfToken());
$saveUrl = $e(Site::url('admin/settings/giving-records/save'));

$pageTitle   = 'Giving records and the right to be forgotten';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Admin' => Site::url('admin'), 'Giving records' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo $e($flashType !== '' ? $flashType : 'info'); ?>" role="status">
        <?php echo $e($flashMsg); ?>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <h1 class="mb-0 text-break"><i class="fa-solid fa-receipt me-2" aria-hidden="true"></i>Giving records and the right to be forgotten</h1>
    <a href="<?php echo $e(Site::url('admin')); ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>Back to Admin
    </a>
</div>

<p class="text-secondary">
    When somebody asks us to erase their details, we erase them. One thing is different: tax and
    accounting rules make most organisations keep some giving records, with the donor's name, for a
    number of years. This page sets what your organisation keeps and for how long. When the time is
    up, the portal removes the donor's name and address from those records by itself. The amounts,
    dates and methods stay in your accounts.
</p>

<!-- 📊 What is in force right now. -->
<div class="card mb-4 border-primary">
    <div class="card-body">
        <h2 class="h6 mb-2"><i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i>In force for your organisation now</h2>
        <p class="mb-1">
            Gifts that keep the donor's name:
            <strong><?php echo $e(GivingRetention::SCOPES[(string) $rule['scope']]); ?></strong>.
        </p>
        <p class="mb-1 text-break">
            Kept for <strong><?php echo $e($yearsWords); ?></strong> after
            <?php if ((string) $rule['yearEnd'] !== ''): ?>
                the end of the financial year of the donor's last such gift (your year ends on
                <strong><?php echo $e(GivingRetention::yearEndInWords((string) $rule['yearEnd'])); ?></strong>).
            <?php else: ?>
                a date one year after the donor's last such gift, because your financial year end is not set.
            <?php endif; ?>
        </p>
        <p class="mb-1 text-break"><?php echo $e(GivingRetention::figureSentence($rule)); ?></p>
        <?php if ($rule['holdOwn'] === true): ?>
            <p class="mb-0"><strong>A hold is switched on for your organisation.</strong> Nothing is cleared until it is switched off.</p>
        <?php endif; ?>
        <?php if ($rule['holdEverybody'] === true): ?>
            <p class="mb-0"><strong>A hold is switched on for the whole installation.</strong> Nothing is cleared for any organisation until a global administrator switches it off.</p>
        <?php endif; ?>
    </div>
</div>

<?php if ($rule['confirmed'] !== true): ?>
    <div class="alert alert-warning" role="status">
        <strong>This figure has not been confirmed.</strong> Erasures still go ahead and their reports
        say so. Records are kept until you confirm, and nothing is cleared before then.
    </div>
<?php endif; ?>

<?php if ($jobIsOff === true): ?>
    <div class="alert alert-info" role="status">
        The nightly job is not switched on, so nothing is cleared until a global administrator runs it
        from <a href="<?php echo $e(Site::url('admin/maintenance/retention')); ?>">Admin, Maintenance, Retention</a>.
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3">Your organisation's rule</h2>

        <form method="post" action="<?php echo $saveUrl; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">

            <!-- 🌍 Country. -->
            <div class="mb-4">
                <label class="form-label fw-semibold" for="countryCode">Which country's rules does your organisation follow?</label>
                <select class="form-select" id="countryCode" name="countryCode" aria-describedby="countryHelp">
                    <option value="" <?php echo (string) $rule['countryCode'] === '' ? 'selected' : ''; ?>>Not set</option>
                    <?php foreach ($countries as $code => $name): ?>
                        <option value="<?php echo $e($code); ?>" <?php echo (string) $rule['countryCode'] === $code ? 'selected' : ''; ?>>
                            <?php echo $e($name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text" id="countryHelp">
                    The portal uses this only to suggest a number of years. If your country is not in the
                    list, leave it as "Not set": the portal then suggests a cautious 10 years.
                    Save to see the suggestion for a different country.
                </div>
            </div>

            <!-- 🎁 Which gifts. -->
            <fieldset class="mb-4">
                <legend class="h6">Which gifts keep the donor's name</legend>
                <?php foreach (GivingRetention::SCOPES as $value => $label): ?>
                    <div class="form-check mb-1">
                        <input class="form-check-input" type="radio" name="scope" id="scope_<?php echo $e($value); ?>"
                               value="<?php echo $e($value); ?>" <?php echo (string) $rule['scope'] === $value ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="scope_<?php echo $e($value); ?>">
                            <?php echo $e($label); ?><?php echo $value === GivingRetention::SCOPE_RECEIPTED ? ' (recommended)' : ''; ?>
                        </label>
                    </div>
                <?php endforeach; ?>
                <p class="small text-muted mb-0 mt-1">
                    Other gifts keep their amount, date and method, and lose the donor's name straight away.
                </p>
            </fieldset>

            <!-- ⏳ How long. -->
            <div class="mb-4">
                <label class="form-label fw-semibold" for="years">How long</label>
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <span>Keep for</span>
                    <input type="number" class="form-control" style="max-width: 6rem;" id="years" name="years"
                           min="<?php echo GivingRetention::MIN_YEARS; ?>" max="<?php echo GivingRetention::MAX_YEARS; ?>"
                           value="<?php echo $rule['yearsOwn'] === null ? '' : (int) $rule['yearsOwn']; ?>"
                           placeholder="<?php echo (int) $suggestion['years']; ?>" aria-describedby="yearsHelp">
                    <span>years after the end of the financial year of the donor's last gift.</span>
                </div>
                <div class="form-text" id="yearsHelp">
                    Leave it empty to follow the portal's suggestion, which is <?php echo (int) $suggestion['years']; ?> today.
                </div>

                <!-- 💡 The suggestion, how strong it is, and where it comes from.
                     Every sentence here says "suggestion" or "placeholder";
                     none says what the law requires. -->
                <div class="border rounded p-3 mt-3 bg-body-tertiary text-break">
                    <?php if ($suggestion['kind'] === 'found'): ?>
                        <p class="mb-1">
                            <em>Our suggestion for <?php echo $e($countryName); ?> is <?php echo (int) $suggestion['years']; ?> years.</em>
                            How sure we are: <?php echo $e(GivingRetention::STRENGTH_WORDS[(string) $suggestion['strength']]); ?>.
                        </p>
                    <?php elseif ($suggestion['kind'] === 'raised'): ?>
                        <p class="mb-1">
                            The figure we found for <?php echo $e($countryName); ?> is <?php echo (int) $suggestion['yearsFound']; ?>
                            <?php echo (int) $suggestion['yearsFound'] === 1 ? 'year' : 'years'; ?>
                            (how sure we are: <?php echo $e(GivingRetention::STRENGTH_WORDS[(string) $suggestion['strength']]); ?>).
                            That is probably too short for donor records, so this portal never suggests less than
                            <?php echo (int) $suggestion['minimum']; ?>. You may set a lower figure if your adviser confirms it.
                        </p>
                    <?php elseif ($suggestion['why'] === 'country-not-set'): ?>
                        <p class="mb-1">
                            <strong>Your organisation has not said which country it is in, so we do not know which
                            country's rules apply.</strong> <?php echo (int) $suggestion['years']; ?> years is a cautious
                            placeholder, not a finding. Choose your country above and save.
                        </p>
                    <?php elseif ($suggestion['why'] === 'file-unreadable'): ?>
                        <p class="mb-1">
                            <strong>The portal's list of country figures could not be read</strong>, so it has no
                            suggestion to offer. <?php echo (int) $suggestion['years']; ?> years is a cautious placeholder,
                            not a finding. Please tell whoever looks after this installation.
                        </p>
                    <?php else: ?>
                        <p class="mb-1">
                            <strong>We have no figure for <?php echo $e($countryName); ?>.</strong>
                            <?php echo (int) $suggestion['years']; ?> years is a cautious placeholder, not a finding. Ask a
                            local adviser, and lower it if your law asks for less.
                        </p>
                    <?php endif; ?>

                    <?php if ((string) $suggestion['note'] !== ''): ?>
                        <p class="small mb-1">What the research noted: <?php echo $e((string) $suggestion['note']); ?></p>
                    <?php endif; ?>

                    <?php if ($suggestion['sources'] !== []): ?>
                        <p class="small mb-1">What was read for <?php echo $e($countryName); ?> (the first is the nearest to the figure; some are about the data-protection law):</p>
                        <ul class="small mb-2">
                            <?php foreach ($suggestion['sources'] as $source): ?>
                                <li>
                                    <a href="<?php echo $e((string) $source['url']); ?>" rel="noopener noreferrer external" target="_blank">
                                        <?php echo $e((string) $source['title']); ?><span class="visually-hidden"> (opens in a new tab)</span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ($suggestion['kind'] !== 'placeholder' && $suggestion['needsAdvice'] === true): ?>
                        <p class="mb-1">
                            <strong>Our sources for <?php echo $e($countryName); ?> disagree, or are weak.</strong>
                            Treat the figure as a starting point.
                        </p>
                    <?php endif; ?>

                    <?php if ($countryName !== '' && $suggestion['why'] !== 'file-unreadable' && $suggestion['why'] !== 'country-not-in-file'): ?>
                        <?php if (in_array($suggestion['erasureException'], ['weak', 'not-found'], true) === true): ?>
                            <p class="mb-1">
                                <strong>In <?php echo $e($countryName); ?> we could not confirm that the law lets an organisation
                                keep these records after somebody has asked to be erased.</strong> The wording is weak or
                                unclear. Please take local legal advice before relying on this page. Until then the portal
                                keeps the records for the period above.
                            </p>
                        <?php elseif ($suggestion['erasureException'] === 'none'): ?>
                            <p class="mb-1">
                                We found no general right to be erased in <?php echo $e($countryName); ?>'s law. The portal
                                still honours a request, and keeps these records for the period above.
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>

                    <p class="mb-0">
                        This is our reading of the rules, not legal advice. Please check it with your accountant or adviser.
                    </p>
                </div>

                <div class="form-check mt-3">
                    <input class="form-check-input" type="checkbox" id="confirmed" name="confirmed" value="1"
                           <?php echo $rule['confirmed'] === true ? 'checked' : ''; ?> aria-describedby="confirmedHelp">
                    <label class="form-check-label" for="confirmed">We have confirmed this figure with our adviser.</label>
                    <div class="form-text" id="confirmedHelp">
                        <?php if ($rule['confirmed'] === true && (string) $rule['confirmedOn'] !== ''): ?>
                            Confirmed on <?php echo $e(GivingRetention::dateInWords((string) $rule['confirmedOn'])); ?>.
                        <?php endif; ?>
                        Ticking this fixes the figure for your organisation, so a later change to the portal's
                        suggestion does not move it. Nothing is cleared until it is ticked. If you change the
                        figure or the country afterwards, you will be asked to tick it again.
                    </div>
                </div>
            </div>

            <!-- 📅 Financial year end. -->
            <fieldset class="mb-4">
                <legend class="h6">Your financial year ends on</legend>
                <div class="d-flex flex-wrap gap-2">
                    <div>
                        <label class="form-label small mb-1" for="yearEndDay">Day</label>
                        <select class="form-select" id="yearEndDay" name="yearEndDay">
                            <option value="" <?php echo $yearEndDay === 0 ? 'selected' : ''; ?>>Not set</option>
                            <?php for ($d = 1; $d <= 31; $d++): ?>
                                <option value="<?php echo $d; ?>" <?php echo $yearEndDay === $d ? 'selected' : ''; ?>><?php echo $d; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label small mb-1" for="yearEndMonth">Month</label>
                        <select class="form-select" id="yearEndMonth" name="yearEndMonth">
                            <option value="" <?php echo $yearEndMon === 0 ? 'selected' : ''; ?>>Not set</option>
                            <?php foreach (GivingRetention::monthNames() as $number => $monthName): ?>
                                <option value="<?php echo (int) $number; ?>" <?php echo $yearEndMon === (int) $number ? 'selected' : ''; ?>><?php echo $e($monthName); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <p class="small text-muted mb-0 mt-2">
                    <?php if ((string) $rule['yearEnd'] === ''): ?>
                        Until you tell us when your financial year ends, we count from one year after the last
                        gift, the latest your year could end. That may keep a record up to a year longer than needed.
                    <?php else: ?>
                        The years are counted from the first such day on or after the donor's last kept gift.
                    <?php endif; ?>
                </p>
            </fieldset>

            <!-- ✋ Hold. The box is THIS organisation's own hold and nothing
                 else. It first showed a hold set for the whole installation
                 as if it were the organisation's own tick, so saving the page
                 for any other reason switched the organisation's own hold on
                 without being asked. The installation's hold is shown beside
                 it, as words, and this form cannot change it. -->
            <div class="mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="hold" name="hold" value="1"
                           <?php echo $rule['holdOwn'] === true ? 'checked' : ''; ?> aria-describedby="holdHelp">
                    <label class="form-check-label" for="hold">
                        <strong>Hold.</strong> An audit or tax enquiry is open. Clear nothing until this is switched off.
                    </label>
                    <div class="form-text" id="holdHelp">
                        <?php if ($rule['holdEverybody'] === true): ?>
                            A global administrator has also switched a hold on for the whole installation.
                            That one holds your organisation's records whatever this box says, and it cannot
                            be changed here.
                        <?php else: ?>
                            This is your organisation's own hold.
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- 🛑 Only needed when a change would make kept records due at once. -->
            <div class="mb-4 border rounded p-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="understood" name="understood" value="1" aria-describedby="understoodHelp">
                    <label class="form-check-label" for="understood">I understand that kept donor records will be cleared.</label>
                </div>
                <div class="form-text" id="understoodHelp">
                    Leave this alone unless you are told to tick it. If a change you make here would put a kept
                    record past its keep-until date, the portal refuses to save and tells you how many records
                    would be cleared. Tick this only if that is what you intend. Clearing cannot be undone.
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-check me-1" aria-hidden="true"></i>Save for my organisation
            </button>
        </form>
    </div>
</div>

<!-- 📋 The donors whose names are still kept. -->
<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5 mb-2">Kept donor records</h2>
        <?php if ($kept === []): ?>
            <p class="text-muted mb-0">
                No erased donor's records are being kept. When a donor with gifts that keep their name asks
                to be erased, they are listed here until the keep-until date.
            </p>
        <?php else: ?>
            <p class="small text-muted">
                <?php echo count($kept); ?> erased <?php echo count($kept) === 1 ? 'donor' : 'donors'; ?>.
                Past their date: <?php echo (int) $states['due']; ?>.
                Held: <?php echo (int) $states['on-hold']; ?>.
                Waiting for a confirmed figure: <?php echo (int) $states['not-confirmed']; ?>.
                <?php if ((int) $states['not-erased'] > 0): ?>
                    Account not emptied yet: <?php echo (int) $states['not-erased']; ?> (run that erasure request again at
                    <a href="<?php echo $e(Site::url('admin/erasure-requests')); ?>">Admin, Erasure requests</a>).
                <?php endif; ?>
                The dates below follow the rule in force now, and move if you change it.
            </p>
            <!-- The five columns add up to ELEVEN twelfths, not twelve, on
                 purpose. A list row puts a gap between its cells, and five
                 cells that fill the whole width plus four gaps do not fit:
                 measured in a browser at 768, 1024 and 1440 pixels, the
                 heading row ran 48 pixels past its edge and the last cell of
                 each record dropped onto a line of its own. On a phone every
                 cell takes a full line and carries its own label. -->
            <div class="portal-data-list">
                <div class="portal-data-row portal-data-header d-none d-md-flex">
                    <div class="col-md-3">Donor</div>
                    <div class="col-md-2">Last kept gift</div>
                    <div class="col-md-2">Kept until</div>
                    <div class="col-md-1 text-center">Gifts</div>
                    <div class="col-md-3">Now</div>
                </div>
                <?php foreach ($kept as $row): ?>
                    <div class="portal-data-row d-flex flex-wrap flex-md-nowrap">
                        <div class="col-12 col-md-3 text-break">
                            <span class="d-md-none text-muted small">Donor: </span>
                            <?php echo $row['fullName'] !== null && $row['fullName'] !== ''
                                ? $e((string) $row['fullName'])
                                : '<em>No name was recorded: the account had already been emptied</em>'; ?>
                        </div>
                        <div class="col-12 col-md-2">
                            <span class="d-md-none text-muted small">Last kept gift: </span>
                            <?php // The date the keep-until date is counted from: the date on the kept row, or a
                                  // newer gift recorded for the same donor since (stateOfKeptRow()).
                                  echo $e(GivingRetention::dateInWords((string) $row['countedFromGift'])); ?>
                        </div>
                        <div class="col-12 col-md-2">
                            <span class="d-md-none text-muted small">Kept until: </span>
                            <?php echo (string) $row['keepUntil'] !== '' ? $e(GivingRetention::dateInWords((string) $row['keepUntil'])) : '&mdash;'; ?>
                        </div>
                        <div class="col-12 col-md-1 text-md-center">
                            <span class="d-md-none text-muted small">Gifts: </span><?php echo (int) $row['keptGiftCount']; ?>
                        </div>
                        <div class="col-12 col-md-3 text-break">
                            <span class="d-md-none text-muted small">Now: </span><?php echo $e($stateWords[(string) $row['state']] ?? (string) $row['state']); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ℹ️ What this setting does not do. -->
<div class="card border-0 bg-body-tertiary">
    <div class="card-body">
        <h2 class="h6 mb-2"><i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>Three things worth knowing</h2>
        <p class="small mb-2">
            <strong>Nothing is deleted when the time is up.</strong> The gift, the tax declaration and the
            statement record all stay, with their amounts and dates. What goes is the donor's name, their
            home address, the link to them, and the copies of statements kept with those records.
        </p>
        <p class="small mb-2">
            <strong>Clearing cannot be undone.</strong> Once a name has been removed, the portal cannot put
            it back. That is why nothing is cleared before you have confirmed the number of years, and why
            the hold switch exists.
        </p>
        <p class="small mb-0">
            <strong>A donor who was erased before this page existed has no name kept anywhere.</strong> The
            portal cannot bring one back.
        </p>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
