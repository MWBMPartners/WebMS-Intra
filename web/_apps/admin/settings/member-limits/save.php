<?php
// Path: _apps/admin/settings/member-limits/save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Save one member limit 💾 (#989, #992; D-171, D-172; migration 298)
 * -----------------------------------------------------------------------------
 * The form target for /admin/settings/member-limits. POST only, form token
 * first, administrators only, ONE limit per post (see the page for why).
 *
 * THE BOUNDARY BETWEEN TWO PARTIES IS HERE, not in the page (#668):
 *   - only a global administrator may change the installation's figure;
 *   - an organisation's figure above what the installation allows is REFUSED
 *     with the reason, never quietly reduced, and the refusal is recorded;
 *   - the installation's figure is read LIVE and locked inside the
 *     transaction that writes (`SettingLimit::saveAtLevel()`), so a change to
 *     the ceiling and an organisation's save cannot pass each other (#687).
 *
 * "Did the box ARRIVE" is asked before "what does it hold" (#677): an empty
 * organisation box means "follow the installation" and deletes its own row,
 * and reading a field that never arrived as empty would delete it by accident.
 *
 * A gift figure is read by the ONE converter, `Currency::parseAmount()`
 * (#1004): figures only, never a float. The organisation's in its own
 * currency; the installation's as a plain number (it is a number of each
 * organisation's own currency, not converted — see `Giving`).
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/989
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Currency;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\SettingLimit;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$backTo = Site::url('admin/settings/member-limits');
/** Leave with a message. */
$leave = static function (string $message, string $type) use ($backTo): never {
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . $backTo);
    exit();
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $backTo);
    exit();
}
// 🔐 Form token FIRST, before anything with a side effect — the activity log
//    included, so a forged request from another website cannot fill it.
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $leave('Invalid or expired form token. Nothing was changed. Please try again.', 'danger');
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$limits = \Portal\Core\MemberLimits::all();

// A request chooses the TYPE of what it sends as well as the value, so every
// posted field is asked whether it is a string first.
$name  = is_string($_POST['limit'] ?? null) === true ? (string) $_POST['limit'] : '';
$scope = is_string($_POST['scope'] ?? null) === true ? (string) $_POST['scope'] : '';
if (array_key_exists($name, $limits) === false || in_array($scope, ['site', 'global'], true) === false) {
    $leave('That form was not understood, so nothing was changed.', 'danger');
}
$limit = $limits[$name];

// 🚧 Only a global administrator may change what the whole installation allows.
if ($scope === 'global' && App::isRootAdmin() === false) {
    Logger::activity('MemberLimitSaveRefused', 'Refused: the installation-wide figure for ' . $limit['key'] . ' may only be changed by a global administrator', $userId > 0 ? $userId : null);
    $leave('That is the installation-wide figure, which every organisation on this installation follows, not only yours. '
        . 'Only a global administrator can change it. Nothing was changed.', 'danger');
}

// 📨 DID THE BOX ARRIVE? Not "what does it hold" (#677).
if (array_key_exists('value', $_POST) === false || is_string($_POST['value']) === false) {
    $leave('The form did not include a number, so nothing was changed.', 'danger');
}
$typed    = trim((string) $_POST['value']);
$currency = $scope === 'global' ? '' : Currency::ofSite();
$shown    = static fn (int $value): string => $limit['money'] === true ? Currency::format($value, $currency) : (string) $value;

$value = null;
if ($typed === '') {
    if ($scope === 'global') {
        $leave('The installation\'s figure cannot be left empty. Nothing was saved.', 'danger');
    }
} elseif ($limit['money'] === true) {
    $read = Currency::parseAmount($typed, $currency, $limit['hardMax']);
    if (is_string($read) === true) {
        $leave($read . ' Nothing was saved.', 'danger');
    }
    $value = $read;
} else {
    if (preg_match('/^\d{1,9}$/', $typed) !== 1) {
        $leave('That has to be a whole number, or empty to follow the installation. Nothing was saved.', 'danger');
    }
    $value = (int) $typed;
}

$result = SettingLimit::saveAtLevel(
    $mysqli,
    $scope === 'global' ? null : $siteId,
    $limit['key'],
    $value,
    $limit['default'],
    $limit['min'],
    $limit['hardMax']
);

if ($result['saved'] === false) {
    Logger::activity(
        'MemberLimitSaveRefused',
        ($scope === 'global' ? 'Installation-wide' : 'Organisation ' . $siteId) . ' figure for ' . $limit['key']
        . ' refused (' . $result['reason'] . ')' . ($result['reason'] === 'above-installation' ? ', above the ' . $result['allowed'] . ' the installation allows' : ''),
        $userId > 0 ? $userId : null
    );
    $leave(match ($result['reason']) {
        'below-min'          => 'The least this can be is ' . $shown($limit['min']) . ': it cannot be switched off. Nothing was saved.',
        'above-hard-max'     => SettingLimit::refusalWords('above-hard-max', $limit['hardMax'], ''),
        'above-installation' => 'This installation allows at most ' . $shown($result['allowed']) . ', so that was not saved. You can choose '
            . $shown($result['allowed']) . ' or less. Ask whoever runs this installation if you need more.',
        default              => 'It could not be saved because the database refused the change. Nothing was changed.',
    }, 'danger');
}

Logger::activity(
    'MemberLimitSaved',
    ($scope === 'global' ? 'Installation-wide' : 'Organisation ' . $siteId) . ' figure for ' . $limit['key']
    . ($value === null ? ' removed: it follows the installation' : ' set to ' . $value),
    $userId > 0 ? $userId : null
);
$leave($value === null
    ? 'Saved. Your organisation now follows what the installation sets.'
    : ($scope === 'global'
        ? 'Saved for the whole installation. Organisations that chose a lower number keep it.'
        : 'Saved for your organisation.'), 'success');
