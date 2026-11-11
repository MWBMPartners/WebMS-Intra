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
 * duty half-moved. The message shown here says what this answer DID
 * (`RotaSwaps::answerWords()`), and promises nobody an e-mail.
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
        // An e-mail to the asker is ATTEMPTED after the commit, built from
        // what respond() established inside its transaction; its outcome is
        // not shown, because whether one arrives cannot be known here.
        RotaSwaps::tellAnswered($db, $siteId, $swapId, $result);
    }
    // 🗣️ What THIS answer did — a fixed sentence per answer, never where the
    //    duty is "now" (#657 round 4). Rounds 2-3 read the duty's row again
    //    after the commit and described it; those sentences kept going untrue
    //    at the edges, and the read could throw and show an error page for an
    //    answer that had been stored. Where the duty is now is shown by the
    //    Duty Roster's own lists, which read it while drawing the page.
    $_SESSION['rota_flash'] = RotaSwaps::answerWords($action);
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

if ($result['outcome'] === RotaSwaps::UNKNOWN) {
    // 🤷 The commit failed or its reply was lost: the answer may have been
    //    stored. Say only what is known — never "nothing was changed", and
    //    never "try again", which could answer twice (#649's shape).
    $_SESSION['rota_flash']      = 'We could not confirm whether your answer was saved. Reload the page to see where the request stands.';
    $_SESSION['rota_flash_type'] = 'warning';
    header('Location: ' . $back);
    exit();
}

// FAILED: the fault came before the commit and the transaction was rolled
// back, so it IS known that nothing was written.
$_SESSION['rota_flash']      = 'The swap request could not be answered just now, and nothing was changed. Please try again.';
$_SESSION['rota_flash_type'] = 'danger';
header('Location: ' . $back);
exit();
