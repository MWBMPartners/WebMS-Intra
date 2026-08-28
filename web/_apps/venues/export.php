<?php
// Path: _apps/venues/export.php
/**
 * -----------------------------------------------------------------------------
 * Venue Bookings — Schedule CSV Export 📊
 * -----------------------------------------------------------------------------
 * GET, viewer. Streams the year (or from/to) schedule for one venue as a
 * CSV — Date, Day, Venue, Room, Usage type, Times, Status, Notes, plus a
 * Cost column appended ONLY when `Venues::canManage()` (money is manager-
 * only everywhere in this app). Matches the source spreadsheet's row-per-
 * date shape 1:1 (02-app-design.md §9.1) so a church can round-trip its
 * own record. Uses `CsvExporter::download()` (`_core/CsvExporter.php`
 * l.37) — BOM + fputcsv, filename sanitised internally, exits on write.
 *
 * @package   Portal\Venues
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/429
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\CsvExporter;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\Venues;

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$isMgr  = Venues::canManage();

// -----------------------------------------------------------------------------
// 🔎 Resolve the venue — explicit ?venue= (site-scoped, 404 if foreign/
// missing), else the site's only active venue, else send the viewer back to
// pick one (no cross-tenant oracle either way).
// -----------------------------------------------------------------------------
$venueId = (int) ($_GET['venue'] ?? 0);
$venue   = null;
if ($venueId > 0) {
    $venue = Venues::getVenue($venueId, $siteId);
    if ($venue === null) {
        Router::renderError(404);
        return;
    }
} else {
    $active = Venues::listVenues($siteId, true);
    if (count($active) === 1) {
        $venueId = (int) $active[0]['venueID'];
        $venue = $active[0];
    }
}
if ($venue === null) {
    $_SESSION['flash_msg']  = 'Choose a venue before exporting its schedule.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: /venues');
    exit();
}

$year = (int) ($_GET['year'] ?? date('Y'));
$from = (string) ($_GET['from'] ?? '');
$to   = (string) ($_GET['to'] ?? '');

$filters = ['venueID' => $venueId, 'includeRejected' => true];
if ($from !== '' && $to !== '') {
    $filters['dateFrom'] = $from;
    $filters['dateTo']   = $to;
} else {
    $filters['year'] = $year;
}

$bookings = Venues::listBookings($siteId, $filters);

$headers = ['Date', 'Day', 'Venue', 'Room', 'Usage type', 'Times', 'Status', 'Notes'];
if ($isMgr === true) {
    $headers[] = 'Cost';
}

$rows = [];
foreach ($bookings as $b) {
    $times = '';
    if ($b['startTime'] !== null && $b['endTime'] !== null) {
        $times = substr((string) $b['startTime'], 0, 5) . '-' . substr((string) $b['endTime'], 0, 5);
    }
    $row = [
        'Date'       => (string) $b['bookingDate'],
        'Day'        => date('D', strtotime((string) $b['bookingDate'])),
        'Venue'      => (string) $b['venueName'],
        'Room'       => (string) ($b['roomName'] ?? ''),
        'Usage type' => (string) $b['usageTypeName'],
        'Times'      => $times,
        'Status'     => (string) $b['statusName'],
        'Notes'      => (string) ($b['notes'] ?? ''),
    ];
    if ($isMgr === true) {
        $row['Cost'] = $b['costPence'] !== null ? number_format((int) $b['costPence'] / 100, 2) : '';
    }
    $rows[] = $row;
}

$slug = trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower((string) $venue['venueName'])), '-');
$filename = 'venue-schedule-' . $slug . '-' . $year . '.csv';

CsvExporter::download($filename, $rows, $headers);
