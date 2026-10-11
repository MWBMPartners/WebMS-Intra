<?php
// Path: _apps/admin/calendar/feeds-choice.php
/**
 * -----------------------------------------------------------------------------
 * Admin — who sees one imported date, or every date of a repeating event 🗓️👁️
 * -----------------------------------------------------------------------------
 * `admin/calendar/feeds/choice?event=N&scope=date` sets a CHOICE for one date
 * of an event copied in from an outside calendar; `&scope=series` sets one for
 * every date of that repeating event. A choice decides who may see those
 * dates and how much of them, in place of the rules and the calendar's own
 * setting. The form posts to itself: a refusal draws the page again with the
 * message and everything typed; a save or a removal returns to the
 * calendar's own page.
 *
 * WHAT THE PAGE SHOWS, AND WHY
 * ----------------------------
 *   * The dates the choice covers. For one date, ONLY that date — never
 *     another date of the same series (challenge L17c): the page sends each
 *     listed date back as "seen" (`seen[]` = event number : the date's
 *     content fingerprint), and part 7's resolver approves a date it was
 *     shown, without looking again. Listing a sibling here would let it be
 *     approved unseen. For every date: every live date of the series.
 *   * Each date's state (C10 item 4): "Waiting for approval", "A request
 *     was declined on <moment>" (worded as history — see where it is drawn),
 *     or nothing; "Details differ from the date you opened" when
 *     its content is not the opened date's; whether it is marked private in
 *     the outside calendar; whether it has a description, a location, a link.
 *     So an administrator who widens a series SEES which dates a colleague
 *     declined before the save approves them, and the save says how many it
 *     approved ("… are now approved", the owner's wording of 25 September
 *     2026 — never "now shown": a choice whose first day is ahead records the
 *     approval now and shows nothing until it starts).
 *   * When the CALENDAR is set "Don't show via API", a line under the box says
 *     these dates will not be sent to API keys whatever is chosen (C10 item 3).
 *   * When any covered date is marked private in the outside calendar and the
 *     choice would show it to anybody, a warning and a tick box: showing it is
 *     the administrator's decision, never a rule's.
 *
 * EVERY RULE LIVES IN ONE CLASS
 * -----------------------------
 * `FeedAdmin::saveChoice()` and `removeChoice()` check who is asking again,
 * check every field, keep only "seen" dates this choice really covers, take
 * the calendar's lock, write, and work out who may see each date with the
 * database's own clock — one transaction. The page shows FeedAdmin's own
 * sentence, escaped, exactly as it comes.
 *
 * WHO MAY USE IT
 * --------------
 * Signed in, and an administrator OF THIS ORGANISATION, else the portal's 403
 * page. The event is looked up by number AND organisation, and must be a live
 * imported event WITH an identity: another organisation's event, a missing
 * one, the portal's own event, an identity-less row left by the old #327
 * importer (challenge M3) and — for `series` — a one-off date all get the
 * same 404 page. A choice is attached to an identity, so a row without one
 * can have none until the next refresh gives it one.
 *
 * ONE EXCEPTION, FOR REMOVING ONLY (fix round 2 of chunk P8-3, check finding
 * F1): `series` for a one-off date answers when a choice for every date was
 * left over from when the event repeated. The resolver finds that choice by
 * the event's identity alone, so it keeps deciding the one date left after
 * an outside calendar turns a repeating event into a one-off with the same
 * UID — and before this nothing showed it or could remove it. The page then
 * shows that choice and offers Remove, and nothing else: a new choice for
 * every date can never be MADE for a date that does not repeat (FeedAdmin
 * refuses it too). Without such a leftover the answer is the same 404.
 *
 * WHAT IT CANNOT DO
 * -----------------
 * - Show a date's location or description text: FeedAdmin's list of covered
 *   dates says only WHETHER each has one (and that is what decides the "full
 *   details" fingerprint).
 * - Stop the dates of a series changing between drawing the page and saving
 *   it. A date that changed is not approved by the save; it waits, and the
 *   message says how many do.
 *
 * Twin page: `feeds-rule.php` draws the same "who may see it" block (level,
 * list, detail, website box, API box, days). Change one, look at the other.
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

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\EventVisibility;
use Portal\Core\ExternalAudience;
use Portal\Core\FeedAdmin;
use Portal\Core\FeedResolver;
use Portal\Core\Logger;
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

// 🔎 Which event, and which scope. Every way of not finding it gets the same
//    404 page (see the header).
$scope   = is_string($_GET['scope'] ?? null) === true ? (string) $_GET['scope'] : '';
$idRaw   = filter_var($_GET['event'] ?? null, FILTER_VALIDATE_INT);
$eventId = ($idRaw === false || $idRaw === null || $idRaw <= 0) ? 0 : (int) $idRaw;
$event   = ($eventId > 0 && ($scope === 'date' || $scope === 'series')) ? FeedAdmin::importedEvent($mysqli, $eventId, $siteId) : null;
if ($event === null) {
    Router::renderError(404);
    return;
}
// "Every date" of a date that does not repeat: only a LEFTOVER choice for
// every date is shown (to be removed); otherwise the same 404 (see the header).
$inSeries = ((string) $event['externalRecurrenceKey'] !== '');
$leftover = false;
if ($scope === 'series' && $inSeries === false) {
    if (FeedAdmin::choiceFor($mysqli, $event, 'series') === null) {
        Router::renderError(404);
        return;
    }
    $leftover = true;
}
$feedId  = (int) $event['feedID'];
$listUrl = Site::url('admin/calendar/feeds');
$viewUrl = Site::url('admin/calendar/feeds/view') . '?id=' . $feedId;
$selfUrl = Site::url('admin/calendar/feeds/choice') . '?event=' . $eventId . '&scope=' . $scope;

/** Leave one message and go to `$url` (the house flash-and-return). */
$finish = static function (string $message, string $type, string $url): never {
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . $url, true, 302);
    exit();
};

