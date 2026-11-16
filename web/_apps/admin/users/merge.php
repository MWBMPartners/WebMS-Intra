<?php
// Path: _apps/admin/users/merge.php
/**
 * -----------------------------------------------------------------------------
 * Merge two accounts — the administrator's page 🔀 (#1064; D-185, D-190, D-191)
 * -----------------------------------------------------------------------------
 * An administrator names the account that stays and the duplicate, sees COUNTS
 * of what a merge would do and why it would be refused, and can ASK THE PERSON
 * to agree. Nothing here merges anything: the merge completes only when the
 * person signs in to both accounts and confirms (account/merge.php, D-190).
 * The open requests of this organisation are listed with a cancel button.
 *
 * WHAT IT SHOWS, AND WHAT IT NEVER SHOWS
 * -------------------------------------------------------------------------
 * Counts per kind of record, in plain words, and the two accounts' name and
 * e-mail address — what the Users page already shows the same administrator,
 * needed so they ask about the right two people. Names are shown ONLY once
 * every refusal has passed, so a refused look learns nothing about an account
 * (AccountGuard row 3). It never shows what a record says.
 *
 * Every decision is `Portal\Core\AccountMerge`'s; this page draws its answers.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   2.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1064
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\AccountGuard;
use Portal\Core\AccountMerge;
use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Capabilities;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

// 🛡️ An administrator of this organisation who holds the permission here.
if (App::isAdmin() === false || App::can(Capabilities::ACCOUNTS_MERGE) === false) {
    Router::renderError(403);
    return;
}

$pageTitle   = 'Merge two accounts';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Admin' => Site::url('admin'), 'Users' => Site::url('admin/users'), 'Merge two accounts' => ''];

// 🔢 Account numbers from the address. A non-string (`?keep[]=1`) reads as none.
$readNumber = static function (string $key): int {
    $raw = $_GET[$key] ?? '';
    if (is_string($raw) === false || preg_match('/^[0-9]{1,10}$/', $raw) !== 1) {
        return 0;
    }

    return (int) $raw;
};
$keptId      = $readNumber('keep');
$duplicateId = $readNumber('duplicate');
$asked       = $keptId > 0 || $duplicateId > 0;

$refusal      = '';
$mergePreview = null;
$accounts     = [];
if ($asked === true) {
    $actor = App::user() ?? [];
    $refusal = AccountMerge::whyNotAllowed(AccountMerge::factsFor(
        $mysqli,
        (int) ($actor['userID'] ?? 0),
        AccountGuard::actorIsGlobal(),
        App::isRootAdmin(),
        Site::id(),
        AccountGuard::isSingleOrganisation(),
        $keptId,
        $duplicateId
    ));
    if ($refusal === '') {
        $mergePreview = AccountMerge::preview($mysqli, $keptId, $duplicateId);
        $refusal      = $mergePreview['refusal'];
        $stmt = $mysqli->prepare('SELECT `userID`, `fullName`, `emailAddress` FROM `tblUsers` WHERE `userID` IN (?, ?)');
        $stmt->bind_param('ii', $keptId, $duplicateId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $accounts[(int) $row['userID']] = $row;
        }
        $stmt->close();
    }
    // 📝 Numbers and fixed words only (#933).
    Logger::activity(
        $mergePreview !== null ? 'AccountMergePreviewed' : 'AccountMergeRefused',
        $mergePreview !== null
            ? 'Previewed merging account ' . $duplicateId . ' into account ' . $keptId . ($refusal === '' ? '' : ' (refused: ' . $refusal . ')')
            : 'Merging account ' . $duplicateId . ' into account ' . $keptId . ' was refused: ' . $refusal
    );
}

$openRequests = AccountMerge::openRequests($mysqli, 0, Site::id());

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$e    = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
$csrf = $e(Auth::csrfToken());

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-code-merge me-2"></i>Merge two accounts</h1>
    <a href="<?php echo $e(Site::url('admin/users')); ?>" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i>Back to Users
    </a>
</div>

<?php if (is_string($flashMsg) === true && $flashMsg !== ''): ?>
    <div class="alert alert-<?php echo $e((string) $flashType); ?>" data-merge-flash><?php echo $e($flashMsg); ?></div>
<?php endif; ?>

<div class="alert alert-info">
    <p class="mb-2">
        Use this when one person has two accounts &mdash; for example when signing in with Microsoft or Google made
        a new, empty account for somebody who already had one. Choose the account that stays and the duplicate. This
        page shows how many of each kind of record the duplicate holds and what a merge does with each &mdash; moves
        it, or leaves the record of what was done under the old number &mdash; and never what a record says.
    </p>
    <p class="mb-0">
        <strong>You cannot merge the accounts yourself.</strong> You can ask the person to. They are e-mailed at both
        accounts' addresses, and the merge happens only when they sign in to both accounts, one after the other in
        the same browser, and confirm. Until then nothing changes. Once it happens it cannot be undone.
    </p>
</div>

<form method="get" action="<?php echo $e(Site::url('admin/users/merge')); ?>" class="row g-2 align-items-end mb-4">
    <div class="col-12 col-md-4">
        <label for="mergeKeep" class="form-label">Number of the account that stays</label>
        <input type="text" inputmode="numeric" pattern="[0-9]*" id="mergeKeep" name="keep" class="form-control"
               value="<?php echo $keptId > 0 ? (string) $keptId : ''; ?>" required>
    </div>
    <div class="col-12 col-md-4">
        <label for="mergeDuplicate" class="form-label">Number of the duplicate</label>
        <input type="text" inputmode="numeric" pattern="[0-9]*" id="mergeDuplicate" name="duplicate" class="form-control"
               value="<?php echo $duplicateId > 0 ? (string) $duplicateId : ''; ?>" required>
    </div>
    <div class="col-12 col-md-4">
        <button type="submit" class="btn btn-primary w-100"><i class="fa-solid fa-magnifying-glass me-1"></i>Show what would happen</button>
    </div>
</form>

<?php if ($asked === true && $refusal !== ''): ?>
    <div class="alert alert-warning" data-merge-refusal="<?php echo $e($refusal); ?>">
        <i class="fa-solid fa-circle-exclamation me-1"></i><?php echo $e(AccountMerge::refusalWords($refusal)); ?>
    </div>
<?php endif; ?>

<?php if ($mergePreview !== null): ?>
    <div class="portal-data-list mb-4">
        <div class="portal-data-row portal-data-header d-none d-md-flex">
            <div class="col-md-3">Account</div>
            <div class="col-md-5">Name</div>
            <div class="col-md-4">E-mail address</div>
        </div>
        <?php foreach ([[$keptId, 'Stays'], [$duplicateId, 'Duplicate']] as [$id, $role]): ?>
            <div class="portal-data-row">
                <div class="col-12 col-md-3"><strong><?php echo $e($role); ?></strong> &middot; account <?php echo (int) $id; ?></div>
                <div class="col-12 col-md-5"><?php echo $e((string) ($accounts[$id]['fullName'] ?? '')); ?></div>
                <div class="col-12 col-md-4"><small><?php echo $e((string) ($accounts[$id]['emailAddress'] ?? '')); ?></small></div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($refusal === ''): ?>
        <form method="post" action="<?php echo $e(Site::url('admin/users/merge/start')); ?>" class="mb-4" data-merge-start>
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="keep" value="<?php echo $keptId; ?>">
            <input type="hidden" name="duplicate" value="<?php echo $duplicateId; ?>">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-envelope me-1"></i>Ask the person to agree</button>
        </form>
    <?php endif; ?>

    <?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'merge-counts.php'; ?>
<?php endif; ?>

<h2 class="h5 mt-5">Requests waiting for the person</h2>
<?php if ($openRequests === []): ?>
    <p class="text-muted">None.</p>
<?php else: ?>
    <div class="portal-data-list">
        <?php foreach ($openRequests as $request): ?>
            <div class="portal-data-row" data-merge-open="<?php echo (int) $request['requestID']; ?>">
                <div class="col-12 col-md-8">
                    Account <?php echo (int) $request['duplicateId']; ?> into account <?php echo (int) $request['keptId']; ?>
                </div>
                <div class="col-12 col-md-4 text-md-end">
                    <form method="post" action="<?php echo $e(Site::url('admin/users/merge/cancel')); ?>" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="requestID" value="<?php echo (int) $request['requestID']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Cancel this request</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
