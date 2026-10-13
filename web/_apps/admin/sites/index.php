<?php
// Path: public_html/admin/sites/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Site Management 🌐
 * -----------------------------------------------------------------------------
 * Lists all sites (active and inactive) for umbrella admins.
 * Provides create/edit forms and quick-glance site info cards.
 *
 * @package   Portal\App\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.9.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/45
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\ReservedKeys;
use Portal\Core\Site;

// 🛡️ Umbrella admin only
if (App::isUmbrellaAdmin() === false) {
    http_response_code(403);
    echo t('error.umbrella_admin_only');
    exit();
}

$db = App::db();

// 📋 Fetch all sites with user counts
$sites = Site::all($db);

// 📊 Get user counts per site
$userCounts = [];
$countResult = $db->query(
    'SELECT siteID, COUNT(*) AS cnt FROM tblUserSites WHERE isActive = 1 GROUP BY siteID'
);
if ($countResult !== false) {
    while ($row = $countResult->fetch_assoc()) {
        $userCounts[(int) $row['siteID']] = (int) $row['cnt'];
    }
}

// 🔒 #515 — which organisations (if any) carry a key that clashes with an
// address the portal relies on, keyed by siteID so the card loop below can
// look each one up with an O(1) isset() rather than scanning the list per
// card. A failure here must never stop this page rendering — it just means
// the badge is silently absent, same as "no clash" (#508's own lesson: an
// admin page must never crash part way through drawing itself).
$clashMap = [];
try {
    foreach (ReservedKeys::clashingSites($db) as $clash) {
        $clashMap[$clash['siteID']] = $clash;
    }
} catch (\Throwable) {
    $clashMap = [];
}

// 📋 Check for flash messages
$flashMsg  = $_SESSION['flash_msg'] ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

