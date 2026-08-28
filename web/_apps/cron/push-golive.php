<?php
// Path: _apps/cron/push-golive.php
/**
 * -----------------------------------------------------------------------------
 * Cron — Web Push auto go-live + service-starting-soon broadcast 🔔📡 (#322)
 * -----------------------------------------------------------------------------
 * Endpoint expected to be called every 5 minutes by an external scheduler.
 * Two INDEPENDENT, BOTH DEFAULT-OFF opt-in escalations layered on top of the
 * primary "we're live now" trigger (the manual button on `/admin/livestream`
 * and the Host Console — see admin/livestream/index.php's `notify_live`
 * action, which calls WebPush::sendToChannel() directly, no cron involved):
 *
 *   auto-go-live      — `push.golive.auto` (per-site). If
 *                       `Livestream::currentlyLive($siteId)` reports a live
 *                       channel, auto-fire the SAME "livestream" channel
 *                       blast the manual button sends. Dedupe: once per
 *                       CHANNEL per DAY (`tblUserReminderLog`,
 *                       refType='push-golive', refID=channelID, dueDate=
 *                       today in the schedule's own timezone) — a channel
 *                       with two windows in one day needs the manual
 *                       button for the second window (documented in
 *                       DEV_NOTES "Web Push setup").
 *   starting-soon     — `push.reminders.broadcast` (per-site). If
 *                       `Livestream::nextScheduled($siteId)` starts within
 *                       60 minutes, broadcast to the WHOLE "reminders"
 *                       channel (no onlyUserIds) — the anonymous-viewer
 *                       counterpart to cron/event-reminders.php's per-RSVP
 *                       1h push (which only reaches logged-in RSVP'd
 *                       users). Dedupe: once per channel per day
 *                       (refType='push-service-1h').
 *
 * TOKEN GATE: `?key=` vs `push.cron_token`, constant-time `hash_equals()`,
 * empty stored token ⇒ ALWAYS 403 — migration 175 seeds `push.cron_token`
 * empty + isSensitive=1, so this endpoint is inert until an admin sets a
 * real token. A DEDICATED token (not `reminders.cron_token` /
 * `user_reminders.cron_token`) keeps rotation independent — the six-of-six
 * convention (171_user_reminders.sql's own header).
 *
 * Also inert whenever `Portal\Core\WebPush::isConfigured()` is false (no
 * VAPID keys set) — sendToChannel() checks this itself and no-ops, so an
 * unconfigured install's cron run is a harmless, cheap per-site no-op.
 *
 * MULTI-SITE: loops every distinct active site (`tblSites.isActive = 1`),
 * `App::settingForSite()` read INSIDE the loop (venue-cron discipline —
 * cron/venue-reminders.php / cron/user-reminders.php precedent) so a
 * per-site override on either toggle takes effect without a code change.
 *
 * @package   Portal\Apps\Cron
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/322
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Livestream;
use Portal\Core\Logger;
use Portal\Core\Settings;
use Portal\Core\Site;
use Portal\Core\WebPush;

// -----------------------------------------------------------------------------
// 🔑 Token gate (constant-time compare). Empty stored token ALWAYS 403s.
// -----------------------------------------------------------------------------
$incoming = (string) ($_GET['key'] ?? '');
$expected = (string) (Settings::get('push.cron_token', '') ?? '');
if ($expected === '' || hash_equals($expected, $incoming) === false) {
    http_response_code(403);
    exit('Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');

$db = App::db();

/**
 * Single-shot dedupe check — mirrors userReminderAlreadySent() in
 * cron/user-reminders.php exactly, against the same tblUserReminderLog
 * table (migration 171's documented reserved purpose).
 */
function pushGoliveAlreadySent(\mysqli $db, string $refType, int $refId, string $dueDate): bool
{
    $stmt = $db->prepare('SELECT 1 FROM tblUserReminderLog WHERE refType = ? AND refID = ? AND dueDate = ? LIMIT 1');
    if ($stmt === false) {
        return false;
    }
    $stmt->bind_param('sis', $refType, $refId, $dueDate);
    $stmt->execute();
    $already = $stmt->get_result()->fetch_assoc() !== null;
    $stmt->close();
    return $already;
}

/**
 * Claim the dedupe row. recipientCount is logged even when 0 (sendToChannel
 * found no active subscriptions) so a channel with no subscribers doesn't
 * retry every 5 minutes for the rest of its live window. Race-caught
 * exactly like logUserReminder() — the uq_usrrem_ref UNIQUE key is the
 * concurrency backstop, never a fatal error.
 */
