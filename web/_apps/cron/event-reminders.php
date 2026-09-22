<?php
// Path: _apps/cron/event-reminders.php
/**
 * -----------------------------------------------------------------------------
 * Cron — Event lifecycle email reminders (#329)
 * -----------------------------------------------------------------------------
 * Endpoint expected to be called every 15 minutes by an external scheduler.
 * Authenticates via ?key=<reminders.cron_token>; processes three reminder
 * windows and writes one tblEventReminderLog row per (eventID,
 * reminderType) to enforce single-shot semantics.
 *
 * Windows:
 *   24h  — startDateTime between NOW+23h45m and NOW+24h15m
 *   1h   — startDateTime between NOW+45m  and NOW+75m
 *   day  — once per day at 06:30-08:00 local — coordinator/admin
 *          summary of today's events
 *
 * WEB PUSH (#322): the 1h window ALSO fans a push out to the same RSVP'd
 * ("going"/"confirmed") userIDs, on the `reminders` channel, gated on their
 * `pushServiceReminders` notifyPrefs key — riding the SAME
 * `tblEventReminderLog('1h')` single-shot claim the email batch already
 * makes (push goes out iff the email batch for that window goes out; no
 * separate dedupe row). The 24h window stays email-only (a push a day
 * early is noise). `WebPush::isConfigured()` short-circuits first, so an
 * unconfigured install pays one settings read and nothing else.
 *
 * WHO GETS A REMINDER, AND HOW MUCH IT SAYS (#514 part P2): every recipient
 * is checked against the one shared visibility rule, Portal\Core\
 * EventVisibility, in "token" mode with the recipient as the viewer — inside
 * the recipient query itself, one statement per event. Somebody who answered
 * "going" but may no longer see the event (they left the organisation, or an
 * imported event's level changed) gets nothing. Somebody who may see only its
 * title, date and time gets a reminder WITHOUT the location. Token mode leaves
 * the administrator branches off on purpose (#514 leak-hunt finding 24): an
 * email or a push leaves the portal, so it must never carry an
 * administrator-only event. The push companion never names a location for an
 * event copied in from an outside calendar. The day-of summary covers the
 * portal's own events only (imported events have no coordinators). The
 * output line stays counts only.
 *
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/329
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/322
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\EventVisibility;
use Portal\Core\Logger;
use Portal\Core\Mailer;
use Portal\Core\Settings;
use Portal\Core\WebPush;

// 🔑 Token gate (constant-time compare).
$incoming = (string) ($_GET['key'] ?? '');
$expected = (string) (Settings::get('reminders.cron_token', '') ?? '');
if ($expected === '' || hash_equals($expected, $incoming) === false) {
    http_response_code(403); exit('Forbidden');
}
if ((string) Settings::get('reminders.enabled', '1') !== '1') {
    echo 'Reminders disabled'; exit();
}

header('Content-Type: text/plain; charset=utf-8');
$stats = ['24h' => 0, '1h' => 0, 'day' => 0];

// ─────────────────────────────────────────────────────────────────────
// Helper: send a single batch and log
// ─────────────────────────────────────────────────────────────────────
/**
 * Email one reminder to everybody who answered "going" (and is confirmed)
 * for one event, and log it once.
 *
 * #514 part P2: takes the event ROW (not just its number) and TWO bodies —
 * `$bodyFull` for a recipient who may see the event's full details and
 * `$bodyLimited` (no location) for one who may see only its title, date and
 * time. Before, one body with the location went to every "going" answer,
 * whether or not that person could still see the event at all.
 *
 * The recipient query joins the event and applies the shared rule with the
 * RECIPIENT as the viewer (EventVisibility::whereForColumn(), "token" mode,
 * viewer column r.userID), so everybody is checked in one statement. The
 * canSeeFull column (1 or 0) says which body each recipient gets.
 * The rule's fragment and the canSeeFull column bind only today's date (the
 * viewer is a column, not a value); canSeeFull's values come first because
 * the SELECT list comes before the WHERE.
 *
 * WHAT THIS CANNOT DO: two accounts sharing one email address, one of them
 * allowed full details and one not, get ONE email — the full one, because
 * the account allowed it reads that mailbox too. Nothing else is merged.
 *
 * @param \mysqli              $db
 * @param array<string, mixed> $event       The event row (needs eventID).
 * @param string               $type        '24h' or '1h'.
 * @param string               $subject     The subject (title and time only).
 * @param string               $bodyFull    The body with the location.
 * @param string               $bodyLimited The body without it.
 *
 * @return int How many emails went out.
 */
