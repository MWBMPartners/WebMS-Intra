<?php
// _apps/calendar/anon-checkin.php — Anonymous check-in landing (#314)
//
// ---------------------------------------------------------------------------
// WHAT WAS WRONG BEFORE, AND WHY IT MATTERED (#519)
// ---------------------------------------------------------------------------
// This page used to look an event up with only "belongs to this
// organisation, not deleted, published" — it never asked whether the event
// was PUBLIC. Event numbers count up from 1, so anyone who is signed out
// could simply walk through numbers and read the name and date of every
// published INTERNAL event (a safeguarding case review, a finance
// committee), and `anon-checkin-save.php` would let them add a check-in row
// against it too. Verified: both /attend and /attend/save answered 200 for
// an internal event to a signed-out stranger before this fix.
//
// ---------------------------------------------------------------------------
// THE RULE NOW, AND WHY
// ---------------------------------------------------------------------------
// A PUBLIC event: anyone may see it and check in, signed in or not — this
// is the whole point of the page (migration 130's own header: "no login...
// used at the door or via a phone QR scan"), and is unchanged from before.
//
// An INTERNAL event: only a member of THAT event's own organisation, a
// global root administrator, or (in a single-organisation installation
// only) an active account with no switched-off membership row at all. The
// last branch exists because, before commit e1d0a34 (#518), creating a new
// account never created a membership row, so many real members on an
// upgraded single-organisation portal have none — see AccountGuard's own
// header for the fuller account of what changed and when. Everybody else,
// including a signed-in member of a DIFFERENT organisation, gets exactly
// the same "Event not found." as a made-up event number. That is
// deliberate: it stops a refused event and a missing one being told apart
// by anything at all, the same discipline #503 already applied to four
// other calendar handlers.
//
// WHAT THIS DOES NOT DO: it does not test the older, portal-wide `isAdmin`
// flag (rsvp-by-link.php:207 does; this page deliberately does not,
// because #514's leak-hunt finding 3 decided that flag proves nothing — any
// site administrator can tick it on any account, in any organisation). It
// does not exclude an imported event (no `externalFeedID IS NULL` — the
// importer already forces every imported row to `isPublic = 1`, so nothing
// changes there; #514 P3 is where that gets revisited). It cannot tell a
// member who was removed before #518 (whose row was deleted outright) from
// an account that has simply never had one.
declare(strict_types=1);

use Portal\Core\AccountGuard;
use Portal\Core\Auth;
use Portal\Core\Site;

$eventId = (int) ($_GET['eventID'] ?? 0);
if ($eventId <= 0) { http_response_code(400); exit('Invalid event.'); }

// The front controller (public_html/index.php) already starts the session
// for every request via Auth::ensureSession() before the maintenance gate,
// so $_SESSION['user_id'] is simply there — a second session-start call was
// tried while drafting this fix and dropped once that was confirmed; adding
// one here would be a change nobody asked for.
$viewerId  = (int) ($_SESSION['user_id'] ?? 0);
$singleOrg = AccountGuard::isSingleOrganisation() === true ? 1 : 0;

$siteId = Site::id();
// The visibility rule sits INSIDE the WHERE clause, not decided in PHP
// after the row comes back — the #503 shape, so a refused event and a
// missing one cost the database exactly the same work. isPublic/isActive
// are database yes/no flags: a prepared statement hands one back as the
// NUMBER 1, not the text '1' (#497), so the comparison is done in SQL
// rather than risking that trap in PHP.
$stmt = $mysqli->prepare(
    'SELECT eventID, eventName, startDateTime FROM tblEvents '
    . 'WHERE eventID = ? AND siteID = ? AND isDeleted = 0 '
    . "  AND status = 'published' "
    . '  AND ( isPublic = 1 '
    . '        OR EXISTS (SELECT 1 FROM tblUsers va '
    . '                    WHERE va.userID = ? AND va.isActive = 1 AND va.isRootAdmin = 1) '
    . '        OR EXISTS (SELECT 1 FROM tblUsers vm '
    . '                    WHERE vm.userID = ? AND vm.isActive = 1 '
    . '                      AND ( EXISTS (SELECT 1 FROM tblUserSites ms '
    . '                                     WHERE ms.userID = vm.userID AND ms.siteID = ? AND ms.isActive = 1) '
    . '                            OR ( ? = 1 AND NOT EXISTS (SELECT 1 FROM tblUserSites mx '
    . '                                                        WHERE mx.userID = vm.userID AND mx.siteID = ? '
    . '                                                          AND mx.isActive = 0) ) ) ) '
    . '      ) LIMIT 1'
);
// Seven `?`, seven letters in the type string, seven bound values, in this
// order: eventId, siteId, viewerId (root-admin test), viewerId (member
// test), siteId (member test), singleOrg, siteId (single-org "no
// switched-off row" test). check_bind_param_arity.py checks the type
// string against the argument COUNT; it does NOT check the number of `?`
// in the SQL against either, so a mismatch there only shows up as a live
// HTTP 500 — proved during this fix by deliberately miscounting.
$stmt->bind_param('iiiiiii', $eventId, $siteId, $viewerId, $viewerId, $siteId, $singleOrg, $siteId);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();
if ($event === null) { http_response_code(404); exit('Event not found.'); }

$pageTitle = 'Check in — ' . (string) $event['eventName'];
$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-5 text-center" style="max-width:480px;">
    <h1 class="h3 mb-2"><?php echo htmlspecialchars((string) $event['eventName'], ENT_QUOTES, 'UTF-8'); ?></h1>
    <p class="text-muted small mb-4"><?php echo htmlspecialchars(date('l j M Y', strtotime((string) $event['startDateTime'])), ENT_QUOTES, 'UTF-8'); ?></p>

    <form method="post" action="/attend/save">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
        <div class="mb-3">
            <label for="headcount" class="form-label small">How many in your group?</label>
            <input type="number" id="headcount" name="headcount" value="1" min="1" max="20" class="form-control form-control-lg text-center">
        </div>
        <button type="submit" class="btn btn-success btn-lg w-100"><i class="fa-solid fa-circle-check me-1"></i>Check in</button>
    </form>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