function claimPushGolive(\mysqli $db, int $siteId, string $refType, int $refId, string $dueDate, int $recipientCount): void
{
    try {
        $stmt = $db->prepare(
            'INSERT INTO tblUserReminderLog (siteID, refType, refID, dueDate, recipientCount) VALUES (?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('isisi', $siteId, $refType, $refId, $dueDate, $recipientCount);
        $stmt->execute();
        $stmt->close();
    } catch (\mysqli_sql_exception $e) {
        error_log('claimPushGolive() duplicate race: ' . $e->getMessage());
    }
}

/** "Today" in the schedule's own timezone, matching Livestream::currentlyLive()'s own tz resolution. */
function todayInTz(string $tz): string
{
    try {
        return (new \DateTimeImmutable('now', new \DateTimeZone($tz)))->format('Y-m-d');
    } catch (\Throwable $e) {
        return date('Y-m-d');
    }
}

// -----------------------------------------------------------------------------
// 🌍 Every distinct, active site.
// -----------------------------------------------------------------------------
$siteIds = [];
$siteStmt = $db->prepare('SELECT siteID FROM tblSites WHERE isActive = 1');
if ($siteStmt !== false) {
    $siteStmt->execute();
    $result = $siteStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $siteIds[] = (int) $row['siteID'];
    }
    $siteStmt->close();
}

$totals = ['golive' => 0, 'starting-soon' => 0, 'skipped' => 0];

foreach ($siteIds as $siteId) {
    try {
        Site::forceContext($siteId);
    } catch (\Throwable $e) {
        Logger::errorPlatform('PushGolive', 'Warning', 'PUSH_GOLIVE_SITE_CONTEXT_FAIL', $e->getMessage(), 'siteID=' . $siteId);
        continue;
    }

    if (WebPush::isConfigured() === false) {
        $totals['skipped']++;
        continue;
    }

    // -------------------------------------------------------------------
    // 📣 Auto go-live (push.golive.auto, default OFF).
    // -------------------------------------------------------------------
    $autoGolive = (string) (App::settingForSite('push.golive.auto', $siteId) ?? 'false');
    if ($autoGolive === 'true') {
        $live = Livestream::currentlyLive($siteId);
        if ($live !== null) {
            $channelId = (int) $live['channelID'];
            $dueDate   = todayInTz((string) ($live['timezone'] ?? 'Europe/London'));
            if (pushGoliveAlreadySent($db, 'push-golive', $channelId, $dueDate) === false) {
                $ttl = (int) (App::settingForSite('push.ttl.golive', $siteId) ?? '900');
                $stats = WebPush::sendToChannel(
                    $siteId,
                    'livestream',
                    [
                        'title' => (string) (App::settingForSite('site.name', $siteId) ?? 'Portal') . ' is live now',
                        'body'  => (string) ($live['name'] ?? 'Livestream'),
                        'url'   => '/live',
                        'tag'   => 'golive',
                    ],
                    $ttl,
                    'high',
                    'golive' . $siteId,
                    'pushLivestream'
                );
                claimPushGolive($db, $siteId, 'push-golive', $channelId, $dueDate, $stats['sent']);
                $totals['golive'] += $stats['sent'];
                Logger::activity('PushGoliveAuto', 'site=' . $siteId . ' channel=' . $channelId . ' sent=' . $stats['sent'] . ' failed=' . $stats['failed'] . ' pruned=' . $stats['pruned']);
            }
        }
    }

    // -------------------------------------------------------------------
    // ⏳ Anonymous "starting soon" broadcast (push.reminders.broadcast, default OFF).
    // -------------------------------------------------------------------
    $broadcastOn = (string) (App::settingForSite('push.reminders.broadcast', $siteId) ?? 'false');
    if ($broadcastOn === 'true') {
        $next = Livestream::nextScheduled($siteId);
        if ($next !== null) {
            $nextAt = strtotime((string) $next['nextAt']);
            if ($nextAt !== false) {
                $minutesUntil = (int) floor(($nextAt - time()) / 60);
                if ($minutesUntil >= 0 && $minutesUntil <= 60) {
                    $channelId = (int) $next['channelID'];
                    $dueDate   = todayInTz((string) ($next['timezone'] ?? 'Europe/London'));
                    if (pushGoliveAlreadySent($db, 'push-service-1h', $channelId, $dueDate) === false) {
                        $ttl = (int) (App::settingForSite('push.ttl.reminder', $siteId) ?? '3600');
                        $stats = WebPush::sendToChannel(
                            $siteId,
                            'reminders',
                            [
                                'title' => (string) ($next['name'] ?? 'Service') . ' starts soon',
                                'body'  => 'Starting at ' . date('H:i', $nextAt),
                                'url'   => '/live',
                                'tag'   => 'evt' . $channelId,
                            ],
                            $ttl,
                            'normal',
                            'servicesoon' . $siteId
                        );
                        claimPushGolive($db, $siteId, 'push-service-1h', $channelId, $dueDate, $stats['sent']);
                        $totals['starting-soon'] += $stats['sent'];
                        Logger::activity('PushServiceSoonBroadcast', 'site=' . $siteId . ' channel=' . $channelId . ' sent=' . $stats['sent'] . ' failed=' . $stats['failed'] . ' pruned=' . $stats['pruned']);
                    }
                }
            }
        }
    }
}

echo 'OK ' . json_encode($totals);
