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
use Portal\Core\Site;

// 🛡️ Umbrella admin only
if (App::isUmbrellaAdmin() === false) {
    http_response_code(403);
    echo 'Access denied. Umbrella admin privileges required.';
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

// 📋 Check for flash messages
$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError   = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// 🎨 Page setup
$pageTitle   = 'Site Management';
$pageSection = 'admin';
$breadcrumbs = ['Admin' => '/admin', 'Sites' => ''];

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

<?php if ($flashSuccess !== ''): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fa-solid fa-check-circle me-1"></i>
    <?php echo htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<?php if ($flashError !== ''): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fa-solid fa-triangle-exclamation me-1"></i>
    <?php echo htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
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
    $sTz        = htmlspecialchars($site['timezone'] ?? 'UTC', ENT_QUOTES, 'UTF-8');
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
            </div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5">Key</dt>
                    <dd class="col-7"><code><?php echo $sKey; ?></code></dd>

                    <dt class="col-5">Host Pattern</dt>
                    <dd class="col-7"><?php echo $sHost; ?></dd>

                    <dt class="col-5">Timezone</dt>
                    <dd class="col-7"><?php echo $sTz; ?></dd>

                    <dt class="col-5">Copyright</dt>
                    <dd class="col-7"><?php echo $sCopyright; ?></dd>

                    <dt class="col-5">Users</dt>
                    <dd class="col-7">
                        <a href="/admin/sites/users?site=<?php echo $sId; ?>"><?php echo $sUsers; ?> user<?php echo ($sUsers !== 1) ? 's' : ''; ?></a>
                    </dd>
                </dl>
            </div>
            <div class="card-footer d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-primary"
                        data-bs-toggle="modal" data-bs-target="#siteModal"
                        onclick="editSite(<?php echo htmlspecialchars(json_encode($site, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)">
                    <i class="fa-solid fa-pen me-1"></i> Edit
                </button>
                <a href="/admin/sites/users?site=<?php echo $sId; ?>" class="btn btn-sm btn-outline-secondary">
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
    <div class="modal-dialog">
        <form method="post" action="/admin/sites/save" class="modal-content">
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
                    <label for="formHostPattern" class="form-label">Host Pattern</label>
                    <input type="text" class="form-control" id="formHostPattern" name="hostPattern" maxlength="255">
                    <div class="form-text">For subdomain detection (e.g. <code>cambridge.portal.example.com</code>)</div>
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
                               name="primaryColor" value="#0d6efd">
                    </div>
                </div>
                <div class="mb-3">
                    <label for="formCopyrightOrg" class="form-label">Copyright Organisation</label>
                    <input type="text" class="form-control" id="formCopyrightOrg" name="copyrightOrg" maxlength="255">
                </div>
                <div class="mb-3">
                    <label for="formTimezone" class="form-label">Timezone</label>
                    <select class="form-select" id="formTimezone" name="timezone">
                        <?php
                        $tzList = timezone_identifiers_list();
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
    document.getElementById('formPrimaryColor').value = '#0d6efd';
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
    document.getElementById('formPrimaryColor').value = site.primaryColor || '#0d6efd';
    document.getElementById('formCopyrightOrg').value = site.copyrightOrg || '';
    document.getElementById('formTimezone').value = site.timezone || 'UTC';
    document.getElementById('formIsActive').checked = (String(site.isActive) === '1');
    document.getElementById('modalTitleText').textContent = 'Edit Site';
}
</script>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
