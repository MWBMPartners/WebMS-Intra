<?php
// Path: web/_core/OffsiteSettings.php
/**
 * -----------------------------------------------------------------------------
 * What an off-site backup setting MEANS, decided in one place 📤
 * -----------------------------------------------------------------------------
 * The six `backup.offsite.*` settings are typed on
 * /admin/maintenance/offsite-backup and used by a shell script on the server.
 * Three of them end up on a command line. This class is the ONE place that
 * decides whether a stored value can be used, and why not when it cannot.
 *
 * WHY IT EXISTS, AND WHY IT IS A CLASS RATHER THAN A COPIED PATTERN (#671)
 * -----------------------------------------------------------------------------
 * The rules were written first inside `tools/offsite-backup/read-offsite-settings.php`,
 * the bridge that hands the settings to the script. That left the admin page
 * unable to ask the same question — so the page accepted an rclone remote with
 * a space or a capital in it (an ordinary typo), stored it, said "Off-site
 * backup settings saved.", showed it back in the box, and badged the settings
 * "in force", while every run quietly used the remote written inside the script
 * file. Where the customer had set that fallback to a real remote — and the
 * setup guide tells them to — the copy went somewhere the administrator had not
 * chosen, and nothing in the portal said so.
 *
 * Writing the pattern a second time on the page would have been worse: two
 * guards that can drift apart are worse than one. So the page and the bridge
 * both ask this class, and the answer cannot differ between them.
 *
 * THE RULE THESE PATTERNS FOLLOW, which is not the usual one for input
 * -----------------------------------------------------------------------------
 * These values reach a shell. So the question is never "is this a valid rclone
 * remote" — it is "will this portal put this text on a command line". A value
 * that fails is REFUSED, never escaped and passed on: an escaped destination is
 * still a destination nobody chose, and a copy of the whole database is what is
 * being sent. A value starting with a dash is refused separately, because
 * `rclone` and the mail program each read one as an option rather than a value.
 *
 * So a value this class rejects is not necessarily a bad value. It is one this
 * portal will not use, and the wording says so.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 * -----------------------------------------------------------------------------
 *   - It cannot tell whether an rclone remote EXISTS, or a bucket is writable.
 *     Only the sending program knows that, at run time.
 *   - It does not decide the S3 bucket or the SFTP host: there is no box for
 *     either, so those stay in the script's own file (#688).
 *   - It is not the only guard. `sync-offsite.sh` checks every value again in
 *     bash, on purpose: that is the guard that has to hold if the bridge is
 *     ever replaced or edited on the server. Its patterns are kept written the
 *     same way as these so the two can be read side by side.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/671
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class OffsiteSettings
{
    /**
     * The longest a stored value may be before it is refused on length alone.
     *
     * Far more than any real value needs: an rclone remote is a short name and
     * a path, and an e-mail address has a 254-character ceiling of its own.
     *
     * It exists because the settings boxes have no length limit of their own —
     * `settingValue` is MEDIUMTEXT — and a very large value once stopped the
     * backup outright: the script read the bridge's first line through a pipe,
     * a value of about 128 KB overflowed it (about 64 KB on Linux, the real
     * target), and under `set -o pipefail` the whole run died before anything
     * was packed, reporting "a step failed" and naming a closing `fi` (#671).
     *
     * THIS IS NOT "the second of two guards", WHICH IS WHAT THIS COMMENT SAID
     * FIRST. The script no longer reads that line through a pipe, so the
     * broken pipe cannot happen again — but that fix alone does NOT keep the
     * backup working. Measured on 31 October 2026 by raising this constant and
     * leaving the script's fix in place: a 200,000-character remote then gets
     * past the settings step and `rclone` refuses it instead ("Failed to
     * create file system for destination"), so the run still fails and no copy
     * is sent. The two guards stop different things, and THIS one is the only
     * one that keeps a backup happening.
     */
    public const MAX_LENGTH = 256;

    /** The three programs the script can send with. */
    public const DESTINATIONS = ['rclone', 's3', 'sftp'];

    /** The lowest and highest each "copies to keep" figure may be. */
    public const KEEP_WEEKLY_MIN  = 1;
    public const KEEP_WEEKLY_MAX  = 52;
    public const KEEP_MONTHLY_MIN = 1;
    public const KEEP_MONTHLY_MAX = 60;

    /**
     * An rclone remote: a name, a colon, then an optional path.
     *
     * Exactly the characters one needs and nothing else — no space, no quote,
     * no backtick, no dollar, no semicolon, no newline.
     */
    private const REMOTE_PATTERN = '/^[A-Za-z0-9_.-]+:[A-Za-z0-9_.\/-]*$/';

    /**
     * A plain e-mail address this portal is willing to pass to a program.
     *
     * Narrower than the addresses the standard allows on purpose. `filter_var`
     * accepts forms that are awkward to pass as an argument — a quoted local
     * part may hold a space, and the permitted marks include the backtick and
     * the dollar. None is actually dangerous, because bash does not re-read the
     * CONTENT of a variable it has expanded (measured, not assumed), but this
     * pattern is kept narrow so that it and the matching test in the shell
     * script can be read side by side and seen to agree.
     */
    private const ALERT_PATTERN = '/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+$/';

    /**
     * Why this rclone remote cannot be used, or an empty string if it can.
     *
     * An EMPTY remote is not a fault here: it means "nothing chosen", and the
     * caller decides what that means. The page treats it as "not set yet"; the
     * bridge simply prints nothing and the script keeps its own value.
     */
    public static function whyRemoteUnusable(string $remote): string
    {
        $remote = trim($remote);
        if ($remote === '') {
            return '';
        }
        if (strlen($remote) > self::MAX_LENGTH) {
            return 'it is longer than ' . self::MAX_LENGTH . ' characters, which no real remote is';
        }
        if (str_starts_with($remote, '-') === true) {
            return 'it starts with a dash, which rclone would read as an option rather than a remote';
        }
        if (preg_match(self::REMOTE_PATTERN, $remote) !== 1) {
            return 'it is not of the form name:path using only letters, digits, dot, dash, underscore and slash'
                . ' — a space or a capital letter in the wrong place is the usual cause';
        }
        return '';
    }

    /**
     * Why this alert address cannot be used, or an empty string if it can.
     *
     * An EMPTY address is allowed and meaningful: it is a real choice meaning
     * "do not e-mail", and it has to be able to override an address left in the
     * script's own file.
     */
    public static function whyAlertUnusable(string $alert): string
    {
        $alert = trim($alert);
        if ($alert === '') {
            return '';
        }
        if (strlen($alert) > self::MAX_LENGTH) {
            return 'it is longer than ' . self::MAX_LENGTH . ' characters, which no real address is';
        }
        if (str_starts_with($alert, '-') === true) {
            return 'it starts with a dash, which the mail program would read as an option rather than an address';
        }
        // Two tests, and the narrow one decides. See ALERT_PATTERN above for why.
        if (preg_match(self::ALERT_PATTERN, $alert) !== 1
            || filter_var($alert, FILTER_VALIDATE_EMAIL) === false
        ) {
            return 'it is not a plain e-mail address this portal can pass to the server\'s mail program';
        }
        return '';
    }

    /** Whether this is one of the three programs the script knows. */
    public static function destinationIsKnown(string $destination): bool
    {
        return in_array(trim($destination), self::DESTINATIONS, true);
    }

    /**
     * One "copies to keep" figure, brought into range.
     *
     * @param string $which 'weekly' or 'monthly'.
     */
    public static function clampKeep(int $number, string $which): int
    {
        $lowest  = $which === 'monthly' ? self::KEEP_MONTHLY_MIN : self::KEEP_WEEKLY_MIN;
        $highest = $which === 'monthly' ? self::KEEP_MONTHLY_MAX : self::KEEP_WEEKLY_MAX;
        return max($lowest, min($highest, $number));
    }
}
