<?php
// Path: web/_core/SsoNewAccounts.php
/**
 * -----------------------------------------------------------------------------
 * May a Microsoft or Google sign-in make a NEW account? (#1042, D-185) 🔑🆕
 * -----------------------------------------------------------------------------
 * The ONE place that answers it. The Microsoft and Google callbacks in
 * `Auth.php` ask `allowed()` at the one moment it matters — when a sign-in has
 * found no linked account, no switched-on account with its e-mail address and
 * no switched-off one, and would otherwise call `createUser()` — and the
 * Integrations page asks `allowedFrom()` to say what is in force.
 *
 * WHY THIS EXISTS. A whole restore puts `tblUsers` and `tblLinkedAccounts`
 * back as they were at the snapshot. A member who linked Microsoft and then
 * changed their e-mail address after the snapshot therefore looks, to the
 * callback, exactly like a stranger signing in for the first time, and used to
 * be given a second, empty account. The callback cannot tell the two apart, so
 * the owner chose a setting rather than a guess (D-185): "A setting, default:
 * allow." Merging two accounts that belong to one person is #1064, not here.
 *
 * THE SETTING: `auth.sso.createAccounts`, '1' (allow) or '0' (refuse).
 *
 * **The STRICTER level wins** (D-185, quoting D-013/D-026): one installation
 * may serve many organisations run by different people. "Refuse" is the
 * stricter value, so a new account is made only when NEITHER the installation
 * NOR the organisation says no. `$SETTINGS` has already let an organisation's
 * row win, so the installation-wide layer is read on its own
 * (`InstallationSettings`), exactly as `SelfServicePassword` does. As there,
 * no query is needed: where an organisation has its own row the merged value
 * IS that row, and where it has none the merged value IS the installation's.
 *
 * **A MISSING VALUE COUNTS AS ALLOW — the opposite of `auth.password.selfAdd`,
 * on purpose, and here is why.** That setting guards a PRIVILEGE (a second way
 * into an account), so "not chosen" there must not grant it. This one guards
 * what the portal has ALWAYS done: D-185 says "default: allow" and "nothing
 * changes until somebody chooses". Counting a missing row as "refuse" would
 * turn new members away on upgrade day, between files reaching a server and
 * somebody pressing "Run migrations" — a behaviour change nobody chose. So a
 * missing row, an installation layer start-up never loaded
 * (`layerOrNull()` answering null) and an empty one all mean "allow". The
 * cost, stated plainly: an installation whose settings could not be read at
 * all behaves as it always has, and makes accounts.
 *
 * **A value that IS stored but is not plainly '1' counts as REFUSE.** Somebody
 * chose something there; reading an unrecognised choice as the stricter one
 * is the recoverable direction (a person turned away can be invited).
 *
 * WHAT IT CANNOT DO: it does not merge or find the member's real account, and
 * it cannot tell a returning member from a newcomer — nothing on the callback
 * can. With "refuse", a genuine newcomer is turned away too and must be
 * invited; that is the decision, not a side effect.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1042
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class SsoNewAccounts
{
    /** The setting's dotted name. */
    public const SETTING_KEY = 'auth.sso.createAccounts';

    /** What a person refused at the callback is told — one sentence, the owner's words in D-185's brief. */
    public const REFUSAL_WORDS = 'No account on this portal matches this sign-in. Ask an administrator to invite you.';

    /** The activity-log type of a refusal. The record carries no e-mail address. */
    public const REFUSAL_EVENT = 'SsoNewAccountRefused';

    /**
     * ⚙️ May a sign-in make a new account, with the STRICTER LEVEL WINNING?
     *
     * Both arrays are passed in so the rule runs without a request.
     *
     * @param array<string,mixed>      $merged            `$SETTINGS` (the organisation's answer).
     * @param array<string,mixed>|null $installationLayer `InstallationSettings::layerOrNull()`.
     *
     * @return bool
     */
    public static function allowedFrom(array $merged, ?array $installationLayer): bool
    {
        return self::levelAllows(self::dig($installationLayer ?? [], self::SETTING_KEY))
            && self::levelAllows(self::dig($merged, self::SETTING_KEY));
    }

    /**
     * ⚙️ The same question for the request in hand.
     *
     * `layerOrNull()`, not `layer()`: here "we do not know" must leave
     * behaviour as it always was (see the header), which is what that method
     * is for.
     *
     * @return bool
     */
    public static function allowed(): bool
    {
        $merged = App::settings();

        return self::allowedFrom(is_array($merged) === true ? $merged : [], InstallationSettings::layerOrNull());
    }

    /**
     * 🔍 One level's answer: missing means allow, '1' means allow, anything
     * else stored means refuse.
     *
     * @param mixed $stored The value at that level, or null when there is none.
     *
     * @return bool
     */
    public static function levelAllows(mixed $stored): bool
    {
        if ($stored === null) {
            return true;
        }
        if (is_int($stored) === true) {
            return $stored === 1;
        }
        if (is_bool($stored) === true) {
            return $stored;
        }
        if (is_string($stored) === false) {
            return false;
        }

        return trim($stored) === '1';
    }

    /**
     * 🔍 One dotted key out of a nested settings array (the walk
     * `SelfServicePassword::dig()` does; six lines, kept apart for the same
     * reason it gives).
     *
     * @param array<string,mixed> $layer
     *
     * @return mixed
     */
    private static function dig(array $layer, string $dotKey): mixed
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
}
