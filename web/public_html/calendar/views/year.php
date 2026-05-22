<?php
// Path: public_html/calendar/views/year.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Year Planner View Partial 🗓️
 * -----------------------------------------------------------------------------
 * 12-column wall-planner layout: months as columns (left to right), days as
 * rows (1..31 top to bottom). Each cell shows day-of-week initial and any
 * events as small coloured dots.
 *
 * Designed to print landscape and to give a one-page overview of the whole
 * year — modelled on traditional wall-planner posters.
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
$year = (int) $cursor->format('Y');

// 🗂️ Bucket events by Y-m-d (multi-day events show on each spanned day)
$byDate = [];
foreach ($events as $ev) {
    $start = new DateTimeImmutable((string) $ev['startDateTime']);
    $end   = $ev['endDateTime'] !== null
        ? new DateTimeImmutable((string) $ev['endDateTime'])
        : $start;
    $cur = $start;
    while ($cur <= $end) {
        if ((int) $cur->format('Y') === $year) {
            $k = $cur->format('Y-m-d');
            if (isset($byDate[$k]) === false) {
                $byDate[$k] = [];
            }
            $byDate[$k][] = $ev;
        }
        $cur = $cur->modify('+1 day');
    }
}

$eventColor = static function (array $ev): string {
    $c = (string) ($ev['categoryColor'] ?? '');
    if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $c) === 1) {
        return $c;
    }
    return 'var(--portal-primary)';
};

$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
?>

<div class="portal-cal-year">
    <div class="portal-cal-year-grid">
        <!-- Header row: month labels -->
        <div class="portal-cal-year-cornercell" aria-hidden="true"></div>
        <?php foreach ($months as $idx => $m): ?>
            <div class="portal-cal-year-monthhead">
                <a href="/calendar?view=month&amp;date=<?php echo $year; ?>-<?php echo sprintf('%02d', $idx + 1); ?>-01">
                    <?php echo $esc($m); ?>
                </a>
            </div>
        <?php endforeach; ?>

        <!-- 31 rows × 12 columns of day cells, with a day-number gutter -->
        <?php for ($day = 1; $day <= 31; $day++): ?>
            <div class="portal-cal-year-daynum"><?php echo $day; ?></div>
            <?php for ($month = 1; $month <= 12; $month++): ?>
                <?php
                $daysInMonth = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
                if ($day > $daysInMonth) {
                    echo '<div class="portal-cal-year-cell is-blank" aria-hidden="true"></div>';
                    continue;
                }
                $cellDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
                $dt        = new DateTimeImmutable($cellDate);
                $isWeekend = (int) $dt->format('N') >= 6;
                $isToday   = $cellDate === $today;
                $dayEv     = $byDate[$cellDate] ?? [];

                $classes = ['portal-cal-year-cell'];
                if ($isWeekend === true) { $classes[] = 'is-weekend'; }
                if ($isToday === true)   { $classes[] = 'is-today'; }
                if (count($dayEv) > 0)   { $classes[] = 'has-events'; }
                ?>
                <a class="<?php echo implode(' ', $classes); ?>"
                   href="/calendar?view=day&amp;date=<?php echo $esc($cellDate); ?>"
                   title="<?php echo $esc($dt->format('l, j F Y')); ?><?php echo count($dayEv) > 0 ? ' — ' . count($dayEv) . ' event(s)' : ''; ?>">
                    <span class="portal-cal-year-cell-dow"><?php echo $esc(substr($dt->format('D'), 0, 1)); ?></span>
                    <?php if (count($dayEv) > 0): ?>
                        <span class="portal-cal-year-dots">
                            <?php
                            // 🎨 Up to 3 colour dots so a busy day shows pattern, not clutter.
                            foreach (array_slice($dayEv, 0, 3) as $ev):
                                ?>
                                <span class="portal-cal-year-dot"
                                      style="background: <?php echo $esc($eventColor($ev)); ?>;"></span>
                            <?php endforeach; ?>
                            <?php if (count($dayEv) > 3): ?>
                                <span class="portal-cal-year-dot-more">+<?php echo count($dayEv) - 3; ?></span>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                </a>
            <?php endfor; ?>
        <?php endfor; ?>
    </div>

    <p class="text-muted small mt-3 mb-0">
        Click any day to open the day view; click a month name to jump to the month view.
        Dots are colour-coded by category.
    </p>
</div>
