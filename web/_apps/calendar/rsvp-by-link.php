<?php
// Path: _apps/calendar/rsvp-by-link.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Anonymous RSVP landing page (#335)
 * -----------------------------------------------------------------------------
 * Public, no-login endpoint. ?t=<token> → fetch the invite, show event
 * details + 3 buttons (Going / Maybe / Declined). POST records the
 * response on the invite row AND inserts an anonymous tblEventRSVPs row
 * so downstream tooling (broadcast, headcount) sees the attendee.
 *
 * Single-file landing + POST handler.
 *
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/335
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\EventVisibility;
use Portal\Core\Logger;
use Portal\Core\Site;

$token = trim((string) ($_REQUEST['t'] ?? ''));
if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
    http_response_code(400); exit('Invalid invitation link.');
}

// 👤 Who is asking, and which organisation this address opened as. Both are
//    read HERE, for every request, before the invitation is looked up, and
//    whatever the token turns out to be. See "The same database work for every
//    'not found'" below for why the position matters.
//    App::user() runs its own query the first time it is asked in a request
//    (for a signed-in visitor) and remembers the answer after that. Asking
//    here means that query happens for every signed-in request, not only for
//    some tokens. A signed-out visitor gets null and no query.
$viewer         = App::user();
$viewerId       = $viewer !== null ? (int) $viewer['userID'] : null;
$openedAsSiteId = Site::id();

