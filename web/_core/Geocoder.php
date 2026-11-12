<?php
// Path: _core/Geocoder.php
/**
 * -----------------------------------------------------------------------------
 * Geocoder — Google primary, Nominatim/OSM fallback 🌍 (#456 Chunk A)
 * -----------------------------------------------------------------------------
 * Static class, same structural shape as every other outbound-HTTP adapter
 * in this codebase (`CloudflareStream`, `What3Words`): no SSL overrides,
 * `CURLOPT_FOLLOWLOCATION => false`, short timeouts, JSON decode, never
 * throws. ALL methods are best-effort — they return null on any failure
 * (missing key, transport error, non-OK status, zero results) so a failed
 * or slow geocode NEVER blocks a save (contract §4).
 *
 * Provider chain: Google (when `geo.google.apiKey` is set) -> Nominatim
 * fallback. `geo.autoGeocode` defaults OFF (contract §4) — no silent
 * network I/O appears on upgrade; save handlers consult `autoEnabled()`
 * before auto-geocoding, but the manual `/geo/lookup` action always runs
 * (explicit user intent).
 *
 * NOMINATIM POLICY COMPLIANCE (mandatory, built in from first commit):
 *   1. Descriptive User-Agent — productName/version (admin contact).
 *   2. Spacing — the portal waits about a second between its own calls, using a last-call
 *      timestamp in the `geo.nominatim.lastCallAt` setting row and
 *      `usleep()`ing the remainder, hard-capped at 1.2s. This is NOT a
 *      guarantee of one request a second: there is no lock, so two requests
 *      arriving together both read the old timestamp and both call within
 *      milliseconds, and a failed read of the timestamp skips the wait.
 *   3. Cache — `tblGeocodeCache`, so the same address limited to the same
 *      country (#1035) is normally looked up once. Only usable answers are
 *      kept (#1034), so an address with no usable answer is asked again,
 *      and "Test connection" deliberately skips READING the cache (it still
 *      writes the fresh answer it gets, which is a real answer).
 *   4. Attribution — respected at the display layer (map_attribution copy).
 *
 * No SSRF guard needed: both hosts are `const`, never caller-supplied.
 *
 * Public methods:
 *   Geocoder::forward($address, $countryCode = null)  -> ?array{lat,lng,formatted,source}
 *   Geocoder::reverse($lat, $lng)                      -> ?array{formatted,source}
 *     (reverse() has NEVER had a caller: `git log -S"Geocoder::reverse" e3f8cbc1 -- web/`
 *      shows only 3694c693, which added it; 44970579 and later add the name
 *      only in comments like this one. No page turns coordinates into an
 *      address, and none may say it does.)
 *   Geocoder::autoEnabled()                            -> bool
 *   Geocoder::testConnection()                         -> array{success,message}
 *
 * ONE SHAPE FOR A FORWARD ANSWER, BUILT IN ONE PLACE (#1034, 12 November 2026).
 * A forward answer is built ONLY by `answer()`, whichever way it arrives — the
 * Google leg, the Nominatim leg, or the cache — so a fresh answer and a cached
 * one cannot differ in shape. `answer()` returns null for anything that is not
 * a real position, and every caller already treats null as "no answer".
 *
 * What #1033 claimed, and what was measured instead. #1033 said a FRESH
 * answer carried only `formatted` and `source`, so the first live look-up
 * stored an empty position. That is not so, and #1033 was closed: driven
 * against a stand-in for each provider, a fresh answer from both legs carried
 * `lat` and `lng`, and a venue saved with automatic look-up on stored it. The
 * shape #1033 described is `reverse()`'s, a different method.
 * What WAS wrong (#1034), measured over HTTP with `tools/geocoder-selftest.php`
 * on the code before this change: the provider's coordinates were turned into
 * numbers with `(float)` and never checked. A `lat` that was empty or not a
 * number became 0,0 — a real place, in the Gulf of Guinea — and a saved venue
 * stored 0,0; a latitude of 95 was stored as given; `1e999` became infinity,
 * which `/geo/lookup` could not even encode as JSON. Each was also written to
 * the cache, which is asked first for ever after, and a bad Google answer did
 * not fall through to Nominatim because it was not null. A cache row with no
 * coordinates (only possible by hand) came back as 0,0 too. All now come back
 * as "no answer". (The event, organisation and small-group savers take the
 * same answer as the venue; they were read, not driven.)
 *
 * The forward cache key also carries the country a look-up is limited to
 * (#1035); see forwardKeyText(), which says what that did to old rows.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.5.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/456
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Geocoder
{
    /** @var string Google Geocoding API. */
    private const GOOGLE_BASE = 'https://maps.googleapis.com/maps/api/geocode/json';

    /** @var string Nominatim/OSM base — /search and /reverse appended per call. */
    private const NOMINATIM_BASE = 'https://nominatim.openstreetmap.org';

    /** @var int Minimum microseconds between Nominatim calls (>=1.1s -- policy is <=1 rps). */
    private const NOMINATIM_MIN_INTERVAL_US = 1100000;

    /** @var int Hard cap on the throttle sleep, in microseconds (1.2s). */
    private const NOMINATIM_MAX_SLEEP_US = 1200000;

    /* ====================================================================== */
    /* Public surface                                                         */
    /* ====================================================================== */

    /**
     * Address -> coordinates. Cache-first, then Google (if configured),
     * falling back to Nominatim. Best-effort — null on any failure.
     *
     * @return array{lat: float, lng: float, formatted: ?string, source: string}|null
     */
    public static function forward(string $address, ?string $countryCode = null): ?array
    {
        return self::lookUp($address, $countryCode, true);
    }

    /**
     * The forward look-up itself. `$readCache` is false ONLY for
     * testConnection(): a connection test answered from the cache reports
     * "Connected" without contacting any provider, which is what it did until
     * #1034 round 3 (12 November 2026) — after the first success, every press
     * of "Test connection" was answered from the cache, so a broken key or a
     * blocked network still read as connected. A fresh answer is still WRITTEN
     * to the cache: it is a real answer for that address.
     *
     * @return array{lat: float, lng: float, formatted: ?string, source: string}|null
     */
    private static function lookUp(string $address, ?string $countryCode, bool $readCache): ?array
    {
        $address = trim($address);
        if ($address === '') {
            return null;
        }

        // 🌍 The country the look-up is limited to is PART OF THE KEY (#1035).
        //    It used to be left out, so "Springfield" limited to the US and
        //    "Springfield" limited to Great Britain shared one cached answer,
        //    and whichever was asked second was given the first one's place —
        //    for ever, because the cache is asked first. See forwardKeyText().
        $hash = self::cacheKey('forward', self::forwardKeyText($address, $countryCode));
        if ($readCache === true) {
            $cached = self::cacheGet($hash);
            if ($cached !== null) {
                return $cached;
            }
        }

        $result = null;
        if ((string) Settings::get('geo.google.apiKey', '') !== '') {
            $result = self::google('forward', ['address' => $address]);
        }
        if ($result === null) {
            $result = self::nominatim('forward', ['q' => $address, 'countrycodes' => $countryCode]);
        }
        if ($result === null) {
            return null;
        }

        // 📐 Both legs built `$result` through answer(), which has already
        //    checked and rounded the position, so there is nothing to tidy here
        //    and nothing unchecked reaches the cache.
        self::cachePut($hash, 'forward', $address, $result['lat'], $result['lng'], $result['formatted'], $result['source']);
        return $result;
    }

    /**
     * Coordinates -> display address. Same cache + provider-chain shape as
     * `forward()`.
     *
     * @return array{formatted: string, source: string}|null
     */
    public static function reverse(float $lat, float $lng): ?array
    {
        $coords = GeoLocation::validateCoords($lat, $lng);
        if ($coords === null) {
            return null;
        }
        $queryText = $coords['lat'] . ',' . $coords['lng'];

        $hash = self::cacheKey('reverse', $queryText);
        $cached = self::cacheGet($hash);
        if ($cached !== null && $cached['formatted'] !== null) {
            return ['formatted' => $cached['formatted'], 'source' => $cached['source']];
        }

        $result = null;
        if ((string) Settings::get('geo.google.apiKey', '') !== '') {
            $result = self::google('reverse', ['latlng' => $queryText]);
        }
        if ($result === null) {
            $result = self::nominatim('reverse', ['lat' => (string) $coords['lat'], 'lon' => (string) $coords['lng']]);
        }
        if ($result === null || $result['formatted'] === null) {
            return null;
        }

        self::cachePut($hash, 'reverse', $queryText, $coords['lat'], $coords['lng'], $result['formatted'], $result['source']);
        return ['formatted' => $result['formatted'], 'source' => $result['source']];
    }

    /** Save handlers consult this before auto-geocoding; default OFF (contract §4). */
    public static function autoEnabled(): bool
    {
        return (string) Settings::get('geo.autoGeocode', 'false') === 'true';
    }

    /**
     * Admin "Test connection" — looks up a fixed test address with the
     * providers themselves, never the cache, and reports which provider
     * answered. Never echoes provider error text.
     *
     * It skips READING the cache on purpose (#1034 round 3), and still writes
     * the fresh answer it gets: through forward() it was
     * answered from the cache after the first success, so it reported
     * "Connected" without contacting anybody.
     *
     * @return array{success: bool, message: string}
     */
    public static function testConnection(): array
    {
        $result = self::lookUp('Westminster, London, United Kingdom', null, false);
        if ($result !== null) {
            return [
                'success' => true,
                'message' => 'Connected — geocoded the test address via ' . $result['source'] . '.',
            ];
        }
        return [
            'success' => false,
            'message' => 'Could not geocode the test address — check connectivity (and the Google key, if set).',
        ];
    }

    /* ====================================================================== */
    /* Google leg                                                             */
    /* ====================================================================== */

    /**
     * @param array<string, string> $query
     *
     * @return array{lat: float, lng: float, formatted: ?string, source: string}|null
     */
    private static function google(string $mode, array $query): ?array
    {
        $key = (string) Settings::get('geo.google.apiKey', '');
        if ($key === '') {
            return null;
        }

        // 🔒 Key added ONLY via http_build_query (encoded once) — NEVER
        //    logged, NEVER in an exception/return value. Same key-leak
        //    discipline as What3Words::request().
        $url = self::GOOGLE_BASE . '?' . http_build_query($query + ['key' => $key]);

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

        $raw      = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrNo = curl_errno($ch);
        // No curl_close() here: the handle has been an object since PHP 8.0 and is
        // freed when it goes out of scope. The call has done nothing since then,
        // and PHP 8.5 raises a "deprecated" notice every time it runs (#673).

        if ($raw === false) {
            Logger::errorPlatform(
                'Geocoder',
                'Warning',
                'GEO_GOOGLE_TRANSPORT',
                'Google Geocoding API transport failure',
                'mode=' . $mode . ' httpCode=' . (string) $httpCode . ' curlErrno=' . (string) $curlErrNo
            );
            return null;
        }

        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded) === false) {
            return null;
        }

        $status = (string) ($decoded['status'] ?? '');
        if ($status !== 'OK' || empty($decoded['results']) === true || is_array($decoded['results']) === false) {
            if ($status !== 'ZERO_RESULTS') {
                Logger::errorPlatform(
                    'Geocoder',
                    'Warning',
                    'GEO_GOOGLE_STATUS',
                    'Google Geocoding API non-OK status',
                    'mode=' . $mode . ' httpCode=' . (string) $httpCode . ' status=' . $status
                );
            }
            return null;
        }

        $first = $decoded['results'][0];
        $formatted = (string) ($first['formatted_address'] ?? '');

        if ($mode === 'reverse') {
            return ['lat' => 0.0, 'lng' => 0.0, 'formatted' => $formatted !== '' ? $formatted : null, 'source' => 'google'];
        }

        $location = $first['geometry']['location'] ?? null;
        if (is_array($location) === false || isset($location['lat']) === false || isset($location['lng']) === false) {
            return null;
        }

        // 📐 answer() judges the position; it used to be `(float)` here, which
        //    turned anything that was not a number into 0,0 (#1034).
        return self::answer($location['lat'], $location['lng'], $formatted !== '' ? $formatted : null, 'google');
    }

    /* ====================================================================== */
    /* Nominatim leg (policy-compliant — see class docblock)                  */
    /* ====================================================================== */

    /**
     * @param array<string, string|null> $query
     *
     * @return array{lat: float, lng: float, formatted: ?string, source: string}|null
     */
    private static function nominatim(string $mode, array $query): ?array
    {
        self::throttle();

        $path = $mode === 'reverse' ? '/reverse' : '/search';
        $params = array_filter($query, static fn ($v) => $v !== null && $v !== '');
        if ($mode === 'reverse') {
            $params['format'] = 'jsonv2';
        } else {
            $params['format']         = 'jsonv2';
            $params['limit']          = '1';
            $params['addressdetails'] = '0';
        }

        $url = self::NOMINATIM_BASE . $path . '?' . http_build_query($params);

        $contact = (string) Settings::get('privacy.contactEmail', '');
        if ($contact === '') {
            $contact = (string) Settings::get('mail.defaultFromAddress', '');
        }
        if ($contact === '') {
            $contact = 'admin contact unset';
        }
        $userAgent = Site::productName() . '/' . PORTAL_VERSION . ' (' . $contact . ')';

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);

        $raw      = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrNo = curl_errno($ch);

        if ($raw === false) {
            Logger::errorPlatform(
                'Geocoder',
                'Warning',
                'GEO_NOMINATIM_TRANSPORT',
                'Nominatim transport failure',
                'mode=' . $mode . ' httpCode=' . (string) $httpCode . ' curlErrno=' . (string) $curlErrNo
            );
            return null;
        }

        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded) === false || $httpCode < 200 || $httpCode >= 300) {
            return null;
        }

        if ($mode === 'reverse') {
            $formatted = (string) ($decoded['display_name'] ?? '');
            return $formatted !== '' ? ['lat' => 0.0, 'lng' => 0.0, 'formatted' => $formatted, 'source' => 'nominatim'] : null;
        }

        // 🔍 /search returns a JSON array of results — take the first.
        $first = is_array($decoded) && array_is_list($decoded) ? ($decoded[0] ?? null) : null;
        if (is_array($first) === false || isset($first['lat']) === false || isset($first['lon']) === false) {
            return null;
        }

        // 📐 Nominatim sends its numbers as TEXT ("52.2053"), so `(float)` here
        //    turned an empty or garbled field into 0,0 (#1034). answer() asks
        //    whether it is a number at all before it asks whether it is in range.
        return self::answer(
            $first['lat'],
            $first['lon'],
            isset($first['display_name']) === true ? (string) $first['display_name'] : null,
            'nominatim'
        );
    }

    /**
     * 📐 The ONE place a forward answer is built (#1034) — from either
     * provider or from the cache — so the three cannot differ in shape.
     *
     * Returns null, meaning "no answer", unless both coordinates are numbers
     * (an int, a float, or numeric text) and on the Earth: latitude
     * -90 to 90, longitude -180 to 180. Exactly 0,0 IS accepted: it is a real
     * place, and refusing it would be a rule about one point rather than about
     * what a position is. What is refused is anything that only BECOMES 0,0 by
     * being forced into a number — which is how the old `(float)` produced it.
     *
     * Why not `GeoLocation::validateCoords()`: that casts with `(float)` before
     * it checks the range, so `'abc'` passes it as 0. It is right for what a
     * person typed into a form, where an empty box is caught first; it is not
     * enough for text a third party's server sent.
     *
     * @return array{lat: float, lng: float, formatted: ?string, source: string}|null
     */
    private static function answer(mixed $lat, mixed $lng, ?string $formatted, string $source): ?array
    {
        foreach ([$lat, $lng] as $part) {
            // `is_numeric()` is true for an int, a float, or text that reads
            // as a number apart from spaces around it (PHP 8 allows leading
            // AND trailing whitespace, so ' 52.2 ' passes and `(float)` reads
            // it as 52.2, which is what was meant); false for '', null, 'abc',
            // '52abc', an array or a bool. Both coordinates are asked, each in
            // its own right: a good latitude never vouches for the longitude.
            if (is_numeric($part) === false) {
                return null;
            }
        }
        $latF = (float) $lat;
        $lngF = (float) $lng;
        // '1e999' is numeric text and becomes INF, which this range test
        // refuses (INF > 90). A separate is_finite() test was written first
        // and deleted: planted out, the self-test stayed green, because NaN —
        // the one value a range test lets through — cannot come from numeric
        // text or from JSON, and a guard nothing can tell from its absence is
        // not a guard.
        if ($latF < -90.0 || $latF > 90.0 || $lngF < -180.0 || $lngF > 180.0) {
            return null;
        }

        return [
            'lat'       => round($latF, 7),
            'lng'       => round($lngF, 7),
            'formatted' => $formatted,
            'source'    => $source,
        ];
    }

    /**
     * Space Nominatim calls about a second apart (its policy asks for at
     * most one a second) using a persisted last-call timestamp
     * (`geo.nominatim.lastCallAt`, global setting). It cannot GUARANTEE that:
     * nothing locks the row between the read and the write, so two requests
     * at once both see the old timestamp and both call. Best-effort on
     * read/write failure — proceed without sleeping rather than fatal.
     * Sleep is hard-capped at 1.2s (bounded — at most ONE geocode call
     * happens per request, so this never compounds).
     */
    private static function throttle(): void
    {
        try {
            $db = App::db();
            $stmt = $db->prepare("SELECT settingValue FROM tblSettings WHERE settingKey = 'geo.nominatim.lastCallAt' AND siteID IS NULL LIMIT 1");
            $last = null;
            if ($stmt !== false) {
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $last = ($row !== null && (string) $row['settingValue'] !== '') ? (float) $row['settingValue'] : null;
            }

            if ($last !== null) {
                $elapsedUs = (microtime(true) - $last) * 1_000_000;
                $remainingUs = self::NOMINATIM_MIN_INTERVAL_US - $elapsedUs;
                if ($remainingUs > 0) {
                    usleep((int) min($remainingUs, self::NOMINATIM_MAX_SLEEP_US));
                }
            }

            $now = (string) microtime(true);
            $upd = $db->prepare("UPDATE tblSettings SET settingValue = ? WHERE settingKey = 'geo.nominatim.lastCallAt' AND siteID IS NULL");
            if ($upd !== false) {
                $upd->bind_param('s', $now);
                $upd->execute();
                $upd->close();
            }
        } catch (\Throwable $e) {
            // 🛡️ Best-effort — proceed without throttling rather than fatal.
            error_log('Geocoder::throttle() failed: ' . $e->getMessage());
        }
    }

    /* ====================================================================== */
    /* Cache (tblGeocodeCache — read/write ONLY here)                         */
    /* ====================================================================== */

    private static function cacheKey(string $direction, string $queryText): string
    {
        return hash('sha256', $direction . '|' . mb_strtolower(trim($queryText)));
    }

    /**
     * 🌍 What a FORWARD look-up's cache key is made from (#1035): the country
     * it was limited to and the address, as a two-item JSON list —
     * `["gb","1 high street"]`, or `["","1 high street"]` when it was not
     * limited to any country.
     *
     * Nothing is tidied here. `cacheKey()` lower-cases the whole text, so
     * `GB` and `gb` already share one row (Nominatim reads its `countrycodes`
     * the same whatever the case). `JSON_UNESCAPED_UNICODE` keeps `É` as a
     * letter rather than an escape, so that lower-casing folds it to `é` as it
     * always did. A version that ALSO trimmed and lower-cased the country here
     * was written first and taken out: planted back to the untidied form, the
     * self-test stayed green, because `/geo/lookup` trims what it is sent and
     * `cacheKey()` does the rest — a step nothing can tell from its absence.
     * The country is not otherwise judged: the look-up is sent exactly as
     * before, and what was asked is what the row is filed under.
     *
     * WHY A JSON LIST AND NOT `country|address`. With a plain separator a
     * country of `x|y` and an address `a` would give the same text as a
     * country of `x` and an address `y|a` — two different questions sent to
     * the provider, one cached answer. A JSON list cannot be read two ways.
     *
     * WHAT THIS DID TO ROWS ALREADY CACHED. Every forward row written before
     * #1035 was filed under `"<address>"` alone, and a key built here equals
     * one of those only if somebody once typed an address that was itself
     * such a JSON list, so every old forward row becomes a MISS: the address
     * is asked of the provider once more and filed under its new key. That
     * was chosen over keeping the old rows for look-ups with no country,
     * because an old row cannot say whether it was written by a limited
     * look-up — keeping them would keep exactly the wrong answers #1035 is
     * about. The old rows are not deleted (nothing here removes cache rows);
     * they stay in the table unused. Reverse rows are untouched. No
     * installation existed when this changed (D-135), so in practice there
     * were no such rows.
     *
     * The column comment on `tblGeocodeCache.queryHash` ("sha256(direction |
     * lowercased query text)") still reads true if "query text" is taken to
     * be this text; `queryText` itself still stores the address alone.
     */
    private static function forwardKeyText(string $address, ?string $countryCode): string
    {
        $country = $countryCode ?? '';
        $text = json_encode(
            [$country, $address],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        // json_encode() answers false only for text it cannot encode, which the
        // SUBSTITUTE flag rules out; this keeps the key well defined if not.
        return $text !== false ? $text : '["' . bin2hex($country) . '","' . bin2hex($address) . '"]';
    }

    /**
     * @return array{lat: float, lng: float, formatted: ?string, source: string}|null
     */
    private static function cacheGet(string $hash): ?array
    {
        try {
            $db = App::db();
            $stmt = $db->prepare('SELECT latitude, longitude, formatted, provider FROM tblGeocodeCache WHERE queryHash = ? LIMIT 1');
            if ($stmt === false) {
                return null;
            }
            $stmt->bind_param('s', $hash);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row === null) {
                return null;
            }

            // 📐 Built by answer(), the same as a fresh answer (#1034). A row
            //    with no coordinates used to come back as 0,0; it now reads as
            //    a miss, so the provider is asked again and the row is
            //    overwritten by cachePut()'s ON DUPLICATE KEY UPDATE. The same
            //    is true of a row written before #1034 holding a position off
            //    the Earth. reverse() also reads here; the rows it writes carry
            //    the coordinates it was asked about, already checked, so it is
            //    unaffected.
            $answer = self::answer(
                $row['latitude'],
                $row['longitude'],
                $row['formatted'] !== null ? (string) $row['formatted'] : null,
                (string) $row['provider']
            );
            if ($answer === null) {
                // Not counted as a hit: the row was found but not USED. The
                // count was taken before this test in the first version of
                // #1034, which counted a row nobody could use as a hit.
                return null;
            }

            $upd = $db->prepare('UPDATE tblGeocodeCache SET hitCount = hitCount + 1, lastUsedAt = NOW() WHERE queryHash = ?');
            if ($upd !== false) {
                $upd->bind_param('s', $hash);
                $upd->execute();
                $upd->close();
            }

            return $answer;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function cachePut(
        string $hash,
        string $direction,
        string $queryText,
        ?float $lat,
        ?float $lng,
        ?string $formatted,
        string $provider
    ): void {
        try {
            $db = App::db();
            $queryText = mb_substr($queryText, 0, 500);
            $formatted = $formatted !== null ? mb_substr($formatted, 0, 500) : null;
            $stmt = $db->prepare(
                'INSERT INTO tblGeocodeCache (queryHash, direction, queryText, latitude, longitude, formatted, provider) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE latitude = VALUES(latitude), longitude = VALUES(longitude), '
                . 'formatted = VALUES(formatted), provider = VALUES(provider), hitCount = hitCount + 1, lastUsedAt = NOW()'
            );
            if ($stmt === false) {
                return;
            }
            $stmt->bind_param('sssddss', $hash, $direction, $queryText, $lat, $lng, $formatted, $provider);
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('Geocoder::cachePut() failed: ' . $e->getMessage());
        }
    }
}
