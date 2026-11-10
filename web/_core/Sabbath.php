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
        $place = $method === 'sunset_calc' ? self::place($siteId, $sabbath) : null;
        if ($place !== null) {
            $startTs = self::sunsetAt($fridayDate, $place['lat'], $place['lng']) + ($startOffsetMin * 60);
            $endTs   = self::sunsetAt($saturdayDate, $place['lat'], $place['lng']) + ($endOffsetMin * 60);
        } else {
            // Fixed: 18:00 on both edges (also the answer when sunset has no place)
            $startTs = $fridayDate->setTime(18, 0, 0)->getTimestamp() + ($startOffsetMin * 60);
            $endTs   = $saturdayDate->setTime(18, 0, 0)->getTimestamp() + ($endOffsetMin * 60);
        }

        return [$startTs, $endTs];
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
     *                            fallback; null for the current one.
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

        // 🏢 The organisation's own map position. For the organisation the
        //    current request belongs to, the merged settings already hold its
        //    own row where it has one. For ANOTHER organisation (a scheduled job
        //    working through several), it is read from the database, that
        //    organisation's own row first (App::settingForSite()). A read that
        //    fails means "no place known", never a made-up one.
        if ($siteId === null || $siteId === Site::id()) {
            $orgLat = App::settings('org.latitude');
            $orgLng = App::settings('org.longitude');
        } else {
            try {
                $orgLat = App::settingForSite('org.latitude', $siteId);
                $orgLng = App::settingForSite('org.longitude', $siteId);
            } catch (\Throwable $e) {
                return null;
            }
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
     * ignores. A plain decimal written as text (optionally signed), finite, and
     * no further from zero than `$limit` (90 for a latitude, 180 for a
     * longitude). Anything else (empty text, words, a list, an exponent such as
     * "1e2") is null.
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
        if (preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $text) !== 1) {
            return null;
        }
        $number = (float) $text;
        if (is_finite($number) === false || abs($number) > $limit) {
            return null;
        }

        return $number;
    }

    private static function sunsetAt(\DateTimeImmutable $date, float $lat, float $lng): int
    {
        $info = date_sun_info($date->getTimestamp(), $lat, $lng);
        if (is_array($info) && isset($info['sunset']) && is_int($info['sunset'])) {
            return $info['sunset'];
        }
        // 🪞 Fallback: 18:00 local on the given date.
        return $date->setTime(18, 0, 0)->getTimestamp();
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
