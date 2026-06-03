<?php
// Path: public_html/calendar/views/day.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Day View Partial 🗓️
 * -----------------------------------------------------------------------------
 * Renders one day's events on a 24-hour vertical timeline. Each event is
 * positioned by start time and visually spans its duration. All-day events
 * appear in a dedicated strip above the timeline.
 *
 * Reuses the same data-shape produced by the day/week/weekdays/weekend
 * router branch — receives $events and $rangeStart from the router scope.
 *
 * @package   Portal\Calendar
 * @license   All Rights Reserved
 * @version   0.11.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$day = $rangeStart;  // already set to 00:00 of the chosen date
require __DIR__ . DIRECTORY_SEPARATOR . '_day_columns.php';
echo render_day_columns([$day], $events);
