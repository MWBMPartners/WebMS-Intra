<?php
// Path: web/_apps/calendar/manage/series-repattern.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — change a repeating series' pattern 🔁
 * -----------------------------------------------------------------------------
 * Repeating events have shipped since #569, but on CREATION only. This page is
 * the other half: shifting every future date half an hour later, going weekly
 * to fortnightly, extending a run that stopped at the ceiling, or cutting the
 * rest of a run short.
 *
 * NOTHING IS WRITTEN WITHOUT A PREVIEW. The page has two steps, and both work
 * the change out through `Portal\Core\SeriesRepattern::plan()` from the same
 * facts, so what is confirmed is what happens. A digest of the previewed plan
 * is posted back with the confirmation and compared against a freshly worked
 * out one: if anybody has touched the series in between, the change is refused
 * and the new preview is shown instead. That is not a theoretical worry — two
 * administrators on one series is the ordinary case in a large organisation.
 *
 * WHY THIS PAGE HANDLES ITS OWN POSTS rather than having a save handler beside
 * it, which is the pattern `calendar/manage/series-edit` uses too: the preview
 * and the change must be worked out by ONE piece of code from ONE set of facts.
 * Two files would be two readings of the same question, and the whole point of
 * the preview is that it is trustworthy.
 *
 * WHAT IS SAFE HERE, AND WHY — the owner's three decisions of 31 October 2026
 * -----------------------------------------------------------------------------
 *   • FUTURE DATES ONLY, and a date with anything attached to it is never
 *     moved and never removed. `SeriesRepattern::ATTACHMENTS` is the list of
 *     every table that points at an event, and section B below asks each one.
 *   • A date that STAYS is updated in place — only its start and end change —
 *     so a hand-changed title, venue or description is never at risk.
 *   • A date that GOES is soft-deleted (`isDeleted = 1`), never removed from
 *     the table, so nothing attached to it is destroyed.
 *
 *     IT CAN BE BROUGHT BACK, AS A DRAFT, from the series' bulk edit page
 *     (`calendar/manage/series-edit`), since #691. `Portal\Core\HiddenSeriesDates`
 *     is the one place that decides it. Say exactly that and no more: it comes
 *     back as a DRAFT (D-145) — or still CANCELLED if it had been cancelled
 *     before it was hidden (D-146) — and onto a day the series
 *     already has a live date on — the ordinary case after a series is
 *     shortened and extended over the same weeks again, because this page then
 *     makes NEW rows for those days — the first press is refused and a second,
 *     deliberate "Bring it back anyway" press is needed (D-144), which leaves
 *     two dates on that day. Until #691 these sentences had to say
 *     there was no way back at all: an earlier "an administrator can put it
 *     back" was written when nothing could, and was corrected on 31 October
 *     2026 before the restore existed.
 *
 * @package   Portal\Calendar
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/669
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\EventRecurrence;
use Portal\Core\Events;
use Portal\Core\InstallationSettings;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\SeriesRepattern;
use Portal\Core\Site;
// ⚠️ A class used by its SHORT name needs its `use` line in THIS file, and
//    the line only fails when it RUNS — `php -l` is happy without it (#536).
use Portal\Core\I18n;

// 📌 Page metadata. Set before `header.php`, which requires `head-meta.php` —
//    the one place the title, description and sharing tags are written (#569).
$pageTitle       = 'Change a repeating pattern';
$pageDescription = 'Shift, extend, shorten or re-time every future date of a repeating series, with a preview of exactly what will change.';
$pageSection     = 'calendar';

// 🛡️ Administrators only, exactly as the rest of calendar/manage is.
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$siteId = Site::id();
$userId = isset($_SESSION['user_id']) === true ? (int) $_SESSION['user_id'] : null;

$breadcrumbs = [
    'Dashboard' => Site::url(''),
    'Calendar'  => Site::url('calendar'),
    'Manage'    => Site::url('calendar/manage'),
    'Series'    => Site::url('calendar/manage/series'),
    'Pattern'   => '',
];

// #############################################################################
// 🅰️ A. The series, and whether this organisation may change it
// #############################################################################
// 🛡️ THE SAME TWO TESTS THE CREATE HANDLER MAKES (#514, D5): the series must
//    belong to THIS organisation, and must hold no imported event. `FeedImporter`
//    is the ONE writer of an imported event, so a series holding one is not ours
//    to re-pattern — and there is no foreign key tying a series to an
//    organisation, so the database would accept the cross-organisation case
//    without complaint.
$seriesID = (int) ($_GET['seriesID'] ?? $_POST['seriesID'] ?? 0);

$backToSeries = Site::url('calendar/manage/series');

if ($seriesID <= 0) {
    $_SESSION['flash_msg']  = 'No series was given, so there was nothing to change.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $backToSeries);
    exit();
}

$series     = null;
$stmtSeries = $mysqli->prepare(
    'SELECT s.seriesID, s.seriesName FROM tblEventSeries s '
    . 'WHERE s.seriesID = ? AND s.siteID = ? '
    . '  AND NOT EXISTS (SELECT 1 FROM tblEvents xi WHERE xi.seriesID = s.seriesID AND xi.externalFeedID IS NOT NULL) '
    . 'LIMIT 1'
);
if ($stmtSeries !== false) {
    $stmtSeries->bind_param('ii', $seriesID, $siteId);
    $stmtSeries->execute();
    $series = $stmtSeries->get_result()->fetch_assoc();
    $stmtSeries->close();
}

if ($series === null) {
    // 🛡️ ONE message for "not yours", "does not exist" and "holds imported
    //    dates". Telling them apart would say whether another organisation's
    //    series exists, which is exactly what #514 is about.
    $_SESSION['flash_msg']  = 'That series could not be opened for a pattern change.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $backToSeries);
    exit();
}

// #############################################################################
// 🅱️ B. The series' own dates, and what is attached to each
// #############################################################################

$events = [];
$stmtEvents = $mysqli->prepare(
    'SELECT eventID, eventName, eventSlug, startDateTime, endDateTime, patternStart, patternEnd, timezone, status '
    . 'FROM tblEvents '
    . 'WHERE seriesID = ? AND siteID = ? AND isDeleted = 0 AND externalFeedID IS NULL '
    . 'ORDER BY startDateTime ASC, eventID ASC'
);
if ($stmtEvents !== false) {
    $stmtEvents->bind_param('ii', $seriesID, $siteId);
    $stmtEvents->execute();
    $res = $stmtEvents->get_result();
    while ($row = $res->fetch_assoc()) {
        $events[(int) $row['eventID']] = $row;
    }
    $stmtEvents->close();
}

$eventIds = array_keys($events);

/**
 * 🔗 WHAT IS ATTACHED TO EACH DATE, asked once per table rather than once per
 *    date. A 200-date series would otherwise need six thousand queries.
 *
 *    THE TABLE NAMES COME FROM A CODE CONSTANT, never from a request, which is
 *    what makes building the statement text from them safe. The event numbers
 *    are still bound as parameters.
 *
 * @var array<int,list<string>> $attachedTo eventID → the plain words for each kind of record found.
 */
$attachedTo = [];
if ($eventIds !== []) {
    $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
    $idTypes      = str_repeat('i', count($eventIds));

    foreach (SeriesRepattern::ATTACHMENTS as $table => $meaning) {
        // Backticked and taken from the constant: see the note above.
        $sql  = 'SELECT DISTINCT `eventID` FROM `' . $table . '` WHERE `eventID` IN (' . $placeholders . ')';
        $stmt = $mysqli->prepare($sql);
        if ($stmt === false) {
            // 🛡️ A TABLE THAT WILL NOT ANSWER STOPS THE PAGE, and that is
            //    deliberate. The alternative — carry on without it — would make
            //    every date it protects look unattached, and the change would
            //    then remove dates carrying real records. Refusing is the only
            //    safe direction.
            $_SESSION['flash_msg']  = 'The records attached to this series could not all be checked, so no change was offered.';
            $_SESSION['flash_type'] = 'danger';
            Logger::errorPlatform(
                'Calendar',
                'Error',
                'REPATTERN_ATTACHMENT_UNREADABLE',
                'one of the tables that points at an event could not be read, so a series pattern change was refused '
                . 'rather than risk removing dates that carry records',
                'seriesID=' . $seriesID . ' table=' . $table
            );
            header('Location: ' . $backToSeries);
            exit();
        }

        $stmt->bind_param($idTypes, ...$eventIds);
        $stmt->execute();
        $found = $stmt->get_result();
        while ($hit = $found->fetch_row()) {
            $id = (int) $hit[0];
            // The same words twice (two service-plan tables, two pastoral
            // ones) would read as a fault to somebody looking at the preview.
            if (in_array($meaning['words'], $attachedTo[$id] ?? [], true) === false) {
                $attachedTo[$id][] = $meaning['words'];
            }
        }
        $stmt->close();
    }
}

