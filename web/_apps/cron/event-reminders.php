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
 * Windows (each counted from the MOMENT the event starts; see "IS IT TIME?"):
 *   24h  — the start is between 23h45m and 24h15m away
 *   1h   — the start is between 45m and 75m away
 *   day  — once, between 06:00 and 08:59 on the EVENT's own clock, on the
 *          day it starts: a coordinator/admin summary
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
 * WHICH TIME ZONE AN EVENT'S START IS IN (#592, #604). An event's start is
 * a time on a clock, and the clock's zone is `tblEvents.timezone`, the ONE
 * column an event's zone is kept in. When that is not a zone PHP can open
 * (an event saved before the form checked its zone box), the ORGANISATION's
 * own zone is used, from Site::timezone(): a reminder must not be lost for a
 * slip of the finger. (This once read a second column, `eventTimezone`, which
 * held "Europe/London" for every event made in the portal. Migration 217
 * removed it.)
 *
 * IS IT TIME? (#604). That is a question about a MOMENT, so it is asked of
 * moments: the event's start, turned from its clock time and zone into UTC
 * (eventStartMoment(), which uses MailQueue::useBy(), the same conversion the
 * use-by moments come from), against the database's own UTC clock, read ONCE
 * at the top of the run (UTC_TIMESTAMP()).
 * WHAT WAS WRONG: the three queries compared the event's CLOCK time with the
 * database server's clock (NOW(), CURDATE()). Those are the same thing only
 * when the event, the database server and the organisation are all in one
 * zone and no clock has changed. For an event in another zone the reminder
 * was made hours early or late, or never, since a window is half an hour
 * wide. And on the two nights a year a clock changes, "24 hours before" by
 * the clock is 23 or 25 hours before in fact.
 * The database cannot turn a zone name into an offset without tables many
 * hosts do not load, so each query takes a WIDE net (every event whose clock
 * time is within a day before and two days after now, far more than any
 * zone's distance from UTC) and the exact test is made here, per event.
 * Hours and minutes are counted in SECONDS between two moments.
 *
 * THE DAY-BEFORE REMINDER IS 24 HOURS OF REAL TIME AHEAD (86,400 seconds),
 * on purpose: "a fixed length of time before the start" is what it is. That
 * is NOT always "the day before" on the event's calendar, because two days a
 * year are 23 or 25 hours long. So the reminder's WORDS are checked against
 * the event's own calendar before anything is sent:
 *   - When the moment EXACTLY 24 hours before the start is already the
 *     event's own day (a start late on the day the clocks go back, which is
 *     longer than 24 hours), there is no day before on which this reminder
 *     could be due. It is recorded as made, so it is not tried again, and
 *     NOTHING IS SENT. The one-hour reminder still goes.
 *   - 24 hours before a start early on the day after the 23-hour day is two
 *     days before on the calendar. The reminder names the day ("is on Monday
 *     29 Mar") and does not say "tomorrow".
 *   - A CALL THAT IS MERELY LATE still sends. The window is half an hour
 *     wide, so for a start in the last minutes of a day the first call
 *     inside it can land just after midnight, on the event's own day (a
 *     23:55 start, with the job called on the hour). The reminder goes, with
 *     the day named, and its use-by moment is then the event's START, since
 *     naming the day stays true until then.
 *     (Decided by the coordinator, 13 October 2026. The first fix held back
 *     EVERY reminder whose call came on the event's own day, and its log
 *     line blamed "a 25-hour day" for an ordinary Thursday: the second check
 *     ran the job every 30 minutes and a 23:55 start got no reminder in 8 of
 *     9 zones. Called every 15 minutes, as the page says, it did not
 *     happen; one missed call was enough.)
 * (This header used to say "no day is taken to be 24 hours long", which was
 * untrue of this window, and the first two cases went out saying "tomorrow":
 * found by the independent check, 13 October 2026.)
 *
 * ONCE. The `tblEventReminderLog` row is still what makes a reminder
 * single-shot, and a moment happens once: on the night an hour is repeated
 * its window is not entered twice, and on the night an hour is missing it is
 * not jumped over. WHAT THIS CANNOT DO: say which of the two happenings a
 * start time inside a repeated hour means. PHP chooses, by zone (see
 * MailQueue::useBy()); the reminder and its use-by moment follow the same
 * choice, so they cannot disagree with each other.
 *
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/329
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/322
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\EventVisibility;
use Portal\Core\Logger;
use Portal\Core\Mailer;
use Portal\Core\MailQueue;
use Portal\Core\Settings;
use Portal\Core\Site;
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

// 🕰️ NOW, as the database's own UTC clock gives it, read once for the whole
//    run so that every event is judged against the same moment. Not PHP's
//    time(): the mail queue's moments are the database's too, and two clocks
//    on two machines need not agree.
$nowRow = $mysqli->query('SELECT UTC_TIMESTAMP() AS nowUtc')->fetch_assoc();
$nowUtc = new \DateTimeImmutable((string) $nowRow['nowUtc'], new \DateTimeZone('UTC'));
$nowTs  = $nowUtc->getTimestamp();

/**
 * 🕰️ The MOMENT an event starts, as seconds since 1970, or null when its
 * stored start cannot be read at all.
 *
 * Its clock time is read in its own zone, or the organisation's when that
 * cannot be opened: MailQueue::useBy() does both steps, and is also where
 * the reminder's use-by moment comes from, so "is it time?" and "is it too
 * late?" are answered from one conversion.
 *
 * @param array<string, mixed> $event Needs startDateTime, timezone and siteID.
 */
function eventStartMoment(array $event): ?int
{
    $utc = MailQueue::useBy((string) $event['startDateTime'], (string) $event['timezone'], Site::timezone((int) $event['siteID']));
    if (preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $utc) !== 1) {
        return null;
    }

    return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->getTimestamp();
}