// 📋 Fetch invite + parent event, and decide in the same query whether it may
//    be shown at all.
//
// 🌐 Deliberately NOT limited to the current organisation (no
//    "e.siteID = Site::id()"), unlike almost every other event lookup. The
//    token is the invitation, and the address in the email does not say which
//    organisation it belongs to:
//      - calendar/event-invites-send.php builds it from the web address the
//        sender happened to be using plus "/calendar/rsvp-by-link?t=...". It
//        never adds the organisation's path prefix, so in "path" mode every
//        emailed link opens as organisation 1.
//      - In "session" mode a signed-out guest has no session, so the page
//        always opens as organisation 1 (and, because of issue #502, so does
//        every request through index.php today).
//      - Only in "subdomain" mode does the address carry the organisation.
//    So limiting the lookup to Site::id() would make a genuine guest's
//    invitation to organisation 2 answer "not found" in path mode, and in
//    session mode as soon as #502 is fixed. Instead the organisation is read
//    from the event itself (e.siteID) and used below.
//
// 🛡️ Drafts and deleted events (#503). The same rule as the event's own page
//    (calendar/event.php), applied in the WHERE clause of this one query:
//
//    - A deleted event is never shown to anybody (e.isDeleted = 0), so its
//      invitation reads "Invitation not found."
//    - Only an event whose status is published, cancelled or postponed is shown
//      to people in general. A DRAFT only to somebody who can manage events IN
//      THE EVENT'S OWN ORGANISATION. For everybody else the query simply finds
//      no row, so they get EXACTLY the same "Invitation not found." as a token
//      that matches nothing. Because this happens before the expiry check
//      further down, an expired invitation to a draft also says "not found"
//      rather than "expired", which would give the draft away. An invitation
//      sent while the event was still a draft starts working once the event is
//      published.
//
//    Who "can manage events in the event's own organisation" is, read by the
//    two LEFT JOINs: the same four things App::isAdmin() accepts, but read for
//    the EVENT'S organisation (US.siteID = e.siteID) rather than for the
//    organisation this address opened as:
//      - a global administrator (tblUsers.isRootAdmin);
//      - the older whole-portal administrator flag (tblUsers.isAdmin). It
//        counts in every organisation on the event-management pages too
//        (checked on 14 September 2026: such an account opens organisation B's
//        calendar/manage/ page and sees B's drafts), so refusing it here would
//        protect nothing;
//      - a switched-on membership of THAT organisation (US.isActive = 1)
//        carrying isSiteAdmin or isSiteRootAdmin.
//    "= 1" on these TINYINT columns matches exactly the value 1, which is what
//    App::isAdmin() treats as "on" (see App::flagIsOn()). A missing membership
//    row gives NULL, and "NULL = 1" is never true, so it counts as "no".
//    A signed-out visitor is bound as NULL; "U.userID = NULL" never matches any
//    account, so a guest never picks up anybody's rights.
//    One deliberate difference: the account must also be switched on
//    (U.isActive = 1). App::isAdmin() does not ask that. If the two ever drift
//    apart, this copy is the stricter one, so it can only answer "not found"
//    more often, never show a draft to somebody it should not.
//    ⚠️ If who may manage events changes in App::isAdmin(), change this query
//       to match.
//
//    What was wrong before #503: the event was joined with no condition on
//    status and none on isDeleted, so this page showed the name, date and
//    location of a draft, or of a deleted event, and accepted an answer to it.
//
//    What was wrong in the first fix (found by the Codex review of
//    14 September 2026): the draft test was App::isAdmin(), which means "an
//    administrator of the organisation this ADDRESS opened as". So an
//    administrator of organisation A holding an invitation to organisation B's
//    draft saw it, and could answer it, in every mode.
//
// ⏱️ The same database work for every "not found" (Codex review, second round,
//    14 September 2026).
//    The second fix checked the rights in PHP after the lookup: App::isAdmin()
//    when the event belonged to the organisation the page opened as, and a
//    SECOND prepared query otherwise. Both ran only when the token matched a
//    draft. Every answer was the same "Invitation not found.", but the work
//    behind it was not. Measured with MySQL's general query log for a
//    signed-in visitor: an unknown token ran 21 database commands; a refused
//    draft of the same organisation ran 24 (App::user()'s query, asked for the
//    first time); a refused draft of another organisation, expired or not, ran
//    27 (that plus the rights query). Over HTTP on a test machine that showed
//    up as roughly 0.8 and 1.2 milliseconds more at the median, over 300
//    requests each. That is enough for a patient person to tell "this token
//    is a real invitation to a draft" from "this token is nothing".
//
//    Now: the viewer is read before the lookup for every request (above), and
//    the rights test is part of this one query. A refused draft, an expired
//    refused draft, a deleted event and an unknown token all send the same
//    statement text in the same single round trip, all get back an empty
//    result, and then run the same PHP lines to the same 404. The general query
//    log for all of them is identical apart from the token itself.
//
//    ⚠️ What this CANNOT promise: the time is identical only as far as the
//       database connection and PHP are concerned. Inside MySQL, a token that
//       matches a row still costs a few more index look-ups (the event row, the
//       account row and the membership row) before that row is rejected, than a
//       token that matches nothing. That is microseconds, well below the noise
//       of a web request, but it is not zero, and nobody should describe this
//       page as having no timing difference at all. The token itself is 64
//       random hexadecimal characters, so guessing one is not practical either
//       way. Separately, a signed-in visitor always costs one more query than
//       a signed-out one (App::user() above), but that depends only on who is
//       asking, which the asker already knows, not on the token.
//
//    Tried and rejected:
//      - Adding "e.siteID = Site::id()" to the lookup (the first review's
//        suggestion). It breaks genuine guest invitations to any organisation
//        but the first in path mode, and in session mode once #502 is fixed,
//        because the emailed address does not carry the organisation (see the
//        comment at the top of this query).
//      - Switching the page into the event's organisation with
//        Site::forceContext() and then calling App::isAdmin(). App::user()
//        remembers the signed-in person's flags for the first organisation it
//        was asked about, so the answer would still be for the wrong one; it
//        would also change the branding and logging for the rest of the page.
//      - Keeping the rights check in PHP but running the rights query for every
//        request, including unknown tokens. The event's organisation is not
//        known until the invitation is found, so it would have to read the
//        visitor's rights in every organisation, as a second round trip on
//        every request, and PHP would still take a different path for a
//        refused draft before the 404. One query does the same job with less.
//      - Fetching the rights columns alongside the invitation and deciding in
//        PHP. The database work would match, but a refused draft would still
//        run extra PHP lines (the status and flag tests) that an unknown token
//        skips. Putting the rule in the WHERE clause makes both an empty result.
//      - Keeping App::isAdmin() for an event of the organisation the page
//        opened as. Its query (App::user()) ran only when a draft was found,
//        which is exactly the difference being removed. It is still the same
//        four flags, now read by the query below for that same organisation.
//      - Adding a random or fixed delay before "not found". It slows every
//        visitor, and random noise can be averaged away with enough requests,
//        so it hides nothing reliably.
//
//    Published invitations of another organisation: they keep working. When
//    the page opens as organisation A for an invitation to organisation B's
//    PUBLISHED event (a guest in path or session mode, or somebody who happens
//    to be signed in to A), it is shown and can be answered. The token is the
//    invitation: 64 random hexadecimal characters sent to one named address.
//    Signing in to A adds nothing, because a signed-out visitor holding the
//    same link sees exactly the same page. And refusing would break the normal
//    case, since in path and session mode most of B's guests arrive at an
//    address that opens as A.
//    ⚠️ Cannot do: the page is still drawn with the branding of the
//       organisation the address opened as, and Logger::activity() records the
//       answer under that organisation, not the event's.
//
//    ⚠️ Deliberately NOT done here: an event that is not marked public does not
//    ask the visitor to sign in, unlike the event page. An invitation is made
//    only by an administrator or that event's coordinator
//    (calendar/event-invites-send.php), is sent to one named email address,
//    and exists so that people with no portal account can answer (#335).
//    Asking them to sign in would make it useless for exactly those people.
//
//    Whether internal (members-only) events should be invitable at all was put
//    to the owner, because the original proposal (#335) said public events
//    only, and this page never enforced that. On 14 September 2026 the owner
//    decided internal events STAY invitable: the link is sent on purpose, to one
//    named person, by someone trusted to run the event, so a guest speaker or a
//    visiting family can be invited to a members-only event. The cost, accepted
//    knowingly: anyone the email is forwarded to sees this event's name, date
//    and location. Do not "fix" this by adding an isPublic check here without
//    going back to the owner (decision recorded on #503).
//
// 👁️ #514 part P2 — events copied in from OUTSIDE calendars. The owner decision
//    above is about the portal's OWN events, and it still stands: the shared
//    rule, EventVisibility::where(), is appended here in "invite" mode, which
//    leaves the portal's own events exactly as this page already treats them
//    (not restricted by audience; the draft test above still applies) and
//    applies the #514 levels only to an imported event. An imported event is
//    therefore shown only to somebody its own level admits, with the viewer
//    taken from the session (0 for a guest), whatever the token says. Its
//    location is shown only when the viewer may see full details (canSeeFull
//    1; for the portal's own events that is always 1). The manager branch
//    above (`U.isAdmin = 1` included) is this page's own older test for
//    drafts of the portal's own events, which "invite" mode leaves alone; it is
//    not changed here.
//    Binding is by position: canSeeFull's values first (the SELECT list comes
//    before the joins and the WHERE), then the viewer for the manager join,
//    then the token, then the rule's own values.
$ruleViewerId = EventVisibility::sessionViewerId();
$today        = date('Y-m-d');
$visibility   = EventVisibility::where('e', EventVisibility::MODE_INVITE, $ruleViewerId, $today);
$fullDetail   = EventVisibility::fullDetailSelect('e', EventVisibility::MODE_INVITE, $ruleViewerId, $today, 'canSeeFull');
// 🧩 The canSeeFull expression goes in through sprintf()'s `%s`, not by
//    joining it in with `.`: tools/audit-checks/check_sql_columns.py does
//    not recognise a statement at all when PHP code sits between SELECT
//    and FROM, which would hide this whole statement — the page's own
//    column names included — from it (measured while building #514 part
//    P2). The text sprintf() puts in is SQL built by EventVisibility
//    itself, never anything a visitor sent; every value is still bound.
$stmt = $mysqli->prepare(sprintf(
    'SELECT i.inviteID, i.eventID, i.email, i.displayName, i.expiresAt, i.usedAt, i.response, '
    . '       e.eventName, e.eventSlug, e.startDateTime, e.endDateTime, e.locationName, e.status, e.siteID, %s '
    . 'FROM tblEventRSVPInvites i '
    . 'JOIN tblEvents e ON e.eventID = i.eventID '
    . 'LEFT JOIN tblUsers U ON U.userID = ? AND U.isActive = 1 '
    . 'LEFT JOIN tblUserSites US ON US.userID = U.userID AND US.siteID = e.siteID AND US.isActive = 1 '
    . 'WHERE i.token = ? AND e.isDeleted = 0 '
    . "  AND (e.status IN ('published', 'cancelled', 'postponed') "
    . '       OR U.isRootAdmin = 1 OR U.isAdmin = 1 OR US.isSiteAdmin = 1 OR US.isSiteRootAdmin = 1)',
    $fullDetail['sql']
) . $visibility['sql'] . ' LIMIT 1');
$stmt->bind_param(
    $fullDetail['types'] . 'is' . $visibility['types'],
    ...array_merge($fullDetail['params'], [$viewerId, $token], $visibility['params'])
);
$stmt->execute();
$invite = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

