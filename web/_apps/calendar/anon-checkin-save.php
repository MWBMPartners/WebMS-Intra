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
// THAT event's own organisation, a global root administrator, or (single-
// organisation installations only) an account with no switched-off
// membership row. Keeping the two statements identical matters: when #514
// replaces both with one call, both call sites must be replaceable the same
// way, so this file must never quietly drift from the other one.
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
// WHAT THIS CANNOT DO: it does not stop somebody spreading check-ins across
// many different events, or across many different addresses, and it cannot
// tell one genuine person at a venue from another when they share the
// venue's own connection. It makes a bulk flood slow and noticeable, not
// impossible.
declare(strict_types=1);

use Portal\Core\AccountGuard;
use Portal\Core\Auth;
use Portal\Core\RateLimiter;
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
if ($rateMax > 0 && RateLimiter::tooMany($rateBucket, $rateMax, $rateWindow) === true) {
    http_response_code(429);
    exit('Too many check-ins from this connection — please try again shortly.');
}
RateLimiter::recordHit($rateBucket, $rateWindow);

$viewerId  = (int) ($_SESSION['user_id'] ?? 0);
$singleOrg = AccountGuard::isSingleOrganisation() === true ? 1 : 0;

// Same statement as anon-checkin.php (selecting eventID only — see that
// file's header for the full explanation of every branch). The `LIMIT 1`
// is new here and changes nothing: the handler already only ever reads one
// row.
$stmt = $mysqli->prepare(
    'SELECT eventID FROM tblEvents '
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
$stmt->bind_param('iiiiiii', $eventId, $siteId, $viewerId, $viewerId, $siteId, $singleOrg, $siteId);
$stmt->execute();
$ok = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($ok === false) { http_response_code(404); exit('Event not found'); }

// 🗒️ ipHash below is left reading $_SERVER['REMOTE_ADDR'] DIRECTLY, exactly
// as it did before this fix — it is deliberately NOT switched to
// RateLimiter::clientIp(). Nothing reads this column today, changing it
// would make rows written before and after this fix incomparable if
// anything ever does start reading it, and neither issue this fix closes
// needs it changed. So the rate-limit bucket above and this stored hash now
// come from two different places on purpose; a follow-up issue, not a
// change here, is the right way to unify them.
$ua     = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
$ip     = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$ipHash = $ip !== '' ? hash('sha256', $ip . '|' . $eventId) : null;

// 📭 NOTE FOR WHOEVER NEXT TOUCHES THIS FILE: this row is written here and
// read NOWHERE — no attendance report, no admin screen, no export reads
// tblAnonymousCheckins today. That was true before this fix and is
// unchanged by it; closing #519's leak does not make the count appear
// anywhere. If a screen is ever built to show it, that is a separate,
// non-security piece of work (raised for the owner as #519's plan §11).
$stmt = $mysqli->prepare('INSERT INTO tblAnonymousCheckins (eventID, headcount, source, userAgent, ipHash) VALUES (?, ?, ?, ?, ?)');
$stmt->bind_param('iisss', $eventId, $headcount, $source, $ua, $ipHash);
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
