<?php
/**
 * -----------------------------------------------------------------------------
 * 🚧 One place that resolves a LIMIT across the three tiers (#668)
 * -----------------------------------------------------------------------------
 * Path: web/_core/SettingLimit.php
 *
 * WHY THIS EXISTS
 * ---------------
 * One installation may serve many organisations, and **whoever administers the
 * installation may be a different person from the administrators of the
 * organisations on it** — a hosting provider and its customers is the ordinary
 * case. So for a setting that is a LIMIT, the boundary between the two is a
 * boundary between PARTIES, not a convenience for one person wearing two hats.
 *
 * The rule (owner, 29 October 2026, application-wide): an organisation may hold
 * itself to a LOWER limit; it may NEVER raise its own above what the
 * installation allows. If the installation allows one year, an organisation may
 * choose six months and may not choose three years.
 *
 * This is the OPPOSITE of the ordinary settings rule, where an organisation's
 * row simply overrides the installation-wide one — and `$SETTINGS` has ALREADY
 * done that merge by the time any page runs, so the merged array cannot express
 * the rule on its own.
 *
 * THE RULE IS min(MERGED, INSTALLATION), AND THAT IS WORTH EXPLAINING
 * -------------------------------------------------------------------
 * It looks as though this needs to know whether an organisation has a row of
 * its own. It does not, and the reason is worth keeping because it is what
 * makes the whole sweep tractable. The merged value already IS:
 *
 *   * the organisation's own value, where it has a row; and
 *   * the installation's value, where it has not.
 *
 * So taking the smaller of the merged value and the installation's value gives
 * the right answer in all three cases, with no extra query:
 *
 *   organisation has no row     -> min(inst, inst)   = inst
 *   organisation chose LOWER    -> min(lower, inst)  = lower
 *   organisation chose HIGHER   -> min(higher, inst) = inst   (capped)
 *
 * Then a hard maximum in code caps both, so three tiers each unable to loosen
 * the one above it: code, then installation, then organisation.
 *
 * `Portal\Core\EventRecurrence::limitsFrom()` was the first reader built this
 * way, for repeating events, and it stays as it is: it resolves TWO limits at
 * once from arrays it is handed, which suits its callers. This class is the
 * general form for everything else, and it reads the layers itself.
 *
 * FOUR THINGS THAT ARE EASY TO GET WRONG
 * --------------------------------------
 * Point 1 was FOUND by a planted check while #668's first reader was built
 * (check H04 of `event-recurrence-selftest.php`). Points 2 to 4 are the
 * owner's rules of 29 October 2026, PINNED by checks afterwards — pinned is
 * not the same as found, and an earlier version of this comment claimed all
 * four were found, which overstates what the checks have actually caught:
 *
 *   1. "NOT CHOSEN" MUST FOLLOW THE INSTALLATION, not the built-in default.
 *      Resolving an organisation's missing value to the default makes the
 *      default cap the installation, so an installation could lower a limit but
 *      never raise one. Here that falls out of using the merged value, which is
 *      already the installation's where the organisation has chosen nothing.
 *   2. ENFORCE IT IN THE HANDLER, never only in a `max` on the input. A `max`
 *      is a courtesy to somebody filling a form in; it is not a boundary
 *      between two parties, because anything can be posted.
 *   3. REFUSE THE NUMBER WITH THE REASON; never quietly clamp it. Silently
 *      reducing a number somebody else typed is how a person comes to believe a
 *      limit is in force when it is not. `whyNotAllowed()` is for that, and
 *      `effective()` is for READING — they are deliberately separate.
 *   4. RECORD THE REFUSAL. A request to exceed the installation's limit crosses
 *      a line between two parties.
 *
 * THREE QUESTIONS, THREE SOURCES, ONE RULE (#685, #722)
 * -----------------------------------------------------
 * The rule is asked in three different situations, and the difference between
 * them is WHERE the two numbers come from, never what the rule is:
 *
 *   `effective()`        the organisation being SERVED by this request. Reads
 *                        the merged `$SETTINGS`. For drawing a page.
 *   `cappedForSite()`    one NAMED organisation, for a job that walks them all.
 *                        The caller reads that organisation's own figure and
 *                        hands it in. For a clear-out (#685).
 *   `whyNotAllowedFor()` a SAVE, judged against the installation's row read
 *                        LIVE and locked inside the transaction (#687).
 *
 * All three end in `smallerOfTwo()`, which is the only place the arithmetic —
 * including the zero rule below — is written. Adding a fourth source means
 * adding a reader, never a second copy of the rule: two readers that disagree
 * about what a job will do is the fault `MailQueue::numbers()` had to fix.
 *
 * WHAT THIS CLASS CANNOT DO
 * -------------------------
 *   - It does not know WHICH settings are limits. There is no list here, and
 *     there deliberately is not one: whether a number is a limit, and which
 *     direction is the harmful one, is a judgement per setting. A list in this
 *     file would be believed without being checked.
 *   - It does not handle a limit where SMALLER is the dangerous direction (a
 *     minimum password length, say). `effective()` takes the smaller of two on
 *     purpose; a minimum needs the larger, and that is a different method
 *     nobody has needed yet.
 *   - It says nothing about a non-numeric setting.
 *   - For a `$zeroIsLargest` key it cannot tell "for ever" from "the hard
 *     maximum" in the number it returns: both come back as `$hardMax` unless
 *     BOTH levels mean for ever, in which case it returns 0. Ask
 *     `installationAllowsForEver()` when that difference matters.
 *
 * ZERO, WHICH IS THE TRAP AND IS NOT A CORNER CASE
 * ------------------------------------------------
 * For several of these settings 0 means "keep for ever" or "no limit" — so it
 * is the LARGEST value there is, not the smallest. Compared as the number 0 it
 * sails through any limit, because 0 is less than everything.
 *
 * `$zeroIsLargest` turns that on, and it has to be read AT BOTH LEVELS or it
 * makes things worse rather than better. The first version of this class
 * converted only the POSTED number, in the one handler, and the independent
 * check broke it in both directions within minutes:
 *
 *   * an installation that chose 0 — "keep for ever", which its own form
 *     offers — got a ceiling of 0, so EVERY organisation save was refused,
 *     including a TIGHTER one. 30 days was refused on an installation that
 *     allowed for ever, and the organisation could not change its other
 *     settings either, because the refusal exits before all three writes.
 *   * an installation that chose 3650 — ten years, the most its form offers —
 *     let an organisation store 0 and keep the data FOR EVER, which is
 *     strictly longer, because 3650 > 3650 is false.
 *
 * So the rule is stated once, here: **"for ever" is allowed only when the
 * installation itself allows for ever.** Anything else is compared as days,
 * with for-ever standing in as the hard maximum.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/668
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class SettingLimit
{
    /**
     * 🏢 What the INSTALLATION allows for one limit, on its own.
     *
     * Never null: an installation that has chosen nothing still has a number,
     * the built-in default. A page needs this to SAY what the installation
     * permits, and a save handler needs it to refuse a bigger number.
     *
     * It reads `InstallationSettings::layer()` rather than
     * `$SETTINGS_INSTALLATION`, and that is not a style choice: **a page
     * inherits only `$mysqli` and `$SETTINGS`** (see `.claude/rules/app-pages.md`),
     * so the global is not there and reading it gives null in silence.
     *
     * When start-up has not handed the layer over at all, `layer()` answers an
     * empty array and this falls back to the built-in default — the SAFE
     * answer for a boundary between two parties, because allowing less is
     * recoverable and allowing more is not.
     *
     * For a `$zeroIsLargest` key a stored 0 comes back as `$hardMax`, because
     * that is the ceiling to COMPARE against and to put in a form's `max`.
     * Ask `installationAllowsForEver()` to find out whether it really said
     * "for ever" — the two answers are different questions and a page needs
     * both.
     *
     * @param string $dotKey        The setting, dotted: 'attend.detailRetentionDays'.
     * @param int    $default       The built-in default, used when nothing is stored.
     * @param int    $hardMax       The most this may ever be, whatever is stored.
     * @param bool   $zeroIsLargest True when 0 means "for ever" / "no limit".
     *
     * @return int
     */
    public static function installationAllows(string $dotKey, int $default, int $hardMax, bool $zeroIsLargest = false): int
    {
        return self::ceilingFrom(
            self::fromLayer(InstallationSettings::layer(), $dotKey),
            $default,
            $hardMax,
            $zeroIsLargest
        );
    }

    /**
     * 🏢 The same ceiling, worked out from a value the CALLER has read.
     *
     * Why this exists, and it is not a convenience: a save handler has to judge
     * the ceiling LIVE, from the database, inside the transaction that writes
     * and with the installation's own row locked. `installationAllows()` reads
     * the REQUEST'S SNAPSHOT, taken at start-up, which is right for drawing a
     * page and wrong for judging a save — #687 measured two ordinary web
     * requests storing fifty years under a ceiling of seven, 60 pairs out of
     * 60, because the gate compared against the snapshot.
     *
     * #687 answered that by writing the arithmetic out a second time inside
     * `GivingRetention`. This method is the alternative: the CALLER decides
     * where the stored text comes from (the layer, or a locked `SELECT`), and
     * the RULE stays here, in one place, for both. Nothing about the rule is
     * repeated anywhere.
     *
     * `$stored` is whatever was read: the text out of a settings row, an int,
     * or null when there is no row at all. Anything unusable falls back to the
     * default, exactly as a value out of the layer does.
     *
     * @param mixed $stored        What the installation has stored, however it was read.
     * @param int   $default       The built-in default, used when nothing is stored.
     * @param int   $hardMax       The most this may ever be.
     * @param bool  $zeroIsLargest True when 0 means "for ever" / "no limit".
     *
     * @return int
     */
    public static function ceilingFrom(mixed $stored, int $default, int $hardMax, bool $zeroIsLargest = false): int
    {
        $value = self::oneLimit($stored, $default, $hardMax);
        if ($zeroIsLargest === true && $value === 0) {
            return $hardMax;
        }

        return $value;
    }

    /**
     * ♾️ Does a stored value mean "for ever" — judged from a value the caller read?
     *
     * The companion to `ceilingFrom()`, for the same reason: a save handler
     * reading the row live needs BOTH facts about the ceiling, and must get
     * them from ONE read, or the two can disagree.
     *
     * @param mixed $stored  What the installation has stored, however it was read.
     * @param int   $default The built-in default.
     * @param int   $hardMax The most this may ever be.
     *
     * @return bool
     */
    public static function allowsForEverFrom(mixed $stored, int $default, int $hardMax): bool
    {
        return self::oneLimit($stored, $default, $hardMax) === 0;
    }

    /**
     * ♾️ Does the INSTALLATION allow "for ever" (a stored 0) for this limit?
     *
     * Only meaningful for a `$zeroIsLargest` key. A page asks it to decide
     * whether to offer 0 at all: offering it where the installation does not
     * allow it is a box whose own Save refuses it, which is the fault #675 was
     * about.
     *
     * @param string $dotKey  The setting, dotted.
     * @param int    $default The built-in default.
     * @param int    $hardMax The most this may ever be.
     *
     * @return bool
     */
    public static function installationAllowsForEver(string $dotKey, int $default, int $hardMax): bool
    {
        return self::allowsForEverFrom(self::fromLayer(InstallationSettings::layer(), $dotKey), $default, $hardMax);
    }

    /**
     * 🏢 The limit actually in force for the organisation being served.
     *
     * The smaller of what this organisation has (the merged value) and what the
     * installation allows, then capped by the hard maximum in code. See the
     * class comment for why the merged value is the right thing to compare.
     *
     * FOR READING ONLY. A save handler must ask whyNotAllowed() and refuse,
     * never store what this gives back: quietly storing a reduced number is
     * the clamping this rule exists to prevent.
     *
     * FOR A `$zeroIsLargest` KEY the answer is a number of days (or bytes, or
     * whatever), with "for ever" standing in as `$hardMax` — EXCEPT where both
     * levels mean for ever, which comes back as 0. So an organisation that
     * chose for ever on an installation allowing 90 days gets 90, and one that
     * chose for ever on an installation that also allows for ever gets 0.
     *
     * @param string $dotKey        The setting, dotted.
     * @param int    $default       The built-in default.
     * @param int    $hardMax       The most this may ever be.
     * @param bool   $zeroIsLargest True when 0 means "for ever" / "no limit".
     *
     * @return int
     */
    public static function effective(string $dotKey, int $default, int $hardMax, bool $zeroIsLargest = false): int
    {
        $allowedRaw = self::oneLimit(self::fromLayer(InstallationSettings::layer(), $dotKey), $default, $hardMax);
        $mergedRaw  = self::oneLimit(self::fromLayer(App::settings(), $dotKey), $default, $hardMax);

        return self::smallerOfTwo($mergedRaw, $allowedRaw, $hardMax, $zeroIsLargest);
    }

    /**
     * 🧹 One NAMED organisation's limit, held to what the installation allows.
     *
     * WHY THIS IS A DIFFERENT METHOD FROM `effective()`, which is the whole
     * point of #685. `effective()` answers for the organisation being SERVED by
     * this request, because it reads the merged `$SETTINGS`. A scheduled job has
     * no served organisation and `$SETTINGS` is merged once for the whole run
     * (#683, where one organisation's support address reached another's
     * members), so a clear-out that walks every organisation cannot use it. It
     * needs a per-organisation answer for an organisation it is not "in".
     *
     * THE CALLER READS ITS OWN FIGURE AND HANDS IT IN, and that is deliberate:
     * what a stored value MEANS differs between settings, and those decisions
     * are already written down beside each one. `AnonymousCheckins` reads a
     * negative number of days as "keep for ever", because for a clear-out doing
     * nothing on a number nobody meant to type is the safe direction;
     * `TranscriptRetention` reads the same negative as "not chosen". Both are
     * right for their own setting, neither belongs in here, and folding them
     * into one rule would silently change one of them.
     *
     * What IS here is the three-tier rule, and it is the same arithmetic
     * `effective()` uses — written once, in `smallerOfTwo()`.
     *
     * A SAVE-TIME CAP IS NOT A CAP (#668). This is why: a row written by hand,
     * restored from a whole-database backup, or simply stored before the
     * save-time refusal existed is still obeyed by the job unless the READER
     * caps as well. That was #685 — an organisation holding attendance personal
     * data for ten years on an installation intending ninety days, with nothing
     * refusing it, because the only cap was on the form.
     *
     * @param int    $own           The figure the caller read for that organisation.
     * @param string $dotKey        The setting, dotted.
     * @param int    $default       The built-in default.
     * @param int    $hardMax       The most this may ever be.
     * @param bool   $zeroIsLargest True when 0 means "for ever" / "no limit".
     *
     * @return int
     */
    public static function cappedForSite(int $own, string $dotKey, int $default, int $hardMax, bool $zeroIsLargest = false): int
    {
        $allowedRaw = self::oneLimit(self::fromLayer(InstallationSettings::layer(), $dotKey), $default, $hardMax);

        return self::smallerOfTwo($own, $allowedRaw, $hardMax, $zeroIsLargest);
    }

    /**
     * ⚙️ The three-tier rule itself, in ONE place: the smaller of the two
     *    levels, with zero read as the largest value where that is what it
     *    means.
     *
     * Written out here rather than twice because it has already been got wrong
     * in both directions (see the class comment), and because two copies of it
     * is how `effective()` and a per-organisation reader would come to
     * disagree about what a job will do — the fault `MailQueue::numbers()` had
     * to fix for the four queue numbers.
     *
     * Note the hard maximum caps BOTH sides: `$allowed` is already capped by
     * `oneLimit()`, and `min()` against it caps `$own` too, so a figure above
     * the hard maximum can never come back out.
     *
     * @param int  $own           The organisation's figure.
     * @param int  $allowed       What the installation allows.
     * @param int  $hardMax       The most this may ever be.
     * @param bool $zeroIsLargest True when 0 means "for ever" / "no limit".
     *
     * @return int
     */
    private static function smallerOfTwo(int $own, int $allowed, int $hardMax, bool $zeroIsLargest): int
    {
        if ($zeroIsLargest === false) {
            return min($own, $allowed);
        }

        // ♾️ "For ever" survives only where BOTH levels mean it. Otherwise it
        //    stands in as the hard maximum so the comparison is on days.
        if ($own === 0 && $allowed === 0) {
            return 0;
        }

        return min(
            $own === 0 ? $hardMax : $own,
            $allowed === 0 ? $hardMax : $allowed
        );
    }

    /**
     * 🚫 Why a posted number may not be saved for this organisation — '' when
     *    it may be.
     *
     * The answer is a REASON for the caller to put into its own words, in its
     * own place, in the words its own page uses. Two of them:
     *
     *   'above-installation' — the installation allows less than this.
     *   'above-hard-max'     — more than the portal will ever store.
     *
     * A handler asks this and REFUSES. It does not clamp. See point 3 in the
     * class comment for why that matters more than it looks.
     *
     * THE CALLER PASSES THE RAW POSTED NUMBER. For a `$zeroIsLargest` key the
     * conversion happens here, not in the handler — that is the whole point of
     * the flag, and doing it in the handler is what broke both ends before.
     * A posted 0 means "for ever", which is allowed ONLY where the
     * installation itself allows for ever; there is no arithmetic that
     * expresses that, so it is a condition of its own.
     *
     * @param int    $posted        The number somebody typed, exactly as typed.
     * @param string $dotKey        The setting, dotted.
     * @param int    $default       The built-in default.
     * @param int    $hardMax       The most this may ever be.
     * @param bool   $zeroIsLargest True when 0 means "for ever" / "no limit".
     *
     * @return string '' when the number may be saved; otherwise a reason.
     */
    public static function whyNotAllowed(int $posted, string $dotKey, int $default, int $hardMax, bool $zeroIsLargest = false): string
    {
        return self::whyNotAllowedFor(
            $posted,
            self::fromLayer(InstallationSettings::layer(), $dotKey),
            $default,
            $hardMax,
            $zeroIsLargest
        );
    }

    /**
     * 🔒 The same refusal, judged against a ceiling the CALLER has read.
     *
     * THIS IS THE ONE A SAVE SHOULD BE JUDGED BY, and the reason is #687's,
     * measured rather than argued. `whyNotAllowed()` reads the request's
     * settings snapshot, taken at start-up. Two overlapping ordinary web
     * requests — one setting the ceiling to seven years, one storing fifty —
     * both succeeded, 60 pairs out of 60, because neither saw what the other
     * had just committed. No hand-written SQL and no restored backup were
     * involved, which is exactly what five places in that package had said was
     * the only way in.
     *
     * So a handler re-reads the installation's own row LIVE, `FOR UPDATE`,
     * INSIDE the transaction that writes, and hands the text in here. A lock
     * outside a transaction is decoration (#697).
     *
     * The rule is not repeated: this method IS the rule, and
     * `whyNotAllowed()` is a thin wrapper that takes the value out of the
     * layer instead.
     *
     * @param int   $posted        The number somebody typed, exactly as typed.
     * @param mixed $ceilingStored What the installation has stored, however it was read.
     * @param int   $default       The built-in default.
     * @param int   $hardMax       The most this may ever be.
     * @param bool  $zeroIsLargest True when 0 means "for ever" / "no limit".
     *
     * @return string '' when the number may be saved; otherwise a reason.
     */
    public static function whyNotAllowedFor(int $posted, mixed $ceilingStored, int $default, int $hardMax, bool $zeroIsLargest = false): string
    {
        if ($zeroIsLargest === true && $posted === 0) {
            return self::allowsForEverFrom($ceilingStored, $default, $hardMax) === true
                ? ''
                : 'above-installation';
        }
        if ($posted > $hardMax) {
            return 'above-hard-max';
        }
        if ($posted > self::ceilingFrom($ceilingStored, $default, $hardMax, $zeroIsLargest)) {
            return 'above-installation';
        }

        return '';
    }

    /**
     * 💬 The refusal in words, for a person reading a page.
     *
     * Plain English, says what the limit IS, and never blames the person. It
     * deliberately does NOT name the setting key or say which administrator set
     * the limit: the two levels may be different parties, and an organisation's
     * administrator has no business being told who to argue with.
     *
     * ⚠️ THE ADVICE USED TO CONTRADICT THE REFUSAL FOR A POSTED ZERO, and the
     *    sentence was wrong in the direction that reads as help. Where 0 means
     *    "for ever" it is the LARGEST value, so posting 0 under a ceiling of 7
     *    is refused — and the words then said "This installation allows at
     *    most 7 days … You can choose 7 or fewer", **and 0 is fewer than 7**.
     *    Somebody following that advice would post the very number just
     *    refused. Reachable only outside a browser, because the box's own
     *    `min` is 1 when for-ever is not allowed (#722) — but a handler is the
     *    boundary between two parties and must not depend on the form.
     *
     *    So the caller says whether the number it refused was the "for ever"
     *    zero, with `$postedWasForEver`. It is a FOURTH argument with a
     *    default rather than a new method, because the words must stay in ONE
     *    place: a second wording method is how a page comes to disagree with a
     *    handler. A caller that does not pass it gets exactly what it got
     *    before, which is right for every setting where 0 is simply a small
     *    number.
     *
     * @param string $reason           What whyNotAllowed() answered.
     * @param int    $allowed          What the installation allows.
     * @param string $units            The word for the number: 'days', 'megabytes'.
     * @param bool   $postedWasForEver True when the refused number was 0 AND 0 means "for ever" here.
     *
     * @return string
     */
    public static function refusalWords(string $reason, int $allowed, string $units, bool $postedWasForEver = false): string
    {
        if ($reason === 'above-hard-max') {
            return 'That number is larger than this portal will store. Nothing was saved.';
        }
        if ($reason === 'above-installation' && $postedWasForEver === true) {
            // 🚫 "0 or fewer" would be nonsense and "7 or fewer" would include
            //    the 0 just refused, so neither range is offered: the sentence
            //    names the one thing that is not allowed and the smallest and
            //    largest that are.
            return 'This installation does not allow "keep for ever" for this setting, so nothing was saved. '
                . 'Choose a number of ' . $units . ' between 1 and ' . $allowed . '. '
                . 'Ask whoever runs this installation if you need to keep it indefinitely.';
        }
        if ($reason === 'above-installation') {
            return 'This installation allows at most ' . $allowed . ' ' . $units
                . ', so that number was not saved. You can choose ' . $allowed . ' or fewer. '
                . 'Ask whoever runs this installation if you need more.';
        }

        return '';
    }

    /**
     * ⚙️ One dotted key out of a settings array, which the loader nests.
     *
     * `assign_setting()` in bootstrap.php turns 'a.b.c' into
     * `$arr['a']['b']['c']`, so this walks the same way. Anything missing, or a
     * branch that is not an array, answers null — which the callers read as
     * "nothing usable is stored".
     *
     * @param array<string,mixed> $layer
     * @param string              $dotKey
     *
     * @return mixed
     */
    private static function fromLayer(array $layer, string $dotKey): mixed
    {
        $node = $layer;
        foreach (explode('.', $dotKey) as $part) {
            if (is_array($node) === false || array_key_exists($part, $node) === false) {
                return null;
            }
            $node = $node[$part];
        }

        return $node;
    }

    /**
     * ⚙️ One stored ceiling, read defensively.
     *
     * A settings value is always text, so '12' is the ordinary case and
     * 'twelve', '' and '-4' all have to be survivable. Anything unusable falls
     * back to the default, and the hard maximum caps whatever comes out —
     * including the default, so a mistaken default cannot raise a ceiling.
     *
     * ZERO IS KEPT. It is a real answer for several of these settings ("keep
     * for ever", "no limit"), and treating it as "nothing stored" would turn a
     * deliberate choice into the default. A caller for which 0 is meaningless
     * should say so itself.
     *
     * @param mixed $stored
     * @param int   $default
     * @param int   $hardMax
     *
     * @return int
     */
    private static function oneLimit(mixed $stored, int $default, int $hardMax): int
    {
        $value = $default;
        if (is_int($stored) === true) {
            $value = $stored;
        } elseif (is_string($stored) === true && preg_match('/^-?\d{1,18}$/', trim($stored)) === 1) {
            $value = (int) trim($stored);
        }
        if ($value < 0) {
            $value = $default;
        }

        return min($value, $hardMax);
    }
}