// #############################################################################
// 🅲 C. The pattern the series claims NOW
// #############################################################################
// Needed for two separate things, and it is worth being clear which:
//
//   1. It fills the form in, so somebody changing one field does not have to
//      retype the rest.
//   2. It is the FALLBACK for telling a hand-moved date from one the pattern
//      put there, for every date created before migration 232 recorded that
//      directly. Without it the feature would do nothing at all on an existing
//      installation — see the migration's own note.

$currentRule = null;
$stmtRule    = $mysqli->prepare(
    'SELECT ruleID, frequency, intervalVal, dayOfWeek, dayOfMonth, weekOfMonth, monthOfYear, startDate, endDate, maxOccurrences '
    . 'FROM tblRecurrenceRules WHERE seriesID = ? AND siteID = ? ORDER BY ruleID ASC LIMIT 1'
);
if ($stmtRule !== false) {
    $stmtRule->bind_param('ii', $seriesID, $siteId);
    $stmtRule->execute();
    $currentRule = $stmtRule->get_result()->fetch_assoc();
    $stmtRule->close();
}

// 🕰️ The zone every reading on this page is read in. An event carries its own
//    (`tblEvents.timezone`, the only zone column an event has), so the series'
//    earliest date decides — not the organisation's zone, and never a zone
//    typed anywhere. `Site::zoneFor()` falls back to the organisation's when
//    the stored name is not one PHP knows.
$firstEvent = $events === [] ? null : reset($events);
$zoneName   = $firstEvent === null ? Site::timezone() : (string) $firstEvent['timezone'];
$zone       = Site::zoneFor($zoneName);
$nowReading = (new DateTimeImmutable('now', $zone))->format('Y-m-d H:i:s');

// 📅 The dates the CURRENT rule produces, as readings, for the fallback above.
//    Generated from the series' own earliest date, which is what the rule
//    describes.
$oldPatternStarts = [];
if ($currentRule !== null && $firstEvent !== null) {
    $oldWorked = EventRecurrence::datesFor(
        $currentRule,
        (string) $firstEvent['startDateTime'],
        $firstEvent['endDateTime'] === null ? null : (string) $firstEvent['endDateTime'],
        $zoneName,
        EventRecurrence::limitsFrom($SETTINGS, InstallationSettings::layerOrNull())
    );
    foreach ($oldWorked['dates'] as $date) {
        $oldPatternStarts[] = (string) $date['start'];
    }
}

// 📋 The facts `SeriesRepattern` works on. Built once, used by the preview and
//    by the change, so the two cannot see different worlds.
$facts = [];
foreach ($events as $id => $row) {
    $facts[] = [
        'eventID'      => $id,
        'start'        => (string) $row['startDateTime'],
        'end'          => $row['endDateTime'] === null ? null : (string) $row['endDateTime'],
        'patternStart' => $row['patternStart'] === null ? null : (string) $row['patternStart'],
        // 🕰️ BOTH recorded origins, and leaving this one out broke the whole
        //    page in the quietest possible way: `whyKept()` compares the finish
        //    too, so an absent `patternEnd` made EVERY date look hand-edited,
        //    the plan came out empty, and the preview simply offered nothing to
        //    do. No error, no warning — just a page that had stopped working.
        'patternEnd'   => $row['patternEnd'] === null ? null : (string) $row['patternEnd'],
        'attachedTo'   => $attachedTo[$id] ?? [],
    ];
}

// #############################################################################
// 🅳 D. The pattern being asked for
// #############################################################################

$flashMsg  = (string) ($_SESSION['flash_msg'] ?? '');
$flashType = (string) ($_SESSION['flash_type'] ?? 'info');
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$isPost      = $_SERVER['REQUEST_METHOD'] === 'POST';
$showPreview = false;
$plan        = null;
$problem     = '';
$warning     = '';
$newDates    = [];
$cappedNote  = '';

// 🧰 The browser joins a date and a time with a "T" and may leave the seconds
//    off; the generator wants a full reading. Same helper as the create
//    handler, for the same reason.
$toReading = static function (string $when): string {
    $when = trim(str_replace('T', ' ', $when));
    return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $when) === 1 ? $when . ':00' : $when;
};

// 🗓️ The form's starting point: the series' first FUTURE date, which is what
//    "change it from here on" means. Falls back to the last date of all when
//    every date has passed, so the boxes are never empty.
$anchorDefaultStart = '';
$anchorDefaultEnd   = '';
foreach ($facts as $fact) {
    if ($fact['start'] > $nowReading) {
        $anchorDefaultStart = $fact['start'];
        $anchorDefaultEnd   = (string) ($fact['end'] ?? '');
        break;
    }
}
if ($anchorDefaultStart === '' && $facts !== []) {
    $last               = $facts[count($facts) - 1];
    $anchorDefaultStart = $last['start'];
    $anchorDefaultEnd   = (string) ($last['end'] ?? '');
}

// The values the form shows. On a post they are what was typed, so a refused
// form comes back filled in rather than blank.
// 🧰 The frequencies this page can work with. `tblRecurrenceRules.frequency`
//    also allows 'custom', which the generator cannot produce dates from, so a
//    series carrying one must not have it prefilled: the form would come back
//    saying "Choose how often the series should repeat" about a value the page
//    itself had put there.
$repattEnFrequencies = ['weekly', 'fortnightly', 'monthly', 'quarterly', 'yearly'];
$formFrequency       = (string) ($_POST['repeatFrequency'] ?? (string) ($currentRule['frequency'] ?? 'weekly'));
if ($isPost === false && in_array($formFrequency, $repattEnFrequencies, true) === false) {
    $formFrequency = 'weekly';
}
$formDays       = [];
foreach ((array) ($_POST['repeatDays'] ?? []) as $day) {
    if (is_scalar($day) === true && preg_match('/^[0-6]$/', (string) $day) === 1) {
        $formDays[] = (string) (int) $day;
    }
}
if ($isPost === false && $currentRule !== null && trim((string) ($currentRule['dayOfWeek'] ?? '')) !== '') {
    foreach (explode(',', (string) $currentRule['dayOfWeek']) as $piece) {
        if (preg_match('/^[0-6]$/', trim($piece)) === 1) {
            $formDays[] = trim($piece);
        }
    }
}
$formMonthlyMode  = (string) ($_POST['repeatMonthlyMode'] ?? (($currentRule !== null && $currentRule['weekOfMonth'] !== null) ? 'weekday' : 'day'));
$formWeekOfMonth  = (string) ($_POST['repeatWeekOfMonth'] ?? (string) ($currentRule['weekOfMonth'] ?? ''));
$formCount        = (string) ($_POST['repeatCount'] ?? (string) ($currentRule['maxOccurrences'] ?? ''));
$formUntil        = trim((string) ($_POST['repeatUntil'] ?? ''));
$formStart        = (string) ($_POST['patternStart'] ?? $anchorDefaultStart);
$formEnd          = (string) ($_POST['patternEnd'] ?? $anchorDefaultEnd);

if ($isPost === true) {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
        $problem = 'That form had expired, so nothing was changed. Please try again.';
    } elseif ($events === []) {
        $problem = 'This series has no dates of its own, so there is no pattern to change.';
    } elseif (in_array($formFrequency, $repattEnFrequencies, true) === false) {
        $problem = 'Choose how often the series should repeat.';
    } else {
        $anchor = $toReading($formStart);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $anchor) !== 1) {
            $problem = 'The first date and time of the new pattern could not be read.';
        } elseif ($anchor <= $nowReading) {
            // 🕰️ Compared as TEXT against a reading of the same clock, which is
            //    this project's standing rule (#435) — turning either into a
            //    moment breaks on the two nights a year the clocks change.
            //
            //    REFUSED RATHER THAN ALLOWED AND IGNORED. A pattern anchored in
            //    the past cannot change anything (a date that has happened is
            //    always left alone), so letting it through would produce an
            //    empty preview with no explanation, which reads as a fault.
            $problem = 'The new pattern has to start in the future. Dates that have already happened are never changed.';
        } else {
            $anchorEnd = trim($formEnd) === '' ? null : $toReading($formEnd);
            if ($anchorEnd !== null && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $anchorEnd) !== 1) {
                $problem = 'The time the first date of the new pattern ends could not be read.';
            } else {
                // 🧢 The ceilings are the installation's choice and this
                //    organisation's within it, and the INSTALLATION'S value
                //    takes precedence — the same two-layer read the create
                //    handler makes. An organisation may hold itself to a lower
                //    ceiling and can never raise its own above the
                //    installation's (the owner, 29 October 2026).
                $askedRule = [
                    'frequency'      => $formFrequency,
                    'intervalVal'    => 1,
                    'dayOfWeek'      => $formDays === [] ? null : implode(',', $formDays),
                    'dayOfMonth'     => $formMonthlyMode === 'weekday' ? null : (int) substr($anchor, 8, 2),
                    'weekOfMonth'    => $formMonthlyMode === 'weekday' && preg_match('/^-?[1-5]$/', $formWeekOfMonth) === 1
                        ? (int) $formWeekOfMonth
                        : null,
                    'monthOfYear'    => $formFrequency === 'yearly' ? (int) substr($anchor, 5, 2) : null,
                    'startDate'      => substr($anchor, 0, 10),
                    'endDate'        => preg_match('/^\d{4}-\d{2}-\d{2}$/', $formUntil) === 1 ? $formUntil : null,
                    'maxOccurrences' => preg_match('/^\d+$/', $formCount) === 1 && (int) $formCount > 0 ? (int) $formCount : null,
                ];

                $worked = EventRecurrence::datesFor(
                    $askedRule,
                    $anchor,
                    $anchorEnd,
                    $zoneName,
                    EventRecurrence::limitsFrom($SETTINGS, InstallationSettings::layerOrNull())
                );

                $newDates = $worked['dates'];
                if ($newDates === []) {
                    $problem = $worked['why'] !== ''
                        ? (string) $worked['why']
                        : 'That pattern produced no dates, so there was nothing to change.';
                } else {
                    if ($worked['capped'] === true) {
                        $cappedNote = (string) $worked['why'];
                    }
                    $plan        = SeriesRepattern::plan($facts, $newDates, $nowReading, $oldPatternStarts);
                    $showPreview = true;
                }
            }
        }
    }
}

