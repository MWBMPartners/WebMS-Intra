<?php
// Path: _apps/admin/sites/setup-save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — How organisations are told apart: save ⚙️
 * -----------------------------------------------------------------------------
 * POST handler for the set-up page (admin/sites/setup.php, #502). It accepts
 * one of four choices: "off" (one organisation only), or one of the three
 * ways of telling organisations apart.
 *
 * WHAT IT REFUSES, and nothing is written when it does:
 *   - anybody who is not a global administrator;
 *   - a form whose token has expired;
 *   - a choice that is not one of the four;
 *   - a way that Site::modeProblems() says cannot be chosen now. That is the
 *     same list the page shows, and it includes "first part of the address"
 *     while it is not ready (the owner, Salem874, decided on 12 October
 *     2026 that it is refused until the portal's links are corrected). The page
 *     greys those choices out, but that is only a courtesy: THIS is the
 *     refusal, because a form can be posted by hand.
 *
 * Switching OFF is always allowed: with one organisation nothing can be left
 * unreachable.
 *
 * The two settings are written together or not at all (Site::saveSetup()).
 *
 * THE REDIRECT AFTERWARDS is built for the way that was just saved, not the
 * way this request arrived by (Site::urlUnder()): otherwise leaving "first
 * part of the address" would send the administrator to an address that no
 * longer exists.
 *
 * WHAT IT CANNOT DO: undo. The previous choice is written to the activity
 * log, so it can be put back by hand on the same page.
 *
 * @package   Portal\App\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/502
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

/** Back to the set-up page with a message. */
$back = static function (string $message, string $type, ?string $address = null): never {
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . ($address ?? Site::url('admin/sites/setup')), true, 302);
    exit();
};

// 🛡️ POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . Site::url('admin/sites/setup'), true, 302);
    exit();
}

// 🛡️ Global administrators only
if (App::isUmbrellaAdmin() === false) {
    http_response_code(403);
    echo t('error.umbrella_admin_only');
    exit();
}

// 🛡️ CSRF
if (Auth::verifyCsrf((string) ($_POST['csrf_token'] ?? '')) === false) {
    $back('That form had expired, so nothing was changed. Please choose again.', 'danger');
}

$db      = App::db();
$adminId = (int) (App::user()['userID'] ?? 0);
$choice  = is_string($_POST['mode'] ?? null) === true ? (string) $_POST['mode'] : '';
if ($choice !== 'off' && isset(Site::MODE_LABELS[$choice]) === false) {
    $back('Choose one of the four options. Nothing was changed.', 'danger');
}

// 📋 What is stored now, for the log and to see whether anything changes.
try {
    $before = Site::storedSetup($db);
} catch (\Throwable $e) {
    Logger::errorPlatform('Sites', 'Error', 'SETUP_READ_FAILED', 'The multi-organisation set-up could not be read', $e->getMessage());
    $back('The portal could not read the current set-up, so nothing was changed. Please try again.', 'danger');
}

$enable = ($choice !== 'off');
$mode   = $enable === true ? $choice : $before['mode'];

// 🚦 The refusal. Asked every time, whatever the page showed.
if ($enable === true) {
    $problems = Site::modeProblems($mode, $db);
    if ($problems !== []) {
        Logger::activity('SiteSetupRefused', 'Refused: "' . Site::MODE_LABELS[$mode] . '" cannot be chosen now (' . count($problems) . ' reason(s))', $adminId);
        $back(
            '"' . Site::MODE_LABELS[$mode] . '" cannot be chosen yet, so nothing was changed.' . "\n" . implode("\n", $problems),
            'danger'
        );
    }
}

if ($before['enabled'] === $enable && $before['mode'] === $mode) {
    $back('Nothing needed changing: that is already how this installation is set up.', 'info');
}

try {
    Site::saveSetup($db, $enable, $mode);
} catch (\Throwable $e) {
    Logger::errorPlatform('Sites', 'Error', 'SETUP_SAVE_FAILED', 'The multi-organisation set-up could not be saved', $e->getMessage());
    $back('The portal could not save that, so nothing was changed. Please try again.', 'danger');
}

$describe = static fn (bool $on, string $way): string => $on === true ? Site::MODE_LABELS[$way] : 'one organisation only';
Logger::activity(
    'SiteSetupChange',
    'How organisations are told apart changed from "' . $describe($before['enabled'], $before['mode'])
    . '" to "' . $describe($enable, $mode) . '"',
    $adminId
);

$back(
    'Saved. Organisations are now told apart by: ' . $describe($enable, $mode) . '.',
    'success',
    Site::urlUnder($enable, $mode, 'admin/sites/setup')
);