/** One posted text field, or '' when it is missing or not text (a list would become "Array"). */
$postText = static function (string $field): string {
    $value = $_POST[$field] ?? '';

    return is_string($value) === true ? $value : '';
};

$failed  = 'That could not be saved, so nothing was changed. The problem has been recorded.';
$refusal = '';
$typed   = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // 🔐 The form token, before anything with a side effect.
    if (Auth::verifyCsrf($postText('csrf_token')) === false) {
        $finish('Invalid or expired form token. Nothing was changed. Please try again.', 'danger', $selfUrl);
    }
    $action = $postText('action');

    if ($action === 'remove') {
        try {
            $result = FeedAdmin::removeChoice($mysqli, $eventId, $scope, $siteId, $userId);
        } catch (\Throwable $e) {
            Logger::errorPlatform('FeedAdmin', 'Error', 'CHOICE_REMOVE', 'Removing a choice of event #' . $eventId . ' failed', $e->getMessage());
            $finish($failed, 'danger', $selfUrl);
        }
        if ($result['ok'] === true) {
            // The person is passed in (chunk P8-4 fix round 1): Logger::activity() names as the
            // line's user only the user it is GIVEN. It does read the session (the session's
            // number and a copy of it, less its form token and its sign-in secrets, go into every line), but it never takes the USER from it,
            // so without this the Activity page showed "System" for an administrator's own action.
            Logger::activity('ExternalChoiceRemoved', 'Event #' . $eventId . ' (' . $scope . ')', $userId);
            $finish((string) $result['message'], 'success', $viewUrl . '#events');
        }
        // "There is no choice to remove." (somebody removed it meanwhile) comes
        // back to this page; anything else means the event itself has gone.
        $finish((string) $result['message'], 'warning', ($result['message'] === 'There is no choice to remove.') ? $selfUrl : $listUrl);
    }

    if ($action !== 'save') {
        $finish('Unknown action. Nothing was changed.', 'danger', $selfUrl);
    }
    if ($leftover === true) {
        // Removing is the only thing a leftover choice offers (see the header).
        $finish('A new choice for every date cannot be made for an event that does not repeat. Nothing was changed.', 'danger', $selfUrl);
    }

    // Only TEXT reaches FeedAdmin; `seen[]` keeps only text entries (FeedAdmin
    // then keeps only well-formed ones for dates THIS choice covers).
    $seen = [];
    foreach ((is_array($_POST['seen'] ?? null) === true ? $_POST['seen'] : []) as $entry) {
        if (is_string($entry) === true) {
            $seen[] = $entry;
        }
    }
    $post = [
        'audienceLevel'        => $postText('audienceLevel'),
        'audience'             => is_array($_POST['audience'] ?? null) === true ? $_POST['audience'] : [],
        'detailLevel'          => $postText('detailLevel'),
        'websiteOptIn'         => ($postText('websiteOptIn') === '1') ? '1' : '0',
        'apiOptOut'            => ($postText('apiOptOut') === '1') ? '1' : '0',
        'fromDate'             => $postText('fromDate'),
        'toDate'               => $postText('toDate'),
        'note'                 => $postText('note'),
        'overridesPrivateMark' => ($postText('overridesPrivateMark') === '1') ? '1' : '0',
        'seen'                 => $seen,
    ];
    try {
        $result = FeedAdmin::saveChoice($mysqli, $eventId, $scope, $siteId, $userId, $post);
    } catch (\Throwable $e) {
        Logger::errorPlatform('FeedAdmin', 'Error', 'CHOICE_SAVE', 'Saving a choice of event #' . $eventId . ' failed', $e->getMessage());
        $finish($failed, 'danger', $selfUrl);
    }
    if ($result['status'] === 'not_found') {
        $finish((string) $result['message'], 'danger', $listUrl);
    }
    if ($result['ok'] !== true) {
        $refusal = (string) $result['message'];
        $typed   = $post;
    } else {
        Logger::activity('ExternalChoiceSaved', 'Choice #' . (int) ($result['choiceId'] ?? 0) . ' (event #' . $eventId . ', ' . $scope . ')', $userId);
        $finish((string) $result['message'], 'success', $viewUrl . '#events');
    }
}