// 🔐 A short fingerprint of the plan, so that a confirmation can be checked
//    against the preview it was given. Only the three lists that CHANGE
//    anything go in: what is left alone is reported, not done, and a date
//    becoming attached between the two steps must not block a change that does
//    not touch it.
//
//    WHAT IT IS AND IS NOT FOR. It guards against the series moving under
//    somebody between the preview and the confirmation. It is NOT a secret and
//    is not meant to be: an administrator who knows how it is built could
//    compute one and confirm without ever looking at a preview, and that is no
//    escalation, because they may make the change anyway. The guarantee is
//    against a MISTAKE, not against intent.
$planDigest = static function (?array $plan): string {
    if ($plan === null) {
        return '';
    }
    $canonical = [];
    foreach ($plan['move'] as $m) {
        $canonical[] = 'M' . $m['eventID'] . '>' . $m['to'] . '|' . (string) $m['toEnd'];
    }
    foreach ($plan['add'] as $a) {
        $canonical[] = 'A' . $a['start'] . '|' . (string) $a['end'];
    }
    foreach ($plan['remove'] as $r) {
        $canonical[] = 'R' . $r['eventID'];
    }
    sort($canonical);
    return hash('sha256', implode(';', $canonical));
};

// #############################################################################
// 🅴 E. Applying it
// #############################################################################

$confirmed = $isPost === true && (string) ($_POST['confirm'] ?? '') === '1';

