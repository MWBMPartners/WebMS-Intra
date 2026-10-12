<?php
// Path: _core/templates/not-a-member.php
/**
 * -----------------------------------------------------------------------------
 * "You are not a member of this organisation" — the refusal page 🚪
 * -----------------------------------------------------------------------------
 * Shown, with HTTP 403, to a signed-in person who opens an address of an
 * organisation they do not belong to (#511, roll-out package F2). Only
 * SiteGate::notAMember() requires this file, and it does so before the
 * router, so no page of that organisation has run.
 *
 * WHAT IT SAYS
 *   - who the person is signed in as, so somebody with two accounts sees at
 *     once that they are in the wrong one;
 *   - where their OWN organisations are, as links (or, when organisations
 *     are told apart by the sign-in session, a link to the chooser);
 *   - for an account that belongs to no organisation: to ask an
 *     administrator to add them;
 *   - how to sign out. Signing out is also how to see this organisation's
 *     public pages: the owner (Salem874) chose on 12 October 2026 that a
 *     signed-in non-member is refused on EVERY address, public ones included.
 *
 * WHAT IT MUST NEVER SAY: anything about the organisation it refuses. Not
 * its name, logo or colours, and nothing that tells whether the address the
 * person asked for exists. That is why it uses SiteGate::pageStart() (the
 * product's name only) and not the shared header, which draws the
 * organisation's name and menu. The only organisation names on this page are
 * the person's own.
 *
 * The wording is plain English written here, not translated text; see the
 * note at the top of SiteGate.php.
 *
 * Variables handed in by SiteGate::notAMember():
 *   $gateSignedInAs  string  "Name (e-mail)", or '' when it could not be read
 *   $gateOwn         list    the person's own organisations (Site::organisationsFor())
 *
 * @package   Portal\Core\Templates
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/511
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Site;
use Portal\Core\SiteGate;

$gateEsc  = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
$gateMode = Site::detectionMode();

SiteGate::pageStart('Not a member of this organisation', 'This account is not a member of the organisation this address belongs to.');
?>
<h1>You are not a member of this organisation</h1>
<?php if ($gateSignedInAs !== ''): ?>
<p class="muted">You are signed in as <?php echo $gateEsc($gateSignedInAs); ?>.</p>
<?php endif; ?>

<?php if ($gateOwn === []): ?>
<p>Your account has not been added to any organisation yet, so the portal has nothing it can show you.</p>
<p>Ask an administrator to add you to your organisation. Then sign in again.</p>
<?php else: ?>
<p>This account does not belong to the organisation this address opens, so the portal has shown you nothing from it.</p>
<?php if ($gateMode === Site::MODE_SESSION): ?>
<p class="actions"><a class="button" href="<?php echo $gateEsc(Site::url('site/choose')); ?>">Choose one of your organisations</a></p>
<?php else: ?>
<p>
    Go to one of your own organisations<?php echo $gateMode === Site::MODE_HOST ? '. You will be asked to sign in again there, because a browser keeps the sign-in for each address separate' : ''; ?>:
</p>
<ul class="choices">
    <?php foreach ($gateOwn as $gateSite): ?>
    <?php $gateAddress = Site::addressOf($gateSite); ?>
    <li>
        <?php if ($gateAddress !== ''): ?>
        <a class="button" href="<?php echo $gateEsc($gateAddress); ?>"><?php echo $gateEsc((string) $gateSite['siteName']); ?></a>
        <?php else: ?>
        <span class="muted"><?php echo $gateEsc((string) $gateSite['siteName']); ?>: no address has been set for this organisation yet. Ask an administrator.</span>
        <?php endif; ?>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
<?php endif; ?>

<p class="actions">
    If you meant to use a different account, or want to see this organisation's public pages, sign out first.
    <a href="<?php echo $gateEsc(Site::url('logout')); ?>">Sign out</a>
</p>
<?php
SiteGate::pageEnd();