// 📖 What to draw: the saved choice (if any), the dates it covers, and the
//    values for the form — what was typed after a refusal, else the saved
//    choice, else the date's CURRENT setting (so saving without touching
//    anything changes nothing).
$existing = FeedAdmin::choiceFor($mysqli, $event, $scope);
// One date of a repeating event with no choice of its own: is there a choice
// for every date? It decides this date instead (fix round 1 of chunk P8-3,
// check finding L2 — the page used to say "No choice yet: this date follows
// the rules…" even then, while the "Now:" line beside it said otherwise).
// Chosen by whether a choice for every date EXISTS for this identity — in
// force or not, the way the resolver finds it, by identity alone — not by
// whether this date repeats (fix round 2 of chunk P8-3, check finding F1: a
// choice made while the event repeated stays after it becomes a one-off).
$seriesChoice = ($scope === 'date') ? FeedAdmin::choiceFor($mysqli, $event, 'series') : null;
$covered  = FeedAdmin::datesCovered($mysqli, $event, $scope);
$orgZone  = FeedResolver::organisationZone($siteId);
if ($typed !== null) {
    $v = $typed;
} elseif ($existing !== null) {
    $v = [
        'audienceLevel' => (string) $existing['audienceLevel'], 'detailLevel' => (string) $existing['detailLevel'],
        'websiteOptIn' => ((int) $existing['websiteOptIn'] === 1) ? '1' : '0', 'apiOptOut' => ((int) $existing['apiOptOut'] === 1) ? '1' : '0',
        'fromDate' => (string) ($existing['fromDate'] ?? ''), 'toDate' => (string) ($existing['toDate'] ?? ''),
        'note' => (string) ($existing['note'] ?? ''), 'overridesPrivateMark' => ((int) $existing['overridesPrivateMark'] === 1) ? '1' : '0',
        'audience' => [],
    ];
} else {
    $v = [
        'audienceLevel' => (string) ($event['importLevel'] ?? 'members'), 'detailLevel' => (string) ($event['importDetail'] ?? 'basic'),
        'websiteOptIn' => ((int) ($event['importWebsite'] ?? 0) === 1) ? '1' : '0', 'apiOptOut' => '0',
        'fromDate' => '', 'toDate' => '', 'note' => '', 'overridesPrivateMark' => '0', 'audience' => [],
    ];
}
$currentList = ($existing !== null) ? (array) $existing['list'] : [];
$ticked      = [];
if ($typed !== null) {
    foreach ((array) $typed['audience'] as $kind => $values) {
        foreach ((array) $values as $value) {
            if (is_scalar($value) === true && ctype_digit((string) $value) === true) {
                $ticked[(string) $kind . ':' . (int) $value] = true;
            }
        }
    }
} else {
    foreach ($currentList as $entry) {
        $ticked[$entry['kind'] . ':' . $entry['refID']] = true;
    }
}
$options = ExternalAudience::options($mysqli, $siteId);

