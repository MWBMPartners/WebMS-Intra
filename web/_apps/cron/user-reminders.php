<?php
// Path: _apps/cron/user-reminders.php
/**
 * -----------------------------------------------------------------------------
 * Cron — User reminders sweep ⏰👤 (#439)
 * -----------------------------------------------------------------------------
 * Endpoint expected to be called every 15 minutes by an external scheduler.
 * Clone of `cron/asset-reminders.php` (structure) + `cron/venue-
 * reminders.php` (per-site settings discipline), sweeping three previously
 * shipped-but-never-consumed reminder fields — three families:
 *
 *   task-reminder     — `tblTasks.reminderDate`/`reminderSent` (migration
 *                       036). Fifteen-minute precision matters here, unlike
 *                       the other two families, because reminderDate is a
 *                       DATETIME the user picked deliberately.
 *   rota-slot         — `tblRotaSlot.reminderSentAt` +
 *                       `rota.reminder_days_before` (migration 074). One
 *                       grouped email per assignee per run listing every
 *                       due slot; dedupe still stays per-slot.
 *   milestone-digest  — daily 06:00-08:59 digest of today's birthdays/
 *                       anniversaries to designated roles
 *                       (`milestones.digest_recipients`, migration 076).
 *
 * TOKEN GATE: `?key=` vs `user_reminders.cron_token`, constant-time
 * `hash_equals()`, empty stored token ⇒ ALWAYS 403 — migration 171 seeds
 * `user_reminders.cron_token` empty + isSensitive=1, so this endpoint is
 * inert until an admin sets a real token at /admin/settings. A dedicated
 * global token (NOT `reminders.cron_token`) keeps rotation independent —
 * the shipped six-of-six-endpoint convention (see
 * cron/asset-reminders.php's own header for the pattern this clones).
 *
 * MULTI-SITE: loops every distinct ACTIVE site (`tblSites.isActive = 1`) —
 * deliberately not pre-filtered to "sites owning ≥1 candidate row" the way
 * the asset/venue crons are, because with three unrelated families a union
 * pre-filter would be noisier than three cheap empty result sets;
 * `Site::forceContext()` on a site with nothing due is harmless.
 * `Site::forceContext($siteId)` is called before each site's work (so
 * `Logger::activity()` attributes correctly) wrapped in try/catch-continue
 * — mirrors `asset-reminders.php` exactly, including NO context reset
 * after the loop (the process exits immediately after).
 *
 * PER-SITE SETTINGS INSIDE THE LOOP: every per-site flag/tunable is read
 * via `App::settingForSite($key, $siteId)`, NEVER the ambient
 * `Settings::get()`/`App::settings()` snapshot — that snapshot is frozen
 * for the FIRST resolved site of the request and `Site::forceContext()`
 * does not refresh it, so an ambient read would silently apply the first
 * site's value to every other site in a multi-site loop (see
 * `cron/venue-reminders.php`'s own header for the fuller explanation).
 * `user_reminders.cron_token`/`user_reminders.enabled` are the two
 * genuinely global (siteID=NULL) exceptions, read once via `Settings::get()`
 * before the loop.
 *
 * DEDUPE — three different mechanisms, one per family (see each family's
 * own inline comment for why):
 *   task-reminder    — atomic claim `UPDATE tblTasks SET reminderSent = 1
 *                      WHERE taskID = ? AND reminderSent = 0`.
 *   rota-slot        — atomic claim `UPDATE tblRotaSlot SET
 *                      reminderSentAt = NOW() WHERE slotID = ? AND
 *                      reminderSentAt IS NULL`, applied per-slot even
 *                      though the email is grouped per-assignee.
 *   milestone-digest — new `tblUserReminderLog` (migration 171), check-first
 *                      + insert wrapped in a try/catch for
 *                      `\mysqli_sql_exception` (the `uq_usrrem_ref`
 *                      concurrency backstop) — mirrors
 *                      `Venues::reminderAlreadySent()`/`logReminder()`
 *                      exactly, reimplemented here as two small local
 *                      functions (one caller, no new `_core` class).
 * In every family, a claim/log happens EVEN when the resolved recipient
 * was suppressed (notifyPrefs opt-out) or invalid — otherwise that item
 * would retry forever on every subsequent run (asset-cron precedent,
 * `asset-reminders.php`'s own header).
 *
 * NOTIFY PREFS: this is the first sender in the codebase to honour
 * `tblUsers.notifyPrefs` — `taskReminders`/`rotaReminders` (both default
 * ON; an absent key or invalid JSON ⇒ allowed, matching the defaults-array
 * semantics of `auth/account/notifications.php`). Milestone-digest has no
 * per-recipient pref — recipients are explicitly configured leadership
 * roles, not individual opt-ins.
 *
 * PLAIN-ENGLISH EMAIL BODIES, NOT I18n::t(): deliberately not translated
 * via language keys — `cron/venue-reminders.php` shipped calling
 * `I18n::t('venues.reminder.*_subject')` for keys that were never added to
 * `web/_lang/en.php`, so every subject silently rendered as the raw key.
 * This cron avoids that trap entirely by never calling I18n.
 *
 * @package   Portal\App\Cron
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/439
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Logger;
use Portal\Core\Mailer;
use Portal\Core\Settings;
use Portal\Core\Site;

// -----------------------------------------------------------------------------
// 🔑 Token gate (constant-time compare) — cloned from cron/asset-
// reminders.php. An empty stored token ALWAYS 403s, so this endpoint is
// inert until an admin explicitly sets user_reminders.cron_token.
// -----------------------------------------------------------------------------
$incoming = (string) ($_GET['key'] ?? '');
$expected = (string) (Settings::get('user_reminders.cron_token', '') ?? '');
if ($expected === '' || hash_equals($expected, $incoming) === false) {
    http_response_code(403);
    exit('Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');

if ((string) Settings::get('user_reminders.enabled', '1') !== '1') {
    echo 'User reminders disabled';
    exit();
}

$db = App::db();

// -----------------------------------------------------------------------------
// 🔗 Absolute link builder for reminder emails — scheme+host resolution
// mirrors `venueReminderUrl()` in cron/venue-reminders.php. $_SERVER
// values come from the webserver/connection itself, never a request body.
// -----------------------------------------------------------------------------
function userReminderUrl(string $path): string
{
    $https  = (string) ($_SERVER['HTTPS'] ?? '');
    $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host . $path;
}

/**
 * Does this user's notifyPrefs JSON allow sending under $key? Default-on:
 * missing/empty/invalid JSON, or the key simply absent, all mean "allowed"
 * — matches the defaults-array semantics of
 * auth/account/notifications.php:75-84. Only an explicit `false` for this
 * exact key suppresses sending.
 */
