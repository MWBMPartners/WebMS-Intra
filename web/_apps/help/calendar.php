<?php
// Path: public_html/help/calendar.php
/**
 * -----------------------------------------------------------------------------
 * Help Centre — Calendar Guide 📅
 * -----------------------------------------------------------------------------
 * Walks members and admins through the seven calendar view modes, date
 * navigation, filtering, and (for admins) categories, colours, display
 * styles, and per-month strap-lines.
 * -----------------------------------------------------------------------------
 * @package    Portal\Help
 * @license    All Rights Reserved
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$pageTitle   = 'Help - Calendar';
$pageSection = 'help';
$breadcrumbs = ['Dashboard' => '/', 'Help' => '/help', 'Calendar' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-calendar-days me-2"></i>Calendar Guide</h1>
        <p class="text-secondary mb-0">View, filter, and (if you're an admin) configure the calendar's seven view modes.</p>
    </div>
    <a href="/help" class="btn btn-outline-secondary btn-sm mt-2 mt-md-0">
        <i class="fa-solid fa-arrow-left me-1"></i>Back to Help Centre
    </a>
</div>

<!-- 🧭 Table of contents -->
<div class="card mb-4 border-0 bg-body-tertiary">
    <div class="card-body">
        <h6 class="card-title mb-2"><i class="fa-solid fa-list me-1"></i>On this page</h6>
        <div class="d-flex flex-wrap gap-2">
            <a href="#viewmodes" class="badge text-bg-secondary text-decoration-none">View modes</a>
            <a href="#navigation" class="badge text-bg-secondary text-decoration-none">Navigation</a>
            <a href="#filters" class="badge text-bg-secondary text-decoration-none">Filters</a>
            <a href="#defaults" class="badge text-bg-secondary text-decoration-none">Default view</a>
            <a href="#admin-categories" class="badge text-bg-secondary text-decoration-none">For admins — categories</a>
            <a href="#admin-themes" class="badge text-bg-secondary text-decoration-none">For admins — month themes</a>
        </div>
    </div>
</div>

<!-- 1️⃣ View modes -->
<section id="viewmodes" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-table-cells me-2"></i>The seven view modes</h2>
    <p>The calendar landing page (<a href="/calendar">/calendar</a>) supports seven different views, each appropriate for a different scale of planning:</p>

    <div class="portal-data-list">
        <div class="portal-data-row">
            <div class="col-12 col-md-3"><strong><i class="fa-solid fa-calendar-day me-1"></i>Day</strong></div>
            <div class="col-12 col-md-9">A single day's events on a vertical 24-hour timeline. Events with start times appear at the correct hour and span their duration; all-day events get a strip above the timeline.</div>
        </div>
        <div class="portal-data-row">
            <div class="col-12 col-md-3"><strong><i class="fa-solid fa-calendar-week me-1"></i>Week</strong></div>
            <div class="col-12 col-md-9">Full 7-day grid Monday → Sunday, sharing the same hour timeline.</div>
        </div>
        <div class="portal-data-row">
            <div class="col-12 col-md-3"><strong><i class="fa-solid fa-briefcase me-1"></i>Weekdays (Mon-Fri)</strong></div>
            <div class="col-12 col-md-9">Same timeline, working week only — useful when the weekend is noise.</div>
        </div>
        <div class="portal-data-row">
            <div class="col-12 col-md-3"><strong><i class="fa-solid fa-couch me-1"></i>Weekend (Sat-Sun)</strong></div>
            <div class="col-12 col-md-9">Same timeline, weekend only — useful for worship-service and ministry-event planning.</div>
        </div>
        <div class="portal-data-row">
            <div class="col-12 col-md-3"><strong><i class="fa-solid fa-calendar me-1"></i>Month</strong></div>
            <div class="col-12 col-md-9">7-column calendar grid for the chosen month. Each cell shows up to 3 event pills and a "+ N more" link to the day view if there are more.</div>
        </div>
        <div class="portal-data-row">
            <div class="col-12 col-md-3"><strong><i class="fa-solid fa-calendar-days me-1"></i>Year planner</strong></div>
            <div class="col-12 col-md-9">12 month columns across, 31 day rows down — a wall-planner layout for the whole year at a glance. Multi-day events show as continuous coloured bands; weekend cells get a subtle tint.</div>
        </div>
        <div class="portal-data-row">
            <div class="col-12 col-md-3"><strong><i class="fa-solid fa-list me-1"></i>List</strong></div>
            <div class="col-12 col-md-9">Chronological card grid — the original list. Includes a "Show past events" toggle and pagination.</div>
        </div>
    </div>
    <p class="text-muted small mt-3 mb-0">
        Switch between views using the buttons at the top of the calendar page, or
        directly via <code>?view=day</code>, <code>?view=week</code>, etc. in the URL.
    </p>
</section>

<!-- 2️⃣ Navigation -->
<section id="navigation" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-compass me-2"></i>Date navigation</h2>
    <p>Every view except List has three navigation controls:</p>
    <ul>
        <li><strong>◀</strong> — step back (1 day / 1 week / 1 month / 1 year depending on view).</li>
        <li><strong>Today</strong> — jump back to today.</li>
        <li><strong>▶</strong> — step forward.</li>
    </ul>
    <p>
        The <strong>Jump to</strong> date picker (top right) lets you land on any specific date directly.
        The List view uses pagination instead — Newest-first when "Show past events" is off, oldest-first when on.
    </p>
</section>

<!-- 3️⃣ Filters -->
<section id="filters" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-filter me-2"></i>Filters</h2>
    <p>Two filters apply across every view:</p>
    <ul>
        <li><strong>Category</strong> — picks events from one specific category (e.g. "Area 8", "Conference", "Worship services").</li>
        <li><strong>Type</strong> — picks events of a specific type (e.g. "Family Worship", "Communion", "Working Bee").</li>
    </ul>
    <p>
        The List view also has a <strong>Show past events</strong> toggle that's hidden in the other views (since the grid is anchored on a specific time window already).
    </p>
</section>

<!-- 4️⃣ Default view -->
<section id="defaults" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-house me-2"></i>Default view + per-device memory</h2>
    <p>
        When you arrive at <code>/calendar</code> with no <code>?view=</code> in the URL, the portal picks the view in this order:
    </p>
    <ol>
        <li><strong>Whatever you last used</strong> — your browser remembers the last view you switched to (per device, via <code>localStorage</code>).</li>
        <li><strong>The site default</strong> — what your admin set as <code>calendar.defaultView</code> (default: Month).</li>
    </ol>
    <p>
        You can always override by typing the view name into the URL — that wins over both the remembered choice and the site default.
    </p>
</section>

<!-- 5️⃣ Admin: Categories -->
<section id="admin-categories" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-tags me-2"></i>For admins — categories &amp; colours</h2>
    <p>
        Visit <a href="/calendar/manage/types">/calendar/manage/types</a> to manage event categories.
        Each category can carry:
    </p>
    <ul>
        <li><strong>Colour</strong> (hex) — drives how events in that category are coloured in the year planner and month grid.</li>
        <li>
            <strong>Display style</strong>:
            <ul>
                <li><em>Background</em> (default) — events show as a tinted band behind the event text. Best for organisational scopes like "Area", "Conference", "Union".</li>
                <li><em>Text only</em> — events show as coloured event text on the default background. Best for "tag-style" categories like "Bank Holidays" or "Notable Days" that flag a day without filling the cell.</li>
            </ul>
        </li>
    </ul>
    <p>The category colour is also picked up by the auto-generated legend at the top of the year planner.</p>
</section>

<!-- 6️⃣ Admin: Month themes -->
<section id="admin-themes" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-quote-left me-2"></i>For admins — month themes / strap-lines</h2>
    <p>
        If your organisation runs themed months (e.g. "Healthy connections" for February, "Pause?" for November), you can record a short strap-line per month per year and it will appear underneath the month name on the year planner.
    </p>
    <p>
        Visit <a href="/calendar/manage/month-themes">/calendar/manage/month-themes</a>, pick a year, fill in (or clear) the 12 inputs, save.
        Empty inputs remove the row so the cell goes back to default.
    </p>
    <p class="text-muted small">
        Strap-lines are per-site, so each site under an umbrella org can run its own themes independently.
    </p>
</section>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
