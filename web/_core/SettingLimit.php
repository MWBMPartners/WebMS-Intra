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
        $stored = self::oneLimit(self::fromLayer(InstallationSettings::layer(), $dotKey), $default, $hardMax);
        if ($zeroIsLargest === true && $stored === 0) {
            return $hardMax;
        }

        return $stored;
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
        return self::oneLimit(self::fromLayer(InstallationSettings::layer(), $dotKey), $default, $hardMax) === 0;
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

        if ($zeroIsLargest === false) {
            return min($mergedRaw, $allowedRaw);
        }

        // ♾️ "For ever" survives only where BOTH levels mean it. Otherwise it
        //    stands in as the hard maximum so the comparison is on days.
        if ($mergedRaw === 0 && $allowedRaw === 0) {
            return 0;
        }

        return min(
            $mergedRaw === 0 ? $hardMax : $mergedRaw,
            $allowedRaw === 0 ? $hardMax : $allowedRaw
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
        if ($zeroIsLargest === true && $posted === 0) {
            return self::installationAllowsForEver($dotKey, $default, $hardMax) === true
                ? ''
                : 'above-installation';
        }
        if ($posted > $hardMax) {
            return 'above-hard-max';
        }
        if ($posted > self::installationAllows($dotKey, $default, $hardMax, $zeroIsLargest)) {
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
     * @param string $reason  What whyNotAllowed() answered.
     * @param int    $allowed What the installation allows.
     * @param string $units   The word for the number: 'days', 'megabytes'.
     *
     * @return string
     */
    public static function refusalWords(string $reason, int $allowed, string $units): string
    {
        if ($reason === 'above-hard-max') {
            return 'That number is larger than this portal will store. Nothing was saved.';
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
