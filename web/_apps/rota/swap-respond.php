<?php
// Path: _apps/rota/swap-respond.php
/**
 * -----------------------------------------------------------------------------
 * Rota — accept, decline or withdraw a swap request 🔁
 * -----------------------------------------------------------------------------
 * POST only, from the forms on the Duty Roster page (`/rota`). Every rule —
 * who may answer, whether the request is still waiting, whether the duty is
 * still the asker's — is decided by `Portal\Core\RotaSwaps`, under a row lock,
 * so this page holds no rule of its own (#657).
 *
 * WHAT WAS WRONG (#657). This handler existed before any page linked to it,
 * and it read the request, then wrote, with no lock; never asked whether the
 * duty had already moved; and let anybody decline an OPEN request, closing it
 * for everybody else. Linking a page to it would have made all three live, so
 * it now hands the whole decision to `RotaSwaps::respond()`.
 *
 * After a stored accept or decline, an e-mail to the asker is ATTEMPTED
 * (`RotaSwaps::tellAnswered()`, subject to their own choices), AFTER the
 * commit and never able to undo it: a broken mail route must not leave the
 * duty half-moved. The message shown here says where the duty is NOW, from
 * `RotaSwaps::whereDutyIsNow()`, and promises nobody an e-mail.
 *
 * @package   Portal\Rota
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   2.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/657
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\RotaSwaps;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$back = Site::url('rota');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $back);
    exit();
}
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['rota_flash']      = 'Your form had expired, so nothing was changed. Please try again.';
    $_SESSION['rota_flash_type'] = 'warning';
    header('Location: ' . $back);
    exit();
}

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
// A request chooses the TYPE of what it sends as well as the value, so an
// array posted where a word is expected is treated as no word at all.
$swapId   = (int) (is_string($_POST['swapID'] ?? null) === true ? $_POST['swapID'] : 0);
$action   = is_string($_POST['action'] ?? null) === true ? $_POST['action'] : '';
$response = is_string($_POST['responseMessage'] ?? null) === true ? $_POST['responseMessage'] : '';

// Whether open requests may be taken here at all, read for THIS organisation
// (the merged settings already are) and passed in, so the rule stays in one
// place and the class needs no settings of its own.
$openAllowed = (string) (App::settings()['rota']['allow_open_swap'] ?? '1') === '1';

$result = RotaSwaps::respond($db, $siteId, $swapId, $userId, $action, $response, $openAllowed);

if ($result['outcome'] === RotaSwaps::DONE) {
    // 📝 Numbers only in the description: a rota's own labels are allowed
    //    (D-138) but nothing here needs a name.
    $logged = [
        RotaSwaps::ACCEPT   => ['RotaSwapAccepted', 'accepted'],
        RotaSwaps::DECLINE  => ['RotaSwapDeclined', 'declined'],
        RotaSwaps::WITHDRAW => ['RotaSwapWithdrawn', 'withdrawn'],
    ];
    Logger::activity($logged[$action][0], 'Rota swap request #' . $swapId . ' ' . $logged[$action][1] . ' (duty #' . $result['slotID'] . ')');

    if ($action !== RotaSwaps::WITHDRAW) {
        // An e-mail to the asker is ATTEMPTED after the commit; its outcome
        // is not shown, because whether one arrives cannot be known here.
        RotaSwaps::tellAnswered($db, $siteId, $swapId);
    }
    // 🗣️ Where the duty is NOW, read from its row after the answer, never
    //    predicted from the answer (#657 round 3). These used to say "The
    //    duty is still yours." after a withdrawal and "The duty stays with
    //    the person who asked." after a decline — both false once an
    //    administrator had moved the duty, on a page whose own list then
    //    said otherwise.
    $now   = RotaSwaps::holderOf($db, $siteId, $result['slotID']);
    $where = RotaSwaps::whereDutyIsNow($now['id'], $now['name'], $result['askerID'], $userId);
    $_SESSION['rota_flash'] = match ($action) {
        RotaSwaps::WITHDRAW => 'Your swap request was withdrawn. ' . $where,
        RotaSwaps::ACCEPT   => 'Swap accepted. ' . $where,
        default             => 'Swap declined. ' . $where,
    };
    $_SESSION['rota_flash_type'] = 'success';
    header('Location: ' . $back);
    exit();
}

if ($result['outcome'] === RotaSwaps::REFUSED) {
    // A refusal that crosses a line between two people is recorded, by
    // number only, so an administrator can see somebody tried.
    if ($result['reason'] === RotaSwaps::NOT_YOURS) {
        Logger::activity('RotaSwapRefused', 'An answer to rota swap request #' . $swapId . ' was refused: not theirs to answer');
    }
    $_SESSION['rota_flash']      = RotaSwaps::refusalWords($result['reason']);
    $_SESSION['rota_flash_type'] = 'warning';
    header('Location: ' . $back);
    exit();
}

$_SESSION['rota_flash']      = 'The swap request could not be answered just now, and nothing was changed. Please try again.';
$_SESSION['rota_flash_type'] = 'danger';
header('Location: ' . $back);
exit();
