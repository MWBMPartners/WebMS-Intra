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
 * THE PUBLIC DOOR (#493 P4a, D-097): `door_on`, `door_replace` and `door_off`
 * hand over to `PublicWebsiteSetup`'s three writers. The last two stop every
 * copy pasted into a website, so they need the `understood` tick, asked here.
 *
 * THE PUBLIC DOOR ON A TEST COPY (#493 P4d, D-087): `door_test_limit` (the
 * installation's ceiling, its global administrator only — `App::isRootAdmin()`
 * is passed and the CLASS refuses) and `door_test_choice` (the organisation's
 * choice within it — the class asks, from the database, whether this person
 * is the organisation's global administrator, and refuses "open to anybody"
 * while the ceiling forbids it). Both are refused on the live portal. Every
 * refusal and every save is recorded by the class. Like every other `value`
 * here, a post with no `value` at all is refused, never read as a choice.
 *
 * AN EVENT WITH NO END TIME (#882, D-129): `no_end_listing` saves how long
 * such an event stays on the public website's list — `mode` 'day' or 'fixed',
 * with `hours` and `minutes` for a fixed time — through
 * `PublicWebsiteSetup::saveNoEndListing()`, which checks the bounds.
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

// ✅ D-101: approving or withdrawing approval of ANY organisation's public
//    address — the installation's global administrator only (the
//    installation and an organisation may be different parties). The CLASS is
//    the one refusal: this handler passes App::isRootAdmin() and asks nothing
//    itself, so the rule lives in one place; the class records every refusal.
//    (An earlier comment said it was asked here as well; it was not.)
if ($action === 'public_host_approve' || $action === 'public_host_withdraw') {
    $target = is_string($_POST['site'] ?? null) === true && preg_match('/^[1-9][0-9]{0,9}$/D', $_POST['site']) === 1 ? (int) $_POST['site'] : 0;
    $host   = is_string($_POST['host'] ?? null) === true ? $_POST['host'] : '';
    if ($target <= 0) {
        $finish('That form did not arrive whole. Nothing was changed.', 'danger');
    }
    $out = $action === 'public_host_approve'
        ? PublicWebsiteSetup::approvePublicHost($mysqli, $target, $host, App::isRootAdmin(), $actorId)
        : PublicWebsiteSetup::withdrawPublicHostApproval($mysqli, $target, App::isRootAdmin(), $actorId);
    $finish($out['message'], $out['type']);
}

// 🚪 The public door (#493 P4a, D-097): on, a new name, off. Each writer
//    records what it did, and refuses with its reason. Replacing the name and
//    switching the door off both stop every copy pasted into a website, so the
//    form carries a tick-box saying the person understands, and a post without
//    it changes nothing — asked HERE, because anything can be posted.
if ($action === 'door_on' || $action === 'door_replace' || $action === 'door_off') {
    if ($action !== 'door_on' && ($_POST['understood'] ?? null) !== '1') {
        // Recorded like every other refusal on this page (round 1 of P4a's check).
        Logger::activity(
            'PublicWebsiteSetupRefused',
            'Refused: a change to organisation #' . $siteId . '\'s public door (' . ($action === 'door_off' ? 'switch off' : 'replace the name')
            . '): the "I understand" tick was not given. Nothing was changed.',
            $actorId
        );
        // The words must match the card's own tick (round 2 of P4a's check):
        // with a public address set, replacing the name does NOT stop copies
        // that use it.
        // The SAME question the card asks (round 7 of P4a's check): an
        // unapproved address is not the door, so it changes nothing here.
        $finish($action === 'door_replace' && PublicWebsiteSetup::publicHostInEffect($mysqli, $siteId) !== ''
            ? 'Nothing was changed: tick the box to say you understand that copies using the old name on the portal\'s own '
                . 'address will stop working, and copies using your public address will not.'
            : 'Nothing was changed: tick the box to say you understand that every copy pasted into a website will stop working.', 'danger');
    }
    $out = match ($action) {
        'door_on'      => PublicWebsiteSetup::turnDoorOn($mysqli, $siteId, $actorId),
        'door_replace' => PublicWebsiteSetup::replaceDoorName($mysqli, $siteId, $actorId),
        default        => PublicWebsiteSetup::turnDoorOff($mysqli, $siteId, $actorId),
    };
    $finish($out['message'], $out['type']);
}

// ⏱️ D-129 (#882): how long an event with no end time stays on the public
//    website's list. Its own fields (`mode`, and for a fixed time `hours` and
//    `minutes`), each passed ONLY when it arrived as text — a missing field
//    is never read as empty (#677); the class refuses a fixed time whose
//    boxes did not both arrive. The same gate as everything else here
//    (maySetUp(), above); every save and refusal recorded by the class.
if ($action === 'no_end_listing') {
    // A missing `mode` is passed on as null, so the class refuses AND records
    // it like any other refusal (round 1 of #882's check).
    $field = static fn (string $name): ?string => is_string($_POST[$name] ?? null) === true ? $_POST[$name] : null;
    $out   = PublicWebsiteSetup::saveNoEndListing($mysqli, $siteId, $field('mode'), $field('hours'), $field('minutes'), $actorId);
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
} elseif ($action === 'door_test_limit') {
    $out = PublicWebsiteSetup::saveTestCopyLimit($mysqli, $value, App::isRootAdmin(), $actorId);
} elseif ($action === 'door_test_choice') {
    $out = PublicWebsiteSetup::saveTestCopyChoice($mysqli, $siteId, $value, $actorId);
} else {
    $finish('That form was not recognised. Nothing was changed.', 'danger');
}

$finish($out['message'], $out['type']);
