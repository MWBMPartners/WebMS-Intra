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
use Portal\Core\Channel;
use Portal\Core\Gatekeeper;
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
$publicHostApproved = PublicWebsiteSetup::publicHostApproved($mysqli, $siteId);
$publicHostInEffect = PublicWebsiteSetup::publicHostInEffect($mysqli, $siteId);
$origins     = PublicWebsiteSetup::frameOriginsStored($mysqli, $siteId);
$permitted   = PublicWebsiteSetup::framingPermitted($mysqli);
$isRootAdmin = App::isRootAdmin();
$door        = PublicWebsiteSetup::doorStateFor($mysqli, $siteId);
$doorEvents  = $door['state'] === 'on' ? PublicDoor::addressFor($mysqli, $siteId, PublicDoor::SURFACE_EVENTS) : '';
$doorPosters = $door['state'] === 'on' ? PublicDoor::addressFor($mysqli, $siteId, PublicDoor::SURFACE_POSTERS) : '';
// D-087 on a test copy: the door answers signed-in testers only in this
// version (nothing can choose "anybody" until package P4d), and the card says
// what that means for trying it from a real website.
$portalChannelSource = defined('PORTAL_ENV_SOURCE') === true ? (string) PORTAL_ENV_SOURCE : '';
// The channel is asked FIRST: on a live portal shouldEnforce() writes a line to
// the error log when the channel was only assumed, and index.php has already
// written it for this request.
$doorTestersOnly     = in_array(PORTAL_ENV, Channel::PRE_RELEASE, true) === true
    && Gatekeeper::shouldEnforce(PORTAL_ENV, $portalChannelSource) === true
    && PublicWebsiteSetup::testCopyDoorOpenToAnybody($mysqli, $siteId) === false;

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
                <?php endif; ?>
                <?php if ($doorTestersOnly === true): ?>
                    <div class="alert alert-warning" data-door-testers-only="1">This is a test copy of the portal, so its public door answers only people signed in here who are allowed to use the test copy. Browsers do not send your sign-in to another website, so a copy pasted into a real website shows nothing from this test copy; open the addresses above in this browser instead.</div>
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

    <?php if ($checkRows !== null): ?>
        <div class="card mb-3 border-primary" data-setup-check="1">
            <div class="card-body">
                <h2 class="h5">What the set-up check found</h2>
                <p class="small text-muted">Shown once. It changed no setting, and nothing here is a promise that your website shows the public door — this check does not fetch it.</p>
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
                <p class="mt-2 mb-0" data-public-host-approval="<?php echo $publicHostInEffect !== '' ? 'approved' : ($publicHostApproved === true ? 'approved-members-address' : 'waiting'); ?>">
                    <?php if ($publicHostInEffect !== ''): ?>
                        <span class="badge text-bg-success me-1">Approved</span> The installation's administrator has approved this address, so the public door answers on it while the door is on.
                    <?php elseif ($publicHostApproved === true): ?>
                        <span class="badge text-bg-warning me-1">Approved, not in use</span> The installation's administrator approved this address, but it is also an address members sign in at, so the public door does <strong>not</strong> answer on it. Change it to a name members do not use.
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
                            <li class="mb-2" data-public-host-row="<?php echo (int) $one['siteId']; ?>">
                                <span class="badge <?php echo $one['approved'] === true ? 'text-bg-success' : 'text-bg-warning'; ?> me-1"><?php echo $one['approved'] === true ? 'Approved' : 'Waiting'; ?></span>
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
            <p class="text-muted">Checks what can be known today: whether your main website answers over https (a website at a port other than the standard https one is not fetched), whether your public address's name exists, whether each framing website is valid, what is marked to appear on the public website today, whether the portal's own address is set, and whether the public door is on, with its addresses. It does not fetch the door, so it cannot say whether your website really shows it. It changes no setting.</p>
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