if ($confirmed === true && $plan !== null && $problem === '') {
    $shown = (string) ($_POST['planDigest'] ?? '');
    if ($shown === '' || hash_equals($planDigest($plan), $shown) === false) {
        // 🛡️ Somebody changed this series between the preview and the
        //    confirmation. Refusing and showing the new preview is the only
        //    honest answer: the person agreed to a specific list of changes.
        $warning = 'This series changed while you were looking at it, so nothing was altered. '
            . 'Here is what the change would do now.';
    } elseif (SeriesRepattern::planIsEmpty($plan) === true) {
        $problem = 'That pattern matches the dates already in the diary, so there was nothing to change.';
    } else {
        // 📋 The row every new date is copied from, and the ORDER MATTERS.
        //
        //    First choice is a date the pattern is about to re-time, because
        //    that is a date the pattern still governs — not one somebody has
        //    taken out of the series by hand. Second is the earliest future
        //    date `SeriesRepattern` raised no objection to, for the case where
        //    the change only ADDS dates and nothing is being moved.
        //
        //    ONLY THEN the last date of all, and that fallback is a compromise
        //    worth naming: it may be a past date, or one that is left alone
        //    because of what is attached to it. It is reached only when the
        //    series has no usable future date at all, and the columns that
        //    would make such a template misleading — the cancellation, the
        //    moderation trail, the submitter — are cleared on every copy
        //    anyway, so what it really contributes is the name, venue and
        //    category. Earlier wording called this "the earliest FUTURE date
        //    the pattern still governs" full stop, which was not true of the
        //    fallback.
        $templateId = 0;
        foreach ($plan['move'] as $m) {
            $templateId = (int) $m['eventID'];
            break;
        }
        if ($templateId === 0) {
            $keptIds = [];
            foreach ($plan['keep'] as $k) {
                $keptIds[(int) $k['eventID']] = true;
            }
            foreach ($facts as $fact) {
                if ($fact['start'] > $nowReading && isset($keptIds[(int) $fact['eventID']]) === false) {
                    $templateId = (int) $fact['eventID'];
                    break;
                }
            }
        }
        if ($templateId === 0 && $facts !== []) {
            $templateId = (int) $facts[count($facts) - 1]['eventID'];
        }

        $moved   = 0;
        $added   = 0;
        $removed = 0;
        // Set just before the one throw that is NOT a fault, so the catch can
        // say what really happened instead of "something went wrong".
        $recordAppeared = false;
        // The second such throw: the series' live dates changed between the
        // plan being worked out and this transaction taking the series' lock.
        $seriesMoved = false;

        // 🔒 ALL OR NOTHING. A half-applied pattern change is the worst
        //    outcome: the diary then holds some dates on the old pattern and
        //    some on the new, and the person has no way of knowing which.
        $mysqli->begin_transaction();

        try {
            // 0️⃣ THE SERIES' OWN ROW, LOCKED, AS THE FIRST STATEMENT — then the
            //    live dates read again and compared with the ones the plan was
            //    worked out from (#691).
            //
            //    WHY. Everything above this line — the plan, the preview, the
            //    digest — was read WITHOUT a lock. Since #691 a hidden date can
            //    be brought back (`Portal\Core\HiddenSeriesDates::restore()`), and
            //    a restore committed between the plan and this transaction
            //    would leave two live dates on one day: the plan adds a date
            //    onto a day the restore has just filled. Both writers now take
            //    this same row FIRST, so they queue: whichever comes second sees
            //    what the first committed. The re-read is a LOCKING read for
            //    the same reason — a plain one reads this transaction's
            //    snapshot, which is exactly what must not be trusted here.
            //
            //    Refused rather than re-planned: the person confirmed a
            //    specific list of changes, and a different list is a different
            //    decision. The fresh preview is one press away.
            $seriesLock = $mysqli->prepare(
                'SELECT `seriesID` FROM `tblEventSeries` WHERE `seriesID` = ? AND `siteID` = ? FOR UPDATE'
            );
            if ($seriesLock === false) {
                throw new RuntimeException('the series could not be locked');
            }
            $seriesLock->bind_param('ii', $seriesID, $siteId);
            $seriesLock->execute();
            $seriesStill = $seriesLock->get_result()->fetch_row();
            $seriesLock->close();

            $liveNow   = [];
            $liveAgain = $mysqli->prepare(
                'SELECT `eventID`, `startDateTime`, `endDateTime`, `patternStart`, `patternEnd` FROM `tblEvents` '
                . 'WHERE `seriesID` = ? AND `siteID` = ? AND `isDeleted` = 0 AND `externalFeedID` IS NULL '
                . 'ORDER BY `eventID` ASC FOR UPDATE'
            );
            if ($liveAgain === false) {
                throw new RuntimeException('the series\' dates could not be read again');
            }
            $liveAgain->bind_param('ii', $seriesID, $siteId);
            $liveAgain->execute();
            $againRows = $liveAgain->get_result();
            while ($againRow = $againRows->fetch_row()) {
                $liveNow[] = implode('|', array_map(static fn ($v): string => (string) $v, $againRow));
            }
            $liveAgain->close();

            $livePlanned = [];
            foreach ($events as $plannedId => $plannedRow) {
                $livePlanned[] = implode('|', [
                    (string) $plannedId,
                    (string) $plannedRow['startDateTime'],
                    (string) $plannedRow['endDateTime'],
                    (string) $plannedRow['patternStart'],
                    (string) $plannedRow['patternEnd'],
                ]);
            }
            sort($livePlanned);
            sort($liveNow);
            if ($seriesStill === null || $liveNow !== $livePlanned) {
                $seriesMoved = true;
                throw new RuntimeException('the series changed between the plan and the change');
            }

            // 1️⃣ Dates that stay, re-timed in place. ONLY the start, the end
            //    and the recorded origin — never the name, venue or anything
            //    else a person may have changed by hand.
            if ($plan['move'] !== []) {
                // 🕰️ BOTH recorded origins move with the date. Writing
                //    `patternStart` alone would leave `patternEnd` describing
                //    the OLD finish, so every date this page re-timed would
                //    read as hand-edited at the next pattern change and be
                //    left alone for ever after — the opposite fault to the one
                //    comparing the end was added to fix.
                $up = $mysqli->prepare(
                    'UPDATE tblEvents SET startDateTime = ?, endDateTime = ?, patternStart = ?, patternEnd = ?, '
                    . 'updatedByID = ? '
                    . 'WHERE eventID = ? AND siteID = ? AND seriesID = ? AND isDeleted = 0 AND externalFeedID IS NULL'
                );
                if ($up === false) {
                    throw new RuntimeException('the statement that re-times a date could not be prepared');
                }
                foreach ($plan['move'] as $m) {
                    $to    = (string) $m['to'];
                    $toEnd = $m['toEnd'];
                    $id    = (int) $m['eventID'];
                    $up->bind_param('ssssiiii', $to, $toEnd, $to, $toEnd, $userId, $id, $siteId, $seriesID);
                    $up->execute();
                    if ($up->affected_rows > 0) {
                        $moved++;
                    }
                }
                $up->close();
            }

            // 2️⃣ Dates the new pattern adds, copied from the template row.
            //
            //    THE COLUMN LIST COMES FROM THE DATABASE, not from a list typed
            //    here, so a column added to `tblEvents` is carried across
            //    without this file changing.
            //
            //    BE HONEST ABOUT WHAT THAT DOES AND DOES NOT BUY, because an
            //    earlier version of this comment claimed it prevented a new
            //    date differing "from its siblings in a way nobody would think
            //    to look for", and the independent check measured that it did
            //    not. The CREATE handler copies only the columns its FORM
            //    writes, so a column it never sets is empty on every date it
            //    made — while this block, copying everything, would carry it.
            //    That is how the registration window came to be inherited.
            //
            //    So the real guard is `$freshDate` below: a HAND-MAINTAINED
            //    list of the columns that describe one date's own history. The
            //    cost is stated plainly — a NEW column of that kind will be
            //    copied until somebody adds it here, and no automatic check
            //    can tell "belongs to the series" from "belongs to this one
            //    date". Section S of the HTTP test pins the ones that matter.
            if ($plan['add'] !== [] && $templateId > 0) {
                $tmplStmt = $mysqli->prepare(
                    'SELECT * FROM tblEvents WHERE eventID = ? AND siteID = ? AND externalFeedID IS NULL LIMIT 1'
                );
                if ($tmplStmt === false) {
                    throw new RuntimeException('the date new copies are taken from could not be read');
                }
                $tmplStmt->bind_param('ii', $templateId, $siteId);
                $tmplStmt->execute();
                $template = $tmplStmt->get_result()->fetch_assoc();
                $tmplStmt->close();

                if ($template === null) {
                    throw new RuntimeException('the date new copies are taken from has gone');
                }

                // Columns the copy must NOT take from the template.
                //   eventID   — the database allocates it
                //   eventSlug — unique per organisation (uq_event_site_slug),
                //               worked out per date below
                //   createdAt / updatedAt — left to their defaults
                $skip = [
                    'eventID'   => true,
                    'eventSlug' => true,
                    'createdAt' => true,
                    'updatedAt' => true,
                ];

                // 🧹 COLUMNS THAT DESCRIBE ONE DATE'S OWN HISTORY, which a NEW
                //    date cannot have. Every one of these is set to NULL on a
                //    copy rather than taken from the template, and leaving them
                //    out was a real fault with two separate kinds of harm,
                //    found by the independent check on 31 October 2026:
                //
                //    WRONG DATA. The template is one real date of the series. If
                //    an administrator had cancelled it by hand — the ordinary
                //    reason to cancel one week — then extending the run created
                //    new dates that were ALREADY CANCELLED, carrying that date's
                //    cancellation reason and the name of whoever cancelled it,
                //    while the preview promised only "the same name, venue,
                //    category and settings".
                //
                //    PRIVACY. `submitterName` and `submitterEmail` are free text
                //    for a member of the public who submitted an event. Copying
                //    them writes that person's name and e-mail onto rows they
                //    had nothing to do with — and `GdprEraser::catalogue()`
                //    clears neither column on `tblEvents`, so those copies could
                //    not be erased on request. Checked rather than assumed.
                //
                //    The STATUS is handled separately below, because it is the
                //    one of these that a new date does legitimately inherit.
                $freshDate = [
                    'cancelReason'      => true,
                    'statusChangedByID' => true,
                    'statusChangedAt'   => true,
                    'submissionStatus'  => true,
                    'submittedByID'     => true,
                    'submitterName'     => true,
                    'submitterEmail'    => true,
                    'submittedAt'       => true,
                    'moderatedByID'     => true,
                    'moderatedAt'       => true,
                    'moderationNote'    => true,
                    // 🎟️ AND THE REGISTRATION WINDOW, which is about ONE date
                    //    and not about the series. Leaving these out was a real
                    //    fault, measured on 31 October 2026: the template's
                    //    `registrationClosesAt` is a moment just before the
                    //    template's own date, so a date added four weeks later
                    //    inherited a window that had ALREADY CLOSED.
                    //    `calendar/event-register.php` and
                    //    `event-register-save.php` both treat that column as a
                    //    hard gate, so a member opening the new date was simply
                    //    told registration had closed, with nothing to explain
                    //    it.
                    //
                    //    `registrationEnabled` goes too, and that is the same
                    //    rule rather than caution: the CREATE handler writes
                    //    none of these columns, so every date it made has them
                    //    empty. A copy that carried them would differ from its
                    //    own siblings — which is the exact harm the comment
                    //    above claims this block's design prevents.
                    //    `registrationEnabled` is NOT in this list, and that is
                    //    not an omission: it is `TINYINT(1) NOT NULL DEFAULT 0`,
                    //    so binding NULL to it makes the whole INSERT fail under
                    //    the strict connection `DbServer::connect()` opens. It is
                    //    given its default explicitly further down instead.
                    //    Measured: putting it here failed every add, and the
                    //    independent check's note that "every one of these
                    //    columns is nullable" was true of the first eleven only.
                    'registrationOpensAt'       => true,
                    'registrationClosesAt'      => true,
                    'capacityCount'             => true,
                    'registrationRetentionDays' => true,
                    // 🌐 AND THE PUBLIC WEBSITE'S DATES (#493 P1, D-078). The
                    //    window was chosen for the template's own date by a
                    //    person who may publish; a date the pattern adds is not
                    //    one anybody looked at. The SWITCH itself is not in this
                    //    list for the same reason as `registrationEnabled`
                    //    (NOT NULL, so NULL would fail the INSERT): it is set to
                    //    0 explicitly below.
                    'websiteFrom'               => true,
                    'websiteUntil'              => true,
                ];

                $columns = [];
                foreach (array_keys($template) as $column) {
                    if (isset($skip[$column]) === true) {
                        continue;
                    }
                    $columns[] = $column;
                }
                // The slug is set per date, so it is added back deliberately.
                $columns[] = 'eventSlug';

                // 🛡️ AN IMPORTED EVENT IS NEVER COPIED. There is no WHERE on an
                //    INSERT to carry that guard, so it is carried by the read
                //    the values come from: the template was fetched a few lines
                //    above with `externalFeedID IS NULL`, and section A refused
                //    the whole series if it held even one imported date. The
                //    copy therefore cannot be of somebody else's event, which
                //    `FeedImporter` is the only thing allowed to create (#514).
                //    Stated here because the automatic check cannot see a guard
                //    that far away, and because the next person editing this
                //    block would otherwise have to know it.
                $insertSql = 'INSERT INTO tblEvents (`' . implode('`, `', $columns) . '`) VALUES ('
                    . implode(', ', array_fill(0, count($columns), '?')) . ')';
                $ins = $mysqli->prepare($insertSql);
                if ($ins === false) {
                    throw new RuntimeException('the statement that adds a date could not be prepared');
                }

                // 🔑 A FREE ADDRESS, ASKED OF THE DATABASE rather than guessed.
                //
                //    `uq_event_site_slug (siteID, eventSlug)` applies to
                //    SOFT-DELETED rows as well, and a date this page removes
                //    keeps its address for ever. So the first version — base,
                //    date, and the date's position in the add list — collided
                //    the moment a series was shortened and then extended again
                //    over the same day: the whole change was rolled back, the
                //    administrator was told "Something went wrong … please try
                //    again", and retrying could never succeed. It was also
                //    INTERMITTENT, because the position in the list changes from
                //    one run to the next, so the same day got a different
                //    suffix and sometimes happened to miss. Measured by the
                //    independent check on 31 October 2026.
                //
                // 🔤 ONE PLACE NOW (#883). This page kept its own copy of the
                //    question — the TEMPLATE'S ADDRESS plus the date, then
                //    `-2` … `-50`, then a random ending — and `Events::freeSlug()`
                //    was lifted from it for the calendar file import (#877).
                //    The page now asks `Events::saveUnderFreeSlug()`, with the
                //    stem the Manage Events form gives (`Events::slugStem()`,
                //    the series' name and the new date's own date). Two things
                //    change:
                //
                //      * THE SHAPE. A date added here was
                //        `sunday-service-2026-06-07-2026-07-12` (the template's
                //        whole address, its own date in it, then the new date);
                //        it is now `sunday-service-2026-07-12`, as if the form
                //        had made it. No installation exists yet, so no link
                //        anybody has kept changes.
                //      * A RACE IS ANSWERED. Another writer taking the address
                //        between the question and the INSERT used to refuse
                //        the INSERT and roll the whole change back. It is now
                //        asked again. INSIDE THIS TRANSACTION that needs the
                //        list of addresses already refused (`freeSlug()`'s
                //        `$refused`): the question reads this transaction's
                //        snapshot, which cannot see the other writer's row, so
                //        without the list every retry sent the same address.
                //
                // 🛡️ NO `externalFeedID IS NULL` IN THAT QUESTION, AND ADDING ONE
                //    WOULD BE A BUG. It asks only "is this address already
                //    taken", and the unique key does not care who owns the
                //    row: an IMPORTED event's address occupies the key exactly
                //    as a portal-owned one does, and so does a soft-deleted
                //    row. It returns no event detail, so it needs neither
                //    `EventVisibility::where()` nor the imported-event refusal.
                //
                //    Bounded on purpose: fifty numbered tries, then a random
                //    ending, then `Events::SLUG_SAVE_TRIES` saves. If every one
                //    is refused the throw reaches the `catch` below, which
                //    rolls the whole change back — a loop that could run for
                //    ever against a unique key is worse than a refusal.
                $slugName = (string) $template['eventName'];

                foreach ($plan['add'] as $date) {
                    $values = [];
                    $slugAt = -1;
                    foreach ($columns as $column) {
                        if ($column === 'startDateTime') {
                            $values[] = (string) $date['start'];
                        } elseif ($column === 'endDateTime') {
                            $values[] = $date['end'];
                        } elseif ($column === 'patternStart') {
                            // 📌 Recorded for every date this page creates, so
                            //    the NEXT pattern change can tell it apart from
                            //    one somebody moves by hand afterwards.
                            $values[] = (string) $date['start'];
                        } elseif ($column === 'patternEnd') {
                            $values[] = $date['end'];
                        } elseif ($column === 'isDeleted') {
                            $values[] = '0';
                        } elseif ($column === 'deletedAt') {
                            $values[] = null;
                        } elseif ($column === 'status') {
                            // 🚦 A DATE THIS CHANGE ADDS IS ALWAYS A DRAFT
                            //    (the owner's decision, #692, 31 October 2026).
                            //    It is NOT inherited from the template, and the
                            //    template's status is deliberately not read
                            //    here at all.
                            //
                            //    WHY. An added date is a copy of the series as
                            //    it stands, and nothing more: it has no
                            //    registration window (the template's
                            //    `registrationClosesAt` is a moment just before
                            //    the TEMPLATE's own date, so a date added weeks
                            //    later would arrive with registration already
                            //    closed), and nothing beyond the series'
                            //    description. Publishing that puts a
                            //    half-finished entry in front of members the
                            //    moment somebody presses the button.
                            //
                            //    A draft is one click from being right. A
                            //    wrongly published service is already in front
                            //    of members by the time anybody notices. So the
                            //    recoverable mistake is the one to make.
                            //
                            //    (D-033 also gave as a reason that a hidden date
                            //    could not be brought back. Since #691 one can —
                            //    as a draft, from the bulk edit page — so that
                            //    half of the reason has lapsed. The decision
                            //    stands on the half above, and nothing here
                            //    changed because of it.)
                            //
                            //    WHAT WAS HERE BEFORE, so nobody puts it back
                            //    believing it was an oversight: the added date
                            //    inherited the template's status, with
                            //    `cancelled` and `postponed` turned into
                            //    `published` because a date that did not exist
                            //    until now cannot already be "not happening"
                            //    and its reason columns are cleared just above.
                            //    That reasoning still holds — it is simply
                            //    answered by always drafting, which cannot be
                            //    `cancelled` or `postponed` either.
                            //
                            //    PUBLISHING THEM is already possible in one
                            //    go, and on the very page this handler
                            //    redirects to: `series-edit.php` lists every
                            //    non-deleted date of the series, drafts
                            //    included, with a checkbox each and a bulk
                            //    `Status` control that writes `status = ?`
                            //    across every date ticked. I first wrote here
                            //    that it was one at a time and raised #696 for
                            //    a bulk control; the independent check found
                            //    the facility already existed, and #696 was
                            //    closed. Do not raise it again without
                            //    reading that page.
                            $values[] = 'draft';
                        } elseif ($column === 'registrationEnabled') {
                            // Its DEFAULT, not NULL: the column refuses NULL.
                            // Off, which is what every date the create handler
                            // makes has, so a new date matches its siblings.
                            $values[] = '0';
                        } elseif ($column === 'showOnWebsite') {
                            // 🌐 NEVER INHERITED (#493 P1; D-057, the D-033
                            //    precedent for drafts). A date this change
                            //    ADDS is one nobody has looked at, so it is not
                            //    put in front of the whole internet because the
                            //    template happened to be: "nothing is public
                            //    until somebody ticks it, one item at a time".
                            //    It also arrives as a DRAFT (just above), which
                            //    the website would not show anyway — this keeps
                            //    it off once somebody publishes it, until a
                            //    person who may publish ticks it.
                            $values[] = '0';
                        } elseif ($column === 'timezone') {
                            // 🕰️ THE ZONE THE READING WAS MADE IN, not the
                            //    template's. Every reading in the plan was
                            //    generated in `$zoneName` — the series' earliest
                            //    date's zone — so taking the zone from the
                            //    template row would store a reading made in one
                            //    zone against another. That can only differ on a
                            //    series whose dates hold different zones, which
                            //    is not supposed to happen but is not refused
                            //    anywhere either.
                            $values[] = $zoneName;
                        } elseif ($column === 'createdByID' || $column === 'updatedByID') {
                            // Whoever is making this change, not whoever made
                            // the date it was copied from. The template's name
                            // against a row they never saw is simply untrue.
                            $values[] = $userId;
                        } elseif (isset($freshDate[$column]) === true) {
                            $values[] = null;
                        } elseif ($column === 'eventSlug') {
                            // Filled in by `Events::saveUnderFreeSlug()` below;
                            // its position is remembered so each try can set it.
                            $slugAt   = count($values);
                            $values[] = '';
                        } else {
                            $values[] = $template[$column];
                        }
                    }
                    Events::saveUnderFreeSlug(
                        $mysqli,
                        $siteId,
                        Events::slugStem($slugName, (string) $date['start']),
                        static function (string $candidate) use ($ins, $values, $slugAt): void {
                            $values[$slugAt] = $candidate;
                            $ins->bind_param(str_repeat('s', count($values)), ...$values);
                            if ($ins->execute() !== true) {
                                throw new \mysqli_sql_exception((string) $ins->error, (int) $ins->errno);
                            }
                        }
                    );
                    if ($ins->affected_rows > 0) {
                        $added++;
                    }
                }
                $ins->close();
            }

            // 3️⃣ Dates the new pattern drops. SOFT-DELETED, never removed:
            //    `SeriesRepattern` has already refused to list any date that
            //    carries records, and soft-deleting means even a mistake here
            //    cascades nothing and can be undone.
            //
            //    🔁 ASKED AGAIN, INSIDE THE TRANSACTION. The list of what is
            //    attached was read before the preview was even drawn, and a
            //    person at a door marking attendance does not wait for an
            //    administrator to finish reading a page. Between those two
            //    moments a date with nothing against it can acquire a register,
            //    and hiding it then leaves those rows pointing at a date nobody
            //    can see — which, for the two tables with no foreign key, means
            //    they are not even cleaned up.
            //
            //    This is cheap (one query per table over at most a handful of
            //    event numbers) and it is the only one of the three steps where
            //    the window matters: re-timing and adding take nothing away.
            //    It does NOT make the page safe against two administrators —
            //    nothing here holds a lock on the series, and the harness runs
            //    one worker so no test could prove it either way. It closes the
            //    window that a single administrator plus an ordinary member can
            //    open, which is the one that happens.
            if ($plan['remove'] !== []) {
                $removeIds = [];
                foreach ($plan['remove'] as $r) {
                    $removeIds[] = (int) $r['eventID'];
                }
                $marks   = implode(',', array_fill(0, count($removeIds), '?'));
                $idTypes = str_repeat('i', count($removeIds));
                foreach (SeriesRepattern::ATTACHMENTS as $table => $meaning) {
                    $again = $mysqli->prepare(
                        'SELECT 1 FROM `' . $table . '` WHERE `eventID` IN (' . $marks . ') LIMIT 1'
                    );
                    if ($again === false) {
                        throw new RuntimeException('the records attached to the dates being removed could not be re-checked');
                    }
                    $again->bind_param($idTypes, ...$removeIds);
                    $again->execute();
                    $nowAttached = $again->get_result()->fetch_row();
                    $again->close();
                    if ($nowAttached !== null) {
                        // Refused, not worked around. Saying which table would
                        // be a hint about somebody's records; the person is told
                        // to look again, and the fresh preview will show the
                        // date in "left exactly as they are" with its reason.
                        $recordAppeared = true;
                        throw new RuntimeException('a date being removed acquired a record while this change was being confirmed');
                    }
                }
            }

            if ($plan['remove'] !== []) {
                $del = $mysqli->prepare(
                    'UPDATE tblEvents SET isDeleted = 1, deletedAt = NOW(), updatedByID = ? '
                    . 'WHERE eventID = ? AND siteID = ? AND seriesID = ? AND isDeleted = 0 AND externalFeedID IS NULL'
                );
                if ($del === false) {
                    throw new RuntimeException('the statement that removes a date could not be prepared');
                }
                foreach ($plan['remove'] as $r) {
                    $id = (int) $r['eventID'];
                    $del->bind_param('iiii', $userId, $id, $siteId, $seriesID);
                    $del->execute();
                    if ($del->affected_rows > 0) {
                        $removed++;
                    }
                }
                $del->close();
            }

            // 4️⃣ THE SERIES' RULE ROW. `calendar/export.php` collapses a series
            //    with a rule into ONE repeating entry and skips every other
            //    event of that series, so a rule that over-promises sends
            //    subscribers dates the diary does not contain AND hides the
            //    ones it does. The old rule is therefore always removed, and a
            //    new one written only when the series now holds exactly the
            //    dates the new pattern makes.
            // 📡 `status` is read too, and only for the MESSAGE. The rule row
            //    itself is decided on the dates alone, which is right: the
            //    pattern either describes this series' dates or it does not,
            //    and that does not change when somebody publishes one.
            //
            //    But the calendar feed collapses a series into one repeating
            //    entry only when the PUBLISHED dates match the rule
            //    (`calendar/export.php` reads the run with
            //    `AND e.status = 'published'`). So while any date is a draft —
            //    which, since #692, every date this page ADDS is — a
            //    subscriber still sees one entry per date, and the old
            //    sentence "Anybody subscribing to this calendar now sees one
            //    repeating entry" was FALSE for exactly the change that had
            //    just been made. Found by the independent check on
            //    31 October 2026.
            $liveAfter   = [];
            $draftsAfter = 0;
            $checkStmt = $mysqli->prepare(
                'SELECT startDateTime, status FROM tblEvents '
                . 'WHERE seriesID = ? AND siteID = ? AND isDeleted = 0 AND externalFeedID IS NULL'
            );
            if ($checkStmt === false) {
                throw new RuntimeException('the series\' dates could not be read back');
            }
            $checkStmt->bind_param('ii', $seriesID, $siteId);
            $checkStmt->execute();
            $after = $checkStmt->get_result();
            while ($row = $after->fetch_row()) {
                $liveAfter[] = (string) $row[0];
                if ((string) $row[1] !== 'published') {
                    $draftsAfter++;
                }
            }
            $checkStmt->close();

            $newStarts = [];
            foreach ($newDates as $date) {
                $newStarts[] = (string) $date['start'];
            }

            $dropRule = $mysqli->prepare('DELETE FROM tblRecurrenceRules WHERE seriesID = ? AND siteID = ?');
            if ($dropRule === false) {
                throw new RuntimeException('the old repeat rule could not be removed');
            }
            $dropRule->bind_param('ii', $seriesID, $siteId);
            $dropRule->execute();
            $dropRule->close();

            $rulePublished = false;
            if (SeriesRepattern::ruleCanStillBeTrue($liveAfter, $newStarts) === true) {
                // 📅 `endDate` is deliberately never written and
                //    `maxOccurrences` is the number of dates really in the
                //    diary — the same two decisions the create handler
                //    documents at length. `export.php` prefers UNTIL over
                //    COUNT, and an UNTIL taken from the form would outlive a
                //    series our own ceiling had stopped.
                $ruleFrequency   = $formFrequency;
                $ruleInterval    = 1;
                $byWeekday       = $formMonthlyMode === 'weekday' && preg_match('/^-?[1-5]$/', $formWeekOfMonth) === 1;
                $isWeekly        = in_array($formFrequency, ['weekly', 'fortnightly'], true);
                $ruleDayOfWeek   = null;
                if ($isWeekly === true) {
                    $ruleDayOfWeek = $formDays === [] ? null : implode(',', $formDays);
                } elseif ($byWeekday === true) {
                    $ruleDayOfWeek = $formDays === [] ? null : $formDays[0];
                }
                $ruleDayOfMonth  = ($isWeekly === false && $byWeekday === false) ? (int) substr((string) $newStarts[0], 8, 2) : null;
                $ruleWeekOfMonth = $byWeekday === true ? (int) $formWeekOfMonth : null;
                $ruleMonthOfYear = $formFrequency === 'yearly' ? (int) substr((string) $newStarts[0], 5, 2) : null;
                $ruleStartDate   = substr((string) $newStarts[0], 0, 10);
                $ruleEndDate     = null;
                $ruleCount       = count($newStarts);

                $ruleStmt = $mysqli->prepare(
                    'INSERT INTO tblRecurrenceRules '
                    . '(siteID, seriesID, frequency, intervalVal, dayOfWeek, dayOfMonth, weekOfMonth, monthOfYear, startDate, endDate, maxOccurrences) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                if ($ruleStmt === false) {
                    throw new RuntimeException('the new repeat rule could not be prepared');
                }
                $ruleStmt->bind_param(
                    'iisisiiissi',
                    $siteId,
                    $seriesID,
                    $ruleFrequency,
                    $ruleInterval,
                    $ruleDayOfWeek,
                    $ruleDayOfMonth,
                    $ruleWeekOfMonth,
                    $ruleMonthOfYear,
                    $ruleStartDate,
                    $ruleEndDate,
                    $ruleCount
                );
                $ruleStmt->execute();
                $rulePublished = $ruleStmt->affected_rows > 0;
                $ruleStmt->close();
            }

            $mysqli->commit();

            // 📝 Numbers only, and no event name: `Logger::activity()` fills
            //    `userID` from the session itself (#626), and a series' name is
            //    the one thing in here that could carry somebody's details.
            Logger::activity(
                'SeriesRepatterned',
                'Series #' . $seriesID . ' re-patterned: ' . $moved . ' re-timed, ' . $added . ' added, '
                . $removed . ' removed, ' . count($plan['keep']) . ' left alone, rule '
                . ($rulePublished === true ? 'published' : 'withdrawn')
            );

            $parts = [];
            if ($moved > 0) {
                $parts[] = $moved . ' date(s) re-timed';
            }
            if ($added > 0) {
                // 📝 "as drafts" is in the sentence on purpose. Without it the
                //    message reads as though the dates are now in the diary,
                //    and the first thing anybody does is go looking for them
                //    on the public calendar, where they are not (#692).
                $parts[] = $added . ' date(s) added as drafts';
            }
            if ($removed > 0) {
                $parts[] = $removed . ' date(s) removed';
            }
            $kept = count($plan['keep']);

            // 🧾 EVERY STATEMENT CAN AFFECT NO ROWS and the change still
            //    commit — another administrator may have hidden those dates
            //    between the confirmation and the writes. The counts come from
            //    `affected_rows` rather than from the plan, on purpose, so the
            //    message cannot claim work that did not happen. Without this
            //    branch it read "Pattern changed: ." — which is worse than
            //    either truth.
            $_SESSION['flash_msg'] = ($parts === []
                ? 'Nothing needed changing — the diary already matched that pattern, or somebody else had'
                  . ' just made the same change.'
                : 'Pattern changed: ' . implode(', ', $parts) . '.')
                . ($kept > 0 ? ' ' . $kept . ' date(s) were left exactly as they were.' : '')
                . ($rulePublished === true
                    ? ($draftsAfter === 0
                        ? ' Anybody subscribing to this calendar now sees one repeating entry.'
                        // 📡 THREE ANSWERS, NOT TWO. The rule row is written, so this series CAN be
                        //    collapsed — but the feed only collapses the PUBLISHED dates, so while
                        //    any date is still a draft a subscriber sees each date on its own. Saying
                        //    "now sees one repeating entry" here was untrue of every change that
                        //    added a date, which since #692 is every added date there is.
                        : ' Anybody subscribing to this calendar still sees each date on its own, and will see'
                          . ' one repeating entry once ' . ($draftsAfter === 1 ? 'the draft date is' : 'all '
                          . $draftsAfter . ' draft dates are') . ' published.')
                    : ' Anybody subscribing to this calendar now sees each date on its own, because no single'
                      . ' repeating rule can describe this series truthfully.');
            $_SESSION['flash_type'] = 'success';
            header('Location: ' . Site::url('calendar/manage/series-edit') . '?seriesID=' . $seriesID);
            exit();
        } catch (\Throwable $e) {
            $mysqli->rollback();
            // 🛡️ NEVER THE FAULT'S OWN WORDS. A database error can quote the
            //    value it choked on, which here can be an event's name.
            // A series that moved under the change is the page doing its job,
            // like a record appearing, so neither is logged as a fault.
            if ($seriesMoved === false) {
                Logger::errorPlatform(
                    'Calendar',
                    $recordAppeared === true ? 'Warning' : 'Error',
                    $recordAppeared === true ? 'REPATTERN_RECORD_APPEARED' : 'REPATTERN_FAILED',
                    $recordAppeared === true
                        ? 'a series pattern change was stopped because a date it was about to remove had gained a '
                          . 'record since the preview was drawn, so the diary is exactly as it was before'
                        : 'a series pattern change was rolled back, so the diary is exactly as it was before',
                    'seriesID=' . $seriesID
                );
            }
            // Two different things, and telling them apart matters: one is a
            // fault and one is the page doing its job. Saying "something went
            // wrong" about the second would send somebody looking for a bug.
            $problem = $seriesMoved === true
                ? 'Nothing was changed. While you were confirming, this series\' dates changed — somebody may have '
                  . 'brought a hidden date back, or changed the series another way. Press "Show me what would change" '
                  . 'again to see what the change would do now.'
                : ($recordAppeared === true
                ? 'Nothing was changed. While you were confirming, somebody added a record — attendance, a '
                  . 'booking, a registration — to one of the dates this change would have removed. Press "Show '
                  . 'me what would change" again and that date will be listed as one to leave alone.'
                : 'Something went wrong part way through, so nothing was changed at all — the diary is '
                  . 'exactly as it was. Please try again.');
        }
    }
}

