<?php
// Path: public_html/calendar/feed.php
/**
 * iCalendar feed endpoint.
 *
 * URLs:
 *   /calendar.ics?token=PERSONAL_TOKEN          — user's full visible calendar
 *   /calendar.ics?token=PERSONAL_TOKEN&days=90  — bounded window
 *
 * Returns text/calendar with a 15-minute cache hint so well-behaved
 * clients don't hammer the server.
 *
 * @package   Portal\Calendar
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/271
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\EventVisibility;
use Portal\Core\Ical;
use Portal\Core\Site;

$token = (string) ($_GET['token'] ?? '');
$userId = Ical::userIdForToken($token);
if ($userId <= 0) {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit('Invalid token.');
}

$db = App::db();

// 🪞 Resolve the user's site for scoping. Multi-site users are not
//    currently supported in the feed — picks their primary.
//
// 🛡️ Members only (#503). This feed includes events that are not marked
//    public, and the event page's rule for those is "members only": somebody
//    who is signed in. A feed address works without signing in, so the next
//    best thing is to check that the person the address belongs to could
//    still sign in and still belongs to an organisation (site):
//      - their account is switched on (tblUsers.isActive = 1, the same test
//        Auth::loginLocal makes before letting anybody sign in);
//      - they have a membership that is switched on (tblUserSites.isActive = 1);
//      - that organisation is switched on (tblSites.isActive = 1, as
//        Site::resolveDefaultSiteForUser requires).
//    If any of those fails, the reply is the same "Invalid token." as an
//    address that belongs to nobody.
//
//    What was wrong before: the lookup read tblUserSites alone, and when it
//    found nothing it fell back to site 1. Offboarding switches off the account
//    and every membership but does not clear the feed address, so somebody who
//    had left kept receiving site 1's calendar, internal events included, for
//    as long as their calendar app kept asking.
//
//    🤝 #533 (20 September 2026): this page's strict rule — no membership row
//    means no feed, full stop — used to be the ODD ONE OUT among the pages
//    that decide who may see an internal event. The check-in page
//    (anon-checkin.php) and the waitlist promotion (Events.php) both used to
//    make a single-organisation exception for an account with no membership
//    row at all, because before #518 creating an account never wrote one. This
//    page never had that exception, so those three row-less accounts could
//    check in and be promoted off a waiting list while their OWN calendar
//    subscription answered "Invalid token" — a real disagreement, not merely
//    an inconsistency in wording. Migration 199 fixes the underlying DATA (a
//    real membership row for every such account) instead of leaving code to
//    paper over it, and the other two pages have now dropped their exceptions
//    to match this one. All three now apply the SAME rule — an active
//    membership row for the organisation, or a global root administrator —
//    and this page needed no code change to get there.
//
//    ⚠️ Cannot do: it does not stop the address working for somebody who is
//    still a member. Only regenerating or revoking it on the Calendar feed page
//    (calendar/account-feed.php) does that.
$siteId = 0;
$stmt = $db->prepare(
    'SELECT US.siteID FROM tblUserSites US '
    . 'JOIN tblUsers U ON U.userID = US.userID '
    . 'JOIN tblSites S ON S.siteID = US.siteID '
    . 'WHERE US.userID = ? AND US.isActive = 1 AND U.isActive = 1 AND S.isActive = 1 '
    . 'ORDER BY US.siteID LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row !== null && (int) $row['siteID'] > 0) {
        $siteId = (int) $row['siteID'];
    }
}
if ($siteId <= 0) {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit('Invalid token.');
}

$daysFwd = max(7, min(365, (int) ($_GET['days'] ?? 365)));
$fromDate = date('Y-m-d', strtotime('-30 days'));
$toDate   = date('Y-m-d', strtotime('+' . $daysFwd . ' days'));

$events = [];

// 📅 Standard calendar events.
//
// 🛡️ Which events (#503). The event page's rule is: only an event whose status
//    is published, cancelled or postponed is shown to people in general, and a
//    draft only to somebody who can manage events. This feed leaves drafts out
//    for EVERYBODY, event managers included, for two reasons. The feed is
//    identified by its address, not by anybody signing in, so "can manage
//    events" (App::isAdmin(), which reads the signed-in session) has nothing
//    to go on here. And the Calendar feed page promises subscribers
//    "published portal events", while the feed ends up copied into Google,
//    Apple or Outlook calendars, where a draft does not belong.
//    Cancelled and postponed events stay in, marked as such (see STATUS below),
//    so a subscriber's calendar shows the change instead of the event quietly
//    vanishing or looking as if it is still on.
//    Deleted events (isDeleted = 1) are never included.
//
//    What was wrong before: the query had no condition on status and none on
//    isDeleted, so every subscriber's calendar received drafts, and even
//    events that had been deleted, with their descriptions and locations.
//
// 👁️ Which events, part two — #514 part P2. Until then every event of the
//    holder's organisation that passed the status test above went into the
//    feed, members-only ones included (the holder is a member, checked above).
//    Events copied in from outside calendars (#514) have their own levels, so
//    the one shared rule, EventVisibility::where(), now decides, in "token"
//    mode with the feed's HOLDER as the viewer. Token mode deliberately leaves
//    the administrator branches OFF (#514 leak-hunt finding 24): a feed
//    address works without signing in and is copied into outside calendar
//    apps, so it must never carry an administrator's hidden events out of the
//    portal. canSeeFull (1 = full details, 0 = title, date and time only) is
//    selected beside each row; at 0 the row goes out with no description, no
//    location and no last-changed time. The fragment is appended after the
//    literal conditions (tools/audit-checks/check_sql_columns.py reads only
//    those; the fragment's own column names are the self-test's job), and the
//    canSeeFull values are bound first because the SELECT list comes before
//    the WHERE.
$today      = date('Y-m-d');
$visibility = EventVisibility::where('e', EventVisibility::MODE_TOKEN, $userId, $today);
$fullDetail = EventVisibility::fullDetailSelect('e', EventVisibility::MODE_TOKEN, $userId, $today, 'canSeeFull');
// 🧩 The canSeeFull expression goes in through sprintf()'s `%s`, not by
//    joining it in with `.`: tools/audit-checks/check_sql_columns.py does
//    not recognise a statement at all when PHP code sits between SELECT
//    and FROM, which would hide this whole statement — the page's own
//    column names included — from it (measured while building #514 part
//    P2). The text sprintf() puts in is SQL built by EventVisibility
//    itself, never anything a visitor sent; every value is still bound.
$stmt = $db->prepare(sprintf(
    'SELECT e.eventID, e.eventName, e.description, e.startDateTime, e.endDateTime, e.isAllDay, '
    . '       e.timezone, e.locationName, e.locationAddress, e.eventSlug, e.updatedAt, e.status, %s '
    . 'FROM tblEvents e '
    . 'WHERE e.siteID = ? AND e.isDeleted = 0 '
    . '  AND e.startDateTime >= ? AND e.startDateTime <= ? '
    . "  AND e.status IN ('published', 'cancelled', 'postponed')",
    $fullDetail['sql']
) . $visibility['sql'] . ' ORDER BY e.startDateTime');
if ($stmt !== false) {
    $stmt->bind_param(
        $fullDetail['types'] . 'iss' . $visibility['types'],
        ...array_merge($fullDetail['params'], [$siteId, $fromDate, $toDate], $visibility['params'])
    );
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        // 👁️ #514 part P2: empty the limited details before anything below
        //    reads them. The number comes back as 1 or 0, never true/false.
        $canSeeFull = (int) $r['canSeeFull'] === 1;
        $r          = EventVisibility::redact($r, $canSeeFull);
        $location = trim((string) ($r['locationName'] ?? '') . ' ' . (string) ($r['locationAddress'] ?? ''));
        $entry = [
            // 🚦 The same status words the single-event download sends
            //    (calendar/export.php): CANCELLED, TENTATIVE for postponed,
            //    otherwise CONFIRMED. Calendar apps show a cancelled event
            //    struck through or remove it, which is the point.
            'status'       => match ((string) $r['status']) {
                'cancelled' => 'CANCELLED',
                'postponed' => 'TENTATIVE',
                default     => 'CONFIRMED',
            },
            'uid'          => 'event-' . $r['eventID'] . '@portal.webms-intra',
            'summary'      => (string) $r['eventName'],
            'description'  => (string) ($r['description'] ?? ''),
            'location'     => $location !== '' ? $location : null,
            'startsAt'     => (string) $r['startDateTime'],
            'endsAt'       => (string) ($r['endDateTime'] ?? ''),
            'allDay'       => (int) $r['isAllDay'] === 1,
        ];
        // #514 part P2: no LAST-MODIFIED at "title, date and time only" — when
        // an event last changed can itself tell somebody that its hidden
        // details did (the #514 plan, section 1.4). Left out entirely, not
        // sent empty.
        if ($canSeeFull === true) {
            $entry['lastModified'] = (string) ($r['updatedAt'] ?? '');
        }
        $events[] = $entry;
    }
    $stmt->close();
}

// 🗓️ User's rota duties (if rota app is enabled).
try {
    $stmt = $db->prepare(
        'SELECT s.slotID, s.slotDate, s.startTime, s.endTime, r.name AS roleName '
        . 'FROM tblRotaSlot s JOIN tblRotaRoleType r ON r.roleTypeID = s.roleTypeID '
        . 'WHERE s.siteID = ? AND s.assignedToID = ? AND s.slotDate >= ? AND s.slotDate <= ? '
        . 'ORDER BY s.slotDate'
    );
    if ($stmt !== false) {
        $stmt->bind_param('iiss', $siteId, $userId, $fromDate, $toDate);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) {
            $allDay = $r['startTime'] === null;
            $start = (string) $r['slotDate'] . ($allDay ? '' : ' ' . substr((string) $r['startTime'], 0, 5));
            $end   = $allDay
                ? (string) $r['slotDate']
                : (string) $r['slotDate'] . ' ' . substr((string) ($r['endTime'] ?? $r['startTime']), 0, 5);
            $events[] = [
                'uid'      => 'rota-' . $r['slotID'] . '@portal.webms-intra',
                'summary'  => 'Duty: ' . (string) $r['roleName'],
                'startsAt' => $start,
                'endsAt'   => $end,
                'allDay'   => $allDay,
            ];
        }
        $stmt->close();
    }
} catch (\Throwable $ignored) {
    // 🛡️ Rota tables may not exist if the app isn't installed — silent.
}

$siteName = (string) (App::settings()['site']['name'] ?? 'Portal');
$ics = Ical::emit($siteName . ' — My Calendar', $events);

header('Content-Type: text/calendar; charset=utf-8');
header('Cache-Control: private, max-age=900');
header('Content-Disposition: inline; filename="portal-calendar.ics"');
echo $ics;
