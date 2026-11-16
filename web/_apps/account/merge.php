<?php
// Path: _apps/account/merge.php
/**
 * -----------------------------------------------------------------------------
 * Merge my accounts — the person's own page 🔀 (#1064, D-190)
 * -----------------------------------------------------------------------------
 * Where the person agrees, from each of their two accounts in turn, to an
 * administrator's request to merge them. The merge happens only when they have
 * agreed from BOTH, in the same browser, each time having just signed in.
 *
 * WHAT IT SHOWS
 * -------------------------------------------------------------------------
 * The two account NUMBERS, which one stays, and the counts — never the other
 * account's name, address or records (the signed-in person may not yet have
 * proved they own it). A request number that does not exist and one that is
 * not this person's get the SAME page: the portal's ordinary 404.
 *
 * Every decision is `Portal\Core\AccountMerge`'s, including whether this is the
 * final step (`isSecondStep()`); this page draws its answers.
 *
 * @package   Portal\Account
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1064
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\AccountMerge;
use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\SelfServicePassword;
use Portal\Core\Site;

Auth::requireLogin();
$me = (int) ((App::user() ?? [])['userID'] ?? 0);

$raw       = $_GET['request'] ?? '';
$requestId = (is_string($raw) === true && preg_match('/^[0-9]{1,10}$/', $raw) === 1) ? (int) $raw : 0;
$request   = null;
if ($requestId > 0) {
    $request = AccountMerge::requestFor($mysqli, $requestId, $me);
    if ($request === null) {
        // ⚠️ One answer for "no such request" and "not yours".
        Router::renderError(404);
        return;
    }
}

$https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
$token = $_COOKIE[AccountMerge::cookieName($https)] ?? '';
$token = is_string($token) === true ? $token : '';

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$e    = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
$csrf = $e(Auth::csrfToken());

$pageTitle   = 'Merge my accounts';
$pageSection = 'account';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Account' => Site::url('account'), 'Merge my accounts' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-4"><i class="fa-solid fa-code-merge me-2"></i>Merge my accounts</h1>

<?php if (is_string($flashMsg) === true && $flashMsg !== ''): ?>
    <div class="alert alert-<?php echo $e((string) $flashType); ?>" data-merge-flash><?php echo $e($flashMsg); ?></div>
<?php endif; ?>

<?php if ($request === null): ?>
    <?php $open = AccountMerge::openRequests($mysqli, $me); ?>
    <?php if ($open === []): ?>
        <p class="text-muted">Nobody has asked to merge this account with another one.</p>
    <?php else: ?>
        <p>An administrator has asked whether these accounts are both yours:</p>
        <ul>
            <?php foreach ($open as $row): ?>
                <li>
                    <a href="<?php echo $e(Site::url('account/merge')); ?>?request=<?php echo (int) $row['requestID']; ?>">
                        Account <?php echo (int) $row['duplicateId']; ?> into account <?php echo (int) $row['keptId']; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
<?php else: ?>
    <?php
    $keptId   = (int) $request['keptId'];
    $dupId    = (int) $request['duplicateId'];
    $other    = $me === $keptId ? $dupId : $keptId;
    $open     = $request['status'] === 'waiting' && ($request['expired'] === 1 || $request['expired'] === '1') === false;
    $second   = $open === true && AccountMerge::isSecondStep($request, $me, $token);
    $fresh    = SelfServicePassword::recentlyProvedThemselves($me);
    // ⏳ D-200: nobody can agree until 7 days after the notice. The moment is
    //    stored in UTC and shown on the ORGANISATION's own clock (Site::timezone()).
    $tooSoon  = $request['tooSoon'] === 1 || $request['tooSoon'] === '1';
    $opensAt  = '';
    if ($tooSoon === true) {
        $opensAt = (new \DateTimeImmutable((string) $request['notBeforeUtc'], new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone(Site::timezone()))->format('j F Y, H:i');
    }
    ?>
    <div class="alert alert-info">
        <p class="mb-2">
            An administrator has asked to merge <strong>account <?php echo $dupId; ?></strong> into
            <strong>account <?php echo $keptId; ?></strong>. You are signed in as account <?php echo $me; ?>.
        </p>
        <p class="mb-2">
            If you agree, account <?php echo $dupId; ?>'s records about you, what you belong to, what you have
            been given to do, your gifts and choices, and the records you manage (loans you asked for, photos you
            uploaded, tasks, saved reports, recording notes, attendance sessions you created, room bookings and
            rota-swap requests) will move to account <?php echo $keptId; ?>, and the move is written in the activity
            log. Account <?php echo $dupId; ?> will be closed. Its activity log and change history stay under its
            number and are never rewritten; they are still counted as yours.
            <strong>This cannot be undone.</strong> Only agree if both accounts are yours.
        </p>
        <p class="mb-0">
            To prove both are yours you agree twice, once from each account, in this same browser: first here, then
            you sign out, sign in to account <?php echo $other; ?>, and agree again within 30 minutes. Each time, you
            must have signed in within the last five minutes.
        </p>
    </div>

    <?php if ($open === false): ?>
        <p class="text-muted" data-merge-state="closed">This request is no longer open.</p>
    <?php else: ?>
        <?php
        // 🙈 WHAT THIS PERSON MAY SEE (adversarial check, finding 1). Until both
        //    accounts have agreed in this browser, nothing about the OTHER
        //    account is shown — not its records, not its counts, not a refusal
        //    that would describe it: they have not proved it is theirs. With a
        //    fresh sign-in they see counts of THIS account's own records. Only at
        //    the final step, both proofs in, are the merge's own counts shown.
        $mergePreview = null;
        $mergeShowClashes = false;
        if ($second === true && $fresh === true) {
            $mergePreview = AccountMerge::preview($mysqli, $keptId, $dupId);
            $mergeShowClashes = true;
        } elseif ($fresh === true) {
            $mergePreview = AccountMerge::ownCounts($mysqli, $me);
            // The kept account's own records simply stay: one true heading.
            $mergeKeptSide = $me === $keptId;
        }
        ?>
        <?php if ($mergeShowClashes === true && $mergePreview['refusal'] !== ''): ?>
            <div class="alert alert-warning" data-merge-refusal="<?php echo $e($mergePreview['refusal']); ?>">
                <?php echo $e(AccountMerge::personRefusalWords($mergePreview['refusal'])); ?>
            </div>
        <?php endif; ?>

        <?php if ($tooSoon === true): ?>
            <p data-merge-state="too-soon">
                You can agree to this merge from <strong><?php echo $e($opensAt); ?></strong>, 7 days after you were told
                about it, so that you have time to cancel it if it was not you. You can cancel it now.
            </p>
        <?php elseif ($fresh === false): ?>
            <p data-merge-state="needs-proof">
                To agree, first <a href="<?php echo $e(Site::url('logout')); ?>">sign out</a>, sign in to
                account <?php echo $me; ?> again, then come back to
                <a href="<?php echo $e(Site::url('account/merge')); ?>?request=<?php echo (int) $request['requestID']; ?>">this page</a>
                (it is also linked from your Account page while the request is open).
            </p>
        <?php elseif ($second === true): ?>
            <form method="post" action="<?php echo $e(Site::url('account/merge/save')); ?>" class="card card-body mb-4" data-merge-state="final">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="requestID" value="<?php echo (int) $request['requestID']; ?>">
                <input type="hidden" name="action" value="agree">
                <p>Account <?php echo $other; ?> has agreed in this browser. This is the last step.</p>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="moveSignIns" value="1" id="mergeMoveSignIns">
                    <label class="form-check-label" for="mergeMoveSignIns">
                        Bring account <?php echo $dupId; ?>'s Microsoft or Google sign-ins and passkeys to account
                        <?php echo $keptId; ?>, so they open it. If you leave this unticked they are deleted.
                        Account <?php echo $dupId; ?>'s password is never moved.
                    </label>
                </div>
                <?php // The number is deliberately NOT printed in this label: typing it must mean knowing which account closes. ?>
                <label for="mergeTyped" class="form-label">To confirm, type the number of the account that will be closed</label>
                <input type="text" inputmode="numeric" id="mergeTyped" name="typedDuplicate" class="form-control mb-3" required>
                <button type="submit" class="btn btn-danger">Merge the accounts. This cannot be undone</button>
            </form>
        <?php else: ?>
            <form method="post" action="<?php echo $e(Site::url('account/merge/save')); ?>" class="mb-4" data-merge-state="first">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="requestID" value="<?php echo (int) $request['requestID']; ?>">
                <input type="hidden" name="action" value="agree">
                <button type="submit" class="btn btn-primary">I agree, for this account</button>
            </form>
        <?php endif; ?>

        <form method="post" action="<?php echo $e(Site::url('account/merge/save')); ?>" class="mb-4">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="requestID" value="<?php echo (int) $request['requestID']; ?>">
            <input type="hidden" name="action" value="cancel">
            <button type="submit" class="btn btn-outline-secondary">These are not both mine: cancel the request</button>
        </form>

        <?php if ($mergePreview !== null): ?>
            <h2 class="h5 mt-4"><?php echo $mergeShowClashes === true ? 'What the merge would do' : 'This account\'s own records'; ?></h2>
            <?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'merge-counts.php'; ?>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