// 👁️ At "title, date and time only" the location is emptied before anything
//    below can print it (#514 part P2). The number comes back as 1 or 0.
if ($invite !== null) {
    $invite = EventVisibility::redact($invite, (int) $invite['canSeeFull'] === 1);
}

if ($invite === null) {
    http_response_code(404); exit('Invitation not found.');
}
if (strtotime((string) $invite['expiresAt']) < time()) {
    http_response_code(410); exit('Invitation has expired.');
}

// 🔗 "View event details" link.
//
//    Shown only when the event belongs to the organisation this page opened as.
//    The event page finds an event by its slug WITHIN the organisation the
//    address opens as, and a slug is only unique within one organisation.
//    Before, a guest of organisation B whose link opened as A (path or session
//    mode) was sent to A's event of the same slug, or to "not found"
//    (reproduced 14 September 2026 with two events called "shared-slug"). A
//    correct link to B cannot be built from here in every mode (session mode
//    has no address for B at all), so it is left out.
//
//    Built with Site::url(), which puts the organisation's path prefix in front
//    in path mode (for example /orgb/calendar/event?slug=...). What was wrong
//    before (Codex review, second round, 14 September 2026): the link was a
//    bare "/calendar/event?slug=...". Opened at /orgb/calendar/rsvp-by-link,
//    that dropped "/orgb", so the click opened as organisation 1 and showed
//    organisation A's event with the same slug (reproduced on 14 September
//    2026). In the other modes Site::url() adds nothing, which is right: the
//    host name (subdomain mode) or the session carries the organisation, and
//    the link keeps the same host. The slug is also URL-encoded, so an unusual
//    character cannot end the query string early.
//    ⚠️ Cannot do: Site::url() gives only the path. It relies on the page
//       having opened as the right organisation, which is exactly what the
//       condition above checks.
$showEventLink   = (int) $invite['siteID'] === $openedAsSiteId;
$eventDetailsUrl = Site::url('calendar/event?slug=' . rawurlencode((string) $invite['eventSlug']));

