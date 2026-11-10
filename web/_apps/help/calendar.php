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
$breadcrumbs = ['Dashboard' => \Portal\Core\Site::url(''), 'Help' => \Portal\Core\Site::url('help'), 'Calendar' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-calendar-days me-2"></i>Calendar Guide</h1>
        <p class="text-secondary mb-0">View, filter, and (if you're an admin) configure the calendar's seven view modes.</p>
    </div>
    <a href="<?php echo htmlspecialchars(\Portal\Core\Site::url('help'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary btn-sm mt-2 mt-md-0">
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
            <a href="#repeats" class="badge text-bg-secondary text-decoration-none">For admins &mdash; repeating events</a>
            <a href="#public-website" class="badge text-bg-secondary text-decoration-none">For admins &mdash; the public website</a>
            <a href="#anon-checkins" class="badge text-bg-secondary text-decoration-none">Anonymous check-ins at the door</a>
            <a href="#imported" class="badge text-bg-secondary text-decoration-none">Imported calendars</a>
        </div>
    </div>
</div>

<!-- 1️⃣ View modes -->
<section id="viewmodes" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-table-cells me-2"></i>The seven view modes</h2>
    <p>The calendar landing page (<a href="<?php echo htmlspecialchars(\Portal\Core\Site::url('calendar'), ENT_QUOTES, 'UTF-8'); ?>">/calendar</a>) supports seven different views, each appropriate for a different scale of planning:</p>

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
        Visit <a href="<?php echo htmlspecialchars(\Portal\Core\Site::url('calendar/manage/types'), ENT_QUOTES, 'UTF-8'); ?>">/calendar/manage/types</a> to manage event categories.
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
        Visit <a href="<?php echo htmlspecialchars(\Portal\Core\Site::url('calendar/manage/month-themes'), ENT_QUOTES, 'UTF-8'); ?>">/calendar/manage/month-themes</a>, pick a year, fill in (or clear) the 12 inputs, save.
        Empty inputs remove the row so the cell goes back to default.
    </p>
    <p class="text-muted small">
        Strap-lines are per-site, so each site under an umbrella org can run its own themes independently.
    </p>
</section>

<!-- 🔁 Repeating events, and changing a pattern afterwards (#569, #669) -->
<section id="repeats" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-repeat me-2"></i>For admins &mdash; repeating events</h2>

    <p>
        When you create an event you can make it repeat: every week, fortnight, month, quarter or year.
        Weekly and fortnightly take any set of weekdays; monthly, quarterly and yearly go either by the
        same date each time or by the same weekday (the second Tuesday, the last Sunday). Say how many
        dates you want, or a date to stop after.
    </p>
    <p>
        <strong>Each date becomes an ordinary event of its own.</strong> That is why you can move or
        cancel one week of a service without touching the rest &mdash; just open that date and edit it
        like any other event.
    </p>
    <p class="text-muted small">
        Two things that surprise people. The repeat you choose wins over the date you typed: tick
        "Thursday" above a Wednesday and you get Thursdays, including the first one, and the page tells
        you the date moved. And the time on the clock is what repeats &mdash; a 10:00 service is at 10:00
        on both sides of a clock change.
    </p>

    <h3 class="h6 mt-4">Changing the whole pattern later</h3>
    <p>
        Go to <a href="<?php echo htmlspecialchars(\Portal\Core\Site::url('calendar/manage/series'), ENT_QUOTES, 'UTF-8'); ?>">Manage &rarr; Series</a>
        and press the repeat button beside a series. Use it to shift every future date (the service moves
        from 10:00 to 10:30 from next month), change weekly to fortnightly, extend a run that stopped
        early, or cut the rest of a run short.
    </p>
    <p>
        <strong>You always see exactly what will happen before anything changes.</strong> The page shows
        four lists &mdash; dates it would re-time, dates it would add, dates it would remove, and dates it
        is leaving exactly as they are, each with the reason. Nothing is written until you press
        <em>Make these changes</em>.
    </p>
    <ul>
        <li><strong>Dates that have already happened are never changed.</strong></li>
        <li>
            <strong>A date with anything attached to it is left alone</strong> &mdash; attendance that has
            been marked, people who have said they are coming, somebody registered, invitations sent, a
            room booked, a child checked in, a service plan, a rota. The page says what is attached and
            gives you a link to that date, so you can deal with it yourself if you want to.
        </li>
        <li>
            <strong>A date you have already moved by hand is left alone too.</strong> If you shifted the
            Christmas service to a different evening, changing the pattern will not drag it back.
        </li>
        <li>
            A date that stays keeps <em>everything</em> about it except its time &mdash; its name, venue
            and description are untouched. If you have already changed one date's time by hand, that
            is left alone too.
        </li>
        <li>
            <strong>A date the pattern <em>adds</em> arrives as a draft, so nobody can see it yet.</strong>
            This is deliberate: a new date has no registration window of its own and nothing beyond the
            series' own description, so it is not ready to be read by anybody until you have looked at
            it. A date that has been hidden cannot be brought back, so this way round the mistake is
            the one you can undo.
            <br>
            <strong>Publishing them is one step, not one per date.</strong> After the change you land on
            that series' bulk edit page: tick the new dates and set <em>Status</em> to Published. Until
            you do, anybody subscribing to the calendar sees each date on its own instead of one
            repeating entry, because the calendar file only collapses dates that are published.
        </li>
        <li>
            A date the pattern adds is also never created already cancelled, and never carries
            another date's cancellation reason or the details of somebody who submitted an event.
        </li>
        <li>
            A date that is removed is <strong>hidden rather than deleted</strong>, so nothing attached to it
            is destroyed. <strong>There is no button to bring one back</strong>, though, so treat removing a
            date as final.
        </li>
    </ul>
    <p class="text-muted small">
        One thing to expect afterwards: people who subscribe to your calendar in Google, Apple or Outlook
        will usually start seeing each date of that series on its own, instead of one repeating entry.
        That is deliberate. Once a series has dates that no single pattern describes &mdash; which is true
        of any series that has already started &mdash; listing each date is the only way the feed can stay
        truthful. Everything still shows at the right time; there are just more entries.
    </p>
</section>

<!-- 🌐 Showing an event on the organisation's public website (#493 package
     P1; D-057, D-077, D-078, D-080, D-081). Change with the form's own words
     in calendar/manage/_event_form.php and api-spec.json's showOnWebsite: all
     three say what the switch governs; #493's public door joined the list in
     all three in package P4b, replacing the old countdown widget (D-084). -->
<section id="public-website" class="mb-5">
    <?php // D-079 and D-092 have their own paragraph below, ahead of "Three things to know", which names each box in full. ?>
    <h2 class="h4 mb-3"><i class="fa-solid fa-globe me-2"></i>For admins &mdash; the public website</h2>
    <p>
        Some of what the portal shows is open to anybody on the internet, without signing in: the public
        door your own website reads events from (once it is switched on, on Admin, Public website), the list of pages the portal gives search engines (its
        sitemap), and what an event's page tells search engines about the event. An event goes there only when somebody ticks <strong>Show on the public website</strong>
        on the event's form, and then only while the event is also marked <strong>Public</strong> and today
        is between the optional <strong>From</strong> and <strong>Until</strong> dates. Both of those days
        count, an empty date means no limit, and the dates follow your organisation's calendar.
    </p>
    <p>
        <strong>Nothing goes there until somebody ticks it</strong>, and that includes events that existed
        before this setting did. Your organisation's administrators can tick it. The permission
        &ldquo;Choose what appears on the public website&rdquo; on the
        <a href="<?php echo htmlspecialchars(\Portal\Core\Site::url('admin/roles'), ENT_QUOTES, 'UTF-8'); ?>">Roles page</a>
        can be given to other roles too (the Website Editor role exists for this), but today only
        administrators can open the page that changes an event, so giving it to somebody who is not an
        administrator lets them change nothing yet.
    </p>
    <p>
        A new event starts with <strong>Public</strong> unticked, so it is for members only until somebody
        ticks it &mdash; and that includes an event a member of the public suggested, once it is approved.
        Events that existed before your portal was upgraded to this version kept what they had.
    </p>
    <p class="text-muted small">
        Three things to know. The portal's own pages &mdash; the event's page, the calendar a signed-out
        visitor sees, and the calendar feed people subscribe to &mdash; follow the &ldquo;Public&rdquo; box,
        not &ldquo;Show on the public website&rdquo;; but what the event's page tells search engines needs
        &ldquo;Show on the public website&rdquo; too. A repeating series made with &ldquo;Show on the public website&rdquo; ticked gives
        every date the same From and Until dates, so leave them empty or set them wide enough for the whole
        series. And taking an event off can take up to an hour to reach every copy; a copy somebody has
        already taken cannot be pulled back.
    </p>
    <p class="mb-0">
        Events copied in from an outside calendar reach the public website by a rule set on
        <a href="<?php echo htmlspecialchars(\Portal\Core\Site::url('admin/calendar/feeds'), ENT_QUOTES, 'UTF-8'); ?>">Admin, Outside calendars</a>:
        either everything that a calendar's or a rule's &ldquo;Also show on the public website&rdquo; box
        covers (and choices too), or only events somebody has made a choice about &mdash; one date, or every
        date of a repeating event, future ones included. Whoever runs the installation decides which your
        organisation may use, and your organisation may hold itself to the stricter one.
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
    <!-- Rewritten 10 November 2026 for #529 / D-106: the reports page is open to administrators and to
         whoever holds the permission "See the attendance totals" — no longer decided by a setting. -->
    <p>
        <strong>One thing to know before you press that button.</strong> The row it writes is labelled
        with the event's name, and once it is part of the attendance record that label appears in the
        &ldquo;By Headcount Group&rdquo; cards on the attendance reports page &mdash; which your
        administrators can open, and so can anybody your organisation has given the permission
        &ldquo;See the attendance totals&rdquo; (see &ldquo;Who can see the attendance totals&rdquo;
        below), and never anybody outside your organisation. That is not the anonymous section leaking
        anything: the anonymous section never names an event. It is the ordinary consequence of putting
        something into your organisation's own attendance record. But if the event's name is not
        something those people should see there, do not add it to a session. The warning is repeated on
        the button itself.
    </p>

    <h3 class="h5 mt-4">Who can see them</h3>
    <p>
        Your organisation chooses, at
        <a href="<?php echo htmlspecialchars(\Portal\Core\Site::url('admin/settings/attendance'), ENT_QUOTES, 'UTF-8'); ?>">/admin/settings/attendance</a>. There are three choices:
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

    <h4 class="h6 mt-4" id="attendance-totals">Who can see the attendance totals</h4>
    <!-- #529 / D-106 (the owner, 10 November 2026). This replaced a three-way setting at
         /admin/settings/attendance; migration 271 deleted it. -->
    <p>
        Your organisation's attendance figures &mdash; the attendance reports page (headcounts by month,
        by service type and by headcount group), each recorded session's own headcount and the
        &ldquo;Total Headcount This Month&rdquo; figure on the attendance page, the form for correcting a
        session already recorded, and the attendance spreadsheet download
        (<code>/attendance/export</code>) &mdash; can be seen by:
    </p>
    <ul>
        <li><strong>your organisation's administrators</strong>, always; and</li>
        <li><strong>anybody holding the permission &ldquo;See the attendance totals&rdquo;</strong>. You
            choose which role holds it on
            <a href="<?php echo htmlspecialchars(\Portal\Core\Site::url('admin/roles'), ENT_QUOTES, 'UTF-8'); ?>">Admin, Roles</a>.
            It starts out pointing at the role <em>Attendance Coordinator</em>, which nobody holds until
            somebody is given it &mdash; so until you give it to somebody, only administrators see the
            totals.</li>
    </ul>
    <p>
        Nobody else. An ordinary member of your organisation, and anybody from another organisation on
        the same installation, gets the same &ldquo;page not found&rdquo; as an address that does not
        exist. None of these figures names a person; they are totals.
    </p>
    <p>
        <strong>What a member who records attendance sees.</strong> Any member of your organisation can
        still record a new session, and sees the counts they are typing (and the total of what they just
        saved). They see the list of recorded sessions &mdash; date, service, event and who recorded it
        &mdash; but not any session's headcount, and they cannot open a session already recorded to
        correct it, because that would show its figures. A member who made a mistake can delete the
        session and record it again, or ask an administrator to correct it.
    </p>
    <!-- #917 / D-109 (the owner, 10 November 2026). Until then this paragraph said the list "still
         shows each session's own headcount to the people who record them", which was untrue in the
         reassuring direction: it showed every session's figure to EVERY member, recording or not. -->
    <p>
        The attendance spreadsheet includes the <em>notes</em> typed when each session was recorded, so
        whoever you give the permission can read those too.
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

<!-- 🧒 #816: how long event registrations are kept. Its link comes from
     Site::url(), so it keeps the organisation's own address prefix. -->
<section id="registration-retention" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-child-reaching me-2"></i>For admins &mdash; how long registrations are kept</h2>
    <p>
        When somebody registers for an event, the details they give &mdash; which can include a child's
        allergies and medical notes and a parent's telephone number &mdash; are deleted a set number of days
        after the event. Your organisation follows the period this installation sets (90 days unless
        whoever runs the installation has changed it), unless it chooses a period of its own. An
        administrator sets your organisation's period at
        <a href="<?php echo htmlspecialchars(\Portal\Core\Site::url('admin/settings/registrations'), ENT_QUOTES, 'UTF-8'); ?>">Admin, Registration retention</a>,
        which also shows the period in force and whether the nightly clear-out is switched on for your
        organisation. While it is switched off, nothing is deleted at all.
    </p>
    <p>
        Whoever runs the installation may set a ceiling on that period. If they have, you cannot choose a
        longer one, and the page says so. A ceiling never shortens a period your organisation has already
        chosen: it is refused instead, because a period like this can be a legal requirement.
    </p>
</section>

<!-- 📥 Imported calendars (#514 part 8, chunk P8-4). Its links come from
     Site::url(), so they keep the organisation's own address prefix. -->
<section id="imported" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-rss me-2"></i>Imported calendars</h2>
    <p>
        Administrators can copy events into this calendar from a calendar somebody else publishes, such as a
        partner organisation's diary. Each imported event shows only to the people its administrators chose, and
        the <a href="<?php echo htmlspecialchars(\Portal\Core\Site::url('help/calendar-imports'), ENT_QUOTES, 'UTF-8'); ?>">Outside Calendars Guide</a>
        explains how.
    </p>
    <p class="mb-0">
        An API key receives an imported event exactly when a signed-out visitor could see it, unless "Don't show via
        API" is ticked on its calendar, or on a choice or rule that covers it.
    </p>
</section>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
