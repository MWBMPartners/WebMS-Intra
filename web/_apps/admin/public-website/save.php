<?php
// Path: _apps/admin/public-website/save.php
/**
 * -----------------------------------------------------------------------------
 * Admin → Public website: the save handler 💾 (#493, package P3)
 * -----------------------------------------------------------------------------
 * Address: /admin/public-website/save (POST only). The write half of
 * `admin/public-website/index.php`.
 *
 * The SAME gate as the page (`PublicWebsiteSetup::maySetUp()`), asked again
 * here because the page is never the boundary; a refusal is recorded. Every
 * value is checked, refused with its reason, and recorded by
 * `Portal\Core\PublicWebsiteSetup` — this file reads the form, hands the work
 * over, and says what happened. The installation's switch also needs the
 * installation's global administrator, asked here AND in the class.
 *
 * ⚠️ ASK WHETHER A FIELD ARRIVED (#677). A post with no `value` field at all is
 *    refused rather than read as "empty", because empty DELETES a setting.
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
use Portal\Core\Logger;
use Portal\Core\PublicWebsiteSetup;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$siteId  = Site::id();
$userId  = (int) ($_SESSION['user_id'] ?? 0);
$actorId = $userId > 0 ? $userId : null;
$back    = Site::url('admin/public-website');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $back, true, 302);
    exit();
}

// 🚪 THE ONE GATE (D-085), asked again: anything can be posted.
if (PublicWebsiteSetup::maySetUp($mysqli, $userId, $siteId) === false) {
    Logger::activity(
        'PublicWebsiteSetupRefused',
        'Refused: a change on Admin, Public website for organisation #' . $siteId . ' by somebody who may not set up its public website.',
        $actorId
    );
    Router::renderError(403);
    return;
}

$finish = static function (string $message, string $type) use ($back): never {
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . $back, true, 302);
    exit();
};

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $finish('Invalid or expired form token. Nothing was changed. Please try again.', 'danger');
}

$action = is_string($_POST['action'] ?? null) === true ? $_POST['action'] : '';

// "Add your main website to the list": the current list plus the main website,
// through the SAME save as typing it, so it is judged the same way.
if ($action === 'frame_add_main') {
    $website = PublicWebsiteSetup::websiteAddressFor($mysqli, $siteId);
    if ($website === '') {
        $finish('No main website is recorded, so there is nothing to add. Nothing was changed.', 'danger');
    }
    $list   = PublicWebsiteSetup::frameOriginsStored($mysqli, $siteId);
    $list[] = 'https://' . substr($website, (int) strpos($website, '://') + 3);
    $out    = PublicWebsiteSetup::saveFrameOrigins($mysqli, $siteId, implode("\n", $list), $actorId);
    $finish($out['message'], $out['type']);
}

if (array_key_exists('value', $_POST) === false || is_string($_POST['value']) === false) {
    $finish('That form did not arrive whole. Nothing was changed.', 'danger');
}
$value = $_POST['value'];

if ($action === 'website') {
    $out = PublicWebsiteSetup::saveWebsiteAddress($mysqli, $siteId, $value, $actorId);
} elseif ($action === 'public_host') {
    $out = PublicWebsiteSetup::savePublicHost($mysqli, $siteId, $value, $actorId, (string) ($_SERVER['HTTP_HOST'] ?? ''));
} elseif ($action === 'frame_origins') {
    $out = PublicWebsiteSetup::saveFrameOrigins($mysqli, $siteId, $value, $actorId);
} elseif ($action === 'frame_permitted') {
    $out = PublicWebsiteSetup::saveFramingPermitted($mysqli, $value, App::isRootAdmin(), $actorId);
} else {
    $finish('That form was not recognised. Nothing was changed.', 'danger');
}

$finish($out['message'], $out['type']);
