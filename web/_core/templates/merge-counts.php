<?php
// Path: _core/templates/merge-counts.php
/**
 * -----------------------------------------------------------------------------
 * The counts of a merge preview, in plain words (#1064)
 * -----------------------------------------------------------------------------
 * Drawn by BOTH the administrator's preview (admin/users/merge.php) and the
 * person's own confirmation page (account/merge.php), so the two can never
 * describe one merge differently. Shows counts only, never what a record says.
 *
 * Expects `$mergePreview`, the answer of `AccountMerge::preview()`.
 *
 * @package   Portal\Templates
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1064
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\AccountMerge;

/** @var array{lines: list<array<string, mixed>>} $mergePreview */
// `$mergeShowClashes` (default true): whether to show the two columns that
// compare with the OTHER account. The person's page passes false before both
// agreements are in, because those numbers describe an account they have not
// yet proved is theirs (#1064, adversarial check finding 1).
$mergeShowClashes = ($mergeShowClashes ?? true) === true;
if ($mergePreview['lines'] === []) {
    echo '<p class="text-muted">' . ($mergeShowClashes === true
        ? 'The duplicate holds no records at all apart from the account itself.'
        : 'This account holds no records apart from the account itself.') . '</p>';
}
// `$mergeKeptSide` (default false): the KEPT account reading its own records.
// Every per-treatment heading describes what happens to the DUPLICATE's records,
// so none is true here: one heading, AccountMerge::KEPT_SIDE_WORDS (third check,
// finding F).
$mergeKeptSide = ($mergeKeptSide ?? false) === true;
$mergeGroups   = $mergeKeptSide === true
    ? ['kept-side' => AccountMerge::KEPT_SIDE_WORDS]
    : AccountMerge::TREATMENT_WORDS;
foreach ($mergeGroups as $mergeTreatment => [$mergeHeading, $mergeNote]) {
    $mergeLines = array_values(array_filter(
        $mergePreview['lines'],
        static fn (array $l): bool => $mergeKeptSide === true || $l['treatment'] === $mergeTreatment
    ));
    if ($mergeLines === []) {
        continue;
    }
    ?>
    <h2 class="h5 mt-4"><?php echo htmlspecialchars($mergeHeading, ENT_QUOTES, 'UTF-8'); ?></h2>
    <?php if ($mergeNote !== ''): ?>
        <p class="text-muted small mb-2"><?php echo htmlspecialchars($mergeNote, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <div class="portal-data-list" data-merge-treatment="<?php echo htmlspecialchars($mergeTreatment, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="portal-data-row portal-data-header d-none d-md-flex">
            <div class="col-md-6">Kind of record</div>
            <div class="col-md-2 text-md-end">How many</div>
            <?php if ($mergeShowClashes === true): ?>
                <div class="col-md-2 text-md-end">The other account has one too</div>
                <div class="col-md-2 text-md-end">And it says something different</div>
            <?php endif; ?>
        </div>
        <?php foreach ($mergeLines as $mergeLine): ?>
            <div class="portal-data-row">
                <div class="col-12 col-md-6"><?php echo htmlspecialchars((string) $mergeLine['words'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="col-4 col-md-2 text-md-end" data-merge-rows><?php echo (int) $mergeLine['rows']; ?></div>
                <?php if ($mergeShowClashes === true): ?>
                    <div class="col-4 col-md-2 text-md-end"><?php echo (int) $mergeLine['clashes']; ?></div>
                    <div class="col-4 col-md-2 text-md-end"><?php echo (int) $mergeLine['differences']; ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}
