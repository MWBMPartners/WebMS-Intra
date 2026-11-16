<?php
// Path: _core/MergeRefused.php
/**
 * -----------------------------------------------------------------------------
 * A merge refused part-way through its own transaction (#1064)
 * -----------------------------------------------------------------------------
 * Thrown by `AccountMerge::applyMerge()` when something it finds under its
 * locks means the merge must not go ahead — two copies that now say different
 * things, or a unique key that a row written meanwhile now makes clash — and
 * caught by `AccountMerge::agree()`, which rolls the whole merge back and
 * answers the refusal code. It is never shown to anybody as an error: the
 * person reads `AccountMerge::personRefusalWords()` for the code.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1064
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class MergeRefused extends \RuntimeException
{
    /** @param string $refusal One of AccountMerge's R_* codes. */
    public function __construct(private readonly string $refusal)
    {
        parent::__construct('The merge was refused: ' . $refusal);
    }

    /** The refusal code. */
    public function code(): string
    {
        return $this->refusal;
    }
}
