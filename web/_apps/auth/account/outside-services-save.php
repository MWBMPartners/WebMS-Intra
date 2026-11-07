<?php
// Path: _apps/auth/account/outside-services-save.php
/**
 * -----------------------------------------------------------------------------
 * My account — Save my outside-services choices 💾 (#778)
 * -----------------------------------------------------------------------------
 * The form target for /account/outside-services. POST only, signed in, form
 * token first. Only the person signed in is ever changed: the account number
 * is the session's, never anything posted.
 *
 * ONLY THE SERVICES THAT ARRIVED ARE TOUCHED, one row each
 * (`OutsideServices::saveMemberChoice()`) — never rebuilt from a list, which is
 * how #667's preferences page wiped the choices it did not draw. Where a service
 * is not allowed at all and the member has NO stored choice, the page draws no
 * control and the service is simply absent from the post. Where a choice IS
 * stored, a control is drawn (so it can be withdrawn) whose untouched value is
 * "keep", which the writer reads as "leave this service alone".
 *
 * A MIXED PRESS (round 4; the owner's stronger reading of D-058, a withdrawal
 * of consent is never lost): every "off" and every "follow" is APPLIED; only a
 * real change to "on" above what is allowed — or with the settings unreadable —
 * is refused, and that service is left exactly as it was. An "on" equal to the
 * stored choice is not a change and is neither refused nor written. The message
 * says what was saved AND what was refused and why. So switching OFF is never
 * refused, by anything posted beside it.
 *
 * What was applied is recorded as `OutsideServicePrefsUpdated`, and a refused
 * "on" as `OutsideServicePrefsRefused` — a mixed press writes both. An untouched
 * "on" is in neither, because it is not a change: recording it would be a
 * consent record for something the member did not do.
 *
 * @package   Portal\Auth
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/778
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\OutsideServices;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$back = Site::url('account/outside-services');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $back);
    exit();
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Nothing was changed. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $back);
    exit();
}

$userId = (int) ($_SESSION['user_id'] ?? 0);

// 📥 Ask whether each field ARRIVED (#677). Not text → '' → the writer refuses.
$posted = [];
foreach (array_keys(OutsideServices::SERVICES) as $service) {
    if (array_key_exists('choice_' . $service, $_POST) === true) {
        $posted[$service] = is_string($_POST['choice_' . $service]) === true ? $_POST['choice_' . $service] : '';
    }
}

$outcome = OutsideServices::saveMemberChoice($mysqli, $userId, Site::id(), $posted);

$refused = $outcome['refused'] ?? [];
if ($outcome['changes'] !== [] || ($outcome['saved'] === true && $refused === [])) {
    Logger::activity(
        'OutsideServicePrefsUpdated',
        'Own outside-services choices saved. '
        . ($outcome['changes'] === [] ? 'Nothing was different.' : 'Changed ' . implode('; ', $outcome['changes']) . '.'),
        $userId > 0 ? $userId : null
    );
}
if ($refused !== [] || ($outcome['saved'] === false && $outcome['changes'] === [])) {
    Logger::activity(
        'OutsideServicePrefsRefused',
        'A change to own outside-services choices was refused: '
        . ($refused !== [] ? implode(' ', $refused) : $outcome['message']),
        $userId > 0 ? $userId : null
    );
}

$_SESSION['flash_msg']  = $outcome['message'];
$_SESSION['flash_type'] = $outcome['type'];
header('Location: ' . $back);
exit();
