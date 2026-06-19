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
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/329
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Logger;
use Portal\Core\Mailer;
use Portal\Core\Settings;

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
function sendReminderBatch(\mysqli $db, int $eventId, string $type, string $subject, string $bodyHtml): int
{
    $stmt = $db->prepare(
        'SELECT DISTINCT u.email, u.fullName FROM tblEventRSVPs r '
        . 'JOIN tblUsers u ON u.userID = r.userID '
        . 'WHERE r.eventID = ? AND r.response = "going" AND r.status = "confirmed" '
        . '  AND u.email IS NOT NULL AND u.email != ""'
    );
    $stmt->bind_param('i', $eventId);
    $stmt->execute();
    $recipients = [];
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) { $recipients[] = (string) $r['email']; }
    $stmt->close();

    $sent = 0;
    foreach ($recipients as $email) {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            if (Mailer::send($email, $subject, $bodyHtml) === true) { $sent++; }
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

// ─────────────────────────────────────────────────────────────────────
// 24h window
// ─────────────────────────────────────────────────────────────────────
$stmt = $mysqli->prepare(
    'SELECT e.eventID, e.eventName, e.eventSlug, e.startDateTime, e.locationName FROM tblEvents e '
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
    $body  = '<p>This is a reminder that <strong>' . htmlspecialchars((string) $e['eventName'], ENT_QUOTES, 'UTF-8') . '</strong> is tomorrow.</p>'
           . '<p><strong>When:</strong> ' . htmlspecialchars($when, ENT_QUOTES, 'UTF-8') . '</p>'
           . (!empty($e['locationName']) ? '<p><strong>Where:</strong> ' . htmlspecialchars((string) $e['locationName'], ENT_QUOTES, 'UTF-8') . '</p>' : '')
           . '<p>See you there!</p>';
    $stats['24h'] += sendReminderBatch($mysqli, (int) $e['eventID'], '24h', $subject, $body);
}
$stmt->close();

// ─────────────────────────────────────────────────────────────────────
// 1h window
// ─────────────────────────────────────────────────────────────────────
$stmt = $mysqli->prepare(
    'SELECT e.eventID, e.eventName, e.eventSlug, e.startDateTime, e.locationName FROM tblEvents e '
    . 'WHERE e.isDeleted = 0 AND e.status = "published" '
    . '  AND e.startDateTime BETWEEN DATE_ADD(NOW(), INTERVAL 45 MINUTE) '
    . '                          AND DATE_ADD(NOW(), INTERVAL 75 MINUTE) '
    . '  AND NOT EXISTS (SELECT 1 FROM tblEventReminderLog l WHERE l.eventID = e.eventID AND l.reminderType = "1h")'
);
$stmt->execute();
$result = $stmt->get_result();
while ($e = $result->fetch_assoc()) {
    $when = date('H:i', strtotime((string) $e['startDateTime']));
    $subject = '⏰ Starting soon: ' . (string) $e['eventName'];
    $body  = '<p><strong>' . htmlspecialchars((string) $e['eventName'], ENT_QUOTES, 'UTF-8') . '</strong> starts at ' . htmlspecialchars($when, ENT_QUOTES, 'UTF-8') . '.</p>'
           . (!empty($e['locationName']) ? '<p><strong>Where:</strong> ' . htmlspecialchars((string) $e['locationName'], ENT_QUOTES, 'UTF-8') . '</p>' : '')
           . '<p>See you in about an hour!</p>';
    $stats['1h'] += sendReminderBatch($mysqli, (int) $e['eventID'], '1h', $subject, $body);
}
$stmt->close();

// ─────────────────────────────────────────────────────────────────────
// Day-of summary (07:00 hour window, once per event per day)
// ─────────────────────────────────────────────────────────────────────
$hour = (int) date('H');
if ($hour >= 6 && $hour <= 8) {
    $stmt = $mysqli->prepare(
        'SELECT e.eventID, e.eventName, e.startDateTime FROM tblEvents e '
        . 'WHERE e.isDeleted = 0 AND e.status = "published" '
        . '  AND DATE(e.startDateTime) = CURDATE() '
        . '  AND NOT EXISTS (SELECT 1 FROM tblEventReminderLog l WHERE l.eventID = e.eventID AND l.reminderType = "day")'
    );
    $stmt->execute();
    $result = $stmt->get_result();
    while ($e = $result->fetch_assoc()) {
        $eid = (int) $e['eventID'];

        // 📧 Recipients: admins + coordinators.
        $r2 = $mysqli->prepare(
            'SELECT DISTINCT u.email, u.fullName FROM tblUsers u '
            . 'WHERE u.isActive = 1 AND u.email IS NOT NULL AND u.email != "" AND ('
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
