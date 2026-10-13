<?php
// Path: _apps/admin/settings/organisation/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Organisation / Site HQ address 🏠 (#456 Chunk A)
 * -----------------------------------------------------------------------------
 * The site-wide "where is this organisation" address — feeds public pages
 * / feeds / branding that need a physical HQ location. Stored as nine
 * global (`siteID IS NULL`) `org.*` settings keys (the `portal.sabbath.
 * location_lat/lng` precedent) rather than a `tblSites` column, so a
 * multi-site install gets free per-site overrides via the existing
 * `App::settingForSite()` pattern if ever needed later.
 *
 * THE "WEB ADDRESSES" CARD (#500) is the second form on this page: the
 * portal's own web address (the `site.url` setting, read and judged only by
 * Portal\Core\PortalAddress) and the support e-mail address. Both are for
 * the whole installation. Anybody who may open this page sees them; only a
 * global administrator is shown a Save button, because the save handler
 * refuses anybody else and a button that can only be refused is no button.
 * (The postal address form above it still shows its button to everybody;
 * that is older and was left alone.)
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/456
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\PortalAddress;
use Portal\Core\Router;
use Portal\Core\Site;

require_once PORTAL_CORE . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'location-input.php';
require_once PORTAL_CORE . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'location-display.php';
require_once PORTAL_CORE . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'location-map-assets.php';

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$values = [
    'line1'       => (string) (App::settings('org.address.line1') ?? ''),
    'line2'       => (string) (App::settings('org.address.line2') ?? ''),
    'city'        => (string) (App::settings('org.address.city') ?? ''),
    'region'      => (string) (App::settings('org.address.region') ?? ''),
    'postcode'    => (string) (App::settings('org.address.postcode') ?? ''),
    'countryCode' => (string) (App::settings('org.address.countryCode') ?? 'GB'),
    'lat'         => (string) (App::settings('org.latitude') ?? ''),
    'lng'         => (string) (App::settings('org.longitude') ?? ''),
    'w3w'         => (string) (App::settings('org.what3words') ?? ''),
];
$hasCoords = $values['lat'] !== '' && $values['lng'] !== '';

// 🌐 The "Web addresses" card. Everything about the portal's address comes
//    from PortalAddress, so this card, the dashboard and every e-mailed link
//    go by one answer.
$mayChangeAddresses = App::isRootAdmin();
$addressState       = PortalAddress::state();
$addressSaved       = PortalAddress::saved();
$addressInUse       = PortalAddress::fromRequest();
$addressForHere     = PortalAddress::base();
$ownAddressEach     = Site::isMultisiteEnabled() === true && Site::detectionMode() === Site::MODE_HOST;
$supportEmail       = trim((string) (App::settings('portal.support.email') ?? ''));
$addressesLocked    = $mayChangeAddresses === false ? ' disabled' : '';
$e                  = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');

// 🗺️ Preview map — only when coords are already set (opt-in page-scoped
//    CSP widening, #386 precedent).
if ($hasCoords === true) {
    $cspImgExtra = 'https://*.tile.openstreetmap.org';
}

$pageTitle   = 'Organisation Address';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Admin' => Site::url('admin'), 'Settings' => Site::url('admin/settings'), 'Organisation' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-house-chimney me-2"></i>Organisation Address</h1>
<p class="text-secondary">
    The organisation's physical HQ address — feeds public pages, feeds, and branding that need a
    location for this install.
</p>

<div class="card mb-4">
    <div class="card-body">
        <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/settings/organisation/save'), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <?php portal_location_input([
                'values'     => $values,
                'compact'    => false,
                'lookup'     => true,
                'w3wSuggest' => true,
            ]); ?>
            <div class="mt-3">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i>Save address</button>
            </div>
        </form>
    </div>
</div>

<?php if ($hasCoords === true): ?>
    <div class="card mb-4">
        <div class="card-header">Preview</div>
        <div class="card-body">
            <?php portal_location_display([
                'address' => $values,
                'lat'     => (float) $values['lat'],
                'lng'     => (float) $values['lng'],
                'w3w'     => $values['w3w'] !== '' ? $values['w3w'] : null,
                'showMap' => true,
                'mapId'   => 'orgAddressMap',
            ]); ?>
        </div>
    </div>
