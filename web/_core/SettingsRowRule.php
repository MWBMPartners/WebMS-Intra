<?php
// Path: _core/SettingsRowRule.php
/**
 * -----------------------------------------------------------------------------
 * Which ROWS of the general settings editor an administrator may change 🚧
 * -----------------------------------------------------------------------------
 * The general settings editor (`/settings`, `web/_apps/settings/`) is open to
 * every administrator — a global administrator, and an administrator of ONE
 * organisation alike (`App::isAdmin()` accepts both). Two kinds of rule decide
 * what such a person may change there:
 *
 *   - SETTING rules: a setting that has a page of its own is refused here for
 *     everybody. Each family says which keys are its own through its own
 *     `…lockedInGeneralEditor()` method (GivingRetention, Site, MailRoutes …).
 *   - ROW rules, which are THIS class: an administrator of one organisation is
 *     refused on three kinds of row whatever the setting is, and a global
 *     administrator is never refused by them —
 *       1. a PORTAL-WIDE row (no organisation against it), because every other
 *          organisation on the installation reads it as its fallback;
 *       2. a row named "public", or with a name beginning "public.", in any
 *          letter case, because those control the public website;
 *       3. a row whose stored name uses anything but A to Z, digits, ".", "-"
 *          and "_", because the database can treat such a name as EQUAL to a
 *          protected one.
 *
 * WHY THIS CLASS EXISTS (#1021). The row rules used to be written inline in
 * `web/_apps/settings/save.php`, with a second copy in the delete branch of
 * `web/_apps/settings/index.php` and a third, the SQL text, repeated in both
 * files under a comment reading "if one changes, change both". The Settings
 * page's DRAWING asked none of them, so it offered an organisation's own
 * administrator an Edit button on every portal-wide row — hundreds of them on
 * an ordinary installation — and pressing it ended in a refusal. #845 had
 * already made the page ask each family's `…lockedInGeneralEditor()` method for
 * the SETTING rules; this is the same cure for the ROW rules. The page, the save
 * handler and the delete handler now all ask `whyNotChangeable()`, so they
 * cannot disagree.
 *
 * WHAT IT DOES NOT DECIDE.
 *   - Whether a NEW setting may be added. The add path tests the posted name
 *     with `isSafeName()` (for everybody) and `isPublicName()` (for anybody but
 *     a global administrator) itself; rule 1 cannot arise there, because the
 *     editor only ever adds a row for the current organisation.
 *   - Who may DELETE at all: only a global administrator, decided by the
 *     delete handler. For them the row rules never refuse; the delete handler
 *     still asks, so that widening its gate one day keeps them true.
 *   - The SETTING rules above, which stay with each family.
 *
 * THE DATABASE HALF. A refusal decided in PHP is made on a row read a moment
 * earlier. The save's UPDATE and the delete's DELETE therefore carry the same
 * rules inside the statement (`nameRuleSql()`, plus a `siteID` condition each
 * statement writes itself), so a row renamed or moved in between is still not
 * written. The PHP answer decides what to SAY; the statement decides what
 * HAPPENS.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1021
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class SettingsRowRule
{
    /** Rule 1: a portal-wide row (its `siteID` is empty). */
    public const PORTAL_WIDE = 'portal-wide';

    /** Rule 2: "public", or a name beginning "public.", in any letter case. */
    public const PUBLIC_NAME = 'public-name';

    /** Rule 3: a stored name outside A to Z, digits, ".", "-" and "_". */
    public const UNSAFE_NAME = 'unsafe-name';

    /**
     * 🚧 Why an administrator may NOT change (or delete) this row, or null
     * when the row rules allow it. The answer is one of the three constants
     * above, checked in the order the save handler has always checked them, so
     * a row caught by two rules is explained by the first.
     *
     * Asked with what is STORED, never what a form sent: a form could send a
     * harmless-looking name beside the number of a "public." row.
     *
     * @param string $storedKey     The row's name as it is in the database.
     * @param bool   $portalWideRow True when the row's `siteID` is empty.
     * @param bool   $globalAdmin   `App::isRootAdmin()`; a global
     *                              administrator is never refused here.
     */
    public static function whyNotChangeable(string $storedKey, bool $portalWideRow, bool $globalAdmin): ?string
    {
        if ($globalAdmin === true) {
            return null;
        }
        if ($portalWideRow === true) {
            return self::PORTAL_WIDE;
        }
        if (self::isPublicName($storedKey) === true) {
            return self::PUBLIC_NAME;
        }
        if (self::isSafeName($storedKey) === false) {
            return self::UNSAFE_NAME;
        }

        return null;
    }

    /**
     * 🔡 Is a setting name reserved for the public website — "public" on its
     * own, or anything beginning "public." — in ANY letter case?
     *
     * WHAT WAS WRONG, TWICE (moved here from `settings/save.php`):
     *  1. The first version tested with str_starts_with(), which is
     *     case-sensitive. The database is not: `settingKey` compares names with
     *     utf8mb4_unicode_ci, so a lookup for "public.example" finds a row
     *     saved as "PUBLIC.example". Such a row walked past the refusal and was
     *     then read as the public-website setting anyway.
     *  2. The second version only looked for "public." WITH the full stop, so
     *     the bare name "public" slipped through. That one name does more harm
     *     than any single "public." setting: the settings loader turns full
     *     stops into nesting (assign_setting() in web/_core/bootstrap.php), so
     *     a row called just "public" is loaded as a plain value where the whole
     *     "public" group lives, and — an organisation's own rows being loaded
     *     after the portal-wide ones — it REPLACES every public-website setting
     *     for that organisation at once. Checked 13 September 2026 on MySQL
     *     8.0.36 with the real loader: a site administrator added
     *     "public = disabled" and {"public":{"website":{"enabled":"true"}}}
     *     became {"public":"disabled"}.
     * Setting names here are NOT all lower case (portal.trustedProxies, for
     * one), so a name is never changed to lower case. Only this test ignores it.
     */
    public static function isPublicName(string $key): bool
    {
        return strcasecmp($key, 'public') === 0 || strncasecmp($key, 'public.', 7) === 0;
    }

    /**
     * 🔤 Is a name made ONLY of the letters A to Z (either case), digits, full
     * stops, hyphens and underscores?
     *
     * Used for a NEW name (the add path refuses anything else, for everybody)
     * and for a name ALREADY STORED (rule 3).
     *
     * WHY THE STORED NAME IS CHECKED TOO (moved here from `settings/save.php`).
     * The database does not compare names one character at a time. Checked
     * 13 September 2026 on MySQL 8.0.36 with this column's own rules
     * (utf8mb4_unicode_ci): each of these is EQUAL to "public.example" in an
     * ordinary lookup —
     *   - "públic.example" with the accent typed as a separate mark after "u";
     *   - "ｐｕｂｌｉｃ.example" in full-width letters;
     *   - "public．example" with a full-width full stop;
     *   - "pu", an invisible zero-width space, then "blic.example";
     *   - "pu", the invisible control character number 1, then "blic.example"
     *     (plain ASCII: one byte per character) —
     * yet the separate-accent, zero-width and control-character ones all FAIL
     * the test LIKE 'public.%'. A row already stored under such a name (from
     * before the new-name check, or written by a script) could otherwise be
     * changed by an administrator of one organisation and still be found by a
     * lookup for the real name.
     *
     * WHAT WAS CONSIDERED AND REJECTED:
     *   - Converting names to one standard Unicode form before comparing: that
     *     needs PHP's Normalizer class, which shared hosting may not have, and
     *     it would only ever cover the tricks somebody thought of in advance.
     *   - "Refuse any name with a character wider than one byte": it misses the
     *     control-character example above, which is a single byte.
     * So the rule is written the other way round: allow only the few
     * characters that cannot be confused with anything. None of the settings a
     * fresh installation seeds breaks it (checked against full_schema.sql on
     * MySQL 8.0.36), so no real setting is locked away by it.
     *
     * `\z`, never `$`: `$` also matches just before a final line break, so
     * "abc" followed by a new line would pass.
     */
    public static function isSafeName(string $key): bool
    {
        return preg_match('/^[A-Za-z0-9._-]+\z/', $key) === 1;
    }

    /**
     * 🧱 The NAME half of the row rules, written for the database, so an
     * UPDATE or DELETE carries it inside the statement. It has ONE placeholder,
     * an integer that is 1 for a global administrator (who passes whatever the
     * name) and 0 for anybody else. Every statement that uses it also writes
     * its own `siteID` condition — `(siteID = ? OR (siteID IS NULL AND ? = 1))`
     * — which is rule 1.
     *
     * For anybody but a global administrator ALL of these must hold:
     *   - CHAR_LENGTH = LENGTH: every character is a single byte, so no accent,
     *     full-width letter or invisible Unicode mark;
     *   - COLLATE utf8mb4_bin NOT REGEXP '[^A-Za-z0-9._-]': no character outside
     *     the safe set, compared byte for byte. Without utf8mb4_bin the pattern
     *     ignores letter case, and the Kelvin sign (a separate character that
     *     looks like K) is NOT flagged; and it looks for one BAD character
     *     rather than matching a whole good name between ^ and $, because $
     *     also matches before a final line break (both checked on 8.0.36);
     *   - `settingKey` <> '': an empty name fails isSafeName() too;
     *   - not "public", and not beginning "public.", in any letter case.
     * The first two between them do the job of isSafeName(). Both are kept so
     * the rule does not rest on one regular-expression engine alone.
     *
     * WHAT WAS WRONG BEFORE IT: the statements tested only
     * LOWER(settingKey) NOT LIKE 'public.%', which misses the bare name
     * "public" and the names the database treats as EQUAL to a "public." name
     * while LIKE does not (see isSafeName()).
     */
    public static function nameRuleSql(): string
    {
        return "(? = 1 OR (CHAR_LENGTH(`settingKey`) = LENGTH(`settingKey`) "
            . "AND `settingKey` COLLATE utf8mb4_bin NOT REGEXP '[^A-Za-z0-9._-]' "
            . "AND `settingKey` <> '' "
            . "AND LOWER(`settingKey`) <> 'public' "
            . "AND LOWER(`settingKey`) NOT LIKE 'public.%'))";
    }

    /**
     * 💬 What a person is told when a save ($act 'change') or a delete ($act
     * 'delete') is refused for $reason. Each says WHY this row differs from the
     * rows the same person may change on the same screen: a bare "forbidden"
     * leaves an administrator believing the portal is broken.
     *
     * WHAT WAS WRONG with the first portal-wide wording: it also suggested
     * "add a setting of the same name for your own organisation instead". The
     * editor refuses exactly that (its duplicate check counts a portal-wide row
     * as an existing name), so the advice was removed.
     */
    public static function refusalWords(string $reason, string $act = 'change'): string
    {
        $verb = $act === 'delete' ? 'delete' : 'change';
        return match ($reason) {
            self::PORTAL_WIDE => 'That setting is portal-wide: it is the value every '
                . 'organisation on this installation uses unless it has set its own. '
                . 'Only a global administrator can ' . $verb . ' it.'
                . ($verb === 'change' ? ' If you need it changed, please ask one.' : ''),
            self::PUBLIC_NAME => 'The setting called "public", and every setting whose name begins '
                . '"public.", control the public website, which anybody on the internet can '
                . 'read without signing in. Only a global administrator can ' . $verb . ' those.',
            self::UNSAFE_NAME => 'This setting\'s name contains characters other than the letters '
                . 'A to Z, numbers, full stops (.), hyphens (-) and underscores (_). The '
                . 'database can treat a name like that as the same name as a protected '
                . 'setting, so only a global administrator can ' . $verb . ' it.'
                . ($verb === 'change' ? ' If you need it changed, please ask one.' : ''),
            default => 'Only a global administrator can ' . $verb . ' that setting.',
        };
    }

    /**
     * 🏷️ The short note the Settings page shows IN PLACE of the Edit button on
     * a row the rules refuse, so nobody fills a form in only to be refused.
     * It names the reason and who can change it, and offers no link: there is
     * no page where this person could change it either.
     */
    public static function noteWords(string $reason): string
    {
        return match ($reason) {
            self::PORTAL_WIDE => 'Portal-wide: used by every organisation that has not set its own. Only a global administrator can change it.',
            self::PUBLIC_NAME => 'Controls the public website. Only a global administrator can change it.',
            self::UNSAFE_NAME => 'Its name uses characters other than A to Z, numbers, ".", "-" and "_". Only a global administrator can change it.',
            default           => 'Only a global administrator can change it.',
        };
    }

    /**
     * 📝 The activity-log line for a refused save. It names the key in double
     * quotes (what an administrator searches for, and what
     * tools/settings-locks-selftest.php matches) and never the value asked for.
     */
    public static function logWords(string $reason, string $storedKey): string
    {
        return match ($reason) {
            self::PORTAL_WIDE => 'Refused: portal-wide setting "' . $storedKey . '" may only be changed by a global administrator',
            self::PUBLIC_NAME => 'Refused: public-website setting "' . $storedKey . '" may only be changed by a global administrator',
            self::UNSAFE_NAME => 'Refused: setting "' . $storedKey . '" has a name outside the safe characters and may only be changed by a global administrator',
            default           => 'Refused: setting "' . $storedKey . '" may only be changed by a global administrator',
        };
    }
}
