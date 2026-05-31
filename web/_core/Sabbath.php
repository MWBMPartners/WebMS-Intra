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
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Sabbath
{
    /**
     * Is "now" within the Sabbath quiet window?
     *
     * @param int|null $userId  if provided, honours per-user override.
     */
    public static function isQuietNow(?int $userId = null, ?int $now = null): bool
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
        [$startTs, $endTs] = self::computeWindow($now);

        return $now >= $startTs && $now <= $endTs;
    }

    /**
     * Compute the Sabbath window containing or surrounding the given time.
     *
     * @return array{0:int, 1:int}  [start_ts, end_ts]
     */
    public static function computeWindow(int $referenceTs): array
    {
        $settings = App::settings();
        $method = (string) ($settings['portal']['sabbath']['method'] ?? 'fixed');
        $tz     = (string) ($settings['portal']['sabbath']['timezone'] ?? 'Europe/London');
        $startOffsetMin = (int) ($settings['portal']['sabbath']['start_offset_minutes'] ?? 0);
        $endOffsetMin   = (int) ($settings['portal']['sabbath']['end_offset_minutes'] ?? 0);

        try {
            $tzObj = new \DateTimeZone($tz);
        } catch (\Throwable $e) {
            $tzObj = new \DateTimeZone('UTC');
        }

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