function notifyPrefAllows(?string $notifyPrefsJson, string $key): bool
{
    if ($notifyPrefsJson === null || trim($notifyPrefsJson) === '') {
        return true;
    }
    $prefs = json_decode($notifyPrefsJson, true);
    if (is_array($prefs) === false || array_key_exists($key, $prefs) === false) {
        return true;
    }
    return $prefs[$key] === false ? false : true;
}

/**
 * Single-shot dedupe check for the milestone-digest family (the only
 * family without its own sent-flag column) — mirrors
 * Venues::reminderAlreadySent() exactly, against the new
 * tblUserReminderLog table (migration 171).
 */
function userReminderAlreadySent(\mysqli $db, string $refType, int $refId, string $dueDate): bool
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
 * Log a fresh milestone-digest send. recipientCount = 0 STILL logs — a
 * site with no valid recipient must not retry the same day forever. The
 * INSERT is wrapped in try/catch for the rare overlapping-run race caught
 * by uq_usrrem_ref — mirrors Venues::logReminder() exactly.
 */
function logUserReminder(\mysqli $db, int $siteId, string $refType, int $refId, string $dueDate, int $recipientCount): void
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
        // 🏁 uq_usrrem_ref caught a concurrent run that logged this exact
        // due item between our check and this INSERT — treat exactly like
        // "already sent", never a fatal error.
        error_log('logUserReminder() duplicate race: ' . $e->getMessage());
    }
}

/**
 * Milestone-digest recipients: CSV roleKeys from
 * milestones.digest_recipients resolved to active, site-member, valid-
 * email users holding one of those roles — ROLES-ONLY, deliberately NO
 * implicit admin/site-admin union (privacy-tighter than
 * Venues::resolveReminderRecipients(); an admin who should see the digest
 * opts in by holding a listed role). Empty CSV ⇒ empty array — the caller
 * treats that as "skip this site" (explicit opt-in only, see file header's
 * milestone-digest gate).
 *
 * @return string[] de-duplicated, FILTER_VALIDATE_EMAIL'd addresses
 */
