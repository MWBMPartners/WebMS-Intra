<?php
// Path: public_html/calendar/views/weekend.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Weekend View Partial 🛋️
 * -----------------------------------------------------------------------------
 * Saturday + Sunday only. Reuses _day_columns.php with 2 columns.
 *
 * @package   Portal\Calendar
 * @license   All Rights Reserved
 * @version   0.11.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . '_day_columns.php';

$days = [
    $rangeStart,
    $rangeStart->modify('+1 day'),
];

// 🏛️ $venueOverlay (#429) — set by the router; [] when disabled/absent/throwing.
echo render_day_columns($days, $events, $venueOverlay ?? []);