<?php endif; ?>

<!-- 🌐 Web addresses (#500). One column on a phone, two from a large screen up;
     the long example addresses may break across lines (text-break) so nothing
     runs off the side of a narrow screen. -->
<div class="card mb-4" id="web-addresses">
    <div class="card-body">
        <h2 class="h5"><i class="fa-solid fa-globe me-2"></i>Web addresses</h2>
        <p class="text-secondary small">
            These two are for the whole installation: every organisation on it uses them.
        </p>

        <?php if ($addressState === PortalAddress::STATE_UNUSABLE): ?>
            <div class="alert alert-warning small" role="alert">
                What is stored as the portal's address is not a usable web address, so the portal is ignoring it.
                Type the address again below and save.
            </div>
        <?php elseif ($addressState === PortalAddress::STATE_UNREADABLE): ?>
            <div class="alert alert-danger small" role="alert">
                The portal's address could not be read from the database just now, so e-mails are being sent without links.
                Try this page again; if it keeps happening, look at Admin, Errors.
            </div>
        <?php elseif ($addressState === PortalAddress::STATE_SET && $addressInUse !== '' && $addressForHere !== $addressInUse): ?>
            <div class="alert alert-warning small text-break" role="alert">
                Links in e-mails begin with <code><?php echo $e($addressForHere); ?></code>,
                but you are using the portal at <code><?php echo $e($addressInUse); ?></code> right now.
                If the second one is right,
                <?php echo $mayChangeAddresses === true ? 'correct the address below' : 'ask a global administrator to correct the address below'; ?><?php echo $ownAddressEach === true ? ', or this organisation\'s own address at Admin, Organisations' : ''; ?>.
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/settings/organisation/save'), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="form" value="web-addresses">
            <div class="row g-3">
                <div class="col-12 col-lg-6">
                    <label for="portalAddress" class="form-label">The address of this portal</label>
                    <input type="text" inputmode="url" class="form-control" id="portalAddress" name="portalAddress"
                           maxlength="255" autocomplete="off" autocapitalize="none" spellcheck="false"
                           value="<?php echo $e($addressSaved); ?>" aria-describedby="portalAddressHelp"<?php echo $addressesLocked; ?>>
                    <div id="portalAddressHelp" class="form-text text-break">
                        The address people type to reach this portal, with nothing after the name:
                        for example <code>https://portal.example.org</code>.
                        Every link in an e-mail the portal sends begins with it.
                        <?php if ($addressSaved === ''): ?>
                            <strong>It is not set.</strong> Until it is, each link uses the address of whatever
                            request caused the e-mail, and a visitor can choose that.
                        <?php endif; ?>
                        <?php if ($addressInUse !== ''): ?>
                            You are using the portal at <code><?php echo $e($addressInUse); ?></code> right now.
                        <?php endif; ?>
                        <?php if ($ownAddressEach === true): ?>
                            Each organisation here has an address of its own (Admin, Organisations). A link for an
                            organisation uses that organisation's name, with the http or https part, and any port,
                            taken from this address.
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-12 col-lg-6">
                    <label for="supportEmail" class="form-label">Support e-mail address</label>
                    <input type="email" class="form-control" id="supportEmail" name="supportEmail" maxlength="254"
                           autocomplete="off" value="<?php echo $e($supportEmail); ?>" aria-describedby="supportEmailHelp"<?php echo $addressesLocked; ?>>
                    <div id="supportEmailHelp" class="form-text">
                        Shown on the Help page "Getting Support" and at the foot of e-mails the portal sends.
                        Leave it empty to show none; the Help page then tells people to ask an administrator.
                    </div>
                </div>
            </div>
            <div class="mt-3">
                <?php if ($mayChangeAddresses === true): ?>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i>Save web addresses</button>
                <?php else: ?>
                    <p class="text-secondary small mb-0">
                        Only a global administrator can change these, because they apply to every organisation on this installation.
                    </p>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<a href="<?php echo htmlspecialchars(Site::url('admin/settings'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Settings</a>

<?php
portal_location_map_assets(App::cspNonce());
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
