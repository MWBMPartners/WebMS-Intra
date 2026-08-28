<?php
// Path: public_html/directory/me.php
/**
 * Member Directory — user edits own profile + per-field visibility.
 *
 * @package   Portal\Directory
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/261
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

// 📍 #456 Chunk B — coords capture on the owner edit surface. Needs both
// the input partial (the compact lat/lng/w3w trio + lookup button) and
// the map-assets partial (the JS that actually wires the lookup button +
// W3W autosuggest — the partial that emits `data-geo-lookup`/
// `data-w3w-suggest` attributes never binds behaviour to them itself).
require_once PORTAL_CORE . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'location-input.php';
require_once PORTAL_CORE . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'location-map-assets.php';

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$u = null;
$stmt = $db->prepare(
    'SELECT displayBio, displayPhone, displayAddress, latitude, longitude, what3words, visibilityCoords, '
    . '       visibilityName, visibilityRoles, visibilityEmail, visibilityPhone, visibilityAddress, '
    . '       visibilityBio, visibilityPhoto '
    . 'FROM tblUsers WHERE userID = ? LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$pageTitle   = 'My directory profile';
$pageSection = 'directory';
$breadcrumbs = ['Dashboard' => '/', 'Directory' => '/directory', 'My profile' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();

$visRow = function (string $field, string $label, string $current, string $help = '') use ($csrf): void {
    $opts = ['private' => 'Private (just me + admins)', 'team' => 'Team-mates', 'members' => 'All members', 'public' => 'Public (no login)'];
    echo '<div class="row mb-2 align-items-center"><div class="col-md-4"><label class="form-label small">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</label></div>'
       . '<div class="col-md-5"><select name="' . htmlspecialchars($field, ENT_QUOTES, 'UTF-8') . '" class="form-select form-select-sm">';
    foreach ($opts as $val => $optLabel) {
        $sel = $val === $current ? ' selected' : '';
        echo '<option value="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>' . htmlspecialchars($optLabel, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    echo '</select></div>';
    if ($help !== '') {
        echo '<div class="col-md-3 small text-muted">' . htmlspecialchars($help, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    echo '</div>';
};
?>

<h1 class="mb-3"><i class="fa-solid fa-user-pen me-2"></i>My directory profile</h1>
<p class="text-muted">Update what you share and who sees it.</p>

<form method="post" action="/directory/save">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">About me</h2>
            <div class="mb-2"><label class="form-label small">Short bio (markdown supported)</label>
                <textarea name="displayBio" class="form-control form-control-sm" rows="3"><?php echo htmlspecialchars((string) ($u['displayBio'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
            <div class="mb-2"><label class="form-label small">Phone</label>
                <input type="tel" name="displayPhone" class="form-control form-control-sm" maxlength="50" value="<?php echo htmlspecialchars((string) ($u['displayPhone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="mb-2"><label class="form-label small">Address</label>
                <textarea name="displayAddress" class="form-control form-control-sm" rows="2"><?php echo htmlspecialchars((string) ($u['displayAddress'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
            <div class="mb-2">
                <p class="small text-muted mb-2"><?php echo htmlspecialchars(t('location.coords_privacy_hint'), ENT_QUOTES, 'UTF-8'); ?></p>
                <?php
                // 📍 #456 Chunk B — compact coords/W3W trio only (no
                // structured address fields here — the free-text
                // `displayAddress` textarea above stays the address
                // capture). The lookup button geocodes the member's OWN
                // typed address text — an explicit user action, so it is
                // wired regardless of the site-wide `geo.autoGeocode`
                // setting (which only governs SILENT auto-geocoding on
                // save elsewhere, e.g. Venues::saveVenue()).
                portal_location_input([
                    'values' => [
                        'lat' => ($u['latitude']   ?? null) !== null ? (string) $u['latitude']   : '',
                        'lng' => ($u['longitude']  ?? null) !== null ? (string) $u['longitude']  : '',
                        'w3w' => ($u['what3words'] ?? null) !== null ? (string) $u['what3words'] : '',
                    ],
                    'showAddress'         => false,
                    'compact'             => true,
                    'lookup'              => true,
                    'w3wSuggest'          => true,
                    'lookupAddressField'  => 'displayAddress',
                ]);
                ?>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Who sees what</h2>
            <?php
            $visRow('visibilityName',    'Name',              (string) ($u['visibilityName']    ?? 'members'));
            $visRow('visibilityEmail',   'Email',             (string) ($u['visibilityEmail']   ?? 'private'));
            $visRow('visibilityPhone',   'Phone',             (string) ($u['visibilityPhone']   ?? 'private'));
            $visRow('visibilityAddress', 'Address',           (string) ($u['visibilityAddress'] ?? 'private'));
            $visRow('visibilityCoords',  t('location.visibility_coords'), (string) ($u['visibilityCoords'] ?? 'private'), 'A map pin is stricter than the address text above — set separately.');
            $visRow('visibilityBio',     'Bio',               (string) ($u['visibilityBio']     ?? 'members'));
            $visRow('visibilityRoles',   'Roles / leadership',(string) ($u['visibilityRoles']   ?? 'members'));
            ?>
        </div>
    </div>

    <button type="submit" class="btn btn-primary">Save</button>
    <a href="/directory" class="btn btn-outline-secondary">Cancel</a>
</form>

<?php
portal_location_map_assets(App::cspNonce());
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
