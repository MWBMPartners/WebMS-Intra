<?php
// Path: _apps/admin/exchange-rates/save.php
/**
 * Admin — Exchange rates: save handler 💱 (#993; D-173, D-192)
 *
 * ONE address, four actions, chosen by the posted `action`:
 *
 *   fetch_switch  turn the daily fetch on or off          global administrator
 *   fetch_now     fetch the bank's list this minute       global administrator
 *   save_rate     type (or replace) a rate                see below
 *   remove_rate   remove a typed rate                     see below
 *
 * WHO MAY DO WHAT. The switch and "Fetch now" are the INSTALLATION's: they
 * decide whether this portal contacts an outside company at all, for every
 * organisation on it (D-058 rule 1), so they are for a global administrator
 * only, exactly as `admin/translation/save.php` holds portal-wide settings. A
 * typed rate belongs to a level: the installation's (global administrator) or
 * the open organisation's own (any administrator of it). An organisation's
 * administrator can therefore only ever write rows for `Site::id()`, and the
 * installation's spending checks never read those rows (ExchangeRates::rate()
 * with a null organisation), so one organisation cannot type a rate that
 * loosens the installation's limit (D-013).
 *
 * Every refusal gives its reason on screen and is recorded, after the form
 * token is checked so a forged request from another website cannot fill the
 * activity log. Records name currencies and rates only (all validated
 * before they are written), never a person's words.
 *
 * THE SWITCH ARRIVES OR IT DOES NOT (#677). A tick-box that is not ticked is
 * not submitted at all, so "absent" cannot mean "off". The form carries a
 * hidden `fetchEnabledShown` marker; without it nothing is written.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/993
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\ExchangeRates;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

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

$db     = App::db();
$userId = $_SESSION['user_id'] ?? null;
$back   = Site::url('admin/exchange-rates');

/** Say something on the page and go back to it. */
$finish = static function (string $message, string $type) use ($back): never {
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . $back);
    exit();
};

/** A posted value, but only if it is plain text: a request chooses the TYPE as well as the value. */
$text = static function (string $name): string {
    $value = $_POST[$name] ?? '';

    return is_string($value) === true ? trim($value) : '';
};

$action = $text('action');
if (in_array($action, ['fetch_switch', 'fetch_now', 'save_rate', 'remove_rate'], true) === false) {
    $finish('Nothing was changed: that request was not understood.', 'danger');
}

// 🧭 Which level a rate is for. Anything but "installation" is the open organisation.
$scope      = $text('scope') === 'installation' ? 'installation' : 'organisation';
$needsRoot  = $action === 'fetch_switch' || $action === 'fetch_now' || ($scope === 'installation');
if ($needsRoot === true && App::isRootAdmin() === false) {
    Logger::activity(
        'ExchangeRatesActionRefused',
        'Refused: the exchange-rate action "' . $action . '" for the whole installation may only be done by a global administrator',
        $userId
    );
    $finish('That is for the whole installation, so only a global administrator can do it. '
        . 'You can type a rate for your own organisation instead.', 'danger');
}
$siteId = $scope === 'installation' ? null : Site::id();

if ($action === 'fetch_switch') {
    // Only a form that DREW the tick-box may change it (see the header).
    if ($text('fetchEnabledShown') !== '1') {
        $finish('Nothing was changed.', 'info');
    }
    $value = $text('fetchEnabled') === '1' ? '1' : '0';
    $stmt  = $db->prepare(
        'INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `isSensitive`, `siteID`, `updatedAt`) VALUES (?, ?, 0, NULL, NOW()) '
        . 'ON DUPLICATE KEY UPDATE `settingValue` = VALUES(`settingValue`), `updatedAt` = NOW()'
    );
    $key = ExchangeRates::FETCH_SETTING;
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();
    Logger::activity(
        'ExchangeRatesFetchSwitched',
        'The daily exchange-rate fetch was switched ' . ($value === '1' ? 'on' : 'off'),
        $userId
    );
    $finish($value === '1'
        ? 'The daily fetch of exchange rates is on.'
        : 'The daily fetch of exchange rates is off. Rates already held, and any you type, are still used.', 'success');
}

if ($action === 'fetch_now') {
    // The switch decides whether the portal may contact the bank AT ALL (D-058), a button included.
    $stored = \Portal\Core\SettingLimit::storedAtLevel($db, ExchangeRates::FETCH_SETTING, null);
    if ($stored !== '1') {
        $finish('The daily fetch is switched off, so the portal will not contact the European Central Bank. '
            . 'Switch it on first.', 'warning');
    }
    $problem = ExchangeRates::refresh($db);
    Logger::activity(
        'ExchangeRatesFetchedByHand',
        $problem === '' ? 'Exchange rates were fetched by hand' : 'Exchange rates were fetched by hand and the fetch did not succeed',
        $userId
    );
    $finish($problem === ''
        ? 'The exchange rates were fetched.'
        : 'The fetch did not work: ' . ExchangeRates::problemWords($problem) . '. Nothing was changed.', $problem === '' ? 'success' : 'danger');
}

if ($action === 'save_rate') {
    $refusal = ExchangeRates::saveEntered($db, $siteId, $text('fromCurrency'), $text('toCurrency'), $text('rate'), $text('asOf'));
    if ($refusal !== '') {
        Logger::activity('ExchangeRateRefused', 'A typed exchange rate was refused', $userId);
        $finish('Nothing was saved. ' . $refusal, 'danger');
    }
    // Validated by saveEntered(): three capital letters each, and a plain number.
    Logger::activity(
        'ExchangeRateEntered',
        'An exchange rate was typed: ' . strtoupper($text('fromCurrency')) . ' to ' . strtoupper($text('toCurrency')) . ' at ' . $text('rate')
        . ($siteId === null ? ' (installation)' : ' (organisation)'),
        $userId
    );
    $finish('The rate was saved.', 'success');
}

// remove_rate
$enteredId = ctype_digit($text('enteredID')) === true ? (int) $text('enteredID') : 0;
$gone      = $enteredId > 0 ? ExchangeRates::removeEntered($db, $siteId, $enteredId) : 0;
if ($gone < 0) {
    $finish('The rate could not be removed. Try again.', 'danger');
}
if ($gone === 0) {
    $finish('Nothing was removed: that rate is not there any more, or it is not yours to remove.', 'warning');
}
Logger::activity(
    'ExchangeRateRemoved',
    'A typed exchange rate was removed' . ($siteId === null ? ' (installation)' : ' (organisation)'),
    $userId
);
$finish('The rate was removed.', 'success');