// 💾 POST → record response.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = (string) ($_POST['response'] ?? '');
    if (in_array($response, ['going', 'maybe', 'declined'], true) === false) {
        http_response_code(400); exit('Invalid response.');
    }

    $inviteId = (int) $invite['inviteID'];
    $stmt = $mysqli->prepare(
        'UPDATE tblEventRSVPInvites SET response = ?, usedAt = NOW() WHERE inviteID = ?'
    );
    $stmt->bind_param('si', $response, $inviteId);
    $stmt->execute();
    $stmt->close();

    // 📋 The invite row is the source of truth. Earlier drafts tried to
    //    mirror the response into tblEventRSVPs as an anonymous row, but
    //    that table requires userID NOT NULL and has no externalEmail/
    //    externalName/source columns — every previous attempt fataled at
    //    prepare() and was silently swallowed. Downstream consumers
    //    (broadcaster, headcount, manage UI) join tblEventRSVPInvites
    //    directly when they need anonymous responses.

    Logger::activity('EventInviteResponded', 'Invite #' . $inviteId . ' = ' . $response);

    $confirmation = ['going' => "Great — we'll see you there.", 'maybe' => "Thanks — we'll keep your seat warm.", 'declined' => "Thanks for letting us know."][$response];
    $pageTitle = 'RSVP recorded';
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
    echo '<div class="container py-5 text-center" style="max-width:560px;">';
    echo '<i class="fa-solid fa-check-circle fa-3x text-success mb-3"></i>';
    echo '<h1 class="h3">' . htmlspecialchars($confirmation, ENT_QUOTES, 'UTF-8') . '</h1>';
    echo '<p class="text-muted">Your response to "<strong>' . htmlspecialchars((string) $invite['eventName'], ENT_QUOTES, 'UTF-8') . '</strong>" has been recorded.</p>';
    if ($showEventLink === true) {
        echo '<a href="' . htmlspecialchars($eventDetailsUrl, ENT_QUOTES, 'UTF-8') . '" class="btn btn-outline-primary mt-3">View event details</a>';
    }
    echo '</div>';
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
    exit();
}

