<?php
// Path: _apps/calendar/event-broadcast-send.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Event broadcast send handler (#350)
 * -----------------------------------------------------------------------------
 * POST. Resolves the segment to a recipient userID list, fetches emails,
 * records one tblEventBroadcasts row, and only then sends: every message is
 * kept in the mail queue first and the queue sends the first few at once
 * (#592, part 3). A second press of Send within ten minutes sends nothing.
 * The reasons are beside the code.
 *
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/350
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Mailer;
use Portal\Core\MailQueue;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . Site::url('calendar'), true, 302); exit();
}

Auth::ensureSession();
Auth::requireLogin();
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400); exit('Bad request');
}

$eventId = (int) ($_POST['eventID'] ?? 0);
$segment = (string) ($_POST['segment'] ?? '');
$subject = mb_substr(trim((string) ($_POST['subject'] ?? '')), 0, 255);
$body    = trim((string) ($_POST['body'] ?? ''));
$senderId = (int) ($_SESSION['user_id'] ?? 0);
$siteId   = Site::id();

if ($eventId <= 0 || (App::isAdmin() === false && Auth::isCoordinatorOf($eventId) === false)) {
    http_response_code(403); exit('Forbidden');
}
if ($subject === '' || $body === '') {
    $_SESSION['flash_msg']  = 'Subject and message are required.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('calendar/event/broadcast') . '?eventID=' . $eventId, true, 302);
    exit();
}

// 🛡️ Confirm event belongs to this site.
// Imported events are read-only (#514 D5); this makes an imported event exactly as "not found" as a missing one.
$stmt = $mysqli->prepare('SELECT eventID, eventName FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0 AND externalFeedID IS NULL');
$stmt->bind_param('ii', $eventId, $siteId);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();
if ($event === null) { http_response_code(404); exit('Event not found'); }

// 📋 Resolve segment → SELECT statement returning DISTINCT (email, fullName).
$query = null;
$params = [];
$types  = '';

if ($segment === 'all-rsvps') {
    $query = 'SELECT DISTINCT u.emailAddress AS email, u.fullName FROM tblEventRSVPs r '
           . 'JOIN tblUsers u ON u.userID = r.userID '
           . 'WHERE r.eventID = ? AND r.response = "going" AND r.status = "confirmed" '
           . '  AND u.emailAddress IS NOT NULL AND u.emailAddress != ""';
    $params = [$eventId]; $types = 'i';
} elseif ($segment === 'all-volunteers') {
    $query = 'SELECT DISTINCT u.emailAddress AS email, u.fullName FROM tblEventCrewMembers m '
           . 'JOIN tblEventCrews c ON c.crewID = m.crewID '
           . 'JOIN tblUsers u ON u.userID = m.userID '
           . 'WHERE c.eventID = ? AND u.emailAddress IS NOT NULL AND u.emailAddress != "" '
           . 'UNION '
           . 'SELECT DISTINCT u.emailAddress AS email, u.fullName FROM tblEventJobAssignments a '
           . 'JOIN tblEventJobs j ON j.jobID = a.jobID '
           . 'JOIN tblUsers u ON u.userID = a.userID '
           . 'WHERE j.eventID = ? AND u.emailAddress IS NOT NULL AND u.emailAddress != ""';
    $params = [$eventId, $eventId]; $types = 'ii';
} elseif (preg_match('/^crew:(\d+)$/', $segment, $m) === 1) {
    $crewId = (int) $m[1];
    $query = 'SELECT DISTINCT u.emailAddress AS email, u.fullName FROM tblEventCrewMembers cm '
           . 'JOIN tblEventCrews c ON c.crewID = cm.crewID '
           . 'JOIN tblUsers u ON u.userID = cm.userID '
           . 'WHERE c.eventID = ? AND cm.crewID = ? AND u.emailAddress IS NOT NULL AND u.emailAddress != ""';
    $params = [$eventId, $crewId]; $types = 'ii';
} elseif (preg_match('/^job:(\d+)$/', $segment, $m) === 1) {
    $jobId = (int) $m[1];
    $query = 'SELECT DISTINCT u.emailAddress AS email, u.fullName FROM tblEventJobAssignments a '
           . 'JOIN tblEventJobs j ON j.jobID = a.jobID '
           . 'JOIN tblUsers u ON u.userID = a.userID '
           . 'WHERE j.eventID = ? AND a.jobID = ? AND u.emailAddress IS NOT NULL AND u.emailAddress != ""';
    $params = [$eventId, $jobId]; $types = 'ii';
}

