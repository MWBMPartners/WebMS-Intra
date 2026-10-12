<?php
// Path: _core/TimeLimit.php
/**
 * -----------------------------------------------------------------------------
 * TimeLimit — ask PHP for more time, safely ⏱️ (#563)
 * -----------------------------------------------------------------------------
 * The one place in the portal that raises PHP's time limit for a long job
 * (refreshing an outside calendar, building giving statements, running the
 * off-site backup).
 *
 * WHY THIS EXISTS. Six pages used to call `@set_time_limit(...)` directly.
 * Some shared hosts switch that function off (the `disable_functions`
 * setting). On PHP 8 a switched-off function does not exist, so calling it
 * stops the page with "Call to undefined function set_time_limit()". The `@`
 * in front does NOT help: it hides warnings, not this kind of error. (Tested
 * on PHP 8.5.10 with `-d disable_functions=set_time_limit`.) So on those hosts
 * a calendar refresh, a statements run and the backup all crashed at their
 * first line.
 *
 * WHY "NEVER LOWER". `set_time_limit()` RESTARTS PHP's count from zero each
 * time it is called. Four of the six pages used to set 300 seconds without
 * looking, which would cut short a longer limit the host or an earlier line
 * had already given. And calling it again inside a loop, once per item, would
 * mean the limit never fires at all. So this only acts when the limit is
 * genuinely too low. A limit of 0 means "no limit" (that is what a command
 * line gives) and is left alone.
 *
 * WHAT THIS CANNOT DO. On a host that has switched the function off, nothing
 * can raise the limit: the job can still be cut off at the host's own limit.
 * The method says so by returning false, and the caller carries on.
 * `FeedAdmin::allowTimeForLockedWork()` makes the same check on its own (its
 * self-test counts its calls), so it is deliberately left as it is.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/563
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class TimeLimit
{
    /**
     * Make sure PHP will allow this request at least `$seconds` seconds.
     *
     * - No limit at all (0), or a limit already big enough: nothing is done and
     *   true is returned. The limit is never lowered and the clock is not
     *   restarted.
     * - The host has switched `set_time_limit` off: nothing can be done, false
     *   is returned, and the request carries on with whatever limit it has.
     * - Otherwise the limit is raised to `$seconds`, and true is returned if
     *   PHP accepted that.
     *
     * @param int $seconds The least time the job needs, in seconds.
     *
     * @return bool True when the request now has at least that long (or has no
     *              limit); false when the host would not allow the change.
     */
    public static function atLeast(int $seconds): bool
    {
        $currentLimit = (int) ini_get('max_execution_time');
        if ($currentLimit === 0 || $currentLimit >= $seconds) {
            return true;
        }

        if (function_exists('set_time_limit') === false) {
            return false;
        }

        return @set_time_limit($seconds);
    }
}
