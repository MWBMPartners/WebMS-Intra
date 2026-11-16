<?php
// Path: _core/ProviderSpend.php
/**
 * -----------------------------------------------------------------------------
 * Spending with outside companies, in each company's own currency 💱
 * -----------------------------------------------------------------------------
 * THE ONE PLACE a charge from an outside company (AI drafting, translation,
 * transcription, text messages) is PRICED, RECORDED and CHECKED against a
 * spending limit. Nothing else may price a call, add to `tblProviderSpend`, or
 * compare a month's spend with a limit.
 *
 * WHY IT EXISTS (#993; D-173, D-192, D-194)
 *   - D-173: a charge is recorded in the currency the company charges in, and
 *     converted ONLY to compare it with a limit. Two currencies are never added.
 *   - D-194: the installation's limit and an organisation's own limit are each
 *     written in that level's own currency, and the stricter figure always wins.
 *   - D-192: the rate comes from `ExchangeRates` (fetched daily, or typed), and
 *     spending is REFUSED when there is neither.
 *   - Until this class, three formulas computed pence with floating-point
 *     numbers and were wrong by 100 to 1,000 times: at $1 per million tokens,
 *     300 tokens in and 250 out cost about 0.155 US cents, and the old Anthropic
 *     formula called it 13p (100 times too big); the OpenAI formula was 1,000
 *     times too big. And the AI figure was read from the MERGED settings, so the
 *     installation did not cap an organisation (D-013).
 *
 * UNITS. Every amount here is a whole number of MILLIONTHS of one unit of a
 * named currency (1,000,000 = one dollar, pound or euro). One AI call costs
 * about a tenth of a cent, so hundredths would round it to nothing. A price is
 * also whole millionths (per million tokens, per million characters, per hour,
 * per text), so a cost is `ceil(units x price / divisor)` in integers only:
 * no float is ever created (`check_no_float_money.py`).
 *
 * ROUNDING always goes the way that refuses sooner: a cost is rounded UP, a
 * conversion is rounded UP (`ExchangeRates::toHundredths()`), and a figure that
 * cannot be worked out counts as over every limit.
 *
 * THE LIMIT CHECK (`whyRefused()`), in the order it asks:
 *   1. a service with no limit key (texts) is never refused here;
 *   2. the month's spend is read from `tblProviderSpend`; unreadable refuses;
 *   3. the INSTALLATION's figure is compared in the INSTALLATION's currency;
 *   4. when the organisation has its OWN row, its figure is compared again in
 *      the ORGANISATION's currency.
 *   Refused if either is reached. Two checks rather than one converted figure so
 *   an organisation's typed exchange rate can never loosen the installation's
 *   limit (the two administrators may be different parties): the installation's
 *   check never uses an organisation's rate (`ExchangeRates::rate()`, `$siteId`
 *   null).
 *
 * ZERO means different things per service (`ZERO_MEANS_NO_LIMIT`): for
 * translation 0 is "no limit"; for AI and transcription it is "nothing may be
 * spent". A uniform rule would let AI or transcription spend without limit.
 *
 * WHAT THIS CANNOT DO. Prices are the company's list prices written here by
 * hand, not read from the company: see `PRICES` for which were checked and
 * when. A model dearer than any listed is under-counted. A company that bills
 * in a different currency from the one listed here (DeepL bills some customers
 * in dollars or pounds) is estimated in the listed one. A text message's real
 * price depends on the country it goes to; the estimate is flat.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/993
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class ProviderSpend
{
    /** The four kinds of outside company whose charges are recorded. */
    public const SERVICES = ['ai', 'translation', 'transcription', 'sms'];

    /** Services where a stored 0 means "no limit" at that level. The others: 0 means nothing may be spent. */
    public const ZERO_MEANS_NO_LIMIT = ['translation'];

    /**
     * The setting that holds each service's monthly limit, in HUNDREDTHS of the
     * currency of the level that stored it. Texts have no limit. The key names
     * still say `Pence`; renaming them is a separate issue (D-012 of the plan).
     */
    public const CAP_KEYS = [
        'ai'            => 'ai_assist.monthCapPence',
        'translation'   => 'translation.monthCapPence',
        'transcription' => 'transcription.monthCapPence',
    ];

    /** The built-in figure when nothing is stored, in hundredths: £50-equivalent, as each service always had. */
    public const DEFAULT_CAPS = ['ai' => 5000, 'translation' => Translation::DEFAULT_CAP_PENCE, 'transcription' => Transcription::DEFAULT_CAP_PENCE];

    /**
     * The most a limit may ever be, in hundredths. AI never had one; the figure
     * here is simply larger than any real budget, so `min()` leaves it alone.
     * Translation and transcription keep the maxima they always had. These are
     * bare numbers, so in a currency with tiny units they cap at a few cents'
     * worth: a known limit, recorded as its own issue.
     */
    public const HARD_MAX_CAPS = ['ai' => 2000000000, 'translation' => Translation::MAX_CAP_PENCE, 'transcription' => Transcription::HARD_MAX_CAP_PENCE];

    /**
     * 💲 LIST PRICES, in whole millionths. Per service and provider: the
     * currency the company charges in, what the figures are per, and the price.
     *
     * HOW EACH WAS CHECKED. "Checked" means read from the company's own pricing
     * page on 16 November 2026; "recalled" means NOT checked (the page needs a
     * browser to show prices) and is the figure as remembered on that day.
     *   - Anthropic: CHECKED. Claude Haiku 4.5 is $1 in and $5 out per million
     *     tokens; the dearest model listed then was Opus 4.1 at $15 and $75,
     *     which is what an unknown Anthropic model is charged at.
     *   - Google Cloud Translation: CHECKED. $20 per million characters (the
     *     first paid tier).
     *   - AssemblyAI: CHECKED, and kept HIGHER on purpose. Its page lists $0.15
     *     and $0.21 per hour for speech-to-text, plus add-ons; the $0.37 an hour
     *     already in this code (above both, so it leans towards refusing) stays.
     *   - OpenAI chat models (gpt-4o-mini $0.15/$0.60, gpt-4o $2.50/$10 per
     *     million tokens) and Whisper ($0.006 a minute): RECALLED, not checked.
     *   - DeepL: EUR 20 per million characters: RECALLED, not checked.
     *   - Texts: NOT list prices. The old flat 4p (Twilio), 5p (MessageBird) and
     *     6p (AWS) written in `Sms.php`, re-expressed in the currency each
     *     company bills in at the 13 November 2026 European Central Bank rate
     *     (1 euro = 1.1052 US dollars = 0.83559 pounds). They are estimates
     *     carried over, not checked against any company's page.
     * A provider with no entry (local, LibreTranslate) reports no cost.
     *
     * Token prices are `in`/`out` per million tokens; character prices are per
     * million characters; transcription prices are per hour; text prices are
     * per text. A `models` entry is matched as a PREFIX of the model name; a
     * model that matches none is charged at `unknown`, the dearest listed for
     * that company, so an unfamiliar model makes a limit bite early.
     */
    public const PRICES = [
        'ai' => [
            'anthropic' => ['currency' => 'USD', 'per' => 'tokens',
                'models' => ['claude-haiku-4-5' => ['in' => 1000000, 'out' => 5000000]],
                'unknown' => ['in' => 15000000, 'out' => 75000000]],
            'openai' => ['currency' => 'USD', 'per' => 'tokens',
                'models' => ['gpt-4o-mini' => ['in' => 150000, 'out' => 600000], 'gpt-4o' => ['in' => 2500000, 'out' => 10000000]],
                'unknown' => ['in' => 2500000, 'out' => 10000000]],
        ],
        'translation' => [
            'anthropic' => ['currency' => 'USD', 'per' => 'tokens',
                'models' => ['claude-haiku-4-5' => ['in' => 1000000, 'out' => 5000000]],
                'unknown' => ['in' => 15000000, 'out' => 75000000]],
            'openai' => ['currency' => 'USD', 'per' => 'tokens',
                'models' => ['gpt-4o-mini' => ['in' => 150000, 'out' => 600000], 'gpt-4o' => ['in' => 2500000, 'out' => 10000000]],
                'unknown' => ['in' => 2500000, 'out' => 10000000]],
            'google' => ['currency' => 'USD', 'per' => 'characters', 'price' => 20000000],
            'deepl'  => ['currency' => 'EUR', 'per' => 'characters', 'price' => 20000000],
        ],
        'transcription' => [
            'openai'     => ['currency' => 'USD', 'per' => 'hour', 'price' => 360000],
            'assemblyai' => ['currency' => 'USD', 'per' => 'hour', 'price' => 370000],
        ],
        'sms' => [
            'twilio'      => ['currency' => 'USD', 'per' => 'text', 'price' => 52907],
            'messagebird' => ['currency' => 'EUR', 'per' => 'text', 'price' => 59838],
            'aws'         => ['currency' => 'USD', 'per' => 'text', 'price' => 79360],
        ],
    ];

    // 💲 =========================================================================
    // Pricing
    // =========================================================================

    /**
     * The currency a service's provider charges in, or null when it reports no
     * cost (a local route, LibreTranslate, an unknown provider).
     */
    public static function currencyOf(string $service, string $provider): ?string
    {
        $entry = self::PRICES[$service][$provider] ?? null;

        return $entry === null ? null : (string) $entry['currency'];
    }

    /**
     * Every currency any listed company charges in, sorted: what the exchange-
     * rates page offers a rate from. Replaces the fixed pair package A used.
     *
     * @return list<string>
     */
    public static function billingCurrencies(): array
    {
        $codes = [];
        foreach (self::PRICES as $providers) {
            foreach ($providers as $entry) {
                $codes[(string) $entry['currency']] = true;
            }
        }
        $list = \array_keys($codes);
        \sort($list, \SORT_STRING);

        return $list;
    }

    /**
     * What one charge costs, in whole millionths of the company's currency, or
     * null when that company reports no cost. Integers only, rounded UP.
     *
     * `$use` carries what was used: `['in' => tokens, 'out' => tokens]`,
     * `['chars' => n]`, `['seconds' => n]` or `['texts' => n]`. A figure that
     * is missing or negative counts as 0, never as a reason to skip the cost.
     *
     * @param array<string,int> $use
     *
     * @return array{currency:string, millionths:int}|null
     */
    public static function cost(string $service, string $provider, ?string $model, array $use): ?array
    {
        $entry = self::PRICES[$service][$provider] ?? null;
        if ($entry === null) {
            return null;
        }
        $units = static fn (string $key): int => \max(0, (int) ($use[$key] ?? 0));
        $sum   = 0;
        $div   = 1000000;
        switch ((string) $entry['per']) {
            case 'tokens':
                $price = $entry['unknown'];
                foreach ($entry['models'] as $prefix => $modelPrice) {
                    if ($model !== null && \str_starts_with($model, (string) $prefix) === true) {
                        $price = $modelPrice;
                        break;
                    }
                }
                $sum = ($units('in') * (int) $price['in']) + ($units('out') * (int) $price['out']);
                break;
            case 'characters':
                $sum = $units('chars') * (int) $entry['price'];
                break;
            case 'hour':
                $sum = $units('seconds') * (int) $entry['price'];
                // The price is millionths per HOUR, so per second it is price / 3600.
                $div = 3600;
                break;
            case 'text':
                $sum = $units('texts') * (int) $entry['price'];
                $div = 1;
                break;
            default:
                return null;
        }
        // Ceiling of sum / div, in integers.
        $millionths = \intdiv($sum + $div - 1, $div);

        return ['currency' => (string) $entry['currency'], 'millionths' => $millionths];
    }

    // 🧾 =========================================================================
    // Recording and reading
    // =========================================================================

    /** The month a charge made now falls in: the first day of this month in UTC. */
    public static function currentMonth(): string
    {
        return \gmdate('Y-m-01');
    }

    /**
     * Add one charge to this month's record. A null or zero cost writes nothing.
     * Never throws into the caller: the provider has already been paid, so the
     * words are still returned, and the failure goes to PHP's error log in
     * fixed words (never the fault's own, which can quote the statement).
     *
     * @param array{currency:string, millionths:int}|null $cost
     */
    public static function record(\mysqli $db, int $siteId, string $service, ?array $cost, ?string $month = null): void
    {
        if ($cost === null || $cost['millionths'] <= 0 || \in_array($service, self::SERVICES, true) === false) {
            return;
        }
        $month ??= self::currentMonth();
        $code    = (string) $cost['currency'];
        $amount  = (int) $cost['millionths'];
        try {
            $stmt = $db->prepare(
                'INSERT INTO `tblProviderSpend` (`siteID`, `monthStartUtc`, `service`, `costCurrency`, `costMillionths`) '
                . 'VALUES (?, ?, ?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE `costMillionths` = `costMillionths` + VALUES(`costMillionths`)'
            );
            $stmt->bind_param('isssi', $siteId, $month, $service, $code, $amount);
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $ignored) {
            \error_log('ProviderSpend: a charge for organisation ' . $siteId . ' could not be recorded; this month\'s spend is under-counted.');
        }
    }

    /**
     * This month's spend for one service, per currency, in millionths; or null
     * when it cannot be read. Null is never "nothing spent": every caller
     * refuses on it.
     *
     * @return array<string,int>|null
     */
    public static function monthTotals(\mysqli $db, int $siteId, string $service, ?string $month = null): ?array
    {
        $month ??= self::currentMonth();
        try {
            $stmt = $db->prepare(
                'SELECT `costCurrency`, `costMillionths` FROM `tblProviderSpend` '
                . 'WHERE `siteID` = ? AND `monthStartUtc` = ? AND `service` = ?'
            );
            $stmt->bind_param('iss', $siteId, $month, $service);
            $stmt->execute();
            $result = $stmt->get_result();
            $totals = [];
            while (($row = $result->fetch_assoc()) !== null) {
                $totals[(string) $row['costCurrency']] = (int) $row['costMillionths'];
            }
            $stmt->close();
        } catch (\Throwable $ignored) {
            return null;
        }

        return $totals;
    }

    // 🚦 =========================================================================
    // The limit
    // =========================================================================

    /**
     * Why a new charge may not be started, or '' when it may. One word:
     *   `no-budget`           a figure of 0: nothing may be spent at all (not
     *                         "used up": no budget was ever set)
     *   `installation-limit`  the installation's figure is reached
     *   `organisation-limit`  the organisation's own figure is reached
     *   `no-rate`             a limit has to be compared and no usable exchange
     *                         rate exists for a currency involved
     *   `no-currency`         a limit has to be compared and the level that set
     *                         it has no currency chosen
     *   `unreadable`          the month's record or a figure could not be read
     *
     * `$callCurrency` is the currency the coming call will be charged in (null
     * for a free route). It needs a rate BEFORE the first call, even at zero
     * spend, because after the call is too late to refuse (D-192). Where neither
     * level has a limit (translation, both 0) nothing is compared and no rate is
     * needed (D-173: convert only to compare).
     */
    public static function whyRefused(\mysqli $db, int $siteId, string $service, ?string $callCurrency): string
    {
        $key = self::CAP_KEYS[$service] ?? null;
        if ($key === null) {
            return '';
        }
        $totals = self::monthTotals($db, $siteId, $service);
        if ($totals === null) {
            return 'unreadable';
        }
        try {
            $installationStored = SettingLimit::storedAtLevel($db, $key, null);
            $organisationStored = SettingLimit::storedAtLevel($db, $key, $siteId);
        } catch (\Throwable $ignored) {
            return 'unreadable';
        }
        $currencies = \array_keys($totals);
        if ($callCurrency !== null && $callCurrency !== '' && isset($totals[$callCurrency]) === false) {
            $currencies[] = $callCurrency;
        }

        // 1. The installation's figure, in the installation's currency, using
        //    the installation's rate and never an organisation's typed one.
        $word = self::compare($db, $service, $installationStored, null, Currency::ofInstallation(), $totals, $currencies, 'installation-limit');
        if ($word !== '') {
            return $word;
        }
        // 2. The organisation's own figure, only when it has its own row.
        if ($organisationStored !== null) {
            $word = self::compare($db, $service, $organisationStored, $siteId, Currency::ofOrganisation($siteId), $totals, $currencies, 'organisation-limit');
            if ($word !== '') {
                return $word;
            }
        }

        return '';
    }

    /**
     * One level's comparison: '' when the spend is under that level's figure
     * (or the level has no limit), else the refusal word.
     *
     * @param array<string,int> $totals
     * @param list<string>      $currencies
     */
    private static function compare(\mysqli $db, string $service, ?string $stored, ?int $siteId, string $levelCurrency,
                                    array $totals, array $currencies, string $limitWord): string
    {
        $default = self::DEFAULT_CAPS[$service];
        $hardMax = self::HARD_MAX_CAPS[$service];
        if (\in_array($service, self::ZERO_MEANS_NO_LIMIT, true) === true
            && SettingLimit::allowsForEverFrom($stored, $default, $hardMax) === true) {
            return '';
        }
        $figure = SettingLimit::ceilingFrom($stored, $default, $hardMax, false);
        // Nothing may be spent at a figure of 0: no rate is needed to say so,
        // and the word says it is a budget of nothing rather than one used up
        // (an administrator told a budget "has been used up" when none was ever
        // set goes looking for spending that never happened).
        if ($figure <= 0) {
            return 'no-budget';
        }
        if ($levelCurrency === '') {
            return 'no-currency';
        }
        $rates = [];
        foreach ($currencies as $code) {
            $rate = ExchangeRates::rate($db, $code, $levelCurrency, $siteId);
            if ($rate === null) {
                return 'no-rate';
            }
            $rates[$code] = $rate;
        }
        $spent = 0;
        foreach ($totals as $code => $millionths) {
            $hundredths = ExchangeRates::toHundredths($db, (int) $millionths, $rates[(string) $code]);
            if ($hundredths === null) {
                // Could not be worked out: over every limit, never nothing.
                return $limitWord;
            }
            $spent += $hundredths;
        }

        return $spent >= $figure ? $limitWord : '';
    }

    // 🖥️ =========================================================================
    // Words and summaries for pages
    // =========================================================================

    /**
     * The ONE way a charge is written for a person: a dash for none, "less than
     * 0.01 of the currency" under one hundredth, otherwise the amount rounded up
     * to the hundredth.
     */
    public static function spendWords(?string $currency, ?int $millionths): string
    {
        if ($millionths === null || $millionths <= 0 || $currency === null || $currency === '') {
            return '—';
        }
        if ($millionths < 10000) {
            return 'less than ' . Currency::format(1, $currency);
        }

        return Currency::format(\intdiv($millionths + 9999, 10000), $currency);
    }

    /**
     * What an admin page shows for one service this month. Everything it needs
     * in one read, so the page cannot say "reached" while a press still sends
     * (the same rule `whyRefused()` applies).
     *
     * `installation` and `organisation` hold: the currency, the figure in
     * hundredths (null when there is none), whether it means "no limit", and
     * what the whole month's spend counts as against it (hundredths, null when
     * it could not be worked out).
     *
     * @return array{totals:array<string,int>|null, refusal:string, installation:array<string,mixed>, organisation:array<string,mixed>|null}
     */
    public static function monthSummary(\mysqli $db, int $siteId, string $service): array
    {
        $totals  = self::monthTotals($db, $siteId, $service);
        $refusal = self::whyRefused($db, $siteId, $service, null);
        $key     = self::CAP_KEYS[$service] ?? null;
        $levels  = ['installation' => null, 'organisation' => null];
        if ($key !== null) {
            try {
                $inst = SettingLimit::storedAtLevel($db, $key, null);
                $own  = SettingLimit::storedAtLevel($db, $key, $siteId);
            } catch (\Throwable $ignored) {
                $inst = null;
                $own  = null;
            }
            $levels['installation'] = self::levelFacts($db, $service, $inst, null, Currency::ofInstallation(), $totals);
            if ($own !== null) {
                $levels['organisation'] = self::levelFacts($db, $service, $own, $siteId, Currency::ofOrganisation($siteId), $totals);
            }
        }

        return ['totals' => $totals, 'refusal' => $refusal, 'installation' => $levels['installation'] ?? [], 'organisation' => $levels['organisation']];
    }

    /**
     * The INSTALLATION's own monthly figure for a service, in hundredths of the
     * installation's currency, resolved to the built-in default when nothing is
     * stored and cut at the hard maximum: what the installation-wide form box
     * shows (#674), and the number the box's own hint quotes. Never an
     * organisation's figure.
     */
    public static function installationFigure(\mysqli $db, string $service): int
    {
        $key = self::CAP_KEYS[$service] ?? null;
        if ($key === null) {
            return 0;
        }
        try {
            $stored = SettingLimit::storedAtLevel($db, $key, null);
        } catch (\Throwable $ignored) {
            $stored = null;
        }

        return SettingLimit::ceilingFrom($stored, self::DEFAULT_CAPS[$service], self::HARD_MAX_CAPS[$service], false);
    }

    /**
     * The words an admin page prints for `monthSummary()`: what has been spent
     * this month (each currency apart, never added together), each limit in
     * its own currency with what the spend counts as against it, and — when a
     * limit is in the way — why, in words and with where to fix it. Plain text:
     * the page escapes it. The ONE place those sentences are written, so the
     * four spend pages cannot say different things.
     *
     * @param array{totals:array<string,int>|null, refusal:string, installation:array<string,mixed>, organisation:array<string,mixed>|null} $summary
     *
     * @return array{spent:string, limits:list<string>, refusal:string}
     */
    public static function pageLines(array $summary): array
    {
        if ($summary['totals'] === null) {
            $spent = 'could not be read';
        } elseif ($summary['totals'] === []) {
            $spent = 'nothing yet';
        } else {
            $parts = [];
            \ksort($summary['totals'], \SORT_STRING);
            foreach ($summary['totals'] as $code => $millionths) {
                $parts[] = self::spendWords((string) $code, (int) $millionths);
            }
            $spent = \implode(' and ', $parts);
        }
        $limits = [];
        foreach (['installation' => 'The installation\'s limit', 'organisation' => 'This organisation\'s own limit'] as $level => $label) {
            $facts = $summary[$level];
            if (\is_array($facts) === false || $facts === []) {
                continue;
            }
            if ($facts['noLimit'] === true) {
                $limits[] = $label . ': no limit.';
                continue;
            }
            if ((string) $facts['currency'] === '') {
                $limits[] = $label . ': ' . (string) $facts['figure'] . ' — but no currency has been chosen for it, so it cannot be checked until one is (Admin, Settings, Organisation).';
                continue;
            }
            $line = $label . ': ' . Currency::format((int) $facts['figure'], (string) $facts['currency']) . ' a month';
            if ($facts['counts'] !== null) {
                $line .= '; this month\'s spending counts as ' . Currency::format((int) $facts['counts'], (string) $facts['currency']) . ' against it';
            } else {
                $line .= '; what this month\'s spending comes to in that currency could not be worked out (no exchange rate)';
            }
            $limits[] = $line . '.';
        }

        return ['spent' => $spent, 'limits' => $limits, 'refusal' => self::refusalWords((string) $summary['refusal'])];
    }

    /**
     * An admin's words for why a service is stopped by its money limit, or ''
     * when it is not. Never says "used up" for a budget of nothing.
     */
    public static function refusalWords(string $word): string
    {
        return match ($word) {
            ''                   => '',
            'installation-limit' => 'The installation\'s monthly limit has been reached, so nothing more will be sent until next month or the limit is raised.',
            'organisation-limit' => 'This organisation\'s own monthly limit has been reached, so nothing more will be sent until next month or the limit is raised.',
            'no-budget'          => 'The monthly limit is set to nothing, so nothing can be sent.',
            'no-rate'            => 'Nothing can be sent right now: there is no exchange rate to check the spending limit with. Add one under Admin, Exchange rates.',
            'no-currency'        => 'Nothing can be sent right now: no currency has been chosen to check the spending limit in. Choose one under Admin, Settings, Organisation.',
            default              => 'Nothing can be sent right now, because this month\'s spending could not be read.',
        };
    }

    /**
     * @param array<string,int>|null $totals
     *
     * @return array<string,mixed>
     */
    private static function levelFacts(\mysqli $db, string $service, ?string $stored, ?int $siteId, string $currency, ?array $totals): array
    {
        $default = self::DEFAULT_CAPS[$service];
        $hardMax = self::HARD_MAX_CAPS[$service];
        $noLimit = \in_array($service, self::ZERO_MEANS_NO_LIMIT, true) === true
            && SettingLimit::allowsForEverFrom($stored, $default, $hardMax) === true;
        $counts = null;
        if ($totals !== null && $currency !== '' && $noLimit === false) {
            $counts = 0;
            foreach ($totals as $code => $millionths) {
                $rate = ExchangeRates::rate($db, (string) $code, $currency, $siteId);
                $part = $rate === null ? null : ExchangeRates::toHundredths($db, (int) $millionths, $rate);
                if ($part === null) {
                    $counts = null;
                    break;
                }
                $counts += $part;
            }
        }

        return [
            'currency' => $currency,
            'noLimit'  => $noLimit,
            'figure'   => $noLimit === true ? null : SettingLimit::ceilingFrom($stored, $default, $hardMax, false),
            'counts'   => $counts,
        ];
    }
}