// #############################################################################
// 🅵 F. The page
// #############################################################################

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

$dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

/**
 * 🗓️ One stored reading, shown the way a person reads it.
 *
 * 🕐 IT SHOWED AN HOUR LATE ON THE CLOCK-CHANGE MORNING (#777 round 7). This
 *    was `DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $reading)` with
 *    NO ZONE, which resolves the reading in PHP's default zone and so moves
 *    it when the hour it names does not exist there. Measured in
 *    Europe/London: a stored `2026-03-29 01:30:00` was shown as
 *    `Sun 29 Mar 2026, 02:30`.
 *
 *    **On the one screen whose entire purpose is comparing a date's current
 *    time with the time the new pattern would give it.** Display only -- the
 *    stored value was never touched by this page.
 *
 * ⚠️ AND NO GUARD COULD SEE IT, which is the part worth keeping. This check
 *    (`check_clock_time_not_a_moment.py`) needs a column name within 200
 *    characters on the same line, and `$reading` arrives as a FUNCTION
 *    PARAMETER -- the values are `tblEvents.startDateTime` readings, set at
 *    line 267. Round 5's published one-hop sweep looks for
 *    `$x = $row['<column>']` followed by a conversion, so a hop through a
 *    parameter misses it too. **That is a THIRD blind spot, alongside the
 *    one-hop variable and the write path**, and it is now written down in the
 *    backlog's header.
 */
