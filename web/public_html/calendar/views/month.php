<?php
// Path: public_html/calendar/views/month.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Month View Partial 📅
 * -----------------------------------------------------------------------------
 * Standard 7-column calendar grid for the cursor's month. Each cell shows
 * the date number, an optional "today" badge, and up to N event pills.
 * Days outside the active month are dimmed but still rendered so the grid
 * is always a clean 5 or 6 rows.
 *
 * Receives from the router:
 *   $events, $cursor, $rangeStart, $rangeEnd
 *
 * @package   Portal\Calendar
 * @license   All Rights Reserved
 * @version   0.11.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

// 🗂️ Index events by their start date so we can place them in cells
$byDate = [];
foreach ($events as $ev) {
    $start = new DateTimeImmutable((string) $ev['startDateTime']);
    $end   = $ev['endDateTime'] !== null
        ? new DateTimeImmutable((string) $ev['endDateTime'])
        : $start;

    // Multi-day events appear on every day they overlap with the visible month
    $current = $start;
    while ($current <= $end) {
        $k = $current->format('Y-m-d');
        if (isset($byDate[$k]) === false) {
            $byDate[$k] = [];
        }
        $byDate[$k][] = $ev;
        $current = $current->modify('+1 day');
        // Safety: don't loop past the month's visible range
        if ($current->format('Y-m') > $cursor->format('Y-m')
            && $current->format('Y-m') !== $cursor->modify('+1 month')->format('Y-m')
        ) {
            break;
        }
    }
}

// 🗓️ Compute grid bounds: pad to the previous Monday and the following Sunday
$firstOfMonth = $cursor->modify('first day of this month')->setTime(0, 0, 0);
$lastOfMonth  = $cursor->modify('last day of this month')->setTime(0, 0, 0);

$leadDays = ((int) $firstOfMonth->format('N')) - 1;   // Mon=0..Sun=6
$gridStart = $firstOfMonth->modify('-' . $leadDays . ' days');

$trailDays = 7 - ((int) $lastOfMonth->format('N'));
$gridEnd   = $lastOfMonth->modify('+' . $trailDays . ' days');

$totalCells = (int) (($gridEnd->getTimestamp() - $gridStart->getTimestamp()) / 86400) + 1;

$weekdayHeader = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

$eventColor = static function (array $ev): string {
    $c = (string) ($ev['categoryColor'] ?? '');
    if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $c) === 1) {
        return $c;
    }
    return 'var(--portal-primary)';
};

$today = (new DateTimeImmutable('today'))->format('Y-m-d');
?>

<div class="portal-cal-month">
    <div class="portal-cal-month-head">
        <?php foreach ($weekdayHeader as $w): ?>
            <div class="portal-cal-month-headcell"><?php echo $esc($w); ?></div>
        <?php endforeach; ?>
    </div>

    <div class="portal-cal-month-grid">
        <?php
        $d = $gridStart;
        for ($i = 0; $i < $totalCells; $i++) {
            $k = $d->format('Y-m-d');
            $inMonth   = $d->format('Y-m') === $cursor->format('Y-m');
            $isToday   = $k === $today;
            $dayEvents = $byDate[$k] ?? [];
            $classes   = ['portal-cal-month-cell'];
            if ($inMonth === false) { $classes[] = 'is-outside'; }
            if ($isToday === true)  { $classes[] = 'is-today'; }
        ?>
            <div class="<?php echo implode(' ', $classes); ?>">
                <div class="portal-cal-month-cell-head">
                    <a class="portal-cal-month-cell-date"
                       href="/calendar?view=day&amp;date=<?php echo $esc($k); ?>"
                       title="View this day">
                        <?php echo $esc($d->format('j')); ?>
                    </a>
                    <?php if ($isToday === true): ?>
                        <span class="badge bg-primary ms-1">Today</span>
                    <?php endif; ?>
                </div>

                <?php
                // Show up to 3 events, then a "+ N more" link to the day view.
                $maxEvents = 3;
                $count = count($dayEvents);
                $shown = array_slice($dayEvents, 0, $maxEvents);
                foreach ($shown as $ev):
                    $evStart = new DateTimeImmutable((string) $ev['startDateTime']);
                    $isAllDay = (int) ($ev['isAllDay'] ?? 0) === 1;
                    ?>
                    <a class="portal-cal-month-pill"
                       href="/calendar/event?slug=<?php echo $esc((string) $ev['eventSlug']); ?>"
                       style="--ev-color: <?php echo $esc($eventColor($ev)); ?>;"
                       title="<?php echo $esc((string) $ev['eventName']); ?>">
                        <?php if ($isAllDay === false): ?>
                            <span class="portal-cal-month-pill-time"><?php echo $esc($evStart->format('H:i')); ?></span>
                        <?php endif; ?>
                        <span class="portal-cal-month-pill-name">
                            <?php echo $esc((string) $ev['eventName']); ?>
                        </span>
                    </a>
                <?php endforeach; ?>

                <?php if ($count > $maxEvents): ?>
                    <a class="portal-cal-month-more small"
                       href="/calendar?view=day&amp;date=<?php echo $esc($k); ?>">
                        + <?php echo (int) ($count - $maxEvents); ?> more
                    </a>
                <?php endif; ?>
            </div>
        <?php
            $d = $d->modify('+1 day');
        }
        ?>
    </div>
</div>
