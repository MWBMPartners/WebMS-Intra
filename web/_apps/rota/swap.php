<?php
// Path: _apps/rota/swap.php
/**
 * -----------------------------------------------------------------------------
 * Rota — ask somebody to take one of your duties 🔁
 * -----------------------------------------------------------------------------
 * The member a duty is assigned to asks one named member — or, where the
 * organisation allows it (`rota.allow_open_swap`), anybody — to take it. The
 * request waits on the Duty Roster page (`/rota`) of the person asked, who
 * accepts or declines it there; nothing changes until they accept.
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
 *   - tells the person asked by e-mail, through their notification
 *     preferences (`RotaSwaps::tellAsked()`) — or, for an OPEN request, every
 *     active member with the switch on (the owner, 11 November 2026 — D-141).
 *     The e-mails go after the request is saved, outside any transaction;
 *   - answers a successful post with a REDIRECT, so pressing Refresh cannot
 *     send the same request (and the same e-mail) twice.
 *
 * The confirmation never promises an e-mail: whether one is sent depends on
 * the other person's own choices, and whether it arrives is not something the
 * portal can know. It says where the request is waiting, which is true.
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
            $swapId = 0;
            try {
                $swapId = RotaSwaps::request($db, $slotId, $userId, $targetUserID, $message);
            } catch (\Throwable $e) {
                // The fault's own words are not logged: they can quote a value.
                Logger::errorPlatform('Rota', 'Warning', 'SWAP_REQUEST', 'A rota swap request could not be saved', '');
            }
            if ($swapId <= 0) {
                $flash     = 'The swap request could not be saved just now. Please try again.';
                $flashType = 'danger';
            } else {
                Logger::activity('RotaSwapRequested', 'Rota swap request #' . $swapId . ' made (duty #' . $slotId . ')');
                // Told AFTER the request is saved: a mail fault never loses it.
                RotaSwaps::tellAsked($db, $siteId, $swapId);
                $_SESSION['rota_flash'] = $targetUserID > 0
                    ? 'Swap request sent. It is waiting on their Duty Roster page for them to accept or decline. The duty stays yours until they accept.'
                    : 'Open swap request posted. Every member who has rota swap e-mails switched on is sent an e-mail about it, and any member can take it from their Duty Roster page. The duty stays yours until somebody takes it.';
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
        <p class="small text-muted">The person you ask sees the request on their Duty Roster page and can accept or decline it there. The duty stays yours until somebody accepts.</p>
        <form method="post" action="<?php echo htmlspecialchars(Site::url('rota/swap') . '?slot=' . $slotId, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="mb-2">
                <label class="form-label small" for="swap-target">Send to</label>
                <select name="targetUserID" id="swap-target" class="form-select form-select-sm">
                    <?php if ($allowOpenSwap === true): ?>
                        <option value="0">Open swap — anyone can cover</option>
                    <?php endif; ?>
                    <?php foreach ($users as $u): ?>
                        <option value="<?php echo (int) $u['userID']; ?>"><?php echo htmlspecialchars((string) $u['fullName'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-2">
                <label class="form-label small" for="swap-message">Message (optional)</label>
                <textarea name="requestMessage" id="swap-message" class="form-control form-control-sm" rows="3" maxlength="500" placeholder="Reason / details…"></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Send swap request</button>
            <a href="<?php echo htmlspecialchars(Site::url('rota'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary btn-sm">Cancel</a>
        </form>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
