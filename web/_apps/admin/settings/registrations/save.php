<?php
// Path: _apps/admin/settings/registrations/save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — save how long event registrations are kept 💾 (#816)
 * -----------------------------------------------------------------------------
 * The form target for `/admin/settings/registrations`. POST only, form token
 * first, an administrator of this organisation — and a global administrator
 * for the installation's two rows.
 *
 * WHAT THIS FILE DOES AND DELIBERATELY DOES NOT DO
 * ------------------------------------------------
 * It reads the form and records what happened. It does NOT decide whether a
 * period or a ceiling is allowed, and it writes no row: both are
 * `Portal\Core\RegistrationRetention`, which re-reads the ceiling LIVE with its
 * row LOCKED inside the transaction that writes. #687 measured why a page
 * cannot do that from its settings snapshot: two overlapping requests stored
 * fifty years under a ceiling of seven, in 60 of 60 pairs.
 *
 * THE RULES IT FOLLOWS, EACH ONE PAID FOR ALREADY
 * -----------------------------------------------
 *   - ASK WHETHER A FIELD ARRIVED, never only what it contains (#677). An
 *     absent field changes nothing; an EMPTY organisation box means "follow
 *     the installation", and an empty ceiling box means "no ceiling".
 *   - REFUSE WITH THE REASON, never clamp (#668).
 *   - RECORD EVERY REFUSAL. A request to go beyond the installation's ceiling
 *     crosses a line between two parties who may be two different people. The
 *     record carries COUNTS, never the names the refusal message shows the
 *     installation's administrator, because the log is read later and by others.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/816
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\RegistrationRetention;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

if (App::isAdmin() === false) {
    Router::renderError(403);

    return;
}

$back = Site::url('admin/settings/registrations');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $back);
    exit();
}

// 🔐 Form token FIRST, before anything with a side effect — the activity-log
//    entries below included, so a forged request cannot fill the log.
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Nothing was changed. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $back);
    exit();
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$who    = $userId > 0 ? $userId : null;

/**
 * A posted whole number of days: null when the field did not arrive, '' when it
 * arrived empty, an int when it holds a whole number, false for anything else.
 * An array where text is expected counts as "anything else".
 */
$daysField = static function (string $name): int|string|false|null {
    if (array_key_exists($name, $_POST) === false) {
        return null;
    }
    if (is_string($_POST[$name]) === false) {
        return false;
    }
    $raw = trim($_POST[$name]);
    if ($raw === '') {
        return '';
    }

    return preg_match('/^\d{1,6}$/', $raw) === 1 ? (int) $raw : false;
};

$finish = static function (string $message, string $type) use ($back): never {
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . $back);
    exit();
};

// =============================================================================
// 🏛️ THE INSTALLATION — a global administrator only
// =============================================================================
if (($_POST['action'] ?? '') === 'installation') {
    $isGlobal = App::isRootAdmin();
    $days     = $daysField('installationDays');
    $ceiling  = $daysField('maxDays');

    if ($days === false || $days === '' || $ceiling === false) {
        Logger::activity(
            'RegistrationRetentionRefused',
            'A change to the installation\'s registration period or ceiling was refused: a box did not hold a whole number of days. Nothing was saved.',
            $who
        );
        $finish('The installation\'s period must be a whole number of days, and the ceiling a whole number of '
            . 'days or empty for no ceiling. Nothing was saved.', 'danger');
    }

    $result = RegistrationRetention::saveInstallation(
        $mysqli,
        $days,
        // An EMPTY ceiling box is "no ceiling"; an absent one is "leave it".
        $ceiling === '' ? RegistrationRetention::NO_CAP : $ceiling,
        $isGlobal
    );

    if ($result['saved'] === true) {
        if ($result['changes'] !== []) {
            Logger::activity(
                'RegistrationRetentionSaved',
                'Installation-wide registration settings changed: ' . implode('; ', $result['changes']) . '.',
                $who
            );
        }
    } else {
        Logger::activity(
            'RegistrationRetentionRefused',
            'A change to the installation\'s registration period or ceiling was refused and nothing was saved'
            . ($result['blockedInstallation'] === true ? ' (the installation\'s own period is longer)' : '')
            . ($result['blockedOrganisations'] > 0 ? ' (' . $result['blockedOrganisations'] . ' organisation(s) keep registrations longer)' : '')
            . ($result['blockedEvents'] > 0 ? ' (' . $result['blockedEvents'] . ' event(s) keep registrations longer)' : '')
            . ($isGlobal === false ? ' (not a global administrator)' : '')
            . '.',
            $who
        );
    }
    $finish($result['message'], $result['type']);
}

// =============================================================================
// 🏢 THIS ORGANISATION'S OWN PERIOD
// =============================================================================
$days = $daysField('registrationDays');

if ($days === null) {
    $finish('The form did not include a period, so nothing was changed.', 'danger');
}
if ($days === false) {
    Logger::activity(
        'RegistrationRetentionRefused',
        'Organisation ' . $siteId . ' asked for a registration period that is not a whole number of days. Nothing was saved.',
        $who
    );
    $finish('How long registrations are kept must be a whole number of days, or empty to follow the period '
        . 'this installation sets. Nothing was saved.', 'danger');
}

$asked  = $days === '' ? null : $days;
$result = RegistrationRetention::saveForSite($mysqli, $siteId, $asked);

if ($result['outcome'] === 'refused') {
    Logger::activity(
        'RegistrationRetentionRefused',
        'Organisation ' . $siteId . ' asked to keep registrations '
        . ($asked === null ? 'for the installation\'s period' : ($asked === 0 ? 'indefinitely' : 'for ' . $asked . ' days'))
        . ' and it was refused' . ($result['refusal'] === '' ? '' : ' (' . $result['refusal'] . ')') . '. Nothing was saved.',
        $who
    );
} elseif ($result['outcome'] !== 'unchanged') {
    Logger::activity(
        'RegistrationRetentionSaved',
        'Organisation ' . $siteId . ' now keeps registrations '
        . ($result['outcome'] === 'cleared'
            ? 'for the period this installation sets, having removed its own figure'
            : ($asked === 0 ? 'indefinitely' : 'for ' . $asked . ' days after the event')) . '.',
        $who
    );
}

$finish($result['message'], $result['type']);
