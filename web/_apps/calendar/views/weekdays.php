<?php
// Path: public_html/calendar/views/weekdays.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Weekdays View Partial 💼
 * -----------------------------------------------------------------------------
 * Monday → Friday only. Reuses _day_columns.php with 5 columns.
 *
 * @package   Portal\Calendar
 * @license   All Rights Reserved
 * @version   0.11.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . '_day_columns.php';

$days = [];
for ($i = 0; $i < 5; $i++) {
    $days[] = $rangeStart->modify('+' . $i . ' days');
}

echo render_day_columns($days, $events);