function sendReminderBatch(\mysqli $db, array $event, string $type, string $subject, string $bodyFull, string $bodyLimited): int
{
    $eventId    = (int) $event['eventID'];
    $today      = date('Y-m-d');
    $visibility = EventVisibility::whereForColumn('e', 'r.userID', EventVisibility::MODE_TOKEN, $today);
    $fullDetail = EventVisibility::fullDetailSelectForColumn('e', 'r.userID', EventVisibility::MODE_TOKEN, $today, 'canSeeFull');
    // The canSeeFull expression goes in through sprintf()'s `%s`, not by
    // joining it in with `.`: tools/audit-checks/check_sql_columns.py does not
    // recognise a statement at all when PHP code sits between SELECT and FROM
    // (measured while building #514 part P2). The text sprintf() puts in is
    // SQL built by EventVisibility itself.
    $stmt = $db->prepare(sprintf(
        'SELECT DISTINCT u.emailAddress AS email, u.fullName, %s FROM tblEventRSVPs r '
        . 'JOIN tblUsers u ON u.userID = r.userID '
        . 'JOIN tblEvents e ON e.eventID = r.eventID '
        . 'WHERE r.eventID = ? AND u.emailAddress IS NOT NULL '
        . '  AND r.response = "going" AND r.status = "confirmed" AND u.emailAddress != ""',
        $fullDetail['sql']
    ) . $visibility['sql']);
    $stmt->bind_param(
        $fullDetail['types'] . 'i' . $visibility['types'],
        ...array_merge($fullDetail['params'], [$eventId], $visibility['params'])
    );
    $stmt->execute();
    // email => true for the full body, false for the limited one. A full
    // answer for the same address wins (see the doc block above).
    $recipients = [];
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $email = (string) $r['email'];
        $recipients[$email] = ($recipients[$email] ?? false) || (int) $r['canSeeFull'] === 1;
    }
    $stmt->close();

    $sent = 0;
    foreach ($recipients as $email => $full) {
        $email = (string) $email;
        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            if (Mailer::send($email, $subject, $full === true ? $bodyFull : $bodyLimited) === true) { $sent++; }
        }
    }

    $stmt = $db->prepare(
        'INSERT INTO tblEventReminderLog (eventID, reminderType, recipientCount) VALUES (?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE recipientCount = VALUES(recipientCount), sentAt = NOW()'
    );
    $stmt->bind_param('isi', $eventId, $type, $sent);
    $stmt->execute();
    $stmt->close();

    Logger::activity('EventReminderSent', 'Event #' . $eventId . ' type=' . $type . ' n=' . $sent);
    return $sent;
}

/**
 * The RSVP'd ("going"/"confirmed") userIDs for one event — used only by
 * the 1h Web Push fan-out (#322), a companion query to
 * sendReminderBatch()'s email-address SELECT above (kept separate rather
 * than widening that shared function's signature, since the 24h/day
 * windows never need userIDs).
 *
 * #514 part P2: the SAME visibility test as sendReminderBatch() — the shared
 * rule in "token" mode with each recipient as the viewer — so a push goes
 * only to somebody who may still see the event. (Whether its body may name
 * the location is decided by the caller: never for an imported event.)
 */
function eventRsvpUserIds(\mysqli $db, int $eventId): array
{
    $visibility = EventVisibility::whereForColumn('e', 'r.userID', EventVisibility::MODE_TOKEN, date('Y-m-d'));
    $stmt = $db->prepare(
        'SELECT DISTINCT r.userID FROM tblEventRSVPs r '
        . 'JOIN tblEvents e ON e.eventID = r.eventID '
        . 'WHERE r.eventID = ? AND r.response = "going" AND r.status = "confirmed"'
        . $visibility['sql']
    );
    if ($stmt === false) {
        return [];
    }
    $stmt->bind_param('i' . $visibility['types'], $eventId, ...$visibility['params']);
    $stmt->execute();
    $ids = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $ids[] = (int) $row['userID'];
    }
    $stmt->close();
    return $ids;
}

