<?php
// Path: _core/Sabbath.php
/**
 * -----------------------------------------------------------------------------
 * WebMS Intra — Sabbath Quiet Hours 🕯️
 * -----------------------------------------------------------------------------
 * For SDA-context portals: detects whether "now" falls within the Sabbath
 * window (Friday sunset → Saturday sunset by default) and lets callers
 * defer non-critical sends until after the window.
 *
 * Two-level system:
 *   1. Org default — portal.sabbath.* settings.
 *   2. Per-user override — tblUsers.sabbathHonour ('inherit'|'on'|'off').
 *
 * Calculation: date_sun_info() against a PLACE (see place()). Falls back to
 * the fixed method (18:00 local time on both edges, with the offsets) when no
 * place is known, and the settings page says so (#935).
 *
 * NO PLACE IS BUILT IN (#935). The place used to ship as, and fall back to,
 * Cambridge's latitude and longitude, for every customer anywhere: a church in
 * Sydney that chose "sunset" had its quiet hours begin at Cambridge's sunset.
 * The setting now ships EMPTY, and empty means "each organisation's own map
 * position" (`org.latitude` / `org.longitude`, set at Admin, Settings,
 * Organisation, per organisation since #677): the same shape as the zone,
 * where empty means each organisation's own (#782). Where neither is known
 * there is NO place, and the window is worked out by the fixed method, never
 * at a made-up place or at 0,0 (which is in the Gulf of Guinea).
 *
 * Usage:
 *   if (Sabbath::isQuietNow($userId) === true) {
 *       // queue for later
 *   } else {
 *       Mailer::send(...);
 *   }
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/231
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/782
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/935
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Sabbath
{
    /**
     * Is "now" within the Sabbath quiet window?
     *
     * WHOSE window (#782). The window is worked out on ONE organisation's
     * clock when the zone setting is empty ("each organisation's own", which
     * a new installation ships with), so the caller must say which
     * organisation the message belongs to. A scheduled job that works through
     * several organisations' messages in one run MUST pass each message's own
     * organisation: Sms::send() and Sms::sendDue() do. Null means the
     * organisation the current request resolved to, which is right only for
     * something that belongs to no organisation in particular (the
     * installation-wide error alert in Logger) or to the request itself.
     *
     * @param int|null $userId  if provided, honours per-user override.
     * @param int|null $now     the moment to ask about; null means now.
     * @param int|null $siteId  the organisation whose clock decides; see above.
     */
    public static function isQuietNow(?int $userId = null, ?int $now = null, ?int $siteId = null): bool
    {
        $settings = App::settings();
        if ((string) ($settings['portal']['sabbath']['enabled'] ?? '0') !== '1') {
            return false;
        }

        // Per-user override
        if ($userId !== null) {
            $userOverride = self::userOverride($userId);
            if ($userOverride === 'off') {
                return false;
            }
            // 'on' or 'inherit' continues to check the window
        }

        $now ??= time();
        [$startTs, $endTs] = self::computeWindow($now, $siteId);

        return $now >= $startTs && $now <= $endTs;
    }

    /**
     * 🕰️ THE ONE ANSWER to "on which clock is the quiet window worked out?" (#782).
     *
     * The `portal.sabbath.timezone` setting when it names a zone PHP can open;
     * otherwise — and EMPTY is the ordinary case, the value a new installation
     * ships with — the zone of the organisation given (or, given none, of the
     * organisation the current request resolved to), through `Site::zoneFor()`,
     * the portal's one rule for a stored zone (#604).
     *
     * THE ORGANISATION MUST BE GIVEN WHEREVER MORE THAN ONE IS IN PLAY. The
     * first version of this took none, and the scheduled job that sends held
     * texts works through EVERY organisation's texts in one run without
     * switching organisation, so a Sydney member's text was judged on whichever
     * organisation the job happened to resolve to. Before #782 there was one
     * zone for everybody, so that could not happen; "each organisation's own"
     * is what made it possible. Found by the independent check.
     *
     * The OTHER Sabbath settings (on or off, the method, the offsets, a place
     * typed for sunset) are read as the current request has them. The settings
     * page saves them installation-wide only, so in practice they are the same
     * for every organisation; only the clock, and (when no place is typed) the
     * place for sunset (place(), #935), are per organisation.
     *
     * WHAT WAS WRONG. The setting shipped holding "Europe/London" for every
     * installation, the settings page offered London pre-selected, and a zone
     * PHP could not open fell back to UTC. So quiet hours for a church in
     * Sydney began at 18:00 on London's Friday — between 03:00 and 05:00 on
     * Saturday morning in Sydney — unless somebody had found this page and
     * changed it.
     *
     * WHAT THIS CANNOT DO: tell a "Europe/London" somebody chose from one the
     * install script put there. An installation upgraded from before #782
     * keeps the London it holds, because nothing can show it was not chosen;
     * an installation made after #782 starts empty.
     */
    public static function zone(?int $siteId = null, ?array $sabbath = null): \DateTimeZone
    {
        $sabbath ??= self::settingsNow();

        return Site::zoneFor(is_scalar($sabbath['timezone'] ?? null) === true ? (string) $sabbath['timezone'] : '', $siteId);
    }

    /**
     * The `portal.sabbath.*` settings as this request has them (merged: an
     * organisation's own row wins), nested as in $SETTINGS['portal']['sabbath'].
     * An empty list when there are none, never a non-list.
     *
     * @return array<string, mixed>
     */
    private static function settingsNow(): array
    {
        $sabbath = App::settings()['portal']['sabbath'] ?? [];

        return is_array($sabbath) === true ? $sabbath : [];
    }

    /**
     * Compute the Sabbath window containing or surrounding the given time,
     * on the clock of the organisation given (see isQuietNow() for why it
     * must be given wherever more than one organisation is in play).
     *
     * @param int|null   $siteId  The organisation whose clock decides; null for the current one.
     * @param array|null $sabbath The `portal.sabbath.*` settings to use, nested as in
     *                            $SETTINGS['portal']['sabbath']; null (every caller but one)
     *                            means as this request has them. The settings page passes
     *                            what it has JUST SAVED, which start-up read before the
     *                            save, so its preview is not one save behind (#936).
     *
     * @return array{0:int, 1:int}  [start_ts, end_ts]
     */
    public static function computeWindow(int $referenceTs, ?int $siteId = null, ?array $sabbath = null): array
    {
        $details = self::windowDetails($referenceTs, $siteId, $sabbath);

        return [$details['start'], $details['end']];
    }

    /**
     * The window computeWindow() answers, with ONE more fact: `noSunset` is true
     * when the sunset method is in use and at least one edge is at 18:00 because
     * the sun does not set that day (polar day or night, sunsetAt()). The
     * settings page uses it to SAY so beside the preview, rather than show 18:00
     * as if it were a sunset (#935 round 3).
     *
     * @param int|null   $siteId  As computeWindow().
     * @param array|null $sabbath As computeWindow().
     *
     * @return array{start: int, end: int, noSunset: bool}
     */
    public static function windowDetails(int $referenceTs, ?int $siteId = null, ?array $sabbath = null): array
    {
        $sabbath ??= self::settingsNow();
        $method = (string) ($sabbath['method'] ?? 'fixed');
        $startOffsetMin = (int) ($sabbath['start_offset_minutes'] ?? 0);
        $endOffsetMin   = (int) ($sabbath['end_offset_minutes'] ?? 0);

        $tzObj = self::zone($siteId, $sabbath);

        $ref = (new \DateTimeImmutable('@' . $referenceTs))->setTimezone($tzObj);

        // Find this week's Friday (start) and Saturday (end) in local tz
        $weekday = (int) $ref->format('w'); // 0=Sun..6=Sat
        // Days back to Friday: from a given weekday, how many days to subtract
        // to land on the same week's Friday (5)? If today is Sun-Thu, the
        // "current Sabbath" is last weekend; if Fri-Sat, it's this weekend.
        $offsetToFriday = ($weekday >= 5)
            ? ($weekday - 5)
            : ($weekday + 2); // 0->2 (last Friday), ..., 4->6
        $fridayDate = $ref->modify('-' . $offsetToFriday . ' days')->setTime(12, 0, 0);
        $saturdayDate = $fridayDate->modify('+1 day');

        // 📍 Sunset needs a PLACE (#935). With none known, the fixed method below is
        //    used instead: the behaviour this class's header has promised since #231
        //    ("falls back to a fixed 18:00"), and the one the settings page states.
        //    WHAT WAS WRONG: an empty setting fell back to Cambridge's latitude and
        //    longitude, for every customer anywhere, and a stored '' read as 0,0.
        // 🌇 Each edge: the sunset (sunsetAt()), or 18:00 on that date when there is
        //    no place, or when the sun does not set that day (polar day or night).
        $place    = $method === 'sunset_calc' ? self::place($siteId, $sabbath) : null;
        $noSunset = false;
        $edge     = static function (\DateTimeImmutable $date) use ($place, &$noSunset): int {
            $sunset = $place !== null ? self::sunsetAt($date, $place['lat'], $place['lng']) : null;
            if ($place !== null && $sunset === null) {
                $noSunset = true;
            }

            return $sunset ?? $date->setTime(18, 0, 0)->getTimestamp();
        };
        $startTs = $edge($fridayDate) + ($startOffsetMin * 60);
        $endTs   = $edge($saturdayDate) + ($endOffsetMin * 60);

        return ['start' => $startTs, 'end' => $endTs, 'noSunset' => $noSunset];
    }

    /**
     * 📍 THE ONE ANSWER to "where is sunset worked out?" (#935).
     *
     *   1. The `portal.sabbath.location_lat` / `location_lng` settings, when
     *      BOTH hold a usable number (coordinate()): a place somebody typed on
     *      the Sabbath settings page.
     *   2. Otherwise (and EMPTY is the ordinary case, the value a new
     *      installation ships with) the organisation's own map position,
     *      `org.latitude` / `org.longitude`, from Admin, Settings,
     *      Organisation. That is per organisation (#677), so it is read for the
     *      organisation given, exactly as zone() reads that organisation's zone.
     *   3. Otherwise null: there is NO place, and the caller must say so rather
     *      than guess. computeWindow() then uses the fixed method.
     *
     * WHY THE ORGANISATION, AND NOT A BOX THE PAGE REQUIRES. The issue (#935)
     * offered both. The organisation's map position is already the portal's
     * answer to "where is this organisation?"; it is per organisation where the
     * Sabbath settings are installation-wide; and it lets "sunset" work for
     * several organisations on one installation without a second place to keep
     * in step: the reasoning #782 applied to the zone.
     *
     * WHAT THIS CANNOT DO: tell a place somebody chose from one the install
     * script put there. An installation made before #935 keeps the Cambridge it
     * holds (migration 274 changes only the value to "reset to"), and a stored
     * 0,0 (which the settings page used to write when a save did not send the
     * boxes, #936) is a real place and is used as one. Both show in the boxes on
     * the settings page, so they can be seen and changed.
     *
     * @param int|null   $siteId  The organisation whose map position is the
     *                            fallback; null for the current one,
     *                            Site::id() (also after a forced context).
     * @param array|null $sabbath As for computeWindow(); null means as this
     *                            request has them.
     *
     * @return array{lat: float, lng: float, from: string}|null `from` is
     *         'setting' or 'organisation'.
     */
    public static function place(?int $siteId = null, ?array $sabbath = null): ?array
    {
        $sabbath ??= self::settingsNow();
        $lat     = self::coordinate($sabbath['location_lat'] ?? null, 90.0);
        $lng     = self::coordinate($sabbath['location_lng'] ?? null, 180.0);
        if ($lat !== null && $lng !== null) {
            return ['lat' => $lat, 'lng' => $lng, 'from' => 'setting'];
        }

        // 🏢 The organisation's own map position. GIVEN an organisation, it is
        //    ALWAYS read from the database, that organisation's own row first
        //    (App::settingForSite()), never from the merged settings.
        //    WHAT WAS WRONG (round 1 of the independent check): the first version
        //    took a short cut when the organisation given was Site::id(), reading
        //    the merged settings. But those were merged at start-up for the
        //    organisation the ADDRESS resolved to, and Site::forceContext() (an
        //    API key belonging to another organisation, ApiAuth) changes
        //    Site::id() without merging again. A prayer request sent that way
        //    reaches Sms::send($siteId) → isQuietNow(…, $siteId), and the window
        //    was opened at the ADDRESS organisation's sunset on the KEY
        //    organisation's clock. Measured: London's position, Sydney's clock.
        //    NO organisation given means the CURRENT one, Site::id(), read the
        //    same way: the same organisation zone(null) uses for the clock, so
        //    the place and the clock always belong to one organisation, even
        //    after a forced context (round 2 found the two disagreeing when
        //    null read the merged settings). A read that fails means "no place
        //    known", never a made-up one.
        $siteId ??= Site::id();
        try {
            $orgLat = App::settingForSite('org.latitude', $siteId);
            $orgLng = App::settingForSite('org.longitude', $siteId);
        } catch (\Throwable $e) {
            return null;
        }
        $lat = self::coordinate($orgLat, 90.0);
        $lng = self::coordinate($orgLng, 180.0);
        if ($lat !== null && $lng !== null) {
            return ['lat' => $lat, 'lng' => $lng, 'from' => 'organisation'];
        }

        return null;
    }

    /**
     * 📐 A stored or posted latitude or longitude, or null when it is not one.
     *
     * The ONE test of what counts as a coordinate here, asked by place() AND by
     * the settings page's save, so the page cannot accept what the window then
     * ignores. A decimal written as text (optionally signed, optionally with an
     * exponent such as "4.0E-5"), finite, and no further from zero than
     * `$limit` (90 for a latitude, 180 for a longitude). Anything else (empty
     * text, words, a list) is null. Exactly 0 is a real coordinate.
     *
     * WHY THE EXPONENT IS ACCEPTED (round 1 of the independent check): the
     * organisation page stored a coordinate as PHP writes a float, and PHP
     * writes anything under 0.0001 with an exponent ('4.0E-5'). The first
     * version refused that, so a place just off the Greenwich meridian or the
     * equator silently fell back to the fixed times while the page said the
     * organisation had no map position. The organisation page now stores a
     * plain decimal (#935), and this still reads what it stored before.
     *
     * WHAT WAS WRONG: the value was cast with (float), so empty text, a word or
     * a missing field all read as 0: a place in the Gulf of Guinea.
     */
    public static function coordinate(mixed $value, float $limit): ?float
    {
        if (is_int($value) === true || is_float($value) === true) {
            $value = (string) $value;
        }
        if (is_string($value) === false) {
            return null;
        }
        $text = trim($value);
        if (preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d{1,3})?$/', $text) !== 1) {
            return null;
        }
        $number = (float) $text;
        if (is_finite($number) === false || abs($number) > $limit) {
            return null;
        }

        return $number;
    }

    /**
     * 🌇 Sunset at a place, on the calendar date `$date` has on ITS OWN clock, or
     * null when the sun does not set that day (polar day or night).
     *
     * THE RULE: `date_sun_info()` gives a solar day's TRANSIT (the sun's highest
     * point) together with that same day's sunset. The sunset used is the one
     * paired with the transit that falls on `$date`, read on the window's own
     * clock. Nothing global is touched, and no range of hours is assumed.
     *
     * WHY THE TRANSIT AND NOT A RANGE OF HOURS (round 3 of the independent
     * check). Round 3 took "the sunset between local noon and noon the next
     * day", and that is untrue in the Arctic: on Central European Time well east
     * of 15°E (Vardø, Kirkenes, Hammerfest, Alta, Kiruna, Tromsø) and at
     * Scoresbysund, the sun sets BEFORE local noon for a few days around the
     * start and end of polar night. Vardø on Friday 20 November 2026 sets at
     * 11:47, and round 3 took Saturday's 11:36 instead, a full day late; Tromsø
     * on 27 November 2026 sets at 11:46 and round 3 gave 18:00. A sunset always
     * belongs to the transit before it, so pairing them needs no assumption
     * about the clock time at all.
     *
     * WHY IT HAS TO BE A RULE AND NOT ONE CALL. `date_sun_info()` decides which
     * day to answer for from PHP's DEFAULT time zone, not from anything it is
     * given, and near the date line it answers for the wrong day even when that
     * zone is the place's own. Measured on PHP 8.5:
     *   - Friday 16 June 2028 at noon in Santiago gives Friday's sunset with the
     *     default zone on UTC and SATURDAY's with it on Sydney. Start-up sets
     *     the default zone to the organisation the ADDRESS resolved to, so any
     *     other organisation (the held-texts job, Site::forceContext()) could
     *     have its window a day late (#935 round 2, P16).
     *   - Even with the default zone set to the place's own, Tongatapu, Apia,
     *     Kiritimati and Fiji got SATURDAY's sunset for Friday 13 November 2026,
     *     every week in Apia and Tongatapu; Adak sometimes got the day BEFORE
     *     (round 2's independent check). Since #231 the window in those places
     *     ran Saturday to Sunday.
     *   - And with a zone written as an offset ("+10:00", which Site::zoneFor()
     *     accepts) date_default_timezone_set() refuses with a notice, so round
     *     2's set-and-restore did nothing there and logged two notices a window.
     * So `date_sun_info()` is asked for instants every six hours from two days
     * before local noon to two days after; each answer's transit is read on the
     * window's own clock to say which DATE it belongs to, and the answer whose
     * transit is on `$date` gives the sunset. Which day PHP thinks each instant
     * is no longer matters.
     *
     * THREE OUTCOMES, said plainly:
     *   - A transit on `$date` with a sunset time: that sunset.
     *   - A transit on `$date` whose sunset is `true` or `false`: the sun does
     *     not set that day (polar day) or does not rise (polar night). Null is
     *     returned, and computeWindow() uses 18:00 on that date instead, on the
     *     window's clock, as the fixed method does. The settings page says so.
     *     At the very edge of midnight sun PHP and an independent NOAA-style
     *     calculation can disagree about whether the sun dips below the horizon
     *     for a few minutes; those days are counted in the notes for #935.
     *   - No transit on `$date` at all: PHP's calculation can SKIP a day
     *     (measured: Anadyr, Friday 1 October 2027). Then the time halfway
     *     between the day before's sunset and the day after's is used. Against
     *     the independent calculation, over ten years and 51 places, it was used
     *     50 times and was at most 167 seconds out. With either neighbour's
     *     sunset missing too, null (18:00).
     */
    private static function sunsetAt(\DateTimeImmutable $date, float $lat, float $lng): ?int
    {
        $zone = $date->getTimezone();
        $day  = $date->format('Y-m-d');
        $noon = new \DateTimeImmutable($day . ' 12:00:00', $zone);
        $from = $noon->getTimestamp();

        // 📅 Each solar day's sunset, filed under the date its TRANSIT has on the
        //    window's own clock. `true`/`false` (no sunset) are kept as they are.
        $sunsetByDate = [];
        for ($hours = -48; $hours <= 48; $hours += 6) {
            $info = date_sun_info($from + ($hours * 3600), $lat, $lng);
            if (is_array($info) === false || is_int($info['transit'] ?? null) === false) {
                continue;
            }
            $transitDate = (new \DateTimeImmutable('@' . $info['transit']))->setTimezone($zone)->format('Y-m-d');
            if (array_key_exists($transitDate, $sunsetByDate) === false) {
                $sunsetByDate[$transitDate] = $info['sunset'] ?? null;
            }
        }

        if (array_key_exists($day, $sunsetByDate) === true) {
            return is_int($sunsetByDate[$day]) === true ? $sunsetByDate[$day] : null;
        }

        // ⏭️ A skipped day: halfway between the day before's and the day after's.
        $before = $sunsetByDate[$noon->modify('-1 day')->format('Y-m-d')] ?? null;
        $after  = $sunsetByDate[$noon->modify('+1 day')->format('Y-m-d')] ?? null;
        if (is_int($before) === true && is_int($after) === true) {
            return intdiv($before + $after, 2);
        }

        return null;
    }

    private static function userOverride(int $userId): string
    {
        // 🛡️ Wrapped — column may not exist on legacy installs.
        try {
            $db = App::db();
            $stmt = $db->prepare('SELECT sabbathHonour FROM tblUsers WHERE userID = ? LIMIT 1');
            if ($stmt === false) {
                return 'inherit';
            }
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $val = (string) ($row['sabbathHonour'] ?? 'inherit');
            return in_array($val, ['on', 'off', 'inherit'], true) ? $val : 'inherit';
        } catch (\Throwable $e) {
            return 'inherit';
        }
    }
}