// 🎨 GET → render the landing page.
$pageTitle = 'RSVP — ' . (string) $invite['eventName'];
$when = date('l j M Y, H:i', strtotime((string) $invite['startDateTime']));
$currentResponse = (string) ($invite['response'] ?? '');
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container py-4" style="max-width:560px;">
    <div class="card shadow-sm">
        <div class="card-body p-4 text-center">
            <h1 class="h4 mb-2"><i class="fa-solid fa-calendar-day me-2 text-primary"></i><?php echo htmlspecialchars((string) $invite['eventName'], ENT_QUOTES, 'UTF-8'); ?></h1>
            <p class="text-muted mb-1"><?php echo htmlspecialchars($when, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php if (!empty($invite['locationName'])): ?>
                <p class="text-muted small"><i class="fa-solid fa-location-dot me-1"></i><?php echo htmlspecialchars((string) $invite['locationName'], ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>

            <hr>

            <p class="mb-3">
                <?php if ($invite['displayName'] !== null): ?>
                    Hi <?php echo htmlspecialchars((string) $invite['displayName'], ENT_QUOTES, 'UTF-8'); ?> — can you make it?
                <?php else: ?>
                    Can you make it?
                <?php endif; ?>
            </p>

            <?php if ($currentResponse !== ''): ?>
                <div class="alert alert-info small">You already responded: <strong><?php echo htmlspecialchars(ucfirst($currentResponse), ENT_QUOTES, 'UTF-8'); ?></strong>. Click again to change.</div>
            <?php endif; ?>

            <form method="post" class="d-flex flex-column gap-2">
                <?php // Auth lives on the token in the URL; the CSRF token is included for
                      // any session-side validation that may want it (e.g. once a viewer
                      // has logged in mid-flow). Skipped at the handler when the t= token
                      // resolves a valid invite row. ?>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(\Portal\Core\Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="t" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" name="response" value="going" class="btn btn-success btn-lg"><i class="fa-solid fa-check me-1"></i>Yes, I'm going</button>
                <button type="submit" name="response" value="maybe" class="btn btn-warning btn-lg"><i class="fa-solid fa-question me-1"></i>Maybe</button>
                <button type="submit" name="response" value="declined" class="btn btn-outline-secondary btn-lg"><i class="fa-solid fa-xmark me-1"></i>Sorry, can't make it</button>
            </form>

            <?php if ($showEventLink === true): ?>
            <p class="text-muted small mt-3 mb-0">
                <a href="<?php echo htmlspecialchars($eventDetailsUrl, ENT_QUOTES, 'UTF-8'); ?>" class="text-decoration-none">See full event details</a>
            </p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
