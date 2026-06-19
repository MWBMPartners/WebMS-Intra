<?php
// Path: public_html/calendar/views/_shared_header.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Shared View Header 🧭
 * -----------------------------------------------------------------------------
 * Renders the controls common to every view mode:
 *   • View-switcher (Day / Week / Mon-Fri / Sat-Sun / Month / Year / List)
 *   • Date navigation (◀ Today ▶ + date input) — hidden for List
 *   • Range title (e.g. "May 2026", "Mon 19 — Sun 25 May 2026")
 *   • Filters (category / type / show-past)
 *
 * Receives from index.php:
 *   $view, $cursor, $rangeStart, $rangeEnd,
 *   $filterCategory, $filterType, $showPast,
 *   $categories, $eventTypes
 *
 * @package   Portal\Calendar
 * @license   All Rights Reserved
 * @version   0.11.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// 🏷️ Build a human-readable range title per view
$rangeTitle = '';
switch ($view) {
    case 'day':
        $rangeTitle = $cursor->format('l, j F Y');
        break;
    case 'week':
    case 'weekdays':
    case 'weekend':
        $rangeTitle = $rangeStart->format('j M') . ' &ndash; ' . $rangeEnd->format('j M Y');
        break;
    case 'month':
        $rangeTitle = $cursor->format('F Y');
        break;
    case 'year':
        $rangeTitle = $cursor->format('Y');
        break;
    case 'list':
    default:
        $rangeTitle = '';
        break;
}

/**
 * 🔧 Helper — build a URL preserving the current filter state and replacing
 * the listed query-string parts.
 */
$urlFor = static function (array $overrides) use ($view, $cursor, $filterCategory, $filterType, $showPast): string {
    $params = array_filter([
        'view'     => $view,
        'date'     => $cursor->format('Y-m-d'),
        'category' => $filterCategory,
        'type'     => $filterType,
        'past'     => $showPast ? '1' : '',
    ], static fn ($v) => $v !== '' && $v !== null);
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    return '/calendar?' . http_build_query($params);
};

// 📆 Compute prev/next cursors per view (used by the date-nav arrows)
$prevCursor = $cursor;
$nextCursor = $cursor;
switch ($view) {
    case 'day':
        $prevCursor = $cursor->modify('-1 day');
        $nextCursor = $cursor->modify('+1 day');
        break;
    case 'week':
    case 'weekdays':
    case 'weekend':
        $prevCursor = $cursor->modify('-1 week');
        $nextCursor = $cursor->modify('+1 week');
        break;
    case 'month':
        $prevCursor = $cursor->modify('first day of last month');
        $nextCursor = $cursor->modify('first day of next month');
        break;
    case 'year':
        $prevCursor = $cursor->modify('-1 year');
        $nextCursor = $cursor->modify('+1 year');
        break;
}

// 🪪 View-switcher metadata — order matters for the rendered button row
$viewMeta = [
    'day'      => ['label' => 'Day',     'icon' => 'fa-calendar-day'],
    'week'     => ['label' => 'Week',    'icon' => 'fa-calendar-week'],
    'weekdays' => ['label' => 'Mon-Fri', 'icon' => 'fa-briefcase'],
    'weekend'  => ['label' => 'Sat-Sun', 'icon' => 'fa-couch'],
    'month'    => ['label' => 'Month',   'icon' => 'fa-calendar'],
    'year'     => ['label' => 'Year',    'icon' => 'fa-calendar-days'],
    'list'     => ['label' => 'List',    'icon' => 'fa-list'],
];
?>

