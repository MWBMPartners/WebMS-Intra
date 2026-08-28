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
 *   2. Throttle <= 1 request/second — persisted last-call timestamp in the
 *      `geo.nominatim.lastCallAt` setting row, `usleep()`s the remainder,
 *      hard-capped at 1.2s (bounded — at most ONE geocode per request).
 *   3. Cache-once — `tblGeocodeCache` so a given address/coordinate hits
 *      Nominatim at most once, ever (only successes are cached).
 *   4. Attribution — respected at the display layer (map_attribution copy).
 *
 * No SSRF guard needed: both hosts are `const`, never caller-supplied.
 *
 * Public methods:
 *   Geocoder::forward($address, $countryCode = null)  -> ?array{lat,lng,formatted,source}
 *   Geocoder::reverse($lat, $lng)                      -> ?array{formatted,source}
 *   Geocoder::autoEnabled()                            -> bool
 *   Geocoder::testConnection()                         -> array{success,message}
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
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
        $address = trim($address);
        if ($address === '') {
            return null;
        }

        $hash = self::cacheKey('forward', $address);
        $cached = self::cacheGet($hash);
        if ($cached !== null) {
            return $cached;
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

        $result['lat'] = round($result['lat'], 7);
        $result['lng'] = round($result['lng'], 7);
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
     * Admin "Test connection" — geocodes a fixed test address through the
     * live provider chain and reports which provider answered. Never
     * echoes provider error text.
     *
     * @return array{success: bool, message: string}
     */
    public static function testConnection(): array
    {
        $result = self::forward('Westminster, London, United Kingdom');
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
        curl_close($ch);

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

        return [
            'lat'       => (float) $location['lat'],
            'lng'       => (float) $location['lng'],
            'formatted' => $formatted !== '' ? $formatted : null,
            'source'    => 'google',
        ];
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
        curl_close($ch);

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

        return [
            'lat'       => (float) $first['lat'],
            'lng'       => (float) $first['lon'],
            'formatted' => isset($first['display_name']) === true ? (string) $first['display_name'] : null,
            'source'    => 'nominatim',
        ];
    }

    /**
     * Enforce Nominatim's <=1 req/sec policy using a persisted last-call
     * timestamp (`geo.nominatim.lastCallAt`, global setting). Best-effort
     * on read/write failure — proceed without sleeping rather than fatal.
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

            $upd = $db->prepare('UPDATE tblGeocodeCache SET hitCount = hitCount + 1, lastUsedAt = NOW() WHERE queryHash = ?');
            if ($upd !== false) {
                $upd->bind_param('s', $hash);
                $upd->execute();
                $upd->close();
            }

            return [
                'lat'       => $row['latitude'] !== null ? round((float) $row['latitude'], 7) : 0.0,
                'lng'       => $row['longitude'] !== null ? round((float) $row['longitude'], 7) : 0.0,
                'formatted' => $row['formatted'] !== null ? (string) $row['formatted'] : null,
                'source'    => (string) $row['provider'],
            ];
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