$showReading = static function (?string $reading): string {
    // 📌 THE EM DASH IS KEPT. `formatClockDateTime()` answers '' for nothing,
    //    and this helper has always shown '—' in the table's empty cells, so
    //    dropping it would leave them blank — a change to the page, made by
    //    accident, inside a fix for something else.
    $words = I18n::formatClockDateTime($reading, 'D j M Y, H:i');
    if ($words === '') {
        return '—';
    }
    return htmlspecialchars($words, ENT_QUOTES, 'UTF-8');
};
?>

<!-- 📌 Page header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="mb-0">
            <i class="fa-solid fa-repeat me-2"></i>Change the pattern:
            <?php echo htmlspecialchars((string) $series['seriesName'], ENT_QUOTES, 'UTF-8'); ?>
        </h1>
        <small class="text-muted">
            <?php echo count($events); ?> date(s) in this series, read in
            <?php echo htmlspecialchars($zone->getName(), ENT_QUOTES, 'UTF-8'); ?>
        </small>
    </div>
    <a href="<?php echo htmlspecialchars($backToSeries, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i> Back to series
    </a>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<?php if ($problem !== ''): ?>
    <div class="alert alert-danger">
        <i class="fa-solid fa-circle-exclamation me-1"></i>
        <?php echo htmlspecialchars($problem, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<?php if ($warning !== ''): ?>
    <div class="alert alert-warning">
        <i class="fa-solid fa-triangle-exclamation me-1"></i>
        <?php echo htmlspecialchars($warning, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<?php if ($events === []): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-1"></i>
        This series has no dates of its own yet, so there is no pattern to change.
    </div>
<?php else: ?>

<!-- 📋 What this page will and will not do. Said before the form, because it
     is the difference between this page and the ordinary edit. -->
<div class="alert alert-secondary">
    <p class="mb-1"><strong>What a pattern change does</strong></p>
    <ul class="mb-1">
        <li>Only dates <strong>in the future</strong> are ever changed.</li>
        <li>A date that stays keeps everything about it except its time — its name, venue and description are not touched.</li>
        <li>A date is <strong>left exactly as it is</strong> if anything is attached to it (attendance, bookings, registrations, invitations), or if somebody has moved it by hand.</li>
        <li>A date that goes is <strong>hidden rather than deleted</strong>, so nothing attached to it is
            destroyed. You can bring it back afterwards from this series' bulk edit page, where it returns as a
            draft (or still cancelled, if it had been cancelled). If the series has since been given another date on that same day, you are asked to confirm
            first, because the series would then have two dates on that day.</li>
    </ul>
    <p class="mb-0 small text-muted">Nothing is written until you have seen exactly what will change and confirmed it.</p>
</div>

<form method="post" action="<?php echo htmlspecialchars(Site::url('calendar/manage/series-repattern'), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="seriesID" value="<?php echo (int) $seriesID; ?>">

    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="fa-solid fa-sliders me-1"></i> The new pattern</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6 col-lg-3">
                    <label class="form-label" for="patternStart">Starts</label>
                    <input type="datetime-local" class="form-control" id="patternStart" name="patternStart"
                           value="<?php echo htmlspecialchars(str_replace(' ', 'T', substr($formStart, 0, 16)), ENT_QUOTES, 'UTF-8'); ?>" required>
                    <div class="form-text">The first date and time the new pattern applies to. It has to be in the future.</div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <label class="form-label" for="patternEnd">Ends</label>
                    <input type="datetime-local" class="form-control" id="patternEnd" name="patternEnd"
                           value="<?php echo htmlspecialchars($formEnd === '' ? '' : str_replace(' ', 'T', substr($formEnd, 0, 16)), ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="form-text">Leave blank for a date with no finish time.</div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <label class="form-label" for="repeatFrequency">How often</label>
                    <select class="form-select" id="repeatFrequency" name="repeatFrequency">
                        <?php foreach (['weekly' => 'Every week', 'fortnightly' => 'Every fortnight', 'monthly' => 'Every month', 'quarterly' => 'Every quarter', 'yearly' => 'Every year'] as $value => $label): ?>
                            <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"
                                <?php echo $formFrequency === $value ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 col-lg-3">
                    <label class="form-label" for="repeatCount">How many dates</label>
                    <input type="number" class="form-control" id="repeatCount" name="repeatCount" min="1"
                           value="<?php echo htmlspecialchars($formCount, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="form-text">Leave blank to run to the date below, or to this installation's ceiling.</div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <label class="form-label" for="repeatUntil">Until</label>
                    <input type="date" class="form-control" id="repeatUntil" name="repeatUntil"
                           value="<?php echo htmlspecialchars($formUntil, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-12 col-lg-5">
                    <label class="form-label">On these days (weekly and fortnightly)</label>
                    <div class="d-flex flex-wrap gap-3">
                        <?php foreach ($dayNames as $number => $name): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="repeatDays[]"
                                       id="repeatDay<?php echo (int) $number; ?>" value="<?php echo (int) $number; ?>"
                                    <?php echo in_array((string) $number, $formDays, true) === true ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="repeatDay<?php echo (int) $number; ?>">
                                    <?php echo htmlspecialchars(substr($name, 0, 3), ENT_QUOTES, 'UTF-8'); ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-text">Tick nothing to keep the same weekday as the starting date.</div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label" for="repeatMonthlyMode">Monthly and quarterly</label>
                    <select class="form-select" id="repeatMonthlyMode" name="repeatMonthlyMode">
                        <option value="day" <?php echo $formMonthlyMode !== 'weekday' ? 'selected' : ''; ?>>The same date each month</option>
                        <option value="weekday" <?php echo $formMonthlyMode === 'weekday' ? 'selected' : ''; ?>>The same weekday of the month</option>
                    </select>
                </div>
                <div class="col-md-6 col-lg-3">
                    <label class="form-label" for="repeatWeekOfMonth">Which week</label>
                    <select class="form-select" id="repeatWeekOfMonth" name="repeatWeekOfMonth">
                        <option value="">—</option>
                        <?php
                        // ⚠️ `(string) $value` IS LOAD-BEARING, and leaving it out
                        //    took the whole page down. PHP turns a numeric array
                        //    key into an INTEGER, so '1' => 'First' gives int 1 —
                        //    and with `declare(strict_types=1)` at the top of this
                        //    file, handing an int to `htmlspecialchars()` is a
                        //    TypeError, not a quiet conversion.
                        //
                        //    What that looked like is why it is worth a comment:
                        //    the page rendered its heading, its explanation and
                        //    most of its form, threw here, and the error handler
                        //    then printed the footer — so the answer was HTTP 200
                        //    ending in a perfectly ordinary `</html>`, with the
                        //    preview silently missing. `php -l` cannot see it and
                        //    neither can any audit script here; only asking for
                        //    the page found it (check R08).
                        //
                        //    `===` against a string needs the same cast, or
                        //    nothing is ever pre-selected.
                        foreach (['1' => 'First', '2' => 'Second', '3' => 'Third', '4' => 'Fourth', '-1' => 'Last'] as $value => $label):
                            $weekValue = (string) $value;
                            ?>
                            <option value="<?php echo htmlspecialchars($weekValue, ENT_QUOTES, 'UTF-8'); ?>"
                                <?php echo $formWeekOfMonth === $weekValue ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Only used with "the same weekday of the month".</div>
                </div>
            </div>
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-eye me-1"></i> Show me what would change
            </button>
        </div>
    </div>

    <?php if ($showPreview === true && $plan !== null): ?>
        <?php
        $digest     = $planDigest($plan);
        $nothingYet = SeriesRepattern::planIsEmpty($plan);
        ?>

        <?php if ($cappedNote !== ''): ?>
            <div class="alert alert-info">
                <i class="fa-solid fa-circle-info me-1"></i>
                <?php echo htmlspecialchars($cappedNote, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fa-solid fa-list-check me-1"></i> What would change</h5>
                <small class="text-muted">
                    <?php echo count($plan['move']); ?> re-timed ·
                    <?php echo count($plan['add']); ?> added ·
                    <?php echo count($plan['remove']); ?> removed ·
                    <?php echo count($plan['keep']); ?> left as they are
                </small>
            </div>
            <div class="card-body">
                <?php if ($nothingYet === true): ?>
                    <p class="mb-0">
                        Nothing would change. That pattern already matches the dates in the diary, or every
                        date it would affect is one of those left alone below.
                    </p>
                <?php endif; ?>

                <?php if ($plan['move'] !== []): ?>
                    <h6 class="mt-2">Re-timed <span class="badge bg-primary"><?php echo count($plan['move']); ?></span></h6>
                    <div class="portal-data-list mb-3">
                        <div class="portal-data-row portal-data-header d-none d-md-flex">
                            <div class="col-md-6">Now</div>
                            <div class="col-md-6">Becomes</div>
                        </div>
                        <?php foreach ($plan['move'] as $m): ?>
                            <div class="portal-data-row">
                                <div class="col-12 col-md-6">
                                    <span class="d-md-none fw-semibold">Now: </span><?php echo $showReading((string) $m['from']); ?>
                                </div>
                                <div class="col-12 col-md-6">
                                    <span class="d-md-none fw-semibold">Becomes: </span><?php echo $showReading((string) $m['to']); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($plan['add'] !== []): ?>
                    <h6>Added <span class="badge bg-success"><?php echo count($plan['add']); ?></span></h6>
                    <div class="portal-data-list mb-3">
                        <?php foreach ($plan['add'] as $a): ?>
                            <div class="portal-data-row">
                                <div class="col-12"><?php echo $showReading((string) $a['start']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="small text-muted">
                        Each new date is a copy of this series as it stands now — the same name, venue, category and
                        settings as the next date in the diary. It does <strong>not</strong> inherit anything that
                        belongs to one date's own history: a new date is never created already cancelled, and carries
                        no cancellation reason, moderation note or submitter details.
                    </p>
                    <p class="small text-muted">
                        <strong>Each new date arrives as a draft, so members will not see it yet.</strong> That is
                        deliberate: a new date has no registration window of its own and nothing beyond this series'
                        description, so it is not ready to be read by anybody until you have looked at it. A draft
                        is one step from right, whereas a date published too soon is already in front of members,
                        so this way round the mistake is the recoverable one.
                    </p>
                    <p class="small text-muted">
                        <strong>You will land on this series' bulk edit page, where you can publish them all at
                        once:</strong> tick the new dates and set <em>Status</em> to Published. Until you do, anybody
                        subscribing to this calendar sees each date on its own rather than one repeating entry,
                        because the calendar file only collapses dates that are published.
                    </p>
                <?php endif; ?>

                <?php if ($plan['remove'] !== []): ?>
                    <h6>Removed <span class="badge bg-danger"><?php echo count($plan['remove']); ?></span></h6>
                    <div class="portal-data-list mb-3">
                        <?php foreach ($plan['remove'] as $r): ?>
                            <div class="portal-data-row">
                                <div class="col-12"><?php echo $showReading((string) $r['start']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="small text-muted">
                        These are hidden rather than deleted, so nothing attached to them is destroyed. Nothing is
                        attached to any of them in any case — a date carrying attendance, a booking or a
                        registration is in the list below instead. <strong>You can bring a hidden date back</strong>
                        from this series' bulk edit page, where it returns as a draft (or still cancelled, if it had
                        been cancelled) &mdash; and if the series has
                        since been given another date on that day, only after you confirm you want two.
                    </p>
                <?php endif; ?>

                <?php if ($plan['keep'] !== []): ?>
                    <h6>Left exactly as they are <span class="badge bg-secondary"><?php echo count($plan['keep']); ?></span></h6>
                    <div class="portal-data-list mb-2">
                        <div class="portal-data-row portal-data-header d-none d-md-flex">
                            <div class="col-md-5">Date</div>
                            <div class="col-md-7">Why</div>
                        </div>
                        <?php foreach ($plan['keep'] as $k): ?>
                            <div class="portal-data-row">
                                <div class="col-12 col-md-5">
                                    <span class="d-md-none fw-semibold">Date: </span><?php echo $showReading((string) $k['start']); ?>
                                </div>
                                <div class="col-12 col-md-7">
                                    <span class="d-md-none fw-semibold">Why: </span>
                                    <?php echo htmlspecialchars((string) $k['words'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if (in_array((string) $k['why'], [SeriesRepattern::KEPT_PAST, SeriesRepattern::KEPT_SAME_DAY], true) === false): ?>
                                        <?php
                                        // 🔑 `?edit=`, NOT `?eventID=`. `calendar/manage/index.php` reads
                                        //    `$_GET['edit']` and reads `eventID` nowhere, so the first version of
                                        //    this link opened the unfiltered list with no event selected — the
                                        //    repository's own "name things by the right identifier" trap, and the
                                        //    only remedy this page offers for a date it refuses to touch. Every
                                        //    other link to that page in the repository uses `?edit=`.
                                        //
                                        //    Offered only where editing that date by hand is the answer. A date
                                        //    that has already happened, and one of two dates sharing a day, are
                                        //    not cases where opening the editor helps.
                                        ?>
                                        <a class="ms-1 small"
                                           href="<?php echo htmlspecialchars(Site::url('calendar/manage') . '?edit=' . (int) $k['eventID'], ENT_QUOTES, 'UTF-8'); ?>">
                                            change this one by hand
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($plan['sameDay'] !== []): ?>
                    <div class="alert alert-warning mb-0">
                        <i class="fa-solid fa-triangle-exclamation me-1"></i>
                        This series has more than one date on
                        <?php echo htmlspecialchars(implode(', ', $plan['sameDay']), ENT_QUOTES, 'UTF-8'); ?>.
                        There is no way to tell which one the pattern means, so none of them is touched.
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($nothingYet === false): ?>
                <div class="card-footer">
                    <input type="hidden" name="planDigest" value="<?php echo htmlspecialchars($digest, ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" name="confirm" value="1" class="btn btn-danger">
                        <i class="fa-solid fa-check me-1"></i> Make these changes
                    </button>
                    <span class="ms-2 small text-muted">
                        This re-times <?php echo count($plan['move']); ?>, adds <?php echo count($plan['add']); ?>
                        and hides <?php echo count($plan['remove']); ?> date(s).
                    </span>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</form>

<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
