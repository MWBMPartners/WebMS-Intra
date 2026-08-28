<?php
// Path: _core/GeoLocation.php
/**
 * -----------------------------------------------------------------------------
 * Location / Geocoordinates / What3Words value & service class 📍 (#456 Chunk A)
 * -----------------------------------------------------------------------------
 * Zero-network value/service class implementing the cross-repo location
 * CONTRACT (v1) shared with ProjectBookIT and ProjectEPass — address
 * normalisation/formatting, coordinate validation to DECIMAL(10,7), W3W
 * canonicalisation, map link-outs, and the §2/§7 wire-object serializer
 * (`toLocationObject()` / `fromLocationObject()`).
 *
 * STANDALONE (contract §0): this class never calls another repo, another
 * service, or the network. It delegates network work (geocode lookups,
 * What3Words conversion) to `Geocoder`/`What3Words` — this class itself is
 * pure string/number transformation, safe to call from any request path.
 *
 * `normaliseAddress()`/`nullableTrim()` intentionally mirror
 * `Venues::saveVenue()`'s address rules verbatim (255/255/100/100/20 max
 * lengths, countryCode uppercase-ISO2-or-'GB' fallback) so every app that
 * captures a structured address behaves identically.
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

class GeoLocation
{
    /** @var string Unicode-aware what3words shape — see contract §3. */
    private const W3W_RE = '/^\p{L}+\.\p{L}+\.\p{L}+$/u';

    /* ==========================================================================
     * 📮 Address normalise / format (Venues::saveVenue() rules, verbatim)
     * ======================================================================== */

    /**
     * Normalise a raw address parts array to the canonical shape, applying
     * the exact same trim/max-length/countryCode rules as
     * `Venues::saveVenue()` (408-519) — the house convention for a
     * structured address, kept identical across every app.
     *
     * @param array<string, mixed> $parts line1,line2,city,region,postcode,countryCode
     *
     * @return array{line1:?string,line2:?string,city:?string,region:?string,postcode:?string,countryCode:string}
     */
    public static function normaliseAddress(array $parts): array
    {
        $countryCode = strtoupper(trim((string) ($parts['countryCode'] ?? 'GB')));
        if (preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
            $countryCode = 'GB';
        }

        return [
            'line1'       => self::nullableTrim($parts['line1'] ?? null, 255),
            'line2'       => self::nullableTrim($parts['line2'] ?? null, 255),
            'city'        => self::nullableTrim($parts['city'] ?? null, 100),
            'region'      => self::nullableTrim($parts['region'] ?? null, 100),
            'postcode'    => self::nullableTrim($parts['postcode'] ?? null, 20),
            'countryCode' => $countryCode,
        ];
    }

    /**
     * Trim + max-length a nullable string field — `''` collapses to `null`.
     * Copied from `Venues::nullableTrim()` so both classes stay in lock-step
     * without a cross-class dependency.
     */
    private static function nullableTrim(mixed $v, int $max): ?string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '') {
            return null;
        }
        return mb_substr($s, 0, $max);
    }

    /**
     * One-line display form — the exact `venue.php` 81-88 extract: filters
     * empty parts, joins with `$sep`. Country code deliberately omitted
     * (matches the existing venue.php one-line rendering).
     *
     * @param array<string, mixed> $parts line1,line2,city,region,postcode
     */
    public static function formatAddress(array $parts, string $sep = ', '): string
    {
        $filtered = array_filter(
            [
                $parts['line1'] ?? null, $parts['line2'] ?? null, $parts['city'] ?? null,
                $parts['region'] ?? null, $parts['postcode'] ?? null,
            ],
            static fn ($p) => $p !== null && $p !== ''
        );

        return count($filtered) > 0 ? implode($sep, $filtered) : '';
    }

    /* ==========================================================================
     * 🧭 Coordinate validation (DECIMAL(10,7) — contract §1/§5)
     * ======================================================================== */

    /**
     * Validate a lat/lng pair — all-or-nothing (a half-supplied pair is
     * rejected as null, never silently stored one-sided). Rounds both to
     * 7dp to match the DECIMAL(10,7) column shape everywhere in the schema.
     *
     * @return array{lat: float, lng: float}|null
     */
    public static function validateCoords(mixed $lat, mixed $lng): ?array
    {
        if ($lat === null || $lat === '' || $lng === null || $lng === '') {
            return null;
        }
        $latF = (float) $lat;
        $lngF = (float) $lng;
        if ($latF < -90.0 || $latF > 90.0 || $lngF < -180.0 || $lngF > 180.0) {
            return null;
        }
        return ['lat' => round($latF, 7), 'lng' => round($lngF, 7)];
    }

    /**
     * Coarsen a precise coordinate pair to ~110m (3dp) — the non-private-
     * tier precision floor (Chunk B PII visibility gate), defined here so
     * the shared display partial can reference it from day one even though
     * no PII table exists yet in this chunk.
     *
     * @return array{lat: float, lng: float}
     */
    public static function coarsenCoords(float $lat, float $lng): array
    {
        return ['lat' => round($lat, 3), 'lng' => round($lng, 3)];
    }

    /* ==========================================================================
     * 🗺️ What3Words canonicalisation (contract §3 — NO network here)
     * ======================================================================== */

    /**
     * Normalise a raw what3words value to the canonical bare
     * `word.word.word` form: trims, strips a leading `///` (or 1-3 bare
     * `/`), strips an accidental `what3words.com/` URL prefix, lowercases,
     * then validates against the Unicode-aware three-word shape and a
     * 100-char cap. Returns null on any failure — pure string work, no
     * HTTP call, safe to call unconditionally on every save regardless of
     * whether the What3Words API integration is configured.
     */
    public static function validateW3W(mixed $raw): ?string
    {
        $s = trim((string) ($raw ?? ''));
        if ($s === '') {
            return null;
        }

        // 🌐 Tolerate a full what3words.com share URL pasted in whole.
        $s = preg_replace('#^https?://(?:www\.)?what3words\.com/#i', '', $s) ?? $s;
        // ✂️ Strip a leading `///` (or 1-3 bare slashes) — display-only prefix.
        $s = preg_replace('#^/{1,3}#', '', $s) ?? $s;
        $s = mb_strtolower(trim($s));

        if (mb_strlen($s) > 100) {
            return null;
        }
        if (preg_match(self::W3W_RE, $s) !== 1) {
            return null;
        }
        return $s;
    }

    /* ==========================================================================
     * 🔗 Map link-outs (contract §6 — text/links always present)
     * ======================================================================== */

    /**
     * Build the three display link forms out of a location's raw parts.
     * Every URL is assembled from validated/`rawurlencode()`d parts only —
     * callers still MUST `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')` the
     * returned strings at the point of echo (this method returns raw URLs,
     * not HTML).
     *
     * @param array{lat?: ?float, lng?: ?float, w3w?: ?string, address?: ?string} $loc
     *
     * @return array{w3w: ?string, directions: ?string, osm: ?string}
     */
    public static function mapLinks(array $loc): array
    {
        $lat     = $loc['lat'] ?? null;
        $lng     = $loc['lng'] ?? null;
        $w3w     = $loc['w3w'] ?? null;
        $address = $loc['address'] ?? null;
        $hasCoords = $lat !== null && $lng !== null;

        $w3wUrl = ($w3w !== null && $w3w !== '')
            ? 'https://what3words.com/' . rawurlencode((string) $w3w)
            : null;

        $directionsUrl = null;
        if ($hasCoords === true) {
            $directionsUrl = 'https://www.google.com/maps/search/?api=1&query='
                . rawurlencode((string) $lat . ',' . (string) $lng);
        } elseif ($address !== null && $address !== '') {
            $directionsUrl = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode((string) $address);
        }

        $osmUrl = null;
        if ($hasCoords === true) {
            $osmUrl = 'https://www.openstreetmap.org/?mlat=' . rawurlencode((string) $lat)
                . '&mlon=' . rawurlencode((string) $lng)
                . '#map=17/' . rawurlencode((string) $lat) . '/' . rawurlencode((string) $lng);
        }

        return ['w3w' => $w3wUrl, 'directions' => $directionsUrl, 'osm' => $osmUrl];
    }

    /* ==========================================================================
     * 🔄 Canonical wire object — cross-repo CONTRACT §2/§7 serializer
     * ======================================================================== */

    /**
     * Serialize a DB row (using this table's OWN column names, historical
     * or canonical) into the CONTRACT §2 canonical `location` JSON object.
     * The wire shape is identical across WebMS-Intra/BookIT/EPass even
     * though a table's stored column names may be historical (e.g.
     * tblEvents.locationGeoLat) — `$names` maps canonical field -> this
     * row's actual column name.
     *
     * @param array<string, mixed> $row   The fetched DB row (assoc array)
     * @param array<string, string> $names Canonical field => column name in $row.
     *                                     Defaults to the CONTRACT's own names
     *                                     (name,addressLine1,addressLine2,city,
     *                                     region,postcode,countryCode,latitude,
     *                                     longitude,what3words,geocodedAt,
     *                                     geocodeSource) for tables that already
     *                                     use them (tblVenues, tblResource, …).
     *
     * @return array{name:?string,address:array{line1:?string,line2:?string,city:?string,region:?string,postcode:?string,countryCode:?string},latitude:?float,longitude:?float,what3words:?string,geocodedAt:?string,geocodeSource:?string}
     */
    public static function toLocationObject(array $row, array $names = []): array
    {
        $col = static fn (string $canonical, string $default): mixed
            => $row[$names[$canonical] ?? $default] ?? null;

        $latRaw = $col('latitude', 'latitude');
        $lngRaw = $col('longitude', 'longitude');
        $lat    = ($latRaw !== null && $latRaw !== '') ? round((float) $latRaw, 7) : null;
        $lng    = ($lngRaw !== null && $lngRaw !== '') ? round((float) $lngRaw, 7) : null;

        $w3wRaw = $col('what3words', 'what3words');
        $w3w    = ($w3wRaw !== null && (string) $w3wRaw !== '') ? (string) $w3wRaw : null;

        $geocodedAtRaw = $col('geocodedAt', 'geocodedAt');
        $geocodedAt    = null;
        if ($geocodedAtRaw !== null && (string) $geocodedAtRaw !== '') {
            $ts = strtotime((string) $geocodedAtRaw);
            $geocodedAt = $ts !== false ? gmdate('Y-m-d\TH:i:s\Z', $ts) : null;
        }

        $nameRaw = $col('name', 'name');
        $ccRaw   = $col('countryCode', 'countryCode');

        return [
            'name'    => ($nameRaw !== null && (string) $nameRaw !== '') ? (string) $nameRaw : null,
            'address' => [
                'line1'       => self::orNull($col('line1', 'addressLine1')),
                'line2'       => self::orNull($col('line2', 'addressLine2')),
                'city'        => self::orNull($col('city', 'city')),
                'region'      => self::orNull($col('region', 'region')),
                'postcode'    => self::orNull($col('postcode', 'postcode')),
                'countryCode' => ($ccRaw !== null && (string) $ccRaw !== '') ? strtoupper((string) $ccRaw) : null,
            ],
            'latitude'      => $lat,
            'longitude'     => $lng,
            'what3words'    => $w3w,
            'geocodedAt'    => $geocodedAt,
            'geocodeSource' => self::orNull($col('geocodeSource', 'geocodeSource')),
        ];
    }

    /**
     * Deserialize a CONTRACT §2 canonical `location` object back into this
     * repo's flat, validated field shape — the inverse of
     * `toLocationObject()`. Every field is validated exactly as a native
     * form-post would be (`validateCoords()`, `validateW3W()`,
     * `normaliseAddress()`) so a payload crossing the wire from BookIT/
     * EPass can never bypass this repo's own save-path guarantees.
     *
     * @param array<string, mixed> $obj A `location` object per contract §2
     *
     * @return array{name:?string,address:array{line1:?string,line2:?string,city:?string,region:?string,postcode:?string,countryCode:string},lat:?float,lng:?float,w3w:?string,geocodedAt:?string,geocodeSource:?string}
     */
    public static function fromLocationObject(array $obj): array
    {
        $addr = is_array($obj['address'] ?? null) ? $obj['address'] : [];
        $coords = self::validateCoords($obj['latitude'] ?? null, $obj['longitude'] ?? null);

        $nameRaw = $obj['name'] ?? null;
        $geocodedAtRaw = $obj['geocodedAt'] ?? null;
        $geocodedAt = null;
        if ($geocodedAtRaw !== null && (string) $geocodedAtRaw !== '') {
            $ts = strtotime((string) $geocodedAtRaw);
            $geocodedAt = $ts !== false ? gmdate('Y-m-d H:i:s', $ts) : null;
        }
        $sourceRaw = (string) ($obj['geocodeSource'] ?? '');
        $source    = in_array($sourceRaw, ['google', 'nominatim', 'manual', 'w3w'], true) ? $sourceRaw : null;

        return [
            'name'          => ($nameRaw !== null && trim((string) $nameRaw) !== '') ? trim((string) $nameRaw) : null,
            'address'       => self::normaliseAddress([
                'line1'       => $addr['line1'] ?? null,
                'line2'       => $addr['line2'] ?? null,
                'city'        => $addr['city'] ?? null,
                'region'      => $addr['region'] ?? null,
                'postcode'    => $addr['postcode'] ?? null,
                'countryCode' => $addr['countryCode'] ?? null,
            ]),
            'lat'           => $coords['lat'] ?? null,
            'lng'           => $coords['lng'] ?? null,
            'w3w'           => self::validateW3W($obj['what3words'] ?? null),
            'geocodedAt'    => $geocodedAt,
            'geocodeSource' => $source,
        ];
    }

    /** Small helper — collapse `''`/missing to null for wire-object fields. */
    private static function orNull(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = (string) $v;
        return $s !== '' ? $s : null;
    }
}
