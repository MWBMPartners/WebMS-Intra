<?php
// Path: web/_core/maillib/src/Clock.php
/**
 * -----------------------------------------------------------------------------
 * Clock — the two kinds of time the mail library needs ⏱️
 * -----------------------------------------------------------------------------
 * The library never asks PHP for the time itself. It is handed a clock, so a
 * test can hand in one it controls, and so the library carries no hidden link
 * to the machine it runs on.
 *
 * Two different questions, kept apart on purpose:
 *   - `now()`   "what is the date and time?" For the Date line of a message
 *               and for judging whether a certificate has expired.
 *   - `ticks()` "how long has it been?" For time limits. This must come from a
 *               timer that only ever moves forward, because the wall clock can
 *               jump (a server correcting its time), and a limit measured on
 *               it could then end at once or never.
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

interface Clock
{
    /**
     * The moment now, as whole seconds since 1 January 1970 UTC. A moment, not
     * a time on a wall clock: it has no time zone.
     */
    public function now(): int;

    /**
     * Seconds on a steady timer. Only the difference between two readings
     * means anything; a single reading is not a date.
     */
    public function ticks(): float;
}
