<?php
// Path: apps/help/small-groups.php
/**
 * -----------------------------------------------------------------------------
 * Help Centre — Small Groups Guide 👥
 * -----------------------------------------------------------------------------
 * Plain-English walkthrough of Small Groups: what the app is for, how it
 * relates to Attendance service types, roles, joining a group, taking the
 * roll and the optional Attendance push, who can see the meeting address,
 * and a GDPR note.
 * -----------------------------------------------------------------------------
 * @package    Portal\Help
 * @author     MWBM Partners Ltd (t/a MWservices)
 * @copyright  2026-present MWBM Partners Ltd (t/a MWservices)
 * @license    All Rights Reserved
 * @version    1.0.0
 * @link       https://github.com/MWBMPartners/WebMS-Intra/issues/150
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

$pageTitle   = 'Help - Small Groups';
$pageSection = 'help';
$breadcrumbs = ['Dashboard' => '/', 'Help' => '/help', 'Small Groups' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-people-group me-2"></i>Small Groups Guide</h1>
        <p class="text-secondary mb-0">Groups, classes, and Bible studies — rosters, meeting rolls, and an optional link to Attendance.</p>
    </div>
    <a href="/help" class="btn btn-outline-secondary btn-sm mt-2 mt-md-0">
        <i class="fa-solid fa-arrow-left me-1"></i>Back to Help Centre
    </a>
</div>

<div class="card mb-4 border-0 bg-body-tertiary">
    <div class="card-body">
        <h6 class="card-title mb-2"><i class="fa-solid fa-list me-1"></i>On this page</h6>
        <div class="d-flex flex-wrap gap-2">
            <a href="#overview" class="badge text-bg-secondary text-decoration-none">What is Small Groups?</a>
            <a href="#attendance-link" class="badge text-bg-secondary text-decoration-none">Groups vs Attendance</a>
            <a href="#roles" class="badge text-bg-secondary text-decoration-none">Roles</a>
            <a href="#joining" class="badge text-bg-secondary text-decoration-none">Joining a Group</a>
            <a href="#roll" class="badge text-bg-secondary text-decoration-none">Taking the Roll</a>
            <a href="#location" class="badge text-bg-secondary text-decoration-none">Meeting Location</a>
            <a href="#data-protection" class="badge text-bg-secondary text-decoration-none">Data Protection</a>
        </div>
    </div>
</div>

<section id="overview" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-people-group me-2"></i>What is Small Groups?</h2>
    <p>
        <a href="/small-groups">Small Groups</a> is a register of the groups, classes, and studies your organisation
        runs — Sabbath School classes, home groups, Bible studies, or any other regularly-meeting group. Each group has
        a roster of members with roles, an optional meeting location, an optional link to a lesson/study resource, and
        a per-meeting roll for recording who attended.
    </p>
    <p class="text-muted small mb-0">
        <strong>Adults only in this version.</strong> Small Groups records portal user memberships — it does not hold
        named child rows. Children's classes can still exist here as groups (with visitor/headcount-only rolls), but
        a child's own identity, allergies, and safeguarding information live exclusively in the <a href="/kids/checkin">Kids</a>
        app, which has its own safeguarding model.
    </p>
</section>

<section id="attendance-link" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-link me-2"></i>Groups vs Attendance Service Types</h2>
    <p>
        The <a href="/attendance">Attendance</a> app records headcounts by <strong>service type</strong> — a
        congregation-wide total for "Sabbath School", "Morning Service", and so on. Small Groups is different: it
        tracks <strong>who, by name</strong>, attended a specific group's meeting.
    </p>
    <p>
        A group can optionally be linked to an Attendance service type. When it is, and a leader ticks
        "Also record headcount in Attendance" while saving a meeting roll, this app pushes a single headcount into
        that service type's Attendance session — the number of members marked present, plus any counted (unnamed)
        visitors. Several groups can share the same service type; each contributes its own line to that session's
        headcount breakdown, labelled with the group's name. This link is entirely additive — nothing in Attendance
        is changed, and removing the link (or unticking the push) never deletes anything on the Attendance side.
    </p>
</section>

<section id="roles" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-user-tag me-2"></i>Roles</h2>
    <div class="portal-data-list mb-3">
        <div class="portal-data-row">
            <div class="col-12 col-md-3"><strong>Leader / Co-leader</strong></div>
            <div class="col-12 col-md-9">Manages their own group's details, roster, and meeting rolls. Cannot change which Attendance service type a group is linked to, or take it inactive — those are admin/coordinator decisions.</div>
        </div>
        <div class="portal-data-row">
            <div class="col-12 col-md-3"><strong>Small Groups Coordinator</strong></div>
            <div class="col-12 col-md-9">A site-wide role that can manage every group — create, edit, and fully administer any group's roster and meetings, without needing to be an admin.</div>
        </div>
        <div class="portal-data-row">
            <div class="col-12 col-md-3"><strong>Admin</strong></div>
            <div class="col-12 col-md-9">Full access, same as a coordinator, plus the ability to enable the app and assign the coordinator role.</div>
        </div>
        <div class="portal-data-row">
            <div class="col-12 col-md-3"><strong>Member</strong></div>
            <div class="col-12 col-md-9">Sees their own groups on "My Groups", can leave a group, and — if the group allows it — request to join others from the directory.</div>
        </div>
    </div>
</section>

<section id="joining" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-user-plus me-2"></i>Joining a Group</h2>
    <p>
        A leader or coordinator can add anyone at the site straight to a group. Groups marked "Open enrolment" also
        let members request to join themselves from the directory — the request sits as <strong>pending</strong>
        until a leader approves or declines it. Leaving a group (or being removed) doesn't erase your history — the
        membership row is marked <strong>ended</strong> rather than deleted, and rejoining reactivates it.
    </p>
    <p class="text-muted small mb-0">A group can never be left without at least one active leader — the app refuses the last leader's own removal or self-demotion until another leader is assigned.</p>
</section>

<section id="roll" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-clipboard-check me-2"></i>Taking the Roll</h2>
    <p>
        From a group's page, a leader can record a meeting: date, time, topic, notes, a count of visitors (counted,
        never named), and a tick-list of which active members were present. Saving a roll for a date that already has
        one updates it in place rather than creating a duplicate. If the group is linked to an Attendance service
        type, ticking "Also record headcount in Attendance" pushes the total (present members + visitors) into that
        service type's session — see <a href="#attendance-link">above</a>.
    </p>
</section>

<section id="location" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-location-dot me-2"></i>Who Can See the Meeting Address?</h2>
    <p>
        Many groups meet in a member's home, so a group's meeting address is <strong>never public</strong>. Each
        group picks one of three visibility levels:
    </p>
    <ul>
        <li><strong>Leaders only</strong> — the address is visible only to that group's leaders and admins/coordinators.</li>
        <li><strong>Group members</strong> (the default) — visible to the group's own active members, leaders, and admins/coordinators.</li>
        <li><strong>Everyone at this site</strong> — visible to any logged-in member of the site.</li>
    </ul>
    <p class="text-muted small mb-0">The directory listing never shows an address — location only ever appears on a group's own detail page, and only when the viewer clears the visibility check above.</p>
</section>

<section id="data-protection" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-shield-halved me-2"></i>Data Protection</h2>
    <p>
        Your group memberships, meeting attendance, and any group you created appear in your
        <a href="/account/data-export">data export</a>. If your account is erased, your membership rows are removed,
        your attendance/authorship rows are anonymised (the group's own history is retained, but your name is
        detached from it), and any group leadership you held is ended as part of
        <a href="/offboarding">offboarding</a>.
    </p>
</section>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
