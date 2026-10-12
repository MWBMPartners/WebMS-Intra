<?php
// Path: web/_core/maillib/src/SystemClock.php
/**
 * -----------------------------------------------------------------------------
 * SystemClock — the real clock ⏱️
 * -----------------------------------------------------------------------------
 * The ordinary answer to the two questions in `Clock`: the machine's own time,
 * and PHP's steady timer (`hrtime`), which the operating system promises never
 * runs backwards.
 *
 * @package   MWBM\Mail
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace MWBM\Mail;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }

    public function ticks(): float
    {
        // hrtime(true) counts in billionths of a second.
        return hrtime(true) / 1_000_000_000;
    }
}
