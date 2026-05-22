<?php
// Path: public_html/calendar/views/_day_columns.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Hour-timeline grid renderer 🕒
 * -----------------------------------------------------------------------------
 * Shared by day / week / weekdays / weekend views. Renders a vertical hour
 * timeline (00:00 → 24:00) with one column per day; events are positioned
 * absolutely inside their column at the correct hour and span their duration.
 *
 * All-day events render in a separate strip above the timeline so they don't
 * fight for space with timed events.
 *
 * Usage:
 *   require __DIR__ . '/_day_columns.php';
 *   echo render_day_columns($days, $events);
 *
 * where:
 *   $days   list<DateTimeImmutable>  — midnights of the days to render
 *   $events list<array>              — already-fetched event rows
 *
 * @package   Portal\Calendar
 * @license   All Rights Reserved
 * @version   0.11.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

if (function_exists('render_day_columns') === false) {

    /**
     * Render the hour-timeline grid.
     *
     * @param list<DateTimeImmutable> $days
     * @param list<array<string,mixed>> $events
     *
     * @return string HTML
     */
    function render_day_columns(array $days, array $events): string
    {
        if (count($days) === 0) {
            return '<div class="alert alert-info">No days to display.</div>';
        }

        // 🕔 Hour range. Show full 24 hours for completeness; CSS makes
        // the grid scrollable so the user can focus on working hours.
        $startHour = 0;
        $endHour   = 24;
        $hourSlots = $endHour - $startHour;
        $pxPerHour = 48;  // matches the CSS .portal-cal-hour height

        // 🗂️ Bucket events by which day-column they belong to.
        // For multi-day events we split them across columns and clip
        // the time range to each column's [00:00, 24:00] window.
        $byDay = [];           // map: YYYY-MM-DD => list<eventRow>
        $allDayByDay = [];     // map: YYYY-MM-DD => list<eventRow>
        $dayKeys = [];
        foreach ($days as $d) {
            $k = $d->format('Y-m-d');
            $byDay[$k] = [];
            $allDayByDay[$k] = [];
            $dayKeys[] = $k;
        }

        foreach ($events as $ev) {
            $start = new DateTimeImmutable((string) $ev['startDateTime']);
            $end   = isset($ev['endDateTime']) === true && $ev['endDateTime'] !== null
                ? new DateTimeImmutable((string) $ev['endDateTime'])
                : $start->modify('+1 hour');
            $isAllDay = (int) ($ev['isAllDay'] ?? 0) === 1;

            foreach ($days as $d) {
                $dStart = $d->setTime(0, 0, 0);
                $dEnd   = $d->setTime(23, 59, 59);
                // overlap test
                if ($start <= $dEnd && $end >= $dStart) {
                    $k = $d->format('Y-m-d');
                    if ($isAllDay === true) {
                        $allDayByDay[$k][] = $ev;
                    } else {
                        $byDay[$k][] = $ev;
                    }
                }
            }
        }

        // 🎨 Helpers
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $eventColor = static function (array $ev): string {
            $c = (string) ($ev['categoryColor'] ?? '');
            // 🛡️ Only allow safe hex / rgb tokens through to inline CSS.
            if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $c) === 1) {
                return $c;
            }
            return 'var(--portal-primary)';
        };

        ob_start();
        ?>
        <div class="portal-cal-grid">

            <!-- 📌 Header row: weekday + date per column -->
            <div class="portal-cal-headrow" style="--cal-cols: <?php echo count($days); ?>;">
                <div class="portal-cal-gutter" aria-hidden="true"></div>
                <?php foreach ($days as $d): ?>
                    <?php $isToday = $d->format('Y-m-d') === date('Y-m-d'); ?>
                    <div class="portal-cal-dayhead <?php echo $isToday === true ? 'is-today' : ''; ?>">
                        <div class="small text-muted text-uppercase"><?php echo $esc($d->format('D')); ?></div>
                        <div class="h6 mb-0"><?php echo $esc($d->format('j M')); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- 🌅 All-day events strip -->
            <?php
            $hasAllDay = false;
            foreach ($allDayByDay as $list) {
                if (count($list) > 0) {
                    $hasAllDay = true;
                    break;
                }
            }
            ?>
            <?php if ($hasAllDay === true): ?>
                <div class="portal-cal-allday" style="--cal-cols: <?php echo count($days); ?>;">
                    <div class="portal-cal-gutter small text-muted">all-day</div>
                    <?php foreach ($dayKeys as $k): ?>
                        <div class="portal-cal-allday-cell">
                            <?php foreach ($allDayByDay[$k] as $ev): ?>
                                <a class="portal-cal-event portal-cal-event-allday"
                                   href="/calendar/event?slug=<?php echo $esc((string) $ev['eventSlug']); ?>"
                                   style="--ev-color: <?php echo $esc($eventColor($ev)); ?>;"
                                   title="<?php echo $esc((string) $ev['eventName']); ?>">
                                    <?php echo $esc((string) $ev['eventName']); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- 🕐 Hour timeline -->
            <div class="portal-cal-timeline" style="--cal-cols: <?php echo count($days); ?>; --cal-hours: <?php echo $hourSlots; ?>; --cal-px-per-hour: <?php echo $pxPerHour; ?>px;">

                <!-- Hour gutter -->
                <div class="portal-cal-gutter portal-cal-hours">
                    <?php for ($h = $startHour; $h < $endHour; $h++): ?>
                        <div class="portal-cal-hour">
                            <span class="small text-muted"><?php echo sprintf('%02d:00', $h); ?></span>
                        </div>
                    <?php endfor; ?>
                </div>

                <!-- Day columns -->
                <?php foreach ($dayKeys as $k): ?>
                    <div class="portal-cal-col" data-date="<?php echo $esc($k); ?>">
                        <!-- Hour grid lines -->
                        <?php for ($h = $startHour; $h < $endHour; $h++): ?>
                            <div class="portal-cal-hourline"></div>
                        <?php endfor; ?>

                        <!-- Events absolutely positioned -->
                        <?php foreach ($byDay[$k] as $ev): ?>
                            <?php
                            $colDate = new DateTimeImmutable($k);
                            $colStart = $colDate->setTime(0, 0, 0);
                            $colEnd   = $colDate->setTime(23, 59, 59);
                            $evStart  = new DateTimeImmutable((string) $ev['startDateTime']);
                            $evEnd    = $ev['endDateTime'] !== null
                                ? new DateTimeImmutable((string) $ev['endDateTime'])
                                : $evStart->modify('+1 hour');

                            // Clip to the visible column window
                            $visStart = $evStart < $colStart ? $colStart : $evStart;
                            $visEnd   = $evEnd   > $colEnd   ? $colEnd   : $evEnd;

                            $startMins = ((int) $visStart->format('H')) * 60 + (int) $visStart->format('i');
                            $endMins   = ((int) $visEnd->format('H'))   * 60 + (int) $visEnd->format('i');
                            $duration  = max(15, $endMins - $startMins);  // never less than 15min visible

                            // Convert to pixel offsets via the per-hour height
                            $top    = ($startMins / 60) * $pxPerHour;
                            $height = ($duration  / 60) * $pxPerHour;
                            ?>
                            <a class="portal-cal-event portal-cal-event-timed <?php echo (int) ($ev['isFeatured'] ?? 0) === 1 ? 'is-featured' : ''; ?>"
                               href="/calendar/event?slug=<?php echo $esc((string) $ev['eventSlug']); ?>"
                               style="top: <?php echo (int) round($top); ?>px; height: <?php echo (int) round($height); ?>px; --ev-color: <?php echo $esc($eventColor($ev)); ?>;"
                               title="<?php echo $esc($evStart->format('H:i') . ' – ' . $evEnd->format('H:i') . ' · ' . (string) $ev['eventName']); ?>">
                                <div class="portal-cal-event-time small">
                                    <?php echo $esc($evStart->format('H:i')); ?>
                                </div>
                                <div class="portal-cal-event-title">
                                    <?php echo $esc((string) $ev['eventName']); ?>
                                </div>
                                <?php if ($ev['locationName'] !== null && $ev['locationName'] !== ''): ?>
                                    <div class="portal-cal-event-loc small">
                                        <i class="fa-solid fa-location-dot me-1"></i>
                                        <?php echo $esc((string) $ev['locationName']); ?>
                                    </div>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
