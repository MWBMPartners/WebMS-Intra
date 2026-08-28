<?php
// Path: _apps/cron/venue-reminders.php
/**
 * -----------------------------------------------------------------------------
 * Cron — Venue Bookings reminder sweep 🏛️⏰ (#429)
 * -----------------------------------------------------------------------------
 * Endpoint expected to be called periodically (e.g. every 30-60 minutes) by
 * an external scheduler. Clone of `cron/asset-reminders.php` (cited
 * throughout below), narrowed to the three families of 02-app-design.md §6:
 *
 *   booking-unagreed   — bookings whose usage type is bookable but whose
 *                        status doesn't yet count as confirmed (and isn't
 *                        rejected), due within `venues.unagreed_lead_days`
 *   agreement-renewal  — active agreements whose renewalDate, OR whose
 *                        termEnd-minus-noticePeriodDays, falls within
 *                        `venues.renewal_lead_days`
 *   invoice-due        — pending/part-paid invoices due within
 *                        `venues.invoice_due_lead_days` (overdue included
 *                        by construction — dueDate <= today+lead covers it)
 *
 * TOKEN GATE (security item 10): `?key=` vs `venues.cron_token`, constant-
 * time `hash_equals()`, empty stored token ⇒ ALWAYS 403 — migration 170
 * seeds `venues.cron_token` empty + isSensitive=1, so this endpoint is
 * inert until an admin sets a real token at `/venues/settings`.
 *
 * MULTI-SITE: loops every active site that owns at least one active venue,
 * skipping any site where `venues.enabled` isn't '1'/'true' or where
 * `venues.reminders_enabled` is explicitly '0'. `Site::forceContext($siteId)`
 * is called before each site's work (same rationale as the asset cron's own
 * header note: `Logger::audit()`/`Venues::logReminder()` etc attribute to
 * `Site::id()`) and — mirroring `asset-reminders.php` exactly — there is NO
 * context reset after the loop; the process exits immediately after.
 *
 * PER-SITE SETTINGS INSIDE THE LOOP: reads `venues.enabled`/
 * `reminders_enabled`/the three lead-day settings via
 * `App::settingForSite($key, $siteId)`, NOT `Settings::get()`/
 * `App::settings()` — those read the bootstrap `$SETTINGS` snapshot frozen
 * for the FIRST resolved site of the request, which `Site::forceContext()`
 * does not update, so a per-site override would silently read the wrong
 * site's value inside a multi-site loop. `venues.cron_token` (a genuinely
 * global, siteID=NULL setting) is the one exception, read once via
 * `Settings::get()` before the loop, matching the asset cron's own
 * `assets.cron_token` gate shape exactly.
 *
 * SINGLE-SHOT DEDUPE: `Venues::reminderAlreadySent()` check-first, then
 * `Venues::logReminder()` (which itself try/catches the `uq_venrl_ref`
 * concurrency backstop) — `recipientCount = 0` STILL logs, so a site with
 * no configured recipient does not retry the same due item forever.
 *
 * NO MONEY IN booking-unagreed BODIES (security item 11's adjacent note,
 * 02 §6): that family's email never interpolates a cost figure — amounts
 * are fine in invoice-due, whose only recipients are the same manager/admin
 * audience that already sees costs throughout this app.
 *
 * @package   Portal\App\Cron
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/429
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\I18n;
use Portal\Core\Logger;
use Portal\Core\Mailer;
use Portal\Core\Settings;
use Portal\Core\Site;
use Portal\Core\Venues;

// -----------------------------------------------------------------------------
// 🔑 Token gate (constant-time compare) — cloned from cron/asset-
// reminders.php l.85-90. An empty stored token ALWAYS 403s.
// -----------------------------------------------------------------------------
$incoming = (string) ($_GET['key'] ?? '');
$expected = (string) (Settings::get('venues.cron_token', '') ?? '');
if ($expected === '' || hash_equals($expected, $incoming) === false) {
    http_response_code(403);
    exit('Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');

$db = App::db();

// -----------------------------------------------------------------------------
// 🔗 Absolute link builder for reminder emails — scheme+host resolution
// mirrors `assetReminderUrl()` in cron/asset-reminders.php l.110-116.
// $_SERVER values come from the webserver/connection itself, never a
// request body.
// -----------------------------------------------------------------------------
function venueReminderUrl(string $path): string
{
    $https  = (string) ($_SERVER['HTTPS'] ?? '');
    $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host . $path;
}

/**
 * Send one reminder email to every valid address in $recipients, UNLESS
 * this exact (refType, refID, dueDate) was already logged by an earlier
 * run. Always logs on a fresh send — even when zero valid recipients
 * resolved — via Venues::logReminder(), which itself swallows the
 * uq_venrl_ref concurrency race. Mirrors sendAssetReminder() in
 * cron/asset-reminders.php l.133-210.
 *
 * @param string[] $recipients
 *
 * @return int|null Recipients actually mailed, or NULL when this due item
 *         was already handled by an earlier run.
 */
