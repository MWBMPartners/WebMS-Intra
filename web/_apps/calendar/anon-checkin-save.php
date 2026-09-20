<?php
// _apps/calendar/anon-checkin-save.php (#314)
//
// ---------------------------------------------------------------------------
// #519 — SAME visibility rule as anon-checkin.php, word for word
// ---------------------------------------------------------------------------
// This handler used to accept a check-in for ANY published event, internal
// ones included, from anybody at all. It now repeats the exact lookup
// anon-checkin.php uses (see that file's header for the full account of
// what was wrong and why the rule is shaped the way it is): a public event
// stays open to everyone; an internal event needs a real, active member of
// THAT event's own organisation, or a global root administrator. Keeping
// the two statements identical matters: when #514 replaces both with one
// call, both call sites must be replaceable the same way, so this file must
// never quietly drift from the other one.
//
// #533 (20 September 2026): the single-organisation compatibility branch
// this used to also accept (an active account with no membership row at
// all) is REMOVED — see anon-checkin.php's header for the full reasoning.
// In short: migration 199 fixes the DATA (a real membership row for every
// such account) instead of leaving the CODE to work around a data gap, so
// this handler is now strict everywhere on the viewer side, agreeing with
// the calendar feed and the waitlist promotion.
//
// ---------------------------------------------------------------------------
// THE RATE LIMIT — THERE WAS NONE BEFORE THIS FIX
// ---------------------------------------------------------------------------
// Bucketed on RateLimiter::clientIp(), NOT $_SERVER['REMOTE_ADDR'] directly
// — clientIp() only believes a forwarded header from a machine listed in
// portal.trustedProxies, so behind Cloudflare or similar REMOTE_ADDR is the
// proxy's own address and every visitor in the world would share one
// bucket. The bucket also folds in the event number, so a flood against one
// event cannot exhaust the allowance for a different one.
//
// Default 500 check-ins per 5 minutes PER CONNECTION PER EVENT. That has to
// be generous: a whole congregation on a venue's own wifi is ONE internet
// connection and therefore one bucket, and the 61st genuine person at the
// door must never be refused. 500 in 300 seconds leaves room for a very busy
// door (the owner chose 500 on 17 September 2026; the planning step proposed 300),
// which still stops a script dead (a flood tested against the unprotected
// code ran at about 31/second). Setting attend.rateLimit.max to '0' turns
// the limit off entirely — deliberate, and the reason it is read with a
// `??` fallback rather than Settings::get() is explained just below.
//
// RECORD FIRST, THEN COUNT (Codex catch-up B6, 20 September 2026) — the hit
// is written to the database BEFORE it is counted, not after. Counting and
// recording used to be two separate, unlocked statements, which meant two
// requests arriving together could both see a count just under the limit
// and both be let through, letting the true total run past what the limit
// promised. Recording first closes that: see the full reasoning, and the
// two small behaviour changes it brings, at the call site below.
//
// ABANDONED BUCKETS ARE PRUNED BY FAMILY, NOT JUST BY EXACT BUCKET (Codex
// catch-up B5) — because the bucket folds in the event number, a bucket
// that is only ever hit once (a made-up event number, or a genuine
// one-off event once it is over) would otherwise sit in the table
// forever. See RateLimiter::recordHit()'s own doc comment.
//
// WHAT THIS CANNOT DO: it does not stop somebody spreading check-ins across
// many different events, or across many different addresses, and it cannot
// tell one genuine person at a venue from another when they share the
// venue's own connection. It makes a bulk flood slow and noticeable, not
// impossible.
declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\RateLimiter;
use Portal\Core\Router;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /', true, 302); exit(); }

Auth::ensureSession();
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400); exit('Bad request');
}

$eventId   = (int) ($_POST['eventID'] ?? 0);
$headcount = max(1, min(20, (int) ($_POST['headcount'] ?? 1)));
$siteId    = Site::id();
$source    = (string) ($_GET['source'] ?? 'self');
if (in_array($source, ['self', 'kiosk', 'qr'], true) === false) { $source = 'self'; }

