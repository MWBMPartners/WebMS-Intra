<?php
// Path: _apps/admin/integrations/sso-accounts-save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — may a Microsoft or Google sign-in make a NEW account? Save 💾 (#1042)
 * -----------------------------------------------------------------------------
 * Saves `auth.sso.createAccounts` (D-185) from the card on the Integrations
 * page, at ONE of two levels, named by the posted `level`:
 *
 *   installation  — the row with an empty siteID, every organisation's. A
 *                   global administrator only; '1' (allow) or '0' (refuse).
 *   organisation  — the OPEN organisation's own row. Any administrator of it;
 *                   'follow' (delete the organisation's row, so the
 *                   installation's choice applies) or '0' (refuse here).
 *
 * WHY AN ORGANISATION IS NOT OFFERED "ALLOW". The stricter level wins
 * (`Portal\Core\SsoNewAccounts`): an organisation's "allow" can never undo an
 * installation's "refuse", so offering it would be a control that does nothing
 * in the one case it would be pressed for. "Follow the installation" says what
 * is true. A posted '1' at this level is refused, in words.
 *
 * #674/#677: the page fills each box from ITS OWN level's row — never from
 * the merged settings — and this handler acts only on a field that ARRIVED
 * (`array_key_exists`, never `?? ''`, which once cleared a portal address).
 * A refusal is recorded; a change is recorded in fixed words naming the
 * level and the choice, never a visitor's text.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1042
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\SsoNewAccounts;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$redirect = Site::url('admin/integrations');
$actorId  = isset($_SESSION['user_id']) === true ? (int) $_SESSION['user_id'] : null;

/** Back to the page with a message; nothing is saved. */
$finish = static function (string $message, string $type) use ($redirect): never {
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . $redirect, true, 302);
    exit();
};

// 📨 Only what ARRIVED, and only as a string (a list sent as either is refused).
$level  = (array_key_exists('level', $_POST) === true && is_string($_POST['level']) === true) ? $_POST['level'] : '';
$choice = (array_key_exists('choice', $_POST) === true && is_string($_POST['choice']) === true) ? $_POST['choice'] : null;
if ($choice === null) {
    $finish('Nothing was saved: no choice was sent. Choose one and press Save again.', 'warning');
}

$db  = App::db();
$key = SsoNewAccounts::SETTING_KEY;

if ($level === 'installation') {
    if (App::isRootAdmin() === false) {
        Logger::activity(
            'SettingsGroupSaveRefused',
            'Refused: whether a Microsoft or Google sign-in may make a new account, for the whole installation, may only be changed by a global administrator',
            $actorId
        );
        $finish('This choice is for the whole installation, every organisation on it, so only a global administrator '
            . 'can change it. Your own organisation\'s choice is the second one on this card. Nothing was saved.', 'danger');
    }
    if ($choice !== '1' && $choice !== '0') {
        $finish('Nothing was saved: that is not one of the choices offered.', 'warning');
    }
    $stmt = $db->prepare(
        'INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (NULL, ?, ?, \'1\', 0) '
        . 'ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`)'
    );
    $stmt->bind_param('ss', $key, $choice);
    $stmt->execute();
    $stmt->close();
    Logger::activity(
        'SsoNewAccountsChanged',
        $choice === '1'
            ? 'For the whole installation: a Microsoft or Google sign-in that matches no account may make a new one'
            : 'For the whole installation: a Microsoft or Google sign-in that matches no account is refused',
        $actorId
    );
    $finish($choice === '1'
        ? 'Saved. For the whole installation, a Microsoft or Google sign-in that matches no account makes a new one — '
            . 'except in an organisation that has chosen to refuse.'
        : 'Saved. For the whole installation, a Microsoft or Google sign-in that matches no account is now refused. '
            . 'Somebody signing in is told to ask an administrator for an invitation; a member who is already signed in '
            . 'is told that sign-in could not be used with their account.', 'success');
}

if ($level === 'organisation') {
    $siteId = Site::id();
    if ($choice !== 'follow' && $choice !== '0') {
        $finish('Nothing was saved: an organisation can follow the installation\'s choice or refuse new accounts here; '
            . 'it cannot allow what the installation refuses.', 'warning');
    }
    if ($choice === 'follow') {
        $stmt = $db->prepare('DELETE FROM `tblSettings` WHERE `settingKey` = ? AND `siteID` = ?');
        $stmt->bind_param('si', $key, $siteId);
    } else {
        $stmt = $db->prepare(
            'INSERT INTO `tblSettings` (`siteID`, `settingKey`, `settingValue`, `defaultValue`, `isSensitive`) VALUES (?, ?, \'0\', \'1\', 0) '
            . 'ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`)'
        );
        $stmt->bind_param('is', $siteId, $key);
    }
    $stmt->execute();
    $stmt->close();
    Logger::activity(
        'SsoNewAccountsChanged',
        $choice === 'follow'
            ? 'For this organisation: follows the installation on whether a Microsoft or Google sign-in may make a new account'
            : 'For this organisation: a Microsoft or Google sign-in that matches no account is refused',
        $actorId
    );
    $finish($choice === 'follow'
        ? 'Saved. This organisation now follows the installation\'s choice.'
        : 'Saved. In this organisation, a Microsoft or Google sign-in that matches no account is now refused. '
            . 'Somebody signing in is told to ask an administrator for an invitation; a member who is already signed in '
            . 'is told that sign-in could not be used with their account.', 'success');
}

$finish('Nothing was saved: the form did not say which level to change.', 'warning');