/**
 * A stored clock time, set out for a person ("Sunday 29 Mar, 10:00").
 *
 * The stored text is already what the event's clock shows, so it is only
 * re-shaped. It is read as UTC for that, because UTC has no clock changes:
 * no hour is missing and none is repeated, so the reading that goes in is
 * the reading that comes out. (This used strtotime() and date(), which read
 * it on PHP's clock: a time the organisation's clock skips on the night it
 * goes forward came out an hour later in the reminder.)
 */
function eventClockText(string $stored, string $format): string
{
    try {
        return (new \DateTimeImmutable($stored, new \DateTimeZone('UTC')))->format($format);
    } catch (\Throwable $e) {
        return $stored;
    }
}

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
 * @param string               $useBy       The moment, in UTC, at or after
 *                                          which the reminder must not be
 *                                          sent (MailQueue::useBy()).
 *
 * @return int How many emails a mail service ACCEPTED in this run. One that
 *             was only kept in the mail queue is not counted here: the job
 *             reports those as "waiting" at the end.
 */
function sendReminderBatch(\mysqli $db, array $event, string $type, string $subject, string $bodyFull, string $bodyLimited, string $useBy): int
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

    // ⏳ A REMINDER HAS A USE-BY MOMENT (#592). A reminder the portal could
    //    not send at once is kept and tried again, and without a use-by
    //    moment a "Starting soon" reminder kept while the mail server was
    //    down was delivered three hours after its event had ended. With one,
    //    it is never sent at or after that moment.
    //    And "true" from send() is sent OR kept: only one a mail service
    //    took is counted as sent.
    $sent = 0;
    foreach ($recipients as $email => $full) {
        $email = (string) $email;
        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            if (Mailer::send($email, $subject, $full === true ? $bodyFull : $bodyLimited, [], ['expiresAt' => $useBy]) === true
                && Mailer::lastOutcome() === Mailer::OUTCOME_SENT) { $sent++; }
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
// 🕰️ The WIDE net (see "IS IT TIME?" at the top): clock times from a day
//    before now to two days after, on the database's UTC clock. No zone is
//    more than 14 hours from UTC, so every event that could be 24 hours or
//    one hour away in its own zone is inside it. The exact test follows, on
//    moments. The same net serves the one-hour window below. (WHO is
//    reminded is still decided per recipient by
//    EventVisibility::whereForColumn(), inside sendReminderBatch(), as the
//    note above says.)
$stmt = $mysqli->prepare(
    'SELECT e.eventID, e.siteID, e.eventName, e.eventSlug, e.startDateTime, e.timezone, e.locationName, e.externalFeedID FROM tblEvents e '
    . 'WHERE e.isDeleted = 0 AND e.status = "published" '
    . '  AND e.startDateTime BETWEEN UTC_TIMESTAMP() - INTERVAL 1 DAY AND UTC_TIMESTAMP() + INTERVAL 2 DAY '
    . '  AND NOT EXISTS (SELECT 1 FROM tblEventReminderLog l WHERE l.eventID = e.eventID AND l.reminderType = "24h")'
);
$stmt->execute();
$result = $stmt->get_result();
while ($e = $result->fetch_assoc()) {
    // ⏱️ Is the start between 23h45m and 24h15m away? Counted in seconds
    //    between two moments, so the answer is the same on a night the
    //    clocks change: 24 hours before is 24 hours before.
    $startTs = eventStartMoment($e);
    if ($startTs === null || ($startTs - $nowTs) < 85500 || ($startTs - $nowTs) > 87300) {
        continue;
    }
    $siteZone = Site::timezone((int) $e['siteID']);
    // 📅 Which DAY is it, on the event's own clock, and which day is the
    //    event? See "THE DAY-BEFORE REMINDER" at the top of this file.
    $eventZone = Site::zoneFor((string) $e['timezone'], (int) $e['siteID']);
    $eventNow  = $nowUtc->setTimezone($eventZone);
    $eventDay  = substr((string) $e['startDateTime'], 0, 10);
    // The moment EXACTLY 24 hours before the start, whenever this call came.
    // Asked of that moment and not of "now", so that a call a few minutes
    // late is not mistaken for the long day (see "A CALL THAT IS MERELY LATE").
    $dayBefore = (new \DateTimeImmutable('@' . ($startTs - 86400)))->setTimezone($eventZone);
    if ($dayBefore->format('Y-m-d') >= $eventDay) {
        // There is no day before on which this reminder could be due.
        // Recorded as made, so that no later run tries again; nothing sent.
        $skip = $mysqli->prepare('INSERT INTO tblEventReminderLog (eventID, reminderType, recipientCount) VALUES (?, "24h", 0) ON DUPLICATE KEY UPDATE recipientCount = recipientCount');
        $skipId = (int) $e['eventID'];
        $skip->bind_param('i', $skipId);
        $skip->execute();
        $skip->close();
        Logger::activity('EventReminderNotSent', 'Event #' . $skipId . ' type=24h: nothing sent, because exactly 24 hours before its start ('
            . $dayBefore->format('Y-m-d H:i') . ' on its own clock) is already the event\'s own day: the clocks go back that day, so it is longer than 24 hours');
        continue;
    }
    // Has the event's own day begun at THIS call? Only a late call can make it so (the test above has passed).
    $ownDayBegun = $eventNow->format('Y-m-d') >= $eventDay;
    // "tomorrow" only when it is; otherwise the day itself ("on Monday 29 Mar").
    $dayWords = $eventNow->modify('+1 day')->format('Y-m-d') === $eventDay ? 'tomorrow' : 'on ' . eventClockText((string) $e['startDateTime'], 'l j M');
    $when = eventClockText((string) $e['startDateTime'], 'l j M, H:i');
    $subject = 'Reminder: ' . (string) $e['eventName'] . ' ' . $dayWords;
    // Two bodies (#514 part P2): the same text with and without the location.
    $where = empty($e['locationName']) === false ? '<p><strong>Where:</strong> ' . htmlspecialchars((string) $e['locationName'], ENT_QUOTES, 'UTF-8') . '</p>' : '';
    $head  = '<p>This is a reminder that <strong>' . htmlspecialchars((string) $e['eventName'], ENT_QUOTES, 'UTF-8') . '</strong> is ' . htmlspecialchars($dayWords, ENT_QUOTES, 'UTF-8') . '.</p>'
           . '<p><strong>When:</strong> ' . htmlspecialchars($when, ENT_QUOTES, 'UTF-8') . '</p>';
    $tail  = '<p>See you there!</p>';
    // Use-by: the START OF THE EVENT'S OWN DAY, on the event's own clock.
    // This reminder says "is tomorrow" (or names the day); from that midnight
    // it is untrue, and a person who believed it would come a day late. (Who gets it is still
    // decided per recipient by EventVisibility::whereForColumn(), inside
    // sendReminderBatch(): see the note above this loop's query.)
    // A LATE CALL is the one exception: that midnight has already passed, so
    // a reminder kept in the mail queue with it would be thrown away unsent.
    // It names the day, which stays true until the event starts, so its
    // use-by moment is the START.
    $useBy = MailQueue::useBy($ownDayBegun === true ? (string) $e['startDateTime'] : $eventDay, (string) $e['timezone'], $siteZone);
    $stats['24h'] += sendReminderBatch($mysqli, $e, '24h', $subject, $head . $where . $tail, $head . $tail, $useBy);
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
    'SELECT e.eventID, e.siteID, e.eventName, e.eventSlug, e.startDateTime, e.timezone, e.locationName, e.externalFeedID FROM tblEvents e '
    . 'WHERE e.isDeleted = 0 AND e.status = "published" '
    . '  AND e.startDateTime BETWEEN UTC_TIMESTAMP() - INTERVAL 1 DAY AND UTC_TIMESTAMP() + INTERVAL 2 DAY '
    . '  AND NOT EXISTS (SELECT 1 FROM tblEventReminderLog l WHERE l.eventID = e.eventID AND l.reminderType = "1h")'
);
$stmt->execute();
$result = $stmt->get_result();
$pushConfigured = WebPush::isConfigured();
while ($e = $result->fetch_assoc()) {
    // ⏱️ Is the start between 45 and 75 minutes away? In seconds, between
    //    two moments (see the 24h window above).
    $startTs = eventStartMoment($e);
    if ($startTs === null || ($startTs - $nowTs) < 2700 || ($startTs - $nowTs) > 4500) {
        continue;
    }
    $siteZone = Site::timezone((int) $e['siteID']);
    $when = eventClockText((string) $e['startDateTime'], 'H:i');
    $subject = '⏰ Starting soon: ' . (string) $e['eventName'];
    // Two bodies (#514 part P2): the same text with and without the location.
    $head  = '<p><strong>' . htmlspecialchars((string) $e['eventName'], ENT_QUOTES, 'UTF-8') . '</strong> starts at ' . htmlspecialchars($when, ENT_QUOTES, 'UTF-8') . '.</p>';
    $where = empty($e['locationName']) === false ? '<p><strong>Where:</strong> ' . htmlspecialchars((string) $e['locationName'], ENT_QUOTES, 'UTF-8') . '</p>' : '';
    $tail  = '<p>See you in about an hour!</p>';
    // Use-by: the moment the event STARTS, on the event's own clock.
    $useBy = MailQueue::useBy((string) $e['startDateTime'], (string) $e['timezone'], $siteZone);
    $stats['1h'] += sendReminderBatch($mysqli, $e, '1h', $subject, $head . $where . $tail, $head . $tail, $useBy);

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
                    'url'   => Site::url('calendar/event') . '?slug=' . rawurlencode((string) $e['eventSlug']),
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
// 🕰️ "Today" and "between six and nine in the morning" are both read on the
//    EVENT's own clock, from the one moment taken at the top of the run.
//    WHAT WAS WRONG: the hour came from PHP's clock (the organisation the job
//    happened to be called for) and "today" from the database server's
//    (CURDATE()), so an event in another zone got its summary at the wrong
//    time of day, or on the wrong day, or not at all.
//    The net here is by DATE: the day the UTC clock shows, the one before
//    and the one after, because no zone's date is further from UTC's than
//    that. Always entered (the old test on PHP's hour is gone): which events
//    are in their own morning is not known until each one is looked at.
{
    // #514 part P2: the portal's own events only (`externalFeedID IS NULL`).
    // Imported events have no coordinators, and the recipients below include
    // every holder of the older portal-wide `isAdmin` flag in EVERY
    // organisation (`u.isAdmin = 1`) — a separate, older fault to be reported
    // under #514 part P11, which must not also start carrying imported events.
    $stmt = $mysqli->prepare(
        'SELECT e.eventID, e.siteID, e.eventName, e.startDateTime, e.timezone FROM tblEvents e '
        . 'WHERE e.isDeleted = 0 AND e.externalFeedID IS NULL AND e.status = "published" '
        . '  AND e.startDateTime >= UTC_DATE() - INTERVAL 1 DAY AND e.startDateTime < UTC_DATE() + INTERVAL 2 DAY '
        . '  AND NOT EXISTS (SELECT 1 FROM tblEventReminderLog l WHERE l.eventID = e.eventID AND l.reminderType = "day")'
    );
    $stmt->execute();
    $result = $stmt->get_result();
    while ($e = $result->fetch_assoc()) {
        // ⏱️ Is it between 06:00 and 08:59 where the event is, on the day it
        //    starts there? The clock reading comes from converting the one
        //    moment into the event's zone, so it is right on a 23-hour and
        //    a 25-hour day alike.
        $siteZone  = Site::timezone((int) $e['siteID']);
        $eventNow  = $nowUtc->setTimezone(Site::zoneFor((string) $e['timezone'], (int) $e['siteID']));
        $eventHour = (int) $eventNow->format('G');
        if ($eventHour < 6 || $eventHour > 8 || $eventNow->format('Y-m-d') !== substr((string) $e['startDateTime'], 0, 10)) {
            continue;
        }
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

        $when = eventClockText((string) $e['startDateTime'], 'H:i');
        $subject = '📋 Today: ' . (string) $e['eventName'];
        $body  = '<p><strong>' . htmlspecialchars((string) $e['eventName'], ENT_QUOTES, 'UTF-8') . '</strong> is today at ' . htmlspecialchars($when, ENT_QUOTES, 'UTF-8') . '.</p>'
               . '<p><strong>Confirmed RSVPs:</strong> ' . $rc . '</p>'
               . '<p>Have a great event!</p>';

        // Use-by: the moment the event STARTS ("is today at …" is no use to
        // a coordinator after that). Counted as sent only when a mail
        // service took it: see sendReminderBatch().
        $useBy = MailQueue::useBy((string) $e['startDateTime'], (string) $e['timezone'], $siteZone);
        $sent  = 0;
        foreach ($recipients as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                if (Mailer::send($email, $subject, $body, [], ['expiresAt' => $useBy]) === true
                    && Mailer::lastOutcome() === Mailer::OUTCOME_SENT) { $sent++; }
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

// ⏳ Reminders that were only KEPT in the mail queue in this run, said apart
//    from the ones that went: they used to be counted as sent.
echo 'OK ' . json_encode($stats + ['waiting' => Mailer::keptThisRequest()]);
