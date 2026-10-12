<?php
// Path: _apps/admin/settings/giving-records/save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Save an organisation's giving-records rule 💾 (#574)
 * -----------------------------------------------------------------------------
 * The form target for /admin/settings/giving-records. POST only, form token
 * first, administrators only, and only ever for the administrator's own
 * organisation: the organisation is `Site::id()`, never anything posted.
 *
 * This file checks WHO is asking. WHAT may be saved is checked by
 * Portal\Core\GivingRetention::saveForSite(), which refuses the whole save
 * when any value is outside its list, un-ticks "confirmed" when the figure
 * changes, and refuses a change that would clear kept records unless the
 * administrator has ticked that they understand. It is there, and not here,
 * so that those rules can be tested without a browser.
 *
 * Every save, and every refusal that got past the form token, is written to
 * the activity log: this setting decides when personal records are cleared,
 * so there must be a record of who changed it and from what.
 *
 * The addresses are built with Site::url(), so the redirect carries the
 * organisation's part of the address where organisations are told apart that
 * way.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/574
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\GivingRetention;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$back = Site::url('admin/settings/giving-records');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $back);
    exit();
}

// 🔐 Form token FIRST, before anything with a side effect, the activity-log
//    entries below included: a forged request from another website must not
//    be able to fill the log.
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Nothing was changed. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $back);
    exit();
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

// Only the eight fields this form has, each as text. Anything else posted is
// ignored, and an array where text is expected becomes an empty value, which
// the checks then refuse or treat as "not ticked".
$posted = [];
foreach (['countryCode', 'scope', 'years', 'confirmed', 'yearEndMonth', 'yearEndDay', 'hold', 'understood'] as $field) {
    $posted[$field] = is_string($_POST[$field] ?? null) === true ? $_POST[$field] : '';
}

$result = GivingRetention::saveForSite($mysqli, $siteId, $posted);

if ($result['saved'] === true) {
    Logger::activity(
        'GivingRecordsRuleSaved',
        'Organisation ' . $siteId . ': giving-records rule saved. '
        . ($result['changes'] === [] ? 'Nothing was different.' : 'Changed ' . implode('; ', $result['changes']) . '.')
        . ($result['newlyDue'] > 0 ? ' ' . $result['newlyDue'] . ' kept donor record(s) became due, and the administrator ticked that they understood.' : ''),
        $userId > 0 ? $userId : null
    );
} else {
    Logger::activity(
        'GivingRecordsRuleRefused',
        'Organisation ' . $siteId . ': a change to the giving-records rule was refused and nothing was saved'
        . ($result['newlyDue'] > 0 ? ' (it would have made ' . $result['newlyDue'] . ' kept donor record(s) due, and the box was not ticked)' : '') . '.',
        $userId > 0 ? $userId : null
    );
}

$_SESSION['flash_msg']  = $result['message'];
$_SESSION['flash_type'] = $result['type'];
header('Location: ' . $back);
exit();