<!-- 🧭 Calendar view controls -->
<div class="portal-calendar-controls mb-3">

    <!-- Top row: range title + date navigation (hidden for list) -->
    <?php if ($view !== 'list'): ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <a href="<?php echo htmlspecialchars($urlFor(['date' => $prevCursor->format('Y-m-d')]), ENT_QUOTES, 'UTF-8'); ?>"
                   class="btn btn-sm btn-outline-secondary" title="Previous">
                    <i class="fa-solid fa-chevron-left"></i>
                </a>
                <a href="<?php echo htmlspecialchars($urlFor(['date' => date('Y-m-d')]), ENT_QUOTES, 'UTF-8'); ?>"
                   class="btn btn-sm btn-outline-secondary">Today</a>
                <a href="<?php echo htmlspecialchars($urlFor(['date' => $nextCursor->format('Y-m-d')]), ENT_QUOTES, 'UTF-8'); ?>"
                   class="btn btn-sm btn-outline-secondary" title="Next">
                    <i class="fa-solid fa-chevron-right"></i>
                </a>
                <h2 class="h5 mb-0 ms-2"><?php echo $rangeTitle; /* contains a literal &ndash; — safe */ ?></h2>
            </div>

            <form method="get" action="/calendar" class="d-flex align-items-center gap-2">
                <input type="hidden" name="view" value="<?php echo htmlspecialchars($view, ENT_QUOTES, 'UTF-8'); ?>">
                <?php if ($filterCategory !== ''): ?>
                    <input type="hidden" name="category" value="<?php echo htmlspecialchars($filterCategory, ENT_QUOTES, 'UTF-8'); ?>">
                <?php endif; ?>
                <?php if ($filterType !== ''): ?>
                    <input type="hidden" name="type" value="<?php echo htmlspecialchars($filterType, ENT_QUOTES, 'UTF-8'); ?>">
                <?php endif; ?>
                <label for="cal-date-jump" class="form-label small mb-0">Jump to:</label>
                <input id="cal-date-jump" type="date" name="date" class="form-control form-control-sm"
                       value="<?php echo htmlspecialchars($cursor->format('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>"
                       onchange="this.form.submit()">
            </form>
        </div>
    <?php endif; ?>

    <!-- View switcher row -->
    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
        <div class="btn-group btn-group-sm" role="group" aria-label="Calendar view">
            <?php foreach ($viewMeta as $key => $meta): ?>
                <a href="<?php echo htmlspecialchars($urlFor(['view' => $key]), ENT_QUOTES, 'UTF-8'); ?>"
                   class="btn btn-outline-primary <?php echo $key === $view ? 'active' : ''; ?>"
                   data-portal-calendar-view="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"
                   title="<?php echo htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8'); ?> view">
                    <i class="fa-solid <?php echo htmlspecialchars($meta['icon'], ENT_QUOTES, 'UTF-8'); ?> me-1"></i>
                    <span class="d-none d-sm-inline"><?php echo htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Filters row -->
    <form method="get" action="/calendar" class="row g-2 align-items-end">
        <input type="hidden" name="view" value="<?php echo htmlspecialchars($view, ENT_QUOTES, 'UTF-8'); ?>">
        <?php if ($view !== 'list'): ?>
            <input type="hidden" name="date" value="<?php echo htmlspecialchars($cursor->format('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>">
        <?php endif; ?>
        <div class="col-12 col-md-3">
            <label for="cal-cat" class="form-label small mb-0">Category</label>
            <select id="cal-cat" name="category" class="form-select form-select-sm">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo (int) $cat['categoryID']; ?>"
                        <?php echo ($filterCategory === (string) $cat['categoryID']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['categoryName'], ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-3">
            <label for="cal-type" class="form-label small mb-0">Type</label>
            <select id="cal-type" name="type" class="form-select form-select-sm">
                <option value="">All Types</option>
                <?php foreach ($eventTypes as $et): ?>
                    <option value="<?php echo (int) $et['typeID']; ?>"
                        <?php echo ($filterType === (string) $et['typeID']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($et['typeName'], ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php // 🔍 Faceted filters (#330) ?>
        <div class="col-12 col-md-3">
            <label for="cal-loc" class="form-label small mb-0">Location</label>
            <input type="text" id="cal-loc" name="location" class="form-control form-control-sm"
                   maxlength="80" placeholder="e.g. Hall"
                   value="<?php echo htmlspecialchars($filterLocation ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div class="col-12 col-md-3">
            <label for="cal-q" class="form-label small mb-0">Search</label>
            <input type="text" id="cal-q" name="q" class="form-control form-control-sm"
                   maxlength="80" placeholder="Keyword"
                   value="<?php echo htmlspecialchars($filterSearch ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div class="col-6 col-md-2">
            <label for="cal-from" class="form-label small mb-0">From</label>
            <input type="date" id="cal-from" name="from" class="form-control form-control-sm"
                   value="<?php echo htmlspecialchars($filterFrom ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div class="col-6 col-md-2">
            <label for="cal-to" class="form-label small mb-0">To</label>
            <input type="date" id="cal-to" name="to" class="form-control form-control-sm"
                   value="<?php echo htmlspecialchars($filterTo ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <?php if ($view === 'list'): ?>
            <div class="col-12 col-md-3">
                <div class="form-check form-switch mt-2">
                    <input class="form-check-input" type="checkbox" name="past" value="1" id="showPast"
                           <?php echo $showPast === true ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="showPast">Show past events</label>
                </div>
            </div>
        <?php endif; ?>
        <div class="col-12 col-md-3 d-flex gap-1">
            <button type="submit" class="btn btn-sm btn-outline-primary flex-grow-1">
                <i class="fa-solid fa-filter me-1"></i> Apply
            </button>
            <a href="/calendar?view=<?php echo htmlspecialchars($view, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-secondary" title="Clear filters">
                <i class="fa-solid fa-rotate-left"></i>
            </a>
        </div>
    </form>
</div>

<!-- 💾 Persist last-used view in localStorage (progressive enhancement) -->
<script>
(function () {
    try {
        // When user clicks a view-switcher button, remember the choice
        document.querySelectorAll('[data-portal-calendar-view]').forEach(function (a) {
            a.addEventListener('click', function () {
                localStorage.setItem('portal-calendar-view', a.getAttribute('data-portal-calendar-view'));
            });
        });
        // On the first paint of /calendar with no explicit ?view= we honour
        // the remembered value via a soft redirect — this never triggers
        // when ?view= is already set explicitly.
        var url = new URL(window.location.href);
        var hasView = url.searchParams.has('view');
        if (hasView === false) {
            var remembered = localStorage.getItem('portal-calendar-view');
            if (remembered && remembered !== <?php echo json_encode($view); ?>) {
                url.searchParams.set('view', remembered);
                window.location.replace(url.toString());
            }
        }
    } catch (e) {
        // localStorage unavailable (private mode etc.) — silently no-op
    }
})();
</script>