function resolveMilestoneDigestRecipients(\mysqli $db, int $siteId, string $csv): array
{
    $roleKeys = array_values(array_filter(
        array_map('trim', explode(',', $csv)),
        static fn (string $r): bool => $r !== ''
    ));
    if (count($roleKeys) === 0) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($roleKeys), '?'));
    // 🏷️ #516: tblUserSites joined first, so tblUserRoles ties to it and
    // tblRoles to tblUserRoles — a role held in a DIFFERENT organisation
    // must not be emailed about THIS organisation's reminder.
    $sql = 'SELECT DISTINCT u.emailAddress AS email FROM tblUsers u '
        . 'INNER JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
        . 'INNER JOIN tblUserRoles ur ON ur.userID = u.userID AND ur.siteID = us.siteID '
        . 'INNER JOIN tblRoles r ON r.roleID = ur.roleID AND r.siteID = ur.siteID '
        . "WHERE u.isActive = 1 AND u.emailAddress IS NOT NULL AND u.emailAddress != '' "
        . "AND r.roleKey IN ({$placeholders})";
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        return [];
    }
    $types = 'i' . str_repeat('s', count($roleKeys));
    $stmt->bind_param($types, $siteId, ...$roleKeys);
    $stmt->execute();
    $emails = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $emails[] = (string) $row['email'];
    }
    $stmt->close();

    $emails = array_values(array_unique($emails));
    return array_values(array_filter(
        $emails,
        static fn (string $e): bool => filter_var($e, FILTER_VALIDATE_EMAIL) !== false
    ));
}

/**
 * "5th" / "21st" / "12th" — used for milestone-digest anniversary years.
 */
