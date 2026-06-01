<?php
// Path: _core/Livestream.php
/**
 * -----------------------------------------------------------------------------
 * Livestream channel + schedule helpers 🎥
 * -----------------------------------------------------------------------------
 * Resolves the currently-live channel from tblLivestreamSchedule.
 *
 * @package   Portal\Core
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/273
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Livestream
{
    /**
     * Is a channel currently scheduled to be live? Returns the channel row
     * (with embed-ready URL), or null. Site-scoped via tblLivestreamChannel.
     *
     * Schedule semantics: a channel is "live" right now if there's an active
     * schedule entry whose (dayOfWeek, startTime…endTime) covers the current
     * local time in the schedule's configured timezone.
     */
    public static function currentlyLive(int $siteId): ?array
    {
        $db = App::db();
        try {
            $rs = $db->query(
                'SELECT c.channelID, c.name, c.platform, c.channelOrVideoId, c.embedHtmlOverride, '
                . '       s.timezone, s.dayOfWeek, s.startTime, s.endTime '
                . 'FROM tblLivestreamChannel c '
                . 'JOIN tblLivestreamSchedule s ON s.channelID = c.channelID AND s.isActive = 1 '
                . 'WHERE c.siteID = ' . (int) $siteId
            );
            if ($rs === false) {
                return null;
            }
            while ($r = $rs->fetch_assoc()) {
                $tz = (string) ($r['timezone'] ?? 'Europe/London');
                try {
                    $now = new \DateTimeImmutable('now', new \DateTimeZone($tz));
                } catch (\Throwable $e) {
                    $now = new \DateTimeImmutable('now');
                }
                $today = (int) $now->format('w'); // 0=Sun
                if ((int) $r['dayOfWeek'] !== $today) {
                    continue;
                }
                $start = (string) $r['startTime'];
                $end   = (string) $r['endTime'];
                $nowTime = $now->format('H:i:s');
                if ($nowTime >= $start && $nowTime <= $end) {
                    $rs->free();
                    return $r;
                }
            }
            $rs->free();
        } catch (\Throwable $ignored) {
            // tblLivestream* missing → app not installed.
        }
        return null;
    }

    /**
     * Return the next scheduled stream after the given time (default now)
     * for countdown / "next: Saturday 11:00" rendering.
     */
    public static function nextScheduled(int $siteId, ?\DateTimeImmutable $after = null): ?array
    {
        $db = App::db();
        $after ??= new \DateTimeImmutable('now');
        $best = null;
        try {
            $rs = $db->query(
                'SELECT c.channelID, c.name, c.platform, s.timezone, s.dayOfWeek, s.startTime '
                . 'FROM tblLivestreamChannel c '
                . 'JOIN tblLivestreamSchedule s ON s.channelID = c.channelID AND s.isActive = 1 '
                . 'WHERE c.siteID = ' . (int) $siteId
            );
            if ($rs === false) {
                return null;
            }
            while ($r = $rs->fetch_assoc()) {
                $tz = (string) ($r['timezone'] ?? 'Europe/London');
                try {
                    $tzObj = new \DateTimeZone($tz);
                } catch (\Throwable $e) {
                    $tzObj = new \DateTimeZone('UTC');
                }
                // Find next occurrence of dayOfWeek + startTime.
                $today = (int) $after->setTimezone($tzObj)->format('w');
                $target = (int) $r['dayOfWeek'];
                $daysAhead = ($target - $today + 7) % 7;
                $candidateDate = $after->setTimezone($tzObj)->modify('+' . $daysAhead . ' days');
                $candidate = \DateTimeImmutable::createFromFormat(
                    'Y-m-d H:i:s',
                    $candidateDate->format('Y-m-d') . ' ' . $r['startTime'],
                    $tzObj
                );
                if ($candidate === false) {
                    continue;
                }
                if ($candidate <= $after) {
                    $candidate = $candidate->modify('+7 days');
                }
                if ($best === null || $candidate < $best['when']) {
                    $best = ['when' => $candidate, 'row' => $r];
                }
            }
            $rs->free();
        } catch (\Throwable $ignored) {
            return null;
        }
        return $best !== null ? array_merge($best['row'], ['nextAt' => $best['when']->format('c')]) : null;
    }

    /**
     * Build a safe embed iframe URL for the configured platform + channel/video ID.
     * Returns null for unrecognised platforms.
     */
    public static function embedUrl(string $platform, string $channelOrVideoId): ?string
    {
        $id = preg_replace('/[^A-Za-z0-9_\-]/', '', $channelOrVideoId);
        if ($id === '' || $id === null) {
            return null;
        }
        return match ($platform) {
            'youtube'        => 'https://www.youtube.com/embed/' . $id . '?autoplay=1&modestbranding=1',
            'youtube-live'   => 'https://www.youtube.com/embed/live_stream?channel=' . $id,
            'vimeo'          => 'https://player.vimeo.com/video/' . $id,
            'twitch'         => 'https://player.twitch.tv/?channel=' . $id . '&parent=' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'),
            'facebook'       => 'https://www.facebook.com/plugins/video.php?href=https%3A%2F%2Fwww.facebook.com%2Fvideo.php%3Fv%3D' . $id,
            default          => null,
        };
    }
}
