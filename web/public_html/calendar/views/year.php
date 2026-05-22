<?php
// Path: public_html/calendar/views/year.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Year Planner View Partial (Wall-Planner Layout) 🗓️
 * -----------------------------------------------------------------------------
 * Wall-planner style year overview modelled on traditional printed planners.
 *
 * Layout:
 *   • 12 month columns across the top, each split into TWO sub-columns:
 *       – Day number + day-of-week initial
 *       – Event content (bulleted list of all events on that day)
 *   • 31 day rows top-to-bottom. Days that don't exist for a given month
 *     (e.g. Feb 30/31) render as blank cells so the grid stays aligned.
 *   • Weekend cells get a tinted background (Sat = cream, Sun = peach).
 *   • Multi-day events show on every day they cover — same background
 *     colour, producing a continuous "band" effect down the column.
 *   • Category colours drive cell backgrounds via --ev-color, sourced from
 *     tblEventCategories.color (regex-validated, falls back to primary).
 *   • Legend at the top lists every active category with its swatch.
 *
 * Receives from the router:
 *   $events, $cursor, $rangeStart, $rangeEnd, $categories
 *
 * @package   Portal\Calendar
 * @license   All Rights Reserved
 * @version   0.11.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/136
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$esc  = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$year = (int) $cursor->format('Y');

/**
 * Whitelist a hex colour for inline-CSS injection. Returns the cleaned
 * value or null if the input is not a safe hex token.
 */
$cleanHex = static function (?string $c): ?string {
    if ($c === null || $c === '') {
        return null;
    }
    return preg_match('/^#[0-9a-fA-F]{3,8}$/', $c) === 1 ? $c : null;
};

// 🗓️ Per-month strap-lines from tblCalendarMonthThemes
$monthThemes = [];   // map: monthNum => themeText
$stmtMt = $mysqli->prepare(
    'SELECT month, themeText FROM tblCalendarMonthThemes '
    . 'WHERE siteID = ? AND year = ?'
);
if ($stmtMt !== false) {
    $stmtMt->bind_param('ii', $siteId, $year);
    $stmtMt->execute();
    $r = $stmtMt->get_result();
    while ($row = $r->fetch_assoc()) {
        $monthThemes[(int) $row['month']] = (string) $row['themeText'];
    }
    $stmtMt->close();
}

// 🗂️ Bucket events by Y-m-d, repeating multi-day events on each day they span.
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

$today  = (new DateTimeImmutable('today'))->format('Y-m-d');
$months = [
    1  => 'January',  2  => 'February', 3  => 'March',    4  => 'April',
    5  => 'May',      6  => 'June',     7  => 'July',     8  => 'August',
    9  => 'September',10 => 'October',  11 => 'November', 12 => 'December',
];

/**
 * Decide the cell appearance for a day.
 *
 * Walks the day's events in order and picks the first one whose category has
 * `displayStyle = 'background'` to drive a tinted cell bg. Categories with
 * `displayStyle = 'text'` (e.g. Bank Holidays, Notable Days) never tint the
 * background — they only colour the event text inside the cell.
 *
 * If no event triggers a bg tint, weekend cells get Sat/Sun tint.
 *
 * @return array{bg:?string,isWeekend:bool}
 */
$cellBg = static function (array $dayEvents, DateTimeImmutable $dt) use ($cleanHex): array {
    foreach ($dayEvents as $ev) {
        $style = (string) ($ev['categoryDisplayStyle'] ?? 'background');
        if ($style !== 'background') {
            continue;   // text-style events don't tint the cell
        }
        $c = $cleanHex($ev['categoryColor'] ?? null);
        if ($c !== null) {
            return ['bg' => $c, 'isWeekend' => false];
        }
    }
    $dow = (int) $dt->format('N');
    if ($dow >= 6) {
        return ['bg' => null, 'isWeekend' => true];
    }
    return ['bg' => null, 'isWeekend' => false];
};
?>

