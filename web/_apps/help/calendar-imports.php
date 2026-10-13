<?php
// Path: _apps/help/calendar-imports.php
/**
 * -----------------------------------------------------------------------------
 * Help Centre — Outside Calendars Guide 🗓️📥
 * -----------------------------------------------------------------------------
 * The administrators' guide to copying events in from somebody else's
 * published calendar (#514): adding one, who sees its events and how much of
 * them, choices for one date or every date, rules, approval, private marks,
 * the scheduled refresh, category matching, pausing and deleting, and what
 * API keys receive. The #514 part 8 plan, section 7.3, sets its contents and
 * their order; every statement was checked against the code that does it
 * (chunk P8-4), and the paragraph about API keys is part 7's agreed wording,
 * word for word.
 *
 * Part 11 of #514 (13 October 2026) added two sections: "kinds" (the six things
 * a "Selected groups and people" list can name, part 10) and "told" (how
 * administrators hear that dates wait, part 9). Every statement in them was
 * checked against `Portal\Core\ImportNotifier`, `ExternalAudience` and the
 * picker `admin/calendar/_audience-picker.php`.
 *
 * Check W50 of `tools/feed-admin-selftest.php` used to refuse the words
 * "notification", "07:00" and "quiet hours" anywhere on this page, because
 * part 9 did not exist when it was written and the rule then was never to
 * describe what is not built. Part 11 turned it round: W50 now REQUIRES the
 * "told" section to say what part 9 does (the three ways, the 07:00 e-mail,
 * what each carries, nothing held for quiet hours, and where each is switched
 * off). If you change that section's wording, change the check with it.
 *
 * Like every help page it is seeded `isProtected = 0` (migration 005), so it
 * can be read without signing in. It holds nothing secret: the scheduled
 * refresh's key is named only as the setting that holds it, never printed.
 *
 * Unlike the older help pages, which write bare `/help/...` addresses, every
 * link here comes from `Site::url()`, so it keeps the organisation's own
 * address prefix (the rule for every #514 part 8 file).
 *
 * @package    Portal\Help
 * @author     MWBM Partners Ltd (t/a MWservices)
 * @copyright  2026-present MWBM Partners Ltd (t/a MWservices)
 * @license    All Rights Reserved
 * @version    1.1.0
 * @link       https://github.com/MWBMPartners/WebMS-Intra/issues/514
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Site;

$esc         = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$pageTitle   = 'Help - Outside Calendars';
$pageSection = 'help';
$breadcrumbs = ['Dashboard' => Site::url(''), 'Help' => Site::url('help'), 'Outside Calendars' => ''];
$feedsUrl    = Site::url('admin/calendar/feeds');
// Every other address on this page is built the same way (Site::url), so it
// keeps the organisation's own address prefix and never ends in a file name.
$rolesUrl    = Site::url('admin/roles');
$usersUrl    = Site::url('admin/users');
$groupsUrl   = Site::url('admin/groups');
$deptsUrl    = Site::url('admin/departments');
$sitesUrl    = Site::url('admin/sites');
$emailUrl    = Site::url('admin/integrations/email');
$integrationsUrl = Site::url('admin/integrations');
$inboxUrl    = Site::url('approvals') . '#imports';
$prefsUrl    = Site::url('account/notifications');

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-rss me-2"></i>Outside Calendars Guide</h1>
        <p class="text-secondary mb-0">Copying events in from a calendar somebody else publishes — and choosing exactly who sees them.</p>
    </div>
    <a href="<?php echo $esc(Site::url('help')); ?>" class="btn btn-outline-secondary btn-sm mt-2 mt-md-0">
        <i class="fa-solid fa-arrow-left me-1"></i>Back to Help Centre
    </a>
</div>

<div class="card mb-4 border-0 bg-body-tertiary">
    <div class="card-body">
        <h2 class="h6 card-title mb-2"><i class="fa-solid fa-list me-1"></i>On this page</h2>
        <div class="d-flex flex-wrap gap-2">
            <a href="#adding" class="badge text-bg-secondary text-decoration-none">Adding a calendar</a>
            <a href="#levels" class="badge text-bg-secondary text-decoration-none">Who sees what</a>
            <a href="#kinds" class="badge text-bg-secondary text-decoration-none">Groups and people</a>
            <a href="#detail" class="badge text-bg-secondary text-decoration-none">Title, date and time only</a>
            <a href="#choices" class="badge text-bg-secondary text-decoration-none">Choices</a>
            <a href="#rules" class="badge text-bg-secondary text-decoration-none">Rules</a>
            <a href="#approval" class="badge text-bg-secondary text-decoration-none">Approval</a>
            <a href="#told" class="badge text-bg-secondary text-decoration-none">How you are told</a>
            <a href="#repeating" class="badge text-bg-secondary text-decoration-none">Repeating events</a>
            <a href="#private" class="badge text-bg-secondary text-decoration-none">Private events</a>
            <a href="#dates" class="badge text-bg-secondary text-decoration-none">Start and end days</a>
            <a href="#categories" class="badge text-bg-secondary text-decoration-none">Category matching</a>
            <a href="#pausing" class="badge text-bg-secondary text-decoration-none">Pausing and deleting</a>
            <a href="#history" class="badge text-bg-secondary text-decoration-none">Refresh now and the history</a>
            <a href="#scheduled" class="badge text-bg-secondary text-decoration-none">The scheduled refresh</a>
            <a href="#upgrades" class="badge text-bg-secondary text-decoration-none">During an upgrade</a>
            <a href="#not-imported" class="badge text-bg-secondary text-decoration-none">What is not copied</a>
            <a href="#approvals-off" class="badge text-bg-secondary text-decoration-none">Approvals switched off</a>
            <a href="#api-keys" class="badge text-bg-secondary text-decoration-none">API keys</a>
        </div>
    </div>
</div>

<?php // 1 ?>
<section id="adding" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-plus me-2"></i>What an outside calendar is, and adding one</h2>
    <p>
        An outside calendar is a calendar somebody else publishes — a partner organisation's diary, a regional
        bulletin, a list of public holidays. The portal checks it regularly and copies its events into your
        organisation's calendar, where they show only to the people you choose.
    </p>
    <p>
        To add one, open <a href="<?php echo $esc($feedsUrl); ?>">Outside calendars</a> and choose
        <strong>Add an outside calendar</strong>. Paste the calendar's published address. An address that starts
        <code>webcal://</code> (the way calendar apps write one) works too: the portal reads it as a secure
        <code>https://</code> address. Addresses that point inside a private network — the hosting company's own
        machines, for example — are refused, and so is a name the portal cannot find.
    </p>
</section>

<?php // 2 ?>
<section id="levels" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-users me-2"></i>Who sees what</h2>
    <?php // A calendar offers exactly these three levels (the column is
          // ENUM('public','members','groups'), and FeedAdmin refuses anything
          // else); "Only administrators" exists only for choices and rules.
          // The first version listed it here too (chunk P8-4 fix round 1). ?>
    <p>Each calendar has its own setting for who may see its events:</p>
    <ul>
        <li><strong>Everyone, including people who are not signed in.</strong></li>
        <li><strong>Members of this organisation</strong> — being a member of this organisation is what this level means; nothing else is needed.</li>
        <li><strong>Selected groups and people</strong> — the small groups, leadership roles, roles, user groups, departments and named people you tick (the next section says what each one is). A named person loses access when they leave the organisation, and gets it back if they rejoin.</li>
    </ul>
    <p class="mb-0">
        Choices and rules (below) can set a different level for particular events, and they offer one more:
        <strong>Only administrators</strong>.
    </p>
</section>

<?php // 2b — part 10 of #514 (roles, user groups and departments) and part 11. Each
      // statement is checked against ExternalAudience (OFFERED_KINDS, MAX_MEMBERS),
      // EventVisibility's "who a selected groups list lets in" and the picker's own
      // sentences (admin/calendar/_audience-picker.php). Not a list inside the
      // "levels" section: W50 counts exactly three list items there. ?>
<section id="kinds" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-people-group me-2"></i>What you can tick under "Selected groups and people"</h2>
    <p>
        A list can name any mix of the six kinds below, up to 50 entries in all. A person is let in only while they
        are an active member of your organisation, and only through a group, role or department that belongs to
        YOUR organisation. One that belongs to another organisation lets nobody in, even a person who is a member of
        both.
    </p>
    <ul>
        <li><strong>Small groups</strong> — the groups in the Small Groups app. Their active members are let in, while the group is switched on.</li>
        <li><strong>Leadership roles</strong> — the posts in the Leadership app, such as elder or deacon. A person is let in while they hold the post today (their term has started and has not ended) and the post is switched on.</li>
        <li><strong>Roles</strong> — what a person may do in the portal, such as Treasurer or Expense Approver. They are not leadership roles. They are managed at <a href="<?php echo $esc($rolesUrl); ?>">Admin, Roles</a> and given to people on the <a href="<?php echo $esc($usersUrl); ?>">Admin, Users</a> page. Whoever holds the role is let in.</li>
        <li><strong>User groups</strong> — committees and working groups. They are not small groups. They are managed at <a href="<?php echo $esc($groupsUrl); ?>">Admin, Groups</a>. A retired user group lets nobody in.</li>
        <li><strong>Departments</strong> — what expense claims are charged to. Everyone in the department is included, whatever part they play in it. They are managed at <a href="<?php echo $esc($deptsUrl); ?>">Admin, Departments</a>. A department that is switched off lets nobody in.</li>
        <li><strong>Named people</strong> — individual members of your organisation, ticked by name.</li>
    </ul>
    <p class="mb-0">
        If a user group is retired or a department is switched off, its people stop seeing the events at once, and
        see them again if it is switched back on. You cannot newly tick a retired user group or a switched-off
        department. One that is already on a list stays there, lets nobody in, and is shown under "No longer in
        effect" until you untick it. A role, user group or department that has been deleted is shown the same way
        and is removed the next time the list is saved.
    </p>
</section>

<?php // 3 ?>
<section id="detail" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-eye-slash me-2"></i>"Title, date and time only"</h2>
    <?php // A calendar's own setting has no detail level: its answer is always
          // full detail for its own audience (FeedResolver's base answer). The
          // detail setting belongs to choices and rules — the first version said
          // "each setting" (chunk P8-4 fix round 1). ?>
    <p>
        A choice or a rule also says how much of an event people outside the calendar's own audience may see. At
        <strong>Title, date and time only</strong> they see the title, the date and time, and the category — but not
        the description, the location, the link, the outside calendar's name, or how many people are coming. At
        <strong>Full details</strong> they see everything the outside calendar gave.
    </p>
    <p class="mb-0">
        People inside the calendar's own audience see the whole event, and administrators always do. The exception
        is an event the outside calendar marks private: when a choice shows one, everyone but administrators sees
        only its title, date and time, and category, unless the choice is set to <strong>Full details</strong>.
    </p>
</section>

<?php // 4 ?>
<section id="choices" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-hand-pointer me-2"></i>Choices for one date, or for every date</h2>
    <p>
        On a calendar's own page, each event has <strong>Set this date…</strong>, and a date of a repeating event
        also has <strong>Set every date…</strong> (an event left over from the portal's older way of copying calendars
        gets these buttons after the calendar's next successful refresh). A choice decides who sees those dates instead of the calendar's
        own setting and instead of any rule. A date's own choice comes first, then a choice for every date, then
        the rules, then the calendar's own setting.
    </p>
    <p class="mb-0">
        A choice can have a first day and a last day. They are days on your organisation's own calendar, and the
        choice counts only between them.
    </p>
</section>

<?php // 5 ?>
<section id="rules" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-ruler me-2"></i>Rules</h2>
    <p>
        A rule picks events by what they say and sets who sees them. For example, a rule that shows outreach events
        to everyone could read: <em>category is exactly "Outreach" AND title contains "Open", EXCEPT title contains
        "planning"</em>. Every condition must match; an event that matches any exception is left out.
    </p>
    <p class="mb-0">
        <strong>Preview</strong> on the rule page shows what the rule would do to the calendar's events right now,
        and saves nothing.
    </p>
</section>

<?php // 6 ?>
<section id="approval" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-stamp me-2"></i>Why some dates wait for approval</h2>
    <p>
        A rule or a choice that would show an event to MORE people than the calendar's own setting — or, for an
        event the outside calendar marks private, to anyone but administrators — does not take effect straight
        away: the date waits until an administrator approves it. Until then it keeps the calendar's
        own setting (or, if the outside calendar marks it private, stays with administrators only). Showing an
        event to FEWER people needs no approval.
    </p>
    <p>A date waits for approval again — or for the first time — when:</p>
    <ul>
        <li>its title or category changes — or, at <strong>Full details</strong>, its description, location or link;</li>
        <li>the calendar's address is changed;</li>
        <li>it is a new date that was not on the choice page when the choice was saved.</li>
    </ul>
    <p class="mb-0">A change to the date or time alone never sends a date back for approval.</p>
</section>

<?php // 6b — part 9 of #514, written in part 11. Checked against
      // Portal\Core\ImportNotifier (its header, FIRST_HOUR, MAX_EVENTS_LISTED,
      // waiting(), recipients(), dashboardCard(), inboxRoute(), run()) and the
      // two switches on auth/account/notifications.php. Check W50 of
      // tools/feed-admin-selftest.php pins its main sentences. ?>
<section id="told" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-bell me-2"></i>How administrators are told that dates are waiting</h2>
    <p>
        Administrators of your organisation are told in three ways that imported events are waiting. A member gets none of them.
    </p>
    <ul>
        <li>
            <strong>A card on the dashboard</strong>, <em>Imported events waiting for approval</em>, with the number. It
            shows only while something is waiting, and it opens the list: the
            <a href="<?php echo $esc($inboxUrl); ?>">Approvals inbox</a>, or Outside calendars if your organisation
            has the Approvals app switched off.
        </li>
        <li>
            <strong>One e-mail a day</strong>, on a day when something is waiting. It lists each waiting event's title
            and time (up to 20; the rest are counted), says when the list was made, because an event can be decided or
            changed before the message is read, and links to the list.
        </li>
        <li>
            <strong>A push notification</strong> to your phone or computer, when a refresh finds a waiting date that is
            newer than the last one you were told about. It is one push however many dates are new, and it gives a
            number only, never a title.
        </li>
    </ul>
    <p>
        <strong>When.</strong> The e-mail goes on the first run of the scheduled refresh at or after 07:00 on your
        organisation's own clock (its time zone is set at <a href="<?php echo $esc($sitesUrl); ?>">Admin, Sites</a>,
        Edit). There is no cut-off hour: a refresh that first runs in the afternoon still sends that day's e-mail.
        There is one try a day: if the mail service refuses it, it is not tried again that day, and a message the
        mail service could not take at once is sent later the same day or not at all. The push notification goes on
        the first refresh that finds a newer waiting date, at any hour of the day or night.
    </p>
    <p>
        <strong>What it needs.</strong> Both are sent by the scheduled refresh (see "Setting up the scheduled refresh"
        below). If that is not set up, the dashboard card still works and nothing is sent. The e-mail also needs the
        portal's e-mail to be set up (<a href="<?php echo $esc($emailUrl); ?>">Admin, Integrations, Email</a>), and the
        push needs push notifications to be set up under <a href="<?php echo $esc($integrationsUrl); ?>">Admin, Integrations</a>.
        Until that is done nobody is recorded as told, so the first refresh after you set it up tells people.
    </p>
    <p>
        <strong>Who.</strong> The administrators of your organisation, global administrators who belong to it included.
        If none of them has an e-mail address the portal can use, every global administrator is told as well.
        Each person is shown only what the Approvals list would show them. A paused calendar's waiting dates are not
        counted; the list still shows them, marked "(paused)".
    </p>
    <p>
        <strong>Switching it off.</strong> Each person can do this on the
        <a href="<?php echo $esc($prefsUrl); ?>">Notification preferences</a> page: <strong>Approval requests</strong>
        switches off the daily e-mail (and the portal's other e-mails about approvals with it), and
        <strong>Imported events waiting for approval</strong> switches off the push notification.
    </p>
    <p class="mb-0">
        <strong>Nothing is held for quiet hours.</strong> The e-mail and the push notification are sent like every
        other e-mail and push message of the portal, whether or not the person is in their Sabbath quiet hours.
        (Quiet hours still stop a text message that is not urgent.) Your own device's settings decide whether a push
        notification makes a sound.
    </p>
</section>

<?php // 7 ?>
<section id="repeating" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-repeat me-2"></i>Repeating events</h2>
    <p class="mb-0">
        When you approve or decline one date of a repeating event, its other dates that wait for the very same
        request, with exactly the same details, follow that decision — so a weekly event does not need deciding week
        by week. A date whose details differ waits for its own decision. The same can happen to a date that the
        outside calendar files under the same event as a one-off date (it sometimes lists one changed date of an
        event that way): deciding the one-off can settle it too. The list of waiting dates shows where each one now
        stands.
    </p>
</section>

<?php // 8 ?>
<section id="private" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-user-lock me-2"></i>Private events</h2>
    <p class="mb-0">
        An event the outside calendar marks private is shown only to administrators. Rules never show it. A choice
        can, but the choice page warns you first, and you must tick "I have checked, and I want to show it" — showing
        it is your decision, not a rule's.
    </p>
</section>

<?php // 9 ?>
<section id="dates" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-calendar-day me-2"></i>Start and end days need the scheduled refresh</h2>
    <p>
        When a choice or rule starts or ends, the events it covers are shown only to administrators until the
        scheduled refresh next runs. If the scheduled refresh is not set up, they stay that way.
    </p>
    <p class="mb-0">
        While that is so, the calendar's own page says how many events are waiting for the scheduled refresh to
        update who can see them, and marks each one <strong>Waiting for the scheduled refresh</strong>.
    </p>
</section>

<?php // 10 ?>
<section id="categories" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-tags me-2"></i>Category matching</h2>
    <p class="mb-0">
        Outside calendars label their events with their own category words. On a calendar's own page, under
        <strong>Category matching</strong>, choose which of your organisation's categories each word means. An event
        gets the category of the first of its words, in alphabetical order, that is matched to one; an event none of
        whose words is matched gets the calendar's default category, if it has one.
    </p>
</section>

<?php // 11 ?>
<section id="pausing" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-pause me-2"></i>Pausing and deleting</h2>
    <p class="mb-0">
        <strong>Pause</strong> stops the refreshes and hides the calendar's events from everyone at once;
        <strong>Resume</strong> brings them back. <strong>Delete</strong> removes the calendar, every event copied in
        from it, and every answer people gave about coming to those events. It cannot be undone.
    </p>
</section>

<?php // 12 ?>
<section id="history" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-clock-rotate-left me-2"></i>Refresh now, and reading the history</h2>
    <p class="mb-0">
        <strong>Refresh now</strong> reads the calendar straight away (one refreshed less than a minute ago is not
        read again). Each calendar's own page lists its recent
        refreshes: when, what happened, and why. A refresh that fails, or a calendar that comes back empty, keeps
        the events copied in earlier — nothing is removed on the strength of it.
    </p>
</section>

<?php // 13 ?>
<section id="scheduled" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-gears me-2"></i>Setting up the scheduled refresh</h2>
    <p>
        Outside calendars are refreshed by a scheduled task, set up once for the whole portal in your hosting
        panel's scheduled tasks (often called "cron jobs"). Ask it to visit this address every 15 minutes:
    </p>
    <?php // An EXAMPLE address, labelled as one: the portal never builds in a real
          // address (#500), and the key's value is never printed — only the name
          // of the setting that holds it (feeds.cron_token). ?>
    <p><code>https://portal.example.org/cron/import-feeds?key=YOUR-KEY</code></p>
    <p class="small text-muted">
        That is an example: use your own portal's address, and in place of <code>YOUR-KEY</code> put the value of the
        <code>feeds.cron_token</code> setting on the portal's Settings page. With no key set, the address refuses
        every visit.
    </p>
    <p class="mb-0">
        The same scheduled task also sends the daily e-mail and the push notification described under "How
        administrators are told that dates are waiting". Without it they are never sent.
    </p>
</section>

<?php // 14 ?>
<section id="upgrades" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-screwdriver-wrench me-2"></i>During an upgrade</h2>
    <p class="mb-0">
        While the portal is being upgraded, scheduled refreshes wait; they carry on at their next turn once the upgrade
        has finished.
    </p>
</section>

<?php // 15 ?>
<section id="not-imported" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-paperclip me-2"></i>What is never copied in</h2>
    <p class="mb-0">Attachments and images in an outside calendar are never copied in.</p>
</section>

<?php // 16 ?>
<section id="approvals-off" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-toggle-off me-2"></i>When the Approvals app is switched off</h2>
    <?php // No link to the inbox here: while the app is switched off that address
          // only answers "disabled" (chunk P8-4 fix round 1). The calendar list
          // works either way. ?>
    <p class="mb-0">
        Waiting dates are normally listed in the Approvals inbox, under <strong>Imported events waiting for
        approval</strong>. If your organisation has switched the Approvals app off, open
        <a href="<?php echo $esc($feedsUrl); ?>">Outside calendars</a>, then each calendar's own page: its
        <strong>Waiting for approval</strong> section lists that calendar's waiting dates, with Approve and Decline.
        While the Approvals app is off, the dashboard card and the link in the daily e-mail open Outside calendars
        in the same way.
    </p>
</section>

<?php // 17 — part 7's agreed paragraph (B7), word for word, then one sentence per box. ?>
<section id="api-keys" class="mb-5">
    <h2 class="h4 mb-3"><i class="fa-solid fa-key me-2"></i>API keys</h2>
    <p id="api-keys-b7">
        An API key now receives an event from an outside calendar exactly when a signed-out visitor to your portal
        could see it, and in the same detail: every event from a calendar set to Public, whether or not "Also show on
        the public website" is ticked. That website box now controls only the countdown widget and the sitemap. To
        stop a calendar's events being sent through API keys, tick "Don't show via API" on that calendar (you can also
        tick it on a single date, a repeating event or a rule — one tick anywhere is enough).
    </p>
    <ul>
        <li><strong>Also show on the public website</strong> adds the events to the countdown widget and the sitemap, and is only for "Everyone".</li>
        <li><strong>Don't show via API</strong> stops API keys receiving the events.</li>
    </ul>
    <p>An API key is treated as a member of the public, never as a member of your organisation.</p>
    <?php // The organisation's own events and API keys are reviewed in #127 and #511. ?>
    <p class="mb-0">
        Your organisation's OWN events are a different matter: API keys receive every published one, members-only
        events included, as before. That is being reviewed separately.
    </p>
</section>

<?php
// The section that tells administrators how they hear about waiting dates
// ("told", above) was promised here by part 8 and written in part 11, once
// part 9 had built the behaviour. It describes what part 9 does today: an
// e-mail from the first run at or after 07:00, nothing held for quiet hours
// (the owner's decision of 13 October 2026).
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
