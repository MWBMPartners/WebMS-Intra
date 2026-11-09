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
 * ⚠️ NOTHING FROM THE PORTAL IS SHOWN ON ANY OUTSIDE WEBSITE YET. The public
 *    door that will use these settings is package P4, and the page says so at
 *    the top, because a page of settings that seem to do something they do not
 *    is #671's "a lie with a Save button".
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
$origins     = PublicWebsiteSetup::frameOriginsStored($mysqli, $siteId);
$permitted   = PublicWebsiteSetup::framingPermitted($mysqli);
$isRootAdmin = App::isRootAdmin();

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

    <div class="alert alert-info" role="note" data-public-door-note="1">
        <i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i>
        <strong>Nothing from the portal is shown on any outside website yet.</strong>
        These settings are kept ready for the public door — the piece you will paste into your website — which is
        not part of this version. Until it is added, saving here changes nothing anybody outside the portal can see.
    </div>

    <?php if ($flashMsg !== ''): ?>
        <div class="alert alert-<?php echo $esc($flashType); ?>" role="alert"><?php echo $esc($flashMsg); ?></div>
    <?php endif; ?>

    <?php if ($checkRows !== null): ?>
        <div class="card mb-3 border-primary" data-setup-check="1">
            <div class="card-body">
                <h2 class="h5">What the set-up check found</h2>
                <p class="small text-muted">Shown once. It changed no setting, and nothing here is a promise that the public door works — that cannot be tested until it exists.</p>
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
            <p class="text-muted">The website your organisation already has, which the portal does not run — for example https://www.ourchurch.org. The portal's events and posters will appear inside it once the public door is added.</p>
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
                <div class="form-text">Only the name, without https:// in front. Leave it empty and save to remove it.</div>
                <button type="submit" class="btn btn-primary btn-sm mt-2">Save</button>
            </form>
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
            <p class="text-muted">Checks what can be known today: whether your main website answers over https, whether your public address's name exists, whether each framing website is valid, what is marked to appear on the public website today, and whether the portal's own address is set. It cannot test the public door, because that is not part of this version. It changes no setting.</p>
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
