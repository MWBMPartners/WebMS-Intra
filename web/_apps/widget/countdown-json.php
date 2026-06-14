<?php
// Path: _apps/widget/countdown-json.php
/**
 * -----------------------------------------------------------------------------
 * Embeddable Countdown Widget — JSON feed 📡 (#319)
 * -----------------------------------------------------------------------------
 * Public read-only endpoint that returns the next upcoming event for the
 * active site as JSON. Consumed by the static countdown.js embed widget
 * that churches paste onto their own website.
 *
 * Public — no auth required. CORS open (this is by design — external sites
 * are the entire point of the widget).
 *
 * @package   Portal\Widget
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/319
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Site;

// 🌐 CORS — open to all origins (widget embeds across the internet).
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Cache-Control: public, max-age=60');
header('Content-Type: application/json; charset=utf-8');

$siteId = Site::id();

// 📋 Next upcoming event on this site. Public visibility only (no
//    leadership-only events leak via the public widget feed).
$nextEvent = null;
$stmt = $mysqli->prepare(
    'SELECT eventID, title, location, startDateTime, endDateTime '
    . 'FROM tblEvents '
    . 'WHERE siteID = ? '
    . '  AND (visibility = "public" OR visibility = "members") '
    . '  AND startDateTime >= NOW() '
    . '  AND isCancelled = 0 '
    . 'ORDER BY startDateTime ASC LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $nextEvent = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

// 🏷️ Brand-aware site name + product name (multi-brand layer, #296).
$siteName    = Site::branding('name') ?? Site::productName();
$productName = Site::productName();

if ($nextEvent === null) {
    echo json_encode([
        'siteName'     => $siteName,
        'productName'  => $productName,
        'nextEvent'    => null,
        'message'      => 'No upcoming services scheduled',
    ], JSON_UNESCAPED_SLASHES);
    exit();
}

// 📡 Emit a minimal, stable shape — the JS widget reads only these keys.
echo json_encode([
    'siteName'    => $siteName,
    'productName' => $productName,
    'nextEvent'   => [
        'id'        => (int) $nextEvent['eventID'],
        'title'     => (string) $nextEvent['title'],
        'location'  => (string) ($nextEvent['location'] ?? ''),
        'startsAt'  => date('c', strtotime((string) $nextEvent['startDateTime'])),
        'endsAt'    => $nextEvent['endDateTime'] !== null
            ? date('c', strtotime((string) $nextEvent['endDateTime']))
            : null,
    ],
], JSON_UNESCAPED_SLASHES);
