<?php
// Path: _core/What3Words.php
/**
 * -----------------------------------------------------------------------------
 * What3Words API client 🗺️🔤 (#456 Chunk A)
 * -----------------------------------------------------------------------------
 * Near line-for-line clone of `Portal\Core\CloudflareStream` (same section
 * banners, same docblock discipline, `curl_init` + `CURLOPT_RETURNTRANSFER`
 * + `CURLOPT_TIMEOUT`, JSON decode, null/[]/false on any failure — never
 * throws). Default OFF (`w3w.enabled = 'false'`) — every public method
 * short-circuits when not configured, so a typed what3words value is
 * ALWAYS a valid stored-field fallback whether or not this adapter is on
 * (locked decision 1 / contract §3).
 *
 * CRITICAL deviation from every other Bearer-style adapter in this
 * codebase: What3Words' v3 API takes the API key as a QUERY-STRING
 * parameter (`?key=…`), never a header. `request()` is therefore the ONE
 * place the key is read, and it is appended via `http_build_query()`
 * (which rawurlencodes every value) so it is never concatenated raw and
 * never appears un-encoded in a log line.
 *
 * KEY-LEAK RULE (enforced in review — grep-audited per #456 spec §A15.4):
 * the `$url` local variable (which embeds the key) NEVER appears in a
 * `Logger::errorPlatform()` call, an exception message, or any return
 * value. Every failure log line carries ONLY `path=… httpCode=…` (+
 * `curlErrno=…` on transport failure) — never the provider's own error
 * body text (it may echo request params back).
 *
 * Public methods:
 *   What3Words::isConfigured()                              -> bool
 *   What3Words::convertTo3wa($lat, $lng)                     -> ?string
 *   What3Words::convertToCoordinates($w3w)                   -> ?array{lat,lng}
 *   What3Words::autosuggest($partial, $focus = null)         -> list<array{words,nearestPlace,country}>
 *   What3Words::testConnection()                             -> array{success,message}
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

class What3Words
{
    /** @var string what3words v3 REST API — fixed host, no SSRF guard needed (house convention). */
    private const API_BASE = 'https://api.what3words.com/v3/';

    /** @var string Unicode-aware what3words shape (full three-word form). */
    private const W3W_RE = '/^\p{L}+\.\p{L}+\.\p{L}+$/u';

    /** @var string Partial shape accepted by autosuggest() — 2 full words + a started 3rd. */
    private const W3W_PARTIAL_RE = '/^\p{L}+\.\p{L}+\.\p{L}*$/u';

    /** @var int Max autosuggest results returned to the caller. */
    private const MAX_SUGGESTIONS = 5;

    /* ====================================================================== */
    /* Configuration                                                          */
    /* ====================================================================== */

    /**
     * True iff `w3w.enabled = 'true'` AND `w3w.apiKey` is non-empty. Every
     * other public method short-circuits to null/[]/failure when this is
     * false — callers never need to check it separately before calling,
     * it's exposed for UI gating (autosuggest/validation affordances).
     */
    public static function isConfigured(): bool
    {
        return (string) Settings::get('w3w.enabled', 'false') === 'true'
            && (string) Settings::get('w3w.apiKey', '') !== '';
    }

    /* ====================================================================== */
    /* Coordinates -> words                                                   */
    /* ====================================================================== */

    /**
     * Convert a coordinate pair to its what3words address. Validates the
     * WGS84 range first (never sends an out-of-range pair over the wire).
     *
     * @return string|null Canonical `word.word.word`, or null on any failure
     */
    public static function convertTo3wa(float $lat, float $lng): ?string
    {
        if (self::isConfigured() === false) {
            return null;
        }
        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return null;
        }

        $resp = self::request('convert-to-3wa', ['coordinates' => $lat . ',' . $lng]);
        if ($resp['success'] !== true || is_array($resp['result']) === false) {
            return null;
        }

        $words = (string) ($resp['result']['words'] ?? '');
        return preg_match(self::W3W_RE, $words) === 1 ? $words : null;
    }

    /* ====================================================================== */
    /* Words -> coordinates                                                   */
    /* ====================================================================== */

    /**
     * Convert a what3words address to a coordinate pair. Normalises via
     * `GeoLocation::validateW3W()` first (pure string work, no network) —
     * invalid input short-circuits to null without any HTTP call.
     *
     * @return array{lat: float, lng: float}|null
     */
    public static function convertToCoordinates(string $w3w): ?array
    {
        $normalised = GeoLocation::validateW3W($w3w);
        if ($normalised === null || self::isConfigured() === false) {
            return null;
        }

        $resp = self::request('convert-to-coordinates', ['words' => $normalised]);
        if ($resp['success'] !== true || is_array($resp['result']) === false) {
            return null;
        }

        $coords = $resp['result']['coordinates'] ?? null;
        if (is_array($coords) === false || isset($coords['lat']) === false || isset($coords['lng']) === false) {
            return null;
        }

        return ['lat' => round((float) $coords['lat'], 7), 'lng' => round((float) $coords['lng'], 7)];
    }

    /* ====================================================================== */
    /* Autosuggest (server-proxied — browser NEVER sees the key)             */
    /* ====================================================================== */

    /**
     * Server-side autosuggest proxy for the W3W input's datalist. Requires
     * at least two full words + a started third before calling out (W3W's
     * own minimum shape) — anything shorter returns `[]` with no HTTP call.
     *
     * @param string                          $partial Partial what3words input, e.g. "filled.count.so"
     * @param array{lat: float, lng: float}|null $focus  Optional focus coordinate to bias results
     *
     * @return list<array{words: string, nearestPlace: string, country: string}> Max 5, [] on any failure
     */
    public static function autosuggest(string $partial, ?array $focus = null): array
    {
        if (self::isConfigured() === false) {
            return [];
        }

        $partial = mb_substr(trim($partial), 0, 100);
        if (preg_match(self::W3W_PARTIAL_RE, $partial) !== 1) {
            return [];
        }

        $query = ['input' => $partial];
        if ($focus !== null) {
            $focusCoords = GeoLocation::validateCoords($focus['lat'] ?? null, $focus['lng'] ?? null);
            if ($focusCoords !== null) {
                $query['focus'] = $focusCoords['lat'] . ',' . $focusCoords['lng'];
            }
        }

        $resp = self::request('autosuggest', $query);
        if ($resp['success'] !== true || is_array($resp['result']) === false) {
            return [];
        }

        $suggestions = $resp['result']['suggestions'] ?? [];
        if (is_array($suggestions) === false) {
            return [];
        }

        $out = [];
        foreach ($suggestions as $s) {
            if (is_array($s) === false || count($out) >= self::MAX_SUGGESTIONS) {
                break;
            }
            $words = (string) ($s['words'] ?? '');
            if (preg_match(self::W3W_RE, $words) !== 1) {
                continue;
            }
            $out[] = [
                'words'        => $words,
                'nearestPlace' => (string) ($s['nearestPlace'] ?? ''),
                'country'      => (string) ($s['country'] ?? ''),
            ];
        }
        return $out;
    }

    /* ====================================================================== */
    /* Connectivity check                                                     */
    /* ====================================================================== */

    /**
     * Admin "Test connection" — mirrors `CloudflareStream::testConnection()`
     * 251-287. Calls `convert-to-3wa` on What3Words' own documentation
     * example square. Machine-safe messages keyed off `httpCode` ONLY —
     * never provider text, never the key.
     *
     * @return array{success: bool, message: string}
     */
    public static function testConnection(): array
    {
        if (self::isConfigured() === false) {
            return [
                'success' => false,
                'message' => 'Enable the integration and set an API key before testing.',
            ];
        }

        $resp = self::request('convert-to-3wa', ['coordinates' => '51.520847,-0.195521']);

        if ($resp['success'] === true) {
            return ['success' => true, 'message' => 'Connected — what3words accepted the API key.'];
        }

        return match ($resp['httpCode']) {
            401 => ['success' => false, 'message' => 'what3words rejected the API key — check the key value.'],
            402 => ['success' => false, 'message' => 'The what3words API key is over its quota — check your plan.'],
            400 => ['success' => false, 'message' => 'what3words rejected the request — check the integration configuration.'],
            default => ['success' => false, 'message' => 'Could not reach what3words — check network connectivity and try again.'],
        };
    }

    /* ====================================================================== */
    /* Internals                                                              */
    /* ====================================================================== */

    /**
     * Shared cURL request/response handling. The ONE place `w3w.apiKey` is
     * read. CRITICAL: the key goes in the QUERY STRING (What3Words' own
     * auth shape), added via `http_build_query()` so it is rawurlencoded
     * exactly once and never concatenated raw. TLS verification left at
     * cURL's compiled-in defaults (house convention — no
     * CURLOPT_SSL_VERIFY* override anywhere in this class).
     *
     * @param array<string, string> $query
     *
     * @return array{success: bool, result: ?array, httpCode: int}
     */
    private static function request(string $path, array $query): array
    {
        $fail = ['success' => false, 'result' => null, 'httpCode' => 0];

        $key = (string) Settings::get('w3w.apiKey', '');
        if ($key === '') {
            return $fail;
        }

        // 🔒 http_build_query() rawurlencodes every value (PHP_QUERY_RFC1738
        //    by default) — the key is therefore encoded exactly once and
        //    never concatenated raw. $url embeds the key — NEVER log it.
        $url = self::API_BASE . $path . '?' . http_build_query($query + ['key' => $key]);

        $ch = curl_init($url);
        if ($ch === false) {
            return $fail;
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        // 🛡️ No CURLOPT_SSL_VERIFYPEER / CURLOPT_SSL_VERIFYHOST override —
        //    cURL's default (verify ON) is what we want, always.

        $raw       = curl_exec($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrNo = curl_errno($ch);
        curl_close($ch);

        if ($raw === false) {
            Logger::errorPlatform(
                'What3Words',
                'Error',
                'W3W_TRANSPORT',
                'What3Words API transport failure',
                'path=' . $path . ' httpCode=' . (string) $httpCode . ' curlErrno=' . (string) $curlErrNo
            );
            return $fail;
        }

        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded) === false) {
            Logger::errorPlatform(
                'What3Words',
                'Error',
                'W3W_DECODE',
                'What3Words API returned a non-JSON body',
                'path=' . $path . ' httpCode=' . (string) $httpCode
            );
            return $fail;
        }

        if ($httpCode < 200 || $httpCode >= 300 || isset($decoded['error']) === true) {
            // 🔒 Provider error body text is NEVER surfaced (it may echo
            //    request params back) — log path+httpCode only.
            Logger::errorPlatform(
                'What3Words',
                'Warning',
                'W3W_API_ERROR',
                'What3Words API call failed',
                'path=' . $path . ' httpCode=' . (string) $httpCode
            );
            return ['success' => false, 'result' => null, 'httpCode' => $httpCode];
        }

        return ['success' => true, 'result' => $decoded, 'httpCode' => $httpCode];
    }
}
