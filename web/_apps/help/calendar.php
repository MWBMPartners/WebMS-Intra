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
            <a href="#anon-checkins" class="badge text-bg-secondary text-decoration-none">Anonymous check-ins at the door</a>
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

<!-- 7️⃣ Anonymous check-ins at the door (#525) -->
<section id="anon-checkins" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-door-open me-2"></i>Anonymous check-ins at the door</h2>

    <p>
        Somebody can check in to an event without signing in. They scan a QR code with their own
        phone, or somebody presses a button on a kiosk by the door. They say how many of them there
        are, and that is all that is recorded &mdash; no name, no email address, nothing that could be
        traced back to a person.
    </p>
    <p>
        The figures appear in four places: on an event's attendance page, as a small line on the event
        hub, on the attendance reports page as totals for the whole organisation, and in a spreadsheet
        an administrator can download.
    </p>

    <h3 class="h5 mt-4">What each figure means</h3>
    <ul>
        <li><strong>Check-ins</strong> &mdash; how many times the button was pressed. Always exact.</li>
        <li>
            <strong>People claimed</strong> &mdash; how many people those presses said they were. Also
            exact, in the sense that it is exactly what was typed in. Nobody checks it.
        </li>
        <li>
            <strong>Were a group, not one person</strong> &mdash; how many of the presses were somebody
            checking in a family or a car-load rather than just themselves.
        </li>
        <li>
            <strong>Probably different senders</strong> &mdash; roughly how many different internet
            connections the check-ins came from. This is the one to be careful with; see below.
        </li>
    </ul>

    <h3 class="h5 mt-4">What &ldquo;probably different senders&rdquo; cannot tell you</h3>
    <p>
        It counts <em>internet connections</em>, not people, and there are four things it genuinely
        cannot do:
    </p>
    <ul>
        <li>
            It cannot tell two people apart when they share one connection. Everybody on the
            building's own wifi looks like a single sender, so at a venue with wifi the figure can be
            far lower than the number of people.
        </li>
        <li>Somebody who comes back on another day is counted again on that day.</li>
        <li>
            A check-in whose sender could not be recorded at all counts as its own sender. That errs
            towards counting somebody twice rather than losing them, which is the safer direction for
            a figure with &ldquo;probably&rdquo; in its name.
        </li>
        <li>
            If check-ins arrive for a day after that day's figure has already been worked out (a kiosk
            syncing late, say), they are added on top and somebody who came in both batches is counted
            twice. When that happens the day is labelled <strong>&ldquo;includes late arrivals, so
            less exact&rdquo;</strong> on screen and marked in the spreadsheet, so the figure is never
            quietly wrong.
        </li>
    </ul>
    <!-- Wording matched to the rest of the package by the #525 round-1 independent check: this used
         to say the technical detail "is deleted", which the retention screen and the sweep that
         actually does the clearing both deliberately avoid saying, because the CHECK-IN ROW itself is
         never deleted — only the scrambled sender address on it is cleared. Saying "deleted" here
         risked reading as "the whole check-in disappears", which is not what happens; see
         admin/maintenance/retention.php ("Nothing is deleted here. The rows stay...") and
         cron/_retention-sweep.php for the same care taken elsewhere. #530: this used to also mention a
         browser description — migration 201 removed that column entirely, since nothing ever read it,
         so there is now only the one piece of detail to describe. -->
    <p>
        One more thing worth knowing. After a while &mdash; 90 days by default &mdash; the technical
        detail behind the figure is cleared from the row (the row itself stays; nothing about the
        check-in disappears), because that detail is personal information that nothing in the portal
        ever shows. Before that happens, each day's senders figure is worked out and written down, so
        the number you see does not change afterwards. A day whose figure was worked out that way is
        labelled on screen too.
    </p>

    <h3 class="h5 mt-4">These are not the attendance record</h3>
    <p>
        Anonymous check-ins are never part of your organisation's official attendance figures unless an
        administrator deliberately puts them there. On an event's attendance page there is an
        <strong>Add to session</strong> button that writes one headcount row, labelled with the event's
        name, into an attendance session you pick. Pressing it again replaces that same row with the
        current number &mdash; it never adds a second one and never doubles a count.
    </p>
    <p>
        On the attendance reports page the anonymous section is counted by <em>the day a check-in
        arrived</em>. The figures above it are counted by <em>the date of an attendance session</em>.
        They are not two views of one thing, and one should never be subtracted from the other.
    </p>
    <!-- Wording corrected again on 20 September 2026, now that #529 has shipped: the reports page's
         own gate used to be simply "signed in", so a signed-in member of a DIFFERENT organisation on
         this installation could open it too. Since #529, the page requires membership of THIS
         organisation, and can be narrowed further still by the organisation's own "who can see the
         attendance reports page" setting — so this paragraph now points at that setting instead of
         describing an open gap. -->
    <p>
        <strong>One thing to know before you press that button.</strong> The row it writes is labelled
        with the event's name, and once it is part of the attendance record that label appears in the
        &ldquo;By Headcount Group&rdquo; cards on the attendance reports page &mdash; which is open to
        at least the members of your organisation the reports page's own setting currently admits (see
        &ldquo;Who can see the attendance reports&rdquo; below), and never to anybody outside your
        organisation. That is not the anonymous section leaking anything: the anonymous section never
        names an event. It is the ordinary consequence of putting something into your organisation's
        own attendance record. But if the event's name is not something members should see there, do
        not add it to a session. The warning is repeated on the button itself.
    </p>

    <h3 class="h5 mt-4">Who can see them</h3>
    <p>
        Your organisation chooses, at
        <a href="/admin/settings/attendance">/admin/settings/attendance</a>. There are three choices:
    </p>
    <ul>
        <li><strong>Administrators only</strong> &mdash; the narrowest, and what a new installation starts with.</li>
        <li><strong>Administrators and the event's coordinators</strong> &mdash; a coordinator sees the figures for their own event.</li>
        <li><strong>Anyone who can already open the page</strong> &mdash; the figures follow whatever rule that page already has.</li>
    </ul>
    <p>
        Administrators always see the figures, whatever is chosen. The choice can only ever
        <em>narrow</em> who sees them: it never lets somebody onto a page they could not already open.
    </p>

    <h4 class="h6 mt-4">Who can see the attendance reports page (#529)</h4>
    <p>
        A SEPARATE choice from the one above, also set at
        <a href="/admin/settings/attendance">/admin/settings/attendance</a>, that decides who may open
        the reports page AT ALL — not only who sees the anonymous section on it. Somebody who is not a
        member of your organisation can never open it, whatever either setting says. Among your
        organisation's own members, there are three choices:
    </p>
    <ul>
        <li><strong>Administrators only</strong> &mdash; the default, and what every installation and upgrade starts with.</li>
        <li><strong>Administrators, and anyone who coordinates one of this organisation's events</strong> &mdash; a coordinator of any non-deleted event, past or future, is included.</li>
        <li><strong>Any member of this organisation</strong> &mdash; the widest choice.</li>
    </ul>
    <p>
        Administrators can always open the reports page, whatever is chosen. The download
        (<code>/attendance/export</code>) stays administrators-only regardless of this setting, for the
        same reason the anonymous check-ins spreadsheet does: a file leaves the building.
    </p>
    <p>
        <strong>Being able to see a figure and being able to download it are two different
        permissions.</strong> The spreadsheet of anonymous check-ins stays administrators-only whatever
        your organisation has chosen. A file leaves the building, gets forwarded, and is still sitting
        in somebody's downloads folder after a setting has been tightened again &mdash; and unlike the
        screens, the spreadsheet names the events.
    </p>
    <p class="text-muted small">
        The spreadsheet also leaves out any event that has since been deleted, so a deleted event's name
        can never reappear in a file (owner decision, 20 September 2026). The totals on the reports page
        keep every check-in, deleted events included, so the spreadsheet's column can add up to less than
        the total shown above it &mdash; that difference is exactly the deleted events' rows.
    </p>
    <p class="text-muted small">
        Nothing on any of these screens can identify anybody. There is no name, no email address and no
        link to any account on an anonymous check-in, which is also why somebody cannot ask for
        &ldquo;their&rdquo; check-ins to be deleted &mdash; there would be nothing to match them
        against. That is exactly why the technical detail goes on a timer instead.
    </p>
</section>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
