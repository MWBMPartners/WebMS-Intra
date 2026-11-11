<?php
// Path: _core/InstallationSettings.php
/**
 * -----------------------------------------------------------------------------
 * The installation-wide settings layer, on its own 🏢 (#569, #668)
 * -----------------------------------------------------------------------------
 * `$SETTINGS` is the MERGED answer: installation-wide rows first, then this
 * organisation's own laid over them. That is right for almost every setting,
 * where an organisation's choice should simply win.
 *
 * It is WRONG for a setting that is a LIMIT. One installation may serve many
 * organisations, and whoever administers the installation may be a DIFFERENT
 * PERSON from the administrators of the organisations on it — a hosting
 * provider and its customers is the ordinary case. The owner's rule, set on
 * 29 October 2026: for a limit, the installation's value takes precedence. An
 * organisation may hold itself to a LOWER limit and may never raise its own
 * above what the installation allows.
 *
 * The merged array cannot express that, because by then the two values have
 * become one: there is no way to tell "the organisation chose 500" from "the
 * installation allows 500". This class keeps the installation-wide layer on
 * its own so a reader that needs the two apart has it.
 *
 * WHY A CLASS AND NOT A THIRD GLOBAL
 * -----------------------------------------------------------------------------
 * The first version of this made `$SETTINGS_INSTALLATION` a global beside
 * `$SETTINGS`. It did not work, and it failed in exactly the way this project
 * has already written a rule about: `Router.php` does `global $mysqli,
 * $SETTINGS;` immediately before it loads a page, and **those two are the only
 * things a page inherits** (see `.claude/rules/app-pages.md`, "Two variables,
 * and only two"). A third global is simply not there, and PHP says nothing
 * until it is used — so the create form and the settings page both read an
 * empty array, decided the installation allowed the built-in defaults, and an
 * organisation's 500 dates sailed through. Caught by checks K01, K02 and K05
 * of `tools/event-repeat-pages-selftest.php`, not by reading the code.
 *
 * Widening that rule to three variables was the other option and was rejected:
 * the rule is load-bearing (five Export CSV buttons were dead because their
 * pages reached for `$db`), and a named class is a better answer than one more
 * thing a page has to know is in scope.
 *
 * WHAT IT IS, AND WHAT IT IS NOT
 * -----------------------------------------------------------------------------
 * It is a LAYER, not a list of limits. Nothing here decides which settings are
 * limits; that is for the reader of each one. Code that just wants a setting's
 * effective value should keep using `$SETTINGS`.
 *
 * `known()` tells "not loaded" apart from "loaded and empty", which matters:
 * an empty layer means "the installation allows the built-in defaults", and
 * for a reader that caps a customer's data that is a different statement from
 * "we do not know". Files reach a shared host one at a time, so there is a
 * real window during an upgrade where start-up has not called `remember()`.
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

final class InstallationSettings
{
    /**
     * The installation-wide rows, nested exactly as `$SETTINGS` is.
     *
     * @var array<string,mixed>
     */
    private static array $layer = [];

    /** Whether start-up has handed the layer over at all. */
    private static bool $loaded = false;

    /**
     * 🏢 Keep the installation-wide layer for this request.
     *
     * Called once by the settings loader in `bootstrap.php`, which is already
     * reading both layers and was simply throwing this one away.
     *
     * @param array<string,mixed> $layer
     *
     * @return void
     */
    public static function remember(array $layer): void
    {
        self::$layer  = $layer;
        self::$loaded = true;
    }

    /**
     * 🏢 The installation-wide layer. Empty when start-up has not loaded it.
     *
     * Use this where an empty layer is the SAFE answer — a boundary between
     * two parties, where allowing less is recoverable and allowing more is
     * not.
     *
     * @return array<string,mixed>
     */
    public static function layer(): array
    {
        return self::$layer;
    }

    /**
     * 🏢 The layer, or null when start-up has not loaded it.
     *
     * Use this where "we do not know" should leave behaviour as it was rather
     * than silently tighten it — for instance when working out how many dates
     * a customer's repeating event may have.
     *
     * @return array<string,mixed>|null
     */
    public static function layerOrNull(): ?array
    {
        return self::$loaded === true ? self::$layer : null;
    }

    /** 🏢 Whether start-up has handed the layer over. */
    public static function known(): bool
    {
        return self::$loaded;
    }

    /**
     * 🏢 One installation-wide setting by its dotted name, or null when the
     *    installation holds no row for it (#943).
     *
     * The same walk `App::settings('a.b.c')` does over the MERGED settings,
     * done over this layer instead. It exists because thirteen admin pages
     * that SAVE installation-wide used to FILL their boxes from the merged
     * settings (#674's shape): the open organisation's own row had already
     * won there, so a global administrator who opened such a page inside an
     * organisation with its own value, and pressed Save without touching
     * anything, made that value every organisation's. A page that writes
     * `siteID IS NULL` reads its boxes through this, so what it shows is what
     * it would write back.
     *
     * Null for "no row" — never a default. The page supplies its own default,
     * exactly as it did when it read `App::settings()`.
     *
     * @param string $dotKey 'geo.autoGeocode', 'push.ttl.golive', …
     *
     * @return mixed
     */
    public static function value(string $dotKey): mixed
    {
        $node = self::$layer;
        foreach (explode('.', $dotKey) as $part) {
            if (is_array($node) === false || array_key_exists($part, $node) === false) {
                return null;
            }
            $node = $node[$part];
        }

        return $node;
    }

    /**
     * 🏢 Which settings under the given prefixes the OPEN organisation holds a
     *    value of its own for, different from the installation's (#943).
     *
     * Compares the merged settings with this layer, setting by setting, and
     * answers the dotted NAMES that differ — never the values, because a value
     * here may be an API key. A page that shows the installation's values (so
     * that Save writes back what it showed) uses this to SAY so when this
     * organisation is in fact using something else; hiding that difference is
     * how the old fault stayed invisible. The same comparison
     * `admin/transcription` has made since #674, written once.
     *
     * Both sides were read from the same rows at start-up and passed through
     * the same secret-reading step, so an equal stored value compares equal.
     * A setting the organisation has and the installation lacks counts as
     * different; one the installation has and the organisation does not
     * override cannot differ, because the merged value then IS the
     * installation's.
     *
     * @param string ...$prefixes Dotted prefixes: 'sms', 'hymns.remote', …
     *
     * @return list<string> Dotted names, in the order the settings were read.
     */
    public static function differsHere(string ...$prefixes): array
    {
        $differing = [];
        foreach ($prefixes as $prefix) {
            $merged = App::settings($prefix);
            $mine   = self::flatten(self::value($prefix), $prefix);
            foreach (self::flatten($merged, $prefix) as $name => $value) {
                if (($mine[$name] ?? null) !== $value) {
                    $differing[] = $name;
                }
            }
        }

        return array_values(array_unique($differing));
    }

    /**
     * A nested settings branch as dotted name => text. A leaf at the prefix
     * itself comes back under the prefix's own name.
     *
     * @return array<string,string>
     */
    private static function flatten(mixed $branch, string $prefix): array
    {
        if (is_array($branch) === false) {
            return $branch === null ? [] : [$prefix => is_scalar($branch) === true ? (string) $branch : ''];
        }
        $flat = [];
        foreach ($branch as $key => $value) {
            $flat += self::flatten($value, $prefix . '.' . (string) $key);
        }

        return $flat;
    }
}
