<?php
// Path: _apps/admin/settings/outside-services/save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Save the outside-services choices 💾 (#778)
 * -----------------------------------------------------------------------------
 * The form target for /admin/settings/outside-services. POST only, form token
 * FIRST (so a forged request from another website cannot even fill the
 * activity log), administrators only, and the organisation is always
 * `Site::id()` — never anything posted.
 *
 * Two actions:
 *   installation  a global administrator only, checked here AND again by
 *                 `OutsideServices::saveForInstallation()`;
 *   organisation  the open organisation's own values.
 *
 * ONLY FIELDS THAT ARRIVED ARE PASSED ON (#677). A missing field is "leave it
 * alone", never an empty value: for an organisation an empty or missing value
 * would otherwise read as a decision. The writer refuses the whole save if any
 * value is outside its list, and refuses an organisation value looser than the
 * installation with the reason (never quietly lowering it, #668).
 *
 * Every save and every refusal that got past the form token is written to the
 * activity log: this decides what outside companies learn about members, so
 * there must be a record of who changed it and from what.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/778
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\OutsideServices;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$back = Site::url('admin/settings/outside-services');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $back);
    exit();
}

// 🔐 Form token FIRST, before anything with a side effect, the log included.
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Nothing was changed. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $back);
    exit();
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$action = is_string($_POST['action'] ?? null) === true ? $_POST['action'] : '';
$prefix = $action === 'installation' ? 'installation_' : 'organisation_';

// 📥 Ask whether each field ARRIVED, never what it contains (#677). A value
//    that is not text (an array posted on purpose) is passed on as '' so the
//    writer refuses the whole save rather than this file guessing.
$posted = [];
foreach (array_keys(OutsideServices::SERVICES) as $service) {
    if (array_key_exists($prefix . $service, $_POST) === true) {
        $posted[$service] = is_string($_POST[$prefix . $service]) === true ? $_POST[$prefix . $service] : '';
    }
}

if ($action === 'installation') {
    $outcome = OutsideServices::saveForInstallation($mysqli, $posted, App::isRootAdmin());
    Logger::activity(
        $outcome['saved'] === true ? 'OutsideServiceInstallationSaved' : 'OutsideServiceInstallationRefused',
        $outcome['saved'] === true
            ? 'Outside services for the whole installation saved. '
              . ($outcome['changes'] === [] ? 'Nothing was different.' : 'Changed ' . implode('; ', $outcome['changes']) . '.')
            : 'A change to the whole installation\'s outside services was refused and nothing was saved: ' . $outcome['message'],
        $userId > 0 ? $userId : null
    );
} elseif ($action === 'organisation') {
    $outcome = OutsideServices::saveForOrganisation($mysqli, $siteId, $posted);
    Logger::activity(
        $outcome['saved'] === true ? 'OutsideServiceOrganisationSaved' : 'OutsideServiceOrganisationRefused',
        $outcome['saved'] === true
            ? 'Organisation ' . $siteId . ': outside services saved. '
              . ($outcome['changes'] === [] ? 'Nothing was different.' : 'Changed ' . implode('; ', $outcome['changes']) . '.')
            : 'Organisation ' . $siteId . ': a change to outside services was refused and nothing was saved: ' . $outcome['message'],
        $userId > 0 ? $userId : null
    );
} else {
    $outcome = ['saved' => false, 'type' => 'danger', 'message' => 'That form was not recognised. Nothing was saved.', 'changes' => []];
}

$_SESSION['flash_msg']  = $outcome['message'];
$_SESSION['flash_type'] = $outcome['type'];
header('Location: ' . $back);
exit();
