<?php
// Path: _apps/admin/settings/attendance/save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Save the anonymous check-in count settings 💾 (#525)
 * -----------------------------------------------------------------------------
 * The form target for /admin/settings/attendance. POST only, form token first,
 * administrators only, and every posted value checked against a closed list
 * before anything is written.
 *
 * WHY THE CHOICE IS CHECKED AGAINST A LIST RATHER THAN STORED AS TYPED
 * --------------------------------------------------------------------
 * A setting that decides who sees something must never hold a value nobody
 * planned for. Storing whatever arrives would mean a mistyped or forged value
 * sitting in the database, and although the reader falls back to the narrowest
 * choice when it meets one, an administrator looking at the page would see
 * their choice apparently saved and quietly not applied. So the save is refused
 * with a message instead, and nothing is written at all.
 *
 * TWO DIFFERENT WAYS OF WRITING, AND WHY BOTH ARE STILL WORTH KEEPING
 * ---------------------------------------------------------------------
 * A row for ONE organisation has a real siteID, so the settings table's unique
 * key on (settingKey, siteID) fires normally and
 * `INSERT … ON DUPLICATE KEY UPDATE` is exactly right.
 *
 * CORRECTED by the #525 round-1 independent check, 18 September 2026: this
 * used to say MySQL treats every NULL as a distinct value for uniqueness, so
 * the same ON DUPLICATE KEY UPDATE statement would never match the existing
 * installation-wide row and would pile up a new duplicate NULL row on every
 * save. That stopped being true at migration 187, which added `siteScope`
 * (`INT AS (COALESCE(siteID, -1)) VIRTUAL`) and a second unique key on
 * (settingKey, siteScope) — see full_schema.sql:99-102 — specifically so a
 * NULL siteID collapses to one value (-1) and DOES match. This package's own
 * migration (198) proves it: it writes both settings' installation-wide rows
 * with `ON DUPLICATE KEY UPDATE` and replays three times leaving one row each.
 *
 * So the installation-wide row below is written with look-then-update-or-
 * insert NOT because a single upsert would fail, but because this is the ONE
 * row every other organisation on the installation inherits from, and an
 * explicit SELECT first means the code always knows for certain whether it
 * just changed an existing default or created the first one — instead of
 * having to infer that from `affected_rows` (1 for an insert, 2 for a row
 * ON DUPLICATE KEY UPDATE actually changed, a MySQL-specific quirk easy to
 * get wrong). That matches the rest of this file's own instinct: check
 * explicitly and be certain, rather than lean on upsert semantics whose
 * outcome you would otherwise have to reverse-engineer.
 *
 * WHO MAY CHANGE WHAT
 * -------------------
 * An administrator of an organisation may set that organisation's own value.
 * Only a GLOBAL administrator may change the installation-wide default, because
 * every other organisation on the server inherits it. An attempt by anybody
 * else is refused with the reason, recorded in the activity log, and writes
 * nothing. The refusal comes AFTER the form-token check, so a forged request
 * from another website cannot fill the log.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/525
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\AnonymousCheckins;
use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\AttendanceAccess;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/settings/attendance');
    exit();
}

// 🔐 Form token FIRST, before anything with a side effect — including the
//    activity-log entry below.
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Nothing was changed. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/settings/attendance');
    exit();
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$scope  = (string) ($_POST['scope'] ?? 'site');

/**
 * Write one settings row for ONE organisation.
 *
 * Safe to use ON DUPLICATE KEY UPDATE: siteID is a real value, so the unique
 * key on (settingKey, siteID) matches an existing row normally.
 *
 * @param \mysqli $db     An open connection.
 * @param int     $siteId The organisation.
 * @param string  $key    The settings key.
 * @param string  $value  The value to store.
 *
 * @return void
 */
function attendance_settings_write_for_site(\mysqli $db, int $siteId, string $key, string $value): void
{
    $stmt = $db->prepare(
        'INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive, updatedAt) '
        . 'VALUES (?, ?, ?, ?, 0, NOW()) '
        . 'ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue), updatedAt = NOW()'
    );
    if ($stmt === false) {
        return;
    }
    $stmt->bind_param('isss', $siteId, $key, $value, $value);
    $stmt->execute();
    $stmt->close();
}

/**
 * Write one settings row for the WHOLE installation (siteID NULL).
 *
 * Look-then-update-or-insert. See the file header for why: migration 187's
 * `siteScope` column and `uq_setting_key_scope` key mean an
 * `ON DUPLICATE KEY UPDATE` would in fact match the existing NULL-siteID row
 * correctly today, so this is not the only way to write it safely any more.
 * It stays because this is the one row every other organisation on the
 * installation inherits, and an explicit look first means the caller always
 * knows for certain whether it changed an existing default or created the
 * first one.
 *
 * @param \mysqli $db    An open connection.
 * @param string  $key   The settings key.
 * @param string  $value The value to store.
 *
 * @return void
 */