// 🚦 Rate limit BEFORE the event lookup, so a flood is stopped as cheaply
// as possible and so a refused event and a missing one still cost the same
// amount of work either way (the #503 discipline). Read with a `??`
// fallback rather than Settings::get(), on purpose: this makes the read
// VISIBLE to check_settings_keys.py, which lists it as a guarded read of an
// unseeded key in the pull-request comment — reported, not silently
// enforced, so anybody reading that comment can see the setting is real —
// while a portal whose upgrade migration has not yet run still gets a safe
// default instead of silently reading '' and switching the limit off.
$rateMax     = (int) ($SETTINGS['attend']['rateLimit']['max'] ?? '500');
$rateWindow  = (int) ($SETTINGS['attend']['rateLimit']['windowSeconds'] ?? '300');
$rateBucket  = 'attend:save:' . hash('sha256', RateLimiter::clientIp() . '|' . $eventId);
//
// 🛡️ Codex catch-up B5 + B6 (20 September 2026): TWO separate faults lived
//    in these four lines, and this comment covers both.
//
//    B5 — abandoned buckets were never cleaned up. The bucket above folds
//    in the EVENT NUMBER, so somebody varying the number on every attempt
//    — or simply every genuine one-off event, once it is over — creates a
//    bucket that is never hit again, and RateLimiter's opportunistic prune
//    only ever cleaned the bucket it was currently inserting into. The
//    third argument below ('attend:save:') tells recordHit() to prune
//    every bucket that starts with that text, not only this one — see
//    RateLimiter::recordHit()'s own doc comment for the full reasoning and
//    what was tried and rejected. Every 'attend:save:' bucket shares the
//    same window (attend.rateLimit.windowSeconds), which is what makes
//    sharing one prune family safe.
//
//    B6 — counting and recording used to be two separate, unlocked
//    statements (tooMany() then recordHit()), so two requests that both
//    arrive while the count is just under the limit could both pass the
//    count and both insert, letting the true total run past the stated
//    allowance. THE FIX: record the hit FIRST, then count — with the
//    limit tested against max + 1, so a count of exactly max+1 (this
//    request's own insert included) is the one that gets refused. WHY
//    THIS BOUNDS THE COUNT: every INSERT here commits immediately
//    (autocommit; there is no open transaction in this file), before this
//    request's own COUNT runs, and a COUNT only ever sees rows already
//    committed. Take whichever accepted request's COUNT ran LAST: every
//    OTHER accepted request's INSERT committed before that COUNT started
//    (each request inserts before it counts, and this is the latest
//    count), so that COUNT saw every one of them plus itself — and it was
//    accepted, so that total is at most max + 1, i.e. at most max
//    ACCEPTED requests can exist. Two requests arriving at the exact
//    boundary: at most one is accepted (both MAY be refused, which is the
//    safe side, never the unsafe one).
//
//    TWO BEHAVIOUR CHANGES this produces, both worth stating plainly: a
//    REFUSED attempt now also counts toward the 500-in-5-minutes
//    allowance (the limit is on ATTEMPTS, not on accepted check-ins —
//    for a flood this is the better reading, and the owner set 500 high
//    enough that a genuine busy door never gets near it); and when the
//    limit is switched off (attend.rateLimit.max = '0') nothing is
//    recorded at all any more — before this fix, hits were still recorded
//    even with the limit off, even though nothing ever reads them in that
//    case.
if ($rateMax > 0) {
    RateLimiter::recordHit($rateBucket, $rateWindow, 'attend:save:');
    // Our own hit is now already counted (see the reasoning above). Asking
    // tooMany() about max + 1 means "has the count gone BEYOND the stated
    // allowance" — the max-th attempt sees a count of max and passes; the
    // (max+1)-th attempt sees max + 1 and is refused.
    if (RateLimiter::tooMany($rateBucket, $rateMax + 1, $rateWindow) === true) {
        http_response_code(429);
        exit('Too many check-ins from this connection — please try again shortly.');
    }
}

$viewerId = (int) ($_SESSION['user_id'] ?? 0);

// Same statement as anon-checkin.php (selecting eventID only — see that
// file's header for the full explanation of every branch). The `LIMIT 1`
// is new here and changes nothing: the handler already only ever reads one
// row. Five `?`, five letters in the type string, five bound values — two
// fewer of each since #533 removed the single-organisation compatibility
// branch (see the file header).
$stmt = $mysqli->prepare(
    'SELECT eventID FROM tblEvents '
    . 'WHERE eventID = ? AND siteID = ? AND isDeleted = 0 '
    . "  AND status = 'published' "
    . '  AND ( isPublic = 1 '
    . '        OR EXISTS (SELECT 1 FROM tblUsers va '
    . '                    WHERE va.userID = ? AND va.isActive = 1 AND va.isRootAdmin = 1) '
    . '        OR EXISTS (SELECT 1 FROM tblUsers vm '
    . '                    WHERE vm.userID = ? AND vm.isActive = 1 '
    . '                      AND EXISTS (SELECT 1 FROM tblUserSites ms '
    . '                                   WHERE ms.userID = vm.userID AND ms.siteID = ? AND ms.isActive = 1)) '
    . '      ) LIMIT 1'
);
$stmt->bind_param('iiiii', $eventId, $siteId, $viewerId, $viewerId, $siteId);
$stmt->execute();
$ok = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($ok === false) {
    // Same page as anon-checkin.php's own refusal (changed 20 September 2026
    // from bare-text "Event not found" — see that file's header). The
    // rate-limit refusal above stays a plain 429: it is about the sender
    // making too many attempts, not about whether this event exists.
    Router::renderEventUnavailable();
    exit();
}

// 🗒️ ipHash below is left reading $_SERVER['REMOTE_ADDR'] DIRECTLY, exactly
// as it did before this fix — it is deliberately NOT switched to
// RateLimiter::clientIp(). Nothing reads this column today, changing it
// would make rows written before and after this fix incomparable if
// anything ever does start reading it, and neither issue this fix closes
// needs it changed. So the rate-limit bucket above and this stored hash now
// come from two different places on purpose; a follow-up issue, not a
// change here, is the right way to unify them.
$ip     = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$ipHash = $ip !== '' ? hash('sha256', $ip . '|' . $eventId) : null;

// 📭 This row is read by the attendance page's "anonymous check-ins at the
// door" panel (AnonymousCheckins::summaryForEvent(), #525) — headcount,
// source and ipHash all feed a figure shown there. #530: the browser
// description (userAgent) this INSERT used to also write is REMOVED as of
// migration 201 — nothing anywhere ever read it, before #525 or after, and
// it is not one of the columns that panel uses. See AnonymousCheckins.php
// for the fuller account of what changed and why.
$stmt = $mysqli->prepare('INSERT INTO tblAnonymousCheckins (eventID, headcount, source, ipHash) VALUES (?, ?, ?, ?)');
$stmt->bind_param('iiss', $eventId, $headcount, $source, $ipHash);
$stmt->execute();
$stmt->close();

$pageTitle = 'Checked in';
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
echo '<div class="container py-5 text-center" style="max-width:480px;">';
echo '<i class="fa-solid fa-circle-check fa-4x text-success mb-3"></i>';
echo '<h1 class="h3">Checked in</h1>';
echo '<p class="text-muted">Thanks, ' . (int) $headcount . ' added to the headcount.</p>';
echo '</div>';
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
