<?php
// Path: _apps/admin/settings/transcription/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — how long YOUR organisation keeps transcripts 🎙️⏳ (#722)
 * -----------------------------------------------------------------------------
 * One field. An organisation's own administrator sets how long this
 * organisation keeps the written-out words of a recording, and may choose any
 * period up to — never beyond — what the installation allows.
 *
 * WHY THIS IS A PAGE OF ITS OWN
 * -----------------------------
 * The installation's own figure is set on Admin, Transcription, beside the
 * account key and the monthly ceiling, because one API key pays for every
 * organisation and that page writes installation-wide rows only. This page is
 * the OTHER tier, and it cannot live in the ordinary settings editor at
 * `/settings`: that editor refuses to ADD a per-organisation row for a key the
 * installation already holds, so there was no way at all for an organisation to
 * tighten this until #722. The attendance page at `/admin/settings/attendance`
 * exists for exactly the same reason and is the pattern followed here.
 *
 * WHAT A TRANSCRIPT IS, SO THE FIGURE IS NOT SET CASUALLY
 * -------------------------------------------------------
 * `tblTranscript.fullText` is the verbatim machine transcription of a
 * recording's audio: whatever was spoken, including any name, address or
 * telephone number somebody read out. An erasure request cannot reach it — no
 * column says whose words they are, and it is a record of a meeting involving
 * other people — so this time limit is the ONLY thing that removes it. The
 * recording itself is untouched; what goes is the written-out words.
 *
 * THE INSTALLATION CAPS THE ORGANISATION, AND THEY MAY BE DIFFERENT PARTIES
 * ------------------------------------------------------------------------
 * Whoever runs the installation may be a hosting provider and the
 * organisations on it their customers (the owner, 29 October 2026). So this
 * page may tighten and may never loosen, and `Portal\Core\SettingLimit` is the
 * ONE place that rule lives.
 *
 * TWO QUESTIONS, NOT ONE, and both are asked below (#668):
 *
 *   installationAllows()        the ceiling, in days, for the box's `max`
 *   installationAllowsForEver() whether 0 may be offered at all
 *
 * They are genuinely different, because 0 means "keep for ever" and is
 * therefore the LARGEST value there is, not the smallest: a box with `min="0"`
 * offers for-ever whatever its `max` says. Offering it where the installation
 * forbids it is a control whose own Save refuses it.
 *
 * AND THE BOX NEVER SHOWS A NUMBER ITS OWN `max` FORBIDS. An organisation that
 * stored a longer period while that was allowed would otherwise be shown
 * `value="1000"` against `max="365"`, and a browser then refuses to submit the
 * whole form — so the only way to use the page would be to shorten the period,
 * which is the one thing an administrator must not be forced into. That fault
 * has shipped twice in this repository, once five lines under the comment
 * citing the rule, which is why it is said again here.
 *
 * AN EMPTY BOX MEANS "FOLLOW THE INSTALLATION" and DELETES this organisation's
 * own row. That is deliberate, and it is the limitation the attendance page
 * states plainly and cannot fix: once an organisation has a figure of its own
 * there is otherwise no way back to inheriting. The handler asks whether the
 * field ARRIVED before reading it as empty (#677): a disabled control is not
 * submitted, and reading an absent field as empty is how `site.url` was cleared
 * for every organisation once.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/722
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\InstallationSettings;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\TranscriptRetention;

Auth::ensureSession();
Auth::requireLogin();

if (App::isAdmin() === false) {
    Router::renderError(403);

    return;
}

$siteId = Site::id();

// 🏢 What the installation allows — the two separate questions.
$ceilingDays   = TranscriptRetention::installationAllows();
$mayKeepForEver = TranscriptRetention::installationAllowsForEver();

// 🏢 This organisation's OWN figure, read at its own level, so the box shows
//    what this organisation chose and not what it inherits (#674, #677).
$ownDays = TranscriptRetention::ownDays($mysqli, $siteId);

// 🧹 What the nightly clear-out will actually use for this organisation. Asked
//    of the same method the job asks, so the page and the job cannot disagree.
$inForce = TranscriptRetention::daysForSite($siteId);

// 🚧 The value the box shows. NEVER above its own `max`: an organisation whose
//    stored figure is longer than the installation now allows is shown the
//    capped number, because a browser refuses to submit a form whose number
//    box holds a value above its maximum — a dead Save button.
//
//    An organisation that chose "for ever" where that is no longer allowed is
//    in the same position, and 0 would be below the box's `min` as well, so it
//    is shown the ceiling too.
$shown = '';
if ($ownDays !== null) {
    $shown = (string) ($mayKeepForEver === false && $ownDays === 0
        ? $ceilingDays
        : min($ownDays, $ceilingDays));
}

// Is the stored figure being held down right now? Said plainly, because
// otherwise the page shows a number this organisation did not choose with
// nothing to explain it.
$heldDown = $ownDays !== null && $ownDays !== $inForce;

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');

$pageTitle       = 'Transcript retention';
$pageDescription = 'How long your organisation keeps the written-out words of a recording.';
$pageSection     = 'admin';
$breadcrumbs     = [
    'Dashboard'            => Site::url(''),
    'Transcription'        => Site::url('admin/transcription'),
    'Transcript retention' => '',
];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="mb-0"><i class="fa-solid fa-closed-captioning me-2"></i>Transcript retention</h1>
    <a href="<?php echo htmlspecialchars(Site::url('admin/transcription'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fa-solid fa-arrow-left me-1"></i>Back to transcription
    </a>
</div>

<p class="text-secondary">
    A transcript is everything that was said aloud in a recording, written out word for word — so it
    can hold a name, an address or a telephone number somebody read out. Nobody can ask for a
    transcript to be removed on their own behalf, because nothing on it says whose words they are and
    it is a record of a meeting involving other people. This period is the only thing that removes
    one. The recording itself is kept; what goes is the written-out words.
</p>

<!-- 📊 What is in force right now, and where it came from. -->
<div class="card mb-4 border-primary">
    <div class="card-body">
        <h2 class="h6 mb-2"><i class="fa-solid fa-circle-info me-1"></i>In force for your organisation now</h2>
        <p class="mb-1">
            Transcripts are kept for
            <strong><?php echo $inForce === 0 ? 'ever' : (int) $inForce . ' days'; ?></strong>
            &mdash;
            <?php echo $ownDays !== null
                ? 'your organisation\'s own choice.'
                : 'the period this installation sets, because your organisation has not chosen one.'; ?>
        </p>
        <?php if ($heldDown === true): ?>
            <?php /* 🚧 The number above is NOT the number this organisation chose, and nothing
                     else on the page would say so. It never names WHO set the limit: the two
                     levels may be different parties, which is the decision
                     SettingLimit::refusalWords() already took for every refusal. */ ?>
            <p class="mb-1 small text-warning-emphasis">
                <i class="fa-solid fa-triangle-exclamation me-1"></i>
                Your organisation is set to
                <strong><?php echo $ownDays === 0 ? 'keep transcripts for ever' : 'keep them for ' . (int) $ownDays . ' days'; ?></strong>,
                which is longer than this installation allows, so the shorter period above is what is
                actually in force and what the nightly clear-out uses. Ask whoever runs this
                installation if you need longer.
            </p>
        <?php endif; ?>
        <p class="mb-0 small text-muted">
            This installation
            <?php echo InstallationSettings::known() === true ? 'allows' : 'appears to allow'; ?>
            <?php echo $mayKeepForEver === true
                ? 'any period, including keeping transcripts for ever'
                : 'at most <strong>' . (int) $ceilingDays . ' days</strong>'; ?>.
            Your organisation can choose that or less, and nothing longer.
        </p>
    </div>
</div>

<!-- ✏️ The one field. -->
<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3">Your organisation's own period</h2>
        <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/settings/transcription/save'), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">

            <div class="mb-3" style="max-width: 22rem;">
                <label class="form-label" for="transcriptDays">Keep transcripts for this many days</label>
                <?php
                // 🚧 `min` and `max` are a COURTESY to whoever is filling the
                //    form in, never the boundary: anything can be posted, so
                //    the handler asks the rule again and refuses. `min="0"`
                //    only where the installation itself allows for ever,
                //    because 0 is the LARGEST value for this setting.
                ?>
                <input type="number"
                       min="<?php echo $mayKeepForEver === true ? 0 : 1; ?>"
                       max="<?php echo (int) $ceilingDays; ?>"
                       class="form-control form-control-sm"
                       id="transcriptDays" name="transcriptDays"
                       value="<?php echo htmlspecialchars($shown, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-text">
                    Leave it empty to follow the period this installation sets. Your organisation's own
                    figure is then removed, and it will follow any later change the installation makes.
                    <?php if ($mayKeepForEver === true): ?>
                        Enter <strong>0</strong> to keep transcripts for ever.
                    <?php else: ?>
                        <br><strong>This installation
                        <?php echo InstallationSettings::known() === true ? 'allows' : 'appears to allow'; ?>
                        at most <?php echo (int) $ceilingDays; ?> days</strong>, so keeping transcripts for
                        ever is not available here. Ask whoever runs this installation if you need longer.
                    <?php endif; ?>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-sm">
                <i class="fa-solid fa-check me-1"></i>Save for my organisation
            </button>
        </form>
    </div>
</div>

<p class="small text-muted">
    Transcripts are deleted once they are past the period — the words and the copy held for searching
    both go, in one step. Nothing is kept in a bin, so this cannot be undone. Somebody who wants the
    words again can have the recording transcribed again.
</p>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
