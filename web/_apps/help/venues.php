<?php
// Path: apps/help/venues.php
/**
 * -----------------------------------------------------------------------------
 * Help Centre — Venue Bookings Guide 🏛️
 * -----------------------------------------------------------------------------
 * Plain-English walkthrough of Venue Bookings: what it's for, reading the
 * year schedule, the calendar overlay and "is it booked?" warnings, adding
 * single/multi-day/recurring bookings, usage types and their default hours,
 * statuses and the approval journey, the recommended approach to a day with
 * several activities, importing an existing spreadsheet, hire agreements
 * and the payable invoice/payment ledger, reminders, who can do what, and
 * the manual-handling duty around caretaker/landlord contact details.
 * -----------------------------------------------------------------------------
 * @package    Portal\Help
 * @author     MWBM Partners Ltd (t/a MWservices)
 * @copyright  2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version    1.0.0
 * @link       https://github.com/MWBMPartners/WebMS-Intra/issues/429
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$pageTitle   = 'Help - Venue Bookings';
$pageSection = 'help';
$breadcrumbs = ['Dashboard' => '/', 'Help' => '/help', 'Venue Bookings' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-building-columns me-2"></i>Venue Bookings Guide</h1>
        <p class="text-secondary mb-0">The agreed hire schedule for a building your organisation rents from someone else — so nobody plans an event into a slot that was never booked.</p>
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
            <a href="#overview" class="badge text-bg-secondary text-decoration-none">What is Venue Bookings?</a>
            <a href="#reading-schedule" class="badge text-bg-secondary text-decoration-none">Reading the Schedule</a>
            <a href="#calendar-overlay" class="badge text-bg-secondary text-decoration-none">Calendar Overlay &amp; Warnings</a>
            <a href="#adding-bookings" class="badge text-bg-secondary text-decoration-none">Adding Bookings</a>
            <a href="#usage-types" class="badge text-bg-secondary text-decoration-none">Usage Types &amp; Default Hours</a>
            <a href="#statuses" class="badge text-bg-secondary text-decoration-none">Statuses &amp; Approval</a>
            <a href="#one-day-several" class="badge text-bg-secondary text-decoration-none">One Day, Several Activities</a>
            <a href="#importing" class="badge text-bg-secondary text-decoration-none">Importing Your Spreadsheet</a>
            <a href="#agreements-invoices" class="badge text-bg-secondary text-decoration-none">Agreements, Invoices &amp; Payments</a>
            <a href="#reminders" class="badge text-bg-secondary text-decoration-none">Reminders</a>
            <a href="#roles" class="badge text-bg-secondary text-decoration-none">Who Can Do What</a>
            <a href="#data-protection" class="badge text-bg-secondary text-decoration-none">Data Protection</a>
        </div>
    </div>
</div>

<!-- 1️⃣ Overview -->
<section id="overview" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-building-columns me-2"></i>What is Venue Bookings?</h2>
    <p>
        <a href="/venues">Venue Bookings</a> is for organisations that <strong>rent</strong> the building they meet
        in from someone else — a school hall, a community centre, a shared church building — rather than owning it
        outright. It records exactly what's been agreed with the landlord: which dates you've booked, what hours
        you're allowed to use, and what the current status of that agreement is, so leaders planning an event can
        see at a glance whether the building is actually available before they commit to it.
    </p>
    <p>It sits alongside two other registers that cover different ground:</p>
    <div class="portal-data-list mb-3">
        <div class="portal-data-row">
            <div class="col-12 col-md-3"><strong><i class="fa-solid fa-door-open me-1"></i>Resources</strong></div>
            <div class="col-12 col-md-9">Rooms, equipment, and vehicles <em>you own</em> — bookable by your own people, with conflict detection between your own bookings.</div>
        </div>
        <div class="portal-data-row">
            <div class="col-12 col-md-3"><strong><i class="fa-solid fa-boxes-stacked me-1"></i>Asset Tracker</strong></div>
            <div class="col-12 col-md-9">Physical and digital things <em>you own</em> — furniture, equipment, licences — tracked for ownership, loans, and maintenance.</div>
        </div>
        <div class="portal-data-row">
            <div class="col-12 col-md-3"><strong><i class="fa-solid fa-building-columns me-1"></i>Venue Bookings</strong></div>
            <div class="col-12 col-md-9">The building <em>you don't own</em> — a record of what a landlord has agreed to let you use, and when.</div>
        </div>
    </div>
    <p class="text-muted small mb-0">
        Anyone logged in can view the schedule and a venue's details (without costs). Adding and editing bookings,
        agreements, and invoices is limited to the Venue Manager role and admins — see
        <a href="#roles">Who Can Do What</a> below.
    </p>
</section>

<!-- 2️⃣ Reading the schedule -->
<section id="reading-schedule" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-calendar-days me-2"></i>Reading the Schedule</h2>
    <p>
        The main <a href="/venues">Venue Bookings</a> page is a year schedule, grouped by month — one row per hire
        date, matching the shape of a typical booking spreadsheet so it's instantly familiar. Use the venue switcher
        and year selector at the top to move between venues and years, and jump straight to the current date with
        the "Today" link.
    </p>
    <p>Each row shows:</p>
    <ul>
        <li><strong>Date and day of the week</strong>.</li>
        <li><strong>Usage type</strong> — which hire slot this is (see <a href="#usage-types">Usage Types &amp; Default Hours</a>).</li>
        <li><strong>Times</strong> — the hours you're using the building. A <strong>"times needed"</strong> badge
            appears when a hire-type booking doesn't have times set yet — a quick worklist of gaps to fill in.</li>
        <li><strong>Status</strong> — shown as a coloured badge (see <a href="#statuses">Statuses &amp; the Approval Journey</a>).</li>
        <li><strong>Notes</strong> — free text describing what the booking is for.</li>
        <li><strong>Cost</strong> — visible to Venue Managers and admins only; hidden from the general viewer schedule.</li>
    </ul>
    <p class="text-muted small mb-0">
        Use the <strong>CSV</strong> and <strong>PDF</strong> export links to take a copy of the schedule away with
        you — for a treasurer's file, a printed noticeboard sheet, or to share with someone who doesn't use the
        portal. The cost column only appears in these exports for Venue Managers and admins.
    </p>
</section>

<!-- 3️⃣ Calendar overlay & warnings -->
<section id="calendar-overlay" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-triangle-exclamation me-2"></i>The Calendar Overlay &amp; Warnings</h2>
    <p>
        Once a default venue is configured (an admin setting), every view of the main <a href="/calendar">Calendar</a>
        shows a thin strip on each day that has a venue booking, so you can see building availability without
        leaving the calendar you already use to plan events.
    </p>
    <div class="portal-data-list mb-3">
        <div class="portal-data-row">
            <div class="col-12 col-md-4"><span class="badge text-bg-success">Confirmed</span></div>
            <div class="col-12 col-md-8">A solid strip — the hire is agreed and the times are set. Safe to plan against.</div>
        </div>
        <div class="portal-data-row">
            <div class="col-12 col-md-4"><span class="badge" style="background:#fff3cd;color:#664d03;">Proposed / tentative</span></div>
            <div class="col-12 col-md-8">A hatched strip — the hire has been requested but isn't confirmed yet. Check before promising the slot to anyone.</div>
        </div>
        <div class="portal-data-row">
            <div class="col-12 col-md-4"><span class="badge text-bg-secondary"><i class="fa-solid fa-door-closed me-1"></i>Closed / not needed</span></div>
            <div class="col-12 col-md-8">You chose not to use the building that day — a deliberate gap, not a problem.</div>
        </div>
        <div class="portal-data-row">
            <div class="col-12 col-md-4"><span class="badge text-bg-danger"><i class="fa-solid fa-ban me-1"></i>Building unavailable</span></div>
            <div class="col-12 col-md-8">The landlord has said the building can't be used that day at all — plan elsewhere.</div>
        </div>
    </div>
    <p>
        When you're creating or editing an event on the calendar, a live check quietly asks "is it booked?" as soon
        as you set the start and end time, and shows a coloured message if there's a problem. The same check runs
        again, silently, the moment you save the event — so even without JavaScript running, you'll still see the
        warning appear as a flash message straight after saving.
    </p>
    <ul>
        <li><span class="badge text-bg-success">Green</span> — the event falls inside a confirmed, timed booking. All good.</li>
        <li><span class="badge text-bg-warning text-dark">Amber</span> — the event runs outside the booked hours, the
            booking for that day isn't confirmed yet, or the building is marked closed that day. Ask the Venue
            Manager to propose or extend a booking to cover it.</li>
        <li><span class="badge text-bg-danger">Red</span> — there's no booking at all for that date, or the building
            has been marked unavailable. Don't hold the event there until this is resolved.</li>
    </ul>
    <p class="text-muted small mb-0">
        This is a warning, not a block — saving an event is never prevented by the venue check. It's there to catch
        a clash early, while there's still time to sort it out with the landlord.
    </p>
</section>

<!-- 4️⃣ Adding bookings -->
<section id="adding-bookings" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-plus me-2"></i>Adding Bookings</h2>
    <p>Venue Managers can add bookings three ways, depending on the shape of the hire:</p>
    <ul>
        <li><strong>A single day</strong> — click "+ Booking" from the schedule, pick the date, usage type, and
            status, and save.</li>
        <li><strong>A multi-day run</strong> — for something like a week-long event, choose the "multi-day" mode in
            the generator and give it a start and end date; every day in the run is created together as one group,
            so it can be edited or removed as a block.</li>
        <li><strong>A recurring series</strong> — for regular weekly worship or any repeating pattern, use
            "Generate series": choose weekly, fortnightly, monthly, or a custom list of dates, and a preview shows
            exactly which dates will be created before anything is saved. Any date that's already booked is
            automatically skipped and listed, so you never accidentally double-book a slot.</li>
    </ul>
    <p class="text-muted small mb-0">
        A run created together (multi-day or recurring) can have its status changed or be removed as one group from
        the schedule — no need to edit each date individually. You can also extend an existing series later on,
        picking up from where it left off.
    </p>
</section>

<!-- 5️⃣ Usage types & default hours -->
<section id="usage-types" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-clock me-2"></i>Usage Types &amp; Default Hours</h2>
    <p>
        Every venue has its own list of <strong>usage types</strong> — the different kinds of hire slot you book.
        Six are set up automatically for a new venue, and Venue Managers can add more or change these:
    </p>
    <div class="portal-data-list mb-3">
        <div class="portal-data-row">
            <div class="col-12 col-md-4"><strong>Regular Hours</strong></div>
            <div class="col-12 col-md-8">The everyday hire window (e.g. a Sunday morning slot).</div>
        </div>
        <div class="portal-data-row">
            <div class="col-12 col-md-4"><strong>Extended</strong></div>
            <div class="col-12 col-md-8">A longer window than usual, for a bigger event on an otherwise-normal day.</div>
        </div>
        <div class="portal-data-row">
            <div class="col-12 col-md-4"><strong>Extended (All Day)</strong></div>
            <div class="col-12 col-md-8">The full day, for the longest events.</div>
        </div>
        <div class="portal-data-row">
            <div class="col-12 col-md-4"><strong>Custom</strong></div>
            <div class="col-12 col-md-8">No default hours — set the times by hand for a one-off arrangement.</div>
        </div>
        <div class="portal-data-row">
            <div class="col-12 col-md-4"><strong>Closed - Not Needed</strong></div>
            <div class="col-12 col-md-8">You've chosen not to use the building that day.</div>
        </div>
        <div class="portal-data-row">
            <div class="col-12 col-md-4"><strong>Building Unavailable</strong></div>
            <div class="col-12 col-md-8">The landlord has said the building can't be used that day.</div>
        </div>
    </div>
    <p>
        Most usage types carry a <strong>default start and end time</strong>, so picking "Regular Hours" for a new
        booking fills the times in for you automatically. These defaults are <strong>effective-dated</strong> —
        which means when a landlord changes the agreed hours (for example, "from 2026 the regular slot moves to
        9:30-16:00"), a Venue Manager can add a new default window starting from that date without disturbing the
        hours already recorded on past bookings. Existing bookings can be "re-applied" to pick up a newer default
        if needed.
    </p>
</section>

<!-- 6️⃣ Statuses & the approval journey -->
<section id="statuses" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-list-check me-2"></i>Statuses &amp; the Approval Journey</h2>
    <p>
        <strong>Status</strong> tracks where a booking stands in the approval conversation with the landlord. Like
        usage types, statuses are a configurable list per organisation — six are seeded automatically to start you
        off:
    </p>
    <ul>
        <li><strong>Standard Agreement</strong> — the usual, already-settled arrangement (counts as confirmed).</li>
        <li><strong>Pending Leadership Agreement</strong> — waiting on your own leadership to sign off internally.</li>
        <li><strong>Proposed to Landlord</strong> — you've asked the landlord; awaiting their answer.</li>
        <li><strong>Agreed by Landlord</strong> — the landlord has said yes (counts as confirmed).</li>
        <li><strong>Rejected by Landlord</strong> — the landlord said no.</li>
        <li><strong>Rejected – building already in use</strong> — someone else already has the building that day.</li>
    </ul>
    <p>
        Each status carries two flags Venue Managers can see when editing statuses: whether it
        <strong>counts as confirmed</strong> (drives the green "Confirmed" strip on the calendar and schedule) and
        whether it's <strong>available</strong> (a rejected status is not — the slot is effectively free again).
        These two flags, not the status name itself, are what the calendar warnings actually read — so a
        custom status behaves correctly as long as its flags are set sensibly.
    </p>
    <p class="text-muted small mb-0">
        Leadership sign-off and landlord sign-off are modelled as two separate statuses in the approval journey,
        not as a workflow with its own steps — moving a booking from one status to the next is simply editing the
        booking, the same as changing any other field.
    </p>
</section>

<!-- 7️⃣ One day, several activities -->
<section id="one-day-several" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-layer-group me-2"></i>One Day, Several Activities</h2>
    <div class="alert alert-info d-flex gap-2" role="alert">
        <i class="fa-solid fa-circle-info mt-1"></i>
        <div>
            <strong>Recommended approach:</strong> keep <strong>one booking per hired slot</strong>, and describe
            everything happening that day in its notes — a morning service followed by a shared lunch and an
            afternoon meeting is still just one hire of the building, so it's one booking. Link the main event
            (the service, say) to that booking on the calendar.
        </div>
    </div>
    <p>
        Only create a <strong>second, separate booking</strong> when the hire itself is genuinely separate — for
        example, a different time window later that evening, or a different room within the same building that's
        hired independently. If it's all the same hired window, one booking with a detailed note is simpler to
        manage, easier to read on the schedule, and matches how the landlord actually bills for it.
    </p>
</section>

<!-- 8️⃣ Importing your spreadsheet -->
<section id="importing" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-file-import me-2"></i>Importing Your Spreadsheet</h2>
    <p>
        If you've been tracking your hire schedule in a spreadsheet, the import wizard brings it into the portal in
        three steps, without you needing to re-type every row by hand:
    </p>
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-4">
            <div class="card text-center h-100 border-primary">
                <div class="card-body">
                    <i class="fa-solid fa-upload fa-2x text-primary mb-2"></i>
                    <h6>1. Upload</h6>
                    <p class="small text-secondary mb-0">Choose the venue and upload your file (Excel <code>.xlsx</code> or <code>.csv</code>). One sheet per year, plus an optional "BackingData" sheet, works best.</p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card text-center h-100 border-info">
                <div class="card-body">
                    <i class="fa-solid fa-shuffle fa-2x text-info mb-2"></i>
                    <h6>2. Match up the wording</h6>
                    <p class="small text-secondary mb-0">Any hours or status wording the portal doesn't already recognise is matched to an existing entry (a best-guess match is suggested) or created fresh.</p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card text-center h-100 border-success">
                <div class="card-body">
                    <i class="fa-solid fa-check-double fa-2x text-success mb-2"></i>
                    <h6>3. Preview &amp; commit</h6>
                    <p class="small text-secondary mb-0">Review a summary and the first rows exactly as they'll import, then commit — or cancel and start again.</p>
                </div>
            </div>
        </div>
    </div>
    <p>
        The expected columns are date, hours (usage type), times, notes, and status — the wizard reads your
        spreadsheet's own wording for hours and status and offers to map it to the portal's vocabulary, including
        picking up per-year default-hours changes from a "BackingData" sheet automatically.
    </p>
    <div class="alert alert-warning d-flex gap-2" role="alert">
        <i class="fa-solid fa-file-csv mt-1"></i>
        <div>
            <strong>If the Excel upload doesn't work on this server</strong>, save each year's sheet as a
            <code>.csv</code> file and upload those instead — the CSV path always works, and the wizard will tell
            you up front if Excel import isn't available.
        </div>
    </div>
    <p class="text-muted small mb-0">
        After a successful import, check the "times needed" list on the schedule — a booking imports fine even
        without times set, but it's worth going through and filling in any hours the spreadsheet didn't have.
    </p>
</section>

<!-- 9️⃣ Agreements, invoices & payments -->
<section id="agreements-invoices" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-file-invoice-dollar me-2"></i>Agreements, Invoices &amp; Payments</h2>
    <p>
        Separately from individual bookings, Venue Bookings keeps a record of the <strong>hire agreements</strong>
        themselves — the terms and rate you've agreed with the landlord, whether that's a long-running
        <strong>standing</strong> arrangement or a one-off <strong>ad-hoc</strong> hire. Each agreement can have its
        paperwork (contracts, correspondence) attached in a document vault, and a countdown badge warns when a
        renewal or notice period is coming up, so a standing agreement doesn't quietly lapse.
    </p>
    <p>
        Invoices track <strong>money going OUT</strong> to the landlord — what they've billed you, allocated against
        the specific bookings it covers, and what's been paid so far. Recording a payment updates the invoice's
        status automatically (pending → part-paid → paid), giving a running picture of what's owed.
    </p>
    <div class="alert alert-info d-flex gap-2" role="alert">
        <i class="fa-solid fa-circle-info mt-1"></i>
        <div>
            <strong>This is a tracking ledger, not a payment processor.</strong> Venue Bookings never takes a card
            payment or moves money itself — it simply records what was billed and what was paid, the same way you'd
            keep a paper invoice file, so there's a clear history to hand to your treasurer.
        </div>
    </div>
</section>

<!-- 🔟 Reminders -->
<section id="reminders" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-bell me-2"></i>Reminders</h2>
    <p>A daily automated check sends three kinds of nudge, each a configurable number of days ahead:</p>
    <ul>
        <li><strong>Un-agreed bookings</strong> — a booking that still isn't confirmed as the date approaches.</li>
        <li><strong>Agreement renewals</strong> — a standing agreement's renewal or notice period is coming up.</li>
        <li><strong>Invoices due</strong> — a landlord's invoice is approaching (or past) its due date.</li>
    </ul>
    <p class="text-muted small mb-0">
        Reminders go to Venue Managers and admins by default; an admin can widen this to other roles from Venue
        Bookings' settings page. Each reminder only sends once per item until something about it changes (a
        rescheduled date, for instance), so you won't be nagged repeatedly about the same thing.
    </p>
</section>

<!-- 1️⃣1️⃣ Who can do what -->
<section id="roles" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-users me-2"></i>Who Can Do What</h2>
    <div class="portal-data-list mb-3">
        <div class="portal-data-row">
            <div class="col-12 col-md-3"><strong>Any logged-in user</strong></div>
            <div class="col-12 col-md-9">Can view the schedule, a venue's details, and the calendar overlay — all without seeing costs. Can export the schedule to CSV or PDF (also without costs).</div>
        </div>
        <div class="portal-data-row">
            <div class="col-12 col-md-3"><strong>Venue Manager</strong> (or admin)</div>
            <div class="col-12 col-md-9">Everything above, plus adding/editing bookings, running the generator and import wizard, managing venues/rooms/usage types/statuses, agreements and their files, invoices and payments, and seeing costs everywhere.</div>
        </div>
        <div class="portal-data-row">
            <div class="col-12 col-md-3"><strong>Admin only</strong></div>
            <div class="col-12 col-md-9">Permanently deleting a venue or an invoice, and Venue Bookings' settings page (default calendar venue, reminder timing, and the reminder cron token).</div>
        </div>
    </div>
    <p class="text-muted small mb-0">
        If you can't see a "New booking" or "Manage" option and think you should be able to, ask an administrator
        to give your account the Venue Manager role from <a href="/admin">Admin → Users</a>.
    </p>
</section>

<!-- 1️⃣2️⃣ Data protection -->
<section id="data-protection" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-shield-halved me-2"></i>Data Protection</h2>
    <p>
        A venue's <strong>caretaker and landlord contact details</strong> — names and phone numbers recorded against
        the venue itself — are personal data about someone who may not have (and doesn't need) a portal account of
        their own. Because they aren't tied to a user account, they fall outside the portal's automated data-export
        and erasure tools, which work by looking up a specific user's account.
    </p>
    <div class="alert alert-warning d-flex gap-2" role="alert">
        <i class="fa-solid fa-triangle-exclamation mt-1"></i>
        <div>
            <strong>This is a manual-handling duty for Venue Managers:</strong> keep these contact details current,
            and remove or update them yourself, through the venue's edit form, whenever a caretaker or landlord
            contact changes or moves on. There's no automatic sweep that will do this for you.
        </div>
    </div>
    <p class="text-muted small mb-0">
        Everything else Venue Bookings records about your own portal users — who created a booking, imported a
        spreadsheet, or changed a usage type's default hours — is covered the same way as the rest of the portal by
        the standard account data-export and erasure tools.
    </p>
</section>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