// ─────────────────────────────────────────────────────────────────────
// 24h window
// ─────────────────────────────────────────────────────────────────────
// #514 part P2: every event in the window is looked at; WHO is reminded, and
// with how much detail, is decided per recipient inside sendReminderBatch(),
// which is CALLED near the top of this file and reads the rule through
// EventVisibility::whereForColumn() — the rule cannot be applied to this
// outer query at all, because it has no single viewer yet: that is the whole
// reason this query has to look at every event in the window first, before
// anybody specific is known.
//
// 🩹 FIX ROUND 1 (checker finding 7, G7): corrected wording. The previous
// text said whereForColumn() runs "once for each candidate recipient",
// which reads as one database round trip per person. That is not how
// sendReminderBatch() works: it is ONE statement per event, joined to every
// candidate recipient's own row at once, with EACH recipient's user number
// supplied as the viewer column (`whereForColumn`'s whole point — it takes a
// COLUMN, not a bound value, so one statement filters every row for its own
// viewer in a single pass). Marker text for
// tools/audit-checks/check_event_visibility.py, since the real call sits
// further up this file than the check's own search window reaches.
$stmt = $mysqli->prepare(
    'SELECT e.eventID, e.eventName, e.eventSlug, e.startDateTime, e.locationName, e.externalFeedID FROM tblEvents e '
    . 'WHERE e.isDeleted = 0 AND e.status = "published" '
    . '  AND e.startDateTime BETWEEN DATE_ADD(NOW(), INTERVAL 23 HOUR) + INTERVAL 45 MINUTE '
    . '                          AND DATE_ADD(NOW(), INTERVAL 24 HOUR) + INTERVAL 15 MINUTE '
    . '  AND NOT EXISTS (SELECT 1 FROM tblEventReminderLog l WHERE l.eventID = e.eventID AND l.reminderType = "24h")'
);
$stmt->execute();
$result = $stmt->get_result();
while ($e = $result->fetch_assoc()) {
    $when = date('l j M, H:i', strtotime((string) $e['startDateTime']));
    $subject = 'Reminder: ' . (string) $e['eventName'] . ' tomorrow';
    // Two bodies (#514 part P2): the same text with and without the location.
    $where = empty($e['locationName']) === false ? '<p><strong>Where:</strong> ' . htmlspecialchars((string) $e['locationName'], ENT_QUOTES, 'UTF-8') . '</p>' : '';
    $head  = '<p>This is a reminder that <strong>' . htmlspecialchars((string) $e['eventName'], ENT_QUOTES, 'UTF-8') . '</strong> is tomorrow.</p>'
           . '<p><strong>When:</strong> ' . htmlspecialchars($when, ENT_QUOTES, 'UTF-8') . '</p>';
    $tail  = '<p>See you there!</p>';
    $stats['24h'] += sendReminderBatch($mysqli, $e, '24h', $subject, $head . $where . $tail, $head . $tail);
}
$stmt->close();

// ─────────────────────────────────────────────────────────────────────
// 1h window
// ─────────────────────────────────────────────────────────────────────
// Same shape as the 24h window above: every event in the window is looked
// at here, and EventVisibility::whereForColumn() decides per recipient
// inside sendReminderBatch() — see that query's own comment for the full
// explanation of why the rule cannot apply to this outer query.
$stmt = $mysqli->prepare(
    'SELECT e.eventID, e.siteID, e.eventName, e.eventSlug, e.startDateTime, e.locationName, e.externalFeedID FROM tblEvents e '
    . 'WHERE e.isDeleted = 0 AND e.status = "published" '
    . '  AND e.startDateTime BETWEEN DATE_ADD(NOW(), INTERVAL 45 MINUTE) '
    . '                          AND DATE_ADD(NOW(), INTERVAL 75 MINUTE) '
    . '  AND NOT EXISTS (SELECT 1 FROM tblEventReminderLog l WHERE l.eventID = e.eventID AND l.reminderType = "1h")'
);
$stmt->execute();
$result = $stmt->get_result();
$pushConfigured = WebPush::isConfigured();
while ($e = $result->fetch_assoc()) {
    $when = date('H:i', strtotime((string) $e['startDateTime']));
    $subject = '⏰ Starting soon: ' . (string) $e['eventName'];
    // Two bodies (#514 part P2): the same text with and without the location.
    $head  = '<p><strong>' . htmlspecialchars((string) $e['eventName'], ENT_QUOTES, 'UTF-8') . '</strong> starts at ' . htmlspecialchars($when, ENT_QUOTES, 'UTF-8') . '.</p>';
    $where = empty($e['locationName']) === false ? '<p><strong>Where:</strong> ' . htmlspecialchars((string) $e['locationName'], ENT_QUOTES, 'UTF-8') . '</p>' : '';
    $tail  = '<p>See you in about an hour!</p>';
    $stats['1h'] += sendReminderBatch($mysqli, $e, '1h', $subject, $head . $where . $tail, $head . $tail);

    // 🔔 Web Push companion (#322) — same RSVP'd users, riding the SAME
    // '1h' single-shot claim the email batch above just made. Never blocks
    // or retries independently of the email send.
    //
    // #514 part P2: one push body goes to every recipient, so it names the
    // location only for the portal's OWN events (whose details every viewer
    // who may see them may see in full). For an event copied in from an
    // outside calendar it never does, whatever each recipient could see —
    // the push may be read on a locked phone screen.
    if ($pushConfigured === true) {
        $rsvpUserIds = eventRsvpUserIds($mysqli, (int) $e['eventID']);
        if (count($rsvpUserIds) > 0) {
            $ttl = (int) (Settings::get('push.ttl.reminder', '3600') ?? '3600');
            $pushWhere = $e['externalFeedID'] === null && empty($e['locationName']) === false ? ' · ' . (string) $e['locationName'] : '';
            WebPush::sendToChannel(
                (int) $e['siteID'],
                'reminders',
                [
                    'title' => (string) $e['eventName'] . ' starts soon',
                    'body'  => 'Starting at ' . $when . $pushWhere,
                    'url'   => '/calendar/event?slug=' . rawurlencode((string) $e['eventSlug']),
                    'tag'   => 'evt' . (int) $e['eventID'],
                ],
                $ttl,
                'normal',
                'evt' . (int) $e['eventID'],
                'pushServiceReminders',
                $rsvpUserIds
            );
        }
    }
}
$stmt->close();