function sendVenueReminder(int $siteId, string $refType, int $refId, string $dueDate, string $subject, string $bodyHtml, array $recipients): ?int
{
    if (Venues::reminderAlreadySent($refType, $refId, $dueDate) === true) {
        return null;
    }

    $sent = 0;
    foreach (array_unique($recipients) as $email) {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            if (Mailer::send($email, $subject, $bodyHtml) === true) {
                $sent++;
            }
        }
    }

    Venues::logReminder($siteId, $refType, $refId, $dueDate, $sent);

    return $sent;
}

// -----------------------------------------------------------------------------
// 🌍 Every distinct, active site that owns at least one active venue.
// -----------------------------------------------------------------------------
$siteIds = [];
$siteStmt = $db->prepare(
    'SELECT DISTINCT v.siteID FROM tblVenues v '
    . 'INNER JOIN tblSites s ON s.siteID = v.siteID AND s.isActive = 1 '
    . 'WHERE v.isActive = 1'
);
if ($siteStmt !== false) {
    $siteStmt->execute();
    $result = $siteStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $siteIds[] = (int) $row['siteID'];
    }
    $siteStmt->close();
}

$grandTotals = [
    'booking-unagreed'  => ['due' => 0, 'sent' => 0, 'skipped' => 0],
    'agreement-renewal' => ['due' => 0, 'sent' => 0, 'skipped' => 0],
    'invoice-due'       => ['due' => 0, 'sent' => 0, 'skipped' => 0],
];

