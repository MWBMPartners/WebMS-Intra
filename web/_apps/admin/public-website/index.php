<?php
// Path: _apps/admin/public-website/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin → Public website 🌐 (#493, package P3; D-082, D-085, D-093, D-094, D-095)
 * -----------------------------------------------------------------------------
 * Address: /admin/public-website. Where an organisation records how its public
 * website is set up:
 *
 *   - its MAIN website (for example www.ourchurch.org), which the portal does
 *     not run and inside which the portal's content will appear (D-095);
 *   - an OPTIONAL public address for the portal's own public pages (for
 *     example events.ourchurch.org), so public visitors never touch the
 *     address members sign in at (D-095);
 *   - which outside websites may show its public pages in a frame (D-082);
 *   - and, for the installation's global administrator only, whether framing
 *     is allowed at all on this installation (D-094).
 *
 * WHO MAY OPEN IT: `PublicWebsiteSetup::maySetUp()` — a global administrator
 * of the installation or of this organisation, or somebody holding "Set up
 * the public website" (D-085). An ORDINARY administrator is refused.
 *
 * 🚪 THE PUBLIC DOOR CARD (#493 P4a, D-097) is at the top: whether the door is
 *    on (it starts OFF), its addresses, and the three buttons — turn it on,
 *    replace its name, switch it off. The last two stop every copy pasted
 *    into a website, so they say so before they are pressed and need a tick.
 *    Until the door is on, nothing from the portal is shown on any outside
 *    website, and the card says so: a page of settings that seem to do
 *    something they do not is #671's "a lie with a Save button".
 *    With the door on it prints, as text, the feed addresses, the script lines
 *    (P4b) and the frame lines (P4c) — and says when a frame cannot work: on
 *    no website while the installation forbids framing (D-094), and only on
 *    the listed websites otherwise (D-082).
 *
 * 🧪 THE TEST-COPY CARD (#493 P4d, D-087) is drawn ONLY on a test copy —
 *    `PublicWebsiteSetup::testCopyDoorRule()`, which asks `Channel`'s one
 *    list of test channels, is the one place that decides. It says who the
 *    door answers there now, sets the installation's ceiling (its global
 *    administrator only) and the organisation's choice within it (the
 *    organisation's global administrator only), and says plainly that
 *    "signed-in testers only" works only when the door's address is opened
 *    directly: a browser does not send the portal's sign-in to a script, a
 *    feed or a frame on another website.
 *
 * Every value is checked, refused with its reason, and recorded, by
 * `Portal\Core\PublicWebsiteSetup` — the ONE place. This page only draws.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/493
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\PortalAddress;
use Portal\Core\PublicDoor;
use Portal\Core\PublicWebsiteSetup;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

// 🚪 THE ONE GATE (D-085). Read from the database, never the session.
if (PublicWebsiteSetup::maySetUp($mysqli, $userId, $siteId) === false) {
    Router::renderError(403);
    return;
}

$esc  = static fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$csrf = Auth::csrfToken();

// 📖 What is stored, each read back CHECKED (a hand edit is dropped, not shown as if it were in force).
$website     = PublicWebsiteSetup::websiteAddressFor($mysqli, $siteId);
$publicHost  = PublicWebsiteSetup::publicHostFor($mysqli, $siteId);
// D-101: an organisation's public address does nothing until the
// installation's global administrator approves it, and an approved one that
// is also an address members sign in at is not the door either. The card asks
// the ONE helper the handler and PublicDoor ask (round 7 of P4a's check).
$publicHostStatus   = PublicWebsiteSetup::publicHostStatus($mysqli, $siteId);
$publicHostInEffect = PublicWebsiteSetup::publicHostInEffect($mysqli, $siteId);
$origins     = PublicWebsiteSetup::frameOriginsStored($mysqli, $siteId);
$permitted   = PublicWebsiteSetup::framingPermitted($mysqli);
$isRootAdmin = App::isRootAdmin();
$door        = PublicWebsiteSetup::doorStateFor($mysqli, $siteId);
$doorEvents  = $door['state'] === 'on' ? PublicDoor::addressFor($mysqli, $siteId, PublicDoor::SURFACE_EVENTS) : '';
$doorPosters = $door['state'] === 'on' ? PublicDoor::addressFor($mysqli, $siteId, PublicDoor::SURFACE_POSTERS) : '';
// #493 P4b: the script a website pastes in, built by the same ONE helper, and
// the three lines to paste — one per thing it can show. The countdown (the old
// widget/countdown.js, D-084) is its "next" mode.
$doorScript  = $door['state'] === 'on' ? PublicDoor::addressFor($mysqli, $siteId, PublicDoor::SURFACE_SCRIPT) : '';
$doorPastes  = [];
if ($doorScript !== '') {
    foreach (['events' => 'Your events, soonest first', 'posters' => 'Your noticeboard posters', 'next' => 'A countdown to your next event'] as $show => $words) {
        $doorPastes[$show] = ['words' => $words, 'code' => '<script src="' . $doorScript . '" data-show="' . $show . '" defer></script>'];
    }
}
// #493 P4c: the page a website shows in a FRAME, for websites that cannot take
// a script (D-056's fallback). Its address comes from the same ONE helper; the
// lines to paste are printed as TEXT. Whether a frame will work at all is
// said from the SAME two answers the door's header is built from — the
// installation's switch (D-094) and this organisation's list (D-082) — so the
// card can never promise a frame the header refuses.
$doorPage    = $door['state'] === 'on' ? PublicDoor::addressFor($mysqli, $siteId, PublicDoor::SURFACE_PAGE) : '';
$framePastes = [];
if ($doorPage !== '') {
    foreach (['events' => ['Your events, soonest first', 'Our events'], 'posters' => ['Your noticeboard posters', 'Our noticeboard'],
        'both' => ['Both, events first', 'Our events and noticeboard']] as $show => [$words, $frameTitle]) {
        $framePastes[$show] = ['words' => $words, 'code' => '<iframe src="' . $doorPage . '?show=' . $show . '" title="' . $frameTitle
            . '" width="100%" height="600" style="border:0" loading="lazy" referrerpolicy="no-referrer" '
            . 'sandbox="allow-popups allow-popups-to-escape-sandbox"></iframe>'];
    }
}
$framingInForce = PublicWebsiteSetup::frameOriginsInForce($mysqli, $siteId);
// D-087 on a test copy (#493 P4d): the ONE answer the door itself asks, so the
// card can never say something the door does not do. It asks the channel
// first, so on the live portal nothing else is asked.
$doorTestRule    = PublicWebsiteSetup::testCopyDoorRule($mysqli, $siteId);
$doorTestersOnly = $doorTestRule === PublicWebsiteSetup::DOOR_TEST_TESTERS;
// On a testers-only test copy with a public address in effect, the door's /w/
// addresses on the portal's own address are the ONLY ones a tester can use
// (a browser never carries the sign-in to the public address).
$doorTesterEvents  = $doorTestersOnly === true && $door['state'] === 'on'
    ? PublicDoor::nameAddressFor($mysqli, $siteId, PublicDoor::SURFACE_EVENTS) : '';
$doorTesterPosters = $doorTestersOnly === true && $door['state'] === 'on'
    ? PublicDoor::nameAddressFor($mysqli, $siteId, PublicDoor::SURFACE_POSTERS) : '';
$isOrgGlobal     = PublicWebsiteSetup::isGlobalAdministratorOf($mysqli, $userId, $siteId);
$doorTestLimit   = $doorTestRule === PublicWebsiteSetup::DOOR_TEST_LIVE ? null : PublicWebsiteSetup::testCopyLimit($mysqli);
$doorTestChoice  = $doorTestRule === PublicWebsiteSetup::DOOR_TEST_LIVE ? null : PublicWebsiteSetup::testCopyChoice($mysqli, $siteId);

// The one-click suggestion: the main website as an https framing website, when
// it would be accepted and is not on the list yet.
$suggestion = '';
if ($website !== '') {
    $asOrigin = PublicWebsiteSetup::checkFrameOrigin('https://' . substr($website, (int) strpos($website, '://') + 3));
    if ($asOrigin['problem'] === '' && in_array($asOrigin['origin'], $origins, true) === false) {
        $suggestion = $asOrigin['origin'];
    }
}

// 🔎 A set-up check's result is shown ONCE and kept nowhere else.
$checkRows = $_SESSION['public_website_check'][$siteId] ?? null;
unset($_SESSION['public_website_check'][$siteId]);
if (is_array($checkRows) === false) {
    $checkRows = null;
}

$flashMsg  = (string) ($_SESSION['flash_msg'] ?? '');
$flashType = (string) ($_SESSION['flash_type'] ?? 'info');
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$stateWords = [
    'ok'       => ['Fine', 'success'],
    'problem'  => ['Needs attention', 'danger'],
    'note'     => ['For information', 'secondary'],
    'untested' => ['Not tested', 'warning'],
];

$pageTitle       = 'Public website';
$pageDescription = 'How this organisation\'s public website is set up: its main website, a public address, and which websites may frame its pages.';
$pageSection     = 'admin';
$breadcrumbs     = ['Dashboard' => Site::url(''), 'Admin' => Site::url('admin'), 'Public website' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3">
    <h1 class="h3 mb-2"><i class="fa-solid fa-globe me-2" aria-hidden="true"></i>Public website</h1>

    <?php if ($flashMsg !== ''): ?>
        <div class="alert alert-<?php echo $esc($flashType); ?>" role="alert"><?php echo $esc($flashMsg); ?></div>
    <?php endif; ?>

    <!-- 🚪 The public door (#493 P4a, D-097): on or off, its addresses, and the three buttons. -->
    <div class="card mb-3 border-primary" data-public-door="<?php echo $esc($door['state']); ?>">
        <div class="card-body">
            <h2 class="h5">The public door</h2>
            <p class="text-muted">The public door is how your own website reads the events and noticeboard posters you have put on the public website — only those, and only while their dates allow. Nothing else the portal holds goes through it.</p>
            <?php if ($door['state'] === 'on'): ?>
                <p><span class="badge text-bg-success me-1">On</span> Your website can read:</p>
                <?php if ($doorEvents === ''): ?>
                    <div class="alert alert-warning" data-door-no-address="1">The door is on, but the portal cannot build its addresses. <?php echo $esc(PublicDoor::whyNoAddress($mysqli, $siteId)); ?></div>
                <?php else: ?>
                    <ul class="mb-2">
                        <li>Events: <code data-door-address="events"><?php echo $esc($doorEvents); ?></code></li>
                        <li>Noticeboard posters: <code data-door-address="posters"><?php echo $esc($doorPosters); ?></code></li>
                    </ul>
                    <p class="small text-muted">Each gives at most <?php echo (int) PublicDoor::DEFAULT_LIMIT; ?> items; add <code>?limit=</code> and a number up to <?php echo (int) PublicDoor::MAX_LIMIT; ?> for more. A change reaches websites within about a minute.</p>
                    <?php if ($doorPastes !== []): ?>
                        <?php // #493 P4b: the pasted-in script. The code is printed as TEXT (escaped), never as markup on this page. ?>
                        <h3 class="h6 mt-3">Or paste one line into your website</h3>
                        <p class="small">If your website lets you add a script, paste one of these lines where the list should appear. It draws the items as plain text in a box of its own, so your website's styles are not changed.</p>
                        <p class="small" data-script-apps="1">The events and countdown lines show nothing while Calendar and Events is switched off for your organisation, and the posters line shows nothing while the Noticeboard is switched off.</p>
                        <?php foreach ($doorPastes as $show => $paste): ?>
                            <p class="small mb-1"><?php echo $esc($paste['words']); ?>:</p>
                            <pre class="small bg-body-tertiary border rounded p-2 mb-2" data-door-script="<?php echo $esc($show); ?>"><code><?php echo $esc($paste['code']); ?></code></pre>
                        <?php endforeach; ?>
                        <p class="small text-muted">Add <code>data-limit="5"</code> to show fewer (up to <?php echo (int) PublicDoor::MAX_LIMIT; ?>), or <code>data-target="#my-box"</code> to draw inside a part of your page you name. If your website has a content security policy, it must allow this portal's address for scripts (script-src) and for reading data (connect-src), and for images (img-src) both this portal's address and the address of any outside picture a poster uses — allowing <code>https:</code> for images covers both. Some website builders do not allow scripts at all; the feed addresses above still work for whoever builds your website.</p>
                    <?php endif; ?>
                    <?php if ($framePastes !== []): ?>
                        <?php // #493 P4c: the frame, for websites that cannot take a script. Printed as TEXT (escaped), never as a live frame on this page. ?>
                        <h3 class="h6 mt-3">Or show it in a frame</h3>
                        <p class="small">If your website cannot take a script but lets you add a frame (an "embed" or "iframe" box), paste one of these. It shows a plain page with no script in it.</p>
                        <p class="small" data-frame-apps="1">The events frame, and the one with both, show nothing while Calendar and Events is switched off for your organisation; the posters frame, and the one with both, show nothing while the Noticeboard is switched off.</p>
                        <?php if ($permitted === false): ?>
                            <div class="alert alert-warning small" data-frame-works="installation-off">This installation does not allow any outside website to show its organisations' pages in a frame, so a frame pasted into your website shows nothing at all. The script above still works.</div>
                        <?php elseif ($framingInForce === []): ?>
                            <div class="alert alert-warning small" data-frame-works="none-listed">A frame works only on the websites listed under "Websites allowed to show your public pages in a frame" below, and none is listed yet, so a frame pasted into any website shows nothing. Add your website there first.</div>
                        <?php else: ?>
                            <p class="small" data-frame-works="listed">A frame works <strong>only</strong> on the websites listed under "Websites allowed to show your public pages in a frame" below: <?php echo $esc(implode(', ', $framingInForce)); ?>. On any other website every current browser shows nothing. (A very old browser that does not understand that rule, such as Internet Explorer 11, would show it on any website; it only ever shows what you have already put on the public website.)</p>
                        <?php endif; ?>
                        <?php foreach ($framePastes as $show => $paste): ?>
                            <p class="small mb-1"><?php echo $esc($paste['words']); ?>:</p>
                            <pre class="small bg-body-tertiary border rounded p-2 mb-2" data-door-frame="<?php echo $esc($show); ?>"><code><?php echo $esc($paste['code']); ?></code></pre>
                        <?php endforeach; ?>
                        <p class="small text-muted">Add <code>&amp;limit=5</code> to the address to show fewer (up to <?php echo (int) PublicDoor::MAX_LIMIT; ?> of each; <?php echo (int) PublicDoor::PAGE_DEFAULT_LIMIT; ?> without it). Change <code>height</code> to fit your page.</p>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($doorTestersOnly === true): ?>
                    <?php // #493 P4d, round 1 of its check: a browser sends the sign-in only to the portal's own address, so with a public address in effect the addresses above (which are on it) answer NOBODY here; testers are given the /w/ address instead. ?>
                    <?php if ($publicHostInEffect !== ''): ?>
                        <div class="alert alert-warning" data-door-testers-only="1" data-door-testers-public="1">This is a test copy of the portal, so its public door answers only people signed in here who are allowed to use the test copy. A browser sends that sign-in only to the portal's own address — never to your public address, <?php echo $esc($publicHostInEffect); ?>, and never to a script, a feed or a frame on another website — so the addresses above answer <strong>nobody</strong> here, and neither does any copy pasted into a website, until the door is opened to anybody below.
                            <?php if ($doorTesterEvents !== ''): ?>
                                Testers can try the door at its address on the portal's own address, in a browser signed in here: <code data-door-testers-try="events"><?php echo $esc($doorTesterEvents); ?></code> (events) and <code data-door-testers-try="posters"><?php echo $esc($doorTesterPosters); ?></code> (posters).
                            <?php else: ?>
                                The portal cannot build the door's address on its own address either, so testers cannot try it here until that is put right.
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning" data-door-testers-only="1">This is a test copy of the portal, so its public door answers only people signed in here who are allowed to use the test copy. Browsers do not send your sign-in to another website, so a copy pasted into a real website — a script or a frame — shows nothing from this test copy; open the addresses above in a browser signed in here instead.</div>
                    <?php endif; ?>
                <?php endif; ?>
                <form method="post" action="<?php echo $esc(Site::url('admin/public-website/save')); ?>" class="mt-2" data-door-form="replace">
                    <input type="hidden" name="csrf_token" value="<?php echo $esc($csrf); ?>">
                    <input type="hidden" name="action" value="door_replace">
                    <?php if ($publicHostInEffect !== ''): ?>
                        <?php // Round 1 of P4a's check: the public address carries no name, so replacing the name does NOT stop it. Said plainly, and the replace advice is not offered as the remedy. ?>
                        <p class="small mb-1" data-door-replace-public="1"><strong>Replace the name</strong> stops only copies that use the portal's own address, with <code>/w/</code> and the name in it. Your public address, <?php echo $esc($publicHostInEffect); ?>, carries no name, so copies that use it are <strong>not</strong> affected. To stop those, switch the door off below, or change or remove the public address.</p>
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="understood" value="1" id="pw-door-replace-ok" required><label class="form-check-label" for="pw-door-replace-ok">I understand copies using the old name on the portal's own address will stop working, and copies using the public address will not</label></div>
                    <?php else: ?>
                        <p class="small mb-1"><strong>Replace the name</strong> if the addresses have gone somewhere they should not. Every copy pasted into a website with the old name stops working at once, and you will need to paste the new addresses in again.</p>
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="understood" value="1" id="pw-door-replace-ok" required><label class="form-check-label" for="pw-door-replace-ok">I understand every copy using the old name will stop working</label></div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-outline-warning btn-sm mt-1">Replace the name</button>
                </form>
                <form method="post" action="<?php echo $esc(Site::url('admin/public-website/save')); ?>" class="mt-3" data-door-form="off">
                    <input type="hidden" name="csrf_token" value="<?php echo $esc($csrf); ?>">
                    <input type="hidden" name="action" value="door_off">
                    <p class="small mb-1" data-door-off-words="1"><strong>Switch the door off</strong> to stop your website showing anything from the portal. Every copy pasted into a website stops working while it is off. Turning the door on again gives it a new name, so copies using the old name on the portal's own address stay stopped — but a public address carries no name, so it answers again the moment the door is back on. To stop a public address that has gone somewhere it should not, change or remove it.</p>
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="understood" value="1" id="pw-door-off-ok" required><label class="form-check-label" for="pw-door-off-ok">I understand every copy pasted into a website will stop working</label></div>
                    <button type="submit" class="btn btn-outline-danger btn-sm mt-1">Switch the public door off</button>
                </form>
            <?php elseif ($door['state'] === 'unreadable'): ?>
                <div class="alert alert-warning" data-door-unreadable="1">Whether the public door is on could not be read. Try again later; nothing was changed.</div>
            <?php else: ?>
                <p><span class="badge text-bg-secondary me-1">Off</span> <strong>Nothing from the portal is shown on any outside website.</strong> The door starts off, and stays off until somebody turns it on here.</p>
                <?php if ($door['state'] === 'spoiled'): ?>
                    <div class="alert alert-warning" data-door-spoiled="1">A public door name is stored that the portal would not make, so the door does not answer. Replace the name to give it a new one.</div>
                    <form method="post" action="<?php echo $esc(Site::url('admin/public-website/save')); ?>" data-door-form="replace">
                        <input type="hidden" name="csrf_token" value="<?php echo $esc($csrf); ?>">
                        <input type="hidden" name="action" value="door_replace">
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="understood" value="1" id="pw-door-replace-ok" required><label class="form-check-label" for="pw-door-replace-ok">I understand every copy using the old name will stop working</label></div>
                        <button type="submit" class="btn btn-primary btn-sm mt-1">Replace the name</button>
                    </form>
                <?php else: ?>
                    <form method="post" action="<?php echo $esc(Site::url('admin/public-website/save')); ?>" data-door-form="on">
                        <input type="hidden" name="csrf_token" value="<?php echo $esc($csrf); ?>">
                        <input type="hidden" name="action" value="door_on">
                        <button type="submit" class="btn btn-primary btn-sm">Turn on the public door</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($doorTestRule !== PublicWebsiteSetup::DOOR_TEST_LIVE): ?>
        <!-- 🧪 The public door on THIS TEST COPY (#493 P4d, D-087). Drawn only on a test copy. -->
        <div class="card mb-3" data-door-test-card="<?php echo $esc($doorTestRule); ?>">
            <div class="card-body">
                <h2 class="h5">The public door on this test copy</h2>
                <p class="text-muted">This is a test copy of the portal (<?php echo $esc((string) PORTAL_ENV); ?>), not the live one. Here you choose who its public door answers.</p>
                <?php if ($doorTestRule === PublicWebsiteSetup::DOOR_TEST_UNGATED): ?>
                    <div class="alert alert-warning" data-door-test-now="ungated">This test copy's staff sign-in gate is switched off, so every page of it — the public door included — is open to anybody, whatever is chosen below. Search engines are still told not to list anything the door sends.</div>
                <?php elseif ($doorTestRule === PublicWebsiteSetup::DOOR_TEST_ANYBODY): ?>
                    <p data-door-test-now="anybody"><span class="badge text-bg-warning me-1">Open to anybody</span> Your public door here answers anybody on the internet, so a copy pasted into a real website works. Every answer tells search engines not to list it.</p>
                <?php else: ?>
                    <p data-door-test-now="testers"><span class="badge text-bg-secondary me-1">Signed-in testers only</span> Your public door here answers only people signed in to this test copy who are allowed to use it.</p>
                <?php endif; ?>
                <p class="small" data-door-test-cookie="1"><strong>"Signed-in testers only" works only at the door's address on the portal's own address</strong> — the one with /w/ in it — opened directly in a browser signed in here. A browser sends this portal's sign-in only to the address it signed in at: never to a script, a feed or a frame on another website, and never to your public address. So while the door is for testers only, a copy pasted into a real website shows nothing from this test copy, and a public address answers nobody. To try it on a real website or at your public address, choose "open to anybody".</p>
                <?php if ($doorTestLimit === null || $doorTestChoice === null): ?>
                    <div class="alert alert-warning" data-door-test-unreadable="1">What is chosen here could not be read just now, so nothing can be changed here.<?php echo $doorTestRule === PublicWebsiteSetup::DOOR_TEST_UNGATED ? '' : ' While it cannot be read, the door answers signed-in testers only.'; ?> Try again later.</div>
                <?php else: ?>
                    <p class="mb-2" data-door-test-limit="<?php echo $esc($doorTestLimit); ?>"><strong>What this installation allows:</strong> <?php echo $doorTestLimit === 'either'
                        ? 'each organisation may choose, for its own door, between signed-in testers only and open to anybody.'
                        : 'signed-in testers only, for every organisation. "Open to anybody" cannot be chosen until the installation\'s global administrator allows it.'; ?></p>
                    <?php if ($isOrgGlobal === true): ?>
                        <form method="post" action="<?php echo $esc(Site::url('admin/public-website/save')); ?>" data-door-test-form="choice">
                            <input type="hidden" name="csrf_token" value="<?php echo $esc($csrf); ?>">
                            <input type="hidden" name="action" value="door_test_choice">
                            <p class="mb-1"><strong>Who your public door answers on this test copy</strong></p>
                            <div class="form-check"><input class="form-check-input" type="radio" name="value" value="testers" id="pw-test-testers"<?php echo $doorTestChoice !== 'anybody' ? ' checked' : ''; ?>><label class="form-check-label" for="pw-test-testers">Signed-in testers only</label></div>
                            <div class="form-check"><input class="form-check-input" type="radio" name="value" value="anybody" id="pw-test-anybody"<?php echo $doorTestChoice === 'anybody' ? ' checked' : ''; ?>><label class="form-check-label" for="pw-test-anybody">Open to anybody (search engines told not to list it)<?php echo $doorTestLimit !== 'either' ? ' — not allowed by this installation at the moment' : ''; ?></label></div>
                            <button type="submit" class="btn btn-primary btn-sm mt-2">Save</button>
                        </form>
                    <?php else: ?>
                        <p class="small text-muted" data-door-test-who="1">Only a global administrator of this organisation can change who its door answers here. It is set to <?php echo $doorTestChoice === 'anybody' ? 'open to anybody' : 'signed-in testers only'; ?>.</p>
                    <?php endif; ?>
                    <?php if ($isRootAdmin === true): ?>
                        <form method="post" action="<?php echo $esc(Site::url('admin/public-website/save')); ?>" class="mt-3" data-door-test-form="limit">
                            <input type="hidden" name="csrf_token" value="<?php echo $esc($csrf); ?>">
                            <input type="hidden" name="action" value="door_test_limit">
                            <p class="mb-1"><strong>For the whole installation:</strong> what every organisation may choose on this test copy</p>
                            <div class="form-check"><input class="form-check-input" type="radio" name="value" value="testers" id="pw-limit-testers"<?php echo $doorTestLimit !== 'either' ? ' checked' : ''; ?>><label class="form-check-label" for="pw-limit-testers">Signed-in testers only, for every organisation</label></div>
                            <div class="form-check"><input class="form-check-input" type="radio" name="value" value="either" id="pw-limit-either"<?php echo $doorTestLimit === 'either' ? ' checked' : ''; ?>><label class="form-check-label" for="pw-limit-either">Each organisation may choose to open its door to anybody</label></div>
                            <button type="submit" class="btn btn-primary btn-sm mt-2">Save</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($checkRows !== null): ?>
        <div class="card mb-3 border-primary" data-setup-check="1">
            <div class="card-body">
                <h2 class="h5">What the set-up check found</h2>
                <p class="small text-muted">Shown once. It changed no setting, and nothing here is a promise that your website shows the public door — the only page of your website this check fetches is your main website's front page, to see that it answers, and it never looks for the door there.</p>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($checkRows as $row): ?>
                        <?php [$word, $colour] = $stateWords[(string) ($row['state'] ?? 'note')] ?? $stateWords['note']; ?>
                        <li class="mb-2" data-check-state="<?php echo $esc((string) ($row['state'] ?? '')); ?>">
                            <span class="badge text-bg-<?php echo $esc($colour); ?> me-1"><?php echo $esc($word); ?></span>
                            <strong><?php echo $esc((string) ($row['label'] ?? '')); ?>:</strong>
                            <?php echo $esc((string) ($row['words'] ?? '')); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <!-- 🏠 The main website (D-095) -->
    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h5">Your main website</h2>
            <p class="text-muted">The website your organisation already has, which the portal does not run — for example https://www.ourchurch.org. The portal's events and posters appear inside it through the public door above, once it is on.</p>
            <form method="post" action="<?php echo $esc(Site::url('admin/public-website/save')); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $esc($csrf); ?>">
                <input type="hidden" name="action" value="website">
                <label class="form-label" for="pw-website">Address</label>
                <input type="text" class="form-control" id="pw-website" name="value" maxlength="255" value="<?php echo $esc($website); ?>" placeholder="https://www.example.org" inputmode="url" autocomplete="off">
                <div class="form-text">Leave it empty and save to remove it.</div>
                <button type="submit" class="btn btn-primary btn-sm mt-2">Save</button>
            </form>
        </div>
    </div>

    <!-- 🌍 The optional public address (D-095) -->
    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h5">Public address for the portal's public pages <span class="badge text-bg-light border">Optional</span></h2>
            <p class="text-muted">A separate name, such as events.ourchurch.org, that you point at this portal so public visitors never reach the address members sign in at. Without one, the public pages will use the portal's own address. Each public address belongs to one organisation only.</p>
            <form method="post" action="<?php echo $esc(Site::url('admin/public-website/save')); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $esc($csrf); ?>">
                <input type="hidden" name="action" value="public_host">
                <label class="form-label" for="pw-host">Name</label>
                <input type="text" class="form-control" id="pw-host" name="value" maxlength="253" value="<?php echo $esc($publicHost); ?>" placeholder="events.example.org" autocomplete="off">
                <div class="form-text">Only the name, without https:// in front. Leave it empty and save to remove it. A new or changed address waits for the installation's administrator to approve it.</div>
                <button type="submit" class="btn btn-primary btn-sm mt-2">Save</button>
            </form>
            <?php if ($publicHost !== ''): ?>
                <?php // D-101: an address does NOTHING until the installation's global administrator approves it. Said here, on the organisation's own card. ?>
                <?php // Words for PublicWebsiteSetup::publicHostStatus(), the ONE answer (round 8 of P4a's check). ?>
                <p class="mt-2 mb-0" data-public-host-approval="<?php echo match ($publicHostStatus) {
                    PublicWebsiteSetup::HOST_APPROVED => 'approved', PublicWebsiteSetup::HOST_MEMBERS => 'approved-members-address',
                    PublicWebsiteSetup::HOST_UNCHECKED => 'approved-unchecked', default => 'waiting'}; ?>">
                    <?php if ($publicHostStatus === PublicWebsiteSetup::HOST_APPROVED): ?>
                        <span class="badge text-bg-success me-1">Approved</span> The installation's administrator has approved this address, so the public door answers on it while the door is on.
                    <?php elseif ($publicHostStatus === PublicWebsiteSetup::HOST_MEMBERS): ?>
                        <span class="badge text-bg-warning me-1">Approved, not in use</span> The installation's administrator approved this address, but it is also an address members sign in at, so the public door does <strong>not</strong> answer on it. Change it to a name members do not use.
                    <?php elseif ($publicHostStatus === PublicWebsiteSetup::HOST_UNCHECKED): ?>
                        <span class="badge text-bg-warning me-1">Approved, not checked</span> The installation's administrator approved this address, but whether it is also an address members sign in at could not be checked just now, so the portal does not count it as the public door's address. Try again later.
                    <?php else: ?>
                        <span class="badge text-bg-warning me-1">Waiting</span> Waiting for the installation's administrator to approve it. Until then it does nothing: it is not the public door.
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- 🖼️ Websites allowed to frame the pages (D-082, D-094) -->
    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h5">Websites allowed to show your public pages in a frame</h2>
            <p class="text-muted">A frame is a window of the portal's page inside another website's page. Only the websites listed here will be allowed to do it; with nothing listed, no website may. One website per line, each beginning https://, at most <?php echo (int) PublicWebsiteSetup::MAX_FRAME_ORIGINS; ?>.</p>
            <?php if ($permitted === false): ?>
                <div class="alert alert-warning" data-framing-forbidden="1">
                    This installation does not allow any outside website to show its organisations' pages in a frame, so a list cannot be saved here and any list already kept is ignored. You can still empty it.
                </div>
            <?php endif; ?>
            <form method="post" action="<?php echo $esc(Site::url('admin/public-website/save')); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $esc($csrf); ?>">
                <input type="hidden" name="action" value="frame_origins">
                <label class="form-label" for="pw-origins">Websites</label>
                <textarea class="form-control font-monospace" id="pw-origins" name="value" rows="4" placeholder="https://www.example.org"><?php echo $esc(implode("\n", $origins)); ?></textarea>
                <button type="submit" class="btn btn-primary btn-sm mt-2">Save</button>
            </form>
            <?php if ($suggestion !== '' && $permitted === true): ?>
                <form method="post" action="<?php echo $esc(Site::url('admin/public-website/save')); ?>" class="mt-2" data-frame-suggestion="1">
                    <input type="hidden" name="csrf_token" value="<?php echo $esc($csrf); ?>">
                    <input type="hidden" name="action" value="frame_add_main">
                    <button type="submit" class="btn btn-outline-secondary btn-sm">Add your main website (<?php echo $esc($suggestion); ?>) to the list</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isRootAdmin === true): ?>
        <!-- ✅ Public addresses awaiting approval (D-101), for the installation's global administrator only: every organisation's, in one list. -->
        <?php $allPublicHosts = PublicWebsiteSetup::allPublicHosts($mysqli); ?>
        <div class="card mb-3" data-public-host-approvals="1">
            <div class="card-body">
                <h2 class="h5">Public addresses (every organisation)</h2>
                <p class="text-muted">An organisation's public address does nothing until you approve it here. Before approving, make sure the address is NOT one members sign in at — an alias of the portal's own address, say — because once approved, every page there answers only the public door. Withdrawing approval takes effect at once.</p>
                <?php if ($allPublicHosts === null): ?>
                    <div class="alert alert-warning mb-0" data-public-host-list-unreadable="1">The list of public addresses could not be read just now, so nothing can be approved or withdrawn here. Try again later; nothing was changed.</div>
                <?php elseif ($allPublicHosts === []): ?>
                    <p class="mb-0">No organisation has entered a public address.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($allPublicHosts as $one): ?>
                            <?php
                            // An approved address that is ALSO an address members sign in at is not the door
                            // (round 8 of P4a's check): said here too, not only on the organisation's card.
                            $rowState = $one['approved'] === false ? 'waiting'
                                : ($one['membersAddress'] === true ? 'approved-members-address' : ($one['membersAddress'] === null ? 'approved-unchecked' : 'approved'));
                            ?>
                            <li class="mb-2" data-public-host-row="<?php echo (int) $one['siteId']; ?>" data-public-host-row-state="<?php echo $esc($rowState); ?>">
                                <span class="badge <?php echo $rowState === 'approved' ? 'text-bg-success' : 'text-bg-warning'; ?> me-1"><?php echo $esc(match ($rowState) {
                                    'approved' => 'Approved', 'approved-members-address' => 'Approved, not in use (members sign in at it)',
                                    'approved-unchecked' => 'Approved, not checked', default => 'Waiting'}); ?></span>
                                <strong><?php echo $esc($one['host']); ?></strong> — <?php echo $esc($one['siteName']); ?>
                                <form method="post" action="<?php echo $esc(Site::url('admin/public-website/save')); ?>" class="d-inline ms-2">
                                    <input type="hidden" name="csrf_token" value="<?php echo $esc($csrf); ?>">
                                    <input type="hidden" name="action" value="<?php echo $one['approved'] === true ? 'public_host_withdraw' : 'public_host_approve'; ?>">
                                    <input type="hidden" name="site" value="<?php echo (int) $one['siteId']; ?>">
                                    <input type="hidden" name="host" value="<?php echo $esc($one['host']); ?>">
                                    <button type="submit" class="btn btn-sm <?php echo $one['approved'] === true ? 'btn-outline-danger' : 'btn-outline-success'; ?>"><?php echo $one['approved'] === true ? 'Withdraw approval' : 'Approve'; ?></button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- 🏢 The installation's switch (D-094), for its global administrator only. -->
        <div class="card mb-3" data-installation-switch="1">
            <div class="card-body">
                <h2 class="h5">For the whole installation</h2>
                <p class="text-muted">Whether any organisation on this installation may let outside websites show its public pages in a frame. When this says no, every organisation's list is ignored.</p>
                <form method="post" action="<?php echo $esc(Site::url('admin/public-website/save')); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo $esc($csrf); ?>">
                    <input type="hidden" name="action" value="frame_permitted">
                    <div class="form-check"><input class="form-check-input" type="radio" name="value" value="yes" id="pw-permit-yes"<?php echo $permitted === true ? ' checked' : ''; ?>><label class="form-check-label" for="pw-permit-yes">Each organisation chooses the websites allowed to frame its pages</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="value" value="no" id="pw-permit-no"<?php echo $permitted === false ? ' checked' : ''; ?>><label class="form-check-label" for="pw-permit-no">No outside website may frame any organisation's pages</label></div>
                    <button type="submit" class="btn btn-primary btn-sm mt-2">Save</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- 🔎 Check my set-up -->
    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h5">Check my set-up</h2>
            <p class="text-muted">Checks what can be known today: whether your main website answers over https (a website at a port other than the standard https one is not fetched), whether your public address's name exists and — when it is approved and the door is on — whether it answers as this organisation's public door, whether each framing website is valid, what is marked to appear on the public website today, whether the portal's own address is set, and whether the public door is on, with its addresses. Of your own website it fetches only your main website's front page, and never looks for the door there, so it cannot say whether your website really shows the door. It changes no setting.</p>
            <p class="small text-muted mb-2">The portal's own address is <?php echo PortalAddress::state() === PortalAddress::STATE_SET ? 'set' : 'not set'; ?>.</p>
            <form method="post" action="<?php echo $esc(Site::url('admin/public-website/check')); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $esc($csrf); ?>">
                <button type="submit" class="btn btn-outline-primary btn-sm">Check my set-up</button>
            </form>
        </div>
    </div>

    <?php if (App::isAdmin() === true): ?>
    <p class="small text-muted">
        <?php // The page that hands this job to somebody else, said where it is. Drawn only for an administrator, who can open both pages it links to. ?>
        A global administrator can let somebody else use this page by giving them the role "Website Set-up" on the
        <a href="<?php echo $esc(Site::url('admin/users')); ?>">Users</a> page, or by choosing which roles open
        "Set up the public website" on the <a href="<?php echo $esc(Site::url('admin/roles')); ?>">Roles</a> page.
    </p>
    <?php endif; ?>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
