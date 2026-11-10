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
 * Calculation: date_sun_info() against the configured lat/lng. Falls back
 * to a fixed 18:00 local time on both edges if lat/lng not configured.
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
     * The OTHER Sabbath settings (on or off, the method, the offsets, the
     * place for sunset) are read as the current request has them. The settings
     * page saves them installation-wide only, so in practice they are the same
     * for every organisation; only the clock is per organisation.
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
    public static function zone(?int $siteId = null): \DateTimeZone
    {
        $settings = App::settings();

        return Site::zoneFor((string) ($settings['portal']['sabbath']['timezone'] ?? ''), $siteId);
    }

    /**
     * Compute the Sabbath window containing or surrounding the given time,
     * on the clock of the organisation given (see isQuietNow() for why it
     * must be given wherever more than one organisation is in play).
     *
     * @param int|null $siteId The organisation whose clock decides; null for the current one.
     *
     * @return array{0:int, 1:int}  [start_ts, end_ts]
     */
    public static function computeWindow(int $referenceTs, ?int $siteId = null): array
    {
        $settings = App::settings();
        $method = (string) ($settings['portal']['sabbath']['method'] ?? 'fixed');
        $startOffsetMin = (int) ($settings['portal']['sabbath']['start_offset_minutes'] ?? 0);
        $endOffsetMin   = (int) ($settings['portal']['sabbath']['end_offset_minutes'] ?? 0);

        $tzObj = self::zone($siteId);

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

        if ($method === 'sunset_calc') {
            $lat = (float) ($settings['portal']['sabbath']['location_lat'] ?? 52.205); // Cambridge default
            $lng = (float) ($settings['portal']['sabbath']['location_lng'] ?? 0.119);
            $startTs = self::sunsetAt($fridayDate, $lat, $lng) + ($startOffsetMin * 60);
            $endTs   = self::sunsetAt($saturdayDate, $lat, $lng) + ($endOffsetMin * 60);
        } else {
            // Fixed: 18:00 on both edges
            $startTs = $fridayDate->setTime(18, 0, 0)->getTimestamp() + ($startOffsetMin * 60);
            $endTs   = $saturdayDate->setTime(18, 0, 0)->getTimestamp() + ($endOffsetMin * 60);
        }

        return [$startTs, $endTs];
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