if ($query === null) {
    $_SESSION['flash_msg']  = 'Invalid segment.'; $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('calendar/event/broadcast') . '?eventID=' . $eventId, true, 302); exit();
}

$recipients = [];
$stmt = $mysqli->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) {
    $recipients[] = ['email' => (string) $r['email'], 'name' => (string) $r['fullName']];
}
$stmt->close();

// 📧 The message every recipient gets. One e-mail each, so nobody sees
//    anybody else's address.
$bodyHtml = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
$prefix = '<p>' . htmlspecialchars('Re: ' . $event['eventName'], ENT_QUOTES, 'UTF-8') . '</p>';
$bodyHtml = $prefix . $bodyHtml;

$addresses = [];
foreach ($recipients as $rcp) {
    if (filter_var($rcp['email'], FILTER_VALIDATE_EMAIL) !== false) {
        $addresses[strtolower($rcp['email'])] = $rcp['email']; // the same address twice gets one message
    }
}
$addresses = array_values($addresses);
$intended  = count($addresses);
$backTo    = Site::url('calendar/event/broadcast') . '?eventID=' . $eventId;

if ($intended === 0) {
    $_SESSION['flash_msg']  = 'Nobody in that group has an e-mail address, so nothing was sent.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: ' . $backTo, true, 302);
    exit();
}

// -----------------------------------------------------------------------------
// 🧾 RECORD THE BROADCAST FIRST, BEFORE ANY MESSAGE GOES (#592, part 3).
// -----------------------------------------------------------------------------
// WHAT WAS WRONG. The record was written LAST, after every message had been
// sent one by one inside this request. Through a slow mail server a broadcast
// to five people was ended by the web server at 30 seconds: three messages had
// gone, nothing recorded the broadcast, and pressing Send again sent to all
// five once more.
//
// NOW, in this order:
//   1. The broadcast is recorded, with the moment in UTC and how many people
//      it is for.
//   2. If the very same broadcast (this event, this group, this subject, this
//      text) was already recorded in the last ten minutes, THIS one is a
//      second press: its record is taken out again and nothing is sent.
//      The record is written BEFORE that question is asked, and the question
//      looks only for an EARLIER record, so that two presses at the same
//      instant cannot both find "nothing yet" and both send.
//   3. EVERY message is kept in the mail queue (`sendNow` false). From then
//      on nothing can be lost if this request is ended, and nothing can be
//      sent twice: that is the queue's job (MailQueue.php).
//   4. One run of the queue is made here and now, with a request's own
//      budget (25 messages or 20 seconds), so that a small broadcast still
//      goes straight away. The rest goes at the mail job's next runs.
//
// "Ten minutes" is 600 exact seconds on the database's UTC clock. The older
// `sentAt` column is the database server's own clock, which repeats an hour
// on the night the clocks go back; it is not used for this.
//
// WHAT THIS CANNOT DO: tell a second press from somebody who really means to
// send the same words to the same group again within ten minutes. They are
// told how to do that. And it does not make the rest go while the mail job is
// not running: the page then says so in as many words.
//
// 🔒 STEPS 1 TO 3 ARE ALL OR NONE (one transaction), ONE PRESS AT A TIME.
// WHAT WAS WRONG. Each step was made permanent as it was written. A request
// that was ended while keeping its messages had recorded the broadcast and
// kept 19 of 60; the second press was then refused as "already sent", and 41
// people never got it. Now the record and every message are made permanent
// together or not at all: a request that ends part way leaves NOTHING, and
// the next press starts afresh.
// A transaction alone would undo step 2: the second of two presses at the
// same instant cannot see the first one's record until it is made permanent,
// and both would send. So one press at a time is let in for an event, by a
// lock the database gives up by itself if the request dies. REJECTED: making
// step 2 a locking read. Two presses then each wait for the other and the
// database ends one of them with an error.
$lockEvent = (string) $eventId;
$stmt      = $mysqli->prepare("SELECT GET_LOCK(CONCAT('portal-broadcast:', SHA1(CONCAT(DATABASE(), ':', ?))), 10) AS got");
$stmt->bind_param('s', $lockEvent);
$stmt->execute();
$gotLock = (int) ($stmt->get_result()->fetch_assoc()['got'] ?? 0);
$stmt->close();
if ($gotLock !== 1) {
    $_SESSION['flash_msg']  = 'Nothing was sent by this press: another broadcast for this event was still being recorded. '
        . 'Wait a minute, look at the list of broadcasts below, and send again only if yours is not there.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: ' . $backTo, true, 302);
    exit();
}