// 🎨 Page setup
$pageTitle   = 'Site Management';
$pageSection = 'admin';
$breadcrumbs = ['Admin' => Site::url('admin'), 'Sites' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="fa-solid fa-sitemap me-2"></i> Site Management
    </h1>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#siteModal"
            onclick="resetSiteForm()">
        <i class="fa-solid fa-plus me-1"></i> New Site
    </button>
</div>
<noscript>
    <div class="alert alert-warning">
        <i class="fa-solid fa-triangle-exclamation me-1"></i>
        <strong>JavaScript is disabled.</strong> The New Site and Edit buttons require JavaScript to open modal dialogs. Enable JavaScript for full site management functionality.
    </div>
</noscript>

<?php if ($flashMsg !== ''): ?>
<div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show" role="alert">
    <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<?php
// 🌐 #502 — how this installation tells its organisations apart, and the way
// to the page that changes it. Taken from Site, which holds what the portal
// worked out when this request started.
$setupLabel = Site::isMultisiteEnabled() === true
    ? (Site::MODE_LABELS[Site::detectionMode()] ?? Site::detectionMode())
    : 'One organisation only (multi-organisation is switched off)';
?>
<div class="alert alert-secondary d-flex flex-wrap align-items-center gap-2" role="note">
    <span class="me-auto">
        <i class="fa-solid fa-route me-1"></i>
        <strong>How organisations are told apart:</strong>
        <?php echo htmlspecialchars($setupLabel, ENT_QUOTES, 'UTF-8'); ?>
    </span>
    <a class="btn btn-sm btn-outline-primary" href="<?php echo htmlspecialchars(Site::url('admin/sites/setup'), ENT_QUOTES, 'UTF-8'); ?>">
        <i class="fa-solid fa-sliders me-1"></i> Change
    </a>
</div>

<?php
// 🚧 Before migration 217 has run, this page does not take a time zone
//    (#604): see admin/sites/save.php. It says so, with the way to the
//    upgrade, and each card shows the zone the portal is really using.
$zonesWait = Site::timezoneFacts()['inRow'] === false;
?>
<?php if ($zonesWait === true): ?>
<div class="alert alert-warning" role="alert" id="zones-wait-for-upgrade">
    <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
    <strong>Time zones cannot be chosen here yet.</strong>
    This installation has new files and has not yet run their database upgrade. Until it has, each organisation's time zone is still read from the old setting, and a zone chosen here would not be used.
    <a class="alert-link" href="<?php echo htmlspecialchars(Site::url('admin/upgrade'), ENT_QUOTES, 'UTF-8'); ?>">Run the upgrade first</a>, then choose time zones here.
</div>
<?php endif; ?>

<?php if (count($sites) === 0): ?>
<div class="alert alert-info">
    <i class="fa-solid fa-info-circle me-1"></i> No sites configured yet.
</div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($sites as $site): ?>
    <?php
    $sId        = (int) $site['siteID'];
    $sName      = htmlspecialchars($site['siteName'], ENT_QUOTES, 'UTF-8');
    $sKey       = htmlspecialchars($site['siteKey'], ENT_QUOTES, 'UTF-8');
    $sHost      = htmlspecialchars($site['hostPattern'] ?? '—', ENT_QUOTES, 'UTF-8');
    $sColor     = htmlspecialchars($site['primaryColor'] ?? '#0d6efd', ENT_QUOTES, 'UTF-8');
    $sCopyright = htmlspecialchars($site['copyrightOrg'] ?? '—', ENT_QUOTES, 'UTF-8');
    // 🕰️ The zone exactly as stored, and whether it is a zone name (#604).
    //    Site::timezone() answers UTC for stored text that is not one, so the
    //    card says so instead of showing text the portal is not using.
    //    Before migration 217 has run the row is not what the portal uses, so
    //    the card shows the zone it IS using and no warning about the row.
    $sTzStored  = $zonesWait === true ? Site::timezone($sId) : (string) ($site['timezone'] ?? '');
    $sTzKnown   = Site::isZoneName($sTzStored);
    $sTzInDoubt = $sTzKnown === false && Site::phpCanOpenZone($sTzStored) === true;
    $sTz        = htmlspecialchars($sTzStored, ENT_QUOTES, 'UTF-8');
    $sActive    = ((string) $site['isActive'] === '1');
    $sUsers     = $userCounts[$sId] ?? 0;
    ?>
    <div class="col-md-6 col-xl-4">
        <div class="card h-100<?php echo ($sActive === false) ? ' border-secondary opacity-75' : ''; ?>">
            <div class="card-header d-flex align-items-center gap-2" style="border-left: 4px solid <?php echo $sColor; ?>">
                <strong><?php echo $sName; ?></strong>
                <?php if ($sActive === false): ?>
                <span class="badge bg-secondary ms-auto">Inactive</span>
                <?php else: ?>
                <span class="badge bg-success ms-auto">Active</span>
                <?php endif; ?>
                <?php if (isset($clashMap[$sId])): ?>
                <!-- 🔒 #515 — this organisation's key is the same as an address the portal itself relies on. -->
                <span class="badge bg-warning text-dark">Key is reserved</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5">Key</dt>
                    <dd class="col-7">
                        <code><?php echo $sKey; ?></code>
                        <?php if (isset($clashMap[$sId])): ?>
                        <div class="small text-warning-emphasis">
                            <?php echo htmlspecialchars(
                                ReservedKeys::describe($clashMap[$sId]['siteKey'], $clashMap[$sId]['kinds']),
                                ENT_QUOTES,
                                'UTF-8'
                            ); ?>
                        </div>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-5">Own address</dt>
                    <dd class="col-7"><?php echo $sHost; ?></dd>

                    <dt class="col-5">Time zone</dt>
                    <dd class="col-7">
                        <?php if ($sTzKnown === true): ?>
                            <?php echo $sTz; ?>
                        <?php else: ?>
                            <span class="text-danger" data-zone-problem="<?php echo $sTzInDoubt === true ? 'in-doubt' : 'unknown'; ?>">
                                <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                                <?php echo $sTzStored === '' ? 'None chosen' : 'Stored as "' . $sTz . '", which is not a time zone name the portal knows'; ?>.
                                UTC is used. Choose the right one with Edit.
                                <?php if ($sTzInDoubt === true): ?>
                                    Until then, outside calendars with no time zone of their own are not imported for this organisation.
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-5">Copyright</dt>
                    <dd class="col-7"><?php echo $sCopyright; ?></dd>

                    <dt class="col-5">Users</dt>
                    <dd class="col-7">
                        <a href="<?php echo htmlspecialchars(Site::url('admin/sites/users'), ENT_QUOTES, 'UTF-8'); ?>?site=<?php echo $sId; ?>"><?php echo $sUsers; ?> user<?php echo ($sUsers !== 1) ? 's' : ''; ?></a>
                    </dd>
                </dl>
            </div>
            <div class="card-footer d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-primary"
                        data-bs-toggle="modal" data-bs-target="#siteModal"
                        onclick="editSite(<?php echo htmlspecialchars(json_encode($site, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)">
                    <i class="fa-solid fa-pen me-1"></i> Edit
                </button>
                <a href="<?php echo htmlspecialchars(Site::url('admin/sites/users'), ENT_QUOTES, 'UTF-8'); ?>?site=<?php echo $sId; ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="fa-solid fa-users me-1"></i> Users
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- 📝 Create/Edit Site Modal -->
<div class="modal fade" id="siteModal" tabindex="-1" aria-labelledby="siteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen-sm-down">
        <form method="post" action="<?php echo htmlspecialchars(Site::url('admin/sites/save'), ENT_QUOTES, 'UTF-8'); ?>" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="siteID" id="formSiteID" value="">

            <div class="modal-header">
                <h5 class="modal-title" id="siteModalLabel">
                    <i class="fa-solid fa-sitemap me-1"></i> <span id="modalTitleText">New Site</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="mb-3">
                    <label for="formSiteName" class="form-label">Site Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="formSiteName" name="siteName" required maxlength="255">
                </div>
                <div class="mb-3">
                    <label for="formSiteKey" class="form-label">Site Key <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="formSiteKey" name="siteKey" required maxlength="50"
                           pattern="[a-z0-9\-]+" title="Lowercase letters, numbers, and hyphens only">
                    <div class="form-text">Machine-readable slug (e.g. <code>cambridge</code>, <code>leeds</code>)</div>
                </div>
                <div class="mb-3">
                    <label for="formHostPattern" class="form-label">Own address</label>
                    <input type="text" class="form-control" id="formHostPattern" name="hostPattern" maxlength="253"
                           autocapitalize="none" spellcheck="false">
                    <div class="form-text">Only used when each organisation has an address of its own. A plain host name, with no
                        <code>https://</code> and no <code>/</code>. Example: <code>cambridge.portal.example.org</code>. No two
                        organisations may share one.</div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="formLogoPath" class="form-label">Logo Path</label>
                        <input type="text" class="form-control" id="formLogoPath" name="logoPath" maxlength="500"
                               value="/assets/images/logo.svg">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="formPrimaryColor" class="form-label">Primary Colour</label>
                        <input type="color" class="form-control form-control-color" id="formPrimaryColor"
                               name="primaryColor" value="#5e6ad2">
                    </div>
                </div>
                <div class="mb-3">
                    <label for="formFaviconPath" class="form-label">Favicon Path</label>
                    <input type="text" class="form-control" id="formFaviconPath" name="faviconPath" maxlength="500"
                           placeholder="/assets/images/favicon.ico">
                    <div class="form-text">URL or path to the favicon. Leave blank to use the default.</div>
                </div>
                <div class="mb-3">
                    <label for="formCopyrightOrg" class="form-label">Copyright Organisation</label>
                    <input type="text" class="form-control" id="formCopyrightOrg" name="copyrightOrg" maxlength="255">
                </div>
                <div class="mb-3">
                    <label for="formTimezone" class="form-label">Time zone</label>
                    <?php
                    // 🕰️ A list of real zone names (Site::zoneNames(), the list
                    //    Site::isZoneName() tests against). The first, empty
                    //    choice is what shows for an organisation whose stored
                    //    zone is not on the list: the box is required, so a
                    //    zone has to be CHOSEN before anything can be saved,
                    //    and the save handler refuses an empty one too. It
                    //    used to show nothing selected and save "UTC".
                    ?>
                    <select class="form-select" id="formTimezone" name="timezone" <?php echo $zonesWait === true ? 'disabled' : 'required'; ?>>
                        <option value="">Choose a time zone…</option>
                        <?php
                        $tzList = Site::zoneNames();
                        foreach ($tzList as $tz) {
                            echo '<option value="' . htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') . '">'
                                . htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') . '</option>';
                        }
                        ?>
                    </select>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="formIsActive" name="isActive" value="1" checked>
                    <label class="form-check-label" for="formIsActive">Active</label>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk me-1"></i> Save
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function resetSiteForm() {
    document.getElementById('formSiteID').value = '';
    document.getElementById('formSiteName').value = '';
    document.getElementById('formSiteKey').value = '';
    document.getElementById('formHostPattern').value = '';
    document.getElementById('formLogoPath').value = '/assets/images/logo.svg';
    document.getElementById('formFaviconPath').value = '';
    document.getElementById('formPrimaryColor').value = '#5e6ad2';
    document.getElementById('formCopyrightOrg').value = '';
    document.getElementById('formTimezone').value = 'UTC';
    document.getElementById('formIsActive').checked = true;
    document.getElementById('modalTitleText').textContent = 'New Site';
}

function editSite(site) {
    document.getElementById('formSiteID').value = site.siteID || '';
    document.getElementById('formSiteName').value = site.siteName || '';
    document.getElementById('formSiteKey').value = site.siteKey || '';
    document.getElementById('formHostPattern').value = site.hostPattern || '';
    document.getElementById('formLogoPath').value = site.logoPath || '/assets/images/logo.svg';
    document.getElementById('formFaviconPath').value = site.faviconPath || '';
    document.getElementById('formPrimaryColor').value = site.primaryColor || '#5e6ad2';
    document.getElementById('formCopyrightOrg').value = site.copyrightOrg || '';
    // The stored zone; when it is not on the list this leaves the box on
    // "Choose a time zone…", which has to be answered before saving (#604).
    document.getElementById('formTimezone').value = site.timezone || '';
    if (document.getElementById('formTimezone').selectedIndex < 0) {
        document.getElementById('formTimezone').value = '';
    }
    document.getElementById('formIsActive').checked = (String(site.isActive) === '1');
    document.getElementById('modalTitleText').textContent = 'Edit Site';
}
</script>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
