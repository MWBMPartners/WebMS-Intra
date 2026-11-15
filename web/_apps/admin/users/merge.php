<?php
// Path: _apps/admin/users/merge.php
/**
 * -----------------------------------------------------------------------------
 * Merge two accounts — the preview 🔀 (#1064, D-185)
 * -----------------------------------------------------------------------------
 * An administrator names the account that stays and the duplicate, and sees how
 * many of each kind of record would move, which would be dropped because the
 * kept account already has one, which are history left as they are, and why a
 * merge would be refused.
 *
 * THIS PAGE CHANGES NOTHING. The merge itself is not built: it waits on two
 * owner questions recorded on #1064 (who must agree before two accounts become
 * one, and whether the installation's administrator may do it unasked). The page
 * says so in plain words rather than offering a button that does nothing.
 *
 * WHAT IT SHOWS, AND WHAT IT NEVER SHOWS
 * -------------------------------------------------------------------------
 * Counts per kind of record, and the two accounts' name and e-mail address —
 * exactly what the Users page already shows the same administrator, and needed
 * so they do not merge the wrong two people. It never shows what a record says.
 * The names are shown ONLY once every refusal has passed, so a refused look
 * learns nothing about an account (AccountGuard's row 3).
 *
 * It is a GET page on purpose: looking changes nothing but the activity record,
 * which carries account numbers and fixed words only (#933).
 *
 * Every decision is `Portal\Core\AccountMerge`'s; this page draws its answers.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1064
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\AccountGuard;
use Portal\Core\AccountMerge;
use Portal\Core\App;
use Portal\Core\Capabilities;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

// 🛡️ An administrator of this organisation who holds the permission here. The
//    permission in every OTHER organisation either account belongs to is asked
//    by AccountMerge, after it knows the accounts are visible.
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

$refusal  = '';
$preview  = null;
$accounts = [];
if ($asked === true) {
    $actor = App::user() ?? [];
    $facts = AccountMerge::factsFor(
        $mysqli,
        (int) ($actor['userID'] ?? 0),
        AccountGuard::actorIsGlobal(),
        App::isRootAdmin(),
        Site::id(),
        AccountGuard::isSingleOrganisation(),
        $keptId,
        $duplicateId
    );
    $refusal = AccountMerge::whyNotAllowed($facts);
    if ($refusal === '') {
        $preview = AccountMerge::preview($mysqli, $keptId, $duplicateId);
        $refusal = $preview['refusal'];

        $stmt = $mysqli->prepare('SELECT `userID`, `fullName`, `emailAddress` FROM `tblUsers` WHERE `userID` IN (?, ?)');
        $stmt->bind_param('ii', $keptId, $duplicateId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $accounts[(int) $row['userID']] = $row;
        }
        $stmt->close();
    }

    // 📝 Numbers and fixed words only. A refusal records its code, never a
    //    name, so the record cannot say whether a hidden account exists.
    if ($preview !== null) {
        Logger::activity('AccountMergePreviewed', 'Previewed merging account ' . $duplicateId . ' into account ' . $keptId
            . ($refusal === '' ? '' : ' (refused: ' . $refusal . ')'));
    } else {
        Logger::activity('AccountMergeRefused', 'Merging account ' . $duplicateId . ' into account ' . $keptId
            . ' was refused: ' . $refusal);
    }
}

// 🏷️ The plain words for each treatment, in the order they are shown.
$treatmentWords = [
    AccountMerge::MOVE              => ['Would move to the account that stays', ''],
    AccountMerge::KEEP_KEPT_ON_CLASH => ['Would move, except where the account that stays already has one', 'Where it already has one, the duplicate\'s copy would be removed.'],
    AccountMerge::REFUSE_ON_CLASH   => ['Would move, unless both accounts hold one', 'If both hold one, the merge is refused: these are about money or a record somebody relies on.'],
    AccountMerge::KEEP              => ['History, left as it is', 'Activity and change records keep the number they were made under. They are not rewritten (D-116).'],
    AccountMerge::CREDENTIAL        => ['Ways to sign in, and things that act for the person', 'What happens to these has not been decided yet.'],
    AccountMerge::REFUSE_IF_ANY     => ['Stops a merge', 'An erasure request, an offboarding record or rows a restore is holding back.'],
];

$e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h1 class="mb-0"><i class="fa-solid fa-code-merge me-2"></i>Merge two accounts</h1>
    <a href="<?php echo $e(Site::url('admin/users')); ?>" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i>Back to Users
    </a>
</div>

<div class="alert alert-info">
    <p class="mb-2">
        Use this when one person has two accounts &mdash; for example after signing in with Microsoft or Google
        made a new, empty account for somebody who already had one. Choose the account that stays and the duplicate,
        and this page shows how many of each kind of record would move. It shows counts only, never what a record says.
    </p>
    <p class="mb-0">
        <strong>The merge itself is not available yet.</strong> Two decisions about it are still to be made: who has to
        agree before two accounts become one, and what happens to the duplicate's ways of signing in. Nothing on this
        page changes any account.
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

<?php if ($preview !== null): ?>
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

    <?php if ($preview['lines'] === []): ?>
        <p class="text-muted">The duplicate holds no records at all apart from the account itself.</p>
    <?php endif; ?>

    <?php foreach ($treatmentWords as $treatment => [$heading, $note]): ?>
        <?php $lines = array_values(array_filter($preview['lines'], static fn (array $l): bool => $l['treatment'] === $treatment)); ?>
        <?php if ($lines === []) { continue; } ?>
        <h2 class="h5 mt-4"><?php echo $e($heading); ?></h2>
        <?php if ($note !== ''): ?><p class="text-muted small mb-2"><?php echo $e($note); ?></p><?php endif; ?>
        <div class="portal-data-list" data-merge-treatment="<?php echo $e($treatment); ?>">
            <div class="portal-data-row portal-data-header d-none d-md-flex">
                <div class="col-md-6">Where</div>
                <div class="col-md-3 text-md-end">Records</div>
                <div class="col-md-3 text-md-end">Already held by the account that stays</div>
            </div>
            <?php foreach ($lines as $line): ?>
                <div class="portal-data-row">
                    <div class="col-12 col-md-6"><code><?php echo $e($line['table'] . '.' . $line['column']); ?></code></div>
                    <div class="col-6 col-md-3 text-md-end"><?php echo (int) $line['rows']; ?></div>
                    <div class="col-6 col-md-3 text-md-end"><?php echo (int) $line['clashes']; ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