$categoryName = [];
foreach (FeedAdmin::categories($mysqli, $siteId) as $c) {
    $categoryName[(int) $c['categoryID']] = (string) $c['categoryName'];
}
$anyPrivate = false;
$anyTwice   = false; // listed twice by the outside calendar (final-check fix, F1)
foreach ($covered as $date) {
    if ((int) $date['externalPrivate'] === 1) {
        $anyPrivate = true;
    }
    if ((int) ($date['externalDuplicate'] ?? 0) === 1) {
        $anyTwice = true;
    }
}
// Why the date is set the way it is NOW, in words. A rule is named (read
// through FeedAdmin, this calendar and organisation only).
$nowRuleName = null;
if ((string) ($event['importSource'] ?? '') === 'rule' && $event['importSourceID'] !== null) {
    $nowRule     = FeedAdmin::rule($mysqli, (int) $event['importSourceID'], $feedId, $siteId);
    $nowRuleName = ($nowRule === null) ? null : (string) $nowRule['name'];
}

$flashMsg  = (string) ($_SESSION['flash_msg']  ?? '');
$flashType = (string) ($_SESSION['flash_type'] ?? '');
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
if (in_array($flashType, ['success', 'info', 'warning', 'danger'], true) === false) {
    $flashType = 'info';
}

$esc       = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$isSeries  = ($scope === 'series');
$pageTitle = $isSeries ? 'Who sees every date' : 'Who sees this date';
// 🍞 The calendar's name is quoted and cut to 40 characters in the trail, as
//    on its own page (fix round 1 of P8-2, L5 and M1).
$trailName   = (string) $event['feedName'];
$trailName   = (mb_strlen($trailName, 'UTF-8') > 40) ? mb_substr($trailName, 0, 39, 'UTF-8') . '…' : $trailName;
$breadcrumbs = [
    'Dashboard'                => Site::url(''),
    'Admin'                    => Site::url('admin'),
    'Outside calendars'        => $listUrl,
    '“' . $trailName . '”'     => $viewUrl,
    $pageTitle                 => '',
];
$levels  = ['public', 'members', 'groups', 'hidden'];
$details = ['basic', 'full'];

