<?php
// Path: _core/GivingRetention.php
/**
 * -----------------------------------------------------------------------------
 * How long giving records keep a donor's name after an erasure 🧾⏳
 * -----------------------------------------------------------------------------
 * When somebody asks to be forgotten, everything about them is erased at once,
 * EXCEPT the giving records their organisation's country requires it to keep.
 * Those keep the donor's name and home address until a "keep until" date, and
 * the retention job then clears them. Salem874 set that rule on 11 October
 * 2026 (roll-out package A2b).
 *
 * This class is the ONE place that answers three questions, so that the
 * erasure, the retention job, its preview and the settings page can never
 * disagree:
 *
 *   1. What does the portal SUGGEST for a country?       suggestionFor()
 *   2. What is one organisation's rule today?            ruleForSite()
 *   3. Until when is one donor's name kept, and may it
 *      be cleared now?                                   keepUntil(), stateFor()
 *
 * THE FIGURES ARE SUGGESTIONS, NEVER LEGAL FACT
 * ---------------------------------------------
 * They come from research of mixed strength, held in
 * `data/giving-retention-countries.json` with the strength of each source and
 * its links. Nothing here, and nothing a page prints from here, may read as if
 * the portal knows the law. An administrator confirms the figure with their
 * own adviser, once, and until then NOTHING IS CLEARED.
 *
 * NO COUNTRY IS BUILT IN
 * ----------------------
 * Each organisation sets its own country (`giving.retention.countryCode`),
 * which is empty until it does. It is deliberately NOT read from
 * `org.address.countryCode`: that is one value for the whole installation and
 * it is "GB" unless somebody changes it, so every organisation would look
 * British and be offered the United Kingdom's figure as if it were theirs.
 * (The first plan read it, and the owner chose a country per organisation.)
 *
 * EVERYTHING HERE IS A DATE ON A CALENDAR
 * ---------------------------------------
 * "Keep until" is a date, held as text `YYYY-MM-DD`, and worked out with whole
 * calendar years. No moment in time is involved except one question, asked
 * once and as late as possible: what is today's date? It is asked of the LAST
 * place on Earth to reach a date, whatever zone the organisation is in (see
 * timezoneOfSite() for why), so a name is never cleared early. It is cleared
 * LATE instead, by the distance between the organisation's clock and that
 * place's: 12 or 13 hours for London, and up to 26 hours in the zones
 * furthest ahead of UTC. (This said "at most a day late", which is untrue
 * for those: found by the second check, 13 October 2026.) The two nights a
 * year the clocks change cannot move a date: nothing here adds hours, and
 * nothing here assumes a day is 24 hours long.
 * ONE date here is not that one: the "Confirmed on" date an administrator's
 * tick stores. People read it, so it is the organisation's OWN date (see
 * saveForSite()). It decides nothing.
 *
 * WHAT THIS CANNOT DO
 * -------------------
 *   - It cannot tell whether a figure is right for a particular organisation.
 *   - It does not know a country's own way of counting (some count from the
 *     end of a calendar year, some from a filing date). It always counts from
 *     the end of the organisation's financial year, which is never earlier
 *     than the gift.
 *   - It changes nothing. Clearing is done by GdprEraser::clearKeptGivingIdentity().
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/574
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class GivingRetention
{
    /** The seven per-organisation settings (seeded by migration 209). */
    public const KEY_COUNTRY      = 'giving.retention.countryCode';
    public const KEY_SCOPE        = 'giving.retention.scope';
    public const KEY_YEARS        = 'giving.retention.years';
    public const KEY_CONFIRMED    = 'giving.retention.confirmed';
    public const KEY_CONFIRMED_ON = 'giving.retention.confirmedOn';
    public const KEY_YEAR_END     = 'giving.retention.yearEnd';
    public const KEY_HOLD         = 'giving.retention.hold';

    public const SCOPE_RECEIPTED = 'receipted';
    public const SCOPE_ALL       = 'all';

    /**
     * Which gifts keep the donor's name. Stored value => the words on the page.
     *
     * @var array<string, string>
     */
    public const SCOPES = [
        self::SCOPE_RECEIPTED => 'Only gifts with a tax declaration, a tax receipt or a giving statement',
        self::SCOPE_ALL       => 'Every gift',
    ];

    /** The fewest and the most years an organisation may set. */
    public const MIN_YEARS = 1;
    public const MAX_YEARS = 50;

    /**
     * ⚠️ The years suggested when the data file itself cannot be read.
     *
     * Not a country's figure: it is the same cautious placeholder the data
     * file holds as `placeholderYears`, repeated here because it is needed in
     * exactly the case where that file is unreadable. Ten years, on the
     * owner's decision of 11 October 2026.
     */
    public const PLACEHOLDER_YEARS_WITHOUT_FILE = 10;

    /** How strong a source is, in the words shown to an administrator. */
    public const STRENGTH_WORDS = [
        'read'      => 'we read the official text',
        'summary'   => 'an official page, seen only in summary',
        'secondary' => 'a law firm, accountant, news report or survey, not the law itself',
        'not-found' => 'nothing reliable was found',
    ];

    private const EXCEPTIONS = ['written', 'weak', 'none', 'not-found'];

    private const MONTHS = [
        1 => 'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
    ];

    /** @var array<string, array<string, mixed>> The data file, by path, once read. */
    private static array $dataRead = [];

    // =========================================================================
    // 📚 The data file
    // =========================================================================

    /** Where the data file is. */
    public static function dataPath(): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'giving-retention-countries.json';
    }

    /**
     * 📖 Read the data file of country figures.
     *
     * It never throws and never stops a page or an erasure. The same rules as
     * the schema beside the file are applied here, because the file is edited
     * by hand between releases and this is what stands between a slip in it
     * and somebody's records:
     *
     *   - a ROW that breaks a rule counts as "nothing found" for that country,
     *     so that country gets the flagged placeholder;
     *   - a FILE that cannot be read at all, or whose own top-level values
     *     are wrong, gives the flagged placeholder to everybody.
     *
     * Either way a line goes to the error log, and nothing ever falls back to
     * a real-looking figure.
     *
     * @param string|null $path Another file, for tests. Null means the real one.
     *
     * @return array{readable: bool, placeholderYears: int, minimumSuggestedYears: int,
     *   countries: array<string, array<string, mixed>>}
     */
    public static function data(?string $path = null): array
    {
        $path = $path ?? self::dataPath();
        if (isset(self::$dataRead[$path]) === true) {
            return self::$dataRead[$path];
        }

        $unreadable = [
            'readable'              => false,
            'placeholderYears'      => self::PLACEHOLDER_YEARS_WITHOUT_FILE,
            'minimumSuggestedYears' => self::PLACEHOLDER_YEARS_WITHOUT_FILE,
            'countries'             => [],
        ];

        $raw = is_readable($path) === true ? @file_get_contents($path) : false;
        $all = is_string($raw) === true ? json_decode($raw, true) : null;
        if (is_array($all) === false
            || ($all['schemaVersion'] ?? null) !== 1
            || is_int($all['placeholderYears'] ?? null) === false
            || is_int($all['minimumSuggestedYears'] ?? null) === false
            || is_array($all['countries'] ?? null) === false
            || $all['placeholderYears'] < 6 || $all['placeholderYears'] > self::MAX_YEARS
            || $all['minimumSuggestedYears'] < self::MIN_YEARS
            || $all['minimumSuggestedYears'] > $all['placeholderYears']
        ) {
            error_log('[WebMS-Intra] GivingRetention: the country figures file could not be read or is not in the '
                . 'expected shape (' . $path . '). Every organisation gets the cautious placeholder until it is put right.');

            return self::$dataRead[$path] = $unreadable;
        }

        $countries = [];
        foreach ($all['countries'] as $code => $row) {
            $code = (string) $code;
            if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
                error_log('[WebMS-Intra] GivingRetention: a row with the key "' . $code . '" is not a two-letter country code and was left out.');
                continue;
            }
            $countries[$code] = self::checkedRow($code, $row);
        }

        return self::$dataRead[$path] = [
            'readable'              => true,
            'placeholderYears'      => (int) $all['placeholderYears'],
            'minimumSuggestedYears' => (int) $all['minimumSuggestedYears'],
            'countries'             => $countries,
        ];
    }

    /**
     * One country's row, or a "nothing found" row in its place when it breaks
     * a rule. The name is kept where there is one, so the page can still say
     * which country it has no figure for.
     *
     * @param mixed $row Whatever the file holds for this country.
     *
     * @return array<string, mixed>
     */
    private static function checkedRow(string $code, mixed $row): array
    {
        $name = is_array($row) === true && is_string($row['name'] ?? null) === true && trim($row['name']) !== ''
            ? trim($row['name'])
            : $code;

        $problem = '';
        if (is_array($row) === false) {
            $problem = 'it is not a set of values';
        } else {
            $years    = $row['yearsFound'] ?? null;
            $strength = $row['sourceStrength'] ?? null;
            $sources  = $row['sources'] ?? null;
            if (is_string($strength) === false || isset(self::STRENGTH_WORDS[$strength]) === false) {
                $problem = 'its source strength is not one of the four allowed words';
            } elseif (($years === null) !== ($strength === 'not-found')) {
                $problem = 'a figure and "not found" contradict each other';
            } elseif ($years !== null && (is_int($years) === false || $years < self::MIN_YEARS || $years > self::MAX_YEARS)) {
                $problem = 'its figure is not a whole number of years from 1 to 50';
            } elseif (is_bool($row['needsAdvice'] ?? null) === false) {
                $problem = '"needsAdvice" is not true or false';
            } elseif (in_array($row['erasureException'] ?? null, self::EXCEPTIONS, true) === false) {
                $problem = 'its erasure-exception word is not one of the four allowed';
            } elseif (is_string($row['note'] ?? null) === false) {
                $problem = 'it has no note';
            } elseif (is_array($sources) === false || ($years !== null && $sources === [])) {
                $problem = 'a figure is given with no source';
            } else {
                foreach ($sources as $source) {
                    if (is_array($source) === false || is_string($source['title'] ?? null) === false
                        || is_string($source['url'] ?? null) === false
                        || preg_match('#^https://[^\s]+$#', $source['url']) !== 1
                    ) {
                        $problem = 'one of its sources has no title or no https link';
                        break;
                    }
                }
            }
        }

        if ($problem !== '') {
            error_log('[WebMS-Intra] GivingRetention: the row for ' . $code . ' was not used (' . $problem
                . '). That country gets the cautious placeholder until the row is put right.');

            return [
                'name' => $name, 'yearsFound' => null, 'sourceStrength' => 'not-found', 'needsAdvice' => true,
                'erasureException' => 'not-found', 'sources' => [],
                'note' => 'The portal\'s row for this country could not be read, so no figure is offered.',
            ];
        }

        return [
            'name'             => $name,
            'yearsFound'       => $row['yearsFound'],
            'sourceStrength'   => $row['sourceStrength'],
            'needsAdvice'      => $row['needsAdvice'],
            'erasureException' => $row['erasureException'],
            'sources'          => array_values($row['sources']),
            'note'             => $row['note'],
        ];
    }

    /**
     * The countries an organisation can choose from: code => name, by name.
     *
     * @return array<string, string>
     */
    public static function countryChoices(?string $path = null): array
    {
        $choices = [];
        foreach (self::data($path)['countries'] as $code => $row) {
            $choices[(string) $code] = (string) $row['name'];
        }
        asort($choices, SORT_NATURAL | SORT_FLAG_CASE);

        return $choices;
    }

    // =========================================================================
    // 💡 What the portal suggests
    // =========================================================================

    /**
     * 💡 The number of years the portal suggests for a country.
     *
     * Four outcomes, and the page says which one it is:
     *
     *   found        the figure the research found;
     *   raised       the research found fewer years than the portal's minimum
     *                suggestion, so the minimum is suggested and the figure
     *                found is shown beside it;
     *   placeholder  there is no figure, for one of four reasons given in
     *                `why`: no country set, the country is not in the file,
     *                nothing was found for it, or the file cannot be read.
     *
     * A placeholder is never dressed up as a finding: `kind` says so, and
     * every caller prints it differently.
     *
     * @param string      $countryCode Two capital letters, or '' when not set.
     * @param string|null $path        Another data file, for tests.
     *
     * @return array{years: int, kind: string, why: string, yearsFound: ?int, countryCode: string,
     *   countryName: string, strength: string, needsAdvice: bool, erasureException: string,
     *   sources: array<int, array{title: string, url: string}>, note: string, minimum: int, placeholder: int}
     */
    public static function suggestionFor(string $countryCode, ?string $path = null): array
    {
        $data        = self::data($path);
        $countryCode = strtoupper(trim($countryCode));
        $row         = $data['countries'][$countryCode] ?? null;

        $answer = [
            'years'            => $data['placeholderYears'],
            'kind'             => 'placeholder',
            'why'              => '',
            'yearsFound'       => null,
            'countryCode'      => $countryCode,
            'countryName'      => $row !== null ? (string) $row['name'] : $countryCode,
            'strength'         => 'not-found',
            'needsAdvice'      => true,
            'erasureException' => 'not-found',
            'sources'          => [],
            'note'             => '',
            'minimum'          => $data['minimumSuggestedYears'],
            'placeholder'      => $data['placeholderYears'],
        ];

        if ($data['readable'] === false) {
            $answer['why'] = 'file-unreadable';

            return $answer;
        }
        if (preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
            $answer['why']         = 'country-not-set';
            $answer['countryCode'] = '';
            $answer['countryName'] = '';

            return $answer;
        }
        if ($row === null) {
            $answer['why'] = 'country-not-in-file';

            return $answer;
        }

        $answer['strength']         = (string) $row['sourceStrength'];
        $answer['needsAdvice']      = (bool) $row['needsAdvice'];
        $answer['erasureException'] = (string) $row['erasureException'];
        $answer['sources']          = $row['sources'];
        $answer['note']             = (string) $row['note'];

        if ($row['yearsFound'] === null) {
            $answer['why'] = 'no-figure';

            return $answer;
        }

        $answer['yearsFound'] = (int) $row['yearsFound'];
        if ($answer['yearsFound'] < $data['minimumSuggestedYears']) {
            $answer['years'] = $data['minimumSuggestedYears'];
            $answer['kind']  = 'raised';

            return $answer;
        }

        $answer['years'] = $answer['yearsFound'];
        $answer['kind']  = 'found';

        return $answer;
    }

    // =========================================================================
    // ⚙️ One organisation's rule
    // =========================================================================

    /**
     * ⚙️ The rule one organisation has today, read fresh from its settings.
     *
     * READ FROM THE ORGANISATION'S OWN SETTINGS ONLY. A value held for the
     * whole installation is NOT inherited, with one exception below. This
     * first used App::settingForSite(), which falls back to the
     * installation-wide row when an organisation has none of its own. That
     * would have let one edit to the installation-wide `confirmed` row (the
     * general settings editor allows a global administrator to make it)
     * count as "confirmed with our adviser" for EVERY organisation that had
     * never opened this page, in whatever country, and start clearing their
     * kept records. It would also have let an installation-wide country make
     * every organisation look like it is in that country. The owner's rule
     * is that each organisation sets, and confirms, its own.
     *
     * THE ONE EXCEPTION IS THE HOLD, and it only ever adds caution: a hold
     * on the organisation, OR a hold on the whole installation, holds. The
     * two are returned apart as well (`holdOwn`, `holdEverybody`), because
     * the settings page must show which is which: it first showed the
     * installation's hold as the organisation's own tick, and saving the
     * page then switched the organisation's own hold on without being asked.
     *
     * So an organisation with no settings of its own has exactly the cautious
     * starting point: no country, the narrower rule for which gifts, the
     * portal's suggestion for the years, no year end, and NOT confirmed.
     *
     * Every value is checked as it is read, and anything unexpected falls on
     * the side that CLEARS NOTHING: only the exact word `true` counts as
     * confirmed, and anything but the exact word `false` (or nothing at all)
     * counts as a hold being on. A setting can be edited by other means than
     * this feature's own page, and a typing slip there must not be what
     * removes somebody's tax evidence.
     *
     * @return array{siteId: int, countryCode: string, scope: string, yearsOwn: ?int, years: int,
     *   confirmed: bool, confirmedOn: string, yearEnd: string, hold: bool, holdOwn: bool,
     *   holdEverybody: bool, timezone: string, suggestion: array<string, mixed>}
     */
    public static function ruleForSite(int $siteId, ?string $path = null): array
    {
        $own  = self::settingsAt($siteId);
        $read = static fn (string $key): string => trim((string) ($own[$key] ?? ''));

        $countryCode = strtoupper($read(self::KEY_COUNTRY));
        if (preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
            $countryCode = '';
        }
        $suggestion = self::suggestionFor($countryCode, $path);

        $scope = $read(self::KEY_SCOPE);
        if (isset(self::SCOPES[$scope]) === false) {
            $scope = self::SCOPE_RECEIPTED;
        }

        $yearsOwn = self::yearsFromText($read(self::KEY_YEARS));

        $confirmedOn = $read(self::KEY_CONFIRMED_ON);
        if (self::isDate($confirmedOn) === false) {
            $confirmedOn = '';
        }

        $hold          = $read(self::KEY_HOLD);
        $holdEverybody = trim((string) (self::settingsAt(null)[self::KEY_HOLD] ?? ''));

        return [
            'siteId'      => $siteId,
            'countryCode' => $countryCode,
            'scope'       => $scope,
            'yearsOwn'    => $yearsOwn,
            'years'       => $yearsOwn ?? (int) $suggestion['years'],
            'confirmed'   => $read(self::KEY_CONFIRMED) === 'true',
            'confirmedOn' => $confirmedOn,
            'yearEnd'     => self::yearEndFromText($read(self::KEY_YEAR_END)),
            'hold'        => ($hold !== 'false' && $hold !== '') || ($holdEverybody !== 'false' && $holdEverybody !== ''),
            'holdOwn'       => $hold !== 'false' && $hold !== '',
            'holdEverybody' => $holdEverybody !== 'false' && $holdEverybody !== '',
            'timezone'    => self::timezoneOfSite($siteId),
            'suggestion'  => $suggestion,
        ];
    }

    /**
     * The seven settings stored at ONE exact level: one organisation's own
     * rows, or (with null) the installation-wide rows. Never a mixture: see
     * ruleForSite() for why that matters here.
     *
     * If they cannot be read at all, the answer is none, which ruleForSite()
     * turns into "not confirmed": nothing is cleared.
     *
     * @return array<string, string> Setting key => stored value.
     */
    private static function settingsAt(?int $siteId): array
    {
        $found = [];
        try {
            $stmt = App::db()->prepare(
                "SELECT settingKey, settingValue FROM tblSettings WHERE settingKey LIKE 'giving.retention.%' AND "
                . ($siteId === null ? 'siteID IS NULL' : 'siteID = ?')
            );
            if ($stmt === false) {
                return [];
            }
            if ($siteId !== null) {
                $stmt->bind_param('i', $siteId);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $found[(string) $row['settingKey']] = (string) ($row['settingValue'] ?? '');
            }
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] GivingRetention: the giving-records settings could not be read ('
                . ($siteId === null ? 'installation-wide' : 'organisation #' . $siteId) . '): ' . $e->getMessage());

            return [];
        }

        return $found;
    }

    /**
     * 🔒 May the GENERAL settings editor (/settings) change this setting?
     *
     * No, for the seven settings of this feature, with one exception. They
     * are changed on the giving-records page, whose save routine un-ticks
     * "confirmed" when the figure changes and refuses a change that would
     * clear kept records until a second box is ticked. The general editor
     * knows none of that: it would let an administrator shorten a confirmed
     * figure, or switch a hold off, in one click, with no warning.
     *
     * The exception: a GLOBAL administrator may change the installation-wide
     * `hold` row there. It is the one installation-wide value an organisation
     * follows, and it has no page of its own.
     *
     * Names are compared without regard to capitals, as the database does.
     *
     * @param string $key           The setting's name.
     * @param bool   $portalWideRow Whether the row is the installation-wide one.
     * @param bool   $globalAdmin   Whether the person is a global administrator.
     */
    public static function lockedInGeneralEditor(string $key, bool $portalWideRow, bool $globalAdmin): bool
    {
        if (strncasecmp($key, 'giving.retention.', 17) !== 0) {
            return false;
        }

        return ($portalWideRow === true && $globalAdmin === true && strcasecmp($key, self::KEY_HOLD) === 0) === false;
    }

    /** What the general settings editor says when it refuses one of them. */
    public const LOCKED_MESSAGE = 'That setting is part of the giving-records rule, which decides when an erased donor\'s name is '
        . 'cleared. It can only be changed at Admin, Giving records, where a change that would clear kept records is '
        . 'checked first. Nothing was changed.';

    /** A number of years typed by somebody: 1 to 50, or null for anything else. */
    public static function yearsFromText(string $text): ?int
    {
        if (preg_match('/^\d{1,2}$/', $text) !== 1) {
            return null;
        }
        $years = (int) $text;

        return $years >= self::MIN_YEARS && $years <= self::MAX_YEARS ? $years : null;
    }

    /**
     * A financial year end as `MM-DD`, or '' when the text is not one.
     *
     * 29 February is refused on purpose: a year end that exists one year in
     * four is not a year end, and "the same day N years later" would have no
     * answer three years in four.
     */
    public static function yearEndFromText(string $text): string
    {
        if (preg_match('/^(\d{2})-(\d{2})$/', $text, $m) !== 1) {
            return '';
        }

        // 2023 is not a leap year, so 29 February fails this test.
        return checkdate((int) $m[1], (int) $m[2], 2023) === true ? $text : '';
    }

    /**
     * The zone "today" is asked in for an organisation: ALWAYS "not known"
     * (''), so that today() answers for the last place on Earth to reach a
     * date. The coordinator decided this on 13 October 2026, by the rule that
     * a deletion that cannot be undone must never come sooner.
     *
     * WHAT WAS HERE, AND WHY IT WENT. This first read the organisation's own
     * row. Then (#604) it asked Site::timezone(), the one answer the pages
     * use, on the view that every reader of the organisation's zone should
     * follow the pages. For a date that decides when a donor's name is
     * cleared, that was wrong. The independent check ran seven pairs of
     * stored zones on the unchanged portal and on the new one: wherever an
     * installation's two stored zones had differed, "today" arrived SOONER
     * than before, by up to 17 hours (an organisation's row on Los Angeles,
     * its setting on Tokyo), and a name due that day was cleared that much
     * early. Giving retention is therefore the ONE reader of an
     * organisation's zone that does not follow the pages.
     *
     * WHAT IT COSTS: a name is cleared later than the organisation's own
     * midnight would have it, by the distance between its clock and the last
     * place's: 12 or 13 hours for London, 26 hours for the place furthest
     * ahead of UTC. Never earlier, anywhere.
     *
     * The organisation is still taken as an argument, so that the call reads
     * as what it is asking and a later decision has somewhere to go.
     */
    private static function timezoneOfSite(int $siteId): string
    {
        unset($siteId);

        return '';
    }

    /**
     * 💾 Check and save one organisation's rule. Used by the settings page's
     *    save handler, which has already checked who is asking and the form
     *    token. Kept here, and not in the page, so the rules can be tested
     *    without a browser.
     *
     * Every value is checked against a closed list or a range BEFORE anything
     * is written, and a value outside it REFUSES THE WHOLE SAVE. Nothing is
     * quietly corrected: an administrator who typed 90 years meant something
     * and should be told it was not accepted.
     *
     * THREE RULES THAT PROTECT THE RECORDS
     *
     *   1. Ticking "confirmed" writes the figure on screen into the
     *      organisation's own setting, even when it was only following the
     *      portal's suggestion. A confirmed organisation therefore never
     *      moves when the data file is updated.
     *   2. Changing the figure, or the country, of a rule that WAS confirmed
     *      un-ticks it, whatever the box says. The adviser confirmed the old
     *      figure, not the new one. The organisation ticks it again when the
     *      new one has been confirmed; until then nothing is cleared.
     *   3. A SLIP OF THE FINGER MUST NOT CLEAR RECORDS. If the new rule would
     *      make any kept record due that is not due today, the save is
     *      refused unless the box "I understand" was ticked, and the message
     *      says how many.
     *
     * The seven values are written in one transaction, so a rule can never be
     * half saved (a new number of years with the old "confirmed" tick, say).
     *
     * What this cannot do: stop somebody changing these settings by other
     * means (the general settings editor, or the database). That is why
     * ruleForSite() reads every value defensively.
     *
     * TWO DATES ARE CALLED "TODAY" HERE, AND THEY ARE NOT THE SAME ONE.
     * Whether a save would let records be cleared (rule 3) is decided on the
     * date the clearing itself goes by: the last place on Earth's (see
     * timezoneOfSite()). The "Confirmed on" date a tick stores is a date
     * PEOPLE READ, on the settings page and in the audit line written when a
     * name is cleared, so it is the organisation's OWN date, from
     * Site::timezone(). It used to be taken from the first: an administrator
     * in Auckland who ticked the box at two in the morning of the 14th was
     * recorded as confirming on the 13th, and one in London was a day out
     * from midnight until 12:00 UTC (found by the second check, 13 October
     * 2026). The stored date decides nothing; only people read it.
     *
     * @param array<string, mixed> $posted countryCode, scope, years, confirmed,
     *   yearEndMonth, yearEndDay, hold, understood. The three tick boxes
     *   count as ticked when they hold the text "1".
     * @param string|null             $today For tests: ONE date used as both of the dates above.
     * @param \DateTimeImmutable|null $now   For tests: the moment to work both dates out from,
     *                                       each in its own place. Null means now. Not read when `$today` is given.
     *
     * @return array{saved: bool, type: string, message: string, changes: string[], newlyDue: int}
     *   `type` is the colour of the message (success, warning or danger).
     *   `changes` is what changed, in words, for the activity log.
     */
    public static function saveForSite(\mysqli $db, int $siteId, array $posted, ?string $today = null, ?string $path = null, ?\DateTimeImmutable $now = null): array
    {
        $refuse = static fn (string $why): array => [
            'saved' => false, 'type' => 'danger', 'message' => $why . ' Nothing was saved.', 'changes' => [], 'newlyDue' => 0,
        ];
        $text = static fn (string $key): string => trim((string) ($posted[$key] ?? ''));

        // ✅ Each value against its closed list or range.
        $country = strtoupper($text('countryCode'));
        if ($country !== '' && isset(self::data($path)['countries'][$country]) === false) {
            return $refuse('That country is not in the list.');
        }
        $scope = $text('scope');
        if (isset(self::SCOPES[$scope]) === false) {
            return $refuse('"Which gifts keep the donor\'s name" has to be one of the two choices on the page.');
        }
        $yearsTyped = $text('years');
        $yearsOwn   = self::yearsFromText($yearsTyped);
        if ($yearsTyped !== '' && $yearsOwn === null) {
            return $refuse('The number of years has to be a whole number from ' . self::MIN_YEARS . ' to ' . self::MAX_YEARS
                . ', or left empty to follow the portal\'s suggestion.');
        }
        $month = $text('yearEndMonth');
        $day   = $text('yearEndDay');
        if (($month === '') !== ($day === '')) {
            return $refuse('The financial year end needs both a day and a month, or neither.');
        }
        $yearEnd = '';
        if ($month !== '') {
            if (preg_match('/^\d{1,2}$/', $month) !== 1 || preg_match('/^\d{1,2}$/', $day) !== 1) {
                return $refuse('The financial year end is not a day and a month.');
            }
            $yearEnd = self::yearEndFromText(sprintf('%02d-%02d', (int) $month, (int) $day));
            if ($yearEnd === '') {
                return $refuse((int) $month === 2 && (int) $day === 29
                    ? '29 February cannot be a financial year end, because three years in four have no such day. Please use 28 February.'
                    : 'That day does not exist in that month.');
            }
        }
        $hold       = $text('hold') === '1';
        $ticked     = $text('confirmed') === '1';
        $understood = $text('understood') === '1';

        $old        = self::ruleForSite($siteId, $path);
        // 📅 The date people read, then the date that decides. See "TWO DATES" above.
        $ownToday   = $today ?? self::today(Site::timezone($siteId), $now);
        $today      = $today ?? self::today((string) $old['timezone'], $now);
        $suggestion = self::suggestionFor($country, $path);
        $years      = $yearsOwn ?? (int) $suggestion['years'];

        // 🔏 Rules 1 and 2.
        $unticked    = false;
        $confirmed   = false;
        $confirmedOn = '';
        if ($ticked === true) {
            if ($old['confirmed'] === true && ($years !== (int) $old['years'] || $country !== (string) $old['countryCode'])) {
                $unticked = true;
            } else {
                $confirmed   = true;
                $yearsOwn    = $years;
                $confirmedOn = $old['confirmed'] === true && (string) $old['confirmedOn'] !== '' ? (string) $old['confirmedOn'] : $ownToday;
            }
        }

        // The tick box is the organisation's OWN hold. A hold on the whole
        // installation is not this form's to change, and still holds.
        $new = [
            'siteId' => $siteId, 'countryCode' => $country, 'scope' => $scope, 'yearsOwn' => $yearsOwn, 'years' => $years,
            'confirmed' => $confirmed, 'confirmedOn' => $confirmedOn, 'yearEnd' => $yearEnd,
            'hold' => $hold === true || $old['holdEverybody'] === true, 'holdOwn' => $hold, 'holdEverybody' => $old['holdEverybody'],
            'timezone' => (string) $old['timezone'], 'suggestion' => $suggestion,
        ];

        // 🛑 Rule 3. Decided by the same functions the retention job uses,
        //    from the same date it would count from. A record whose account
        //    has not been emptied can never become due, whatever the rule says.
        $newlyDue = 0;
        foreach (self::keptDonorsForSite($db, $siteId, $old, $today) as $row) {
            if ($row['state'] !== 'due' && $row['accountEmptied'] === true
                && self::stateFor($new, (string) $row['countedFromGift'], $today)['state'] === 'due'
            ) {
                $newlyDue++;
            }
        }
        if ($newlyDue > 0 && $understood === false) {
            return [
                'saved'   => false,
                'type'    => 'danger',
                'message' => 'Saving this would let the next retention run remove the name and address of ' . $newlyDue
                    . ' erased donor' . ($newlyDue === 1 ? '' : 's') . ' from your giving records, because their keep-until '
                    . 'date would already have passed. That cannot be undone. Nothing was saved. If it is what you '
                    . 'intend, tick the box "I understand that kept donor records will be cleared" and save again.',
                'changes'  => [],
                'newlyDue' => $newlyDue,
            ];
        }

        // 💾 All seven, or none.
        $values = [
            self::KEY_COUNTRY      => $country,
            self::KEY_SCOPE        => $scope,
            self::KEY_YEARS        => $yearsOwn === null ? '' : (string) $yearsOwn,
            self::KEY_CONFIRMED    => $confirmed === true ? 'true' : 'false',
            self::KEY_CONFIRMED_ON => $confirmedOn,
            self::KEY_YEAR_END     => $yearEnd,
            self::KEY_HOLD         => $hold === true ? 'true' : 'false',
        ];
        $db->begin_transaction();
        try {
            // A real organisation number, so the settings table's unique key
            // on (settingKey, siteID) matches an existing row normally.
            $stmt = $db->prepare(
                'INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive, updatedAt) '
                . 'VALUES (?, ?, ?, ?, 0, NOW()) '
                . 'ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue), updatedAt = NOW()'
            );
            foreach ($values as $key => $value) {
                $stmt->bind_param('isss', $siteId, $key, $value, $value);
                $stmt->execute();
            }
            $stmt->close();
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            error_log('[WebMS-Intra] GivingRetention: the giving-records settings of organisation #' . $siteId . ' could not be saved: ' . $e->getMessage());

            return $refuse('The settings could not be saved because the database refused the change.');
        }

        // 📝 What changed, in words, for the activity log and the message.
        $changes = [];
        $was = [
            'country' => (string) $old['countryCode'], 'which gifts' => (string) $old['scope'],
            'years' => $old['yearsOwn'] === null ? 'following the suggestion (' . (int) $old['years'] . ')' : (string) $old['yearsOwn'],
            'confirmed' => $old['confirmed'] === true ? 'yes' : 'no', 'financial year end' => (string) $old['yearEnd'],
            'hold' => $old['holdOwn'] === true ? 'on' : 'off',
        ];
        $now = [
            'country' => $country, 'which gifts' => $scope,
            'years' => $yearsOwn === null ? 'following the suggestion (' . $years . ')' : (string) $yearsOwn,
            'confirmed' => $confirmed === true ? 'yes' : 'no', 'financial year end' => $yearEnd,
            'hold' => $hold === true ? 'on' : 'off',
        ];
        foreach ($now as $label => $value) {
            if ($value !== $was[$label]) {
                $changes[] = $label . ': "' . ($was[$label] === '' ? 'not set' : $was[$label]) . '" to "' . ($value === '' ? 'not set' : $value) . '"';
            }
        }

        $message = 'Saved for your organisation.';
        $type    = 'success';
        if ($unticked === true) {
            $message .= ' You changed the ' . ($country !== (string) $old['countryCode'] ? 'country' : 'number of years')
                . ', so it is no longer marked as confirmed, and nothing will be cleared until it is. Tick "confirmed" '
                . 'again once your adviser has confirmed ' . $years . ' ' . ($years === 1 ? 'year' : 'years') . '.';
            $type = 'warning';
        }
        if ($yearsOwn !== null && $yearsOwn < (int) $suggestion['years']) {
            $message .= ' You have set ' . $yearsOwn . ' ' . ($yearsOwn === 1 ? 'year' : 'years') . '. Our suggestion is '
                . (int) $suggestion['years'] . '.';
            $type = 'warning';
        }
        if ($newlyDue > 0) {
            $message .= ' ' . $newlyDue . ' kept donor record' . ($newlyDue === 1 ? ' is' : 's are')
                . ' now past the keep-until date and will be cleared at the next retention run.';
            $type = 'warning';
        }

        return ['saved' => true, 'type' => $type, 'message' => $message, 'changes' => $changes, 'newlyDue' => $newlyDue];
    }

    // =========================================================================
    // 📅 Dates. Calendar dates only: see the note at the top of this file.
    // =========================================================================

    /**
     * 📅 Today's date in one zone, or at the last place on Earth when the
     *    zone given is empty or is not one.
     *
     * The one place a moment in time is turned into a date. If the zone is
     * empty or is not a real zone, the answer is the date at the place on
     * Earth where the day starts LAST (twelve hours behind UTC). No real
     * place is on an earlier date than that, so a record can be cleared
     * late, and never early.
     * EVERY DATE THAT DECIDES A CLEARING IS ASKED WITH NO ZONE, and so is the
     * last place's: ruleForSite() gives '' as the rule's zone on purpose (see
     * timezoneOfSite()). (This comment used to be headed "today's date where
     * an organisation is", which stopped being what the clearing asks.)
     * A zone IS given for the one date people read: saveForSite()'s
     * "Confirmed on".
     *
     * (Rejected: falling back to UTC. For an organisation east of Greenwich
     * that is harmless; for one in the Americas with a broken zone it would
     * call a record due some hours before its own midnight.)
     *
     * A zone counts as real only when it is one of the NAMED zones PHP knows
     * ("Europe/London", "UTC", and the older names kept for compatibility).
     * Simply asking PHP to build the zone is not enough, which this did at
     * first: PHP also accepts a bare offset such as "GMT+25", twenty-five
     * hours ahead of UTC, and that put "today" a day ahead of everywhere on
     * Earth. The self-test found it.
     *
     * @param \DateTimeImmutable|null $now The moment to ask about. Null means now. For tests.
     */
    public static function today(string $timezone, ?\DateTimeImmutable $now = null): string
    {
        static $named = null;
        if ($named === null) {
            $named = array_flip(\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC));
        }
        try {
            $zone = isset($named[$timezone]) === true ? new \DateTimeZone($timezone) : new \DateTimeZone('Etc/GMT+12');
        } catch (\Throwable $e) {
            $zone = new \DateTimeZone('Etc/GMT+12');
        }
        $now = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $now->setTimezone($zone)->format('Y-m-d');
    }

    /** Is this text a real calendar date written `YYYY-MM-DD`? */
    public static function isDate(string $text): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) === true;
    }

    /**
     * 🧮 Until when a donor's name is kept.
     *
     * Counted from the END OF THE FINANCIAL YEAR of the last kept gift, never
     * from the gift itself. Most countries' rules start their clock at a year
     * end, so counting from the gift would clear a record up to a year early.
     *
     *   - With a year end set: the first such date ON OR AFTER the last gift.
     *     A gift on the year-end day belongs to the year ending that day.
     *   - With none set: ONE FULL YEAR after the last gift. No financial year
     *     that contains the gift can end later than that, so this can keep a
     *     record up to a year longer than needed and can never clear it early.
     *     (A gift on 29 February counts from 1 March of the next year, the
     *     later of the two days it could mean.)
     *
     * Then the years are added to that date, same month and same day. The
     * record is kept THROUGH the resulting date and is due from the day after.
     *
     * Worked out with whole numbers for the year, month and day, on purpose.
     * Nothing here uses PHP's date-and-time classes: adding a length of time
     * to a moment is exactly how an hour goes missing on the night the clocks
     * change, and none of that is wanted for a date on a calendar.
     *
     * @param string $lastGiftDate `YYYY-MM-DD`.
     * @param string $yearEnd      `MM-DD`, or '' when the organisation has not said.
     * @param int    $years        1 to 50.
     *
     * @return array{keepUntil: string, countedFrom: string, usedFallback: bool}
     *
     * @throws \InvalidArgumentException When a value is not what it should be.
     *   The callers treat that as "cannot tell", which never clears anything.
     */
    public static function keepUntil(string $lastGiftDate, string $yearEnd, int $years): array
    {
        if (self::isDate($lastGiftDate) === false) {
            throw new \InvalidArgumentException('the date of the last gift is not a real date: "' . $lastGiftDate . '"');
        }
        if ($years < self::MIN_YEARS || $years > self::MAX_YEARS) {
            throw new \InvalidArgumentException('the number of years is not between 1 and 50: ' . $years);
        }
        if ($yearEnd !== '' && self::yearEndFromText($yearEnd) === '') {
            throw new \InvalidArgumentException('the financial year end is not a real day of the year: "' . $yearEnd . '"');
        }

        [$year, $month, $day] = array_map('intval', explode('-', $lastGiftDate));

        if ($yearEnd !== '') {
            [$endMonth, $endDay] = array_map('intval', explode('-', $yearEnd));
            $countedFrom = sprintf('%04d-%02d-%02d', $year, $endMonth, $endDay);
            // Dates written this way sort as text in calendar order.
            if ($countedFrom < $lastGiftDate) {
                $countedFrom = sprintf('%04d-%02d-%02d', $year + 1, $endMonth, $endDay);
            }
            $usedFallback = false;
        } else {
            $countedFrom  = self::sameDayInYear($year + 1, $month, $day);
            $usedFallback = true;
        }

        [$fromYear, $fromMonth, $fromDay] = array_map('intval', explode('-', $countedFrom));

        return [
            'keepUntil'    => self::sameDayInYear($fromYear + $years, $fromMonth, $fromDay),
            'countedFrom'  => $countedFrom,
            'usedFallback' => $usedFallback,
        ];
    }

    /**
     * The same month and day in another year. The one day that may not exist
     * there, 29 February, becomes 1 March: the later day, so nothing is ever
     * cleared a day early because of a leap year.
     */
    private static function sameDayInYear(int $year, int $month, int $day): string
    {
        if (checkdate($month, $day, $year) === false) {
            return sprintf('%04d-03-01', $year);
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * 🚦 May one donor's kept name be cleared now? THE ONE PLACE THAT DECIDES.
     *
     * Used by the retention job, its preview, the settings page and the
     * erasure itself. The answers, in the order they are tested:
     *
     *   unreadable     the date could not be worked out. Never cleared.
     *   on-hold        the organisation has switched a hold on (an audit or a
     *                  tax enquiry is open). Never cleared.
     *   not-confirmed  no administrator has confirmed the number of years.
     *                  Never cleared (Salem874, 11 October 2026): clearing
     *                  too early destroys tax evidence for good, clearing
     *                  late can be put right. It also means a figure lowered
     *                  in the data file cannot clear anything unseen.
     *   not-due        the keep-until date has not passed.
     *   due            all of the above are out of the way. ONLY this answer
     *                  lets anything be cleared.
     *
     * @param array<string, mixed> $rule  From ruleForSite().
     * @param string|null          $today `YYYY-MM-DD`. Null means today's date at the last place on Earth to
     *                                    reach it (the rule's zone is always ''; see timezoneOfSite()). For tests.
     *
     * @return array{state: string, keepUntil: string, countedFrom: string, usedFallback: bool, today: string}
     */
    public static function stateFor(array $rule, string $lastGiftDate, ?string $today = null): array
    {
        $today = $today ?? self::today((string) $rule['timezone']);

        try {
            $date = self::keepUntil($lastGiftDate, (string) $rule['yearEnd'], (int) $rule['years']);
        } catch (\InvalidArgumentException $e) {
            return ['state' => 'unreadable', 'keepUntil' => '', 'countedFrom' => '', 'usedFallback' => false, 'today' => $today];
        }

        if ($rule['hold'] !== false) {
            $state = 'on-hold';
        } elseif ($rule['confirmed'] !== true) {
            $state = 'not-confirmed';
        } elseif (self::isDate($today) === false || $today <= $date['keepUntil']) {
            // Kept THROUGH the keep-until date; due from the day after.
            $state = 'not-due';
        } else {
            $state = 'due';
        }

        return $date + ['state' => $state, 'today' => $today];
    }

    // =========================================================================
    // 🎁 Which gifts keep the donor's name
    // =========================================================================

    /**
     * 🎁 The test for "this gift keeps its donor's name", as a piece of SQL.
     *
     * One piece of text, used by the count of kept gifts, by the update that
     * takes the name off every OTHER gift, and by the test that checks them,
     * so the three cannot disagree. FIXED TEXT: nothing typed by anybody goes
     * into it. `$alias` is the name the query gives `tblGivingEntry`, and is
     * always written in code.
     *
     *   all        every gift of the donor's at that organisation;
     *   receipted  a gift whose date falls inside one of the donor's tax
     *              declarations at that organisation, or inside the period of
     *              a giving statement made for them there.
     *
     * A declaration counts WHATEVER ITS STATUS (active, lapsed, withdrawn),
     * because nothing in the portal records which gifts were actually claimed
     * under it: a declaration that has since lapsed may still be the evidence
     * for a claim made while it was active.
     */
    public static function keptGiftSql(string $scope, string $alias = 'e'): string
    {
        return self::keptWhenSql($scope, $alias . '.siteID', $alias . '.donorID', $alias . '.donatedAt');
    }

    /**
     * The same test, for something that is not a gift YET: a named envelope
     * in an offering count that has not been closed. Closing the count makes
     * a gift from it, dated the count's service date, for the envelope's
     * giver. So the envelope is asked the question its gift will be asked.
     * `$site`, `$donor` and `$date` are column names written in code, never
     * anything typed by anybody.
     */
    public static function keptWhenSql(string $scope, string $site, string $donor, string $date): string
    {
        if ($scope === self::SCOPE_ALL) {
            return '(1 = 1)';
        }

        return '(EXISTS (SELECT 1 FROM tblGiftAidDeclaration kd WHERE kd.siteID = ' . $site . ' '
            . 'AND kd.donorID = ' . $donor . ' AND kd.validFrom <= ' . $date . ' '
            . 'AND (kd.validTo IS NULL OR kd.validTo >= ' . $date . ')) '
            . 'OR EXISTS (SELECT 1 FROM tblGivingStatementLog kl WHERE kl.siteID = ' . $site . ' '
            . 'AND kl.donorID = ' . $donor . ' AND kl.fromDate <= ' . $date . ' '
            . 'AND kl.toDate >= ' . $date . '))';
    }

    /**
     * 🚦 May ONE kept record be cleared now? stateFor() answers from a rule
     *    and a date; this asks the database the two things that function
     *    cannot know, and is what the retention job, its preview, the
     *    settings page and the save routine all use for a real record.
     *
     * 1. HAS THE ACCOUNT REALLY BEEN EMPTIED? A kept row is written near the
     *    START of an erasure and the account is emptied about HALF WAY
     *    through it. A run that stops in between (a host that kills the
     *    page, an audit line that cannot be written) leaves a kept row
     *    beside a live member. Until the account has been emptied, the
     *    answer is `not-erased`, and nothing is ever cleared: the member's
     *    gifts and tax declaration are still theirs. (A run that stops AFTER
     *    the account is emptied leaves an erased donor whose request is
     *    unfinished. That record is treated like any other erased donor's:
     *    the account names nobody, whatever the request says.) This test
     *    was missing at first, and the independent check of 12 October 2026
     *    showed a stopped, then cancelled, request ending with a live
     *    member's gift of five days earlier losing its donor.
     *
     * 2. WHICH GIFT IS THE LAST ONE? The date stored on the kept row was
     *    right when it was written. A gift recorded for the same donor
     *    afterwards (a standing order arriving between two runs, a count
     *    closed later, a bank line matched later) is newer. So the date
     *    counted from is the LATER of the stored one, the newest gift still
     *    linked to the donor at that organisation, and the service date of
     *    any open-count envelope that still names them. Counting from the
     *    stored date alone cleared such a gift's name a year early.
     *
     * If either cannot be read, the answer is `unreadable`: never cleared.
     *
     * @param array<string, mixed> $rule From ruleForSite().
     *
     * @return array{state: string, keepUntil: string, countedFrom: string, usedFallback: bool, today: string,
     *   countedFromGift: string, accountEmptied: bool}
     */
    public static function stateOfKeptRow(\mysqli $db, array $rule, int $siteId, int $donorId, string $storedLastGift, ?string $today = null): array
    {
        $today  = $today ?? self::today((string) $rule['timezone']);
        $unread = ['state' => 'unreadable', 'keepUntil' => '', 'countedFrom' => '', 'usedFallback' => false, 'today' => $today,
            'countedFromGift' => $storedLastGift, 'accountEmptied' => false];

        try {
            $stmt = $db->prepare(
                'SELECT (SELECT MAX(e.donatedAt) FROM tblGivingEntry e WHERE e.siteID = ? AND e.donorID = ?) AS newestGift, '
                . '(SELECT MAX(cs.serviceDate) FROM tblCountEnvelopes ce INNER JOIN tblCountSessions cs ON cs.countSessionID = ce.countSessionID '
                . ' WHERE cs.siteID = ? AND ce.giverID = ?) AS newestEnvelope, '
                . 'EXISTS (SELECT 1 FROM tblUsers u WHERE u.userID = ? AND ' . GdprEraser::emptiedAccountSql('u') . ') AS emptied'
            );
            if ($stmt === false) {
                return $unread;
            }
            $stmt->bind_param('iiiii', $siteId, $donorId, $siteId, $donorId, $donorId);
            $stmt->execute();
            $found = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('[WebMS-Intra] GivingRetention: a kept giving record of organisation #' . $siteId . ' could not be checked: ' . $e->getMessage());

            return $unread;
        }

        // Dates written YYYY-MM-DD sort as text in calendar order.
        $from = max($storedLastGift, (string) ($found['newestGift'] ?? ''), (string) ($found['newestEnvelope'] ?? ''));
        $state = self::stateFor($rule, $from, $today);
        $state['countedFromGift'] = $from;
        $state['accountEmptied']  = (int) ($found['emptied'] ?? 0) === 1;
        if ($state['accountEmptied'] === false && $state['state'] !== 'unreadable') {
            $state['state'] = 'not-erased';
        }

        return $state;
    }

    /**
     * 📋 The donors whose names one organisation is still keeping, each with
     *    its keep-until date and whether it may be cleared now.
     *
     * The retention job, its preview and the settings page all read this, so
     * the number an administrator is shown and the rows the job acts on are
     * decided by the same code.
     *
     * A database not yet upgraded to migration 209 has no such table, and so
     * is keeping nobody's name: that gives an empty list, not an error.
     *
     * @param array<string, mixed>|null $rule  From ruleForSite(). Null reads it now.
     * @param string|null               $today For tests.
     *
     * @return array<int, array<string, mixed>> Each: keptDonorID, donorID, erasureRequestID,
     *   fullName, scope, lastGiftDate, keptGiftCount, and the seven values of stateOfKeptRow().
     */
    public static function keptDonorsForSite(\mysqli $db, int $siteId, ?array $rule = null, ?string $today = null): array
    {
        $rule = $rule ?? self::ruleForSite($siteId);
        $out  = [];
        try {
            $stmt = $db->prepare(
                'SELECT keptDonorID, donorID, erasureRequestID, fullName, scope, lastGiftDate, keptGiftCount '
                . 'FROM tblGivingKeptDonors WHERE siteID = ? AND clearedAt IS NULL ORDER BY lastGiftDate, keptDonorID'
            );
            if ($stmt === false) {
                return [];
            }
            $stmt->bind_param('i', $siteId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $out[] = [
                    'keptDonorID'      => (int) $row['keptDonorID'],
                    'donorID'          => (int) $row['donorID'],
                    'erasureRequestID' => $row['erasureRequestID'] === null ? null : (int) $row['erasureRequestID'],
                    'fullName'         => $row['fullName'] === null ? null : (string) $row['fullName'],
                    'scope'            => (string) $row['scope'],
                    'lastGiftDate'     => (string) $row['lastGiftDate'],
                    'keptGiftCount'    => (int) $row['keptGiftCount'],
                ] + self::stateOfKeptRow($db, $rule, $siteId, (int) $row['donorID'], (string) $row['lastGiftDate'], $today);
            }
            $stmt->close();
        } catch (\mysqli_sql_exception $e) {
            if ((int) $e->getCode() !== 1146) {
                throw $e;
            }
        }

        return $out;
    }

    // =========================================================================
    // 🗣️ Words. Shared, so the page, the erasure report and the job agree.
    // =========================================================================

    /** A date in words: `2033-04-05` becomes "5 April 2033". Anything else comes back as it was. */
    public static function dateInWords(string $date): string
    {
        if (self::isDate($date) === false) {
            return $date;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $date));

        return $day . ' ' . self::MONTHS[$month] . ' ' . $year;
    }

    /** A year end in words: `04-05` becomes "5 April". '' when there is none. */
    public static function yearEndInWords(string $yearEnd): string
    {
        if (self::yearEndFromText($yearEnd) === '') {
            return '';
        }
        [$month, $day] = array_map('intval', explode('-', $yearEnd));

        return $day . ' ' . self::MONTHS[$month];
    }

    /** The twelve month names, 1 to 12, for the settings page. */
    public static function monthNames(): array
    {
        return self::MONTHS;
    }

    /**
     * 🗣️ Where the number of years comes from, in one or two plain sentences.
     *
     * Written into the erasure's audit trail and shown on the settings page.
     * It never says a figure is what the law requires: a figure is either the
     * organisation's own, confirmed or not, or the portal's suggestion.
     *
     * @param array<string, mixed> $rule From ruleForSite().
     */
    public static function figureSentence(array $rule): string
    {
        $years      = (int) $rule['years'];
        $words      = $years . ' ' . ($years === 1 ? 'year' : 'years');
        $suggestion = (array) $rule['suggestion'];
        $country    = (string) ($suggestion['countryName'] ?? '');

        if ($rule['confirmed'] === true) {
            return $words . ' was confirmed by this organisation with its adviser'
                . ((string) $rule['confirmedOn'] !== '' ? ' on ' . self::dateInWords((string) $rule['confirmedOn']) : '') . '.';
        }

        $waiting = ' THE ORGANISATION HAS NOT YET CONFIRMED IT, so nothing will be cleared until it does.';
        if ($rule['yearsOwn'] !== null) {
            return $words . ' is the figure this organisation has set.' . $waiting;
        }
        if ($suggestion['kind'] === 'found') {
            return $words . ' is the portal\'s suggestion for ' . $country . ' (how sure the source is: '
                . self::STRENGTH_WORDS[(string) $suggestion['strength']] . '). It is a suggestion, not legal advice.' . $waiting;
        }
        if ($suggestion['kind'] === 'raised') {
            return $words . ' is the portal\'s minimum suggestion; the figure found for ' . $country . ' was '
                . (int) $suggestion['yearsFound'] . '. It is a suggestion, not legal advice.' . $waiting;
        }

        return $words . ' is a cautious placeholder, not a finding: ' . self::placeholderReason($suggestion) . '.' . $waiting;
    }

    /**
     * Why there is no figure, as the end of a sentence.
     *
     * @param array<string, mixed> $suggestion From suggestionFor().
     */
    public static function placeholderReason(array $suggestion): string
    {
        return match ((string) $suggestion['why']) {
            'file-unreadable'     => 'the portal\'s list of country figures could not be read',
            'country-not-set'     => 'this organisation has not said which country it is in',
            'country-not-in-file' => 'the portal holds no figure for ' . (string) $suggestion['countryName'],
            default               => 'no figure was found for ' . (string) $suggestion['countryName'],
        };
    }
}
