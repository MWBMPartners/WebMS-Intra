<?php
// Path: _apps/calendar/widget.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Embeddable widget (#336)
 * -----------------------------------------------------------------------------
 * Iframe-friendly minimal HTML — NO portal chrome.
 *   ?slug=<slug>      — one-event card
 *   ?upcoming=<N>     — list of next N events (capped at 20)
 *
 * Permissive frame headers (none set) so the page CAN be iframed from
 * external sites. Output is pure data + brand colour.
 *
 * -----------------------------------------------------------------------------
 * THIS PAGE IS NOT REACHABLE, AND THAT IS DELIBERATE. READ BEFORE RE-ENABLING.
 * -----------------------------------------------------------------------------
 * There is no web address pointing here. There used to be one — `widget` — but
 * it never worked, because a real folder called `widget` sits in the web root
 * (it holds `countdown.js`, the small script other websites embed) and the web
 * server answers for a real folder itself rather than handing the request to
 * this portal. So the address existed, and every request to it was answered by
 * the folder instead. Nobody ever reached this file.
 *
 * That dead address was removed in migration 189 rather than repointed, because
 * of what it would have switched on. This page requires NO SIGN-IN. It was
 * seeded as a public address. So the day anybody made it reachable — by moving
 * that folder, or by pointing a new address here — everything below would have
 * gone straight onto the public internet with no further thought.
 *
 * TWO FAULTS WERE FIXED HERE AT THE SAME TIME, so that this file is not a trap
 * for whoever picks it up next:
 *
 *   1. Both queries now require `isPublic = 1`. They did not before. They asked
 *      only for events that were published and not deleted — which includes
 *      every INTERNAL event, such as a leadership meeting. Every other
 *      public-facing page in this app already filtered on that column
 *      (`_apps/widget/countdown-json.php`, `_apps/calendar/index.php`,
 *      `_apps/calendar/export.php`); this one was the exception, and it was the
 *      only one that was unreachable, so nobody noticed.
 *
 *   2. It is documented, here, that this page has no access check of its own.
 *
 * IF YOU WANT TO TURN THIS ON, do all of the following, not just the first:
 *   - Give it an address that does NOT collide with a real folder or file in
 *     `web/public_html/` — `calendar/embed` would work, `widget` will not.
 *   - Gate it behind the Calendar app being switched on for that site, and
 *     behind a per-site setting that is off by default.
 *   - Decide deliberately that publishing public event names, dates, locations
 *     and descriptions to anybody on the internet is what the customer wants.
 *
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/336
 * @link https://github.com/MWBMPartners/WebMS-Intra/issues/478
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Site;

$slug     = trim((string) ($_GET['slug'] ?? ''));
$upcoming = (int) ($_GET['upcoming'] ?? 0);
$siteId   = Site::id();
// 🛑 Called Site::branding() with no argument and used the result as a
//    list. It takes one required argument and returns a single piece of text,
//    so this threw on every request. This page currently has no address of its
//    own (migration 189 removed it), so nobody could reach it to find out.
$primary  = (string) (Site::branding('color') ?? '#5e6ad2');

header('X-Frame-Options: ALLOWALL'); // explicitly opt-in to embedding
header_remove('Content-Security-Policy');

$events = [];

if ($slug !== '' && preg_match('/^[a-z0-9][a-z0-9\-]{0,79}$/i', $slug) === 1) {
    $stmt = $mysqli->prepare(
        'SELECT eventID, eventName, eventSlug, startDateTime, locationName, description '
        . 'FROM tblEvents WHERE eventSlug = ? AND siteID = ? AND isDeleted = 0 AND status = "published" '
        . '  AND isPublic = 1 LIMIT 1'
    );
    $stmt->bind_param('si', $slug, $siteId);
    $stmt->execute();
    while ($e = $stmt->get_result()->fetch_assoc()) { $events[] = $e; }
    $stmt->close();
} elseif ($upcoming > 0) {
    $n = min(max(1, $upcoming), 20);
    $stmt = $mysqli->prepare(
        'SELECT eventID, eventName, eventSlug, startDateTime, locationName '
        . 'FROM tblEvents WHERE siteID = ? AND isDeleted = 0 AND status = "published" '
        . '  AND isPublic = 1 '
        . '  AND startDateTime >= NOW() ORDER BY startDateTime ASC LIMIT ?'
    );
    $stmt->bind_param('ii', $siteId, $n);
    $stmt->execute();
    while ($e = $stmt->get_result()->fetch_assoc()) { $events[] = $e; }
    $stmt->close();
}

$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Events</title>
    <style>
        body { margin: 0; font-family: system-ui, sans-serif; background: transparent; color: #1a1a1a; }
        .ev { background: #fff; border-left: 4px solid <?php echo htmlspecialchars($primary, ENT_QUOTES, 'UTF-8'); ?>; padding: 12px 16px; margin-bottom: 8px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .ev a { color: <?php echo htmlspecialchars($primary, ENT_QUOTES, 'UTF-8'); ?>; text-decoration: none; font-weight: 600; font-size: 1.1em; }
        .ev .meta { color: #666; font-size: .85em; margin-top: 4px; }
        .ev .desc { color: #444; font-size: .9em; margin-top: 8px; }
        .empty { padding: 16px; color: #888; text-align: center; }
    </style>
</head>
<body>
<?php if (count($events) === 0): ?>
    <div class="empty">No events to show.</div>
<?php else: foreach ($events as $e): ?>
    <div class="ev">
        <a href="<?php echo $scheme . '://' . htmlspecialchars($host, ENT_QUOTES, 'UTF-8'); ?>/calendar/event?slug=<?php echo htmlspecialchars((string) $e['eventSlug'], ENT_QUOTES, 'UTF-8'); ?>" target="_top">
            <?php echo htmlspecialchars((string) $e['eventName'], ENT_QUOTES, 'UTF-8'); ?>
        </a>
        <div class="meta">
            📅 <?php echo htmlspecialchars(date('D j M Y, H:i', strtotime((string) $e['startDateTime'])), ENT_QUOTES, 'UTF-8'); ?>
            <?php if (!empty($e['locationName'])): ?>
                &middot; 📍 <?php echo htmlspecialchars((string) $e['locationName'], ENT_QUOTES, 'UTF-8'); ?>
            <?php endif; ?>
        </div>
        <?php if (!empty($e['description'])): ?>
            <div class="desc"><?php echo htmlspecialchars(mb_substr((string) $e['description'], 0, 200), ENT_QUOTES, 'UTF-8'); ?><?php echo mb_strlen((string) $e['description']) > 200 ? '…' : ''; ?></div>
        <?php endif; ?>
    </div>
<?php endforeach; endif; ?>
</body>
</html>