$kept        = [];
$sentNow     = 0; // only when the queue could not take a message and it went in the request instead
$lost        = 0;
$earlier     = 0;
$broadcastId = 0;
$recorded    = false;
try {
    $mysqli->begin_transaction();

    $stmt = $mysqli->prepare(
        'INSERT INTO tblEventBroadcasts (eventID, sentByID, segment, subject, body, recipientCount, sentAtUtc) '
        . 'VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
    );
    $stmt->bind_param('iisssi', $eventId, $senderId, $segment, $subject, $body, $intended);
    $stmt->execute();
    $broadcastId = (int) $stmt->insert_id;
    $stmt->close();

    $stmt = $mysqli->prepare(
        'SELECT COUNT(*) AS n FROM tblEventBroadcasts WHERE eventID = ? AND segment = ? AND subject = ? AND body = ? '
        . 'AND broadcastID < ? AND sentAtUtc > UTC_TIMESTAMP() - INTERVAL 600 SECOND'
    );
    $stmt->bind_param('isssi', $eventId, $segment, $subject, $body, $broadcastId);
    $stmt->execute();
    $earlier = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
    $stmt->close();

    if ($earlier === 0) {
        // 📥 Keep every message. `sendNow` false: nothing is tried here.
        foreach ($addresses as $address) {
            $ok = Mailer::send($address, $subject, $bodyHtml, [], ['priority' => 'bulk', 'sendNow' => false]);
            if (Mailer::lastQueueId() > 0) {
                $kept[] = Mailer::lastQueueId();
            } elseif ($ok === true) {
                $sentNow++;
            } else {
                $lost++;
            }
        }
        $mysqli->commit();
        $recorded = true;
    } else {
        $mysqli->rollback(); // a second press: its record is taken out again
        $recorded = true;
    }
} catch (\Throwable $problem) {
    try {
        $mysqli->rollback();
    } catch (\Throwable $ignored) {
        // The database undoes it by itself when the connection ends.
    }
    error_log('Event broadcast: the broadcast could not be recorded, and nothing of it was kept: ' . get_class($problem));
}
try {
    $stmt = $mysqli->prepare("SELECT RELEASE_LOCK(CONCAT('portal-broadcast:', SHA1(CONCAT(DATABASE(), ':', ?))))");
    $stmt->bind_param('s', $lockEvent);
    $stmt->execute();
    $stmt->close();
} catch (\Throwable $ignored) {
    // The database gives the lock up by itself when the connection ends.
}

if ($recorded === false) {
    // "Nothing was sent" is said only when it is so. A message goes inside
    // this request only when the queue could not take it ($sentNow), and
    // such a message cannot be taken back.
    $_SESSION['flash_msg']  = $sentNow === 0
        ? 'Nothing was sent: the broadcast could not be recorded, and none of its messages was kept. It is safe to press Send again.'
        : 'The broadcast could not be recorded, and none of its messages was kept. ' . $sentNow . ' had already gone, because the mail queue could not take '
            . 'them. Pressing Send again will send to everybody, those ' . $sentNow . ' included.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $backTo, true, 302);
    exit();
}

if ($earlier > 0) {
    Logger::activity('EventBroadcastRepeatRefused', 'Event #' . $eventId . ' segment=' . $segment . ': the same message was recorded less than ten minutes ago');
    $_SESSION['flash_msg']  = 'This same message was already sent to this group less than ten minutes ago, so it has not been sent again. '
        . 'To send it again on purpose, wait ten minutes or change the wording.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: ' . $backTo, true, 302);
    exit();
}