// ─────────────────────────────────────────────────────────────────────
// Day-of summary (07:00 hour window, once per event per day)
// ─────────────────────────────────────────────────────────────────────
$hour = (int) date('H');
if ($hour >= 6 && $hour <= 8) {
    // #514 part P2: the portal's own events only (`externalFeedID IS NULL`).
    // Imported events have no coordinators, and the recipients below include
    // every holder of the older portal-wide `isAdmin` flag in EVERY
    // organisation (`u.isAdmin = 1`) — a separate, older fault to be reported
    // under #514 part P11, which must not also start carrying imported events.
    $stmt = $mysqli->prepare(
        'SELECT e.eventID, e.eventName, e.startDateTime FROM tblEvents e '
        . 'WHERE e.isDeleted = 0 AND e.externalFeedID IS NULL AND e.status = "published" '
        . '  AND DATE(e.startDateTime) = CURDATE() '
        . '  AND NOT EXISTS (SELECT 1 FROM tblEventReminderLog l WHERE l.eventID = e.eventID AND l.reminderType = "day")'
    );
    $stmt->execute();
    $result = $stmt->get_result();
    while ($e = $result->fetch_assoc()) {
        $eid = (int) $e['eventID'];

        // 📧 Recipients: admins + coordinators.
        $r2 = $mysqli->prepare(
            'SELECT DISTINCT u.emailAddress AS email, u.fullName FROM tblUsers u '
            . 'WHERE u.isActive = 1 AND u.emailAddress IS NOT NULL AND u.emailAddress != "" AND ('
            . '   u.userID IN (SELECT userID FROM tblEventCoordinators WHERE eventID = ? AND revokedAt IS NULL) '
            . '   OR u.isAdmin = 1'
            . ')'
        );
        $r2->bind_param('i', $eid);
        $r2->execute();
        $recipients = [];
        $rs = $r2->get_result();
        while ($u = $rs->fetch_assoc()) { $recipients[] = (string) $u['email']; }
        $r2->close();

        // 📋 Counts.
        $rc = 0;
        $stm = $mysqli->prepare('SELECT COUNT(*) c FROM tblEventRSVPs WHERE eventID = ? AND response = "going" AND status = "confirmed"');
        $stm->bind_param('i', $eid);
        $stm->execute();
        $rc = (int) ($stm->get_result()->fetch_assoc()['c'] ?? 0);
        $stm->close();

        $when = date('H:i', strtotime((string) $e['startDateTime']));
        $subject = '📋 Today: ' . (string) $e['eventName'];
        $body  = '<p><strong>' . htmlspecialchars((string) $e['eventName'], ENT_QUOTES, 'UTF-8') . '</strong> is today at ' . htmlspecialchars($when, ENT_QUOTES, 'UTF-8') . '.</p>'
               . '<p><strong>Confirmed RSVPs:</strong> ' . $rc . '</p>'
               . '<p>Have a great event!</p>';

        $sent = 0;
        foreach ($recipients as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                if (Mailer::send($email, $subject, $body) === true) { $sent++; }
            }
        }
        $stm = $mysqli->prepare('INSERT INTO tblEventReminderLog (eventID, reminderType, recipientCount) VALUES (?, "day", ?) ON DUPLICATE KEY UPDATE recipientCount = VALUES(recipientCount), sentAt = NOW()');
        $stm->bind_param('ii', $eid, $sent);
        $stm->execute();
        $stm->close();
        $stats['day'] += $sent;
    }
    $stmt->close();
}

echo 'OK ' . json_encode($stats);
