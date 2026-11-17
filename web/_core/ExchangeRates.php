<?php
// Path: _core/ExchangeRates.php
/**
 * -----------------------------------------------------------------------------
 * Exchange rates: fetched, stored, typed and chosen in ONE place 💱 (#993)
 * -----------------------------------------------------------------------------
 * D-173 says an outside company's charge is kept in that company's own
 * currency and is converted ONLY to compare it with a spending limit. D-192
 * says where the rate for that comes from: fetched once a day from a public
 * service, with a rate an administrator typed as the fallback, and with
 * neither, spending is refused. This class is that rule, and nothing else in
 * the portal fetches, stores, types or chooses a rate.
 *
 * WHERE THE RATES COME FROM. The European Central Bank's daily file
 * (`SOURCE_URL`): free, no key, published since 1999, about thirty currencies,
 * each "1 euro = rate". Its rates are XML ATTRIBUTES, so they arrive as text
 * and are kept as text from the file to the database: no floating-point
 * number ever touches a rate. Two other services were rejected because they
 * answer in JSON, which PHP decodes to floats, and because one of them asks
 * for attribution on the page. A currency the bank does not publish (the
 * Nigerian naira, say) uses a rate an administrator types, which is exactly
 * D-192's fallback.
 *
 * WHICH RATE (`rate()`), in this order:
 *   1. the same currency: exactly 1;
 *   2. a FRESH fetched rate, when both currencies are in the bank's list
 *      (the euro counts as 1): `perEuro(to) / perEuro(from)`;
 *   3. for an ORGANISATION's own limit only, that organisation's typed rate;
 *   4. the installation's typed rate;
 *   5. nothing: the caller refuses.
 * For the INSTALLATION's limit an organisation's typed rate is NEVER used
 * (`$siteId === null` means "for the installation's limit"). The installation
 * and an organisation may be different parties (D-013); if the organisation's
 * rate could reach the installation's check, an organisation could type a low
 * rate and spend past the installation's limit.
 *
 * FRESH means the bank's own date is no more than `FRESH_DAYS` days before
 * today in UTC. The bank publishes on working days only and its longest gap
 * (Easter) is about five days. A stale fetched rate is not used (D-192).
 *
 * ARITHMETIC. A cost is kept in millionths of a unit of its currency
 * (package B). `toHundredths()` is the ONE converter. It works in MySQL's
 * exact DECIMAL arithmetic, because no PHP number extension is an installer
 * requirement and a float must never touch money. It rounds UP (a ceiling):
 * an estimate that rounds towards refusing is the safe direction. MySQL's
 * DECIMAL division keeps 14 decimal places here, so the fetched route can
 * under-count by at most one hundredth; that is stated, not hidden.
 *
 * THE FETCH is an outside service (D-058, D-195): it is named on both privacy
 * pages through OutsideServices, it sends nothing about anybody (only the
 * request for the published list) and the installation can switch it off with
 * `exchangeRates.fetch`. It rides on `cron/mail-queue` as a third step
 * (`refreshIfDue()`), so no customer has to add a scheduler line.
 *
 * WHAT IT CANNOT DO. It cannot say the rate is a fair price; the bank's rate
 * is a reference rate. It cannot make a fetch happen on an installation whose
 * scheduler does not call the mail-queue job: there an administrator presses
 * "Fetch now" or types a rate. `refreshFrom()` is public for the self-test
 * only: nothing under web/ may call it with another address.
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

final class ExchangeRates
{
    /** The bank's daily file. The ONLY address this class ever fetches. */
    public const SOURCE_URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

    /** The XML namespace the bank's rate lines are written in. */
    public const ECB_NAMESPACE = 'http://www.ecb.int/vocabulary/2002-08-01/eurofxref';

    /** The installation-wide switch for the daily fetch. */
    public const FETCH_SETTING = 'exchangeRates.fetch';

    /** A fetched rate is usable while the bank's date is at most this many days old. */
    public const FRESH_DAYS = 7;

    /** After a success, fetch again no sooner than this (20 hours). */
    public const FETCH_EVERY_SECONDS = 72000;

    /** After a failure, try again no sooner than this (1 hour). */
    public const RETRY_AFTER_SECONDS = 3600;

    /** The file is about 1.5 KB; anything past this is not the file. */
    public const MAX_BYTES = 65536;

    /** How many rates the last successful refreshFrom() found in the file (not the table's size). */
    private static int $lastStored = 0;

    /** What a rate may look like: up to 8 figures, then up to 10 after the point. */
    private const RATE_PATTERN = '/^\d{1,8}(\.\d{1,10})?$/';

    /** What the general settings editor says when it refuses a key of ours. */
    public const LOCKED_MESSAGE = 'Exchange rates are set at Admin, Exchange rates. Nothing was changed.';

    // 🔒 =========================================================================
    // The general settings editor
    // ===========================================================================

    /**
     * Is this setting one the general settings editor must refuse? Every
     * `exchangeRates.` key: they are changed only on the rates page, which
     * judges who may change them. Capitals are ignored, as everywhere.
     */
    public static function lockedInGeneralEditor(string $key): bool
    {
        return strncasecmp($key, 'exchangeRates.', 14) === 0;
    }

    // 📖 =========================================================================
    // Reading the bank's file
    // ===========================================================================

    /**
     * Read the bank's file into `['date' => 'Y-m-d', 'perEuro' => [CODE => text]]`,
     * or answer a reason word. Rates stay text from start to finish.
     *
     * A file with a DOCTYPE or an ENTITY is refused outright: the real file
     * has neither, and an external-entity file is the classic way to make a
     * parser read a local file. `LIBXML_NONET` is the second guard, not the
     * first. One bad line is skipped; the file is refused when no line is good.
     *
     * @return array{date: string, perEuro: array<string,string>}|string
     */
    public static function parseEcb(string $xml): array|string
    {
        if (function_exists('simplexml_load_string') === false) {
            return 'no-xml';
        }
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            return 'unreadable';
        }
        $previous = libxml_use_internal_errors(true);
        $root     = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($root === false) {
            return 'unreadable';
        }
        $outer = $root->children(self::ECB_NAMESPACE)->Cube ?? null;
        if ($outer === null || isset($outer->Cube) === false) {
            return 'unreadable';
        }
        $day  = $outer->Cube;
        $date = (string) ($day->attributes()['time'] ?? '');
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1
            || checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) === false) {
            return 'unreadable';
        }
        $rates = [];
        foreach ($day->Cube as $line) {
            $code = (string) ($line->attributes()['currency'] ?? '');
            $rate = (string) ($line->attributes()['rate'] ?? '');
            if (preg_match('/^[A-Z]{3}$/', $code) !== 1
                || preg_match(self::RATE_PATTERN, $rate) !== 1
                || preg_match('/[1-9]/', $rate) !== 1) {
                continue;
            }
            // A currency written twice is a malformed file, not a choice of which to believe.
            if (isset($rates[$code]) === true) {
                return 'unreadable';
            }
            $rates[$code] = $rate;
        }
        if ($rates === []) {
            return 'unreadable';
        }

        return ['date' => $date, 'perEuro' => $rates];
    }

    /**
     * Is a fetched rate dated this day still usable? Pure: `$today` is for
     * tests (Y-m-d, UTC). A date in the future by more than one day is not
     * fresh either: a clock that far out cannot be trusted.
     */
    public static function isFresh(string $rateDate, ?string $today = null): bool
    {
        $utc  = new \DateTimeZone('UTC');
        $then = \DateTimeImmutable::createFromFormat('!Y-m-d', $rateDate, $utc);
        $now  = \DateTimeImmutable::createFromFormat('!Y-m-d', $today ?? gmdate('Y-m-d'), $utc);
        if ($then === false || $now === false) {
            return false;
        }
        // UTC has no clock changes, so a day is exactly 86,400 seconds here.
        $days = intdiv($now->getTimestamp() - $then->getTimestamp(), 86400);

        return $days <= self::FRESH_DAYS && $days >= -1;
    }

    // 🌐 =========================================================================
    // Fetching
    // =========================================================================

    /** Fetch the bank's real file now. '' when it worked, else a reason word. */
    public static function refresh(\mysqli $db): string
    {
        // A NET UNDER THE TESTS (#993 check, round 1). A self-test database is
        // named selftest_..., and no real installation's is (the installer does not
        // forbid the name, and cannot: its own self-test installs into selftest_inst_...
        // databases; a customer who chose it would see this word on the rates page). A test that runs
        // the job or this method against such a database, with the bank's real
        // address and no stub standing in for it (SafeFetch's test override for
        // that host), must never reach the real bank: it would make CI depend on
        // an outside company and store real rates in a test. The switch is the
        // first guard (each test turns it off); this is the one that holds when
        // a test forgot. It records the same fixed problem word a refusal does.
        if (self::isSelfTestDatabase($db) === true && SafeFetch::hasTestStandIn((string) parse_url(self::SOURCE_URL, PHP_URL_HOST)) === false) {
            self::touchFetchRow($db, 'attempt');
            self::touchFetchRow($db, 'problem', 'test-blocked');
            if (getenv('EXCHANGE_RATES_BLOCK_LOG') !== false) {
                @file_put_contents((string) getenv('EXCHANGE_RATES_BLOCK_LOG'), "blocked\n", FILE_APPEND);
            }

            return 'test-blocked';
        }

        return self::refreshFrom($db, self::SOURCE_URL, true);
    }

    /** Is this connection's database one a self-test made (its name starts selftest_)? */
    private static function isSelfTestDatabase(\mysqli $db): bool
    {
        try {
            $row = $db->query('SELECT DATABASE() AS `d`')->fetch_assoc();

            return str_starts_with((string) ($row['d'] ?? ''), 'selftest_') === true;
        } catch (\Throwable $ignored) {
            return false;
        }
    }

    /**
     * Fetch from `$url` (public for the self-test only: see the class header).
     * Always records the attempt. On success stores each rate only where the
     * file's date is not older than what is held; on failure stores one fixed
     * word and leaves every rate untouched. Never logs the reply.
     */
    public static function refreshFrom(\mysqli $db, string $url, bool $httpsOnly): string
    {
        self::touchFetchRow($db, 'attempt');
        // ⚠️ SafeFetch::get() and nothing else, and never CURLOPT_ENCODING (#716):
        //    setting that option to any value lets libcurl decode a Brotli
        //    reply, and the threat is the server itself.
        $answer = SafeFetch::get($url, [
            'maxBytes'              => self::MAX_BYTES,
            'timeoutSeconds'        => 10,
            'connectTimeoutSeconds' => 5,
            'maxRedirects'          => 2,
            'httpsOnly'             => $httpsOnly,
            'accept'                => 'application/xml, text/xml',
            'purpose'               => 'exchange rates',
        ]);
        if ($answer['ok'] !== true) {
            $word = $answer['capped'] === true ? 'capped'
                : ($answer['message'] === SafeFetch::REFUSED_MESSAGE ? 'refused'
                : ($answer['status'] > 0 ? 'http' : 'unreachable'));
            self::touchFetchRow($db, 'problem', $word);

            return $word;
        }
        $parsed = self::parseEcb($answer['body']);
        if (is_string($parsed) === true) {
            self::touchFetchRow($db, 'problem', $parsed);

            return $parsed;
        }
        // A file dated more than a day ahead of now (UTC) is not the bank's
        // list: stored, it would sit above every real day's date for good, so
        // no later file could replace it. Refused and recorded as a PROBLEM.
        if ($parsed['date'] > gmdate('Y-m-d', time() + 86400)) {
            self::touchFetchRow($db, 'problem', 'future-date');

            return 'future-date';
        }
        try {
            $db->begin_transaction();
            // A rate is replaced when the file's day is not older than the held
            // one. A held day more than a day ahead of now is replaceable too,
            // so a wrong date that got in some other way (a hand-typed row, an
            // old bug) can never lock rates out for good.
            $same = '(VALUES(`rateDate`) >= `rateDate` OR `rateDate` > UTC_DATE() + INTERVAL 1 DAY)';
            $stmt = $db->prepare(
                'INSERT INTO `tblExchangeRate` (`rateCurrency`, `perEuro`, `rateDate`, `fetchedAtUtc`) '
                . 'VALUES (?, ?, ?, UTC_TIMESTAMP()) '
                . 'ON DUPLICATE KEY UPDATE '
                . '`perEuro` = IF(' . $same . ', VALUES(`perEuro`), `perEuro`), '
                . '`fetchedAtUtc` = IF(' . $same . ', VALUES(`fetchedAtUtc`), `fetchedAtUtc`), '
                . '`rateDate` = IF(' . $same . ', VALUES(`rateDate`), `rateDate`)'
            );
            foreach ($parsed['perEuro'] as $code => $rate) {
                $code = (string) $code;
                $stmt->bind_param('sss', $code, $rate, $parsed['date']);
                $stmt->execute();
            }
            $stmt->close();
            $db->commit();
            self::$lastStored = count($parsed['perEuro']);
        } catch (\Throwable $problem) {
            try {
                $db->rollback();
            } catch (\Throwable $ignored) {
            }
            error_log('Exchange rates: could not store the fetched rates: ' . get_class($problem));
            // A DATABASE failure, not a bad reply: its own word, so the page does not blame the file.
            self::touchFetchRow($db, 'problem', 'not-saved');

            return 'not-saved';
        }
        self::touchFetchRow($db, 'success');

        return '';
    }

    /**
     * The job's step. Never throws. `ran` 1 when a fetch was made, `stored`
     * the number of rates in the file, `error` 1 when it failed (or could not
     * even start), `skipped` 1 when nothing was due or the switch is off.
     * `$testUrl` is for the self-test alone (see the call below).
     *
     * @return array{ran:int, stored:int, error:int, skipped:int}
     */
    public static function refreshIfDue(\mysqli $db, ?string $testUrl = null): array
    {
        $result = ['ran' => 0, 'stored' => 0, 'error' => 0, 'skipped' => 0];
        try {
            if (SettingLimit::storedAtLevel($db, self::FETCH_SETTING, null) !== '1') {
                $result['skipped'] = 1;

                return $result;
            }
            $state = self::fetchState($db);
            if ($state === null) {
                $result['error'] = 1;

                return $result;
            }
            $sinceSuccess = $state['sinceSuccess'];
            $sinceAttempt = $state['sinceAttempt'];
            if ($sinceSuccess !== null && $sinceSuccess < self::FETCH_EVERY_SECONDS) {
                $result['skipped'] = 1;

                return $result;
            }
            // An attempt newer than the last success is a failure still waiting its hour.
            if ($sinceAttempt !== null && $sinceAttempt < self::RETRY_AFTER_SECONDS
                && ($sinceSuccess === null || $sinceAttempt < $sinceSuccess)) {
                $result['skipped'] = 1;

                return $result;
            }
            $result['ran'] = 1;
            // $testUrl is a seam for tools/exchange-rates-selftest.php ONLY: the job
            //    passes nothing, and that test reads every file under web/ to
            //    prove nothing else passes a second argument here.
            $problem = $testUrl === null ? self::refresh($db) : self::refreshFrom($db, $testUrl, false);
            if ($problem !== '') {
                $result['error'] = 1;

                return $result;
            }
            // What the FILE held, as the docblock says: not the table's size.
            $result['stored'] = self::$lastStored;
        } catch (\Throwable $problem) {
            error_log('Exchange rates: the daily fetch stopped: ' . get_class($problem));
            $result['error'] = 1;
        }

        return $result;
    }

    /**
     * Note an attempt, a success or a problem in the one fetch row.
     * Best effort: a failure here never changes the fetch's own answer.
     */
    private static function touchFetchRow(\mysqli $db, string $what, string $word = ''): void
    {
        try {
            if ($what === 'attempt') {
                $sql = 'INSERT INTO `tblExchangeRateFetch` (`fetchID`, `lastAttemptUtc`) VALUES (1, UTC_TIMESTAMP()) '
                    . 'ON DUPLICATE KEY UPDATE `lastAttemptUtc` = UTC_TIMESTAMP()';
                $db->query($sql);
            } elseif ($what === 'success') {
                $sql = 'INSERT INTO `tblExchangeRateFetch` (`fetchID`, `lastAttemptUtc`, `lastSuccessUtc`, `lastProblem`) '
                    . "VALUES (1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), '') "
                    . "ON DUPLICATE KEY UPDATE `lastSuccessUtc` = UTC_TIMESTAMP(), `lastProblem` = ''";
                $db->query($sql);
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO `tblExchangeRateFetch` (`fetchID`, `lastAttemptUtc`, `lastProblem`) VALUES (1, UTC_TIMESTAMP(), ?) '
                    . 'ON DUPLICATE KEY UPDATE `lastProblem` = VALUES(`lastProblem`)'
                );
                $stmt->bind_param('s', $word);
                $stmt->execute();
                $stmt->close();
            }
        } catch (\Throwable $ignored) {
            error_log('Exchange rates: the fetch record could not be written.');
        }
    }

    /**
     * Seconds since the last success and the last attempt, by the database's
     * own UTC clock. Null parts mean "never". Null overall: could not read.
     *
     * @return array{sinceSuccess: ?int, sinceAttempt: ?int, problem: string, lastSuccessUtc: ?string, lastAttemptUtc: ?string}|null
     */
    private static function fetchState(\mysqli $db): ?array
    {
        try {
            $stmt = $db->prepare(
                'SELECT TIMESTAMPDIFF(SECOND, `lastSuccessUtc`, UTC_TIMESTAMP()) AS `ss`, '
                . 'TIMESTAMPDIFF(SECOND, `lastAttemptUtc`, UTC_TIMESTAMP()) AS `sa`, `lastProblem`, '
                . '`lastSuccessUtc`, `lastAttemptUtc` FROM `tblExchangeRateFetch` WHERE `fetchID` = 1'
            );
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (\Throwable $ignored) {
            return null;
        }
        if ($row === null) {
            // The row is seeded; a missing one means "never fetched".
            return ['sinceSuccess' => null, 'sinceAttempt' => null, 'problem' => '', 'lastSuccessUtc' => null, 'lastAttemptUtc' => null];
        }

        return [
            'sinceSuccess'   => $row['ss'] === null ? null : (int) $row['ss'],
            'sinceAttempt'   => $row['sa'] === null ? null : (int) $row['sa'],
            'problem'        => (string) $row['lastProblem'],
            'lastSuccessUtc' => $row['lastSuccessUtc'] === null ? null : (string) $row['lastSuccessUtc'],
            'lastAttemptUtc' => $row['lastAttemptUtc'] === null ? null : (string) $row['lastAttemptUtc'],
        ];
    }

    // 🔎 =========================================================================
    // Choosing a rate
    // =========================================================================

    /**
     * Which rate to use to turn `$from` into `$to`, or null when there is none
     * (the caller then refuses). `$siteId` null means "for the INSTALLATION's
     * limit": an organisation's typed rate is then never used (see the header).
     *
     * `kind` is `same`, `cross` (`a` = what one euro buys of `to`, `b` = what
     * one euro buys of `from`: amount in `to` = amount * a / b) or `direct`
     * (`a` = how many `to` one `from` is). A typed rate given the other way
     * round is turned into a `cross` with `a` = 1, so no division is done here.
     * `source` is `same`, `fetched`, `entered-organisation` or
     * `entered-installation`; `asOf` is the day the rate is from.
     *
     * @return array{kind:string, a:string, b:string, source:string, asOf:string}|null
     */
    public static function rate(\mysqli $db, string $from, string $to, ?int $siteId): ?array
    {
        if (preg_match('/^[A-Z]{3}$/', $from) !== 1 || preg_match('/^[A-Z]{3}$/', $to) !== 1) {
            return null;
        }
        if ($from === $to) {
            return ['kind' => 'same', 'a' => '1', 'b' => '1', 'source' => 'same', 'asOf' => gmdate('Y-m-d')];
        }
        try {
            $held = [];
            $stmt = $db->prepare('SELECT `rateCurrency`, `perEuro`, `rateDate` FROM `tblExchangeRate` WHERE `rateCurrency` IN (?, ?)');
            $stmt->bind_param('ss', $from, $to);
            $stmt->execute();
            $result = $stmt->get_result();
            while (($row = $result->fetch_assoc()) !== null) {
                $held[(string) $row['rateCurrency']] = ['perEuro' => (string) $row['perEuro'], 'date' => (string) $row['rateDate']];
            }
            $stmt->close();
            // The euro is 1 euro, always, and as fresh as today.
            if ($from === 'EUR') {
                $held['EUR'] = ['perEuro' => '1', 'date' => ($held[$to]['date'] ?? gmdate('Y-m-d'))];
            }
            if ($to === 'EUR') {
                $held['EUR'] = ['perEuro' => '1', 'date' => ($held[$from]['date'] ?? gmdate('Y-m-d'))];
            }
            if (isset($held[$from], $held[$to]) === true
                && self::isFresh($held[$from]['date']) === true && self::isFresh($held[$to]['date']) === true) {
                return [
                    'kind'   => 'cross',
                    'a'      => $held[$to]['perEuro'],
                    'b'      => $held[$from]['perEuro'],
                    'source' => 'fetched',
                    'asOf'   => min($held[$from]['date'], $held[$to]['date']),
                ];
            }
            if ($siteId !== null) {
                $own = self::typedRate($db, $siteId, $from, $to);
                if ($own !== null) {
                    $own['source'] = 'entered-organisation';

                    return $own;
                }
            }
            $installation = self::typedRate($db, null, $from, $to);
            if ($installation !== null) {
                $installation['source'] = 'entered-installation';

                return $installation;
            }
        } catch (\Throwable $ignored) {
            // "Could not read" is "no rate": the caller refuses, which is the safe direction.
            return null;
        }

        return null;
    }

    /**
     * One level's typed rate for the pair, either way round.
     *
     * @return array{kind:string, a:string, b:string, source:string, asOf:string}|null
     */
    private static function typedRate(\mysqli $db, ?int $siteId, string $from, string $to): ?array
    {
        $scope = $siteId ?? -1;
        $stmt  = $db->prepare(
            'SELECT `fromCurrency`, `toCurrency`, `rate`, `asOf` FROM `tblExchangeRateEntered` '
            . 'WHERE `siteScope` = ? AND ((`fromCurrency` = ? AND `toCurrency` = ?) OR (`fromCurrency` = ? AND `toCurrency` = ?))'
        );
        $stmt->bind_param('issss', $scope, $from, $to, $to, $from);
        $stmt->execute();
        $result = $stmt->get_result();
        $found  = null;
        while (($row = $result->fetch_assoc()) !== null) {
            if ((string) $row['fromCurrency'] === $from) {
                // The pair as asked beats the pair the other way round.
                $found = ['kind' => 'direct', 'a' => (string) $row['rate'], 'b' => '1', 'source' => '', 'asOf' => (string) $row['asOf']];
                break;
            }
            $found = ['kind' => 'cross', 'a' => '1', 'b' => (string) $row['rate'], 'source' => '', 'asOf' => (string) $row['asOf']];
        }
        $stmt->close();

        return $found;
    }

    // ➗ =========================================================================
    // Converting
    // =========================================================================

    /**
     * Millionths of one currency, in hundredths of another, by the chosen
     * rate — the ONE converter. Rounds UP. Null when it could not be worked
     * out or the answer is not a sane figure; a caller treats null as
     * "over every limit", never as zero.
     *
     * @param array{kind:string, a:string, b:string} $rate from rate()
     */
    public static function toHundredths(\mysqli $db, int $millionths, array $rate): ?int
    {
        if ($millionths < 0) {
            return null;
        }
        if ($rate['kind'] === 'same') {
            // Exact integer arithmetic: a ceiling of millionths / 10,000.
            return intdiv($millionths + 9999, 10000);
        }
        foreach (['a', 'b'] as $part) {
            if (preg_match('/^\d{1,10}(\.\d{1,10})?$/', (string) $rate[$part]) !== 1 || preg_match('/[1-9]/', (string) $rate[$part]) !== 1) {
                return null;
            }
        }
        $amount = (string) $millionths;
        try {
            if ($rate['kind'] === 'cross') {
                $stmt = $db->prepare(
                    'SELECT CAST(CEILING((CAST(? AS DECIMAL(30,0)) * CAST(? AS DECIMAL(30,10))) '
                    . '/ (CAST(? AS DECIMAL(30,10)) * 10000)) AS CHAR) AS `h`'
                );
                $stmt->bind_param('sss', $amount, $rate['a'], $rate['b']);
            } elseif ($rate['kind'] === 'direct') {
                $stmt = $db->prepare(
                    'SELECT CAST(CEILING(CAST(? AS DECIMAL(30,0)) * CAST(? AS DECIMAL(30,10)) / 10000) AS CHAR) AS `h`'
                );
                $stmt->bind_param('ss', $amount, $rate['a']);
            } else {
                return null;
            }
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (\Throwable $ignored) {
            return null;
        }
        $text = (string) ($row['h'] ?? '');
        if (preg_match('/^\d{1,18}$/', $text) !== 1) {
            return null;
        }

        return (int) $text;
    }

    /**
     * The rate written for a person: `1 USD = 0.756046 GBP`, to six places,
     * worked out in the database. Null when it could not be.
     *
     * @param array{kind:string, a:string, b:string} $rate from rate()
     */
    public static function crossRateText(\mysqli $db, string $from, string $to, array $rate): ?string
    {
        if ($rate['kind'] === 'same') {
            return '1 ' . $from . ' = 1 ' . $to;
        }
        try {
            if ($rate['kind'] === 'cross') {
                $stmt = $db->prepare('SELECT CAST(CAST(? AS DECIMAL(30,10)) / CAST(? AS DECIMAL(30,10)) AS DECIMAL(30,6)) AS `t`');
                $stmt->bind_param('ss', $rate['a'], $rate['b']);
            } else {
                $stmt = $db->prepare('SELECT CAST(CAST(? AS DECIMAL(30,10)) AS DECIMAL(30,6)) AS `t`');
                $stmt->bind_param('s', $rate['a']);
            }
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (\Throwable $ignored) {
            return null;
        }
        if (isset($row['t']) === false) {
            return null;
        }

        return '1 ' . $from . ' = ' . (string) $row['t'] . ' ' . $to;
    }

    // ✍️ =========================================================================
    // Typed rates
    // =========================================================================

    /**
     * Save (or replace) a typed rate. '' when saved, else the refusal in words.
     * `$siteId` null is the installation's. Who may call this is the page's
     * business; this judges only the figures.
     */
    public static function saveEntered(\mysqli $db, ?int $siteId, string $from, string $to, string $rateText, string $asOf): string
    {
        $from = strtoupper(trim($from));
        $to   = strtoupper(trim($to));
        if (Currency::isKnown($from) === false || Currency::isKnown($to) === false) {
            return 'Both currencies must be ones the portal knows, written as three capital letters, for example USD.';
        }
        if ($from === $to) {
            return 'The two currencies are the same, so no rate is needed.';
        }
        $rateText = trim($rateText);
        if (preg_match(self::RATE_PATTERN, $rateText) !== 1 || preg_match('/[1-9]/', $rateText) !== 1) {
            return 'The rate has to be a plain number greater than nothing, in figures only, with at most 8 figures before the point and 10 after, for example 0.7560.';
        }
        $asOf = trim($asOf);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $asOf, $parts) !== 1 || checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) === false) {
            return 'The date the rate is from has to be a real date, written like 2026-11-16.';
        }
        // Tomorrow is allowed: a person in a time zone ahead of UTC is already there.
        $tomorrow = gmdate('Y-m-d', time() + 86400);
        if ($asOf > $tomorrow) {
            return 'The date the rate is from cannot be in the future.';
        }
        try {
            $stmt = $db->prepare(
                'INSERT INTO `tblExchangeRateEntered` (`siteID`, `fromCurrency`, `toCurrency`, `rate`, `asOf`, `enteredAtUtc`) '
                . 'VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP()) '
                . 'ON DUPLICATE KEY UPDATE `rate` = VALUES(`rate`), `asOf` = VALUES(`asOf`), `enteredAtUtc` = VALUES(`enteredAtUtc`)'
            );
            $stmt->bind_param('issss', $siteId, $from, $to, $rateText, $asOf);
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $ignored) {
            return 'The rate could not be saved. If the portal\'s files were updated recently, a global administrator may need to run Admin, Upgrade first.';
        }

        return '';
    }

    /** Remove one typed rate. The number of rows removed, or -1 when it could not be done. */
    public static function removeEntered(\mysqli $db, ?int $siteId, int $enteredId): int
    {
        try {
            $scope = $siteId ?? -1;
            $stmt  = $db->prepare('DELETE FROM `tblExchangeRateEntered` WHERE `enteredID` = ? AND `siteScope` = ?');
            $stmt->bind_param('ii', $enteredId, $scope);
            $stmt->execute();
            $gone = $stmt->affected_rows;
            $stmt->close();

            return $gone;
        } catch (\Throwable $ignored) {
            return -1;
        }
    }

    /**
     * The typed rates one level holds. Null when they could not be read.
     *
     * @return list<array{enteredID:int, fromCurrency:string, toCurrency:string, rate:string, asOf:string}>|null
     */
    public static function enteredFor(\mysqli $db, ?int $siteId): ?array
    {
        try {
            $scope = $siteId ?? -1;
            $stmt  = $db->prepare(
                'SELECT `enteredID`, `fromCurrency`, `toCurrency`, `rate`, `asOf` FROM `tblExchangeRateEntered` '
                . 'WHERE `siteScope` = ? ORDER BY `fromCurrency`, `toCurrency`'
            );
            $stmt->bind_param('i', $scope);
            $stmt->execute();
            $rows = [];
            $res  = $stmt->get_result();
            while (($row = $res->fetch_assoc()) !== null) {
                $rows[] = [
                    'enteredID'    => (int) $row['enteredID'],
                    'fromCurrency' => (string) $row['fromCurrency'],
                    'toCurrency'   => (string) $row['toCurrency'],
                    'rate'         => (string) $row['rate'],
                    'asOf'         => (string) $row['asOf'],
                ];
            }
            $stmt->close();

            return $rows;
        } catch (\Throwable $ignored) {
            return null;
        }
    }

    // 📊 =========================================================================
    // For the page
    // =========================================================================

    /**
     * What the page shows about the fetch. Null parts mean "never" or "none".
     *
     * @return array{lastSuccessUtc:?string, lastAttemptUtc:?string, problem:string, rateDate:?string, fresh:bool, count:int, olderRows:int, futureRows:int}|null
     */
    public static function status(\mysqli $db): ?array
    {
        $state = self::fetchState($db);
        if ($state === null) {
            return null;
        }
        try {
            // The newest SANE day: a row dated more than a day ahead (a wrong date that got in
            // by hand) is not used by rate() and must not make every other rate read "too old".
            $row = $db->query(
                'SELECT MAX(CASE WHEN `rateDate` <= UTC_DATE() + INTERVAL 1 DAY THEN `rateDate` END) AS `d`, '
                . 'COALESCE(SUM(`rateDate` > UTC_DATE() + INTERVAL 1 DAY), 0) AS `f` FROM `tblExchangeRate`'
            )->fetch_assoc();
            // The count is of THAT day's list only: a currency the bank stopped publishing keeps an old
            // row, and a row dated ahead is not in any list, so neither is "held" in the sense the page says.
            $inList = 0;
            $older  = 0;
            if ($row['d'] !== null) {
                $stmt = $db->prepare('SELECT COALESCE(SUM(`rateDate` = ?), 0) AS `n`, COALESCE(SUM(`rateDate` < ?), 0) AS `o` FROM `tblExchangeRate`');
                $stmt->bind_param('ss', $row['d'], $row['d']);
                $stmt->execute();
                $counts = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $inList = (int) $counts['n'];
                $older  = (int) $counts['o'];
            }
        } catch (\Throwable $ignored) {
            return null;
        }
        $date = $row['d'] === null ? null : (string) $row['d'];

        return [
            'lastSuccessUtc' => $state['lastSuccessUtc'],
            'lastAttemptUtc' => $state['lastAttemptUtc'],
            'problem'        => $state['problem'],
            'rateDate'       => $date,
            'fresh'          => $date !== null && self::isFresh($date),
            'count'          => $inList,
            'olderRows'      => $older,
            'futureRows'     => (int) $row['f'],
        ];
    }

    /** A fixed word for the last problem, in a sentence for a person. */
    public static function problemWords(string $word): string
    {
        return match ($word) {
            ''            => '',
            'http'        => 'the bank\'s website answered with an error',
            'capped'      => 'the reply was far bigger than the real file, so it was refused',
            'unreadable'  => 'the reply could not be read as the bank\'s list of rates',
            'no-xml'      => 'this server\'s PHP cannot read XML files',
            'refused'     => 'the address could not be used: its name could not be found, or it points at a private or internal network',
            'future-date' => 'the file was dated more than a day in the future, so it was not used',
            'not-saved'   => 'the rates were fetched but could not be saved in the database',
            'test-blocked' => 'a test database may not contact the real bank',
            'unreachable' => 'the bank\'s website could not be reached',
            default       => 'an unknown problem',
        };
    }
}
