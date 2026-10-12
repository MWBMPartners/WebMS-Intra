<?php
// Path: _core/FeedAdmin.php
/**
 * -----------------------------------------------------------------------------
 * The service class behind the outside-calendar administrator pages 🖥️🗓️
 * -----------------------------------------------------------------------------
 * #514 gave every organisation the ability to copy in events from somebody
 * else's calendar (Google, Microsoft 365 or any other published calendar
 * file), and part P7 built the RULE that works out who may see each copied-
 * in event (`Portal\Core\FeedResolver`) and the importer that downloads the
 * files (`Portal\Core\FeedImporter`). Part P8 is the last piece: the actual
 * pages an administrator uses to add a calendar, choose who sees it, write
 * a rule, and approve or decline a date that is about to be shown more
 * widely than before.
 *
 * THIS CLASS IS THE ONLY PLACE THOSE PAGES TOUCH THE DATABASE
 * ---------------------------------------------------------------------------
 * Every read and every write the administrator pages need lives here. A
 * page never runs its own SQL against `tblEvents`, `tblExternalFeeds` or any
 * of the other #514 tables — it calls one method here, gets back a plain
 * array, and shows it. Three reasons this matters more than tidiness:
 *
 *   1. WRITE SAFETY. Seven write methods — `saveCalendar()`,
 *      `saveCategoryMap()`, `saveChoice()`, `removeChoice()`, `saveRule()`,
 *      `deleteRule()` and `decide()` — follow exactly ONE sequence (see
 *      `inFeedLock()` below): open a transaction, take the calendar's own
 *      row lock BY NUMBER AND ORGANISATION, do the work, call
 *      `FeedResolver::resolveFeed()` with the DATABASE's own clock, commit.
 *      Written once, in one private method, that sequence cannot drift
 *      between them. Written seven times in seven page handlers, it would be
 *      seven chances to get it wrong — and #514's part P6 leak-hunt already
 *      found what happens when a refresh, a pause and an administrator's
 *      save are allowed to interleave (leak-hunt finding 13).
 *      Two methods are outside it, on purpose. `addCalendar()` writes in a
 *      transaction of its own WITHOUT the lock and without a resolve: the
 *      calendar's row does not exist until its INSERT, so nothing else can
 *      be writing it, and a new calendar has no events to work out yet (its
 *      first refresh does that). `previewRule()` writes nothing at all.
 *      (Until the final whole-part check this said "every write" and "nine
 *      write methods", which was untrue — finding F4.)
 *   2. IT MAKES THE SAFETY LOGIC PROVABLE WITHOUT A WEB SERVER. Part D of
 *      `tools/feed-admin-selftest.php` calls every method here directly,
 *      with a pinned database clock and a second connection holding the
 *      calendar's lock — something no page-level test can do.
 *   3. IT KEEPS EVERY READ OF `tblEvents` IN ONE FILE. So
 *      `tools/audit-checks/check_event_visibility.py` needs exactly one
 *      allow-list entry, with one reason, instead of one per page (see that
 *      script's `FILE_ALLOWLIST` for the entry this class earns).
 *
 * WHAT THIS CLASS NEVER DOES
 * ---------------------------------------------------------------------------
 * - It never reads the session, `Site`, `App::settings()` or `$_POST`
 *   directly. Every input is an argument: the organisation (`$siteId`), the
 *   acting account (`$userId`), and the posted array. A page collects those
 *   from the request; this class never assumes where they came from, which
 *   is what lets part D call it with a made-up organisation and a made-up
 *   account.
 * - It never logs. `Portal\Core\Logger::activity()` reads the session, and
 *   part D loads classes one at a time without a session to read — a class
 *   that logged here would either crash the self-test or force it to fake a
 *   session for no reason. Pages log, after a successful write, using the
 *   number this class hands back.
 * - It never calls `FeedImporter::refresh()`. A refresh manages its own
 *   transaction and can take real time (a network fetch); a page runs it
 *   AFTER this class's own transaction has already committed, never inside
 *   one.
 * - It never widens anything without going through `FeedResolver`. Every
 *   write that could show an event more widely ends by calling
 *   `FeedResolver::resolveFeed()` — the ONE place that decides who may see
 *   an event — so a mistake here can narrow the outcome by accident but
 *   can never silently widen it.
 *
 * "MAY YOU CHANGE THIS ONE?" — THE ONE RULE EVERY METHOD FOLLOWS
 * ---------------------------------------------------------------------------
 * Every lookup that FINDS a calendar, an event, a choice, a rule or an
 * approval from a number a page was given is by that number AND `siteID`
 * together. A row that belongs to another organisation gets EXACTLY the
 * same answer as one that does not exist at all: `null` from a read, or
 * `['ok' => false, 'status' => 'not_found', ...]` from a write. There is
 * no way, from outside this class, to tell "wrong organisation" apart from
 * "no such row" (the #503 rule; part P6 leak-hunt finding 30).
 *
 * WHAT THAT SENTENCE DOES NOT COVER, said plainly (fix round 1, checker
 * LOW 9 — the first version of this header overstated it): a few SECOND
 * lookups use a row number alone, because the number was itself just read
 * from a row that an organisation-scoped lookup already found — a rule's
 * conditions by `ruleID` (`conditionsOf()`), a date's waiting/declined
 * state by `eventID` (`approvalStateFor()`), the newest superseded row
 * for the preview page, and the "no longer waiting" count after a bulk
 * decision. None of them can be reached with a number typed into a page.
 * The category names on the events list ARE scoped to the organisation
 * as well, so an event hand-edited to point at another organisation's
 * category shows no name rather than that organisation's word.
 *
 * Every WRITE also checks, before it reads or writes anything in the
 * database, that the acting account is an administrator of the GIVEN
 * organisation (`EventVisibility::isAdminOfSite()`) — never the older,
 * portal-wide `isAdmin` flag, which says nothing about which organisation
 * somebody belongs to. A page checks this too, before it even shows the
 * form (so a non-administrator gets a plain 403 rather than a page that
 * then refuses to save) — but the check happens again here, on every
 * write, so "who is asking" is proved in the one place "may you change
 * THIS one" is also proved (challenge finding L1). The only things done
 * before it are checks of the request's OWN shape that need no database
 * at all — `decide()`'s decision word, whether its note is readable text
 * and not too long, and the number of dates in one go — so they can never
 * read or reveal anything.
 *
 * READS TAKE NO ACTOR. A read here answers "what does THIS organisation
 * hold?"; the page that calls it has already checked that the person
 * asking is one of that organisation's administrators. So a read can
 * never show one organisation's rows to another, but it does not itself
 * prove who is asking — that is the page's job, and part W's checks.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/514
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class FeedAdmin
{
    // =========================================================================
    // 🏷️ Labels — the ONE place every word shown to an administrator lives
    // =========================================================================

    /** Who may see a calendar's, a choice's or a rule's events. */
    public const LEVEL_LABELS = [
        'public'  => 'Everyone, including people who are not signed in',
        'members' => 'Members of this organisation',
        'groups'  => 'Selected groups and people',
        'hidden'  => 'Only administrators',
    ];

    /** How much of an event a viewer outside the calendar's own audience sees. */
    public const DETAIL_LABELS = [
        'basic' => 'Title, date and time only',
        'full'  => 'Full details',
    ];

    /**
     * Why `importLevel` is what it is (`tblEvents.importSource`). The `rule`
     * entry is a BASE word only — `sourceLabel()` (in the display helpers
     * below) builds the real sentence, because the real sentence names the
     * rule (or says it has been deleted), which a constant cannot do. A page
     * shows a source ONLY through `sourceLabel()`, never by reading this
     * array itself. D15 checks the KEYS of this array against the column's
     * own ENUM, never the `rule` value.
     */
    public const SOURCE_LABELS = [
        'calendar' => "The calendar's own setting",
        'date'     => 'A choice for this date',
        'series'   => 'A choice for every date',
        'rule'     => 'Rule',
        'waiting'  => 'Waiting for approval to be shown more widely',
        'private'  => 'Marked private in the outside calendar',
        'conflict' => 'Hidden to be safe: the rules disagree, or its text could not be read',
        'duplicate' => 'Listed twice in the outside calendar, so its own setting stands',
    ];

    /**
     * Every word `FeedResolver::previewRule()` can answer with (C10 item 9).
     * The KEYS must equal `FeedResolver::PREVIEW_OUTCOMES`, in the SAME
     * ORDER — D15 checks this with `array_keys()`, because a preview line's
     * label is looked up by that outcome word.
     */
    public const OUTCOME_LABELS = [
        'rule_not_active_yet_or_ended'    => "Outside the rule's dates",
        'duplicate_not_applied'           => 'Listed twice in the outside calendar; rules are not applied to it',
        'private_not_applied'             => 'Marked private in the outside calendar; rules never show it',
        'covered_by_choice'               => 'A choice for this date, or for every date, decides it instead',
        'conflicting_group_rules_hidden'  => 'Rules name different groups, so it is hidden',
        'another_rule_is_stricter'        => 'Another rule is stricter, so that one applies',
        'declined_before'                 => 'Declined before, with the same details',
        'waits_for_approval'              => 'Waits for approval',
        'applies_now'                     => 'Applies straight away',
    ];

    /** Why a waiting or decided row exists (`tblExternalEventApprovals.reason`). */
    public const REASON_LABELS = [
        'new_match'       => 'A rule or choice newly asks to show it more widely',
        'content_changed' => 'Its details changed since it was decided',
        'address_changed' => "The calendar's address was changed",
        'choice'          => 'Shown on the choice page when it was saved',
        'series_match'    => 'Followed a decision on another date of the same repeating event',
    ];

    /** How a refresh attempt ended (`tblExternalFeedRuns.outcome`). */
    public const RUN_OUTCOME_LABELS = [
        'ok'        => 'Worked',
        'unchanged' => 'No change since last time',
        'failed'    => 'Failed',
        'partial'   => 'Partly read',
    ];

    /** A rule condition's field (`tblExternalRuleConditions.matchField`). */
    public const MATCH_FIELD_LABELS = [
        'category' => 'Category',
        'title'    => 'Title',
        'location' => 'Location',
    ];

    /** A rule condition's comparison (`tblExternalRuleConditions.matchType`). */
    public const MATCH_TYPE_LABELS = [
        'equals'   => 'is exactly',
        'contains' => 'contains',
        'word'     => 'contains the word',
    ];

    /** The most conditions, or exceptions, one rule may have (plan section 5.3). */
    public const MAX_CONDITIONS = 10;

    /** Events shown per page of the calendar's own page (plan section 3.3). */
    public const EVENTS_PER_PAGE = 100;

    /**
     * The most waiting dates one bulk decision may cover (plan section
     * 3.3). `decide()` itself refuses more, before any SQL (fix round 1,
     * checker LOW 4) — a page's own cap is not the only line.
     */
    public const MAX_BULK = 200;

    /**
     * The longest calendar address, the same as `SafeFetch`'s own limit and
     * the `tblExternalFeeds.url` column (plan section 5, L3).
     */
    public const MAX_ADDRESS_LENGTH = 2000;

    /**
     * The longest approval note. `FeedResolver::decideApproval()` would CUT
     * a longer one to this length (`FeedResolver.php:497`), so `decide()`
     * refuses it first — plan L3: "refuse, never cut short" (fix round 1,
     * checker M3).
     */
    public const MAX_DECISION_NOTE = 500;

    /**
     * The seconds PHP is allowed once a write takes a calendar's lock — the
     * same 90 the first version gave `decide()` alone (see
     * `allowTimeForLockedWork()`).
     */
    private const LOCKED_WORK_SECONDS = 90;

    // =========================================================================
    // 💬 Standard messages — used by more than one method, word for word
    // =========================================================================

    /** Every write's refusal when the actor is not an administrator of the given organisation (challenge L1). */
    private const ACTOR_MESSAGE = 'Only an administrator of this organisation can change this.';

    /** Another organisation's calendar, or one that does not exist, are the SAME answer (the #503 rule). */
    private const NOT_FOUND_CALENDAR = 'That calendar could not be found.';

    private const NOT_FOUND_EVENT = 'That event could not be found.';

    private const NOT_FOUND_RULE = 'That rule could not be found.';

    private const NOT_FOUND_APPROVAL = 'That waiting date could not be found.';

    /** Shown when a covered date is marked private and the warning checkbox was not ticked (plan section 5.2). */
    private const PRIVATE_WARNING_MESSAGE
        = 'This event is marked private in the outside calendar. Showing it is your decision, not a rule\'s.';

    // =========================================================================
    // 📖 READS
    // =========================================================================

    /**
     * One calendar of one organisation, with its live counts, or null when
     * it is not this organisation's (or does not exist — the same answer).
     *
     * `lastRunCapped` (1 or 0; chunk P8-2's follow-up, for the "Partly read"
     * badge, the same column `calendars()` returns) is the importer's own
     * record of whether the newest refresh that actually READ the calendar
     * took only part of it (`FeedImporter::stampSuccess()` writes it on every
     * complete or capped reading). A later refresh that found the file
     * unchanged, or failed, leaves it alone — rightly, because the events
     * shown are still the ones that partial reading gave. It is NOT worked
     * out from the refresh history: a run row has no "capped" field, and the
     * newest run's outcome alone would drop the badge after an unchanged or
     * failed refresh while the events were still partial.
     *
     * @return array<string,mixed>|null
     */
    public static function calendar(\mysqli $db, int $feedId, int $siteId): ?array
    {
        $row = self::feedRow($db, $feedId, $siteId);
        if ($row === null) {
            return null;
        }
        $row['waiting']     = self::waitingCount($db, $feedId, $siteId);
        $row['stale']       = ((int) $row['isActive'] === 1) ? self::staleCount($db, $feedId, $siteId) : 0;
        $row['lastTriedAt'] = self::newestRunStartedAt($db, $feedId, $siteId);
        $row['list']        = ($row['audienceLevel'] === 'groups')
            ? ExternalAudience::current($db, $siteId, $feedId, 'feed', $feedId)
            : [];

        return $row;
    }

    /**
     * Every calendar of one organisation, each with its live counts. A
     * fixed number of statements — five, however many calendars there are:
     * the calendars themselves, one grouped count each for waiting
     * approvals and stale events, one grouped newest-run moment, and ONE
     * list query covering every calendar set to "Selected groups"
     * (`ExternalAudience::currentMany()`).
     *
     * (Fix round 1, checker M6: the first version read each Selected-groups
     * calendar's list with its own `current()` call — about four statements
     * per calendar, measured at ~200 statements for 52 calendars.)
     *
     * @return list<array<string,mixed>>
     */
    public static function calendars(\mysqli $db, int $siteId): array
    {
        $stmt = $db->prepare(
            'SELECT feedID, siteID, name, url, fetchEveryMins, timezone, categoryID, audienceLevel, websiteOptIn, '
            . 'apiOptOut, isActive, lastFetchedAt, nextFetchAt, lastFetchOk, lastFetchMessage, lastContentHash, '
            . 'consecutiveFailures, lastRunCapped FROM tblExternalFeeds WHERE siteID = ? ORDER BY name, feedID'
        );
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if ($rows === []) {
            return [];
        }

        $waitingByFeed = [];
        $stmt = $db->prepare(
            "SELECT feedID, COUNT(*) AS n FROM tblExternalEventApprovals WHERE siteID = ? AND status = 'pending' GROUP BY feedID"
        );
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($r = $result->fetch_assoc()) !== null) {
            $waitingByFeed[(int) $r['feedID']] = (int) $r['n'];
        }
        $stmt->close();

        $staleByFeed = [];
        $stmt = $db->prepare(
            'SELECT externalFeedID AS feedID, COUNT(*) AS n FROM tblEvents '
            . 'WHERE siteID = ? AND isDeleted = 0 AND externalFeedID IS NOT NULL '
            . 'AND importRecheckAt IS NOT NULL AND importRecheckAt <= UTC_TIMESTAMP() GROUP BY externalFeedID'
        );
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($r = $result->fetch_assoc()) !== null) {
            $staleByFeed[(int) $r['feedID']] = (int) $r['n'];
        }
        $stmt->close();

        $lastTriedByFeed = [];
        $stmt = $db->prepare('SELECT feedID, MAX(startedAt) AS started FROM tblExternalFeedRuns WHERE siteID = ? GROUP BY feedID');
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($r = $result->fetch_assoc()) !== null) {
            $lastTriedByFeed[(int) $r['feedID']] = (string) $r['started'];
        }
        $stmt->close();

        $owners = [];
        foreach ($rows as $row) {
            if ($row['audienceLevel'] === 'groups') {
                $fid      = (int) $row['feedID'];
                $owners[] = ['feedID' => $fid, 'ownerType' => 'feed', 'ownerID' => $fid];
            }
        }
        $lists = ExternalAudience::currentMany($db, $siteId, $owners);

        foreach ($rows as &$row) {
            $fid              = (int) $row['feedID'];
            $row['waiting']   = $waitingByFeed[$fid] ?? 0;
            $row['stale']     = ((int) $row['isActive'] === 1) ? ($staleByFeed[$fid] ?? 0) : 0;
            $row['lastTriedAt'] = $lastTriedByFeed[$fid] ?? null;
            $row['list']      = ($row['audienceLevel'] === 'groups')
                ? ($lists[ExternalAudience::ownerKey($fid, 'feed', $fid)] ?? [])
                : [];
        }
        unset($row);

        return $rows;
    }

    /**
     * The newest refresh attempts of one calendar, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public static function runs(\mysqli $db, int $feedId, int $siteId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $stmt  = $db->prepare(
            'SELECT runID, startedAt, finishedAt, outcome, httpStatus, message, bytes, eventsSeen, rowsAdded, '
            . 'rowsUpdated, rowsRemoved, rowsSkipped, awaitingApproval, triggeredBy, triggeredByID '
            . 'FROM tblExternalFeedRuns WHERE feedID = ? AND siteID = ? ORDER BY startedAt DESC, runID DESC LIMIT ?'
        );
        $stmt->bind_param('iii', $feedId, $siteId, $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    /**
     * One page of a calendar's live imported events, newest-first by
     * default is wrong for a diary — this is by START time, earliest
     * first, the order the calendar page reads like a diary.
     *
     * @return array{rows: list<array<string,mixed>>, total: int}
     */
    public static function events(\mysqli $db, int $feedId, int $siteId, int $page): array
    {
        $page    = max(1, $page);
        $perPage = self::EVENTS_PER_PAGE;
        $offset  = ($page - 1) * $perPage;

        $totalRow = self::one(
            $db,
            'SELECT COUNT(*) AS n FROM tblEvents WHERE externalFeedID = ? AND siteID = ? AND isDeleted = 0',
            'ii',
            [$feedId, $siteId]
        );
        $total = (int) ($totalRow['n'] ?? 0);

        $stmt = $db->prepare(
            'SELECT eventID, eventName, startDateTime, endDateTime, timezone, isAllDay, categoryID, importLevel, '
            . 'importDetail, importWebsite, importApiOptOut, importAudienceType, importAudienceID, importSource, '
            . 'importSourceID, importRecheckAt, (externalUidHash IS NOT NULL) AS hasIdentity, '
            . "(externalRecurrenceKey <> '') AS inSeries, "
            . '(importRecheckAt IS NOT NULL AND importRecheckAt <= UTC_TIMESTAMP()) AS stale '
            . 'FROM tblEvents WHERE externalFeedID = ? AND siteID = ? AND isDeleted = 0 '
            . 'ORDER BY startDateTime, eventID LIMIT ? OFFSET ?'
        );
        $stmt->bind_param('iiii', $feedId, $siteId, $perPage, $offset);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if ($rows === []) {
            return ['rows' => [], 'total' => $total];
        }

        $categoryIds = [];
        foreach ($rows as $r) {
            if ($r['categoryID'] !== null) {
                $categoryIds[(int) $r['categoryID']] = true;
            }
        }
        $categoryNames = [];
        if ($categoryIds !== []) {
            // 🏷️ Scoped to THIS organisation as well as by number (fix round
            //    1, checker LOW 9). An imported event only ever gets a
            //    category of its own organisation through the map, but a row
            //    edited by hand could point anywhere; then it simply shows
            //    no category name, never another organisation's word.
            $ids          = array_keys($categoryIds);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt         = $db->prepare("SELECT categoryID, categoryName FROM tblEventCategories WHERE siteID = ? AND categoryID IN ($placeholders)");
            $stmt->bind_param('i' . str_repeat('i', count($ids)), $siteId, ...$ids);
            $stmt->execute();
            $result = $stmt->get_result();
            while (($r = $result->fetch_assoc()) !== null) {
                $categoryNames[(int) $r['categoryID']] = (string) $r['categoryName'];
            }
            $stmt->close();
        }

        $ruleIds = [];
        foreach ($rows as $r) {
            if ($r['importSource'] === 'rule' && $r['importSourceID'] !== null) {
                $ruleIds[(int) $r['importSourceID']] = true;
            }
        }
        $ruleNames = [];
        if ($ruleIds !== []) {
            $ids          = array_keys($ruleIds);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt         = $db->prepare("SELECT ruleID, name FROM tblExternalFeedRules WHERE feedID = ? AND ruleID IN ($placeholders)");
            $stmt->bind_param('i' . str_repeat('i', count($ids)), $feedId, ...$ids);
            $stmt->execute();
            $result = $stmt->get_result();
            while (($r = $result->fetch_assoc()) !== null) {
                $ruleNames[(int) $r['ruleID']] = (string) $r['name'];
            }
            $stmt->close();
        }

        foreach ($rows as &$row) {
            $row['hasIdentity'] = ((int) $row['hasIdentity'] === 1);
            // `inSeries` (chunk P8-3): true for one date of a repeating event
            // (its recurrence key is not empty). The calendar page offers
            // "Set every date…" only then — a one-off has no series, and its
            // choice page would answer "not found" (the same test
            // `saveChoice()` makes).
            $row['inSeries']    = ((int) $row['inSeries'] === 1);
            $row['stale']       = ((int) $row['stale'] === 1);
            $catId              = $row['categoryID'] === null ? null : (int) $row['categoryID'];
            $row['categoryName'] = $catId === null ? null : ($categoryNames[$catId] ?? null);
            $row['ruleName']    = null;
            if ($row['importSource'] === 'rule' && $row['importSourceID'] !== null) {
                $row['ruleName'] = $ruleNames[(int) $row['importSourceID']] ?? null; // null = "a rule that has since been deleted"
            }
        }
        unset($row);

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * A live imported event of one organisation's calendar — WITH an
     * identity (`externalUidHash IS NOT NULL`). A row left behind by the
     * old #327 importer, before #514, can have no identity (migration 205's
     * header); such a row answers null here exactly like a missing one
     * (challenge M3), so a choice can never be attached to it.
     *
     * `importAudienceType` / `importAudienceID` (chunk P8-4) say whose list
     * a Selected-groups answer uses (the calendar's, a choice's or a
     * rule's), so the preview page can name the people it lets in — read
     * live through `ExternalAudience::current()`, scoped to this
     * organisation there.
     *
     * `feedActive` and `stale` (chunk P8-4 fix round 2) say whether anybody
     * sees the date RIGHT NOW whatever its stored answer says: a paused
     * calendar's events are hidden from everyone, administrators included,
     * and a date whose stored answer has run out (`importRecheckAt` passed)
     * is shown only to administrators until the scheduled refresh runs —
     * both tested live by `EventVisibility`. `stale` is decided by the
     * DATABASE's clock, in SQL, like every other "now" in part 8 (D19).
     *
     * @return array<string,mixed>|null
     */
    public static function importedEvent(\mysqli $db, int $eventId, int $siteId): ?array
    {
        $stmt = $db->prepare(
            'SELECT e.eventID, e.siteID, e.externalFeedID AS feedID, e.externalUidHash, e.externalRecurrenceKey, '
            . 'e.eventName, e.description, e.locationName, e.externalUrl, e.startDateTime, e.endDateTime, '
            . 'e.timezone, e.isAllDay, e.categoryID, e.externalPrivate, e.externalDuplicate, e.importLevel, '
            . 'e.importDetail, e.importWebsite, e.importApiOptOut, e.importSource, e.importSourceID, '
            . 'e.importAudienceType, e.importAudienceID, '
            . 'f.name AS feedName, f.apiOptOut AS feedApiOptOut, f.timezone AS feedTimezone, f.isActive AS feedActive, '
            . '(e.importRecheckAt IS NOT NULL AND e.importRecheckAt <= UTC_TIMESTAMP()) AS stale '
            . 'FROM tblEvents e JOIN tblExternalFeeds f ON f.feedID = e.externalFeedID AND f.siteID = e.siteID '
            . 'WHERE e.eventID = ? AND e.siteID = ? AND e.isDeleted = 0 '
            . 'AND e.externalFeedID IS NOT NULL AND e.externalUidHash IS NOT NULL LIMIT 1'
        );
        $stmt->bind_param('ii', $eventId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row;
    }

    /**
     * The saved choice for one imported event's identity and scope, or
     * null when there is none.
     *
     * @param array<string,mixed> $event From `importedEvent()`.
     *
     * @return array<string,mixed>|null
     */
    public static function choiceFor(\mysqli $db, array $event, string $scope): ?array
    {
        $feedId  = (int) $event['feedID'];
        $siteId  = (int) $event['siteID'];
        $uidHash = $event['externalUidHash'];
        $key     = ($scope === 'date') ? (string) $event['externalRecurrenceKey'] : '';

        $stmt = $db->prepare(
            'SELECT choiceID, scope, audienceLevel, detailLevel, websiteOptIn, apiOptOut, fromDate, toDate, '
            . 'overridesPrivateMark, note, createdByID, createdAt, updatedByID, updatedAt '
            . 'FROM tblExternalEventChoices '
            . 'WHERE feedID = ? AND siteID = ? AND scope = ? AND externalUidHash = ? AND externalRecurrenceKey = ? LIMIT 1'
        );
        $stmt->bind_param('iisss', $feedId, $siteId, $scope, $uidHash, $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return null;
        }
        $row['list'] = ($row['audienceLevel'] === 'groups')
            ? ExternalAudience::current($db, $siteId, $feedId, 'choice', (int) $row['choiceID'])
            : [];

        return $row;
    }

    /**
     * Every live, identity-bearing date a choice of this scope would
     * cover: for `date`, the opened event's own date ONLY — never a
     * sibling — because listing one here is how a date gets approved when
     * the choice is saved (`saveChoice()`'s `seen`), and part 7's own check
     * (R11) found that step trusts any listed date without looking again
     * (challenge L17c). For `series`, every live date sharing the same
     * identity hash.
     *
     * @param array<string,mixed> $event From `importedEvent()`.
     *
     * @return list<array<string,mixed>>
     */
    public static function datesCovered(\mysqli $db, array $event, string $scope): array
    {
        $feedId      = (int) $event['feedID'];
        $siteId      = (int) $event['siteID'];
        $openedCatId = $event['categoryID'] === null ? null : (int) $event['categoryID'];
        $openedCf    = FeedResolver::fullContentHash($event, $openedCatId);

        if ($scope === 'date') {
            $rawRows = [$event];
        } else {
            $stmt = $db->prepare(
                'SELECT eventID, eventName, description, locationName, externalUrl, startDateTime, endDateTime, '
                . 'timezone, isAllDay, categoryID, externalRecurrenceKey, externalPrivate, externalDuplicate '
                . 'FROM tblEvents WHERE externalFeedID = ? AND siteID = ? AND isDeleted = 0 '
                . 'AND externalUidHash = ? AND externalUidHash IS NOT NULL ORDER BY startDateTime, eventID'
            );
            $stmt->bind_param('iis', $feedId, $siteId, $event['externalUidHash']);
            $stmt->execute();
            $rawRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }

        $out = [];
        foreach ($rawRows as $r) {
            $eventId = (int) $r['eventID'];
            $catId   = $r['categoryID'] === null ? null : (int) $r['categoryID'];
            $cf      = FeedResolver::fullContentHash($r, $catId);
            $state   = self::approvalStateFor($db, $eventId);
            $out[]   = [
                'eventID'         => $eventId,
                'eventName'       => (string) $r['eventName'],
                'startDateTime'   => (string) $r['startDateTime'],
                'endDateTime'     => $r['endDateTime'] === null ? null : (string) $r['endDateTime'],
                'timezone'        => (string) ($r['timezone'] ?? ''),
                'isAllDay'        => (int) ($r['isAllDay'] ?? 0),
                'categoryID'      => $catId,
                'hasDescription'  => (($r['description'] ?? '') !== '' && $r['description'] !== null),
                'hasLocation'     => (($r['locationName'] ?? '') !== '' && $r['locationName'] !== null),
                'hasLink'         => (($r['externalUrl'] ?? '') !== '' && $r['externalUrl'] !== null),
                'externalPrivate' => (int) ($r['externalPrivate'] ?? 0),
                // Listed twice by the outside calendar (final-check fix, F1):
                // the choice page's sentences say what that means for it.
                'externalDuplicate' => (int) ($r['externalDuplicate'] ?? 0),
                'cf'              => $cf,
                'differs'         => ($cf !== $openedCf),
                'state'           => $state['state'],
                'declinedAt'      => $state['declinedAt'],
            ];
        }

        return $out;
    }

    /**
     * Every rule of one calendar (switched on and off), each with its
     * conditions and its list.
     *
     * @return list<array<string,mixed>>
     */
    public static function rules(\mysqli $db, int $feedId, int $siteId): array
    {
        $stmt = $db->prepare('SELECT ruleID FROM tblExternalFeedRules WHERE feedID = ? AND siteID = ? ORDER BY name, ruleID');
        $stmt->bind_param('ii', $feedId, $siteId);
        $stmt->execute();
        $ids = array_map(static fn (array $r): int => (int) $r['ruleID'], $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        $stmt->close();

        $out = [];
        foreach ($ids as $ruleId) {
            $row = self::rule($db, $ruleId, $feedId, $siteId);
            if ($row !== null) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * One rule of one calendar and organisation, with its conditions and
     * its list, or null when it belongs to another calendar, another
     * organisation, or does not exist (challenge L15).
     *
     * @return array<string,mixed>|null
     */
    public static function rule(\mysqli $db, int $ruleId, int $feedId, int $siteId): ?array
    {
        $stmt = $db->prepare(
            'SELECT ruleID, feedID, siteID, name, isActive, audienceLevel, detailLevel, websiteOptIn, apiOptOut, '
            . 'fromDate, toDate, createdByID, createdAt, updatedByID, updatedAt '
            . 'FROM tblExternalFeedRules WHERE ruleID = ? AND feedID = ? AND siteID = ? LIMIT 1'
        );
        $stmt->bind_param('iii', $ruleId, $feedId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return null;
        }
        $row['conditions'] = self::conditionsOf($db, $ruleId);
        $row['list']       = ($row['audienceLevel'] === 'groups')
            ? ExternalAudience::current($db, $siteId, $feedId, 'rule', $ruleId)
            : [];

        return $row;
    }

    /**
     * Every category word seen on a calendar's live events, or already in
     * its map — whichever gives more, in lower case (D9).
     *
     * @return list<array{word:string, categoryID:?int, mapped:bool, present:bool}>
     */
    public static function tags(\mysqli $db, int $feedId, int $siteId): array
    {
        $words = [];
        $stmt  = $db->prepare(
            'SELECT DISTINCT t.tag FROM tblExternalEventTags t JOIN tblEvents e ON e.eventID = t.eventID '
            . 'WHERE e.externalFeedID = ? AND e.siteID = ? AND e.isDeleted = 0'
        );
        $stmt->bind_param('ii', $feedId, $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $words[(string) $row['tag']] = true;
        }
        $stmt->close();

        $mapped = [];
        $stmt   = $db->prepare('SELECT externalCategory, categoryID FROM tblExternalCategoryMap WHERE feedID = ? AND siteID = ?');
        $stmt->bind_param('ii', $feedId, $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $w          = (string) $row['externalCategory'];
            $mapped[$w] = $row['categoryID'] === null ? null : (int) $row['categoryID'];
            if (isset($words[$w]) === false) {
                $words[$w] = false; // known to the map, not seen on a live event right now
            }
        }
        $stmt->close();

        // Every word goes back as TEXT (fix round 8, check round 8 L2): PHP
        // turns an array key such as "2026" or "0" into a whole NUMBER, so
        // the key itself is not the word. Handed on as a number, the house
        // escaping `htmlspecialchars($row['word'], …)` throws a TypeError
        // under strict_types, and the calendar page would fail outright for
        // any calendar with a numeric category word (a year, "1", "42").
        $out = [];
        foreach ($words as $word => $present) {
            $out[] = [
                'word'       => (string) $word,
                'categoryID' => $mapped[$word] ?? null,
                'mapped'     => array_key_exists($word, $mapped),
                'present'    => ($present === true),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['word'], $b['word']));

        return $out;
    }

    /**
     * The organisation's own active event categories.
     *
     * @return list<array{categoryID:int, categoryName:string}>
     */
    public static function categories(\mysqli $db, int $siteId): array
    {
        $stmt = $db->prepare('SELECT categoryID, categoryName FROM tblEventCategories WHERE siteID = ? AND isActive = 1 ORDER BY categoryName, categoryID');
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return array_map(
            static fn (array $r): array => ['categoryID' => (int) $r['categoryID'], 'categoryName' => (string) $r['categoryName']],
            $rows
        );
    }

    /**
     * Waiting approval rows of one organisation (optionally one calendar
     * only), oldest first, with the calendar's name, whether it is
     * switched on, how many OTHER dates of the same repeating event are
     * waiting right now for the same request and content (`siblingsWaiting`
     * — a count of waiting rows as they stand, NOT a prediction of how many
     * will follow a decision: the resolver can file, withdraw or settle rows
     * in the same save, as `decide()`'s docblock explains; owner's decision
     * of 28 September 2026, plan top-section item 10), and the live names
     * for a Selected-groups request.
     *
     * THREE STATEMENTS, however many rows (plan section 4.1; fix round 1,
     * checker M6):
     *   1. the rows themselves, with the total count worked out by the SAME
     *      statement (`COUNT(*) OVER ()` is counted before `LIMIT` is
     *      applied, so it is the number of waiting rows, not of this page);
     *   2. ONE grouped count of waiting rows per (calendar, identity,
     *      request, content) — a row's `siblingsWaiting` is its own group's
     *      count less itself;
     *   3. ONE list query for every Selected-groups request on the page
     *      (`ExternalAudience::currentMany()`).
     * The first version ran a sibling count and a list read PER ROW —
     * measured at about 1,000 statements and 2.6 seconds for 200 rows.
     *
     * One case adds a statement instead: a page number past the end gives
     * no rows, so the total cannot come from them, and one plain count is
     * run. (Nothing else runs then: no rows means no siblings and no
     * names.)
     *
     * @return array{rows: list<array<string,mixed>>, total: int}
     */
    public static function pendingApprovals(\mysqli $db, int $siteId, ?int $feedId, int $limit, int $offset = 0): array
    {
        $limit  = max(1, min(self::MAX_BULK, $limit));
        $offset = max(0, $offset);

        // 1️⃣ The rows, and the total, in one statement. `e.externalUidHash`
        //    comes along for statement 2 only and is removed before the
        //    rows are returned (raw bytes a page has no use for).
        // `a.reason` came along in chunk P8-4: each waiting row says WHY it
        // waits, in words (REASON_LABELS), on the inbox and the calendar page.
        $select = 'SELECT a.approvalID, a.feedID, a.eventID, a.origin, a.originID, a.requestHash, a.contentHash, a.reason, '
            . 'a.requestedLevel, a.requestedDetail, a.requestedWebsite, a.requestedAudienceSummary, a.snapTitle, '
            . 'a.snapStart, a.snapEnd, a.snapTimezone, a.snapIsAllDay, a.createdAt, f.name AS feedName, '
            . 'f.isActive AS feedActive, e.externalUidHash AS uidHash, COUNT(*) OVER () AS totalRows '
            . 'FROM tblExternalEventApprovals a '
            . 'JOIN tblExternalFeeds f ON f.feedID = a.feedID AND f.siteID = a.siteID '
            . 'LEFT JOIN tblEvents e ON e.eventID = a.eventID ';
        if ($feedId === null) {
            $stmt = $db->prepare(
                $select . "WHERE a.siteID = ? AND a.status = 'pending' ORDER BY f.name, a.createdAt, a.approvalID LIMIT ? OFFSET ?"
            );
            $stmt->bind_param('iii', $siteId, $limit, $offset);
        } else {
            $stmt = $db->prepare(
                $select . "WHERE a.siteID = ? AND a.feedID = ? AND a.status = 'pending' ORDER BY a.createdAt, a.approvalID LIMIT ? OFFSET ?"
            );
            $stmt->bind_param('iiii', $siteId, $feedId, $limit, $offset);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if ($rows === []) {
            $total = 0;
            if ($offset > 0) {
                // A page past the end: the total cannot come from rows that
                // are not there (see the docblock).
                $totalRow = ($feedId === null)
                    ? self::one($db, "SELECT COUNT(*) AS n FROM tblExternalEventApprovals WHERE siteID = ? AND status = 'pending'", 'i', [$siteId])
                    : self::one(
                        $db,
                        "SELECT COUNT(*) AS n FROM tblExternalEventApprovals WHERE siteID = ? AND feedID = ? AND status = 'pending'",
                        'ii',
                        [$siteId, $feedId]
                    );
                $total = (int) ($totalRow['n'] ?? 0);
            }

            return ['rows' => [], 'total' => $total];
        }
        $total = (int) $rows[0]['totalRows'];

        // 2️⃣ One grouped sibling count. The group is exactly the old
        //    per-row test: waiting, same calendar, same identity hash, same
        //    request, same content. A row whose event has no identity (an
        //    old #327 row, challenge M3) has no siblings — the same answer
        //    the resolver gives, since it never follows across a missing
        //    identity.
        $groupKey = static fn (int $fid, string $uid, string $req, string $con): string
            => $fid . "\0" . bin2hex($uid) . "\0" . $req . "\0" . $con;
        $feedIds  = [];
        $uidSet   = [];
        foreach ($rows as $r) {
            if ($r['uidHash'] !== null) {
                $feedIds[(int) $r['feedID']] = true;
                $uidSet[(string) $r['uidHash']] = true;
            }
        }
        $groupCounts = [];
        if ($uidSet !== []) {
            $fids   = array_keys($feedIds);
            $uids   = array_map('strval', array_keys($uidSet));
            $sql    = 'SELECT a.feedID, e.externalUidHash AS uidHash, a.requestHash, a.contentHash, COUNT(*) AS n '
                . 'FROM tblExternalEventApprovals a JOIN tblEvents e ON e.eventID = a.eventID '
                . "WHERE a.siteID = ? AND a.status = 'pending' "
                . 'AND a.feedID IN (' . implode(',', array_fill(0, count($fids), '?')) . ') '
                . 'AND e.externalUidHash IN (' . implode(',', array_fill(0, count($uids), '?')) . ') '
                . 'GROUP BY a.feedID, e.externalUidHash, a.requestHash, a.contentHash';
            $stmt   = $db->prepare($sql);
            $types  = 'i' . str_repeat('i', count($fids)) . str_repeat('s', count($uids));
            $params = array_merge([$siteId], $fids, $uids);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            while (($g = $result->fetch_assoc()) !== null) {
                $groupCounts[$groupKey((int) $g['feedID'], (string) $g['uidHash'], (string) $g['requestHash'], (string) $g['contentHash'])]
                    = (int) $g['n'];
            }
            $stmt->close();
        }

        // 3️⃣ One list query for every Selected-groups request on the page.
        $owners = [];
        foreach ($rows as $r) {
            if ($r['requestedLevel'] === 'groups') {
                $owners[] = ['feedID' => (int) $r['feedID'], 'ownerType' => (string) $r['origin'], 'ownerID' => (int) $r['originID']];
            }
        }
        $lists = ExternalAudience::currentMany($db, $siteId, $owners);

        foreach ($rows as &$row) {
            $siblings = 0;
            if ($row['uidHash'] !== null) {
                $n = $groupCounts[$groupKey((int) $row['feedID'], (string) $row['uidHash'], (string) $row['requestHash'], (string) $row['contentHash'])] ?? 1;
                // The row itself is one of its own group's waiting rows.
                $siblings = max(0, $n - 1);
            }
            $row['siblingsWaiting'] = $siblings;
            $row['names'] = ($row['requestedLevel'] === 'groups')
                ? ($lists[ExternalAudience::ownerKey((int) $row['feedID'], (string) $row['origin'], (int) $row['originID'])] ?? [])
                : [];
            unset($row['uidHash'], $row['totalRows']);
        }
        unset($row);

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * One approval row of one organisation, with its calendar's name and
     * the newest `superseded` row for the same event and request (for the
     * preview page's "changed since it was last decided" line).
     *
     * @return array<string,mixed>|null
     */
    public static function approval(\mysqli $db, int $approvalId, int $siteId): ?array
    {
        $stmt = $db->prepare(
            'SELECT a.approvalID, a.feedID, a.eventID, a.origin, a.originID, a.requestHash, a.contentHash, a.status, '
            . 'a.reason, a.requestedLevel, a.requestedDetail, a.requestedWebsite, a.requestedAudienceSummary, '
            . 'a.snapTitle, a.snapStart, a.snapEnd, a.snapTimezone, a.snapIsAllDay, a.snapCategoryID, '
            . 'a.snapDescription, a.snapLocation, a.snapUrl, a.decidedByID, a.decidedAt, a.decisionNote, a.createdAt, '
            . 'a.updatedAt, f.name AS feedName, f.isActive AS feedActive, f.apiOptOut AS feedApiOptOut '
            . 'FROM tblExternalEventApprovals a JOIN tblExternalFeeds f ON f.feedID = a.feedID AND f.siteID = a.siteID '
            . 'WHERE a.approvalID = ? AND a.siteID = ? LIMIT 1'
        );
        $stmt->bind_param('ii', $approvalId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return null;
        }

        // The snapshot fields came along in chunk P8-4: the preview page
        // compares them with this row's ("Changed since it was last decided:
        // the title, the category …"), only for fields BOTH snapshots carry
        // — a "title, date and time only" snapshot holds no description,
        // location or link (full_schema.sql, tblExternalEventApprovals).
        $row['lastSuperseded'] = self::one(
            $db,
            "SELECT approvalID, snapTitle, snapCategoryID, snapDescription, snapLocation, snapUrl, requestedDetail, "
            . "decidedAt, updatedAt FROM tblExternalEventApprovals "
            . "WHERE eventID = ? AND requestHash = ? AND status = 'superseded' ORDER BY updatedAt DESC, approvalID DESC LIMIT 1",
            'is',
            [(int) $row['eventID'], (string) $row['requestHash']]
        );
        $row['names'] = ($row['requestedLevel'] === 'groups')
            ? ExternalAudience::current($db, $siteId, (int) $row['feedID'], (string) $row['origin'], (int) $row['originID'])
            : [];

        // How many OTHER dates of the same repeating event wait right now
        // for the very same decision (chunk P8-4, for the preview page) —
        // the same group pendingApprovals() counts: waiting, same
        // calendar, same identity, same request, same content. A present
        // fact, never a prediction (plan top-section item 10). 0 for a row
        // that is not waiting, and for an event with no identity (it has no
        // siblings — the resolver never follows across a missing identity).
        $row['siblingsWaiting'] = 0;
        if ($row['status'] === 'pending') {
            $siblings = self::one(
                $db,
                'SELECT COUNT(*) AS n FROM tblExternalEventApprovals a2 '
                . 'JOIN tblEvents e2 ON e2.eventID = a2.eventID '
                . 'JOIN tblEvents e1 ON e1.eventID = ? AND e1.externalUidHash IS NOT NULL '
                . "WHERE a2.siteID = ? AND a2.feedID = ? AND a2.status = 'pending' AND a2.requestHash = ? AND a2.contentHash = ? "
                . 'AND e2.externalUidHash = e1.externalUidHash AND a2.approvalID <> ?',
                'iiissi',
                [(int) $row['eventID'], $siteId, (int) $row['feedID'], (string) $row['requestHash'], (string) $row['contentHash'], $approvalId]
            );
            $row['siblingsWaiting'] = (int) ($siblings['n'] ?? 0);
        }

        return $row;
    }

    /**
     * Across the WHOLE installation: how many switched-on, public,
     * "Don't show via API"-clear calendars belong to an organisation that
     * holds at least one active, unexpired API key with the `events:read`
     * scope — and how many organisations that is. Used only by the upgrade
     * page's one-time notice (section 7.1) and the API keys page's one
     * sentence (section 7.2), both in a later chunk; built here because it
     * belongs with every other read of these tables.
     *
     * @return array{calendars:int, organisations:int}
     */
    public static function apiUpgradeNotice(\mysqli $db): array
    {
        // ⏰ "Not expired" is decided by the DATABASE's clock, in SQL (plan
        //    section 10; fix round 1, checker LOW 7). The first version
        //    read `expiresAt` with `strtotime()` and compared it with PHP's
        //    `time()` — which reads the text in whatever zone PHP happens to
        //    be in (the organisation's, once `bootstrap.php` has run; UTC on
        //    a command line), so the same key could count on one page and
        //    not on another.
        //
        //    WHAT THIS CANNOT SETTLE: `ApiKey::findByPlaintext()` (the check
        //    that really lets a key in) still reads `expiresAt` in PHP's
        //    zone, and the API keys page writes it in that zone too. So for
        //    a key within a few hours of expiring, this one-off count and
        //    the real check can disagree by up to the organisation's
        //    distance from UTC. It is a count shown once on the upgrade
        //    page, so that edge only decides whether a key about to expire
        //    is counted. What `expiresAt` itself means is #549's question.
        //
        //    Scope matching stays in PHP, with ApiKey's own method, never
        //    re-written in SQL (plan section 7.1).
        $qualifyingSites = [];
        $result          = $db->query(
            'SELECT siteID, scopes FROM tblApiKeys WHERE isActive = 1 AND (expiresAt IS NULL OR expiresAt >= UTC_TIMESTAMP())'
        );
        if ($result !== false) {
            while (($row = $result->fetch_assoc()) !== null) {
                if (ApiKey::hasScope($row, 'events:read') === false) {
                    continue;
                }
                $qualifyingSites[(int) $row['siteID']] = true;
            }
        }

        $calendars     = 0;
        $organisations = [];
        $result        = $db->query("SELECT siteID FROM tblExternalFeeds WHERE isActive = 1 AND apiOptOut = 0 AND audienceLevel = 'public'");
        if ($result !== false) {
            while (($row = $result->fetch_assoc()) !== null) {
                $siteId = (int) $row['siteID'];
                if (isset($qualifyingSites[$siteId]) === true) {
                    $calendars++;
                    $organisations[$siteId] = true;
                }
            }
        }

        return ['calendars' => $calendars, 'organisations' => count($organisations)];
    }

    // =========================================================================
    // ✏️ WRITES — every one goes through inFeedLock() except addCalendar()
    // (which has no calendar row to lock until the INSERT creates one) and
    // previewRule() (which writes nothing). Every one that takes a lock
    // first finds the calendar with a plain look-up by number AND
    // organisation, so another organisation's number never reaches a lock
    // (fix round 1, checker M1).
    // =========================================================================

    /**
     * Add a new calendar. No resolve is needed here: a new calendar has no
     * events yet — the page's first refresh resolves it.
     *
     * @param array<string,mixed> $post
     *
     * @return array{ok:bool, status:string, message:string, feedId?:int}
     */
    public static function addCalendar(\mysqli $db, int $siteId, int $userId, array $post): array
    {
        if (EventVisibility::isAdminOfSite($db, $userId, $siteId) === false) {
            return ['ok' => false, 'status' => 'refused', 'message' => self::ACTOR_MESSAGE];
        }

        $v = self::validateCalendarForm($db, $siteId, $post, [], null);
        if ($v['ok'] === false) {
            return ['ok' => false, 'status' => 'refused', 'message' => $v['message']];
        }
        $f = $v['fields'];

        $db->begin_transaction();
        try {
            $stmt = $db->prepare(
                'INSERT INTO tblExternalFeeds (siteID, name, url, fetchEveryMins, timezone, categoryID, audienceLevel, '
                . 'websiteOptIn, apiOptOut, isActive, nextFetchAt, createdByID, createdAt) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), ?, UTC_TIMESTAMP())'
            );
            $zone = ($f['timezone'] === '') ? null : $f['timezone'];
            $stmt->bind_param(
                'issisisiii',
                $siteId, $f['name'], $f['url'], $f['fetchEveryMins'], $zone, $f['categoryID'], $f['audienceLevel'],
                $f['website'], $f['apiOptOut'], $userId
            );
            $stmt->execute();
            $feedId = (int) $db->insert_id;
            $stmt->close();

            if ($f['audienceLevel'] === 'groups') {
                ExternalAudience::save($db, $siteId, $feedId, 'feed', $feedId, $f['members'], $userId);
            }

            $db->commit();

            return ['ok' => true, 'status' => 'saved', 'message' => 'Calendar added.', 'feedId' => $feedId];
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable $ignored) {
                // The original exception is what matters; see inFeedLock()'s note.
            }
            throw $e;
        }
    }

    /**
     * Change a calendar's own settings. Every write that could widen who
     * sees its events resolves in the SAME call (C10 item 2).
     *
     * The address is checked only when it is a DIFFERENT address from the
     * stored one (see `validateCalendarForm()`, fix round 1, checker M4),
     * and that check — which may look a name up on the internet — runs
     * BEFORE the lock, because a lookup can take real seconds and must
     * never hold the calendar's row lock. "Different" is decided again
     * under the lock, against the row as it is then.
     *
     * @param array<string,mixed> $post
     *
     * @return array{ok:bool, status:string, message:string, sentBack?:int, refreshNeeded?:bool}
     */
    public static function saveCalendar(\mysqli $db, int $feedId, int $siteId, int $userId, array $post): array
    {
        if (EventVisibility::isAdminOfSite($db, $userId, $siteId) === false) {
            return ['ok' => false, 'status' => 'refused', 'message' => self::ACTOR_MESSAGE];
        }
        $existing = self::feedRow($db, $feedId, $siteId);
        if ($existing === null) {
            return ['ok' => false, 'status' => 'not_found', 'message' => self::NOT_FOUND_CALENDAR];
        }

        $currentList = ($existing['audienceLevel'] === 'groups')
            ? ExternalAudience::current($db, $siteId, $feedId, 'feed', $feedId)
            : [];
        $v = self::validateCalendarForm($db, $siteId, $post, $currentList, $existing);
        if ($v['ok'] === false) {
            return ['ok' => false, 'status' => 'refused', 'message' => $v['message'], 'sentBack' => 0, 'refreshNeeded' => false];
        }
        $f = $v['fields'];

        $result = self::inFeedLock($db, $feedId, $siteId, function () use ($db, $feedId, $siteId, $userId, $f, $existing): array {
            $locked = self::feedRow($db, $feedId, $siteId);
            if ($locked === null) {
                return ['ok' => false, 'status' => 'not_found', 'message' => self::NOT_FOUND_CALENDAR, 'sentBack' => 0, 'refreshNeeded' => false];
            }
            $lockedUrl = (string) $locked['url'];

            // 🌐 The address, decided again under the lock.
            if ($f['urlChecked'] === false) {
                // The form said "unchanged" against the row read BEFORE the
                // lock, so the posted address was never checked. If somebody
                // else changed the address in between, writing the posted
                // one would put an UNCHECKED address back — so refuse, and
                // let the administrator look again. (Rare: two people
                // editing the same calendar's address at once.)
                if ($lockedUrl !== (string) $existing['url']) {
                    return [
                        'ok' => false, 'status' => 'refused',
                        'message' => "Someone else changed this calendar's address while you were editing it. Please open it again.",
                        'sentBack' => 0, 'refreshNeeded' => false,
                    ];
                }
                $addressChanged = false;
                $urlToStore     = $lockedUrl;
            } else {
                // Checked before the lock. Still a change only if it differs
                // from what is stored NOW, compared the same way.
                $addressChanged = (self::sameAddress($f['url'], $lockedUrl) === false);
                $urlToStore     = ($addressChanged === true) ? $f['url'] : $lockedUrl;
            }

            $lockedZone  = $locked['timezone'] === null ? '' : (string) $locked['timezone'];
            $zoneChanged = ($f['timezone'] !== $lockedZone);
            $oldEvery    = (int) $locked['fetchEveryMins'];

            $stmt = $db->prepare(
                'UPDATE tblExternalFeeds SET name = ?, url = ?, fetchEveryMins = ?, timezone = ?, categoryID = ?, '
                . 'audienceLevel = ?, websiteOptIn = ?, apiOptOut = ?, updatedByID = ?, updatedAt = UTC_TIMESTAMP() '
                . 'WHERE feedID = ? AND siteID = ?'
            );
            $zone = ($f['timezone'] === '') ? null : $f['timezone'];
            $stmt->bind_param(
                'ssisisiiiii',
                $f['name'], $urlToStore, $f['fetchEveryMins'], $zone, $f['categoryID'], $f['audienceLevel'],
                $f['website'], $f['apiOptOut'], $userId, $feedId, $siteId
            );
            $stmt->execute();
            $stmt->close();

            if ($f['audienceLevel'] === 'groups') {
                ExternalAudience::save($db, $siteId, $feedId, 'feed', $feedId, $f['members'], $userId);
            } else {
                ExternalAudience::remove($db, $siteId, $feedId, 'feed', $feedId);
            }

            $sentBack      = 0;
            $refreshNeeded = false;
            if ($addressChanged === true) {
                // The DATABASE's clock (C10 item 2): the copies it makes are
                // dated with this moment, beside rows the resolver dates with
                // the same clock.
                $sentBack = FeedResolver::onAddressChanged($db, $feedId, FeedResolver::databaseNowUtc($db));
            }
            if ($addressChanged === true || $zoneChanged === true) {
                // 🚀 The old file fingerprint is thrown away and the next
                //    refresh brought forward to NOW — a changed address may
                //    be a different calendar altogether, and a changed zone
                //    changes every stored wall-clock time's meaning; neither
                //    should wait for the ordinary schedule (S5, L8).
                $stmt2 = $db->prepare('UPDATE tblExternalFeeds SET lastContentHash = NULL, nextFetchAt = UTC_TIMESTAMP() WHERE feedID = ?');
                $stmt2->bind_param('i', $feedId);
                $stmt2->execute();
                $stmt2->close();
                $refreshNeeded = true;
            } elseif ($f['fetchEveryMins'] < $oldEvery) {
                // ⏩ Challenge L8: a SHORTENED "Check every" takes effect at
                //    once, never waiting for the old nextFetchAt. LEAST() only
                //    ever brings the moment CLOSER.
                //    ONLY when the interval was shortened (fix round 1,
                //    checker LOW 8). The first version ran this on every
                //    save, so renaming a calendar that was failing — whose
                //    next try had been pushed days away to leave the other
                //    server alone (`consecutiveFailures`, FeedImporter's
                //    back-off) — pulled that try forward again. The same
                //    happens, rightly, when somebody deliberately shortens
                //    the interval: that is an explicit request for sooner.
                $stmt2 = $db->prepare(
                    'UPDATE tblExternalFeeds SET nextFetchAt = LEAST(COALESCE(nextFetchAt, UTC_TIMESTAMP()), '
                    . 'UTC_TIMESTAMP() + INTERVAL ? MINUTE) WHERE feedID = ?'
                );
                $stmt2->bind_param('ii', $f['fetchEveryMins'], $feedId);
                $stmt2->execute();
                $stmt2->close();
            }

            return ['ok' => true, 'status' => 'saved', 'sentBack' => $sentBack, 'refreshNeeded' => $refreshNeeded];
        });

        if ($result['ok'] === false) {
            return $result;
        }

        $message = 'Saved.';
        if ($result['sentBack'] > 0) {
            $message .= ' ' . $result['sentBack'] . ' widened event' . ($result['sentBack'] === 1 ? '' : 's')
                . ' went back for approval because the address changed.';
        }
        $result['message'] = $message;

        return $result;
    }

    /**
     * Save a calendar's category matching in one write per twin group
     * (challenge M6). See `findTwinGroups()` for the database-collation
     * grouping and what it cannot do.
     *
     * TWINS AND THE MESSAGE (fix round 7, check round 7 M1). The table's
     * unique key compares words the way the database does
     * (`utf8mb4_general_ci`: "café" and "cafe" are one word), so only ONE
     * spelling of a twin pair can be stored. The resolver, though, matches
     * an event's words against the STORED spelling exactly, in lower case,
     * in PHP (`FeedResolver::categoryMap()` / `categoryFor()`): an event
     * tagged with the other spelling does not match it, and gets the
     * category of its next word (in alphabetical order) that is mapped, or
     * else the calendar's own category. So the message says which spelling
     * is REALLY stored after the write — read inside the lock from the row
     * itself, because a twin already stored keeps its own spelling when it
     * is updated — and which events therefore get the category:
     *   - a word saved as "default" stores nothing, so nothing is said;
     *   - a word saved with a category, or as "none", where the stored
     *     spelling differs from a spelling that was posted, gets one
     *     sentence naming the stored spelling and what each spelling's
     *     events get — including a single posted word that landed on a
     *     stored twin of a different spelling.
     * The first version built this sentence from the first POSTED spelling
     * before anything was written, and told the administrator the exact
     * reverse of what happened whenever a twin was already stored.
     *
     * @param array<string,mixed> $post
     *
     * @return array{ok:bool, status:string, message:string}
     */
    public static function saveCategoryMap(\mysqli $db, int $feedId, int $siteId, int $userId, array $post): array
    {
        if (EventVisibility::isAdminOfSite($db, $userId, $siteId) === false) {
            return ['ok' => false, 'status' => 'refused', 'message' => self::ACTOR_MESSAGE];
        }
        $calendar = self::feedRow($db, $feedId, $siteId);
        if ($calendar === null) {
            return ['ok' => false, 'status' => 'not_found', 'message' => self::NOT_FOUND_CALENDAR];
        }

        $categories = self::categories($db, $siteId);
        $categoryIds = array_map(static fn (array $c): int => $c['categoryID'], $categories);

        $mapPost = (array) ($post['map'] ?? []);
        $rows    = [];
        $count   = 0;
        foreach ($mapPost as $row) {
            if ($count >= 450) {
                break;
            }
            $count++;
            if (is_array($row) === false) {
                continue;
            }
            $rawWord = (string) ($row['word'] ?? '');
            if (preg_match('//u', $rawWord) !== 1) {
                return ['ok' => false, 'status' => 'refused', 'message' => 'One of the words contains characters that cannot be read.'];
            }
            $word = mb_strtolower(trim($rawWord), 'UTF-8');
            if ($word === '') {
                continue; // a spare row
            }
            if (mb_strlen($word, 'UTF-8') > 100) {
                return ['ok' => false, 'status' => 'refused', 'message' => 'A category word can be at most 100 characters.'];
            }
            $choice     = (string) ($row['choice'] ?? 'default');
            $categoryId = null;
            if ($choice !== 'default' && $choice !== 'none') {
                $categoryId = (int) $choice;
                // A switched-on category of this organisation — or the one
                // this word is ALREADY stored with, kept unchanged even if it
                // has been switched off since (fix round 8, check round 8 L3;
                // the same keep rule fix round 1 gave the calendar's own
                // default category, LOW 3). Without it, a form posted back
                // unchanged after a category was switched off was refused
                // outright, and one that sent "default" instead quietly
                // deleted the mapping. A NEW choice of a switched-off
                // category, or of another organisation's, is still refused —
                // and the refusal names the word, so the administrator can
                // find it (plan 5.4 item 2 read as a rule for new choices).
                if (in_array($categoryId, $categoryIds, true) === false
                    && self::keepsStoredCategory($db, $feedId, $siteId, $word, $categoryId) === false) {
                    return ['ok' => false, 'status' => 'refused', 'message' => "Choose a category from the list for '{$word}'."];
                }
                $choice = 'category';
            }
            $rows[] = ['word' => $word, 'choice' => $choice, 'categoryID' => $categoryId];
        }

        if ($rows === []) {
            return ['ok' => true, 'status' => 'saved', 'message' => 'Saved.'];
        }

        // 🪤 Twins: words the DATABASE treats as one word (utf8mb4_general_ci)
        //    even though PHP does not (challenge M6). Found in ONE statement,
        //    never by comparing in PHP.
        $twinGroups = self::findTwinGroups($db, array_column($rows, 'word'));
        foreach ($twinGroups as $group) {
            if (count($group) < 2) {
                continue;
            }
            $settings = [];
            foreach ($group as $idx) {
                $settings[$rows[$idx]['choice'] . ':' . ($rows[$idx]['categoryID'] ?? '')] = true;
            }
            if (count($settings) > 1) {
                sort($group);
                // Name two DIFFERENT spellings (fix round 7, check round 7
                // L1: the first version named the first two posted ROWS, so
                // one word listed twice read "'cafe' and 'cafe'").
                $spellings = array_values(array_unique(array_map(static fn (int $idx): string => $rows[$idx]['word'], $group)));
                if (count($spellings) === 1) {
                    return [
                        'ok' => false, 'status' => 'refused',
                        'message' => "'{$spellings[0]}' is listed more than once with different settings; give it one setting.",
                    ];
                }

                return [
                    'ok' => false, 'status' => 'refused',
                    'message' => "'{$spellings[0]}' and '{$spellings[1]}' count as the same word here, so they must use the same setting.",
                ];
            }
        }

        // One write per group; each carries every spelling posted for it,
        // so the message can be built from what is really stored (below).
        $grouped = [];
        $used    = [];
        foreach ($twinGroups as $group) {
            if (count($group) < 2) {
                continue;
            }
            sort($group);
            $g              = $rows[$group[0]];
            $g['spellings'] = array_values(array_unique(array_map(static fn (int $idx): string => $rows[$idx]['word'], $group)));
            $grouped[]      = $g;
            foreach ($group as $idx) {
                $used[$idx] = true;
            }
        }
        foreach ($rows as $idx => $row) {
            if (isset($used[$idx]) === false) {
                $row['spellings'] = [$row['word']];
                $grouped[]        = $row;
            }
        }
        // The NAME of every category of THIS organisation, switched on or
        // off (fix round 9, check round 9 R9-L1). The keep rule (fix round
        // 8) lets a word keep a category that has since been switched off;
        // its name, looked up among the switched-on categories only, came
        // back empty, and twinNote() read that as "no category" — while the
        // row, and the events, still had it. Scoped to this organisation:
        // never another organisation's name. (The checks above have already
        // proved every category being saved is this organisation's.)
        $categoryNames = [];
        $stmt = $db->prepare('SELECT categoryID, categoryName FROM tblEventCategories WHERE siteID = ?');
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $c) {
            $categoryNames[(int) $c['categoryID']] = (string) $c['categoryName'];
        }
        $stmt->close();

        $result = self::inFeedLock($db, $feedId, $siteId, function () use ($db, $feedId, $siteId, $grouped, $categoryNames): array {
            $twinNotes = [];
            foreach ($grouped as $g) {
                if ($g['choice'] === 'default') {
                    // 🗑️ The database's own comparison removes the stored
                    //    twin, whatever spelling it was saved under. Nothing
                    //    is stored afterwards, so the message says nothing
                    //    about what is stored (fix round 7, M1).
                    $stmt = $db->prepare('DELETE FROM tblExternalCategoryMap WHERE feedID = ? AND externalCategory = ?');
                    $stmt->bind_param('is', $feedId, $g['word']);
                    $stmt->execute();
                    $stmt->close();
                    continue;
                }
                // The stored row's own spelling is read here, under the lock,
                // because an UPDATE keeps it — it is what the resolver will
                // match events against (fix round 7, M1).
                $found = self::one(
                    $db,
                    'SELECT mapID, externalCategory FROM tblExternalCategoryMap WHERE feedID = ? AND externalCategory = ? FOR UPDATE',
                    'is',
                    [$feedId, $g['word']]
                );
                $stored = ($found !== null) ? mb_strtolower(trim((string) $found['externalCategory']), 'UTF-8') : $g['word'];
                $others = array_values(array_filter($g['spellings'], static fn (string $w): bool => $w !== $stored));
                if ($others !== []) {
                    // A category whose name cannot be found (it cannot happen
                    // after the checks above, but a null here would read as
                    // "no category") is named by its number instead.
                    $twinNotes[] = self::twinNote($stored, $others, $g['choice'] === 'category' ? ($categoryNames[$g['categoryID']] ?? ('number ' . $g['categoryID'])) : null);
                }
                if ($found !== null) {
                    $stmt = $db->prepare('UPDATE tblExternalCategoryMap SET categoryID = ? WHERE mapID = ?');
                    $mapId = (int) $found['mapID'];
                    $stmt->bind_param('ii', $g['categoryID'], $mapId);
                    $stmt->execute();
                    $stmt->close();
                } else {
                    // 🔒 A 1062 here is never swallowed: under the calendar's
                    //    lock two requests cannot race to insert the same
                    //    word, so if this ever throws it is a real fault
                    //    (challenge M6's "never swallowed" promise).
                    $stmt = $db->prepare('INSERT INTO tblExternalCategoryMap (siteID, feedID, externalCategory, categoryID) VALUES (?, ?, ?, ?)');
                    $stmt->bind_param('iisi', $siteId, $feedId, $g['word'], $g['categoryID']);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            return ['ok' => true, 'status' => 'saved', 'twinNotes' => $twinNotes];
        });

        if ($result['ok'] === false) {
            return $result;
        }

        $message = 'Saved.';
        if ($result['twinNotes'] !== []) {
            $message .= ' ' . implode(' ', $result['twinNotes']);
        }
        $result['message'] = $message;

        return $result;
    }

    /**
     * True when `$categoryId` is the category `$word` is ALREADY stored with
     * in this calendar's map (the database's own comparison, so a stored
     * twin of another spelling counts), and that category still belongs to
     * THIS organisation — switched on or not (fix round 8, check round 8
     * L3). Read before the lock, like the rest of the form's checks: the
     * worst a change in between can do is keep this organisation's own
     * stored category, which is exactly what was asked for.
     */
    private static function keepsStoredCategory(\mysqli $db, int $feedId, int $siteId, string $word, int $categoryId): bool
    {
        $row = self::one(
            $db,
            'SELECT m.categoryID FROM tblExternalCategoryMap m JOIN tblEventCategories c ON c.categoryID = m.categoryID AND c.siteID = ? '
            . 'WHERE m.feedID = ? AND m.siteID = ? AND m.externalCategory = ? LIMIT 1',
            'iiis',
            [$siteId, $feedId, $siteId, $word]
        );

        return $row !== null && (int) $row['categoryID'] === $categoryId;
    }

    /**
     * One sentence for a twin group, from what is REALLY stored (fix round
     * 7, check round 7 M1; see saveCategoryMap()). `$stored` is the one
     * spelling in the table; `$others` the other posted spellings the
     * database counts as the same word; `$categoryName` the category it now
     * has, or null for "none". Singular and plural written out in full.
     *
     * WHAT IT MAY PROMISE (fix round 8, check round 8 L4). The resolver
     * (`FeedResolver::categoryFor()`) gives an event the category of its
     * FIRST mapped word in alphabetical order, so an event tagged "art" and
     * "café" gets Art's category even when "café" is mapped to Music. The
     * round 7 sentence said "events tagged 'café' get 'Music'", which that
     * event disproves. So the sentence says only what is always true: which
     * spelling is stored, that events tagged with it MATCH this word, and
     * that events tagged only with another spelling do not — for them this
     * word counts as not mapped. It never names the category an event ends
     * up with. With "none", no event gets a category from this word at all.
     *
     * Lists read as English: "'a' and 'b'", "'a', 'b' and 'c'" for the
     * spellings that count as the same word, and "or" for "events tagged
     * 'a' or 'b'" — the round 7 version joined everything with "and", which
     * read as events carrying all the words at once.
     *
     * @param list<string> $others
     */
    private static function twinNote(string $stored, array $others, ?string $categoryName): string
    {
        $one    = (count($others) === 1);
        $opener = self::quotedList($others, 'and') . ($one === true ? ' counts' : ' count')
            . " as the same word here as '{$stored}', so only '{$stored}' is stored";
        if ($categoryName === null) {
            return $opener . ', with no category: no event gets a category from this word, whichever spelling it is tagged with.';
        }

        return $opener . ", with the category '{$categoryName}'. Events tagged '{$stored}' match this word; events tagged "
            . self::quotedList($others, 'or') . " (and not '{$stored}') do not, so for them this word counts as not mapped.";
    }

    /**
     * Quoted words joined as English: "'a'", "'a' and 'b'", "'a', 'b' and
     * 'c'" (or "or" in place of "and").
     *
     * @param list<string> $words
     */
    private static function quotedList(array $words, string $joiner): string
    {
        $quoted = array_map(static fn (string $w): string => "'{$w}'", $words);
        if (count($quoted) === 1) {
            return $quoted[0];
        }
        $last = array_pop($quoted);

        return implode(', ', $quoted) . ' ' . $joiner . ' ' . $last;
    }

    /**
     * Save or update a choice for one date, or every date, of an imported
     * event.
     *
     * Besides the resolver's own counts (`resolved`), the result carries
     * `visibilityChanged` — the dates whose audience really changed (see
     * `whoCanSee()`) — and `newlyWaitingHere` — the dates filed under THIS
     * choice that newly wait for approval — which the message is built from.
     *
     * @param array<string,mixed> $post
     *
     * @return array{ok:bool, status:string, message:string}
     */
    public static function saveChoice(\mysqli $db, int $eventId, string $scope, int $siteId, int $userId, array $post): array
    {
        if (EventVisibility::isAdminOfSite($db, $userId, $siteId) === false) {
            return ['ok' => false, 'status' => 'refused', 'message' => self::ACTOR_MESSAGE];
        }
        if ($scope !== 'date' && $scope !== 'series') {
            return ['ok' => false, 'status' => 'not_found', 'message' => self::NOT_FOUND_EVENT];
        }
        $event = self::importedEvent($db, $eventId, $siteId);
        if ($event === null) {
            return ['ok' => false, 'status' => 'not_found', 'message' => self::NOT_FOUND_EVENT];
        }
        if ($scope === 'series' && (string) $event['externalRecurrenceKey'] === '') {
            return ['ok' => false, 'status' => 'not_found', 'message' => self::NOT_FOUND_EVENT];
        }

        $feedId      = (int) $event['feedID'];
        $covered     = self::datesCovered($db, $event, $scope);
        $coveredIds  = array_column($covered, 'eventID');
        $existing    = self::choiceFor($db, $event, $scope);
        $currentList = ($existing !== null)
            ? ExternalAudience::current($db, $siteId, $feedId, 'choice', (int) $existing['choiceID'])
            : [];

        $v = self::validateChoiceForm($db, $siteId, $post, $currentList, $covered);
        if ($v['ok'] === false) {
            return ['ok' => false, 'status' => 'refused', 'message' => $v['message']];
        }
        $f = $v['fields'];

        $seenMap = [];
        foreach ((array) ($post['seen'] ?? []) as $entry) {
            if (is_string($entry) === false) {
                continue;
            }
            $parts = explode(':', $entry, 2);
            if (count($parts) !== 2) {
                continue;
            }
            [$sidText, $hash] = $parts;
            // `\A`/`\z`, not `^`/`$` (chunk P8-4 fix round 1): `$` also
            // matches before a final line break, so "hash + newline" used to
            // be kept as "seen" — never equal to the real fingerprint, so the
            // date was filed as "content_changed" instead of being dropped as
            // unreadable like any other malformed entry.
            if (ctype_digit($sidText) === false || preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1) {
                continue;
            }
            $sid = (int) $sidText;
            if (in_array($sid, $coveredIds, true) === false) {
                continue; // not covered by THIS choice — dropped silently, never trusted (challenge L17b)
            }
            $seenMap[$sid] = $hash;
        }

        $key     = ($scope === 'date') ? (string) $event['externalRecurrenceKey'] : '';
        $uidHash = $event['externalUidHash'];

        // The dates already waiting under this choice before the save, so
        // the message can tell NEWLY waiting ones (read under the lock).
        // And who can see each date BEFORE anything is written (see
        // whoCanSee()), compared after the resolve inside the same
        // transaction.
        $waitingBefore = [];
        $seeBefore     = [];
        $seeToday      = '';
        $result        = self::inFeedLock($db, $feedId, $siteId, function () use ($db, $feedId, $siteId, $scope, $uidHash, $key, $f, $userId, $seenMap, &$waitingBefore, &$seeBefore, &$seeToday): array {
            $seeToday  = self::ruleToday($db, $siteId);
            $seeBefore = self::whoCanSee($db, $feedId, $siteId, $seeToday);
            $locked    = self::lockedChoice($db, $feedId, $siteId, $scope, $uidHash, $key);
            if ($locked === null) {
                $stmt = $db->prepare(
                    'INSERT INTO tblExternalEventChoices (siteID, feedID, scope, externalUidHash, '
                    . 'externalRecurrenceKey, audienceLevel, detailLevel, websiteOptIn, apiOptOut, fromDate, toDate, '
                    . 'overridesPrivateMark, note, createdByID, createdAt, updatedByID, updatedAt) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), NULL, NULL)'
                );
                $stmt->bind_param(
                    'iisssssiissisi',
                    $siteId, $feedId, $scope, $uidHash, $key, $f['level'], $f['detail'], $f['website'],
                    $f['apiOptOut'], $f['from'], $f['to'], $f['overridesPrivateMark'], $f['note'], $userId
                );
                $stmt->execute();
                $choiceId = (int) $db->insert_id;
                $stmt->close();
            } else {
                $choiceId = (int) $locked['choiceID'];
                $stmt     = $db->prepare(
                    'UPDATE tblExternalEventChoices SET audienceLevel = ?, detailLevel = ?, websiteOptIn = ?, '
                    . 'apiOptOut = ?, fromDate = ?, toDate = ?, overridesPrivateMark = ?, note = ?, updatedByID = ?, '
                    . 'updatedAt = UTC_TIMESTAMP() WHERE choiceID = ?'
                );
                $stmt->bind_param(
                    'ssiissisii',
                    $f['level'], $f['detail'], $f['website'], $f['apiOptOut'], $f['from'], $f['to'],
                    $f['overridesPrivateMark'], $f['note'], $userId, $choiceId
                );
                $stmt->execute();
                $stmt->close();
            }

            if ($f['level'] === 'groups') {
                ExternalAudience::save($db, $siteId, $feedId, 'choice', $choiceId, $f['members'], $userId);
            } else {
                ExternalAudience::remove($db, $siteId, $feedId, 'choice', $choiceId);
            }

            $waitingBefore = self::pendingIdsFiledUnder($db, $feedId, 'choice', $choiceId);

            return [
                'ok' => true, 'status' => 'saved', 'choiceId' => $choiceId,
                'savedChoice' => ['choiceID' => $choiceId, 'byUserId' => $userId, 'seen' => $seenMap],
                // The dates of `seen[]` this save was allowed to pass on —
                // only dates this choice covers (the filter above). Returned
                // so the page can say what was recorded, and so the
                // self-test can see this class's OWN filter at work: the
                // resolver ignores a date the choice does not cover as well,
                // so without this nothing would show the filter being lost
                // (fix round 1, checker LOW 6 / fault F16).
                'seenKept' => array_map('intval', array_keys($seenMap)),
            ];
        }, static function (array $done) use ($db, $feedId, $siteId, &$waitingBefore, &$seeBefore, &$seeToday): array {
            // Inside the transaction, after the resolve — READS only (see
            // inFeedLock()): the dates filed under this choice waiting now
            // that were not waiting before, and whose audience really changed.
            $waitingNow = self::pendingIdsFiledUnder($db, $feedId, 'choice', (int) $done['choiceId']);

            return [
                'visibilityChanged' => self::whoCanSeeChanges($seeBefore, self::whoCanSee($db, $feedId, $siteId, $seeToday)),
                'newlyWaitingHere'  => count(array_diff($waitingNow, $waitingBefore)),
            ];
        });

        if ($result['ok'] === false) {
            return $result;
        }

        // 💬 "Saved.", then how many dates REALLY changed who can see them
        //    (whoCanSee()), then the plan's item 2 (section "DECIDED DURING
        //    THE P8-1 CHECKS"); each sentence claims only what its number
        //    counts:
        //    - `approvedBySave`: dates moved from waiting or declined to
        //      APPROVED by this save. "now approved", never "now shown": a
        //      choice whose first day is still ahead has its approval
        //      recorded now (the resolver's step 11b) but shows nothing
        //      until it starts. C10 item 4's purpose is kept: a colleague's
        //      decline is never overturned without anybody being told.
        //    - `newlyWaitingHere`: dates filed under THIS choice that newly
        //      wait. Not the resolver's `newPending`, which is the whole
        //      CALENDAR's and would give this choice's reason ("was not on
        //      the page") to a date a rule had sent back.
        //    THE "CHANGED WHO CAN SEE" COUNT — the owner's decisions of 25
        //    September 2026 (keep it, done properly) and 27 September 2026
        //    (a fast COPY of the visibility rule that compares PEOPLE, with
        //    the self-test's full oracle as the tripwire). It comes from
        //    whoCanSee(), whose docblock says what it copies, line by line,
        //    and records the FOUR ways that were tried and rejected: the
        //    resolver's own `changed`; the owner in every signature; a copy
        //    comparing list entries instead of people; and asking the real
        //    rule about every account (exact, but ~100 s per save for a
        //    large church). Do not bring any of them back.
        //    Singular and plural written out in full (challenge L14).
        $resolved = $result['resolved'];
        $message  = 'Saved.';
        if ($result['visibilityChanged'] > 0) {
            $message .= ($result['visibilityChanged'] === 1)
                ? ' 1 date changed who can see it.'
                : ' ' . $result['visibilityChanged'] . ' dates changed who can see them.';
        }
        if ($resolved['approvedBySave'] > 0) {
            $message .= ($resolved['approvedBySave'] === 1)
                ? ' 1 date that was waiting or declined is now approved.'
                : ' ' . $resolved['approvedBySave'] . ' dates that were waiting or declined are now approved.';
        }
        if ($result['newlyWaitingHere'] > 0) {
            $message .= ($result['newlyWaitingHere'] === 1)
                ? ' 1 date waits for approval because it changed while the page was open or was not on the page.'
                : ' ' . $result['newlyWaitingHere'] . ' dates wait for approval because they changed while the page was open or were not on the page.';
        }
        $result['message'] = $message;

        return $result;
    }

    /**
     * Remove a choice. The dates it covered go back to whatever decides them
     * next, in the resolver's order: a date's own choice, then the choice for
     * every date, then the rules, then the calendar's own setting (C10 item 7;
     * the message says which, see below).
     *
     * A choice for every date can be removed from a date that NO LONGER
     * repeats (fix round 2 of chunk P8-3, check finding F1). The resolver
     * finds that choice by the event's identity alone, so when an outside
     * calendar turns a repeating event into a one-off with the same UID, the
     * old choice keeps deciding the one date that is left — and before this,
     * nothing could remove it. It can still never be MADE for such a date
     * (saveChoice() refuses that scope there): removing is the only action.
     *
     * @return array{ok:bool, status:string, message:string}
     */
    public static function removeChoice(\mysqli $db, int $eventId, string $scope, int $siteId, int $userId): array
    {
        if (EventVisibility::isAdminOfSite($db, $userId, $siteId) === false) {
            return ['ok' => false, 'status' => 'refused', 'message' => self::ACTOR_MESSAGE];
        }
        if ($scope !== 'date' && $scope !== 'series') {
            return ['ok' => false, 'status' => 'not_found', 'message' => self::NOT_FOUND_EVENT];
        }
        $event = self::importedEvent($db, $eventId, $siteId);
        if ($event === null) {
            return ['ok' => false, 'status' => 'not_found', 'message' => self::NOT_FOUND_EVENT];
        }
        $feedId   = (int) $event['feedID'];
        $eventKey = (string) $event['externalRecurrenceKey'];
        $key      = ($scope === 'date') ? $eventKey : '';
        $uidHash  = $event['externalUidHash'];

        return self::inFeedLock($db, $feedId, $siteId, function () use ($db, $feedId, $siteId, $scope, $uidHash, $key, $eventKey, $eventId): array {
            $locked = self::lockedChoice($db, $feedId, $siteId, $scope, $uidHash, $key);
            if ($locked === null) {
                // "Every date" of a date that does not repeat and has no
                // choice for every date left over: there is no such thing to
                // remove, and the answer is the same "not found" it always
                // was (fix round 2 of chunk P8-3).
                if ($scope === 'series' && $eventKey === '') {
                    return ['ok' => false, 'status' => 'not_found', 'message' => self::NOT_FOUND_EVENT];
                }

                return ['ok' => false, 'status' => 'not_found', 'message' => 'There is no choice to remove.'];
            }
            $choiceId = (int) $locked['choiceID'];
            FeedResolver::withdrawForOrigin($db, $feedId, 'choice', $choiceId);
            ExternalAudience::remove($db, $siteId, $feedId, 'choice', $choiceId);
            $stmt = $db->prepare('DELETE FROM tblExternalEventChoices WHERE choiceID = ?');
            $stmt->bind_param('i', $choiceId);
            $stmt->execute();
            $stmt->close();

            // Said the way the resolver decides (fix round 1 of chunk P8-3,
            // check finding L2): a date's own choice first, then the choice
            // for every date, then the rules, then the calendar's own setting
            // — each choice only while it is in force (between its first and
            // last day, when it has them). The first wording, "These dates go
            // back to the rules and the calendar's own setting", was untrue
            // for one date under a choice for every date: that date goes back
            // to the choice for every date.
            // For one date, the sentence is chosen by whether a choice for
            // every date EXISTS for this identity — in force or not, exactly
            // as the resolver finds it, by identity alone — looked up once
            // here, inside the lock, scoped to this calendar and organisation
            // (fix round 2 of chunk P8-3, check finding F1). Fix round 1
            // chose it by whether the date repeats, and said "A date that
            // does not repeat can have no choice for every date". That was
            // untrue: a choice made while the event repeated stays, and keeps
            // deciding the one date left when the outside calendar turns the
            // event into a one-off with the same UID.
            //
            // And whether a covered date is marked PRIVATE or listed TWICE
            // by the outside calendar (the final whole-part check, F1): rules
            // never reach a private date, and a date listed twice can only be
            // narrowed. Read here, inside the lock — the date itself for one
            // date, every live date of the identity for every date — and
            // turned into words by withoutChoiceSentence(), the same words
            // the choice page uses before the button is pressed.
            $seriesExists = ($scope === 'date' && self::lockedChoice($db, $feedId, $siteId, 'series', $uidHash, '') !== null);
            $flags = ($scope === 'date')
                ? self::one(
                    $db,
                    'SELECT MAX(externalPrivate) AS anyPrivate, MAX(externalDuplicate) AS anyTwice FROM tblEvents WHERE eventID = ? AND siteID = ?',
                    'ii',
                    [$eventId, $siteId]
                )
                : self::one(
                    $db,
                    'SELECT MAX(externalPrivate) AS anyPrivate, MAX(externalDuplicate) AS anyTwice FROM tblEvents '
                    . 'WHERE externalFeedID = ? AND siteID = ? AND isDeleted = 0 AND externalUidHash = ?',
                    'iis',
                    [$feedId, $siteId, $uidHash]
                );
            $removed = self::withoutChoiceSentence(
                'removed',
                $scope,
                $seriesExists,
                (int) ($flags['anyPrivate'] ?? 0) === 1,
                (int) ($flags['anyTwice'] ?? 0) === 1
            );

            return ['ok' => true, 'status' => 'saved', 'message' => $removed];
        });
    }

    /**
     * What decides a date once it has no choice of its own (or, for "every
     * date", no choice for every date), in the order the resolver decides —
     * one wording for the three places that say it: the message after
     * Remove (`$context` = 'removed'), the Remove button's confirmation
     * ('confirm'), and the choice page's opening line when there is no such
     * choice ('none').
     *
     * WHY IT EXISTS (the final whole-part check, F1): the sentences used to
     * say "follows the rules and the calendar's own setting" for every date.
     * Two kinds of date do not:
     *   - a date the outside calendar marks PRIVATE starts from "Only
     *     administrators", and rules never reach it (FeedResolver step 2 and
     *     the `$private === false` test before the rules) — only a choice
     *     saved to show it can;
     *   - a date the outside calendar lists TWICE is never shown more widely
     *     by a rule or a choice, only more narrowly (FeedResolver, "Round-1
     *     check FIX B").
     * For one date, `$private` / `$twice` describe that date; for every
     * date, whether ANY covered date is so. `$seriesExists` (one date only):
     * a choice for every date exists for the identity, in force or not.
     *
     * WHAT IT CANNOT DO: say whether a choice for every date is in force
     * today, or whether it was saved to show private dates — so it says
     * "unless … shows it while that choice is in force", which is true
     * either way.
     */
    public static function withoutChoiceSentence(string $context, string $scope, bool $seriesExists, bool $private, bool $twice): string
    {
        $lead = ['removed' => 'Choice removed. ', 'confirm' => 'Remove this choice? ', 'none' => ''][$context] ?? '';
        $now  = ($context === 'removed') ? 'now ' : (($context === 'confirm') ? 'then ' : '');
        if ($scope === 'series') {
            $text = ($context === 'none')
                ? "No choice for every date yet: each date follows its own choice while one is in force, otherwise the rules and the calendar's own setting."
                : $lead . 'Each date ' . $now . "follows its own choice while one is in force, otherwise the rules and the calendar's own setting.";
            if ($private === true) {
                $text .= ' A date the outside calendar marks private is shown only to administrators, unless its own choice shows it.';
            }
            if ($twice === true) {
                $text .= ' A date the outside calendar lists twice can be shown more narrowly by a rule or a choice, but never more widely.';
            }

            return $text;
        }
        if ($private === true) {
            $unless = ($seriesExists === true && $twice === false)
                ? ', unless the choice for every date shows it while that choice is in force'
                : '';
            if ($context === 'none') {
                return ($seriesExists === true ? 'This date has no choice of its own. The outside calendar marks it private' : 'No choice yet: the outside calendar marks this date private')
                    . ', so it is shown only to administrators' . $unless . '.';
            }

            return $lead . 'The outside calendar marks this date private, so it is ' . $now . 'shown only to administrators' . $unless . '.';
        }
        if ($seriesExists === true) {
            $text = ($context === 'none')
                ? "This date has no choice of its own, so the choice for every date decides it while that choice is in force, otherwise the rules and the calendar's own setting."
                : $lead . 'This date ' . $now . "follows the choice for every date while one is in force, otherwise the rules and the calendar's own setting.";
        } else {
            $text = ($context === 'none')
                ? "No choice yet: this date follows the rules and the calendar's own setting."
                : $lead . 'This date ' . $now . "follows the rules and the calendar's own setting.";
        }
        if ($twice === true) {
            $text .= ' The outside calendar lists this date twice, so a rule or a choice can show it more narrowly, but never more widely.';
        }

        return $text;
    }

    /**
     * Save (or add) a rule. `$ruleId = 0` means a new rule.
     *
     * Besides the resolver's own counts (`resolved`), the result carries
     * `visibilityChanged` — the events whose audience really changed (see
     * `whoCanSee()`) — which the message is built from.
     *
     * @param array<string,mixed> $post
     *
     * @return array{ok:bool, status:string, message:string, ruleId?:int}
     */
    public static function saveRule(\mysqli $db, int $feedId, int $ruleId, int $siteId, int $userId, array $post): array
    {
        if (EventVisibility::isAdminOfSite($db, $userId, $siteId) === false) {
            return ['ok' => false, 'status' => 'refused', 'message' => self::ACTOR_MESSAGE];
        }
        $calendar = self::feedRow($db, $feedId, $siteId);
        if ($calendar === null) {
            return ['ok' => false, 'status' => 'not_found', 'message' => self::NOT_FOUND_CALENDAR];
        }
        $existingRule = null;
        if ($ruleId !== 0) {
            $existingRule = self::rule($db, $ruleId, $feedId, $siteId);
            if ($existingRule === null) {
                return ['ok' => false, 'status' => 'not_found', 'message' => self::NOT_FOUND_RULE];
            }
        }
        $currentList = ($existingRule !== null) ? ExternalAudience::current($db, $siteId, $feedId, 'rule', $ruleId) : [];

        $v = self::validateRuleForm($db, $siteId, $post, $currentList);
        if ($v['ok'] === false) {
            return ['ok' => false, 'status' => 'refused', 'message' => $v['message']];
        }
        $f = $v['fields'];

        // Who can see each event BEFORE anything is written (see
        // whoCanSee()), compared after the resolve inside the same
        // transaction.
        $seeBefore = [];
        $seeToday  = '';
        $result    = self::inFeedLock($db, $feedId, $siteId, function () use ($db, $feedId, $siteId, $ruleId, $userId, $f, &$seeBefore, &$seeToday): array {
            $seeToday  = self::ruleToday($db, $siteId);
            $seeBefore = self::whoCanSee($db, $feedId, $siteId, $seeToday);
            if ($ruleId === 0) {
                $stmt = $db->prepare(
                    'INSERT INTO tblExternalFeedRules (siteID, feedID, name, isActive, audienceLevel, detailLevel, '
                    . 'websiteOptIn, apiOptOut, fromDate, toDate, createdByID, createdAt, updatedByID, updatedAt) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), NULL, NULL)'
                );
                $stmt->bind_param(
                    'iisissiissi',
                    $siteId, $feedId, $f['name'], $f['isActive'], $f['level'], $f['detail'], $f['website'],
                    $f['apiOptOut'], $f['from'], $f['to'], $userId
                );
                $stmt->execute();
                $newRuleId = (int) $db->insert_id;
                $stmt->close();
            } else {
                // 🔒 Belt and braces (plan section 4.1): re-check under the
                //    lock that this rule is still this calendar's and
                //    organisation's, even though the caller already checked
                //    it before validating.
                $stmt = $db->prepare('SELECT ruleID FROM tblExternalFeedRules WHERE ruleID = ? AND feedID = ? AND siteID = ? FOR UPDATE');
                $stmt->bind_param('iii', $ruleId, $feedId, $siteId);
                $stmt->execute();
                $found = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($found === null) {
                    return ['ok' => false, 'status' => 'not_found', 'message' => self::NOT_FOUND_RULE];
                }
                $newRuleId = $ruleId;
                $stmt      = $db->prepare(
                    'UPDATE tblExternalFeedRules SET name = ?, isActive = ?, audienceLevel = ?, detailLevel = ?, '
                    . 'websiteOptIn = ?, apiOptOut = ?, fromDate = ?, toDate = ?, updatedByID = ?, '
                    . 'updatedAt = UTC_TIMESTAMP() WHERE ruleID = ?'
                );
                $stmt->bind_param(
                    'sissiissii',
                    $f['name'], $f['isActive'], $f['level'], $f['detail'], $f['website'], $f['apiOptOut'],
                    $f['from'], $f['to'], $userId, $newRuleId
                );
                $stmt->execute();
                $stmt->close();
                $del = $db->prepare('DELETE FROM tblExternalRuleConditions WHERE ruleID = ?');
                $del->bind_param('i', $newRuleId);
                $del->execute();
                $del->close();
            }

            $condStmt = $db->prepare(
                'INSERT INTO tblExternalRuleConditions (ruleID, isException, matchField, matchType, matchValue) VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($f['conditions'] as $condition) {
                $isException = (int) $condition['isException'];
                $condStmt->bind_param(
                    'iisss', $newRuleId, $isException, $condition['matchField'], $condition['matchType'], $condition['matchValue']
                );
                $condStmt->execute();
            }
            $condStmt->close();

            if ($f['level'] === 'groups') {
                ExternalAudience::save($db, $siteId, $feedId, 'rule', $newRuleId, $f['members'], $userId);
            } else {
                ExternalAudience::remove($db, $siteId, $feedId, 'rule', $newRuleId);
            }

            return ['ok' => true, 'status' => 'saved', 'ruleId' => $newRuleId];
        }, static function (array $done) use ($db, $feedId, $siteId, &$seeBefore, &$seeToday): array {
            // A READ only, inside the transaction after the resolve.
            return ['visibilityChanged' => self::whoCanSeeChanges($seeBefore, self::whoCanSee($db, $feedId, $siteId, $seeToday))];
        });

        if ($result['ok'] === false) {
            return $result;
        }

        // 💬 "Saved.", then how many events REALLY changed who can see them
        //    (whoCanSee()), then — the plan's item 3 — how many of the
        //    CALENDAR's events are waiting: `pendingTotal` is every waiting
        //    date of the calendar, not only this rule's, so the sentence says
        //    so.
        //    THE "CHANGED WHO CAN SEE" COUNT — the owner's decisions of 25
        //    September 2026 (keep it, done properly) and 27 September 2026
        //    (a fast COPY of the visibility rule that compares PEOPLE, with
        //    the self-test's full oracle as the tripwire). It comes from
        //    whoCanSee(), whose docblock says what it copies, line by line,
        //    and records the FOUR ways that were tried and rejected: the
        //    resolver's own `changed`; the owner in every signature; a copy
        //    comparing list entries instead of people; and asking the real
        //    rule about every account (exact, but ~100 s per save for a
        //    large church). Do not bring any of them back.
        $resolved = $result['resolved'];
        $message  = 'Saved.';
        if ($result['visibilityChanged'] > 0) {
            $message .= ($result['visibilityChanged'] === 1)
                ? ' 1 event changed who can see it.'
                : ' ' . $result['visibilityChanged'] . ' events changed who can see them.';
        }
        if ($resolved['pendingTotal'] > 0) {
            $message .= ($resolved['pendingTotal'] === 1)
                ? ' 1 event of this calendar is waiting for approval.'
                : ' ' . $resolved['pendingTotal'] . ' events of this calendar are waiting for approval.';
        }
        $result['message'] = $message;

        return $result;
    }

    /**
     * Delete a rule. Waiting rows filed under it are withdrawn, its list
     * goes with it, and its events go back to whatever else applies: any
     * other rule that matches them (the resolver takes the narrowest), or
     * else the calendar's own setting (C10 item 7; the "or any other rule"
     * added in fix round 7, check round 7 L2).
     *
     * @return array{ok:bool, status:string, message:string}
     */
    public static function deleteRule(\mysqli $db, int $ruleId, int $feedId, int $siteId, int $userId): array
    {
        if (EventVisibility::isAdminOfSite($db, $userId, $siteId) === false) {
            return ['ok' => false, 'status' => 'refused', 'message' => self::ACTOR_MESSAGE];
        }
        // 🔎 A plain look-up FIRST, by number AND organisation, exactly as
        //    every other write does before it takes a lock (fix round 1,
        //    checker M1). Without it, another organisation's calendar number
        //    went straight to `inFeedLock()`, whose `SELECT … FOR UPDATE`
        //    takes and holds a lock on that row under MySQL's default
        //    isolation level even though the organisation does not match —
        //    so the request queued behind the other organisation's refresh
        //    and then failed with a lock timeout, while a missing number
        //    answered "not found" at once. That difference in answer, and in
        //    time, told the two cases apart (the #503 rule), and put one
        //    organisation's request in another's queue.
        if (self::feedRow($db, $feedId, $siteId) === null) {
            return ['ok' => false, 'status' => 'not_found', 'message' => self::NOT_FOUND_CALENDAR];
        }

        return self::inFeedLock($db, $feedId, $siteId, function () use ($db, $ruleId, $feedId, $siteId): array {
            $stmt = $db->prepare('SELECT ruleID FROM tblExternalFeedRules WHERE ruleID = ? AND feedID = ? AND siteID = ? FOR UPDATE');
            $stmt->bind_param('iii', $ruleId, $feedId, $siteId);
            $stmt->execute();
            $found = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($found === null) {
                return ['ok' => false, 'status' => 'not_found', 'message' => self::NOT_FOUND_RULE];
            }
            FeedResolver::withdrawForOrigin($db, $feedId, 'rule', $ruleId);
            ExternalAudience::remove($db, $siteId, $feedId, 'rule', $ruleId);
            $del = $db->prepare('DELETE FROM tblExternalFeedRules WHERE ruleID = ?');
            $del->bind_param('i', $ruleId);
            $del->execute();
            $del->close();

            // Not "go back to the calendar's own setting" (fix round 7, check
            // round 7 L2): when another rule also matches an event, the
            // resolver applies the narrowest matching rule, so the event
            // follows THAT rule, not the calendar.
            return ['ok' => true, 'status' => 'saved', 'message' => "Rule deleted. Events it was showing now follow any other rule that matches them, or else the calendar's own setting."];
        });
    }

    /**
     * What a draft rule (typed but not yet saved) would do right now.
     * Validated exactly as `saveRule()` — the same messages — but never
     * writes, never takes the lock, never starts a transaction.
     *
     * @param array<string,mixed> $post
     *
     * @return array{ok:bool, status:string, message:string, lines?:list<array<string,mixed>>, apiOptOut?:int}
     */
    public static function previewRule(\mysqli $db, int $feedId, int $ruleId, int $siteId, int $userId, array $post): array
    {
        if (EventVisibility::isAdminOfSite($db, $userId, $siteId) === false) {
            return ['ok' => false, 'status' => 'refused', 'message' => self::ACTOR_MESSAGE];
        }
        $calendar = self::feedRow($db, $feedId, $siteId);
        if ($calendar === null) {
            return ['ok' => false, 'status' => 'not_found', 'message' => self::NOT_FOUND_CALENDAR];
        }
        if ($ruleId !== 0) {
            // 🪤 Challenge L15: a ruleID from ANOTHER calendar of the same
            //    organisation must be refused too, not only another
            //    organisation's.
            if (self::rule($db, $ruleId, $feedId, $siteId) === null) {
                return ['ok' => false, 'status' => 'not_found', 'message' => self::NOT_FOUND_RULE];
            }
        }

        $currentList = ($ruleId !== 0) ? ExternalAudience::current($db, $siteId, $feedId, 'rule', $ruleId) : [];
        $v           = self::validateRuleForm($db, $siteId, $post, $currentList);
        if ($v['ok'] === false) {
            return ['ok' => false, 'status' => 'refused', 'message' => $v['message']];
        }
        $f = $v['fields'];

        $draft = [
            'ruleID'        => $ruleId,
            'audienceLevel' => $f['level'],
            'detailLevel'   => $f['detail'],
            'websiteOptIn'  => $f['website'],
            'apiOptOut'     => $f['apiOptOut'],
            'fromDate'      => $f['from'],
            'toDate'        => $f['to'],
            'isActive'      => $f['isActive'],
            'conditions'    => $f['conditions'],
            'audience'      => $f['members'],
        ];
        $lines = FeedResolver::previewRule($db, $feedId, $draft, FeedResolver::databaseNowUtc($db));

        // 🕰️ Each line's own time zone and all-day flag (fix round 1 of
        //    chunk P8-3, check finding L1). The resolver's lines carry only
        //    the start as written, so the page printed an all-day event as
        //    "00:00" and dropped a New York event's zone, which then read as
        //    the organisation's own time. ONE read for the whole calendar —
        //    never one per line — scoped to this calendar AND organisation,
        //    exactly like events(). A line whose event is not found (it
        //    cannot be: the resolver read the same calendar a moment ago)
        //    simply keeps no zone and is not all-day.
        $when = [];
        if ($lines !== []) {
            $stmt = $db->prepare('SELECT eventID, timezone, isAllDay FROM tblEvents WHERE externalFeedID = ? AND siteID = ?');
            $stmt->bind_param('ii', $feedId, $siteId);
            $stmt->execute();
            $result = $stmt->get_result();
            while (($row = $result->fetch_assoc()) !== null) {
                $when[(int) $row['eventID']] = [
                    'timezone' => ($row['timezone'] === null) ? null : (string) $row['timezone'],
                    'isAllDay' => (int) $row['isAllDay'],
                ];
            }
            $stmt->close();
        }
        foreach ($lines as &$line) {
            $line['label']    = self::OUTCOME_LABELS[$line['outcome']] ?? $line['outcome'];
            $line['timezone'] = $when[(int) $line['eventID']]['timezone'] ?? null;
            $line['isAllDay'] = $when[(int) $line['eventID']]['isAllDay'] ?? 0;
        }
        unset($line);

        return ['ok' => true, 'status' => 'saved', 'message' => 'What this rule would do now (nothing has been saved)', 'lines' => $lines, 'apiOptOut' => $f['apiOptOut']];
    }

    /**
     * Approve or decline one or many waiting dates. `$items` is a list of
     * `['approvalID' => int, 'requestHash' => string, 'contentHash' => string]`.
     * Grouped by calendar; each calendar's lock is taken exactly once,
     * every item of that calendar decided, then ONE resolve and ONE commit
     * (challenge M2).
     *
     * The message: "Approved N." (or "Declined N."), then any that had
     * changed ("N had changed since you opened the page; please review
     * it/them again."), then any not done ("N could not be decided this
     * time; please try again."), then — ONLY when at least one date really
     * DECIDED here is a date of a repeating event (its recurrence key is
     * not empty) — "Other dates of the same repeating event may follow
     * this decision. The list of waiting dates shows where each one now
     * stands." ("repeating events" when the decided dates come from more
     * than one). Nothing decided, or only one-off dates: no such sentence.
     *
     * WHY IT NO LONGER COUNTS THE OTHER DATES — THE OWNER'S DECISION, 28
     * September 2026 (the plan's top section, item 10; asked directly, the
     * owner chose "one always-true sentence"). The result used to carry
     * `followed`, and the message used to say how many other dates
     * "followed" or "did not follow this decision". Three ways of counting
     * them were built and each was shown untrue — do not add a count back
     * without an answer to all three:
     *   1. "followed = no longer waiting" (the plan's first wording): it
     *      counted dates whose waiting rows the same save WITHDREW (check
     *      round 8, L1).
     *   2. Judging each other date by its OLD row, the one waiting before
     *      the decision (fix rounds 8-10): the same save can withdraw that
     *      row and file a new one, which may wait again (check round 10)
     *      or follow this very decision (check round 11, Q1).
     *   3. Judging each date by its NEWEST row (fix round 11): a date can
     *      hold several live rows at once — a choice saved but not yet
     *      started records an approved row, and decided rows of older
     *      requests stay live — so the newest row does not say where the
     *      date stands (check round 12).
     * What stays true is that another date MAY follow (part 7's sibling
     * rule), and the list of waiting dates shows each one's state.
     * `decided`, `changed` and `notDone` are unchanged.
     *
     * IN THIS ORDER, and why:
     *   1. Checks of the request's own shape that need NO database at all —
     *      the decision word (a wrong one throws, as part 7's
     *      `decideApproval()` does), the note (not valid text, or over
     *      `MAX_DECISION_NOTE` characters → refused; part 7 would otherwise
     *      CUT it, fix round 1 checker M3), and more than `MAX_BULK` dates
     *      (refused, checker LOW 4). None of these can read or reveal
     *      anything, so doing them before the actor check leaks nothing.
     *   2. The actor check (challenge L1).
     *   3. The same date listed twice is decided ONCE (checker LOW 4: it
     *      used to be decided, then counted as "changed since you opened
     *      the page", which was untrue). Anything that is not a proper item
     *      counts as "changed" — the same answer as a row that has gone, so
     *      a tampered form learns nothing.
     *   4. ONE statement finds each item's calendar, scoped to this
     *      organisation. An item it does not return (another
     *      organisation's, or gone) counts as "changed" and never reaches a
     *      lock — so it can never wait in another organisation's lock queue.
     *      The same statement reads the date's recurrence key and identity,
     *      for the "other dates" sentence (fix round 12). Reading them
     *      before the lock is safe: they are what the date IS — the
     *      importer finds an event by them and never rewrites either.
     *   5. Per calendar, `inFeedLock()`.
     *
     * ONE LOCK PER CALENDAR, SO NOT ALL-OR-NOTHING ACROSS CALENDARS (plan
     * section 4.1). If a later calendar fails — its lock times out, or the
     * work throws — AFTER an earlier calendar has committed, that commit is
     * real and is NOT hidden: the result counts what was decided, and
     * `notDone` counts every date of the failed calendar and of the ones
     * not reached, with the sentence "N could not be decided this time;
     * please try again." (checker LOW 4 — the first version threw here, so
     * a page would have said "nothing was changed" when something had).
     * `failure` carries the exception, for the page to RECORD (this class
     * never logs) — never to show. A failure before anything committed is
     * simply thrown: then "nothing was changed" is true.
     *
     * NOTHING touches the database after the last commit (fix round 2,
     * checker round 2 LOW 9). The first version read "how many other dates
     * followed" after all the commits, so a connection lost at exactly
     * that moment would have thrown although decisions were already
     * committed. That read later moved inside the calendar's transaction
     * (`inFeedLock()`'s `$afterResolve`), and since fix round 12 there is
     * no such read at all: the "other dates" sentence needs only step 4's
     * look-up and which items this call decided. So the promise stays
     * simple and true: this method throws only when nothing at all was
     * committed — as far as the connection can tell.
     * One case no client can tell apart (checker round 3): the server
     * commits the FIRST calendar but its reply is lost on the way back;
     * that looks exactly like a failed commit, so it is thrown.
     *
     * A calendar deleted between step 4 and its lock counts its dates as
     * "changed", like any date that has gone.
     *
     * @param list<array{approvalID:int, requestHash:string, contentHash:string}> $items
     *
     * `decidedIds` (chunk P8-4) lists the approval numbers really decided,
     * so the decision handler can write one activity line per decided date
     * (plan section 3.3) — numbers only, never a title.
     *
     * @return array{ok:bool, status:string, message:string, decided:int, changed:int, notDone:int, failure:?\Throwable, decidedIds:list<int>}
     *
     * @throws \InvalidArgumentException When `$decision` is neither `approved` nor `declined` —
     *         checked before any SQL is sent, as `FeedResolver::decideApproval()` does.
     * @throws \Throwable When the FIRST calendar's work fails, before anything was committed.
     */
    public static function decide(\mysqli $db, int $siteId, int $userId, string $decision, string $note, array $items): array
    {
        // `followed` left the result in fix round 12 (the owner's decision —
        // see the docblock); `decided`, `changed` and `notDone` are unchanged.
        $answer = static fn (bool $ok, string $status, string $message, int $decided = 0, int $changed = 0, int $notDone = 0, ?\Throwable $failure = null, array $decidedIds = []): array => [
            'ok' => $ok, 'status' => $status, 'message' => $message, 'decided' => $decided,
            'changed' => $changed, 'notDone' => $notDone, 'failure' => $failure, 'decidedIds' => $decidedIds,
        ];

        // 1️⃣ The request's own shape — no database.
        if ($decision !== 'approved' && $decision !== 'declined') {
            throw new \InvalidArgumentException('FeedAdmin: a decision must be "approved" or "declined".');
        }
        $noteText = trim($note);
        if (preg_match('//u', $noteText) !== 1) {
            return $answer(false, 'refused', 'The note contains characters that cannot be read. Please type it again.');
        }
        if (mb_strlen($noteText, 'UTF-8') > self::MAX_DECISION_NOTE) {
            return $answer(false, 'refused', 'Note can be at most ' . self::MAX_DECISION_NOTE . ' characters.');
        }
        if (count($items) > self::MAX_BULK) {
            return $answer(false, 'refused', 'You can decide at most ' . self::MAX_BULK . ' dates at once.');
        }

        // 2️⃣ Who is asking.
        if (EventVisibility::isAdminOfSite($db, $userId, $siteId) === false) {
            return $answer(false, 'refused', self::ACTOR_MESSAGE);
        }
        if ($items === []) {
            return $answer(true, 'saved', 'Nothing was decided.');
        }

        // 3️⃣ One entry per date; anything malformed counts as "changed".
        $changed = 0;
        $unique  = [];
        foreach ($items as $item) {
            $aid = is_array($item) ? filter_var($item['approvalID'] ?? null, FILTER_VALIDATE_INT) : false;
            $req = is_array($item) ? ($item['requestHash'] ?? null) : null;
            $con = is_array($item) ? ($item['contentHash'] ?? null) : null;
            if ($aid === false || $aid <= 0 || is_string($req) === false || is_string($con) === false) {
                $changed++;
                continue;
            }
            if (isset($unique[$aid]) === true) {
                continue; // listed twice — decided once, never reported "changed"
            }
            $unique[$aid] = ['approvalID' => $aid, 'requestHash' => $req, 'contentHash' => $con];
        }

        // 4️⃣ Each date's calendar, in ONE statement, this organisation only.
        //    The same statement notes which dates belong to a repeating
        //    event (fix round 12): the recurrence key is empty for a
        //    one-off date. LEFT JOIN, so exactly the same dates are found
        //    as before the join was added.
        $feedByApproval   = [];
        $seriesByApproval = []; // approvalID => the repeating event it belongs to, for series dates only
        if ($unique !== []) {
            $approvalIds  = array_keys($unique);
            $placeholders = implode(',', array_fill(0, count($approvalIds), '?'));
            $stmt         = $db->prepare(
                'SELECT a.approvalID, a.feedID, a.eventID, e.externalRecurrenceKey, e.externalUidHash '
                . 'FROM tblExternalEventApprovals a LEFT JOIN tblEvents e ON e.eventID = a.eventID '
                . "WHERE a.siteID = ? AND a.approvalID IN ($placeholders)"
            );
            $types        = 'i' . str_repeat('i', count($approvalIds));
            $params       = array_merge([$siteId], $approvalIds);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            while (($row = $result->fetch_assoc()) !== null) {
                $feedByApproval[(int) $row['approvalID']] = (int) $row['feedID'];
                if ((string) ($row['externalRecurrenceKey'] ?? '') !== '') {
                    // A repeating event is its identity WITHIN its calendar
                    // (two calendars are two events, even with the same
                    // identity). A series date with no identity — which the
                    // importer never makes — counts as its own event.
                    $seriesByApproval[(int) $row['approvalID']] = (int) $row['feedID'] . '|'
                        . ($row['externalUidHash'] === null ? 'event ' . (int) $row['eventID'] : bin2hex((string) $row['externalUidHash']));
                }
            }
            $stmt->close();
        }

        $byFeed = [];
        foreach ($unique as $aid => $item) {
            if (isset($feedByApproval[$aid]) === false) {
                // Another organisation's row, or one that no longer exists —
                // the SAME count as a stale one, so the two look the same
                // from outside (D20).
                $changed++;
                continue;
            }
            $byFeed[$feedByApproval[$aid]][] = $item;
        }

        // 5️⃣ Per calendar: one lock, every item, one resolve, one commit.
        //    Every item of a calendar is decided BEFORE its one resolve, so
        //    no item can be settled by another item of this same request
        //    (part 7's sibling rule runs only in the resolve) and then be
        //    reported as "changed" — the guarantee of challenge M2 that
        //    matters, kept by fix round 12.
        $decidedTotal  = 0;
        $notDone       = 0;
        $failure       = null;
        $committedAny  = false;
        $seriesDecided = []; // the repeating events of the dates really decided (fix round 12)
        $decidedIds    = []; // their approval numbers, added only after each commit (chunk P8-4)
        $feedIds       = array_keys($byFeed);
        foreach ($feedIds as $position => $feedId) {
            $feedItems = $byFeed[$feedId];
            try {
                $result = self::inFeedLock($db, $feedId, $siteId, function () use ($db, $siteId, $decision, $userId, $noteText, $feedItems): array {
                    // Counted here and handed back, never added to the
                    // caller's totals until the commit has happened — a
                    // calendar that then fails must not leave its counts
                    // behind.
                    $decidedIds  = [];
                    $changedHere = 0;
                    foreach ($feedItems as $item) {
                        // siteID is already proven by the look-up in step 4,
                        // and part 7's claim checks it again in its own
                        // UPDATE (FeedResolver.php, decideApproval()).
                        $ok = FeedResolver::decideApproval(
                            $db, (int) $item['approvalID'], $siteId,
                            $decision, $userId, $noteText, (string) $item['requestHash'], (string) $item['contentHash']
                        );
                        if ($ok === true) {
                            $decidedIds[] = (int) $item['approvalID'];
                        } else {
                            $changedHere++;
                        }
                    }

                    return ['ok' => true, 'status' => 'saved', 'decidedIds' => $decidedIds, 'changedHere' => $changedHere];
                });
                // No after-resolve step any more (fix round 12): decide()
                // no longer reads what became of other dates. The hook
                // stays in inFeedLock() for saveChoice() and saveRule().
            } catch (\Throwable $e) {
                if ($committedAny === false) {
                    throw $e; // nothing committed yet — "nothing was changed" is true
                }
                $failure = $e;
                foreach (array_slice($feedIds, $position) as $notReached) {
                    $notDone += count($byFeed[$notReached]);
                }
                break;
            }
            if (($result['ok'] ?? false) === false) {
                // The calendar went between step 4 and its lock.
                $changed += count($feedItems);
                continue;
            }
            $committedAny  = true;
            $decidedTotal += count($result['decidedIds']);
            array_push($decidedIds, ...$result['decidedIds']);
            $changed      += (int) $result['changedHere'];
            foreach ($result['decidedIds'] as $aid) {
                if (isset($seriesByApproval[$aid]) === true) {
                    $seriesDecided[$seriesByApproval[$aid]] = true;
                }
            }
        }

        $verb    = ($decision === 'approved') ? 'Approved' : 'Declined';
        $message = $verb . ' ' . $decidedTotal . '.';
        if ($changed > 0) {
            $message .= ' ' . $changed . ' had changed since you opened the page; please review '
                . ($changed === 1 ? 'it' : 'them') . ' again.';
        }
        if ($notDone > 0) {
            $message .= ' ' . $notDone . ' could not be decided this time; please try again.';
        }
        if ($seriesDecided !== []) {
            // THE OWNER'S DECISION, 28 September 2026 (plan item 10): one
            // sentence that is always true, instead of a count — see the
            // docblock for the three counting methods tried and shown
            // untrue. Only after a date of a repeating event was really
            // decided: never after "Approved 0.", never for one-off dates.
            // "events" when the decided dates come from more than one
            // repeating event (counted per calendar, see step 4).
            $message .= ' Other dates of the same repeating event' . (count($seriesDecided) === 1 ? '' : 's')
                . ' may follow this decision. The list of waiting dates shows where each one now stands.';
        }

        return $answer(true, 'saved', $message, $decidedTotal, $changed, $notDone, $failure, $decidedIds);
    }

    // =========================================================================
    // 🖼️ Display helpers — pure, no database, no clock
    // =========================================================================

    /**
     * A UTC moment (as the database writes it, `Y-m-d H:i:s`), shown in the
     * given zone with its abbreviation (BST/GMT and so on — the
     * abbreviation is what makes the repeated hour on the autumn
     * clock-change night readable). Null, or text that will not parse,
     * reads "never".
     */
    public static function showMoment(?string $utcText, \DateTimeZone $zone): string
    {
        if ($utcText === null || trim($utcText) === '') {
            return 'never';
        }
        $moment = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utcText, new \DateTimeZone('UTC'));
        if ($moment === false) {
            return 'never';
        }

        return $moment->setTimezone($zone)->format('D j M Y, H:i T');
    }

    /**
     * A wall-clock time (as an event stores it) shown exactly as stored —
     * NEVER converted, because the calendar grids print the same stored
     * text and converting here would make the two disagree (the owner's
     * times rule of 23 September 2026). The event's own zone is named
     * alongside it only when it differs from the organisation's.
     */
    public static function showWallClock(string $wall, ?string $zone, bool $allDay, \DateTimeZone $orgZone): string
    {
        $moment = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $wall, new \DateTimeZone('UTC'));
        if ($moment === false) {
            return $wall;
        }
        if ($allDay === true) {
            return $moment->format('D j M Y') . ' (all day)';
        }
        $text     = $moment->format('D j M Y, H:i');
        $zoneName = trim((string) $zone);
        if ($zoneName !== '' && $zoneName !== $orgZone->getName()) {
            $text .= ' (' . $zoneName . ')';
        }

        return $text;
    }

    /**
     * The list page's one-line audience label (C10 item 3): who the
     * calendar shows to, then " · also on the public website" or
     * " · not sent to API keys" when either applies.
     *
     * @param array<string,mixed> $calendarRow From `calendar()` or `calendars()`.
     */
    public static function audienceLabel(array $calendarRow): string
    {
        $level = (string) ($calendarRow['audienceLevel'] ?? '');
        if ($level === 'public') {
            $label = 'Everyone';
            if ((int) ($calendarRow['websiteOptIn'] ?? 0) === 1) {
                $label .= ' · also on the public website';
            }
        } elseif ($level === 'members') {
            $label = 'Members';
        } elseif ($level === 'groups') {
            $list  = array_map(
                static fn (array $m): string => $m['kind'] . ':' . $m['refID'],
                (array) ($calendarRow['list'] ?? [])
            );
            $label = FeedResolver::audienceSummary('groups', $list);
        } else {
            $label = 'Administrators only';
        }
        if ((int) ($calendarRow['apiOptOut'] ?? 0) === 1) {
            $label .= ' · not sent to API keys';
        }

        return $label;
    }

    /**
     * Why an imported event is shown the way it is, in words (plan section
     * 4.3; fix round 1, checker LOW 1 — `SOURCE_LABELS`' own note named
     * this method before it existed). The ONE way a page shows a source.
     *
     * `rule` names the rule — "Rule: Youth events" — or, when the rule has
     * since been deleted (`events()` gives a null `ruleName` then), says so.
     * An event not worked out yet (no source stored, as on a row imported
     * before part 7 ran) reads "Not worked out yet"; a source word this
     * version does not know reads the same, never the raw word.
     */
    public static function sourceLabel(?string $source, ?string $ruleName = null): string
    {
        if ($source === 'rule') {
            $name = trim((string) $ruleName);

            return ($ruleName === null || $name === '') ? 'A rule that has since been deleted' : 'Rule: ' . $name;
        }
        if ($source === null || isset(self::SOURCE_LABELS[$source]) === false) {
            return 'Not worked out yet';
        }

        return self::SOURCE_LABELS[$source];
    }

    /**
     * The warning line for events whose stored answer has run out and is
     * waiting for the scheduled job to work it out again (C10 item 6; plan
     * D18; fix round 1, checker LOW 1). Singular and plural written out,
     * never "event(s)" (challenge L14). An empty string for none, so a page
     * shows nothing rather than "0 events …".
     *
     * A page shows it only for a switched-on calendar — `calendar()` and
     * `calendars()` already give `stale = 0` for a paused one (L9).
     */
    public static function waitingForRefreshMessage(int $count): string
    {
        if ($count <= 0) {
            return '';
        }
        if ($count === 1) {
            return '1 event is waiting for the scheduled refresh to update who can see it.';
        }

        return $count . ' events are waiting for the scheduled refresh to update who can see them.';
    }

    // =========================================================================
    // 🔒 The one locked sequence (plan section 4.2)
    // =========================================================================

    /**
     * Run `$work` inside a transaction holding this calendar's row lock —
     * by NUMBER AND ORGANISATION, so another organisation's calendar of
     * the same number is simply "not found" — then work out who may see
     * its events with the DATABASE's own clock, then commit.
     *
     * `$work` returns a result array; a result with `ok === false` is
     * rolled back and returned exactly as given (nothing written, no
     * resolve). A result with `ok === true` is resolved and committed, and
     * gains a `resolved` key: `FeedResolver::resolveFeed()`'s own return
     * array (`changed`, `newPending`, `pendingTotal`, `approvedBySave`).
     *
     * This is the ONLY place in `FeedAdmin` that calls `resolveFeed()`
     * (C10 item 2) — every write above reaches the resolver through here,
     * never directly.
     *
     * `$afterResolve` (optional; fix round 2) runs INSIDE the same
     * transaction, AFTER the resolve and BEFORE the commit — the self-test
     * pins that order by reading this method's own source (D21). It is
     * given the result so far and returns keys to add to it. It is used for
     * reads that must see what the resolve just did but must not run after
     * the commit: `saveChoice()`'s "dates newly waiting under this choice",
     * and the "who can see it" answer after the save (`whoCanSee()`) in
     * `saveChoice()` and `saveRule()`. (`decide()` used it for "how many
     * other dates followed" until fix round 12, when the owner replaced
     * that count with one always-true sentence; see `decide()`.)
     *
     * ITS RULES (checker round 3, LOW 3):
     * - It must only READ. Anything it wrote would be committed WITHOUT
     *   having been resolved — the one thing this method exists to prevent
     *   (C10 items 1 and 2). Nothing here can enforce that; it is a rule for
     *   whoever writes a hook, and every hook in this class is a SELECT.
     * - It may add NEW keys only. A key the result already has — `ok`,
     *   `status`, `resolved`, or anything `$work` set — is refused with a
     *   `LogicException`, which rolls the whole write back: a hook quietly
     *   replacing `ok` or `resolved` would make a page report something the
     *   write did not do. (`array_merge()` used to let it.)
     * Whatever it throws rolls the whole write back, like anything else
     * inside.
     *
     * @param callable(): array<string,mixed>                          $work
     * @param (callable(array<string,mixed>): array<string,mixed>)|null $afterResolve
     *
     * @return array<string,mixed>
     */
    private static function inFeedLock(\mysqli $db, int $feedId, int $siteId, callable $work, ?callable $afterResolve = null): array
    {
        self::allowTimeForLockedWork();

        $db->begin_transaction();
        try {
            $stmt = $db->prepare('SELECT feedID FROM tblExternalFeeds WHERE feedID = ? AND siteID = ? FOR UPDATE');
            $stmt->bind_param('ii', $feedId, $siteId);
            $stmt->execute();
            $found = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($found === null) {
                $db->rollback();

                return ['ok' => false, 'status' => 'not_found', 'message' => self::NOT_FOUND_CALENDAR];
            }

            $result = $work();
            if (($result['ok'] ?? false) === false) {
                $db->rollback();

                return $result;
            }

            $result['resolved'] = FeedResolver::resolveFeed(
                $db, $feedId, FeedResolver::databaseNowUtc($db), $result['savedChoice'] ?? null
            );
            if ($afterResolve !== null) {
                $extra = $afterResolve($result);
                foreach ($extra as $name => $unused) {
                    if (array_key_exists($name, $result) === true) {
                        throw new \LogicException('FeedAdmin: an after-resolve step may only add new result keys, not replace "' . $name . '".');
                    }
                }
                $result += $extra;
            }
            $db->commit();

            return $result;
        } catch (\Throwable $e) {
            // 🧯 A failed rollback must not hide the ORIGINAL exception —
            //    that is the one that explains what actually went wrong.
            try {
                $db->rollback();
            } catch (\Throwable $ignored) {
                // Nothing more useful can be done here.
            }
            throw $e;
        }
    }

    /**
     * How long PHP may keep going, raised once, before the first lock is
     * taken (fix round 1, checker LOW 12). The one place, so every write
     * gets it — the first version raised it in `decide()` only.
     *
     * Why: a resolve of a large calendar, or a bulk decision across several,
     * can genuinely take longer than PHP's usual thirty seconds for a web
     * request. Running out ends the request with a fatal error nothing can
     * catch — the database then rolls the open transaction back, so
     * nothing is half-written, but the person sees a raw error page.
     *
     * The same rules as `FeedImporter::refresh()`, which now gets them from
     * `TimeLimit::atLeast()` (#563): only RAISED when it is genuinely too low,
     * never lowered, and zero ("no limit", what a command line gives) left
     * alone. Raising
     * only when too low matters, because `set_time_limit()` RESTARTS the
     * count: calling it on every lock of a bulk decision would mean the
     * limit never fired at all.
     *
     * `function_exists()` is asked first here, as `TimeLimit::atLeast()` does.
     * A host that switches `set_time_limit` off (some shared hosts list it in
     * `disable_functions`) makes it, on PHP 8, a function that does not
     * exist — calling it throws an `Error`, which the `@` in front does NOT
     * silence (tested on PHP 8.5.10 with
     * `-d disable_functions=set_time_limit`). `refresh()` used to call it
     * bare and would have stopped with that error on such a host; since
     * #563 it goes through `TimeLimit::atLeast()` and no longer does. This
     * method is deliberately left calling `set_time_limit()` itself, because
     * test D21 counts its calls through `$timeLimitRaises`.
     *
     * WHAT THIS CANNOT DO: on such a host nothing can raise the limit, and
     * a very large resolve can still be cut off at the host's own limit.
     */
    private static function allowTimeForLockedWork(): void
    {
        $currentLimit = (int) ini_get('max_execution_time');
        if ($currentLimit !== 0 && $currentLimit < self::LOCKED_WORK_SECONDS && function_exists('set_time_limit') === true) {
            self::$timeLimitRaises++;
            @set_time_limit(self::LOCKED_WORK_SECONDS);
        }
    }

    /**
     * How many times this process has called `set_time_limit()` from
     * `allowTimeForLockedWork()`. Read ONLY by the self-test, through
     * reflection (fix round 2, checker round 2 LOW 5).
     *
     * Why it exists: the one thing that method must NOT do — call
     * `set_time_limit()` again when the limit is already high enough — would
     * RESTART PHP's count, so a long bulk decision could run past any limit.
     * But a restart leaves no trace PHP can read: `max_execution_time` shows
     * the same number either way, and PHP offers no way to ask how much of
     * the limit is left. Proving it by letting the limit run out would take
     * 90 seconds of processor time per check. This counter is the smallest
     * thing that makes the rule observable. It changes nothing else.
     */
    private static int $timeLimitRaises = 0;

    // =========================================================================
    // ✅ Validation (plan section 5) — nothing here writes anything
    // =========================================================================

    /**
     * The calendar form (plan section 5.1). `$stored` is the calendar's row
     * as it is now (from `feedRow()`), or null for a brand-new calendar.
     *
     * THE ADDRESS (fix round 1, checker M4 and LOW 2). The address check
     * (`SafeFetch::check()`, which may look a name up on the internet) runs
     * ONLY when the posted address is a DIFFERENT address from the stored
     * one: when the two are byte-for-byte the same, or both tidy
     * (`SafeFetch::normaliseUrl()`) to the same spelling, the address is
     * UNCHANGED, the stored text is kept exactly as it is, and nothing is
     * looked up. A new calendar (`$stored === null`) is always checked.
     *   Why: the first version checked on every save, so a calendar whose
     *   host had stopped answering, or whose name now pointed somewhere
     *   private, could not even be NARROWED from Everyone to Members
     *   without also changing its address — and a calendar saved by the
     *   released `feeds-save.php` with an address in a spelling this
     *   version would tidy differently counted every rename as "the
     *   address changed", sending approved dates back for approval.
     *   `fields.urlChecked` says which case this was; `saveCalendar()`
     *   relies on it under the lock.
     * WHAT SKIPPING THE CHECK CANNOT DO: it never lets a download reach a
     * private network. The importer checks the address again at EVERY
     * refresh, before anything is fetched (`FeedImporter::afterClaim()`
     * calls `SafeFetch::check()`, and `SafeFetch::get()` checks every
     * redirect and connects only to the addresses it approved), so an
     * unchanged address that has turned bad is refused there and recorded
     * as a failed refresh. This form only stops refusing unrelated edits
     * because of it.
     *
     * An address over 2000 characters gets its own sentence (checker LOW
     * 9), not the "private or internal network" one it used to get.
     * `SafeFetch` counts the limit in BYTES, the same as characters for an
     * address typed in plain letters — which is every address a browser
     * sends, because anything else is sent %-encoded — so this counts
     * bytes too, and the two can never disagree about where the line is.
     *
     * THE CATEGORY (fix round 1, checker LOW 3 — the same trap L7 fixed for
     * time zones). The calendar's STORED default category is accepted
     * unchanged even after it has been switched off, as long as it still
     * belongs to this organisation; otherwise one retired category would
     * block every later save of the calendar with "Choose a category from
     * the list.". A NEW choice must still be one of the organisation's
     * active categories.
     *
     * @param array<string,mixed>                                              $post
     * @param list<array{kind:string, refID:int, label:string, state:string}>  $currentList
     * @param array<string,mixed>|null                                         $stored
     *
     * @return array{ok:bool, message?:string, fields?:array<string,mixed>}
     */
    private static function validateCalendarForm(\mysqli $db, int $siteId, array $post, array $currentList, ?array $stored): array
    {
        $name = self::checkName((string) ($post['name'] ?? ''), 'Please give the calendar a name.');
        if ($name['ok'] === false) {
            return ['ok' => false, 'message' => $name['message']];
        }

        $rawUrl = trim((string) ($post['url'] ?? ''));
        if (strlen($rawUrl) > self::MAX_ADDRESS_LENGTH) {
            return ['ok' => false, 'message' => 'Address can be at most ' . self::MAX_ADDRESS_LENGTH . ' characters.'];
        }
        if ($stored !== null && self::sameAddress($rawUrl, (string) $stored['url']) === true) {
            // Unchanged: keep the stored text byte for byte, look nothing up.
            $url        = (string) $stored['url'];
            $urlChecked = false;
        } else {
            $normalisedUrl = SafeFetch::normaliseUrl($rawUrl);
            if ($normalisedUrl === null) {
                return ['ok' => false, 'message' => SafeFetch::REFUSED_MESSAGE];
            }
            $checked = SafeFetch::check($normalisedUrl);
            if ($checked['ok'] !== true) {
                return ['ok' => false, 'message' => SafeFetch::REFUSED_MESSAGE];
            }
            $url        = $normalisedUrl;
            $urlChecked = true;
        }

        $every = filter_var($post['fetchEveryMins'] ?? null, FILTER_VALIDATE_INT);
        if ($every === false || $every < 15 || $every > 10080) {
            return ['ok' => false, 'message' => 'Choose between 15 minutes and 7 days (10080 minutes).'];
        }

        $storedZone = ($stored === null || $stored['timezone'] === null) ? null : (string) $stored['timezone'];
        $zone       = trim((string) ($post['timezone'] ?? ''));
        if ($zone !== '' && $zone !== $storedZone && in_array($zone, \DateTimeZone::listIdentifiers(), true) === false) {
            return ['ok' => false, 'message' => 'Choose a time zone from the list.'];
        }

        $categoryIdRaw = trim((string) ($post['categoryID'] ?? ''));
        $categoryId    = null;
        if ($categoryIdRaw !== '') {
            $categoryId       = (ctype_digit($categoryIdRaw) === true) ? (int) $categoryIdRaw : 0;
            $storedCategoryId = ($stored === null || $stored['categoryID'] === null) ? null : (int) $stored['categoryID'];
            if ($categoryId > 0 && $categoryId === $storedCategoryId) {
                // Unchanged (LOW 3): still this organisation's, switched on or not.
                $valid = self::one(
                    $db,
                    'SELECT categoryID FROM tblEventCategories WHERE categoryID = ? AND siteID = ? LIMIT 1',
                    'ii',
                    [$categoryId, $siteId]
                ) !== null;
            } else {
                $valid = false;
                foreach (self::categories($db, $siteId) as $c) {
                    if ($c['categoryID'] === $categoryId) {
                        $valid = true;
                        break;
                    }
                }
            }
            if ($valid === false) {
                return ['ok' => false, 'message' => 'Choose a category from the list.'];
            }
        }

        $level = (string) ($post['audienceLevel'] ?? '');
        if (in_array($level, ['public', 'members', 'groups'], true) === false) {
            return ['ok' => false, 'message' => 'Choose who can see its events.'];
        }

        $listResult = ExternalAudience::fromPost($db, $siteId, $post, $currentList);
        if ($listResult['ok'] === false) {
            return ['ok' => false, 'message' => $listResult['message']];
        }
        if ($level === 'groups' && $listResult['members'] === []) {
            return ['ok' => false, 'message' => 'Choose at least one group or person.'];
        }

        $website   = ($level === 'public' && (int) ($post['websiteOptIn'] ?? 0) === 1) ? 1 : 0;
        $apiOptOut = ((int) ($post['apiOptOut'] ?? 0) === 1) ? 1 : 0;

        return [
            'ok'     => true,
            'fields' => [
                'name'           => $name['value'],
                'url'            => $url,
                'urlChecked'     => $urlChecked,
                'fetchEveryMins' => $every,
                'timezone'       => $zone,
                'categoryID'     => $categoryId,
                'audienceLevel'  => $level,
                'website'        => $website,
                'apiOptOut'      => $apiOptOut,
                'members'        => $listResult['members'],
            ],
        ];
    }

    /**
     * Are these two texts the SAME calendar address? True when they are
     * byte-for-byte equal, or when both tidy (`SafeFetch::normaliseUrl()`)
     * to the same spelling — "HTTPS://Example.org/a.ics#x" and
     * "https://example.org/a.ics" are one address. An address that cannot
     * be tidied at all equals only itself, byte for byte.
     */
    private static function sameAddress(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        $na = SafeFetch::normaliseUrl($a);
        if ($na === null) {
            return false;
        }

        return $na === SafeFetch::normaliseUrl($b);
    }

    /**
     * A calendar or rule name: trimmed; refused when it is not valid text,
     * when nothing is left once spaces and invisible characters are taken
     * away (fix round 1, checker LOW 9 — a name of zero-width spaces used to
     * be accepted, and would show on every page as a blank), or when it is
     * longer than its column. The name is STORED as typed (trimmed); the
     * resolver's own `FeedResolver::normaliseText()` is used only to decide
     * whether anything visible is left, never to change what is saved.
     *
     * @return array{ok:bool, message:string, value:string}
     */
    private static function checkName(string $raw, string $emptyMessage): array
    {
        $name = trim($raw);
        if ($name === '') {
            return ['ok' => false, 'message' => $emptyMessage, 'value' => ''];
        }
        if (preg_match('//u', $name) !== 1) {
            return ['ok' => false, 'message' => 'The name contains characters that cannot be read. Please type it again.', 'value' => ''];
        }
        if (FeedResolver::normaliseText($name) === '') {
            return ['ok' => false, 'message' => $emptyMessage, 'value' => ''];
        }
        if (mb_strlen($name, 'UTF-8') > 120) {
            return ['ok' => false, 'message' => 'Name can be at most 120 characters.', 'value' => ''];
        }

        return ['ok' => true, 'message' => '', 'value' => $name];
    }

    /**
     * The "who / detail / website / API / From / To" block shared by the
     * choice form and the rule form (plan sections 5.2/5.3 — "as 5.2").
     *
     * @param array<string,mixed>                                              $post
     * @param list<array{kind:string, refID:int, label:string, state:string}>  $currentList
     *
     * @return array{ok:bool, message?:string, fields?:array<string,mixed>}
     */
    private static function validateAudienceBlock(\mysqli $db, int $siteId, array $post, array $currentList): array
    {
        $level = (string) ($post['audienceLevel'] ?? '');
        if (in_array($level, ['public', 'members', 'groups', 'hidden'], true) === false) {
            return ['ok' => false, 'message' => 'Choose who can see it.'];
        }

        $listResult = ExternalAudience::fromPost($db, $siteId, $post, $currentList);
        if ($listResult['ok'] === false) {
            return ['ok' => false, 'message' => $listResult['message']];
        }
        if ($level === 'groups' && $listResult['members'] === []) {
            return ['ok' => false, 'message' => 'Choose at least one group or person.'];
        }

        $detail = (string) ($post['detailLevel'] ?? 'basic');
        if (in_array($detail, ['basic', 'full'], true) === false) {
            return ['ok' => false, 'message' => 'Choose how much detail to show.'];
        }

        $website   = ($level === 'public' && (int) ($post['websiteOptIn'] ?? 0) === 1) ? 1 : 0;
        $apiOptOut = ((int) ($post['apiOptOut'] ?? 0) === 1) ? 1 : 0;

        $from = self::parseYmd($post['fromDate'] ?? null);
        if ($from['ok'] === false) {
            return ['ok' => false, 'message' => 'Enter the dates as real calendar dates.'];
        }
        $to = self::parseYmd($post['toDate'] ?? null);
        if ($to['ok'] === false) {
            return ['ok' => false, 'message' => 'Enter the dates as real calendar dates.'];
        }
        if ($from['value'] !== null && $to['value'] !== null && $to['value'] < $from['value']) {
            return ['ok' => false, 'message' => 'The last day cannot be before the first day.'];
        }

        return [
            'ok'     => true,
            'fields' => [
                'level' => $level, 'detail' => $detail, 'website' => $website, 'apiOptOut' => $apiOptOut,
                'from' => $from['value'], 'to' => $to['value'], 'members' => $listResult['members'],
            ],
        ];
    }

    /**
     * @param array<string,mixed>       $post
     * @param list<array<string,mixed>> $currentList
     * @param list<array<string,mixed>> $covered     From `datesCovered()`.
     *
     * @return array{ok:bool, message?:string, fields?:array<string,mixed>}
     */
    private static function validateChoiceForm(\mysqli $db, int $siteId, array $post, array $currentList, array $covered): array
    {
        $block = self::validateAudienceBlock($db, $siteId, $post, $currentList);
        if ($block['ok'] === false) {
            return $block;
        }
        $fields = $block['fields'];

        $note = trim((string) ($post['note'] ?? ''));
        if (preg_match('//u', $note) !== 1) {
            // Not valid text: the database would refuse it outright (strict
            // mode), which the page could only show as a failure.
            return ['ok' => false, 'message' => 'The note contains characters that cannot be read. Please type it again.'];
        }
        if (mb_strlen($note, 'UTF-8') > 255) {
            return ['ok' => false, 'message' => 'Note can be at most 255 characters.'];
        }

        $anyPrivate = false;
        foreach ($covered as $c) {
            if ((int) ($c['externalPrivate'] ?? 0) === 1) {
                $anyPrivate = true;
                break;
            }
        }
        $overridesPrivateMark = 0;
        if ($anyPrivate === true && $fields['level'] !== 'hidden') {
            if ((int) ($post['overridesPrivateMark'] ?? 0) !== 1) {
                return ['ok' => false, 'message' => self::PRIVATE_WARNING_MESSAGE];
            }
            $overridesPrivateMark = 1;
        }

        $fields['note']                  = ($note === '') ? null : $note;
        $fields['overridesPrivateMark']  = $overridesPrivateMark;

        return ['ok' => true, 'fields' => $fields];
    }

    /**
     * @param array<string,mixed>       $post
     * @param list<array<string,mixed>> $currentList
     *
     * @return array{ok:bool, message?:string, fields?:array<string,mixed>}
     */
    private static function validateRuleForm(\mysqli $db, int $siteId, array $post, array $currentList): array
    {
        $nameCheck = self::checkName((string) ($post['name'] ?? ''), 'Please give the rule a name.');
        if ($nameCheck['ok'] === false) {
            return ['ok' => false, 'message' => $nameCheck['message']];
        }
        $name = $nameCheck['value'];

        $block = self::validateAudienceBlock($db, $siteId, $post, $currentList);
        if ($block['ok'] === false) {
            return $block;
        }
        $fields = $block['fields'];

        $isActive = ((int) ($post['isActive'] ?? 0) === 1) ? 1 : 0;

        $rawConditions = (array) ($post['conditions'] ?? []);
        $ordinary      = [];
        $exceptions    = [];
        foreach ($rawConditions as $row) {
            if (is_array($row) === false) {
                continue;
            }
            $rawValue = trim((string) ($row['matchValue'] ?? ''));
            if ($rawValue === '') {
                continue; // a spare row — the form always offers a few, ignored silently
            }

            $field = (string) ($row['matchField'] ?? '');
            $type  = (string) ($row['matchType'] ?? '');
            if (in_array($field, ['category', 'title', 'location'], true) === false
                || in_array($type, ['equals', 'contains', 'word'], true) === false) {
                return ['ok' => false, 'message' => 'Choose a field and a way of matching from the lists.'];
            }
            if (preg_match('//u', $rawValue) !== 1) {
                return ['ok' => false, 'message' => 'One of the values contains characters that cannot be read. Please type it again.'];
            }
            $normalised = FeedResolver::normaliseText($rawValue);
            if ($normalised === null || $normalised === '') {
                return ['ok' => false, 'message' => 'One of the values is empty once spaces and invisible characters are removed.'];
            }
            if ($field === 'category' && $type !== 'equals') {
                return ['ok' => false, 'message' => "A category condition can only use 'is exactly'."];
            }
            if (mb_strlen($rawValue, 'UTF-8') > 255) {
                return ['ok' => false, 'message' => 'Value can be at most 255 characters.'];
            }

            $isException = ((int) ($row['isException'] ?? 0) === 1);
            $condition   = [
                'isException' => $isException ? 1 : 0, 'matchField' => $field, 'matchType' => $type, 'matchValue' => $rawValue,
            ];
            if ($isException === true) {
                $exceptions[] = $condition;
            } else {
                $ordinary[] = $condition;
            }
        }

        if (count($ordinary) > self::MAX_CONDITIONS || count($exceptions) > self::MAX_CONDITIONS) {
            return ['ok' => false, 'message' => 'A rule can have at most 10 conditions and 10 exceptions.'];
        }
        if ($ordinary === []) {
            return ['ok' => false, 'message' => 'A rule needs at least one condition that events must match.'];
        }

        $conditions = array_merge($ordinary, $exceptions);
        $assembled  = ['audienceLevel' => $fields['level'], 'detailLevel' => $fields['detail'], 'conditions' => $conditions];
        if (FeedResolver::ruleIsValid($assembled) === false) {
            // A guard, not a reachable case: every fault this could catch is
            // already refused above. D14 proves it stays unreachable.
            return ['ok' => false, 'message' => 'This rule could not be saved as written.'];
        }

        $fields['name']       = $name;
        $fields['isActive']   = $isActive;
        $fields['conditions'] = $conditions;

        return ['ok' => true, 'fields' => $fields];
    }

    /** A real `Y-m-d` date, or null for empty. `checkdate()` refuses `2026-02-30`. */
    private static function parseYmd(mixed $value): array
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return ['ok' => true, 'value' => null];
        }
        // `\z` like every other pattern part 8 checks (chunk P8-4 fix round 1);
        // here it changes nothing, since trim() above has already removed a
        // final line break.
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $text, $m) !== 1) {
            return ['ok' => false, 'value' => null];
        }
        if (checkdate((int) $m[2], (int) $m[3], (int) $m[1]) === false) {
            return ['ok' => false, 'value' => null];
        }

        return ['ok' => true, 'value' => $text];
    }

    // =========================================================================
    // 🔧 Small private reads used by more than one method above
    // =========================================================================

    /**
     * WHO CAN SEE each live event of one calendar, as one short text per
     * event, so a save can count the events whose audience really changed:
     * read before the save's writes, and again after the resolve, inside the
     * same lock and transaction (saveChoice(), saveRule()).
     *
     * ⚠️ THIS IS A COPY OF THE PORTAL'S VISIBILITY RULE, ON PURPOSE.
     * `EventVisibility` is the rule every page uses. This method repeats, in
     * PHP and one SQL statement, what that rule reads. The owner chose this
     * on 27 September 2026 ("fast copy + automatic tripwire") for SPEED:
     * asking the real rule itself about every account measured about 7 to
     * 9.5 seconds per save at 300 events x 500 accounts, and about 93 to 102
     * seconds at 1,000 events x 2,000 accounts, all of it inside the
     * calendar's lock — and shared hosting stops a page after 30 to 60
     * seconds. This copy, measured the same way on the same machine: 0.03
     * to 0.11 seconds per whole save at 1,000 x 2,000 (0.02 to 0.06 at 300 x
     * 500) — see "Cost" below.
     *
     * WHAT CATCHES IT DRIFTING: the self-test's D17 checks
     * (tools/feed-admin-selftest.php, `pa_full()` and `pa_oracle()`) ask the
     * REAL rule, before and after each save, about every account of its
     * made-up world in every mode, and make TWO comparisons with this copy:
     *   1. the COUNT — this method's number of changed events must equal
     *      the number of events whose real answer changed for anybody;
     *   2. the ANSWER ITSELF (added in fix round 5, and now the main
     *      protection) — for every event, before and after the save, this
     *      method's signature must equal the real rule's answers put into
     *      the same words (`pa_copySig()` against `pa_realSigs()`); for
     *      Selected groups that is the exact set of account numbers, one by
     *      one, so a person counted wrongly cannot hide behind somebody else
     *      on the same event who also moves.
     * The owner has approved running them on every pull request (27
     * September 2026). So: ANY CHANGE TO `EventVisibility`'s RULE MUST BE
     * MATCHED HERE, and the self-test will say so if it is not — as long as
     * its made-up world holds the kind of person the change is about.
     *
     * PART 10 MUST EXTEND THAT WORLD. The `role`, `user_group` and
     * `department` kinds match nobody today (below). When part 10 makes them
     * match people, it must change this copy AND add to the self-test's
     * made-up world (`pa_resetWorld()`) a current holder, a former one and
     * one in a switched-off grouping of each new kind — without them the
     * oracle cannot see a copy that ignores those kinds, exactly as the old
     * seven-viewer panel could not see role holders.
     *
     * THE OWNER'S TWO DECISIONS: 25 September 2026 — keep the "changed who
     * can see it" sentence, done properly; 27 September 2026 — do it with
     * this fast copy, with the full oracle as the tripwire.
     *
     * FOUR WAYS THAT WERE TRIED AND REJECTED — do not bring any of them back:
     *   1. The resolver's own `changed` (fix round 1). It counts every event
     *      whose stored answer was REWRITTEN, including one that only started
     *      waiting or only shows more detail (check round 2, LOW 6).
     *   2. A signature carrying each event's OWNER — the calendar, rule or
     *      choice its answer came from (fix round 2). A rule that changed
     *      nothing for anybody counted every event it touched (check round 3,
     *      M1: "20 events changed who can see them" for a no-op rule).
     *   3. A copy that compared the list's ENTRIES, not the people they let
     *      in (fix round 3). Naming someone already in a listed group,
     *      tidying away a switched-off group, a former member, or a role
     *      nobody holds, each counted as a change though nobody's access
     *      moved (check round 4, M1, cases B1-B6).
     *   4. Asking the real rule about every account (fix round 4, part B as
     *      first decided). Exact, but far too slow — the numbers above.
     *
     * THE RULE IT COPIES — `EventVisibility.php` as of fix round 4, line
     * numbers given so the two can be read side by side:
     *   - The calendar must exist, belong to this organisation and be
     *     switched on, or NOBODY sees the event, administrators included
     *     (line 619, inside every branch). Signature: "nobody".
     *   - Past `importRecheckAt` (line 564), or a level other than public,
     *     members or groups: only administrators (the general branch, lines
     *     589-600; the other modes, 566-588, test the level and freshness
     *     too). Signature: "admins".
     *   - "public": everyone signed in or out (line 596); the public website
     *     only with `importWebsite = 1` (566-572); API keys only with the
     *     event's `importApiOptOut = 0` (573-584) AND the calendar's own
     *     `apiOptOut = 0` (616, tested live). Signature: the level, the
     *     website box, and whether keys receive it.
     *   - "members": active members of this organisation (597, the member
     *     test 235-238) and the newsletter list (585-588); never the website
     *     or keys, so neither box counts. Signature: the level alone.
     *   - "groups": the members the list lets in (598, the audience piece
     *     254-270). The list is read by `importAudienceType` /
     *     `importAudienceID` AND the organisation (256), never by calendar.
     *     Signature: the sorted ACCOUNT NUMBERS it lets in, worked out with
     *     the rule's own conditions — an ACTIVE account with an ACTIVE
     *     membership row here (235-238) who is named on the list (257), or
     *     an 'active' member of an ACTIVE small group of this organisation
     *     (258-261), or the holder of an active term — started on or before
     *     `$today`, not ended before it — of an ACTIVE leadership role of
     *     this organisation (262-265). The `role`, `user_group` and
     *     `department` kinds match nobody (266-268), so they add nobody here.
     *     A list that lets NOBODY in reads "admins": at "groups" with nobody
     *     on it, only administrators see the event in session and invite
     *     mode and nobody in any other — exactly the "admins" answer, so
     *     hiding such an event, or giving a hidden one such a list, is not a
     *     change (the self-test has a case for it).
     *   - ADMINISTRATORS (222-227) see every live event of a switched-on
     *     calendar in session and invite mode, whatever its level, so they
     *     never change a signature. In token mode the administrator branch
     *     is off (the admin() helper, line 716 on) and they are treated like
     *     anyone else — which the people set already covers.
     *   - OUTSIDERS need no entry of their own. An account that is not an
     *     active global administrator and has no active membership row here
     *     gets EXACTLY the "nobody signed in" answer in every mode: the
     *     member test (235-238) and the site-administrator test (225) both
     *     need an active membership row of this organisation, and the list
     *     is only consulted together with the member test (598). So an
     *     outsider's answer changes exactly when the signed-out visitor's
     *     does, which the level and the boxes already show.
     * NOT part of "who can see it": how much detail is shown (title only or
     * full), the category, the private mark (which only affects detail),
     * and the waiting/declined bookkeeping.
     *
     * WHAT IT CANNOT SEE: something ELSE changing a membership, a group or a
     * role term in the same fraction of a second as the save. Both readings
     * run inside one transaction; under MySQL's default isolation
     * (REPEATABLE READ) they see the same memberships, so only this save's
     * own writes can differ between them. Under READ COMMITTED such a
     * change could be counted — a real change of who can see the event,
     * just not one this save made. The calendar's own boxes cannot change
     * between the readings: they run under the calendar's lock.
     *
     * `$today` is the date the rule is given for role terms. Pages pass
     * `date('Y-m-d')` after bootstrap.php has set PHP to the organisation's
     * zone; ruleToday() gives the date from the database's clock in the
     * organisation's zone, so it does not depend on which zone PHP happens
     * to be in. The two agree ONLY when `site.timezone` is spelled exactly
     * as a name in `timezone_identifiers_list()` — see ruleToday() for the
     * exception. It is worked out ONCE per save and used for both readings,
     * so a save that runs across midnight compares like with like.
     *
     * HOW THE SELF-TEST CHECKS THIS DATE (D17, fix rounds 5 and 6): it pins
     * the database clock at three moments, all in the organisation's zone
     * (Europe/London in the test) and all in the previous year:
     *   - 00:30 on 15 July (summer time): UTC and every zone BEHIND London
     *     are still on the previous day, so a date taken in UTC or a zone
     *     behind fails; zones ahead give the same date and pass;
     *   - 00:30 on the night the clocks go back — still summer time, 90
     *     minutes BEFORE the change: the same as the first, on that night;
     *   - 23:30 that same Sunday — winter time, AFTER the change: every zone
     *     AHEAD of London (Europe/Berlin, a fixed +01:00, the test
     *     connection's own +13:00 that `CURDATE()` would use) is already on
     *     the next day and fails; UTC equals London then and passes.
     * What is NOT tested: the change hour itself (01:00-02:00 that night,
     * when London's clock repeats an hour), and the night the clocks go
     * forward. Neither can move the DATE: both changes happen after 01:00,
     * well away from midnight.
     *
     * Cost, in real statements sent to the database: per reading, one for
     * the events (with their calendar), and — only when some event is at
     * "groups" — ONE for the people of every list in use; two readings per
     * save. Plus, once per save, ruleToday()'s two: `SELECT UTC_TIMESTAMP()`
     * (FeedResolver::databaseNowUtc()) and the organisation's
     * `site.timezone` setting (FeedResolver::organisationZone(), which is
     * not cached). Never one per event or per person.
     *
     * Against the version before it (the one that compared list entries,
     * built in fix round 3), this costs a save 2 statements more: the
     * people statement took the place of the old list statement one for
     * one, and ruleToday() adds its two. The figures and where they come
     * from:
     *   - fix round 4 said "three more, 48 -> 51". That was wrong: it read
     *     MySQL's session counters, which count every prepared SELECT
     *     twice (once as an execute, once as a select);
     *   - check round 5 listed the statements themselves, from MySQL's own
     *     statement history (performance_schema): 70 entries against 66,
     *     which is 2 statements, since each prepared statement shows as
     *     three entries (prepare, execute, close) and a plain query as one;
     *   - fix round 4 also timed whole saves (a MySQL 8.0.36 on a
     *     developer's Mac, not the shared host): 0.03 to 0.11 seconds at
     *     1,000 events x 2,000 accounts, 0.02 to 0.06 at 300 x 500; check
     *     round 5 measured 0.03 to 0.22 seconds on a busier machine.
     * The people statement binds 6 values per list in use, so a calendar
     * would need over 10,000 Selected-groups lists in use to reach MySQL's
     * limit of 65,535.
     *
     * @return array<int,string> eventID => signature
     */
    private static function whoCanSee(\mysqli $db, int $feedId, int $siteId, string $today): array
    {
        // 1️⃣ Every live event of the calendar, with its calendar (the
        //    calendar test, EventVisibility.php 619; freshness, 564).
        $stmt = $db->prepare(
            'SELECT e.eventID, e.importLevel, e.importWebsite, e.importApiOptOut, e.importAudienceType, e.importAudienceID, '
            . '(e.importRecheckAt IS NULL OR e.importRecheckAt > UTC_TIMESTAMP()) AS inDate, '
            . 'f.isActive AS calendarOn, f.apiOptOut AS calendarApiOptOut '
            . 'FROM tblEvents e LEFT JOIN tblExternalFeeds f ON f.feedID = e.externalFeedID AND f.siteID = e.siteID '
            . 'WHERE e.externalFeedID = ? AND e.siteID = ? AND e.isDeleted = 0'
        );
        $stmt->bind_param('ii', $feedId, $siteId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // 2️⃣ The lists in use: those of the events really at "groups" (on a
        //    switched-on calendar, answer still in date). Keyed by owner TYPE
        //    and number together — a choice and a rule can share a number.
        $owners = [];
        foreach ($rows as $r) {
            if ((int) ($r['calendarOn'] ?? 0) === 1 && (int) $r['inDate'] === 1 && $r['importLevel'] === 'groups') {
                $owners[(string) $r['importAudienceType'] . ':' . (int) $r['importAudienceID']] = [(string) $r['importAudienceType'], (int) $r['importAudienceID']];
            }
        }

        // 3️⃣ The PEOPLE each of those lists lets in — one statement for all
        //    of them. Three ways onto a list (EventVisibility.php 257-265),
        //    joined into one set per list (UNION also drops a person reached
        //    two ways), then each person must be an active account with an
        //    active membership row HERE (the member test, 235-238).
        //    tblSmallGroupMembers is written out in full, with no short name,
        //    for the same reason as in EventVisibility (the "Group" inside its
        //    name trips tools/audit-checks/check_sql_columns.py).
        $people = [];
        if ($owners !== []) {
            $tests       = implode(' OR ', array_fill(0, count($owners), '(am.ownerType = ? AND am.ownerID = ?)'));
            $ownerTypes  = '';
            $ownerParams = [];
            foreach ($owners as [$type, $id]) {
                $ownerTypes   .= 'si';
                $ownerParams[] = $type;
                $ownerParams[] = $id;
            }
            $stmt = $db->prepare(
                'SELECT x.ownerType, x.ownerID, x.userID FROM ('
                . "SELECT am.ownerType, am.ownerID, am.userID FROM tblExternalAudienceMembers am WHERE am.siteID = ? AND am.kind = 'person' AND ($tests)"
                . ' UNION '
                . 'SELECT am.ownerType, am.ownerID, tblSmallGroupMembers.userID FROM tblExternalAudienceMembers am '
                . "JOIN tblSmallGroupMembers ON tblSmallGroupMembers.groupID = am.refID AND tblSmallGroupMembers.siteID = am.siteID AND tblSmallGroupMembers.status = 'active' "
                . 'JOIN tblSmallGroups sg ON sg.groupID = tblSmallGroupMembers.groupID AND sg.siteID = am.siteID AND sg.isActive = 1 '
                . "WHERE am.siteID = ? AND am.kind = 'small_group' AND ($tests)"
                . ' UNION '
                . 'SELECT am.ownerType, am.ownerID, la.userID FROM tblExternalAudienceMembers am '
                . 'JOIN tblLeadershipAssignments la ON la.roleID = am.refID AND la.siteID = am.siteID AND la.isActive = 1 '
                . 'AND (la.startDate IS NULL OR la.startDate <= ?) AND (la.endDate IS NULL OR la.endDate >= ?) '
                . 'JOIN tblLeadershipRoles lr ON lr.roleID = la.roleID AND lr.siteID = am.siteID AND lr.isActive = 1 '
                . "WHERE am.siteID = ? AND am.kind = 'leadership_role' AND ($tests)"
                . ') x '
                . 'JOIN tblUsers u ON u.userID = x.userID AND u.isActive = 1 '
                . 'JOIN tblUserSites us ON us.userID = x.userID AND us.siteID = ? AND us.isActive = 1 '
                . 'ORDER BY x.ownerType, x.ownerID, x.userID'
            );
            $types  = 'i' . $ownerTypes . 'i' . $ownerTypes . 'ssi' . $ownerTypes . 'i';
            $params = array_merge([$siteId], $ownerParams, [$siteId], $ownerParams, [$today, $today, $siteId], $ownerParams, [$siteId]);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            while (($m = $result->fetch_assoc()) !== null) {
                $people[(string) $m['ownerType'] . ':' . (int) $m['ownerID']][] = (int) $m['userID'];
            }
            $stmt->close();
        }

        // One text per list, built once and shared by every event using it
        // (PHP shares an unchanged string, so 1,000 events on one list cost
        // one copy of it, not a thousand). A list that lets nobody in is the
        // "admins" answer — see the docblock.
        $listSig = [];
        foreach ($owners as $k => $unused) {
            $listSig[$k] = (($people[$k] ?? []) === []) ? 'admins' : 'groups|' . implode(',', $people[$k]);
        }

        // 4️⃣ One signature per event.
        $out = [];
        foreach ($rows as $r) {
            $level = (string) ($r['importLevel'] ?? '');
            if ((int) ($r['calendarOn'] ?? 0) !== 1) {
                $sig = 'nobody';
            } elseif ((int) $r['inDate'] !== 1 || in_array($level, ['public', 'members', 'groups'], true) === false) {
                $sig = 'admins';
            } elseif ($level === 'public') {
                $keys = ((int) $r['importApiOptOut'] === 0 && (int) $r['calendarApiOptOut'] === 0) ? 1 : 0;
                $sig  = 'public|website=' . (int) $r['importWebsite'] . '|keys=' . $keys;
            } elseif ($level === 'members') {
                $sig = 'members';
            } else {
                $sig = $listSig[(string) $r['importAudienceType'] . ':' . (int) $r['importAudienceID']];
            }
            $out[(int) $r['eventID']] = $sig;
        }

        return $out;
    }

    /**
     * The date the visibility rule is given as "today" (it decides whether a
     * leadership-role term has started or ended): the database's own clock,
     * in the organisation's own zone. See whoCanSee() for why not PHP's
     * `date()`. Throws, like `FeedResolver::databaseNowUtc()`, when the
     * database cannot be asked — inside a save that rolls the save back.
     *
     * WHAT IT CANNOT PROMISE (check round 5, LOW 1): this is the date the
     * PAGES give the rule only when the organisation's `site.timezone` is
     * spelled exactly as a name in PHP's `timezone_identifiers_list()`.
     * bootstrap.php switches PHP to the organisation's zone only for such an
     * exact name; FeedResolver::organisationZone(), used here, trims the
     * value and accepts any spelling PHP's DateTimeZone understands. So with
     * an unusual spelling — `europe/london`, or a leading space — the pages
     * stay on UTC while this uses London, and the two dates differ for as
     * long as local midnight and UTC midnight are apart (an hour a day in a
     * London summer). In that hour a term that ended "yesterday" by one and
     * "today" by the other is judged differently. That disagreement between
     * bootstrap.php and the resolver is tracked by #549; neither is changed
     * here.
     */
    private static function ruleToday(\mysqli $db, int $siteId): string
    {
        return FeedResolver::databaseNowUtc($db)->setTimezone(FeedResolver::organisationZone($siteId))->format('Y-m-d');
    }

    /**
     * How many events differ between two `whoCanSee()` answers.
     *
     * @param array<int,string> $before
     * @param array<int,string> $after
     */
    private static function whoCanSeeChanges(array $before, array $after): int
    {
        $n = 0;
        foreach ($before + $after as $eventId => $unused) {
            if (($before[$eventId] ?? null) !== ($after[$eventId] ?? null)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * The waiting approval rows filed under one rule or choice of one
     * calendar (their `origin`/`originID`), as numbers.
     *
     * @return list<int>
     */
    private static function pendingIdsFiledUnder(\mysqli $db, int $feedId, string $origin, int $originId): array
    {
        $stmt = $db->prepare(
            "SELECT approvalID FROM tblExternalEventApprovals WHERE feedID = ? AND origin = ? AND originID = ? AND status = 'pending'"
        );
        $stmt->bind_param('isi', $feedId, $origin, $originId);
        $stmt->execute();
        $ids = array_map(static fn (array $r): int => (int) $r['approvalID'], $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        $stmt->close();

        return $ids;
    }

    private static function feedRow(\mysqli $db, int $feedId, int $siteId): ?array
    {
        $stmt = $db->prepare(
            'SELECT feedID, siteID, name, url, fetchEveryMins, timezone, categoryID, audienceLevel, websiteOptIn, '
            . 'apiOptOut, isActive, lastFetchedAt, nextFetchAt, lastFetchOk, lastFetchMessage, lastContentHash, '
            . 'consecutiveFailures, lastRunCapped FROM tblExternalFeeds WHERE feedID = ? AND siteID = ? LIMIT 1'
        );
        $stmt->bind_param('ii', $feedId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row;
    }

    private static function waitingCount(\mysqli $db, int $feedId, int $siteId): int
    {
        $row = self::one($db, "SELECT COUNT(*) AS n FROM tblExternalEventApprovals WHERE feedID = ? AND siteID = ? AND status = 'pending'", 'ii', [$feedId, $siteId]);

        return (int) ($row['n'] ?? 0);
    }

    private static function staleCount(\mysqli $db, int $feedId, int $siteId): int
    {
        $row = self::one(
            $db,
            'SELECT COUNT(*) AS n FROM tblEvents WHERE externalFeedID = ? AND siteID = ? AND isDeleted = 0 '
            . 'AND importRecheckAt IS NOT NULL AND importRecheckAt <= UTC_TIMESTAMP()',
            'ii',
            [$feedId, $siteId]
        );

        return (int) ($row['n'] ?? 0);
    }

    private static function newestRunStartedAt(\mysqli $db, int $feedId, int $siteId): ?string
    {
        $row = self::one($db, 'SELECT startedAt FROM tblExternalFeedRuns WHERE feedID = ? AND siteID = ? ORDER BY startedAt DESC, runID DESC LIMIT 1', 'ii', [$feedId, $siteId]);

        return $row === null ? null : (string) $row['startedAt'];
    }

    private static function conditionsOf(\mysqli $db, int $ruleId): array
    {
        $stmt = $db->prepare('SELECT conditionID, isException, matchField, matchType, matchValue FROM tblExternalRuleConditions WHERE ruleID = ? ORDER BY conditionID');
        $stmt->bind_param('i', $ruleId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    /**
     * A date's approval state for the choice page's list: "waiting", or
     * "declined" with when — or nothing.
     *
     * "Declined" only when the NEWEST live decision about the date is a
     * decline (the final whole-part check, F3). The first version took the
     * newest decline of ANY request, so a date declined under one request
     * and since shown under a newer, APPROVED request (a rule edited, say)
     * still read "Declined on …" beside a date everyone could see — decided
     * rows of older requests stay live by design (plan item 10), so the old
     * decline was still there to find. Ties on the decision time go to the
     * higher approval number, the newer row.
     *
     * WHAT IT CANNOT SAY: that the date is declined NOW. An older approval
     * of another request can be the one in force again (a rule edited back
     * to what was approved before), and this method does not know which
     * request the date's stored answer comes from. So the choice page words
     * the badge as history — "A request was declined on …" — which is true
     * either way (the last narrow check before part 8's commit).
     *
     * @return array{state:string, declinedAt:?string}
     */
    private static function approvalStateFor(\mysqli $db, int $eventId): array
    {
        $pending = self::one($db, "SELECT 1 AS x FROM tblExternalEventApprovals WHERE eventID = ? AND status = 'pending' LIMIT 1", 'i', [$eventId]);
        if ($pending !== null) {
            return ['state' => 'waiting', 'declinedAt' => null];
        }
        $newest = self::one(
            $db,
            "SELECT status, decidedAt FROM tblExternalEventApprovals WHERE eventID = ? AND status IN ('approved', 'declined') "
            . 'ORDER BY decidedAt DESC, approvalID DESC LIMIT 1',
            'i',
            [$eventId]
        );
        if ($newest !== null && (string) $newest['status'] === 'declined') {
            return ['state' => 'declined', 'declinedAt' => (string) $newest['decidedAt']];
        }

        return ['state' => '', 'declinedAt' => null];
    }

    private static function lockedChoice(\mysqli $db, int $feedId, int $siteId, string $scope, string $uidHash, string $key): ?array
    {
        $stmt = $db->prepare(
            'SELECT choiceID FROM tblExternalEventChoices '
            . 'WHERE feedID = ? AND siteID = ? AND scope = ? AND externalUidHash = ? AND externalRecurrenceKey = ? FOR UPDATE'
        );
        $stmt->bind_param('iisss', $feedId, $siteId, $scope, $uidHash, $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row;
    }

    /**
     * Every posted category word grouped by how the DATABASE compares them
     * (`utf8mb4_general_ci`) rather than how PHP does (challenge M6). Only
     * ONE statement, the exact shape plan section 5.4 sets out, because the
     * table's own unique key uses this collation and PHP's string
     * comparison does not agree with it (accented and plain letters compare
     * equal, a trailing space is ignored).
     *
     * One row comes back per posted word, with the smallest index of its
     * group worked out by a window function — never a `GROUP_CONCAT` list
     * (fix round 7, check round 7 L1). The first version joined each
     * group's indexes with GROUP_CONCAT, which MySQL cuts at 1,024 bytes by
     * default: a hand-made post of 299 "cafe" and one "café" slipped past
     * the twin refusal. With one row per word there is nothing to cut.
     *
     * What it cannot do: it groups only the POSTED words. A twin already
     * STORED under another spelling is not in the list; the save finds it
     * through the same collation when it writes, and the message then names
     * the stored spelling. And it relies on the column's collation being
     * `utf8mb4_general_ci`; if that ever changes, this grouping must change
     * with it, or it will disagree with the table's unique key again.
     *
     * @param list<string> $words
     *
     * @return list<list<int>> Each inner list is the INDEXES into `$words`
     *         that the database treats as one word. Groups of one are
     *         included too, for a simpler caller.
     */
    private static function findTwinGroups(\mysqli $db, array $words): array
    {
        if ($words === []) {
            return [];
        }
        $selects = [];
        $types   = '';
        $params  = [];
        foreach ($words as $i => $w) {
            $selects[] = 'SELECT ? AS w, ? AS i';
            $types    .= 'si';
            $params[]  = $w;
            $params[]  = $i;
        }
        $sql = 'SELECT t.i AS i, MIN(t.i) OVER (PARTITION BY t.w COLLATE utf8mb4_general_ci) AS g FROM ('
            . implode(' UNION ALL ', $selects) . ') t';
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $groups = [];
        while (($row = $result->fetch_assoc()) !== null) {
            $groups[(int) $row['g']][] = (int) $row['i'];
        }
        $stmt->close();
        foreach ($groups as $g => $members) {
            sort($members);
            $groups[$g] = $members;
        }
        ksort($groups);

        return array_values($groups);
    }

    /**
     * One row, or null. `$types`/`$params` follow `mysqli_stmt::bind_param()`.
     *
     * @param list<int|string> $params
     *
     * @return array<string,mixed>|null
     */
    private static function one(\mysqli $db, string $sql, string $types, array $params): ?array
    {
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row;
    }
}
