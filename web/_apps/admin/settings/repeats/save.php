<?php
// Path: _apps/admin/settings/repeats/save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Save how big a repeating series may be 💾 (#569, migration 227)
 * -----------------------------------------------------------------------------
 * The form target for /admin/settings/repeats. POST only, form token first,
 * administrators only, and both numbers checked before anything is written.
 *
 * AN OUT-OF-RANGE NUMBER IS REFUSED, NOT CLAMPED
 * -----------------------------------------------------------------------------
 * `EventRecurrence::limitsFrom()` CLAMPS a stored value that is too big,
 * because by the time a value is in the database the person who typed it has
 * gone and a working portal matters more than a tidy one. Here they are still
 * at the keyboard, so a number outside the allowed range is refused with the
 * reason and nothing at all is written. Somebody who typed 5,000 meant
 * something, and quietly saving 1,000 would tell them they got what they asked
 * for.
 *
 * The two are deliberately different, and the reasoning is written in both
 * places so that neither can be "tidied" to match the other without somebody
 * reading why.
 *
 * WHO MAY CHANGE WHAT
 * -----------------------------------------------------------------------------
 * An administrator of an organisation may set that organisation's own numbers.
 * Only a GLOBAL administrator may change the installation-wide default, because
 * every other organisation on the server inherits it. Anybody else is refused
 * with the reason, it is recorded in the activity log, and nothing is written.
 * The refusal comes AFTER the form-token check, so a forged request from
 * another website cannot fill the log.
 *
 * THE INSTALLATION'S LIMIT IS ENFORCED HERE, NOT ON THE PAGE
 * -----------------------------------------------------------------------------
 * An organisation may hold itself to a LOWER ceiling than the installation
 * allows but can never raise its own above it (the owner, 29 October 2026).
 *
 * That rule is checked in this file, and the reason it cannot live in the
 * `max` attribute on the form is that the two levels may be DIFFERENT PEOPLE:
 * whoever administers the installation may be a hosting provider, and the
 * organisation's administrators its customers. A `max` on an input is a
 * courtesy to somebody filling the form in; it is not a boundary between two
 * parties, because anything can be posted.
 *
 * The number is REFUSED with the reason rather than quietly reduced. Silently
 * clamping a number somebody else typed is how a person ends up believing a
 * limit is in force when it is not — and the generator clamps too
 * (`limitsFrom()` takes the smaller of the two), so a quietly-saved 3 years
 * under an installation limit of 1 would sit in the database looking effective
 * and do nothing.
 *
 * It is recorded in the activity log for the same reason: a request to exceed
 * the installation's limit crosses a line between two parties, so it is worth
 * a record whichever way it goes.
 *
 * WHY `INSERT … ON DUPLICATE KEY UPDATE` IS SAFE FOR BOTH LEVELS
 * -----------------------------------------------------------------------------
 * A row for one organisation has a real siteID, so the unique key on
 * (settingKey, siteID) matches normally. An installation-wide row has
 * siteID NULL, which would not match that key — every NULL is distinct for
 * uniqueness — but migration 187 added `siteScope`
 * (`INT AS (COALESCE(siteID, -1)) VIRTUAL`) and a second unique key on
 * (settingKey, siteScope) exactly so that a NULL collapses to one value and
 * DOES match. Without that this would pile up a new NULL row on every save.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/569
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\EventRecurrence;
use Portal\Core\InstallationSettings;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$backTo = Site::url('admin/settings/repeats');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $backTo);
    exit();
}

// 🔐 Form token FIRST, before anything with a side effect — the activity-log
//    entry below included.
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Nothing was changed. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $backTo);
    exit();
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$scope  = (string) ($_POST['scope'] ?? 'site');

/**
 * Read one of the two numbers from the form.
 *
 * Returns null when it is not a whole number in range, so the caller can
 * refuse with a message rather than store something the reader would ignore.
 *
 * `$_POST` values are text and the TYPE is chosen by the requester, so a
 * posted array has to be survivable: `maxYears[]=x` would otherwise reach
 * `trim()` and raise an error. This project has already shipped one sign-in
 * bypass that began exactly there.
 *
 * @param mixed $raw The posted value.
 * @param int   $max The most it may be.
 *
 * @return int|null The number, or null when it is unusable.
 */
function repeats_posted_number(mixed $raw, int $max): ?int
{
    if (is_string($raw) === false && is_int($raw) === false) {
        return null;
    }

    $text = trim((string) $raw);
    if (preg_match('/^\d{1,9}$/', $text) !== 1) {
        return null;
    }

    $value = (int) $text;
    if ($value < 1 || $value > $max) {
        return null;
    }

    return $value;
}