function attendance_settings_write_installation_wide(\mysqli $db, string $key, string $value): void
{
    $sel = $db->prepare('SELECT settingID FROM tblSettings WHERE settingKey = ? AND siteID IS NULL LIMIT 1');
    if ($sel === false) {
        return;
    }
    $sel->bind_param('s', $key);
    $sel->execute();
    $row = $sel->get_result()->fetch_assoc();
    $sel->close();

    if ($row !== null) {
        $existingId = (int) $row['settingID'];
        $upd = $db->prepare('UPDATE tblSettings SET settingValue = ?, updatedAt = NOW() WHERE settingID = ?');
        if ($upd === false) {
            return;
        }
        $upd->bind_param('si', $value, $existingId);
        $upd->execute();
        $upd->close();
        return;
    }

    $ins = $db->prepare(
        'INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive, updatedAt) '
        . 'VALUES (NULL, ?, ?, ?, 0, NOW())'
    );
    if ($ins === false) {
        return;
    }
    $ins->bind_param('sss', $key, $value, $value);
    $ins->execute();
    $ins->close();
}

// 🚧 Only a global administrator may change the installation-wide default.
//    Checked here, not merely hidden on the page: hiding a control is a
//    courtesy, this is the control.
if ($scope === 'global' && App::isRootAdmin() === false) {
    Logger::activity(
        'AttendanceVisibilitySaveRefused',
        'Refused: the installation-wide default for ' . AnonymousCheckins::VISIBILITY_KEY
        . ' and ' . AttendanceAccess::VISIBILITY_KEY
        . ' may only be changed by a global administrator',
        $userId > 0 ? $userId : null
    );
    $_SESSION['flash_msg']  = 'That is the installation-wide default, which every organisation on this '
        . 'installation follows, not only yours. Only a global administrator can change it. Nothing was '
        . 'changed, and your own organisation\'s setting is unaffected.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/settings/attendance');
    exit();
}

// ✅ The visibility choice must be one of the three exact stored values.
//    Compared against the class's own list, so adding or removing a choice
//    there cannot leave this handler accepting something the reader rejects.
$postedChoice = trim((string) ($_POST['visibleTo'] ?? ''));
if (array_key_exists($postedChoice, AnonymousCheckins::VISIBILITY_CHOICES) === false) {
    $_SESSION['flash_msg']  = 'That is not one of the three choices, so nothing was saved. '
        . 'Please pick one of the options on the page and save again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/settings/attendance');
    exit();
}

// ✅ The number of days. 0 means keep for ever; 3650 (ten years) is an upper
//    stop so a slip of the keyboard cannot store a nonsense number. A value
//    outside the range is refused rather than quietly clamped, because a person
//    who typed 90000 meant something and should be told it was not accepted.
$rawDays = trim((string) ($_POST['retentionDays'] ?? ''));
if (preg_match('/^\d{1,4}$/', $rawDays) !== 1) {
    $_SESSION['flash_msg']  = 'The number of days has to be a whole number between 0 and 3650. '
        . 'Nothing was saved.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/settings/attendance');
    exit();
}
$days = (int) $rawDays;
if ($days < 0 || $days > 3650) {
    $_SESSION['flash_msg']  = 'The number of days has to be a whole number between 0 and 3650. '
        . 'Nothing was saved.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/settings/attendance');
    exit();
}

// ✅ #529 — the reports-page visibility choice must be one of the three
//    exact stored values, checked against AttendanceAccess's own list for
//    the same reason as the door-figures choice above: a mistyped or forged
//    value must never be written, even one that the reader would fall back
//    to the narrowest choice for anyway.
$postedReportsChoice = trim((string) ($_POST['reportsVisibleTo'] ?? ''));
if (array_key_exists($postedReportsChoice, AttendanceAccess::VISIBILITY_CHOICES) === false) {
    $_SESSION['flash_msg']  = '"Who can see the attendance reports page" is not one of the three '
        . 'choices, so nothing was saved. Please pick one of the options on the page and save again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/settings/attendance');
    exit();
}

if ($scope === 'global') {
    attendance_settings_write_installation_wide($mysqli, AnonymousCheckins::VISIBILITY_KEY, $postedChoice);
    attendance_settings_write_installation_wide($mysqli, AnonymousCheckins::RETENTION_KEY, (string) $days);
    attendance_settings_write_installation_wide($mysqli, AttendanceAccess::VISIBILITY_KEY, $postedReportsChoice);

    Logger::activity(
        'AttendanceVisibilitySaved',
        'Installation-wide default set to "' . $postedChoice . '", technical detail kept for '
        . $days . ' days, reports page visibility set to "' . $postedReportsChoice . '"',
        $userId > 0 ? $userId : null
    );

    $_SESSION['flash_msg']  = 'The installation-wide default was saved. Organisations that have chosen '
        . 'a setting of their own keep it.';
    $_SESSION['flash_type'] = 'success';
    header('Location: /admin/settings/attendance');
    exit();
}

attendance_settings_write_for_site($mysqli, $siteId, AnonymousCheckins::VISIBILITY_KEY, $postedChoice);
attendance_settings_write_for_site($mysqli, $siteId, AnonymousCheckins::RETENTION_KEY, (string) $days);
attendance_settings_write_for_site($mysqli, $siteId, AttendanceAccess::VISIBILITY_KEY, $postedReportsChoice);

Logger::activity(
    'AttendanceVisibilitySaved',
    'Organisation ' . $siteId . ' set to "' . $postedChoice . '", technical detail kept for '
    . $days . ' days, reports page visibility set to "' . $postedReportsChoice . '"',
    $userId > 0 ? $userId : null
);

$_SESSION['flash_msg']  = 'Saved for your organisation.';
$_SESSION['flash_type'] = 'success';
header('Location: /admin/settings/attendance');
exit();
