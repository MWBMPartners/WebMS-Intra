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
 * lengths, countryCode uppercase-ISO2 or nothing) so every app that
 * captures a structured address behaves identically. A missing country stays
 * missing: it is never made British (#942, D-133).
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
     * 🌍 THE COUNTRY (#942, D-133). Two letters, in capitals, or NULL when
     * the country is missing, empty or not two letters. It used to become
     * 'GB' in every one of those cases, for every customer anywhere, so an
     * organisation that saved its address with the country box empty was
     * stored as British. Nothing is made up now: an address with no country
     * has no country, and the caller decides what that means (the
     * organisation settings page: follow the installation's country; a venue:
     * Venues::countryToStore(), the organisation's own or a refusal).
     *
     * The same is true of an address arriving over the wire from ProjectBookIT
     * or ProjectEPass through fromLocationObject(): one sent with no country,
     * or with something that is not a two-letter code, now comes back with
     * `countryCode` null instead of 'GB'. (Nothing in this repository calls
     * fromLocationObject() today — `grep -rn fromLocationObject web tools`
     * answers this file alone — so no stored data changes with it.)
     *
     * @param array<string, mixed> $parts line1,line2,city,region,postcode,countryCode
     *
     * @return array{line1:?string,line2:?string,city:?string,region:?string,postcode:?string,countryCode:?string}
     */
    public static function normaliseAddress(array $parts): array
    {
        $rawCountry  = $parts['countryCode'] ?? null;
        $countryCode = is_string($rawCountry) === true ? strtoupper(trim($rawCountry)) : '';
        if (preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
            $countryCode = null;
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
     * ONLY A NUMBER IS A COORDINATE (#945). This used to turn the value into a
     * number with `(float)`, which makes ANY text a number: a word, a typing
     * slip or "51,5" (a decimal comma) became 0 or 51, and 0 is a real place
     * -- the equator, the prime meridian, the Gulf of Guinea at 0, 0. So a
     * mistyped latitude was saved as somewhere real instead of being refused.
     * `coordinateNumber()` below now decides, and anything it does not accept
     * makes the whole pair null, exactly as an out-of-range or half-supplied
     * pair always has. Exactly 0 is still a valid coordinate, and so is the
     * exponent form ("4.0E-5"), which the organisation page used to write
     * (#935) and which may still be stored.
     *
     * Null means "no usable position", and it says nothing about WHY: two empty
     * boxes and a word typed in one look the same here. A FORM must therefore
     * not stop at null -- it asks `typedPositionProblem()` below, which tells
     * those apart. The events API answers 422 for a pair it was sent and could
     * not use; the What3Words focus, the reverse geocoder and the address
     * suggester simply do without, because nobody typed anything there.
     *
     * WHAT WAS WRONG (#1030). Until 15 November 2026 seven forms (asset
     * locations, venues, resources, events, a date's own details, a member's
     * directory entry, and a small group with its hosts' homes) took null as
     * "no position" and saved, so a person who mistyped a latitude was told
     * the save had worked while their position was dropped -- and where the
     * form changes something that already had a position, the stored one was
     * wiped (every one of them but resources, which only adds). Each now asks
     * `positionRefusal()` and refuses with the reason before anything is saved.
     *
     * @return array{lat: float, lng: float}|null
     */
    public static function validateCoords(mixed $lat, mixed $lng): ?array
    {
        if ($lat === null || $lat === '' || $lng === null || $lng === '') {
            return null;
        }
        $latF = self::coordinateNumber($lat);
        $lngF = self::coordinateNumber($lng);
        if ($latF === null || $lngF === null) {
            return null;
        }
        if ($latF < -90.0 || $latF > 90.0 || $lngF < -180.0 || $lngF > 180.0) {
            return null;
        }
        return ['lat' => round($latF, 7), 'lng' => round($lngF, 7)];
    }

    /**
     * The number a coordinate box holds, or null when it does not hold one.
     *
     * Accepted: an integer or a float handed over by code (a geocoder's
     * answer, a JSON number), or TEXT that is a plain decimal with an optional
     * sign -- "51.5", "-0.12", ".5", "0" -- optionally in exponent form
     * ("4.0E-5", up to three exponent digits), with surrounding spaces
     * ignored. This is the same shape `Sabbath::coordinate()` accepts, so a
     * value one of them reads the other reads too.
     *
     * Refused (null): a word, a decimal comma ("51,5"), hexadecimal, an empty
     * box after trimming, a true/false, an array (a request can send
     * `latitude[]=1`, and `(float)` of an array is 1), and anything that is
     * not a finite number (INF, NAN).
     *
     * The range is NOT judged here: that belongs to the caller, because
     * latitude and longitude have different limits.
     */
    public static function coordinateNumber(mixed $value): ?float
    {
        if (is_int($value) === true || is_float($value) === true) {
            $number = (float) $value;

            return is_finite($number) === true ? $number : null;
        }
        if (is_string($value) === false) {
            return null;
        }
        $text = trim($value);
        if (preg_match('/\A[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d{1,3})?\z/', $text) !== 1) {
            return null;
        }
        $number = (float) $text;

        return is_finite($number) === true ? $number : null;
    }

    /**
     * 🧭 Why a map position somebody TYPED cannot be used, or '' when there is
     * nothing to say: either the pair is usable, or neither box holds anything
     * (no position was typed, so the form may store none, or fill one in from
     * a what3words address or the postal address where it does that).
     *
     * This is the ONE judge of that question (#1030). The organisation page
     * (#945) first wrote it inline; the seven forms of #1030 (eight pages)
     * then needed the same answer, and eight copies would drift apart.
     *
     * The answer is a clause in lower case with no full stop, so a caller can
     * put it into its own sentence: the organisation page keeps the stored
     * position and SAYS so; every other form refuses through
     * `positionRefusal()`.
     *
     * "Typed" is asked of the text after trimming spaces: a box holding only
     * spaces is an empty box. Anything that is not text (a request can send
     * `latitude[]=1`) counts as typed, because it was sent.
     *
     * The three reasons are checked in this order, so each is only ever given
     * for the case it describes: one box empty, then not a number, then out of
     * range (text that is not a number has no range to be outside).
     */
    public static function typedPositionProblem(mixed $lat, mixed $lng): string
    {
        $typed = static function (mixed $value): bool {
            return $value !== null && (is_string($value) === false || trim($value) !== '');
        };
        $latTyped = $typed($lat);
        $lngTyped = $typed($lng);
        if ($latTyped === false && $lngTyped === false) {
            return '';
        }
        if (self::validateCoords($lat, $lng) !== null) {
            return '';
        }
        if ($latTyped === false || $lngTyped === false) {
            return 'only one of the two boxes was filled in';
        }
        if (self::coordinateNumber($lat) === null || self::coordinateNumber($lng) === null) {
            return 'the latitude and longitude must be numbers, written with a full stop for the decimal point (for example 51.5074 and -0.1278)';
        }

        return 'the latitude must be from -90 to 90 and the longitude from -180 to 180';
    }

    /**
     * 🚫 The whole message a form shows when it REFUSES to save because of the
     * map position, or null when the position is no reason to refuse (#1030).
     *
     * Worded like the other refusals on those forms ("… Nothing was saved."),
     * and it says how to get past it, because the person may not have meant to
     * give a position at all.
     *
     * WHY REFUSE rather than save the rest. Seven of the eight pages already
     * refuse the whole save for a mistyped what3words address, for the same
     * reason: a position somebody meant to give must not vanish while the page
     * says "saved". (The eighth, asset locations, still drops a mistyped
     * what3words address without a word; that is not this change.) The advice is "empty both boxes", never "to give no
     * position": with both empty, some forms fill a position in from a
     * what3words address or the postal address, so that would be untrue there.
     * (The organisation page is the one exception, kept from
     * #945: there a refusal threw away a whole form of address lines, country
     * and currency, so it saves the rest and leaves the stored position alone.)
     */
    public static function positionRefusal(mixed $lat, mixed $lng): ?string
    {
        $reason = self::typedPositionProblem($lat, $lng);
        if ($reason === '') {
            return null;
        }

        return 'The map position could not be used: ' . $reason
            . '. Correct it, or empty both boxes. Nothing was saved.';
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
     * @return array{name:?string,address:array{line1:?string,line2:?string,city:?string,region:?string,postcode:?string,countryCode:?string},lat:?float,lng:?float,w3w:?string,geocodedAt:?string,geocodeSource:?string}
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