require_once __DIR__ . DIRECTORY_SEPARATOR . '_audience-picker.php';
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:960px;">
    <h1 class="h4 mb-1 text-break"><i class="fa-solid fa-eye me-2 text-primary" aria-hidden="true"></i><?php echo $esc($pageTitle); ?></h1>
    <p class="mb-1 text-break">
        <strong><?php echo $esc($event['eventName']); ?></strong>
        <span class="text-muted">· <?php echo $esc(FeedAdmin::showWallClock((string) $event['startDateTime'], $event['timezone'] === null ? null : (string) $event['timezone'], (int) $event['isAllDay'] === 1, $orgZone)); ?></span>
        <?php // The preview of this date as it is set now (chunk P8-4 fix round 1): the
              // preview page's `?event=` half was linked from nowhere until then. The
              // plan names no place for it, so it sits here, beside the date it shows.
              // "Preview what people see", not "What people see now" (fix round 2):
              // while the calendar is paused, or the date's answer has run out, the
              // stored answer is not what anybody sees now — the preview says so. ?>
        <a class="small ms-1 text-nowrap" id="choice-preview" href="<?php echo $esc(Site::url('admin/calendar/feeds/preview') . '?event=' . $eventId); ?>"><i class="fa-solid fa-eye me-1" aria-hidden="true"></i>Preview what people see</a>
    </p>
    <p class="small text-muted text-break mb-3">
        From the outside calendar <a href="<?php echo $esc($viewUrl); ?>"><?php echo $esc($event['feedName']); ?></a>.
        Now: <?php echo $esc(FeedAdmin::LEVEL_LABELS[(string) ($event['importLevel'] ?? '')] ?? 'Not worked out yet'); ?>
        (<?php echo $esc(FeedAdmin::sourceLabel($event['importSource'] === null ? null : (string) $event['importSource'], $nowRuleName)); ?>).
        <?php
        // 💬 What decides these dates, said the way the resolver decides: a
        //    date's own choice first, then the choice for every date, then the
        //    rules, then the calendar's own setting — each choice only while
        //    it is in force (between its first and last day, when it has
        //    them). Every sentence below is true whichever of those exist;
        //    the "Now:" line above says what decides it at this moment.
        //    The "no choice" sentences come from FeedAdmin::withoutChoiceSentence(),
        //    the same words as the message after Remove — and true for a date
        //    the outside calendar marks private or lists twice (the final
        //    whole-part check, F1: they used to say "follows the rules" there too).
        if ($leftover === true) {
            // Said in the box below instead.
        } elseif ($isSeries === true) {
            echo $esc($existing !== null
                ? 'A choice for every date already exists; saving replaces it. A date with a choice of its own follows that one instead while it is in force.'
                : FeedAdmin::withoutChoiceSentence('none', 'series', false, $anyPrivate, $anyTwice));
        } elseif ($existing !== null) {
            echo $esc('A choice for this date already exists; saving replaces it.');
        } else {
            echo $esc(FeedAdmin::withoutChoiceSentence('none', 'date', $seriesChoice !== null, $anyPrivate, $anyTwice));
        }
        ?>
    </p>

    <?php if ($flashMsg !== ''): ?>
        <div class="alert alert-<?php echo $esc($flashType); ?>" role="alert"><?php echo $esc($flashMsg); ?></div>
    <?php endif; ?>
    <?php if ($refusal !== ''): ?>
        <div class="alert alert-danger" role="alert"><?php echo $esc($refusal); ?></div>
    <?php endif; ?>

    <?php
    // 🧷 A choice for every date left over from when this event repeated
    //    (fix round 2 of chunk P8-3, check finding F1). Shown plainly on both
    //    pages: on this date's own page with a link to it, and on its
    //    "every date" page with Remove — the only action it offers.
    $leftoverChoice = ($leftover === true) ? $existing : (($inSeries === false) ? $seriesChoice : null);
    if ($leftoverChoice !== null):
        $leftoverLevel = (string) $leftoverChoice['audienceLevel'];
        $leftoverWords = (FeedAdmin::LEVEL_LABELS[$leftoverLevel] ?? $leftoverLevel)
            . (($leftoverLevel !== 'hidden' && isset(FeedAdmin::DETAIL_LABELS[(string) $leftoverChoice['detailLevel']]) === true)
                ? ', ' . FeedAdmin::DETAIL_LABELS[(string) $leftoverChoice['detailLevel']] : '');
        // Its list, for Selected groups (fix round 3), each name with its
        // state. NOT through ExternalAudience::stateLabel() (fix round 4,
        // check finding R4-1): two of its words — for an entry that no
        // longer exists, and for a kind this version does not use — say
        // "…will be removed when you save", which is true on a form that
        // saves the list and untrue here, because a leftover can never be
        // saved: saving never removes them; only removing the whole choice
        // does (or, for a person, erasing their data — GdprEraser deletes
        // their list rows — or deleting their account, whose list rows go
        // with it; corrected in chunk P8-4's step 0, check finding R5-2). So
        // this box has its own words: an entry that no longer exists keeps
        // the label current() gave it ("someone no longer in this
        // organisation", "a small group that no longer exists"), with no
        // more added; a kind this version does not use says so; a
        // switched-off group or role, and a person who left, keep their
        // state words. A state this box does not know adds nothing. The
        // list is its own sentence ("Chosen: …"), so no brackets nest.
        $leftoverList = '';
        if ($leftoverLevel === 'groups' && (array) $leftoverChoice['list'] !== []) {
            $boxStateWords = [
                'switched_off' => ' (switched off)',
                'left'         => ' (no longer a member)',
                'not_offered'  => ' (not used by this version)',
                'gone'         => '',
            ];
            $leftoverNames = [];
            foreach ((array) $leftoverChoice['list'] as $entry) {
                $leftoverNames[] = (string) $entry['label'] . ($boxStateWords[(string) $entry['state']] ?? '');
            }
            $leftoverList = ' Chosen: ' . implode(', ', $leftoverNames) . '.';
        }
        // Its first and last day, in words (fix round 3).
        $dayWords     = static function (?string $ymd): string {
            $d = ($ymd === null || $ymd === '') ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);

            return ($d === false) ? '' : $d->format('D j M Y');
        };
        $leftoverFrom = $dayWords($leftoverChoice['fromDate'] === null ? null : (string) $leftoverChoice['fromDate']);
        $leftoverTo   = $dayWords($leftoverChoice['toDate'] === null ? null : (string) $leftoverChoice['toDate']);
        $leftoverDays = ($leftoverFrom === '' && $leftoverTo === '')
            ? 'with no first or last day'
            : trim(($leftoverFrom !== '' ? 'from ' . $leftoverFrom : '') . ' ' . ($leftoverTo !== '' ? 'until ' . $leftoverTo : ''));
        // ⚖️ ONE wording, true in every state (fix round 3 of chunk P8-3,
        //    check finding R3-1). Round 2 said the leftover "still applies"
        //    and decides the date "unless the date has a choice of its own".
        //    Both could be untrue: a choice counts only while it is in force
        //    (between its first and last day), so a date's own choice that
        //    has not started or has ended does NOT win, and a leftover whose
        //    last day has passed applies to nothing. So the box says the
        //    leftover "is still saved", gives its days, and states the rule
        //    exactly; the "Now:" line above says what decides the date at
        //    this moment. Rejected: working out "in force now" here — that
        //    would be a second copy of the resolver's own rule
        //    (FeedResolver::windowActive(), private), free to drift from it.
    ?>
        <div class="alert alert-warning text-break" id="choice-leftover">
            <?php echo $esc('This event no longer repeats in the outside calendar, but a choice for every date of it, made while it repeated, is still saved: '
                . $leftoverWords . ', ' . $leftoverDays . '.' . $leftoverList . " That choice decides this date while it is in force, except while the date's own choice is in force."); ?>
            <?php if ($leftover === true): ?>
                <?php echo $esc('A new choice for every date cannot be made for an event that does not repeat; this one can only be removed.'); ?>
            <?php else: ?>
                <a href="<?php echo $esc(Site::url('admin/calendar/feeds/choice') . '?event=' . $eventId . '&scope=series'); ?>">See or remove that choice</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($leftover === false): ?>
    <form method="post" action="<?php echo $esc($selfUrl); ?>" class="row g-3" id="choice-form">
        <input type="hidden" name="csrf_token" value="<?php echo $esc(Auth::csrfToken()); ?>">
        <input type="hidden" name="action" value="save">

        <div class="col-12">
            <fieldset>
                <legend class="form-label fs-6 mb-1">Who can see <?php echo $isSeries ? 'these dates' : 'this date'; ?></legend>
                <?php foreach ($levels as $level): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="audienceLevel" id="choice-level-<?php echo $esc($level); ?>"
                               value="<?php echo $esc($level); ?>"<?php echo $v['audienceLevel'] === $level ? ' checked' : ''; ?>>
                        <label class="form-check-label" for="choice-level-<?php echo $esc($level); ?>"><?php echo $esc(FeedAdmin::LEVEL_LABELS[$level]); ?></label>
                    </div>
                <?php endforeach; ?>
            </fieldset>
        </div>

        <div class="col-12" id="choice-audience-block">
            <h2 class="h6">Selected groups and people</h2>
            <?php portal_feed_audience_picker(['prefix' => 'choice', 'options' => $options, 'current' => $currentList, 'ticked' => $ticked]); ?>
        </div>

        <div class="col-12">
            <fieldset aria-describedby="choice-detail-help">
                <legend class="form-label fs-6 mb-1">How much detail</legend>
                <?php foreach ($details as $detail): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="detailLevel" id="choice-detail-<?php echo $esc($detail); ?>"
                               value="<?php echo $esc($detail); ?>"<?php echo $v['detailLevel'] === $detail ? ' checked' : ''; ?>>
                        <label class="form-check-label" for="choice-detail-<?php echo $esc($detail); ?>"><?php echo $esc(FeedAdmin::DETAIL_LABELS[$detail]); ?></label>
                    </div>
                <?php endforeach; ?>
                <div class="form-text" id="choice-detail-help">Detail for people outside the calendar's own audience.</div>
            </fieldset>
        </div>

        <div class="col-12">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="choice-website" name="websiteOptIn" value="1"
                       aria-describedby="choice-website-help"<?php echo $v['websiteOptIn'] === '1' ? ' checked' : ''; ?>>
                <label class="form-check-label" for="choice-website">Also show on the public website</label>
            </div>
            <div class="form-text" id="choice-website-help">Adds these dates to the countdown widget and the sitemap. Only for "Everyone".</div>
        </div>
        <div class="col-12">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="choice-api" name="apiOptOut" value="1"
                       aria-describedby="choice-api-help"<?php echo $v['apiOptOut'] === '1' ? ' checked' : ''; ?>>
                <label class="form-check-label" for="choice-api">Don't show via API</label>
            </div>
            <div class="form-text" id="choice-api-help">API keys will not receive these dates. Leave it clear and API keys receive exactly what a signed-out visitor sees.</div>
            <?php if ((int) $event['feedApiOptOut'] === 1): ?>
                <?php // C10 item 3: one tick anywhere is enough — the calendar's own wins. ?>
                <div class="small text-warning-emphasis mt-1" id="choice-calendar-api-note">This calendar is set not to show via API, so these dates will not be sent to API keys whatever you choose here.</div>
            <?php endif; ?>
        </div>

        <div class="col-12 col-md-6">
            <label class="form-label" for="choice-from">First day</label>
            <input type="date" class="form-control" id="choice-from" name="fromDate" value="<?php echo $esc($v['fromDate']); ?>" aria-describedby="choice-days-help">
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label" for="choice-to">Last day</label>
            <input type="date" class="form-control" id="choice-to" name="toDate" value="<?php echo $esc($v['toDate']); ?>" aria-describedby="choice-days-help">
        </div>
        <div class="col-12 form-text mt-0" id="choice-days-help">Days on the organisation's own calendar. Empty means no start or no end.</div>

        <div class="col-12">
            <label class="form-label" for="choice-note">Note (for administrators)</label>
            <input type="text" class="form-control" id="choice-note" name="note" maxlength="255" value="<?php echo $esc($v['note']); ?>">
        </div>

        <?php if ($anyPrivate === true): ?>
            <div class="col-12">
                <div class="alert alert-warning mb-1" id="choice-private-warning">
                    This event is marked private in the outside calendar. Showing it is your decision, not a rule's.
                    (Not needed for "Only administrators".)
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="choice-private" name="overridesPrivateMark" value="1"
                           aria-describedby="choice-private-warning"<?php echo $v['overridesPrivateMark'] === '1' ? ' checked' : ''; ?>>
                    <label class="form-check-label" for="choice-private">I have checked, and I want to show it</label>
                </div>
            </div>
        <?php endif; ?>

        <div class="col-12">
            <h2 class="h6" id="choice-dates-heading"><?php echo $isSeries ? 'The dates it covers (' . count($covered) . ')' : 'The date it covers'; ?></h2>
            <?php // Each listed date goes back as "seen" — FeedAdmin keeps only
                  // dates this choice covers; for one date that is only its own
                  // (see the header, challenge L17c). ?>
            <div class="portal-data-list" aria-labelledby="choice-dates-heading">
            <?php foreach ($covered as $date): ?>
                <input type="hidden" name="seen[]" value="<?php echo (int) $date['eventID'] . ':' . $esc($date['cf']); ?>">
                <div class="portal-data-row" id="date-<?php echo (int) $date['eventID']; ?>">
                    <div class="portal-data-row-main text-break" style="flex: 1 1 auto; min-width: 0;">
                        <strong><?php echo $esc($date['eventName']); ?></strong>
                        <?php if ($date['state'] === 'waiting'): ?>
                            <span class="badge bg-warning text-dark ms-1">Waiting for approval</span>
                        <?php elseif ($date['state'] === 'declined'): ?>
                            <?php // Worded as HISTORY, in grey (the last narrow check before part 8's
                                  // commit): the newest decision about this date was a decline, but an
                                  // OLDER approval can be the one in force again — a rule edited back to
                                  // what was approved before — so the date may be showing to everyone.
                                  // "Declined on …" in red then read as "this date is declined now",
                                  // which was untrue. "A request was declined on …" is true either way. ?>
                            <span class="badge bg-secondary ms-1">A request was declined on <?php echo $esc(FeedAdmin::showMoment($date['declinedAt'] === null ? null : (string) $date['declinedAt'], $orgZone)); ?></span>
                        <?php endif; ?>
                        <?php if ($date['differs'] === true): ?>
                            <span class="badge bg-info text-dark ms-1">Details differ from the date you opened</span>
                        <?php endif; ?>
                        <?php if ((int) $date['externalPrivate'] === 1): ?>
                            <span class="badge bg-secondary ms-1">Marked private in the outside calendar</span>
                        <?php endif; ?>
                        <div class="small text-muted">
                            <?php echo $esc(FeedAdmin::showWallClock($date['startDateTime'], $date['timezone'] === '' ? null : $date['timezone'], (int) $date['isAllDay'] === 1, $orgZone)); ?>
                            <?php if ($date['categoryID'] !== null && isset($categoryName[(int) $date['categoryID']]) === true): ?>
                                · <?php echo $esc($categoryName[(int) $date['categoryID']]); ?>
                            <?php endif; ?>
                        </div>
                        <div class="small text-muted">
                            <?php echo $esc(implode(' · ', array_filter([
                                $date['hasDescription'] === true ? 'Has a description' : '',
                                $date['hasLocation'] === true ? 'Has a location' : '',
                                $date['hasLink'] === true ? 'Has a link' : '',
                            ], static fn (string $s): bool => $s !== ''))); ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        </div>

        <div class="col-12 d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-primary">Save</button>
            <a class="btn btn-outline-secondary" href="<?php echo $esc($viewUrl . '#events'); ?>">Cancel</a>
        </div>
    </form>
    <?php endif; ?>

    <?php if ($existing !== null): ?>
        <form method="post" action="<?php echo $esc($selfUrl); ?>" class="mt-3">
            <input type="hidden" name="csrf_token" value="<?php echo $esc(Auth::csrfToken()); ?>">
            <input type="hidden" name="action" value="remove">
            <button type="submit" class="btn btn-outline-danger btn-sm"
                    data-confirm="<?php
                    // The same words as FeedAdmin's own "Choice removed." sentence,
                    // from the same FeedAdmin::withoutChoiceSentence() (the final
                    // whole-part check, F1): chosen by whether a choice for every
                    // date exists (fix round 2 of P8-3, F1), and true for a date
                    // marked private or listed twice.
                    echo $esc(FeedAdmin::withoutChoiceSentence('confirm', $isSeries === true ? 'series' : 'date', $isSeries === false && $seriesChoice !== null, $anyPrivate, $anyTwice)); ?>"
                    data-confirm-destructive="true">Remove this choice</button>
        </form>
    <?php endif; ?>
</div>
<?php // 🪄 Convenience only (the server enforces both): the list shows only for
      //    "Selected groups and people", the website box only for "Everyone". ?>
<script nonce="<?php echo $esc(App::cspNonce()); ?>">
(function () {
    'use strict';
    var form = document.getElementById('choice-form');
    var block = document.getElementById('choice-audience-block');
    var website = document.getElementById('choice-website');
    if (form === null || block === null || website === null) {
        return;
    }
    function apply() {
        var picked = form.querySelector('input[name="audienceLevel"]:checked');
        var level = picked === null ? '' : picked.value;
        block.hidden = (level !== 'groups');
        website.disabled = (level !== 'public');
    }
    form.querySelectorAll('input[name="audienceLevel"]').forEach(function (radio) {
        radio.addEventListener('change', apply);
    });
    apply();
})();
</script>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
