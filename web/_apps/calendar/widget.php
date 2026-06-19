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
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/336
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Site;

$slug     = trim((string) ($_GET['slug'] ?? ''));
$upcoming = (int) ($_GET['upcoming'] ?? 0);
$siteId   = Site::id();
$primary  = (string) (Site::branding()['primaryColor'] ?? '#5e6ad2');

header('X-Frame-Options: ALLOWALL'); // explicitly opt-in to embedding
header_remove('Content-Security-Policy');

$events = [];

if ($slug !== '' && preg_match('/^[a-z0-9][a-z0-9\-]{0,79}$/i', $slug) === 1) {
    $stmt = $mysqli->prepare(
        'SELECT eventID, eventName, eventSlug, startDateTime, locationName, description '
        . 'FROM tblEvents WHERE eventSlug = ? AND siteID = ? AND isDeleted = 0 AND status = "published" LIMIT 1'
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