// 📤 Every message is kept (above). Now let the queue send what it can.
//    25 messages while the scheduled job is running, which sends the rest.
//    With NO job running there is no "rest": a broadcast to 60 reached 25
//    people and the other 35 waited for ever. So the 25 does not apply then
//    (Mailer::requestMessages()); the 20 seconds always does.
$waiting = 0;
$held    = 0;
$failed  = 0;
if ($kept !== []) {
    try {
        MailQueue::run($mysqli, Mailer::requestMessages(), Mailer::REQUEST_SECONDS);
    } catch (\Throwable $problem) {
        // The messages are kept; the mail job will send them.
        error_log('Event broadcast: the first run of the mail queue stopped: ' . get_class($problem));
    }
    // What became of THIS broadcast's messages. A sent message is deleted
    // from the queue, so the ones no longer there have gone.
    $marks = implode(',', array_fill(0, count($kept), '?'));
    $stmt  = $mysqli->prepare('SELECT status, COUNT(*) AS n FROM tblMailQueue WHERE queueID IN (' . $marks . ') GROUP BY status');
    $stmt->bind_param(str_repeat('i', count($kept)), ...$kept);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $state) {
        if ($state['status'] === 'failed' || $state['status'] === 'expired') {
            $failed += (int) $state['n'];
        } elseif ($state['status'] === 'held') {
            $held += (int) $state['n']; // not "will be sent shortly": it waits for a person, or for the mail route to be put right
        } else {
            $waiting += (int) $state['n'];
        }
    }
    $stmt->close();
}
$sent = $sentNow + count($kept) - $waiting - $held - $failed;
$lost += $failed;

if ($sent + $waiting + $held === 0) {
    // Nothing went and nothing is waiting (e-mail is not set up, most
    // likely). The record is taken out, so that it does not stand in the way
    // of sending once e-mail works.
    $stmt = $mysqli->prepare('DELETE FROM tblEventBroadcasts WHERE broadcastID = ?');
    $stmt->bind_param('i', $broadcastId);
    $stmt->execute();
    $stmt->close();
    Logger::activity('EventBroadcastNotSent', 'Event #' . $eventId . ' segment=' . $segment . ' recipients=' . $intended . ' sent=0');
    $_SESSION['flash_msg']  = 'Nothing was sent: the portal could not send or keep any of the ' . $intended . ' message' . ($intended === 1 ? '' : 's')
        . '. An administrator can see why at Admin → Integrations → Email.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $backTo, true, 302);
    exit();
}

Logger::activity('EventBroadcastSent', 'Event #' . $eventId . ' segment=' . $segment . ' recipients=' . $intended . ' sent=' . $sent . ' waiting=' . $waiting . ' held=' . $held . ' notSent=' . $lost);

// 🗣️ Say exactly what happened: sent, waiting, held and not sent are four
//    different things, and "Sent to 300" for 25 sent would be untrue.
//    WHAT WAS WRONG: this said "queued and will be sent shortly … There is
//    no need to send this again" whether or not anything would ever send
//    them. With no scheduled job that was untrue: three days on, 35 people
//    were still waiting. Now it asks the one question
//    (MailQueue::jobIsRunning()) and says which it is.
$jobRunning = MailQueue::jobIsRunning($mysqli);
$said       = 'Sent to ' . $sent . ' of ' . $intended . ' recipient' . ($intended === 1 ? '' : 's') . '.';
if ($waiting > 0 && $jobRunning === true) {
    $said .= ' ' . $waiting . ' ' . ($waiting === 1 ? 'is' : 'are') . ' queued and will be sent shortly, a few at a time. There is no need to send this again.';
} elseif ($waiting > 0) {
    $said .= ' ' . $waiting . ' ' . ($waiting === 1 ? 'is' : 'are') . ' WAITING and will not be sent yet: the mail queue\'s scheduled job, which sends waiting mail, has not run in the last '
        . intdiv(MailQueue::JOB_RECENT_SECONDS, 60) . ' minutes. ' . ($waiting === 1 ? 'It goes' : 'They go') . ' once an administrator has added that job; the health page says how. '
        . 'Sending this again would not make ' . ($waiting === 1 ? 'it' : 'them') . ' go.';
}
if ($held > 0) {
    $said .= ' ' . $held . ' ' . ($held === 1 ? 'is' : 'are') . ' held and will not go until an administrator has put the mail route right; the health page says what is wrong.';
}
if ($lost > 0) {
    $said .= ' ' . $lost . ' could not be sent.';
}
$_SESSION['flash_msg']  = $said;
$_SESSION['flash_type'] = ($lost > 0 || $held > 0 || ($waiting > 0 && $jobRunning === false)) ? 'warning' : 'success';
header('Location: ' . $backTo, true, 302);
exit();
