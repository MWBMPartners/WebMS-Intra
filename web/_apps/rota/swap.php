<?php
// Path: _apps/rota/swap.php
/**
 * -----------------------------------------------------------------------------
 * Rota — ask somebody to take one of your duties 🔁
 * -----------------------------------------------------------------------------
 * The member a duty is assigned to asks one named member — or, where the
 * organisation allows it (`rota.allow_open_swap`), anybody — to take it. The
 * request waits on the Duty Roster page (`/rota`) of the person asked, who
 * accepts or declines it there; the request moves nothing until they accept.
 *
 * WHAT CHANGED (#657). This page used to save the request and tell the member
 * "the other member will be notified" when nobody was, and there was no page
 * to answer it on (a later sweep made the sentence honest; this makes it
 * work). It now:
 *
 *   - refuses a person who is not a member of this organisation, the asker
 *     themselves, and an open request where open requests are switched off
 *     (`RotaSwaps::whyNotTarget()`). It used to accept ANY posted account
 *     number, so a hand-made post could aim a request — and now an e-mail —
 *     at somebody in another organisation;
 *   - refuses a duty whose date has already passed;
 *   - attempts an e-mail to the person asked, subject to their notification
 *     preferences (`RotaSwaps::tellAsked()`) — or, for an OPEN request, to
 *     every active member with the switch on (the owner, 11 November 2026 —
 *     D-141). The e-mails go after the request is saved, outside any
 *     transaction;
 *   - answers a successful post with a REDIRECT, so pressing Refresh cannot
 *     send the same request (and the same e-mail) twice.
 *
 * THE LIMITS (#989; the owner, 12 November 2026 — D-171). A member may make
 * only so many requests in a day, and may have only so many OPEN requests
 * waiting for one duty (one, by default: an open request reaches every member
 * with the switch on). The page asks BEFORE drawing the form, so a member at
 * a limit is told what it is and what they can do instead, rather than typing
 * a message only to have it refused. `RotaSwaps::request()` asks again under
 * its locks; that answer is the one that counts, and a refused post keeps what
 * the member typed. A commit whose reply was lost says "we could not confirm",
 * never "not sent", because pressing Send again is how everybody came to be
 * e-mailed twice.
 *
 * The confirmation never promises an e-mail — for a named person OR an open
 * request: whether one is sent depends on each person's own choices and on
 * the mail route, and whether it arrives is not something the portal can
 * know. It says where the request is waiting, which is true, and that the
 * request itself does not move the duty — never "the duty stays yours", which
 * an administrator can make untrue at any moment.
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

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$slotId = (int) (is_string($_GET['slot'] ?? null) === true ? $_GET['slot'] : 0);
if ($slotId <= 0) {
    header('Location: ' . Site::url('rota'));
    exit();
}

// 🔒 Load the duty and confirm it is mine, and still to come.
$slot = null;
$stmt = $db->prepare(
    'SELECT `s`.`slotID`, `s`.`slotDate`, `s`.`assignedToID`, `r`.`name` AS `roleName` '
    . 'FROM `tblRotaSlot` `s` JOIN `tblRotaRoleType` `r` ON `r`.`roleTypeID` = `s`.`roleTypeID` '
    . 'WHERE `s`.`slotID` = ? AND `s`.`siteID` = ? LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $slotId, $siteId);
    $stmt->execute();
    $slot = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($slot === null || (int) $slot['assignedToID'] !== $userId) {
    http_response_code(403);
    exit('You can only request swaps for your own duties.');
}
if (strcmp((string) $slot['slotDate'], RotaSwaps::today($siteId)) < 0) {
    http_response_code(403);
    exit('That duty\'s date has already passed, so it cannot be swapped.');
}

$flash         = '';
$flashType     = 'info';
$allowOpenSwap = (string) (App::settings()['rota']['allow_open_swap'] ?? '1') === '1';
// What the member typed and chose, kept when a post is refused so they do not
// have to type it again (D-171: "a user friendly process", #989).
$keptMessage = '';
$keptTarget  = -1;
// Which limit a refused post met, so the page does not say it twice.
$flashLimit  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $flash     = 'Your form had expired, so the request was not sent. Please try again.';
        $flashType = 'warning';
    } else {
        // A request chooses the TYPE of what it sends as well as the value:
        // anything but a string is treated as "no choice made" (-1), which
        // whyNotTarget() refuses.
        $targetUserID = (int) (is_string($_POST['targetUserID'] ?? null) === true ? $_POST['targetUserID'] : -1);
        $message      = is_string($_POST['requestMessage'] ?? null) === true ? $_POST['requestMessage'] : '';
        $keptMessage  = mb_substr($message, 0, 500, 'UTF-8');
        $keptTarget   = $targetUserID;
        try {
            $whyNot = RotaSwaps::whyNotTarget($db, $siteId, $userId, $targetUserID, $allowOpenSwap);
        } catch (\Throwable $e) {
            // Membership could not be asked: refuse rather than guess.
            $whyNot = RotaSwaps::TARGET_NOT_HERE;
        }
        if ($whyNot !== '') {
            $flash     = RotaSwaps::refusalWords($whyNot);
            $flashType = 'warning';
        } else {
            // 🚧 The limits (D-171) are judged inside request(), under its
            //    locks; it never throws.
            $made   = RotaSwaps::request($db, $siteId, $slotId, $userId, $targetUserID, $message);
            $swapId = $made['swapID'];
            if ($made['reason'] === RotaSwaps::OVER_PER_DAY || $made['reason'] === RotaSwaps::OVER_OPEN_PER_DUTY) {
                // Recorded as numbers only: who asked is the record's own
                // person, and the typed message is never logged.
                Logger::activity('RotaSwapLimitReached', 'Rota swap request refused: limit reached (' . $made['reason'] . ', duty #' . $slotId . ')');
                $flash      = RotaSwaps::refusalWords($made['reason']);
                $flashType  = 'warning';
                $flashLimit = $made['reason'];
            } elseif ($made['reason'] === RotaSwaps::UNKNOWN) {
                // The commit's reply was lost: the request MAY be saved, so the
                // member is sent to look before asking again — pressing Send
                // twice is how everybody came to be e-mailed twice (#989).
                Logger::errorPlatform('Rota', 'Warning', 'SWAP_REQUEST', 'A rota swap request could not be confirmed as saved', '');
                $flash     = 'We could not confirm that your swap request was saved. Please look at your Duty Roster page before asking again: if it is listed there, it was sent.';
                $flashType = 'danger';
            } elseif ($swapId <= 0) {
                // The fault's own words are not logged: they can quote a value.
                Logger::errorPlatform('Rota', 'Warning', 'SWAP_REQUEST', 'A rota swap request could not be saved', '');
                $flash     = 'The swap request could not be saved just now. Please try again.';
                $flashType = 'danger';
            } else {
                Logger::activity('RotaSwapRequested', 'Rota swap request #' . $swapId . ' made (duty #' . $slotId . ')');
                // Told AFTER the request is saved: a mail fault never loses it.
                RotaSwaps::tellAsked($db, $siteId, $swapId);
                $_SESSION['rota_flash'] = $targetUserID > 0
                    ? 'Swap request sent. It is waiting on their Duty Roster page for them to accept or decline. Your request does not move the duty unless they accept.'
                    : 'Open swap request posted. It is offered to the other members on their Duty Roster page, where any of them can take it. Your request does not move the duty unless somebody takes it.';
                $_SESSION['rota_flash_type'] = 'success';
                header('Location: ' . Site::url('rota'));
                exit();
            }
        }
    }
}

// Other members (excluding self) for the dropdown — scoped to this
// organisation's membership (mirrors leadership/assign.php's user dropdown).
// The handler above does NOT trust this list: whyNotTarget() asks again.
$users = [];
$uStmt = $db->prepare(
    'SELECT `u`.`userID`, `u`.`fullName` FROM `tblUsers` `u` '
    . 'INNER JOIN `tblUserSites` `us` ON `us`.`userID` = `u`.`userID` AND `us`.`siteID` = ? AND `us`.`isActive` = 1 '
    . 'WHERE `u`.`userID` <> ? AND `u`.`isActive` = 1 ORDER BY `u`.`fullName`'
);
if ($uStmt !== false) {
    $uStmt->bind_param('ii', $siteId, $userId);
    $uStmt->execute();
    $rs = $uStmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $users[] = $r;
    }
    $uStmt->close();
}

// 🚧 WHERE THE MEMBER STANDS AGAINST THE LIMITS (D-171), asked before the form
//    is drawn so they are told before typing anything. These counts are not
//    locked — request() asks again under its locks, and that answer is the one
//    that counts. When they cannot be read the form is drawn as usual and the
//    save decides.
$limits  = RotaSwaps::limits();
$dayFull = false;
$openFull = false;
$leftToday = null;
try {
    $limitFacts = RotaSwaps::limitFacts($db, $siteId, $slotId, $userId);
    $dayFull    = RotaSwaps::whyOverLimits(false, $limitFacts, $limits) === RotaSwaps::OVER_PER_DAY;
    $openFull   = $dayFull === false && RotaSwaps::whyOverLimits(true, $limitFacts, $limits) === RotaSwaps::OVER_OPEN_PER_DUTY;
    $leftToday  = max(0, $limits['perDay'] - $limitFacts['sentToday']);
} catch (\Throwable $e) {
    // Unread counts: say nothing about them rather than guess.
}
$offerOpen = $allowOpenSwap === true && $openFull === false;

$pageTitle   = 'Request Swap';
$pageSection = 'rota';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Duty Roster' => Site::url('rota'), 'Request Swap' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
?>

<h1 class="mb-3"><i class="fa-solid fa-arrows-rotate me-2"></i>Request swap</h1>

<?php if ($flash !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <p>Duty: <strong><?php echo htmlspecialchars((string) $slot['roleName'], ENT_QUOTES, 'UTF-8'); ?></strong> on <?php echo htmlspecialchars(RotaSwaps::dateWords((string) $slot['slotDate']), ENT_QUOTES, 'UTF-8'); ?></p>
        <?php if ($dayFull === true): ?>
            <?php /* 🚧 The day's limit is reached: no form at all, because nothing
                     typed into it could be sent, and the member is told what
                     they can do instead (D-171). */ ?>
            <?php if ($flashLimit !== RotaSwaps::OVER_PER_DAY): ?>
            <p data-rota-swap-limit="per-day"><?php echo htmlspecialchars(RotaSwaps::overLimitWords(RotaSwaps::OVER_PER_DAY, $limits['perDay'], true), ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
            <a href="<?php echo htmlspecialchars(Site::url('rota'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary btn-sm">Back to the Duty Roster</a>
        <?php else: ?>
        <p class="small text-muted">The person you ask sees the request on their Duty Roster page and can accept or decline it there. Your request does not move the duty unless somebody accepts.</p>
        <?php if ($leftToday !== null): ?>
            <p class="small text-muted" data-rota-swap-left="<?php echo (int) $leftToday; ?>">You can make <?php echo (int) $leftToday; ?> more swap request<?php echo $leftToday === 1 ? '' : 's'; ?> today. Each one is e-mailed, and an open request goes to every member who has these e-mails switched on.</p>
        <?php endif; ?>
        <?php if ($openFull === true && $allowOpenSwap === true && $flashLimit !== RotaSwaps::OVER_OPEN_PER_DUTY): ?>
            <p class="small" data-rota-swap-limit="open-per-duty"><?php echo htmlspecialchars(RotaSwaps::overLimitWords(RotaSwaps::OVER_OPEN_PER_DUTY, $limits['openPerDuty'], true), ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <form method="post" action="<?php echo htmlspecialchars(Site::url('rota/swap') . '?slot=' . $slotId, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="mb-2">
                <label class="form-label small" for="swap-target">Send to</label>
                <select name="targetUserID" id="swap-target" class="form-select form-select-sm">
                    <?php if ($offerOpen === true): ?>
                        <option value="0"<?php echo $keptTarget === 0 ? ' selected' : ''; ?>>Open swap — anyone can cover</option>
                    <?php endif; ?>
                    <?php foreach ($users as $u): ?>
                        <option value="<?php echo (int) $u['userID']; ?>"<?php echo $keptTarget === (int) $u['userID'] ? ' selected' : ''; ?>><?php echo htmlspecialchars((string) $u['fullName'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-2">
                <label class="form-label small" for="swap-message">Message (optional)</label>
                <textarea name="requestMessage" id="swap-message" class="form-control form-control-sm" rows="3" maxlength="500" placeholder="Reason / details…"><?php echo htmlspecialchars($keptMessage, ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Send swap request</button>
            <a href="<?php echo htmlspecialchars(Site::url('rota'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary btn-sm">Cancel</a>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
