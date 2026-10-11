<?php
// Path: _apps/admin/calendar/feeds-preview.php
/**
 * -----------------------------------------------------------------------------
 * Admin — what the audience would see of an imported date 👁️🗓️
 * -----------------------------------------------------------------------------
 * Two ways in:
 *   - `?approval=N` — a waiting (or already decided) request to show a date
 *     more widely. It shows exactly what was asked for, from the snapshot
 *     taken when the request was made: who would see it, and how much.
 *   - `?event=N` — an imported date as it is set now: who sees it, and how
 *     much.
 *
 * WHAT IT SHOWS (the #514 part 8 plan, section 3.3)
 * -------------------------------------------------
 * - A heading naming the audience, and for Selected groups and people the
 *   names on the list as they are NOW (read live, this organisation only).
 * - The title, the date and time as the outside calendar wrote them (a
 *   wall-clock time, with its zone when that differs from the
 *   organisation's — never converted, so it agrees with the calendar
 *   grids), and the category.
 * - The description (as plain text, its line breaks kept — never as HTML),
 *   the location, and the link AS PLAIN TEXT, never a clickable link (an
 *   outside calendar chose that link; a click from an administrator's page
 *   must never depend on what it points at) — in three cases:
 *     - at "Full details";
 *     - for a DATE at "Title, date and time only" that is not marked
 *       private, when everyone the heading names is inside the calendar's
 *       own audience (they see the whole event whatever the detail);
 *     - for a DATE at "Only administrators".
 *   Otherwise they are left out, and a note under the card says for whom:
 *   people outside the calendar's own audience; everyone but
 *   administrators for a date marked private. A REQUEST at "Title, date and
 *   time only" never shows them — it kept no copy — and its note says so.
 * - For a request: why it waits, how many other dates of the same
 *   repeating event wait for the same decision (a present fact, never a
 *   prediction — plan top-section item 10), and, ONLY while the request
 *   waits and an earlier decision about the same event and request was
 *   replaced because the details changed,
 *   "Changed since it was last decided: …" naming only the fields BOTH
 *   versions carry (a "Title, date and time only" version holds no
 *   description, location or link to compare). A decided request never
 *   shows it (chunk P8-4 fix round 1).
 *
 * WHO MAY USE IT
 * --------------
 * Signed in, and an administrator OF THIS ORGANISATION, else the portal's
 * 403 page. Another organisation's request or date, a number that does not
 * exist, and an imported date with no identity yet (challenge M3) all get
 * the same 404 page: the look-ups are by number AND organisation, in
 * `FeedAdmin`.
 *
 * - When the calendar is PAUSED, or the date's stored answer has RUN OUT,
 *   a notice at the top says so, in the calendar pages' own words (chunk
 *   P8-4 fix round 2): the stored answer is then not what anybody sees.
 *
 * WHAT IT CANNOT DO
 * -----------------
 * - Show the date exactly as a visitor's browser would draw it: it shows
 *   the same FIELDS at the same level of detail, in this page's layout.
 * - Change anything: it is a page to look at. Deciding is on the inbox and
 *   on the calendar's own page.
 *
 * @package   Portal\App\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/514
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\AppRegistry;
use Portal\Core\Auth;
use Portal\Core\EventVisibility;
use Portal\Core\ExternalAudience;
use Portal\Core\FeedAdmin;
use Portal\Core\FeedResolver;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

// 🛡️ An administrator OF THIS ORGANISATION — whatever number was sent.
if (EventVisibility::isAdminOfSite($mysqli, $userId, $siteId) === false) {
    Router::renderError(403);
    return;
}

// 🔎 Exactly one of the two ways in. Every way of not finding it gets the
//    same 404 page (see the header).
$approvalRaw = filter_var($_GET['approval'] ?? null, FILTER_VALIDATE_INT);
$eventRaw    = filter_var($_GET['event'] ?? null, FILTER_VALIDATE_INT);
$approvalId  = ($approvalRaw === false || $approvalRaw === null || $approvalRaw <= 0) ? 0 : (int) $approvalRaw;
$eventId     = ($eventRaw === false || $eventRaw === null || $eventRaw <= 0) ? 0 : (int) $eventRaw;
$approval    = null;
$event       = null;
if ($approvalId > 0 && $eventId === 0) {
    $approval = FeedAdmin::approval($mysqli, $approvalId, $siteId);
} elseif ($eventId > 0 && $approvalId === 0) {
    $event = FeedAdmin::importedEvent($mysqli, $eventId, $siteId);
}
if ($approval === null && $event === null) {
    Router::renderError(404);
    return;
}

$orgZone = FeedResolver::organisationZone($siteId);
$esc     = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// 🧾 One shape for both ways in: who, how much, and the fields.
if ($approval !== null) {
    $feedId   = (int) $approval['feedID'];
    $feedName = (string) $approval['feedName'];
    $level    = (string) $approval['requestedLevel'];
    $detail   = (string) $approval['requestedDetail'];
    $names    = (array) $approval['names'];
    $fields   = [
        'title'       => (string) $approval['snapTitle'],
        'start'       => (string) $approval['snapStart'],
        'zone'        => $approval['snapTimezone'] === null ? null : (string) $approval['snapTimezone'],
        'allDay'      => (int) $approval['snapIsAllDay'] === 1,
        'categoryID'  => $approval['snapCategoryID'] === null ? null : (int) $approval['snapCategoryID'],
        'description' => (string) ($approval['snapDescription'] ?? ''),
        'location'    => (string) ($approval['snapLocation'] ?? ''),
        'link'        => (string) ($approval['snapUrl'] ?? ''),
    ];
} else {
    $feedId   = (int) $event['feedID'];
    $feedName = (string) $event['feedName'];
    $level    = (string) ($event['importLevel'] ?? 'hidden');
    $detail   = (string) ($event['importDetail'] ?? 'basic');
    // The list the date's answer uses (the calendar's, a choice's or a
    // rule's), read live and for this organisation only.
    $names    = ($level === 'groups' && $event['importAudienceType'] !== null && $event['importAudienceID'] !== null)
        ? ExternalAudience::current($mysqli, $siteId, $feedId, (string) $event['importAudienceType'], (int) $event['importAudienceID'])
        : [];
    $fields   = [
        'title'       => (string) $event['eventName'],
        'start'       => (string) $event['startDateTime'],
        'zone'        => $event['timezone'] === null ? null : (string) $event['timezone'],
        'allDay'      => (int) $event['isAllDay'] === 1,
        'categoryID'  => $event['categoryID'] === null ? null : (int) $event['categoryID'],
        'description' => (string) ($event['description'] ?? ''),
        'location'    => (string) ($event['locationName'] ?? ''),
        'link'        => (string) ($event['externalUrl'] ?? ''),
    ];
}
$full = ($detail === 'full');

// 👁️ Whether the people this page is about really see only the title, date
//    and time (the final whole-part check, F2). The detail setting limits
//    ONLY people outside the calendar's own audience: anyone inside it sees
//    the whole event, and administrators always do (EventVisibility's
//    canSeeFull). The first version drew the "left out" card whenever the
//    detail was "Title, date and time only" — so it told an administrator
//    that members do not see a description they in fact see. The one
//    exception is a date the outside calendar marks private: then everyone
//    but administrators gets the title, date and time only, unless the
//    detail is Full.
//    "Inside" here means EVERYONE the heading names is in the calendar's own
//    audience: a calendar set to Everyone; a Members calendar and a Members
//    or Selected-groups answer; a Selected-groups calendar and a date that
//    uses the calendar's own list. Anything else is mixed, and the page says
//    which people see what.
$calendarRow = FeedAdmin::calendar($mysqli, $feedId, $siteId);
$calLevel    = (string) ($calendarRow['audienceLevel'] ?? '');
$dateRow     = ($approval !== null) ? FeedAdmin::importedEvent($mysqli, (int) $approval['eventID'], $siteId) : $event;
// A request whose date can no longer be read (removed from the outside
// calendar since, or a row with no identity): whether it is marked private
// is then unknown, so nothing may be claimed about people INSIDE the
// calendar's own audience (the last narrow check before part 8's commit —
// it used to be treated as "not private").
$dateKnown   = ($dateRow !== null);
$isPrivate   = ((int) ($dateRow['externalPrivate'] ?? 0) === 1);
$inside      = ($calLevel === 'public')
    || ($calLevel === 'members' && ($level === 'members' || $level === 'groups'))
    || ($calLevel === 'groups' && $level === 'groups' && $approval === null && (string) ($event['importAudienceType'] ?? '') === 'feed');
if ($full === true) {
    $cardFull   = true;
    $detailNote = '';
} elseif ($level === 'hidden') {
    // Administrators always see the whole event.
    $cardFull   = ($approval === null);
    $detailNote = 'Administrators always see the whole event, whatever the detail setting.';
} elseif ($dateKnown === false) {
    $cardFull   = false;
    $detailNote = 'At this level of detail the description, the location and the link are left out for people outside the calendar\'s own audience. The date itself can no longer be read (it may have been removed from the outside calendar), so this page cannot say more about who sees them.';
} elseif ($isPrivate === true) {
    $cardFull   = false;
    $detailNote = 'The outside calendar marks this date private, so at this level of detail the description, the location and the link are left out for everyone but administrators.';
} elseif ($inside === true) {
    // A date's own fields are at hand; a request kept no copy of them at
    // this detail (its snapshot holds only the title, date, time and
    // category), so it says so rather than show nothing as if hidden.
    $cardFull   = ($approval === null);
    $detailNote = 'The detail is "Title, date and time only", but that limits only people outside the calendar\'s own audience — and everyone named here is inside it, so they see the description, the location and the link as well.'
        . ($approval !== null ? ' A request at this detail keeps no copy of them, so they are not shown here.' : '');
} else {
    $cardFull   = false;
    $detailNote = 'At this level of detail the description, the location and the link are left out for people outside the calendar\'s own audience; people inside it see them as well.';
}

// ⏸️ What decides who sees the date RIGHT NOW, beyond its stored answer
//    (chunk P8-4 fix round 2, check round 2's LOW 1). Both are tested live
//    by EventVisibility, so the page says so plainly, in the words the
//    calendar pages already use:
//    - a PAUSED calendar's events are hidden from everyone, administrators
//      included — shown for a date AND for a request (approving it shows it
//      to nobody while the calendar stays paused);
//    - a date whose stored answer has RUN OUT is shown only to administrators
//      until the scheduled refresh runs — shown for a date only: deciding a
//      request works the calendar out again at once, which renews the answer.
//    Without these the page said "What everyone will see …" about a date
//    nobody could see.
$isPaused  = ((int) (($approval !== null) ? $approval['feedActive'] : $event['feedActive']) !== 1);
$isRunOut  = ($approval === null && $isPaused === false && (int) $event['stale'] === 1);

$categoryName = [];
foreach (FeedAdmin::categories($mysqli, $siteId) as $c) {
    $categoryName[(int) $c['categoryID']] = (string) $c['categoryName'];
}
$categoryOf = static fn (?int $id): string => ($id !== null && isset($categoryName[$id]) === true) ? $categoryName[$id] : '';

// The heading names the audience in a sentence that reads naturally.
$headings = [
    'public'  => 'What everyone will see, including people who are not signed in',
    'members' => 'What members of this organisation will see',
    'groups'  => 'What the selected groups and people will see',
    'hidden'  => 'What only administrators will see',
];
$heading = $headings[$level] ?? 'What will be shown';

// The state words for a name on a list, true on a page that never saves it
// (the same words as `_approval-rows.php`; ExternalAudience::stateLabel()'s
// "…will be removed when you save" belongs to the form that does save).
$stateWords = ['switched_off' => ' (switched off)', 'left' => ' (no longer a member)', 'not_offered' => ' (not used by this version)', 'gone' => ''];
$nameWords  = [];
foreach ($names as $entry) {
    $nameWords[] = (string) $entry['label'] . ($stateWords[(string) $entry['state']] ?? '');
}

// 🔁 "Changed since it was last decided" — ONLY while this request WAITS,
//    compared with the newest REPLACED decision about the same event and
//    request (`FeedAdmin::approval()`'s `lastSuperseded`, with a decision
//    time), and only the fields both versions carry. Then it is true: the
//    details shown are the waiting ones, and a decision was made about
//    different ones. (A waiting row whose details change is brought up to
//    date in place, never replaced, so a replaced row of the same request
//    is a decided one — FeedResolver, "approved or declined for DIFFERENT
//    content: that row is superseded". The one exception, the resolver
//    clearing away two live rows of one request after a fault or a hand
//    edit, can replace an undecided row; the decision-time test below
//    leaves such a row out, so the line is then simply not shown.)
//
//    WHAT THIS REPLACED (chunk P8-4 fix round 1, check finding LOW 3): the
//    line was shown for any row with an earlier replaced decision — so a row
//    that had since been APPROVED said "It was approved on … Changed since it
//    was last decided: the location.", although it was last decided on that
//    very location.
$changed = [];
if ($approval !== null && (string) $approval['status'] === 'pending'
    && is_array($approval['lastSuperseded']) === true && $approval['lastSuperseded']['decidedAt'] !== null) {
    $old = $approval['lastSuperseded'];
    if ((string) $old['snapTitle'] !== $fields['title']) {
        $changed[] = 'the title';
    }
    if (($old['snapCategoryID'] === null ? null : (int) $old['snapCategoryID']) !== $fields['categoryID']) {
        $changed[] = 'the category';
    }
    if ($full === true && (string) $old['requestedDetail'] === 'full') {
        if ((string) ($old['snapDescription'] ?? '') !== $fields['description']) {
            $changed[] = 'the description';
        }
        if ((string) ($old['snapLocation'] ?? '') !== $fields['location']) {
            $changed[] = 'the location';
        }
        if ((string) ($old['snapUrl'] ?? '') !== $fields['link']) {
            $changed[] = 'the link';
        }
    }
}

// ↩️ Back: a request goes back to where it is decided — the inbox when the
//    Approvals app is on, else the calendar's own page; a date goes back to
//    its calendar's list of events.
$viewUrl = Site::url('admin/calendar/feeds/view') . '?id=' . $feedId;
if ($approval !== null) {
    $backUrl = (AppRegistry::isEnabled('approvals') === true) ? Site::url('approvals') . '#imports' : $viewUrl . '#waiting';
} else {
    $backUrl = $viewUrl . '#events';
}

$pageTitle = 'Preview';
// 🍞 The calendar's name is quoted and cut to 40 characters in the trail, as
//    on its own page (chunk P8-2 fix round 1, L5 and M1).
$trailName   = (mb_strlen($feedName, 'UTF-8') > 40) ? mb_substr($feedName, 0, 39, 'UTF-8') . '…' : $feedName;
$breadcrumbs = [
    'Dashboard'            => Site::url(''),
    'Admin'                => Site::url('admin'),
    'Outside calendars'    => Site::url('admin/calendar/feeds'),
    '“' . $trailName . '”' => $viewUrl,
    $pageTitle             => '',
];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:880px;">
    <h1 class="h4 mb-1 text-break"><i class="fa-solid fa-eye me-2 text-primary" aria-hidden="true"></i><?php echo $esc($heading); ?></h1>
    <?php if ($isPaused === true): ?>
        <div class="alert alert-warning small py-2 mb-2" id="preview-paused">
            <span class="badge bg-secondary">Paused</span> That calendar is paused. Its events are hidden until you resume it.
        </div>
    <?php elseif ($isRunOut === true): ?>
        <div class="alert alert-warning small py-2 mb-2" id="preview-run-out">
            <span class="badge bg-warning text-dark">Waiting for the scheduled refresh</span> Until the scheduled refresh next runs, this date is shown only to administrators.
        </div>
    <?php endif; ?>
    <p class="small text-muted text-break mb-3">
        <?php echo $approval !== null ? 'A request to show a date more widely' : 'A date as it is set now'; ?>,
        from the outside calendar <a href="<?php echo $esc($viewUrl); ?>"><?php echo $esc($feedName); ?></a>.
        Detail for people outside the calendar's own audience: <?php echo $esc(FeedAdmin::DETAIL_LABELS[$detail] ?? $detail); ?>.
    </p>
    <?php if ($nameWords !== []): ?>
        <p class="small text-break" id="preview-names">Chosen: <?php echo $esc(implode(', ', $nameWords)); ?></p>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-body text-break">
            <h2 class="h5 mb-1" id="preview-title"><?php echo $esc($fields['title']); ?></h2>
            <p class="mb-1"><?php echo $esc(FeedAdmin::showWallClock($fields['start'], $fields['zone'], $fields['allDay'], $orgZone)); ?></p>
            <?php if ($categoryOf($fields['categoryID']) !== ''): ?>
                <?php // A badge never wraps by itself; a long category name would push the
                      // page sideways on a phone (measured: 727 px at 390), so it may wrap. ?>
                <p class="mb-1"><span class="badge bg-primary text-wrap text-break text-start"><?php echo $esc($categoryOf($fields['categoryID'])); ?></span></p>
            <?php endif; ?>
            <?php if ($cardFull === true): ?>
                <?php if ($fields['description'] !== ''): ?>
                    <?php // Plain text, its line breaks kept — never HTML. ?>
                    <div class="mt-2" id="preview-description"><?php echo nl2br($esc($fields['description'])); ?></div>
                <?php endif; ?>
                <?php if ($fields['location'] !== ''): ?>
                    <p class="mt-2 mb-1" id="preview-location"><i class="fa-solid fa-location-dot me-1" aria-hidden="true"></i><?php echo $esc($fields['location']); ?></p>
                <?php endif; ?>
                <?php if ($fields['link'] !== ''): ?>
                    <?php // The link as TEXT, never a clickable link (see the header). ?>
                    <p class="mb-0 small" id="preview-link">Link: <span class="font-monospace"><?php echo $esc($fields['link']); ?></span></p>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($detailNote !== ''): ?>
                <p class="small text-muted mt-2 mb-0" id="preview-detail-note"><?php echo $esc($detailNote); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($approval !== null): ?>
        <div class="small mb-3">
            <p class="mb-1">Why: <?php echo $esc(FeedAdmin::REASON_LABELS[(string) $approval['reason']] ?? (string) $approval['reason']); ?></p>
            <?php
            // A request that is no longer waiting says what became of it.
            $status = (string) $approval['status'];
            $state  = match ($status) {
                'approved'   => 'It was approved on ' . FeedAdmin::showMoment($approval['decidedAt'] === null ? null : (string) $approval['decidedAt'], $orgZone) . '.',
                'declined'   => 'It was declined on ' . FeedAdmin::showMoment($approval['decidedAt'] === null ? null : (string) $approval['decidedAt'], $orgZone) . '.',
                'superseded' => 'It is no longer waiting: a newer request, or changed details, replaced it.',
                'withdrawn'  => 'It is no longer waiting: nothing asks to show it more widely any more.',
                default      => 'Waiting since ' . FeedAdmin::showMoment((string) $approval['createdAt'], $orgZone) . '.',
            };
            ?>
            <p class="mb-1" id="preview-state"><?php echo $esc($state); ?></p>
            <?php if ((int) $approval['siblingsWaiting'] > 0): ?>
                <p class="mb-1" id="preview-siblings"><?php echo $esc((int) $approval['siblingsWaiting'] === 1
                    ? '1 other date of this repeating event is waiting for the same decision.'
                    : (int) $approval['siblingsWaiting'] . ' other dates of this repeating event are waiting for the same decision.'); ?></p>
            <?php endif; ?>
            <?php if ($changed !== []): ?>
                <p class="mb-1" id="preview-changed">Changed since it was last decided: <?php echo $esc(implode(', ', $changed)); ?>.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <a class="btn btn-sm btn-outline-secondary" href="<?php echo $esc($backUrl); ?>"><i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>Back</a>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
