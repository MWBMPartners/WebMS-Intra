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
$breadcrumbs = ['Dashboard' => \Portal\Core\Site::url(''), 'Help' => \Portal\Core\Site::url('help'), 'Small Groups' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-people-group me-2"></i>Small Groups Guide</h1>
        <p class="text-secondary mb-0">Groups, classes, and Bible studies — rosters, meeting rolls, and an optional link to Attendance.</p>
    </div>
    <a href="<?php echo htmlspecialchars(\Portal\Core\Site::url('help'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary btn-sm mt-2 mt-md-0">
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
            <a href="#hosts" class="badge text-bg-secondary text-decoration-none">Hosts, Homes and Maps</a>
            <a href="#data-protection" class="badge text-bg-secondary text-decoration-none">Data Protection</a>
        </div>
    </div>
</div>

<section id="overview" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-people-group me-2"></i>What is Small Groups?</h2>
    <p>
        <a href="<?php echo htmlspecialchars(\Portal\Core\Site::url('small-groups'), ENT_QUOTES, 'UTF-8'); ?>">Small Groups</a> is a register of the groups, classes, and studies your organisation
        runs — Sabbath School classes, home groups, Bible studies, or any other regularly-meeting group. Each group has
        a roster of members with roles, an optional meeting location, an optional link to a lesson/study resource, and
        a per-meeting roll for recording who attended.
    </p>
    <p class="text-muted small mb-0">
        <strong>Adults only in this version.</strong> Small Groups records portal user memberships — it does not hold
        named child rows. Children's classes can still exist here as groups (with visitor/headcount-only rolls), but
        a child's own identity, allergies, and safeguarding information live exclusively in the <a href="<?php echo htmlspecialchars(\Portal\Core\Site::url('kids/checkin'), ENT_QUOTES, 'UTF-8'); ?>">Kids</a>
        app, which has its own safeguarding model.
    </p>
</section>

<section id="attendance-link" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-link me-2"></i>Groups vs Attendance Service Types</h2>
    <p>
        The <a href="<?php echo htmlspecialchars(\Portal\Core\Site::url('attendance'), ENT_QUOTES, 'UTF-8'); ?>">Attendance</a> app records headcounts by <strong>service type</strong> — a
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

<?php // 🏠 #809 (D-120, D-122 to D-128): where a group meets is usually somebody's home, so each
      //    meeting place names its HOST and a map needs that host's own agreement. Each sentence
      //    below is what the code does: SmallGroups::mayEditHome() (D-125),
      //    SmallGroups::placeAgreementAfterSave() and whyHostChangeRefused() (D-124, D-128),
      //    place-save.php's remove action (D-126), GdprEraser's hostUserID instructions (D-122),
      //    and group.php's next-meeting panel (D-127). Change them together. ?>
<section id="hosts" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-house-user me-2"></i>Hosts, Homes and Maps</h2>
    <p>
        A group has its usual meeting place, and can also keep a list of members' homes it takes turns
        meeting in. A place that is somebody's home names its <strong>host</strong>: the member of the group who
        lives there. A place can also have no host named yet, or be marked as not anybody's home (see below).
        When a leader records or plans a meeting, they choose which of these places it is at.
    </p>
    <ul>
        <li><strong>Who may add or change a home.</strong> Leaders and coordinators may add or change any of the
            group's places and choose the host. A member may add their own home (they are always its host) and
            change only their own.</li>
        <li><strong>Maps need the host's own agreement.</strong> A map of a place is shown only when its host has
            ticked "a map of my home may be shown" on their own form, maps are allowed by your organisation and for
            the host, and the person looking has not switched maps off for themselves. A leader who types in somebody
            else's address cannot agree for them. Changing the address, or the host, means the host has to agree
            again. Otherwise only the written address is shown.</li>
        <li><strong>Only the host can change who lives there.</strong> Once a place names its host, nobody else can
            name a different host, remove the host, or mark it as not anybody's home. Anybody who may edit the place
            can still correct its address. To meet somewhere else, remove the home from the list, or clear every part
            of the usual place except the country, and enter the new place afresh.</li>
        <li><strong>A place that is nobody's home.</strong> A church hall or similar can be marked "This is not
            anybody's home". It then has no host, and a map of it may be shown to whoever can see its address, if
            their own map settings allow it. A place with a host cannot be marked, and changing the address clears
            the mark. <strong>Never tick it for somebody's home:</strong> that shows their home on a map without them
            agreeing, and the portal cannot tell a hall from a home.</li>
        <li><strong>Removing a home deletes its address at once.</strong> Meetings held or planned there then say they are at
            "a place no longer recorded".</li>
        <li><strong>Members see the next meeting.</strong> A meeting given a future date is a planned meeting. The
            group's members see the next one on the group's page, with where it is shown only if the group's
            address setting above lets them see addresses. A planned meeting takes no roll and is not counted in
            attendance figures.</li>
        <li><strong>When a host's account is erased,</strong> the address of every place they are named as host of
            is emptied, together with their agreement to a map. An address with no host named is not reached by
            anybody's erasure, because nothing records whose home it is.</li>
    </ul>
</section>

<section id="data-protection" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-shield-halved me-2"></i>Data Protection</h2>
    <p>
        Your group memberships, meeting attendance, and any group you created appear in your
        <a href="<?php echo htmlspecialchars(\Portal\Core\Site::url('account/data-export'), ENT_QUOTES, 'UTF-8'); ?>">data export</a>. If your account is erased, your membership rows are removed,
        including the record that you led a group (the group itself stays). The record that you attended a meeting, and the groups, meetings and
        registers you created or kept, stay for the group's own history with your account number taken off
        them. A meeting's topic and notes are kept as they were written, so they can still mention you, and
        the change history and the activity log can still hold your account number (the
        <a href="<?php echo htmlspecialchars(\Portal\Core\Site::url('privacy/policy'), ENT_QUOTES, 'UTF-8'); ?>">privacy notice</a>
        says how long each is kept). Separately, when somebody leaves a role here, any group leadership they
        held is ended as part of
        <a href="<?php echo htmlspecialchars(\Portal\Core\Site::url('offboarding'), ENT_QUOTES, 'UTF-8'); ?>">offboarding</a>.
    </p>
    <?php // 🗣️ #703 round 7: this said attendance and authorship rows are "anonymised (... your name is
          //    detached from it)". Checked against GdprEraser::catalogue(): those rows only ever held an
          //    account number, which is nulled; but a meeting's `topic` and `notes` are free text kept as
          //    written, so "anonymised" claimed more than is done. Membership rows (which carry the
          //    leader role) are deleted by the erasure itself; offboarding ending them is a separate,
          //    earlier step.
          //    Round 8, exact about the two logs: both are only PARTLY reached. The change history
          //    (`tblAuditTrail`) has the before-and-after values emptied on entries about the person's
          //    own account and their organisation, department, user-group and role memberships (#707,
          //    eraseAuditTrailAboutPerson()) - NOT their small-group memberships, which that step does
          //    not name. Who made a change is only detached, an entry keeps WHICH record changed, and
          //    an entry about a small group, membership or meeting, if one is ever written, keeps its
          //    values; it is kept for ever (D-116). (No change-history writer naming it was found:
          //    `grep -rln "'tblSmallGroupMembers'" web/_apps web/_core` - the table name as a quoted
          //    literal, which is how Logger::audit() is told the table - finds only the eraser and the
          //    catalogue. A call passing it some other way would not be seen.) The activity log
          //    (`tblActivityLogs`) has the account number, address and session details removed from
          //    the person's own records, but what each says is kept as written, until the nightly
          //    clear-out deletes it. Hence "can still hold your account number". "How each is cleared"
          //    became "how long each is kept", because the change history is never cleared. ?>
</section>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
