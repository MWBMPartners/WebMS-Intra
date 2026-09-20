<?php
// Path: apps/help/reports.php
/**
 * -----------------------------------------------------------------------------
 * Help Centre -- Reports & Report Builder Guide
 * -----------------------------------------------------------------------------
 * Guide covering both the fixed analytics dashboards (/admin/reports,
 * #93) and the whitelist-driven custom report builder (/admin/reports/
 * builder, #156): choosing a data source, columns, filters, grouping,
 * previewing, saving, sharing, running, and CSV export.
 * -----------------------------------------------------------------------------
 * @package    Portal\Help
 * @license   All Rights Reserved
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$pageTitle   = 'Help - Reports';
$pageSection = 'help';
$breadcrumbs = ['Dashboard' => '/', 'Help' => '/help', 'Reports' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- Reports Guide -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-chart-bar me-2"></i>Reports Guide</h1>
        <p class="text-secondary mb-0">Analytics dashboards, plus a whitelist-driven custom report builder.</p>
    </div>
    <a href="/help" class="btn btn-outline-secondary btn-sm mt-2 mt-md-0">
        <i class="fa-solid fa-arrow-left me-1"></i>Back to Help Centre
    </a>
</div>

<div class="alert alert-info d-flex gap-2 mb-4" role="alert">
    <i class="fa-solid fa-user-shield mt-1"></i>
    <div>
        <strong>Who is this for?</strong> Reports is an admin-only area. You need Admin, Site Admin, Site Root Admin, or Umbrella Admin access to reach <code>/admin/reports</code>.
    </div>
</div>

<div class="card mb-4 border-0 bg-body-tertiary">
    <div class="card-body">
        <h6 class="card-title mb-2"><i class="fa-solid fa-list me-1"></i>On this page</h6>
        <div class="d-flex flex-wrap gap-2">
            <a href="#dashboards" class="badge text-bg-secondary text-decoration-none">Fixed Dashboards</a>
            <a href="#builder" class="badge text-bg-secondary text-decoration-none">Building a Report</a>
            <a href="#filters" class="badge text-bg-secondary text-decoration-none">Filters &amp; Grouping</a>
            <a href="#sharing" class="badge text-bg-secondary text-decoration-none">Saving, Sharing &amp; Running</a>
            <a href="#security" class="badge text-bg-secondary text-decoration-none">What You Can and Can't Report On</a>
        </div>
    </div>
</div>

<div class="portal-card p-4 mb-4" id="dashboards">
    <h2 class="h4 mb-3"><i class="fa-solid fa-chart-area me-2 text-primary"></i>Fixed Dashboards</h2>
    <p><code>/admin/reports</code> shows a fixed set of analytics cards — active users, events, expense claims by status, monthly activity trends, and more. These are built in and can't be customised, but they load instantly with no configuration.</p>
    <p class="mb-0">Click <strong>Custom reports</strong> from that page (or <strong>Report Builder</strong> from the Admin quick links) to build your own.</p>
</div>

<div class="portal-card p-4 mb-4" id="builder">
    <h2 class="h4 mb-3"><i class="fa-solid fa-table-list me-2 text-primary"></i>Building a Report</h2>
    <div class="list-group list-group-flush mb-3">
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">1</span>
            <div>
                <strong>Choose a data source</strong>
                <p class="mb-0 small text-secondary">Members, Events, Attendance, Expense claims, Giving, or Tasks. Each source only exposes a curated, safe set of columns — there is no free-text SQL anywhere in the builder.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">2</span>
            <div>
                <strong>Pick columns</strong>
                <p class="mb-0 small text-secondary">Tick the columns you want. Selected columns appear as a chip row — drag a chip to reorder it. A locked (<i class="fa-solid fa-lock small"></i>) column means your role can't see that data; ask a site admin or treasurer for access.</p>
            </div>
        </div>
        <div class="list-group-item d-flex gap-3 align-items-start">
            <span class="badge text-bg-primary rounded-pill mt-1">3</span>
            <div>
                <strong>Preview</strong>
                <p class="mb-0 small text-secondary">Click <strong>Preview</strong> at any point to see the first 25 matching rows before you save.</p>
            </div>
        </div>
    </div>
</div>

<div class="portal-card p-4 mb-4" id="filters">
    <h2 class="h4 mb-3"><i class="fa-solid fa-filter me-2 text-primary"></i>Filters &amp; Grouping</h2>
    <p>Add filter rows to narrow results — pick a column, an operator (the choices change based on the column's type: text, number, date, yes/no, or a fixed list of values), and a value. All filter rows are joined by a single <strong>AND</strong> or <strong>OR</strong> switch.</p>
    <p>Turn on <strong>Group &amp; aggregate results</strong> to summarise instead of listing every row — pick a column to group by (dates can be bucketed by day/month/year) and one or more aggregates (count, sum, average, minimum, maximum). A grouped report also renders a bar/line chart above the data table.</p>
    <div class="alert alert-warning d-flex gap-2 mb-0" role="alert">
        <i class="fa-solid fa-triangle-exclamation mt-1"></i>
        <div>A grouped report can't also select arbitrary extra columns — its output is always the group column plus your chosen aggregates.</div>
    </div>
</div>

<div class="portal-card p-4 mb-4" id="sharing">
    <h2 class="h4 mb-3"><i class="fa-solid fa-share-nodes me-2 text-primary"></i>Saving, Sharing &amp; Running</h2>
    <ul class="list-group list-group-flush mb-3">
        <li class="list-group-item"><strong>Save</strong> gives the report a name and optional description. A saved report is a <em>definition</em> — never a stored copy of the data — so it always reflects live, current data when you run it.</li>
        <li class="list-group-item"><strong>Shared</strong> makes the report visible to other admins on this site (still admin-only — never visible to ordinary members). Leave it off to keep a report private to you.</li>
        <li class="list-group-item"><strong>Run</strong> executes the report fresh, paginated, with an <strong>Export CSV</strong> button alongside it. Editing or deleting someone else's report requires you to be a site admin.</li>
    </ul>
</div>

<div class="portal-card p-4 mb-4" id="security">
    <h2 class="h4 mb-3"><i class="fa-solid fa-shield-halved me-2 text-primary"></i>What You Can and Can't Report On</h2>
    <p>The report builder is built around a safety whitelist — you can only ever pick from a fixed, curated list of tables and columns; there is no way to type raw SQL or reach a table that isn't on that list.</p>
    <ul class="list-group list-group-flush mb-3">
        <li class="list-group-item d-flex gap-2">
            <i class="fa-solid fa-ban text-danger mt-1"></i>
            <div>Pastoral Care, Kids ministry, Safeguarding, and Prayer Requests are <strong>never</strong> reportable — those confidential domains aren't in the builder at all, by design.</div>
        </li>
        <li class="list-group-item d-flex gap-2">
            <i class="fa-solid fa-lock text-warning mt-1"></i>
            <div>Financial totals (expense amounts, giving amounts) require the Treasurer role or Site Admin. Donor identity on Giving reports requires the Treasurer role specifically.</div>
        </li>
        <li class="list-group-item d-flex gap-2">
            <i class="fa-solid fa-lock text-warning mt-1"></i>
            <div>Member email addresses require Site Admin. Only name, active status, locale, and join date are open to any report-building admin.</div>
        </li>
    </ul>
    <p class="small text-muted mb-0">Every report is re-checked against these rules every single time it runs — a saved report can never "leak" access it shouldn't have, even if roles change after it was created.</p>
</div>

<!-- 🚪 Cross-reference to the anonymous check-in figures (#525). They are NOT
     part of the report builder and never will be: they are anonymous counts
     with no link to a person, they are counted on a different basis from named
     attendance, and mixing the two in one report would invite exactly the
     subtraction the figures cannot survive. -->
<div class="portal-card p-4 mb-4" id="anon-checkins">
    <h2 class="h4 mb-3"><i class="fa-solid fa-door-open me-2 text-primary"></i>Anonymous Check-ins Are Somewhere Else</h2>
    <p>
        People who check in at the door without signing in &mdash; by scanning a QR code or pressing a
        button on a kiosk &mdash; are counted separately and are <strong>not</strong> part of the report
        builder or of the named attendance figures. Those counts appear on an event's attendance page,
        on the attendance reports page, and in a spreadsheet administrators can download from
        <a href="/attendance">/attendance</a>.
    </p>
    <p class="small text-muted mb-0">
        They are counted by the day the check-in arrived, while attendance figures are counted by the
        date of an attendance session, so the two are not two views of one thing and one should never be
        subtracted from the other. <a href="/help/calendar#anon-checkins">The calendar guide explains
        what those figures can and cannot tell you</a>, including who in your organisation may see them.
    </p>
</div>

<!-- Navigation -->
<div class="d-flex justify-content-end">
    <a href="/help/admin" class="btn btn-primary">
        Admin Guide<i class="fa-solid fa-arrow-right ms-1"></i>
    </a>
</div>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