foreach ($siteIds as $siteId) {
    // ⏭️ Skip unless the app is enabled AND reminders aren't explicitly off.
    $enabledFlag = (string) (App::settingForSite('venues.enabled', $siteId) ?? '0');
    if (in_array($enabledFlag, ['1', 'true'], true) === false) {
        continue;
    }
    $remindersFlag = (string) (App::settingForSite('venues.reminders_enabled', $siteId) ?? '1');
    if ($remindersFlag === '0') {
        continue;
    }

    try {
        Site::forceContext($siteId);
    } catch (\Throwable $e) {
        Logger::errorPlatform(
            'VenueReminders',
            'Warning',
            'VENUE_REMINDERS_SITE_CONTEXT_FAIL',
            $e->getMessage(),
            'siteID=' . $siteId
        );
        continue;
    }

    $leadUnagreed = (int) (App::settingForSite('venues.unagreed_lead_days', $siteId) ?? '21');
    $leadRenewal  = (int) (App::settingForSite('venues.renewal_lead_days', $siteId) ?? '60');
    $leadInvoice  = (int) (App::settingForSite('venues.invoice_due_lead_days', $siteId) ?? '7');

    $recipients = Venues::resolveReminderRecipients($siteId);

    $siteTotals = [
        'booking-unagreed'  => ['due' => 0, 'sent' => 0, 'skipped' => 0],
        'agreement-renewal' => ['due' => 0, 'sent' => 0, 'skipped' => 0],
        'invoice-due'       => ['due' => 0, 'sent' => 0, 'skipped' => 0],
    ];

    // -------------------------------------------------------------------
    // 🕰️ Booking not yet agreed. NO cost figures in this body — see file
    // header's NO MONEY note.
    // -------------------------------------------------------------------
    foreach (Venues::dueUnagreedBookings($siteId, $leadUnagreed) as $b) {
        $siteTotals['booking-unagreed']['due']++;
        $refId     = (int) $b['bookingID'];
        $dueDate   = (string) $b['bookingDate'];
        $venueName = (string) $b['venueName'];

        $subject = I18n::t('venues.reminder.unagreed_subject', ['venue' => $venueName, 'date' => $dueDate]);
        $url     = venueReminderUrl('/venues/booking?id=' . $refId);
        $body = '<p><strong>' . htmlspecialchars($venueName, ENT_QUOTES, 'UTF-8') . '</strong> on '
              . htmlspecialchars($dueDate, ENT_QUOTES, 'UTF-8') . ' is still awaiting agreement — '
              . 'it does not yet count as confirmed.</p>'
              . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Review this booking</a></p>';

        $sent = sendVenueReminder($siteId, 'booking-unagreed', $refId, $dueDate, $subject, $body, $recipients);
        if ($sent === null) {
            $siteTotals['booking-unagreed']['skipped']++;
        } else {
            $siteTotals['booking-unagreed']['sent'] += $sent;
        }
    }

    // -------------------------------------------------------------------
    // 📜 Agreement renewal / notice period due.
    // -------------------------------------------------------------------
    foreach (Venues::dueAgreementRenewals($siteId, $leadRenewal) as $a) {
        $siteTotals['agreement-renewal']['due']++;
        $refId   = (int) $a['agreementID'];
        $dueDate = (string) $a['dueDate'];
        $title   = (string) $a['title'];

        $venue     = Venues::getVenue((int) $a['venueID'], $siteId);
        $venueName = $venue !== null ? (string) $venue['venueName'] : ('venue #' . (int) $a['venueID']);
        $triggerText = (string) $a['trigger'] === 'renewal' ? 'renewal is due' : 'its notice period begins';

        $subject = I18n::t('venues.reminder.renewal_subject', ['title' => $title]);
        $url     = venueReminderUrl('/venues/agreements?edit=' . $refId);
        $body = '<p><strong>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</strong> for '
              . htmlspecialchars($venueName, ENT_QUOTES, 'UTF-8') . ': ' . htmlspecialchars($triggerText, ENT_QUOTES, 'UTF-8')
              . ' on ' . htmlspecialchars($dueDate, ENT_QUOTES, 'UTF-8') . '.</p>'
              . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Review this agreement</a></p>';

        $sent = sendVenueReminder($siteId, 'agreement-renewal', $refId, $dueDate, $subject, $body, $recipients);
        if ($sent === null) {
            $siteTotals['agreement-renewal']['skipped']++;
        } else {
            $siteTotals['agreement-renewal']['sent'] += $sent;
        }
    }

    // -------------------------------------------------------------------
    // 🧾 Invoice due (overdue included by construction). Amounts ARE
    // included here — this family's recipients are the same manager/
    // admin audience that already sees costs throughout the app.
    // -------------------------------------------------------------------
    $today = date('Y-m-d');
    foreach (Venues::dueInvoices($siteId, $leadInvoice) as $inv) {
        $siteTotals['invoice-due']['due']++;
        $refId   = (int) $inv['invoiceID'];
        $dueDate = (string) $inv['dueDate'];
        $ref     = (string) ($inv['invoiceRef'] ?? ('#' . $refId));

        $venue     = Venues::getVenue((int) $inv['venueID'], $siteId);
        $venueName = $venue !== null ? (string) $venue['venueName'] : ('venue #' . (int) $inv['venueID']);
        $amountStr = number_format(((int) $inv['amountPence']) / 100, 2);
        $isOverdue = $dueDate < $today;
        $overdueNote = $isOverdue === true
            ? (', OVERDUE by ' . (int) ((new \DateTimeImmutable($today))->diff(new \DateTimeImmutable($dueDate))->days) . ' day(s)')
            : '';

        $subject = I18n::t('venues.reminder.invoice_subject', ['ref' => $ref]);
        $url     = venueReminderUrl('/venues/invoice?id=' . $refId);
        $body = '<p>Invoice <strong>' . htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') . '</strong> for '
              . htmlspecialchars($venueName, ENT_QUOTES, 'UTF-8') . ' — £' . $amountStr . ', due '
              . htmlspecialchars($dueDate, ENT_QUOTES, 'UTF-8') . htmlspecialchars($overdueNote, ENT_QUOTES, 'UTF-8') . '.</p>'
              . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Review this invoice</a></p>';

        $sent = sendVenueReminder($siteId, 'invoice-due', $refId, $dueDate, $subject, $body, $recipients);
        if ($sent === null) {
            $siteTotals['invoice-due']['skipped']++;
        } else {
            $siteTotals['invoice-due']['sent'] += $sent;
        }
    }

    Logger::activity(
        'VenueRemindersRun',
        sprintf(
            'Unagreed %d/%d sent, renewals %d/%d, invoices %d/%d',
            $siteTotals['booking-unagreed']['sent'], $siteTotals['booking-unagreed']['due'],
            $siteTotals['agreement-renewal']['sent'], $siteTotals['agreement-renewal']['due'],
            $siteTotals['invoice-due']['sent'], $siteTotals['invoice-due']['due']
        )
    );

    echo sprintf(
        "site %d: unagreed %d/%d, renewals %d/%d, invoices %d/%d\n",
        $siteId,
        $siteTotals['booking-unagreed']['sent'], $siteTotals['booking-unagreed']['due'],
        $siteTotals['agreement-renewal']['sent'], $siteTotals['agreement-renewal']['due'],
        $siteTotals['invoice-due']['sent'], $siteTotals['invoice-due']['due']
    );

    foreach ($grandTotals as $family => $ignored) {
        $grandTotals[$family]['due']     += $siteTotals[$family]['due'];
        $grandTotals[$family]['sent']    += $siteTotals[$family]['sent'];
        $grandTotals[$family]['skipped'] += $siteTotals[$family]['skipped'];
    }
}
// 🚧 NO Site::forceContext() reset after the loop — mirrors cron/asset-
// reminders.php exactly; the process exits immediately after this script.

// -----------------------------------------------------------------------------
// 📋 Grand-total text/plain summary — greppable from the scheduler's logs.
// -----------------------------------------------------------------------------
echo "Venue Bookings reminder sweep — " . count($siteIds) . " site(s)\n";
foreach ($grandTotals as $family => $t) {
    echo sprintf(
        "%-18s due=%d sent=%d skipped(already-sent)=%d\n",
        $family,
        $t['due'],
        $t['sent'],
        $t['skipped']
    );
}
