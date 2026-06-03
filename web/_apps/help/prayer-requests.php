<?php
// Path: public_html/help/prayer-requests.php
/**
 * -----------------------------------------------------------------------------
 * Help Centre — Prayer Requests Guide 🙏
 * -----------------------------------------------------------------------------
 * Walkthrough of submitting prayer requests (logged-in + anonymous public
 * route), visibility options, the moderation lifecycle, and testimony notes.
 * -----------------------------------------------------------------------------
 * @package    Portal\Help
 * @license    All Rights Reserved
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$pageTitle   = 'Help - Prayer Requests';
$pageSection = 'help';
$breadcrumbs = ['Dashboard' => '/', 'Help' => '/help', 'Prayer Requests' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-hands-praying me-2"></i>Prayer Requests Guide</h1>
        <p class="text-secondary mb-0">Submit prayer requests, choose how widely they're shared, and follow the moderation lifecycle.</p>
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
            <a href="#submitting" class="badge text-bg-secondary text-decoration-none">Submitting a Request</a>
            <a href="#visibility" class="badge text-bg-secondary text-decoration-none">Visibility Options</a>
            <a href="#anonymous" class="badge text-bg-secondary text-decoration-none">Anonymous Submissions</a>
            <a href="#lifecycle" class="badge text-bg-secondary text-decoration-none">Moderation Lifecycle</a>
            <a href="#testimony" class="badge text-bg-secondary text-decoration-none">Testimonies</a>
            <a href="#admins" class="badge text-bg-secondary text-decoration-none">For Moderators</a>
        </div>
    </div>
</div>

<!-- 1️⃣ Submitting -->
<section id="submitting" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-pen-to-square me-2"></i>Submitting a Request</h2>
    <p>
        From <a href="/prayer-requests">Prayer Requests</a> click
        <em>Submit a Request</em>. You'll be asked for a short subject and the body of
        your request. Up to 4000 characters of text are accepted.
    </p>
    <p>
        Once submitted, your request goes to the leadership team. If your site requires
        moderation (the default), it stays in a <em>Pending</em> queue until a leader
        approves it.
    </p>
</section>

<!-- 2️⃣ Visibility -->
<section id="visibility" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-eye me-2"></i>Visibility Options</h2>
    <p>You choose who can see each request:</p>
    <ul>
        <li>
            <span class="badge bg-secondary-subtle text-secondary-emphasis">
                <i class="fa-solid fa-user-shield me-1"></i>Leadership only
            </span>
            — visible only to pastors and site admins for confidential prayer.
            This is the default.
        </li>
        <li>
            <span class="badge bg-info-subtle text-info-emphasis">
                <i class="fa-solid fa-people-group me-1"></i>Congregation
            </span>
            — appears on the prayer feed visible to all logged-in members of this site.
            A moderator can change visibility at any time.
        </li>
    </ul>
    <p class="text-muted small">
        Your site admin can disable the congregation feed entirely under
        <em>Site Settings → Prayer Requests</em>.
    </p>
</section>

<!-- 3️⃣ Anonymous -->
<section id="anonymous" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-user-secret me-2"></i>Anonymous Submissions</h2>
    <p>There are two ways a request can be anonymous:</p>
    <ol>
        <li>
            <strong>Logged-in, displayed anonymously.</strong> When submitting, tick
            "<em>Display my name as Anonymous</em>". Other members see "Anonymous";
            leaders still see your real name for pastoral follow-up.
        </li>
        <li>
            <strong>Public anonymous route.</strong> Visitors without a portal account
            can submit via <code>/prayer-requests/anonymous</code>. These submissions
            are protected by CAPTCHA and rate limiting, and are always:
            <ul>
                <li>Sent to <em>leadership only</em> (never broadcast)</li>
                <li>Marked as <em>pending</em> for moderator review</li>
                <li>Treated as anonymous by default — name &amp; email are optional</li>
            </ul>
        </li>
    </ol>
</section>

<!-- 4️⃣ Lifecycle -->
<section id="lifecycle" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-arrow-rotate-right me-2"></i>Moderation Lifecycle</h2>
    <p>Each request moves through these statuses:</p>
    <div class="d-flex flex-wrap gap-2 mb-3">
        <span class="badge bg-warning"><i class="fa-solid fa-hourglass-half me-1"></i>Pending</span>
        <span>→</span>
        <span class="badge bg-success"><i class="fa-solid fa-hands-praying me-1"></i>Active</span>
        <span>→</span>
        <span class="badge bg-info"><i class="fa-solid fa-check-double me-1"></i>Answered</span>
        <span>→</span>
        <span class="badge bg-secondary"><i class="fa-solid fa-box-archive me-1"></i>Archived</span>
    </div>
    <ul class="small">
        <li><strong>Pending</strong> — awaiting moderator approval.</li>
        <li><strong>Active</strong> — published; appears on the leadership view (and the congregation feed if visibility is set accordingly).</li>
        <li><strong>Answered</strong> — closed with an optional praise / testimony note.</li>
        <li><strong>Archived</strong> — hidden from feeds but retained in the database.</li>
    </ul>
</section>

<!-- 5️⃣ Testimony -->
<section id="testimony" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-seedling me-2"></i>Testimonies</h2>
    <p>
        When a request is answered, a moderator can attach a short praise or testimony
        note. If the request is shared with the congregation, this note appears on
        the public feed alongside the original request, encouraging the wider church
        family.
    </p>
    <p class="text-muted small">
        Testimonies can be disabled site-wide via <em>Site Settings → Prayer Requests
        → Allow Testimony</em>.
    </p>
</section>

<!-- 6️⃣ Admins -->
<section id="admins" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-gauge-high me-2"></i>For Moderators</h2>
    <p>
        Site admins and leadership see a <em>Moderate</em> button on the prayer-requests
        landing page. The moderation queue is grouped by status, with quick-action
        buttons to approve, mark answered, or archive each request.
    </p>
    <p>
        Inside any request, the <em>Moderator actions</em> card lets you change
        visibility or add a testimony note when marking answered.
    </p>
    <p class="text-muted small">
        Anonymous submissions made by logged-in members still record the real submitter
        so moderators can offer pastoral follow-up. Submissions via the public anonymous
        route only contain the optional name &amp; email the submitter provided.
    </p>
</section>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