/**
 * Write one settings row, at either level.
 *
 * @param \mysqli  $db     An open connection.
 * @param int|null $siteId The organisation, or null for the whole installation.
 * @param string   $key    The settings key.
 * @param string   $value  The value to store.
 *
 * @return void
 */
function repeats_write(\mysqli $db, ?int $siteId, string $key, string $value): void
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

// 🚧 Only a global administrator may change the installation-wide default.
//    Checked here, not merely hidden on the page: hiding a control is a
//    courtesy, this is the control.
if ($scope === 'global' && App::isRootAdmin() === false) {
    Logger::activity(
        'RepeatCeilingSaveRefused',
        'Refused: the installation-wide defaults for ' . EventRecurrence::OCCURRENCES_KEY
        . ' and ' . EventRecurrence::YEARS_KEY
        . ' may only be changed by a global administrator',
        $userId > 0 ? $userId : null
    );
    $_SESSION['flash_msg']  = 'Those are the installation-wide defaults, which every organisation on '
        . 'this installation follows, not only yours. Only a global administrator can change them. '
        . 'Nothing was changed, and your own organisation\'s settings are unaffected.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $backTo);
    exit();
}

// 🏢 THE MOST THIS SAVE MAY ASK FOR.
//    Changing the installation-wide row is capped only by the hard maximum in
//    the generator. Changing ONE organisation's row is capped by what the
//    installation allows, which may be a great deal lower and may have been
//    set by somebody else entirely.
//    `layer()` here, DELIBERATELY DIFFERENT from the `layerOrNull()` the
//    calendar form and handler use. There, a missing layer should leave the
//    dates a customer gets unchanged, so it falls back to reading one layer.
//    Here it guards a boundary between two parties, and the safe direction for
//    a boundary is to allow LESS rather than more: an unknown layer reads as
//    empty, which means "the installation allows the built-in defaults", so in
//    the minutes after an upgrade this page may refuse a number it would later
//    accept — recoverable, whereas accepting one it should refuse is not.
$installationLayer = InstallationSettings::layer();
$allowed           = EventRecurrence::installationAllows($installationLayer);
$mostDates         = $scope === 'global' ? EventRecurrence::HARD_MAX_OCCURRENCES : $allowed['occurrences'];
$mostYears         = $scope === 'global' ? EventRecurrence::HARD_MAX_YEARS : $allowed['years'];

// ✅ Both numbers. A number past the limit is refused with the reason, and
//    for an organisation the reason says whose limit it is.
$dates = repeats_posted_number($_POST['maxOccurrences'] ?? null, $mostDates);
if ($dates === null) {
    $_SESSION['flash_msg']  = 'The most dates in one series has to be a whole number between 1 and '
        . $mostDates . ($scope === 'global' ? '' : ', which is what this installation allows')
        . '. Nothing was saved.';
    $_SESSION['flash_type'] = 'danger';
    if ($scope !== 'global') {
        Logger::activity(
            'RepeatCeilingSaveRefused',
            'Refused: an organisation asked for a repeating-event date limit above the '
            . $mostDates . ' this installation allows',
            $userId > 0 ? $userId : null
        );
    }
    header('Location: ' . $backTo);
    exit();
}

$years = repeats_posted_number($_POST['maxYears'] ?? null, $mostYears);
if ($years === null) {
    $_SESSION['flash_msg']  = 'How far ahead has to be a whole number of years between 1 and '
        . $mostYears . ($scope === 'global' ? '' : ', which is what this installation allows')
        . '. Nothing was saved.';
    $_SESSION['flash_type'] = 'danger';
    if ($scope !== 'global') {
        Logger::activity(
            'RepeatCeilingSaveRefused',
            'Refused: an organisation asked for a repeating-event horizon above the '
            . $mostYears . ' year(s) this installation allows',
            $userId > 0 ? $userId : null
        );
    }
    header('Location: ' . $backTo);
    exit();
}

$writeTo = $scope === 'global' ? null : $siteId;

repeats_write($mysqli, $writeTo, EventRecurrence::OCCURRENCES_KEY, (string) $dates);
repeats_write($mysqli, $writeTo, EventRecurrence::YEARS_KEY, (string) $years);

Logger::activity(
    'RepeatCeilingSaved',
    ($scope === 'global' ? 'Installation-wide' : 'Organisation ' . $siteId)
    . ' repeating-event limits set to ' . $dates . ' dates and ' . $years . ' years',
    $userId > 0 ? $userId : null
);

$_SESSION['flash_msg']  = $scope === 'global'
    ? 'The installation-wide defaults were saved. Organisations that have chosen their own numbers '
        . 'keep them. Dates already in any diary are unchanged.'
    : 'Saved for your organisation. Dates already in your diary are unchanged.';
$_SESSION['flash_type'] = 'success';
header('Location: ' . $backTo);
exit();
