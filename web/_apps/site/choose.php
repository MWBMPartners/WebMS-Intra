<?php
// Path: _apps/site/choose.php
/**
 * -----------------------------------------------------------------------------
 * Choose an organisation 🌐
 * -----------------------------------------------------------------------------
 * Lists "your organisations" (Site::organisationsFor()) and lets the person
 * go to one. Reached in three ways:
 *
 *   - Straight after signing in, when organisations are told apart by the
 *     sign-in session and the person belongs to two or more. (The owner,
 *     Salem874, decided on 12 October 2026 that such a person chooses,
 *     rather than being put into one of them.) SiteGate sends every other address
 *     here until they choose, so nothing of any organisation is shown first.
 *   - From the refusal page, by somebody who opened an organisation they do
 *     not belong to.
 *   - By typing the address, to switch later.
 *
 * In session mode each organisation is a button that posts to /site/switch.
 * In the two address ways each is a plain link to that organisation's own
 * address (Site::addressOf()).
 *
 * WHY IT IS A PLAIN PAGE WITH NO MENU. Until an organisation is chosen there
 * is no organisation whose name, colours and menu could honestly be shown.
 * So it uses SiteGate::pageStart(), which shows the product's name only.
 *
 * ITS STATES
 *   - Nothing to choose (the account belongs to no organisation): says so,
 *     and says to ask an administrator. HTTP 200: the page worked.
 *   - The list could not be read: says so and offers "try again". HTTP 503.
 *   - A message left by /site/switch (an expired form, an organisation that
 *     is not theirs) is shown once, above the list.
 *   - One organisation only on this installation: there is nothing to
 *     choose, so it goes to the front page.
 *
 * WHAT IT CANNOT DO: remember which organisation the person chose last time.
 * Every sign-in asks again.
 *
 * A page inherits only $mysqli and $SETTINGS (see .claude/rules/app-pages.md).
 *
 * @package   Portal\App\Site
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
use Portal\Core\SiteGate;

// 🛡️ Signed in only. The address is seeded as protected, so the router has
//    already sent a signed-out visitor to sign in; this is the second lock.
Auth::requireLogin();
$userId = (int) ($_SESSION['user_id'] ?? 0);

// 🏠 One organisation only: nothing to choose.
if (Site::isMultisiteEnabled() === false) {
    header('Location: ' . Site::url(''), true, 302);
    exit();
}

$db   = App::db();
$mode = Site::detectionMode();
$esc  = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

// 📋 A message left by /site/switch, shown once.
$flashMsg = (string) ($_SESSION['flash_msg'] ?? '');
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$signedInAs  = SiteGate::accountLabel($db, $userId);
$signOutLink = '<a href="' . $esc(Site::url('logout')) . '">Sign out</a>';

// 👥 Your organisations. A failed look-up is an error state of its own: it
//    must never be shown as "you have no organisations".
try {
    $own = Site::organisationsFor($userId, $db);
} catch (\Throwable $e) {
    error_log('[WebMS-Intra] site/choose: the list of organisations could not be read: ' . $e->getMessage());
    http_response_code(503);
    SiteGate::pageStart('Choose an organisation', 'Your organisations could not be listed just now.');
    echo '<h1>Your organisations could not be listed</h1>';
    echo '<p>The portal could not read your organisations just now. Nothing has been changed.</p>';
    echo '<p class="actions"><a class="button" href="' . $esc(Site::url('site/choose')) . '">Try again</a> ' . $signOutLink . '</p>';
    SiteGate::pageEnd();
    exit();
}

// The organisation the person is in now, if they are in one. In session mode
// that is only true once they have chosen.
$currentId = ($mode !== Site::MODE_SESSION || Site::sessionState() === Site::SESSION_OK) ? Site::id() : 0;

SiteGate::pageStart('Choose an organisation', 'Choose which of your organisations to work in.');
?>
<h1>Choose an organisation</h1>
<?php if ($signedInAs !== ''): ?>
<p class="muted">You are signed in as <?php echo $esc($signedInAs); ?>.</p>
<?php endif; ?>
<?php if ($flashMsg !== ''): ?>
<p class="notice" role="alert"><?php echo $esc($flashMsg); ?></p>
<?php endif; ?>

<?php if ($own === []): ?>
<p>Your account has not been added to any organisation yet, so there is nothing to show you.</p>
<p>Ask an administrator to add you to your organisation. Then sign in again.</p>
<?php else: ?>
<p>
    <?php if ($mode === Site::MODE_HOST): ?>
    Each organisation has its own address. You will be asked to sign in again there, because a browser keeps the sign-in for each address separate.
    <?php else: ?>
    Choose the organisation you want to work in. You can change it later from the menu.
    <?php endif; ?>
</p>
<ul class="choices">
    <?php foreach ($own as $site): ?>
    <?php
    $name    = $esc((string) $site['siteName']) . ($site['siteID'] === $currentId ? ' (you are here)' : '');
    $address = Site::addressOf($site);
    ?>
    <li>
        <?php if ($mode === Site::MODE_SESSION): ?>
        <form method="post" action="<?php echo $esc(Site::url('site/switch')); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo $esc(Auth::csrfToken()); ?>">
            <input type="hidden" name="site_id" value="<?php echo (int) $site['siteID']; ?>">
            <button type="submit" class="button"><?php echo $name; ?></button>
        </form>
        <?php elseif ($address !== ''): ?>
        <a class="button" href="<?php echo $esc($address); ?>"><?php echo $name; ?></a>
        <?php else: ?>
        <span class="muted"><?php echo $name; ?>: no address has been set for this organisation yet. Ask an administrator.</span>
        <?php endif; ?>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<p class="actions"><?php echo $signOutLink; ?></p>
<?php
SiteGate::pageEnd();