function ordinalSuffix(int $n): string
{
    if ($n % 100 >= 11 && $n % 100 <= 13) {
        return $n . 'th';
    }
    return match ($n % 10) {
        1       => $n . 'st',
        2       => $n . 'nd',
        3       => $n . 'rd',
        default => $n . 'th',
    };
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

$grandTotals = [
    'task-reminder'    => ['due' => 0, 'sent' => 0, 'skipped' => 0],
    'rota-slot'        => ['due' => 0, 'sent' => 0, 'skipped' => 0],
    'milestone-digest' => ['due' => 0, 'sent' => 0, 'skipped' => 0],
];

$hour = (int) date('H');

foreach ($siteIds as $siteId) {
    // 🌐 Force this site's context — see file header's MULTI-SITE note.
    // Wrapped defensively: one misbehaving site must never abort the
    // whole sweep for every OTHER site.
    try {
        Site::forceContext($siteId);
    } catch (\Throwable $e) {
        Logger::errorPlatform(
            'UserReminders',
            'Warning',
            'USER_REMINDERS_SITE_CONTEXT_FAIL',
            $e->getMessage(),
            'siteID=' . $siteId
        );
        continue;
    }

    $siteTotals = [
        'task-reminder'    => ['due' => 0, 'sent' => 0, 'skipped' => 0],
        'rota-slot'        => ['due' => 0, 'sent' => 0, 'skipped' => 0],
        'milestone-digest' => ['due' => 0, 'sent' => 0, 'skipped' => 0],
    ];

    // -------------------------------------------------------------------
    // ⏰ Family: task-reminder.
    // -------------------------------------------------------------------
    $tasksEnabled     = (string) (App::settingForSite('tasks.enabled', $siteId) ?? 'true');
    $taskRemindersOn  = (string) (App::settingForSite('tasks.reminders_enabled', $siteId) ?? '1');
    if (in_array($tasksEnabled, ['1', 'true'], true) === true && $taskRemindersOn !== '0') {
        $lookbackDays = (int) (App::settingForSite('tasks.reminder_lookback_days', $siteId) ?? '7');

        $stmt = $db->prepare(
            'SELECT t.taskID, t.title, t.description, t.priority, t.dueDate, '
            . 'u.emailAddress, u.notifyPrefs '
            . 'FROM tblTasks t '
            . 'INNER JOIN tblUsers u ON u.userID = t.assignedToID AND u.isActive = 1 '
            . "WHERE t.siteID = ? AND t.isDeleted = 0 AND t.status IN ('pending','in_progress') "
            . 'AND t.reminderSent = 0 AND t.reminderDate IS NOT NULL '
            . 'AND t.reminderDate <= NOW() AND t.reminderDate >= DATE_SUB(NOW(), INTERVAL ? DAY)'
        );
        if ($stmt !== false) {
            $stmt->bind_param('ii', $siteId, $lookbackDays);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($t = $result->fetch_assoc()) {
                $siteTotals['task-reminder']['due']++;
                $taskId = (int) $t['taskID'];
                $title  = (string) $t['title'];

                $titleEsc    = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
                $priorityEsc = htmlspecialchars(ucfirst((string) $t['priority']), ENT_QUOTES, 'UTF-8');
                $dueDateEsc  = $t['dueDate'] !== null ? htmlspecialchars((string) $t['dueDate'], ENT_QUOTES, 'UTF-8') : null;
                $desc        = trim((string) ($t['description'] ?? ''));
                if (mb_strlen($desc) > 300) {
                    $desc = mb_substr($desc, 0, 300) . '…';
                }
                $descEsc = htmlspecialchars($desc, ENT_QUOTES, 'UTF-8');
                $url     = htmlspecialchars(userReminderUrl('/tasks?edit=' . $taskId), ENT_QUOTES, 'UTF-8');

                $subject = '⏰ Task reminder: ' . $title;
                $body = '<p><strong>' . $titleEsc . '</strong> (' . $priorityEsc . ' priority)'
                      . ($dueDateEsc !== null ? ' — due ' . $dueDateEsc : '') . '</p>'
                      . ($descEsc !== '' ? '<p>' . nl2br($descEsc) . '</p>' : '')
                      . '<p><a href="' . $url . '">View this task</a></p>';

                $mailed = 0;
                $email  = (string) $t['emailAddress'];
                if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false
                    && notifyPrefAllows($t['notifyPrefs'] !== null ? (string) $t['notifyPrefs'] : null, 'taskReminders') === true
                ) {
                    if (Mailer::send($email, $subject, $body) === true) {
                        $mailed = 1;
                    }
                }

                // 🔁 Atomic claim — the accepted narrow-race trade-off
                // documented in asset-reminders.php's own header: send
                // first, then claim; affected_rows === 0 means a
                // concurrent run already claimed this exact task.
                $claimed = false;
                $claim = $db->prepare('UPDATE tblTasks SET reminderSent = 1 WHERE taskID = ? AND reminderSent = 0');
                if ($claim !== false) {
                    $claim->bind_param('i', $taskId);
                    $claim->execute();
                    $claimed = $claim->affected_rows > 0;
                    $claim->close();
                }

                if ($claimed === false) {
                    $siteTotals['task-reminder']['skipped']++;
                } else {
                    $siteTotals['task-reminder']['sent'] += $mailed;
                }
            }
            $stmt->close();
        }
    }

    // -------------------------------------------------------------------
    // 📅 Family: rota-slot. One grouped email per assignee; dedupe stays
    // per-slot (each slot claimed independently after the group send).
    // -------------------------------------------------------------------
    $rotaEnabled    = (string) (App::settingForSite('rota.enabled', $siteId) ?? '0');
    $rotaRemindersOn = (string) (App::settingForSite('rota.reminders_enabled', $siteId) ?? '1');
    if (in_array($rotaEnabled, ['1', 'true'], true) === true && $rotaRemindersOn !== '0') {
        $leadDays = (int) (App::settingForSite('rota.reminder_days_before', $siteId) ?? '3');

        $stmt = $db->prepare(
            'SELECT s.slotID, s.slotDate, s.startTime, s.endTime, s.notes, s.assignedToID, '
            . 'r.name AS roleName, u.emailAddress, u.notifyPrefs '
            . 'FROM tblRotaSlot s '
            . 'INNER JOIN tblRotaRoleType r ON r.roleTypeID = s.roleTypeID '
            . 'INNER JOIN tblUsers u ON u.userID = s.assignedToID AND u.isActive = 1 '
            . 'WHERE s.siteID = ? AND s.assignedToID IS NOT NULL AND s.reminderSentAt IS NULL '
            . 'AND s.slotDate BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY) '
            . 'ORDER BY s.assignedToID, s.slotDate, s.startTime'
        );
        if ($stmt !== false) {
            $stmt->bind_param('ii', $siteId, $leadDays);
            $stmt->execute();
            $result = $stmt->get_result();

            // 👥 Group rows by assignee so each person gets ONE email
            // listing every due slot, not one email per slot.
            $groups = [];
            while ($s = $result->fetch_assoc()) {
                $siteTotals['rota-slot']['due']++;
                $uid = (int) $s['assignedToID'];
                if (isset($groups[$uid]) === false) {
                    $groups[$uid] = [
                        'email'       => (string) $s['emailAddress'],
                        'notifyPrefs' => $s['notifyPrefs'] !== null ? (string) $s['notifyPrefs'] : null,
                        'slots'       => [],
                    ];
                }
                $groups[$uid]['slots'][] = $s;
            }
            $stmt->close();

            foreach ($groups as $group) {
                $lines = [];
                foreach ($group['slots'] as $s) {
                    $roleEsc = htmlspecialchars((string) $s['roleName'], ENT_QUOTES, 'UTF-8');
                    $dateEsc = htmlspecialchars((string) $s['slotDate'], ENT_QUOTES, 'UTF-8');
                    $timeStr = '';
                    if ($s['startTime'] !== null) {
                        $timeStr = ' ' . (string) $s['startTime'];
                        if ($s['endTime'] !== null) {
                            $timeStr .= '–' . (string) $s['endTime'];
                        }
                    }
                    $timeEsc  = htmlspecialchars($timeStr, ENT_QUOTES, 'UTF-8');
                    $notesEsc = $s['notes'] !== null ? htmlspecialchars((string) $s['notes'], ENT_QUOTES, 'UTF-8') : '';
                    $lines[] = '<li>' . $roleEsc . ' — ' . $dateEsc . $timeEsc
                             . ($notesEsc !== '' ? ' (' . $notesEsc . ')' : '') . '</li>';
                }
                $n = count($group['slots']);
                $subject = '📅 Rota reminder: ' . $n . ' upcoming ' . ($n === 1 ? 'duty' : 'duties');
                $url     = htmlspecialchars(userReminderUrl('/rota'), ENT_QUOTES, 'UTF-8');
                $body = '<p>You have ' . $n . ' upcoming ' . ($n === 1 ? 'duty' : 'duties') . ':</p>'
                      . '<ul>' . implode('', $lines) . '</ul>'
                      . '<p><a href="' . $url . '">View the rota</a></p>';

                $mailed = 0;
                if (filter_var($group['email'], FILTER_VALIDATE_EMAIL) !== false
                    && notifyPrefAllows($group['notifyPrefs'], 'rotaReminders') === true
                ) {
                    if (Mailer::send($group['email'], $subject, $body) === true) {
                        $mailed = 1;
                    }
                }

                foreach ($group['slots'] as $s) {
                    $slotId = (int) $s['slotID'];
                    $claimed = false;
                    $claim = $db->prepare('UPDATE tblRotaSlot SET reminderSentAt = NOW() WHERE slotID = ? AND reminderSentAt IS NULL');
                    if ($claim !== false) {
                        $claim->bind_param('i', $slotId);
                        $claim->execute();
                        $claimed = $claim->affected_rows > 0;
                        $claim->close();
                    }
                    if ($claimed === false) {
                        $siteTotals['rota-slot']['skipped']++;
                    } else {
                        $siteTotals['rota-slot']['sent'] += $mailed;
                    }
                }
            }
        }
    }

    // -------------------------------------------------------------------
    // 🎂 Family: milestone-digest. Once-per-day, 06:00-08:59 window
    // (matches cron/event-reminders.php's own day-of family window).
    // Explicit opt-in only — an EMPTY milestones.digest_recipients ⇒ skip
    // this site entirely (birthday data + privacy tiers make silent
    // fallback-to-admins the wrong failure mode).
    // -------------------------------------------------------------------
    if ($hour >= 6 && $hour <= 8) {
        $milestonesEnabled = (string) (App::settingForSite('milestones.enabled', $siteId) ?? '0');
        $digestEnabled     = (string) (App::settingForSite('milestones.digest_enabled', $siteId) ?? '1');
        $recipientsCsv     = trim((string) (App::settingForSite('milestones.digest_recipients', $siteId) ?? ''));

        if (in_array($milestonesEnabled, ['1', 'true'], true) === true
            && $digestEnabled !== '0'
            && $recipientsCsv !== ''
        ) {
            $today = date('Y-m-d');

            if (userReminderAlreadySent($db, 'milestone-digest', $siteId, $today) === true) {
                $siteTotals['milestone-digest']['skipped']++;
            } else {
                $stmt = $db->prepare(
                    'SELECT m.kind, m.label, m.originYear, u.fullName '
                    . 'FROM tblUserMilestone m '
                    . 'INNER JOIN tblUsers u ON u.userID = m.userID AND u.isActive = 1 '
                    . 'INNER JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
                    . "WHERE m.monthDay = DATE_FORMAT(CURDATE(), '%m-%d') AND m.privacy <> 'private' "
                    . 'ORDER BY u.fullName'
                );
                $milestones = [];
                if ($stmt !== false) {
                    $stmt->bind_param('i', $siteId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    while ($m = $result->fetch_assoc()) {
                        $milestones[] = $m;
                    }
                    $stmt->close();
                }

                if (count($milestones) > 0) {
                    $siteTotals['milestone-digest']['due']++;

                    $lines = [];
                    foreach ($milestones as $m) {
                        $kindLabel = ucfirst((string) $m['kind']);
                        if (!empty($m['label'])) {
                            $kindLabel .= '/' . (string) $m['label'];
                        }
                        $line = htmlspecialchars((string) $m['fullName'], ENT_QUOTES, 'UTF-8')
                              . ' — ' . htmlspecialchars($kindLabel, ENT_QUOTES, 'UTF-8');
                        if ($m['originYear'] !== null) {
                            $years = (int) date('Y') - (int) $m['originYear'];
                            $line .= ' — ' . htmlspecialchars(ordinalSuffix($years), ENT_QUOTES, 'UTF-8');
                        }
                        $lines[] = '<li>' . $line . '</li>';
                    }

                    $subject = "🎂 Today's milestones — " . date('j M');
                    $url     = htmlspecialchars(userReminderUrl('/milestones'), ENT_QUOTES, 'UTF-8');
                    $body = '<p>Today\'s milestones:</p>'
                          . '<ul>' . implode('', $lines) . '</ul>'
                          . '<p><a href="' . $url . '">View Milestones</a></p>';

                    $recipients = resolveMilestoneDigestRecipients($db, $siteId, $recipientsCsv);
                    $sent = 0;
                    foreach (array_unique($recipients) as $email) {
                        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                            if (Mailer::send($email, $subject, $body) === true) {
                                $sent++;
                            }
                        }
                    }

                    logUserReminder($db, $siteId, 'milestone-digest', $siteId, $today, $sent);
                    $siteTotals['milestone-digest']['sent'] += $sent;
                }
                // 🕊️ Zero milestones today ⇒ no log, no send — a legitimate
                // no-op; re-querying later in the same window is cheap.
            }
        }
    }

    foreach ($grandTotals as $family => $ignored) {
        $grandTotals[$family]['due']     += $siteTotals[$family]['due'];
        $grandTotals[$family]['sent']    += $siteTotals[$family]['sent'];
        $grandTotals[$family]['skipped'] += $siteTotals[$family]['skipped'];
    }

    // 📓 Per-site activity log row — Logger::activity() reads Site::id(),
    // still THIS site for the remainder of the loop body.
    Logger::activity(
        'UserRemindersRun',
        sprintf(
            'Tasks %d/%d sent, rota %d/%d, milestone-digest %d/%d',
            $siteTotals['task-reminder']['sent'], $siteTotals['task-reminder']['due'],
            $siteTotals['rota-slot']['sent'], $siteTotals['rota-slot']['due'],
            $siteTotals['milestone-digest']['sent'], $siteTotals['milestone-digest']['due']
        )
    );
}
// 🚧 NO Site::forceContext() reset after the loop — mirrors cron/asset-
// reminders.php / cron/venue-reminders.php exactly; the process exits
// immediately after this script.

// -----------------------------------------------------------------------------
// 📋 Grand-total text/plain summary — greppable from the scheduler's logs.
// -----------------------------------------------------------------------------
echo "User reminders sweep — " . count($siteIds) . " site(s)\n";
foreach ($grandTotals as $family => $t) {
    echo sprintf(
        "%-17s due=%d sent=%d skipped(already-sent)=%d\n",
        $family,
        $t['due'],
        $t['sent'],
        $t['skipped']
    );
}
