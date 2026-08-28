<?php
// Path: _apps/venues/venue.php
/**
 * -----------------------------------------------------------------------------
 * Venue Bookings — Venue Detail 🏛️
 * -----------------------------------------------------------------------------
 * Read-only detail page for a single venue: identity/address/landlord card,
 * rooms, usage types (with current resolved window), an agreements summary
 * (manager only — money-adjacent), and the next 10 upcoming bookings.
 * Viewers see everything except the agreements summary and cost figures;
 * managers additionally see action links into the config/agreement screens.
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
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\Venues;

require_once PORTAL_CORE . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'location-display.php';
require_once PORTAL_CORE . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'location-map-assets.php';

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$isMgr  = Venues::canManage();

// 🚪 Site-scoped fetch — uniform 404 for a missing/foreign id (no oracle).
$venueId = (int) ($_GET['id'] ?? 0);
$venue   = Venues::getVenue($venueId, $siteId);
if ($venue === null) {
    Router::renderError(404);
    return;
}

// 🗺️ #456 Chunk A — page-scoped CSP widening for OSM tiles, ONLY when the
// venue has coordinates to show on a map (#386 precedent). Must be set
// BEFORE header.php is required.
if ($venue['latitude'] !== null && $venue['longitude'] !== null) {
    $cspImgExtra = 'https://*.tile.openstreetmap.org';
}

$rooms      = Venues::listRooms($venueId, $siteId, false);
$usageTypes = Venues::listUsageTypes($venueId, $siteId, false);
$agreements = $isMgr === true ? Venues::listAgreements($siteId, ['venueID' => $venueId]) : [];

$today = date('Y-m-d');
$upcoming = array_slice(
    Venues::listBookings($siteId, ['venueID' => $venueId, 'dateFrom' => $today, 'includeRejected' => false]),
    0,
    10
);

$pageTitle   = $venue['venueName'];
$pageSection = 'venues';
$breadcrumbs = ['Dashboard' => '/', 'Venue Bookings' => '/venues', (string) $venue['venueName'] => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

$esc = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3 gap-2">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-building-columns me-2"></i><?php echo $esc($venue['venueName']); ?></h1>
        <?php if ((int) $venue['isActive'] === 0): ?><span class="badge bg-secondary">inactive</span><?php endif; ?>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="/venues?venue=<?php echo (int) $venueId; ?>" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-calendar-days me-1"></i>Schedule</a>
        <?php if ($isMgr === true): ?>
            <a href="/venues/booking?venue=<?php echo (int) $venueId; ?>" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus me-1"></i>Booking</a>
            <a href="/venues/manage?edit=<?php echo (int) $venueId; ?>" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-pen me-1"></i>Edit</a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">Identity</div>
            <div class="card-body small">
                <p class="mb-1"><strong>Address:</strong>
                    <?php
                    $addrParts = array_filter([
                        $venue['addressLine1'] ?? null, $venue['addressLine2'] ?? null, $venue['city'] ?? null,
                        $venue['region'] ?? null, $venue['postcode'] ?? null,
                    ], static fn ($p) => $p !== null && $p !== '');
                    echo count($addrParts) > 0 ? $esc(implode(', ', $addrParts)) : '—';
                    ?>
                </p>
                <?php portal_location_display([
                    'lat'     => $venue['latitude'] !== null ? (float) $venue['latitude'] : null,
                    'lng'     => $venue['longitude'] !== null ? (float) $venue['longitude'] : null,
                    'w3w'     => $venue['what3words'] ?? null,
                    'showMap' => true,
                    'mapId'   => 'venueMap',
                ]); ?>
                <p class="mb-1"><strong>Timezone:</strong> <?php echo $esc($venue['timezone']); ?></p>
                <p class="mb-1"><strong>Landlord:</strong> <?php echo $esc($venue['landlordName'] ?? '—'); ?></p>
                <p class="mb-1"><strong>Caretaker:</strong>
                    <?php echo $esc($venue['caretakerName'] ?? '—'); ?>
                    <?php if (($venue['caretakerPhone'] ?? '') !== ''): ?> (<?php echo $esc($venue['caretakerPhone']); ?>)<?php endif; ?>
                </p>
                <?php if (($venue['notes'] ?? '') !== ''): ?>
                    <p class="mb-0"><strong>Notes:</strong> <?php echo nl2br($esc($venue['notes'])); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">Rooms</div>
            <div class="card-body small">
                <?php if (count($rooms) === 0): ?>
                    <p class="text-muted mb-0">This is a single-space venue — no rooms configured.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($rooms as $rm): ?>
                            <li class="mb-1">
                                <?php echo $esc($rm['roomName']); ?>
                                <?php if ($rm['capacity'] !== null): ?><span class="text-muted">— capacity <?php echo (int) $rm['capacity']; ?></span><?php endif; ?>
                                <?php if ((int) $rm['isActive'] === 0): ?><span class="badge bg-secondary ms-1">inactive</span><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header">Usage types</div>
            <div class="card-body small">
                <?php if (count($usageTypes) === 0): ?>
                    <p class="text-muted mb-0">No usage types configured.</p>
                <?php else: ?>
                    <div class="row g-2">
                        <?php foreach ($usageTypes as $ut): ?>
                            <div class="col-md-4">
                                <div class="border rounded p-2 h-100">
                                    <strong><?php echo $esc($ut['typeName']); ?></strong>
                                    <span class="badge bg-<?php echo $ut['usageKind'] === 'hire' ? 'primary' : ($ut['usageKind'] === 'closed' ? 'secondary' : 'danger'); ?> ms-1">
                                        <?php echo $esc($ut['usageKind']); ?>
                                    </span>
                                    <?php if ((int) $ut['isActive'] === 0): ?><span class="badge bg-secondary ms-1">inactive</span><?php endif; ?>
                                    <br>
                                    <span class="text-muted">
                                        <?php
                                        $rw = $ut['resolvedWindow'] ?? null;
                                        if ($rw !== null && $rw['start'] !== null && $rw['end'] !== null) {
                                            echo $esc(substr((string) $rw['start'], 0, 5) . '–' . substr((string) $rw['end'], 0, 5));
                                        } else {
                                            echo 'no default times';
                                        }
                                        ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($isMgr === true): ?>
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    Agreements
                    <a href="/venues/agreements?venue=<?php echo (int) $venueId; ?>" class="small">View all →</a>
                </div>
                <div class="card-body small">
                    <?php if (count($agreements) === 0): ?>
                        <p class="text-muted mb-0">No agreements recorded for this venue.</p>
                    <?php else: ?>
                        <ul class="list-unstyled mb-0">
                            <?php foreach (array_slice($agreements, 0, 5) as $ag): ?>
                                <li class="mb-1">
                                    <?php echo $esc($ag['title']); ?>
                                    <span class="badge bg-<?php echo $ag['status'] === 'active' ? 'success' : ($ag['status'] === 'draft' ? 'secondary' : 'warning text-dark'); ?>">
                                        <?php echo $esc($ag['status']); ?>
                                    </span>
                                    <?php
                                    if ($ag['renewalDate'] !== null) {
                                        $days = (int) ((strtotime((string) $ag['renewalDate']) - strtotime('today')) / 86400);
                                        $badgeClass = $days <= 30 ? 'bg-danger' : 'bg-light text-dark';
                                        echo ' <span class="badge ' . $badgeClass . '">renews in ' . $days . 'd</span>';
                                    }
                                    ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="col-12">
        <div class="card">
            <div class="card-header">Next 10 bookings</div>
            <div class="card-body small">
                <?php if (count($upcoming) === 0): ?>
                    <p class="text-muted mb-0">No upcoming bookings.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($upcoming as $b): ?>
                            <li class="mb-1">
                                <a href="/venues/booking?id=<?php echo (int) $b['bookingID']; ?>">
                                    <?php echo $esc(date('j M Y', strtotime((string) $b['bookingDate']))); ?>
                                </a>
                                — <?php echo $esc($b['usageTypeName']); ?>
                                <span class="badge bg-light text-dark"><?php echo $esc($b['statusName']); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
portal_location_map_assets(\Portal\Core\App::cspNonce());
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