<div class="portal-cal-yearplan">

    <!-- 🎨 Legend — colour key for the categories in use on this site -->
    <?php
    $legendCats = array_filter($categories, static function ($c) use ($cleanHex) {
        return $cleanHex($c['color'] ?? null) !== null;
    });
    ?>
    <?php if (count($legendCats) > 0): ?>
        <div class="portal-cal-yearplan-legend mb-2">
            <strong class="small text-muted me-2">Key:</strong>
            <?php foreach ($legendCats as $cat): ?>
                <?php
                $catColor = (string) $cleanHex($cat['color']);
                $catStyle = (string) ($cat['displayStyle'] ?? 'background');
                ?>
                <span class="portal-cal-yearplan-legend-item">
                    <?php if ($catStyle === 'text'): ?>
                        <span class="portal-cal-yearplan-legend-text"
                              style="color: <?php echo $esc($catColor); ?>; font-weight: 600;">
                            <?php echo $esc((string) $cat['categoryName']); ?>
                        </span>
                    <?php else: ?>
                        <span class="portal-cal-yearplan-legend-swatch"
                              style="background: <?php echo $esc($catColor); ?>;"></span>
                        <?php echo $esc((string) $cat['categoryName']); ?>
                    <?php endif; ?>
                </span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- 🖼️ Wall-planner grid: scrollable horizontally on narrow viewports -->
    <div class="portal-cal-yearplan-scroll">
        <div class="portal-cal-yearplan-grid">

            <!-- Header row: month names (each spans 2 sub-columns) -->
            <?php foreach ($months as $monthNum => $monthName): ?>
                <?php $strap = $monthThemes[$monthNum] ?? ''; ?>
                <a href="/calendar?view=month&amp;date=<?php echo $year; ?>-<?php echo sprintf('%02d', $monthNum); ?>-01"
                   class="portal-cal-yearplan-monthhead"
                   style="grid-column: span 2;">
                    <span class="portal-cal-yearplan-monthhead-name"><?php echo $esc($monthName); ?></span>
                    <?php if ($strap !== ''): ?>
                        <span class="portal-cal-yearplan-monthhead-strap">
                            <?php echo $esc($strap); ?>
                        </span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>

            <!-- 31 day rows × (12 months × 2 sub-columns) = 31 × 24 cells -->
            <?php for ($day = 1; $day <= 31; $day++): ?>
                <?php for ($month = 1; $month <= 12; $month++): ?>
                    <?php
                    $daysInMonth = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
                    if ($day > $daysInMonth) {
                        // Blank pair of cells so the grid stays aligned
                        echo '<div class="portal-cal-yearplan-num is-blank" aria-hidden="true"></div>';
                        echo '<div class="portal-cal-yearplan-content is-blank" aria-hidden="true"></div>';
                        continue;
                    }
                    $cellDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    $dt       = new DateTimeImmutable($cellDate);
                    $isToday  = $cellDate === $today;
                    $dow      = (int) $dt->format('N');   // 1..7 Mon..Sun
                    $dowInit  = substr($dt->format('D'), 0, 2);  // "Mo", "Tu", "We", "Th", "Fr", "Sa", "Su"
                    $dayEv    = $byDate[$cellDate] ?? [];

                    $info       = $cellBg($dayEv, $dt);
                    $bgHex      = $info['bg'];
                    $isWeekend  = $info['isWeekend'];

                    // Style attrs
                    $cellStyle = '';
                    if ($bgHex !== null) {
                        // Use a lightened tint of the category colour so the day
                        // text stays readable. The category swatch + a left-border
                        // accent at full saturation make the band still obvious.
                        $cellStyle = 'style="background: color-mix(in srgb, '
                                   . $esc($bgHex) . ' 28%, var(--portal-surface));'
                                   . ' --ev-color: ' . $esc($bgHex) . ';"';
                    }

                    $contentClasses = ['portal-cal-yearplan-content'];
                    if ($isToday === true)  { $contentClasses[] = 'is-today'; }
                    if ($isWeekend === true) {
                        $contentClasses[] = $dow === 6 ? 'is-saturday' : 'is-sunday';
                    }
                    if ($bgHex !== null) { $contentClasses[] = 'has-event'; }

                    $numClasses = ['portal-cal-yearplan-num'];
                    if ($isToday === true)  { $numClasses[] = 'is-today'; }
                    if ($isWeekend === true) {
                        $numClasses[] = $dow === 6 ? 'is-saturday' : 'is-sunday';
                    }
                    ?>

                    <!-- Day number + day-of-week initial -->
                    <a class="<?php echo implode(' ', $numClasses); ?>"
                       href="/calendar?view=day&amp;date=<?php echo $esc($cellDate); ?>"
                       title="<?php echo $esc($dt->format('l, j F Y')); ?>">
                        <span class="portal-cal-yearplan-num-day"><?php echo sprintf('%02d', $day); ?></span>
                        <span class="portal-cal-yearplan-num-dow"><?php echo $esc($dowInit); ?></span>
                    </a>

                    <!-- Event content cell -->
                    <div class="<?php echo implode(' ', $contentClasses); ?>" <?php echo $cellStyle; ?>>
                        <?php if (count($dayEv) > 0): ?>
                            <ul class="portal-cal-yearplan-events">
                                <?php foreach ($dayEv as $ev): ?>
                                    <?php
                                    $evColor = $cleanHex($ev['categoryColor'] ?? null);
                                    $evStyle = (string) ($ev['categoryDisplayStyle'] ?? 'background');
                                    $textColored = ($evStyle === 'text' && $evColor !== null);
                                    ?>
                                    <li class="<?php echo $textColored === true ? 'is-text-style' : ''; ?>">
                                        <a href="/calendar/event?slug=<?php echo $esc((string) $ev['eventSlug']); ?>"
                                           <?php if ($evColor !== null): ?>style="--ev-color: <?php echo $esc($evColor); ?>;<?php echo $textColored === true ? ' color: ' . $esc($evColor) . '; font-weight: 600;' : ''; ?>"<?php endif; ?>
                                           title="<?php echo $esc((string) $ev['eventName']); ?>">
                                            <?php echo $esc((string) $ev['eventName']); ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            <?php endfor; ?>
        </div>
    </div>

    <p class="text-muted small mt-3 mb-0">
        Click a month name to jump to the month view; click a day number to jump to that day.
        Multi-day events repeat on every day they cover and share their category colour, producing a continuous band.
    </p>
</div>
