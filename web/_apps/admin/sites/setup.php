<?php
// Path: _apps/admin/sites/setup.php
/**
 * -----------------------------------------------------------------------------
 * Admin — How organisations are told apart 🌐⚙️
 * -----------------------------------------------------------------------------
 * The set-up page for an installation that serves several organisations
 * (#502). A global administrator chooses here whether multi-organisation is
 * on, and which ONE of the three ways tells the organisations apart. (The
 * owner, Salem874, decided on 12 October 2026: one way at a time, changeable
 * later, never mixed.)
 *
 * Before this page existed the two settings behind it could only be changed
 * in the general settings editor or straight in the database, with nothing
 * to stop a choice that left an organisation unreachable.
 *
 * WHAT IT SHOWS FOR EACH WAY
 *   - what it is, in a sentence;
 *   - what it CANNOT do, said plainly (a separate sign-in per address; a
 *     signed-out visitor always seeing the default organisation);
 *   - why it cannot be chosen right now, when that is so. Those sentences
 *     come from Site::modeProblems(), the same list the save handler refuses
 *     on, so this page never offers what the handler would turn down.
 *
 * "First part of the address" is marked NOT READY and cannot be chosen until
 * Site::FIRST_PART_MODE_READY is switched on by the link-sweep package.
 * (The owner, Salem874, decided on 12 October 2026 that this way stays
 * marked "not ready", and refused, until the portal's links are corrected.)
 *
 * ITS STATES
 *   - The current set-up cannot be read: an error message that says to try
 *     again, and no form (a form drawn on a guess could save the guess).
 *   - Only one organisation exists: a note saying where to add another.
 *     Switching multi-organisation on is still allowed.
 *   - A message from the save handler (saved, or refused and why).
 *
 * WHAT IT CANNOT DO
 *   - Point an address at this portal. In "own address each" every address
 *     has to be pointed at the portal's web folder in the hosting panel.
 *   - Carry anybody's sign-in from one way to another. People who are
 *     signed in when the way changes may have to sign in or choose again.
 *
 * A page inherits only $mysqli and $SETTINGS (see .claude/rules/app-pages.md).
 *
 * @package   Portal\App\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/502
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

// 🛡️ Global administrators only: this changes every organisation at once.
if (App::isUmbrellaAdmin() === false) {
    http_response_code(403);
    echo t('error.umbrella_admin_only');
    exit();
}

$db  = App::db();
$esc = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

// 📋 The set-up as stored. A failure is its own state, shown below.
$stored    = null;
$readError = false;
try {
    $stored = Site::storedSetup($db);
} catch (\Throwable $e) {
    error_log('[WebMS-Intra] admin/sites/setup: the set-up could not be read: ' . $e->getMessage());
    $readError = true;
}

// 🔢 How many organisations are switched on (Site::allActive() answers [] on a failed query).
$activeCount = count(Site::allActive($db));

// 🚦 Each way: what it is, what it cannot do, and why it cannot be chosen now.
$ways = [
    Site::MODE_SESSION => [
        'what'   => 'People sign in at one address. Somebody who belongs to several organisations chooses one after '
            . 'signing in, and can switch from the menu. This is the recommended way.',
        'cannot' => 'It cannot tell organisations apart for somebody who is not signed in. A signed-out visitor always sees '
            . 'the default organisation\'s sign-in page and its public pages (public calendar, visitor form, photo albums '
            . 'and the like). Pages opened by a secret link, such as a form or a calendar feed, are not affected.',
    ],
    Site::MODE_HOST => [
        'what'   => 'Each organisation has a web address of its own, such as north.example.org (an example). The address '
            . 'decides the organisation, signed in or not.',
        'cannot' => 'It cannot share a sign-in between addresses: a browser keeps each address\'s sign-in and passkeys '
            . 'separate, so a person in two organisations signs in at each. And every address must be pointed at this '
            . 'portal in your hosting panel; the portal cannot do that for you.',
    ],
    Site::MODE_PATH => [
        'what'   => 'One address for everybody, with the organisation\'s key as its first part, such as /north/calendar. '
            . 'The address decides the organisation, signed in or not, and one sign-in covers them all.',
        'cannot' => 'It cannot be used with an organisation whose key is an address the portal itself uses, such as '
            . '"login" or "admin".',
    ],
];
$problems = [];
foreach (array_keys($ways) as $way) {
    $problems[$way] = Site::modeProblems($way, $db);
}

// 📋 A message from the save handler.
$flashMsg  = (string) ($_SESSION['flash_msg'] ?? '');
$flashType = (string) ($_SESSION['flash_type'] ?? 'info');
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

// 🎨 Page setup
$pageTitle   = 'How organisations are told apart';
$pageSection = 'admin';
$breadcrumbs = [
    'Admin'                            => Site::url('admin'),
    'Organisations'                    => Site::url('admin/sites'),
    'How organisations are told apart' => '',
];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

// What is ticked when the page opens: "off", or the stored way.
$current = ($stored !== null && $stored['enabled'] === true) ? $stored['mode'] : 'off';
?>

<h1 class="h3 mb-3">
    <i class="fa-solid fa-route me-2"></i> How organisations are told apart
</h1>

<?php if ($flashMsg !== ''): ?>
<div class="alert alert-<?php echo $esc($flashType); ?>" role="alert" style="white-space: pre-line;"><?php echo $esc($flashMsg); ?></div>
<?php endif; ?>

<?php if ($readError === true): ?>
<div class="alert alert-danger" role="alert">
    <strong>The current set-up could not be read.</strong>
    Nothing has been changed. Wait a moment and
    <a href="<?php echo $esc(Site::url('admin/sites/setup')); ?>">open this page again</a>.
    If it keeps happening, look at the error log.
</div>
<?php else: ?>

<p class="text-break">
    One installation can serve several organisations, each with its own members, settings and records.
    The portal needs ONE way of telling which organisation a request belongs to. Choose it here.
    You can change it later, but only one way is in use at a time.
</p>

<?php if ($activeCount < 2): ?>
<div class="alert alert-info" role="note">
    <i class="fa-solid fa-circle-info me-1"></i>
    There is only one organisation at the moment, so there is nothing to tell apart yet.
    Add another on the <a href="<?php echo $esc(Site::url('admin/sites')); ?>">Organisations page</a> first.
</div>
<?php endif; ?>

<div class="alert alert-warning" role="note">
    <i class="fa-solid fa-triangle-exclamation me-1"></i>
    Changing this changes which organisation every address opens, for everybody, straight away.
    People who are signed in may have to sign in again or choose their organisation again. Pick a quiet moment.
</div>

<form method="post" action="<?php echo $esc(Site::url('admin/sites/setup/save')); ?>">
    <input type="hidden" name="csrf_token" value="<?php echo $esc(Auth::csrfToken()); ?>">

    <fieldset class="mb-3">
        <legend class="h5">Choose one</legend>

        <div class="card mb-3">
            <div class="card-body">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="mode" id="modeOff" value="off"<?php echo $current === 'off' ? ' checked' : ''; ?>>
                    <label class="form-check-label fw-semibold" for="modeOff">One organisation only</label>
                </div>
                <?php
                // The last two sentences were added after the independent check
                // of 12 October 2026: the card did not say what happens to the
                // PEOPLE of the other organisations, and that is not something
                // an administrator would guess. With this chosen, start-up puts
                // every request in organisation 1 (Site::init()), members of
                // the other organisations included.
                ?>
                <p class="small mb-0 mt-2 text-break">
                    Multi-organisation is switched off. Every address opens the default organisation, whatever other
                    organisations exist. This is how a new installation starts.
                    If other organisations exist, their members then work in the default organisation too, from the
                    moment this is saved. The other organisations' records are kept, and their members see them again
                    when one of the ways below is chosen.
                </p>
            </div>
        </div>

        <?php foreach ($ways as $way => $text): ?>
        <?php
        $blocked  = ($problems[$way] !== []);
        $notReady = ($way === Site::MODE_PATH && Site::FIRST_PART_MODE_READY === false);
        $inputId  = 'mode' . ucfirst($way);
        ?>
        <div class="card mb-3">
            <div class="card-body">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="mode" id="<?php echo $esc($inputId); ?>"
                           value="<?php echo $esc($way); ?>"<?php echo $current === $way ? ' checked' : ''; ?><?php echo ($blocked === true && $current !== $way) ? ' disabled' : ''; ?>>
                    <label class="form-check-label fw-semibold" for="<?php echo $esc($inputId); ?>">
                        <?php echo $esc(Site::MODE_LABELS[$way]); ?>
                    </label>
                    <?php if ($current === $way): ?>
                    <span class="badge bg-success ms-1">In use now</span>
                    <?php endif; ?>
                    <?php if ($notReady === true): ?>
                    <span class="badge bg-warning text-dark ms-1">Not ready</span>
                    <?php endif; ?>
                </div>
                <p class="small mt-2 mb-2 text-break"><?php echo $esc($text['what']); ?></p>
                <p class="small mb-0 text-break"><strong>What it cannot do.</strong> <?php echo $esc($text['cannot']); ?></p>
                <?php if ($blocked === true): ?>
                <div class="alert alert-warning small mt-3 mb-0" role="note">
                    <strong>This way cannot be chosen yet.</strong>
                    <ul class="mb-0 ps-3 text-break">
                        <?php foreach ($problems[$way] as $problem): ?>
                        <li><?php echo $esc($problem); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </fieldset>

    <div class="d-flex flex-wrap gap-2">
        <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-floppy-disk me-1"></i> Save
        </button>
        <a class="btn btn-outline-secondary" href="<?php echo $esc(Site::url('admin/sites')); ?>">Back to Organisations</a>
    </div>
</form>

<?php endif; ?>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
