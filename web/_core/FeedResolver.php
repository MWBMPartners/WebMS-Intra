<?php
// Path: _core/FeedResolver.php
/**
 * -----------------------------------------------------------------------------
 * Who may see each copied-in event, worked out once and written down 👁️🗓️
 * -----------------------------------------------------------------------------
 * A copied-in event is one the portal downloaded from somebody else's
 * calendar (a Google, Microsoft 365 or other published calendar file). Who
 * may see it is NOT decided when somebody looks at it. It is decided here,
 * once, after every refresh (and after every administrator decision that
 * could change it), and the answer is written into the event's own
 * `import*` columns. `Portal\Core\EventVisibility` then only has to read
 * those columns.
 *
 * WHY THE ANSWER IS WORKED OUT IN ADVANCE RATHER THAN WHEN SOMEBODY LOOKS
 * ---------------------------------------------------------------------
 * Working it out on every page view would mean running the whole set of
 * rules below inside every calendar query, for every event in the list. On
 * shared hosting that is the difference between a calendar page that opens
 * and one that times out. Writing the answer down also means an
 * administrator can be SHOWN why an event is visible ("because of the
 * calendar's own setting", "because you chose it for this date") instead of
 * being told to work it out themselves.
 *
 * The cost of writing an answer down is that it can go out of date. That is
 * what `importRecheckAt` is for: a moment after which the stored answer is
 * not trusted, and only administrators see the event until this runs again.
 * So the failure is closed, never open. The scheduled job's recheck pass
 * (`FeedImporter::recheckDue()`) is what runs it again on time.
 *
 * -----------------------------------------------------------------------------
 * THE FULL SET OF RULES — ALL SEVEN STEPS ARE BUILT (parts P6 and P7 of #514)
 * -----------------------------------------------------------------------------
 * The #514 plan (section 1.8, as corrected by `.claude-work/resume/
 * p514-p7--plan.md` section C4) sets out seven steps. For each live event:
 *
 *   1. DUPLICATE. If the last download held this identity twice
 *      (`externalDuplicate = 1`): a choice or rule that would NARROW the
 *      calendar's own setting still applies (round-1 check FIX B, 24
 *      September 2026 — narrowing must always win, the owner's standing
 *      principle, the same reasoning as the "Don't show via API" box
 *      below). A choice or rule that would WIDEN it is ignored and the
 *      calendar's own setting stands, and NO approval row is ever created
 *      for a duplicate either way — when two events in one file claim to be
 *      the same event there is no way to tell which one an administrator's
 *      per-event choice was really about, so a duplicate is never asked to
 *      decide anything, only ever narrowed by what already applies to it.
 *      Before this fix every duplicate fell back to the calendar's own
 *      setting regardless of direction, which let an administrator's own
 *      hide be silently undone by the outside calendar listing the date
 *      twice.
 *   2. PRIVATE WITH NO CHOICE. An event the outside calendar marked private
 *      starts at "administrators only" and stays there unless an
 *      administrator deliberately chose otherwise for that event.
 *   3. AN ACTIVE CHOICE for this exact date, or failing that for the whole
 *      repeating event. A choice outside its dates is treated as absent.
 *   4. ACTIVE RULES, only when no choice applied. Several rules combine to
 *      the NARROWEST answer (fail safe).
 *   5. IS THE ANSWER WIDER than the calendar's own setting?
 *   6. IF IT IS WIDER, an administrator has to agree to it first (an
 *      approval row), unless they saw exactly this date on the choice page
 *      when they saved it. Later dates of a repeating event follow an
 *      identical date that was already decided (the "sibling decision").
 *   7. WRITE the answer, and only when it differs from what is already
 *      stored — so an unchanged refresh does not touch a single row.
 *
 * Alongside the level, every event also gets `importApiOptOut`: 1 when the
 * calendar or ANY choice or rule that applies to it has "Don't show via
 * API" ticked (the owner's decision of 24 September 2026). That box can only
 * narrow, so it never waits for approval and is not part of any fingerprint.
 *
 * -----------------------------------------------------------------------------
 * HOW THE CODE IS SPLIT: LOAD, WORK OUT, WRITE
 * -----------------------------------------------------------------------------
 *   * LOAD (`loadFeedState()`) reads every input for one calendar in a FIXED
 *     number of statements, however many events it has.
 *   * WORK OUT (`plan()`) is pure: no database, no clock. It takes what was
 *     loaded and "now", and returns the answer for every event plus the list
 *     of changes to the approval rows.
 *   * WRITE (inside `resolveFeed()` only) applies them.
 * The rule preview an administrator sees (`previewRule()`) runs the very
 * same LOAD and WORK OUT and simply never writes. So the preview and the
 * real thing cannot disagree — which is why this part was built that way.
 *
 * -----------------------------------------------------------------------------
 * WHAT THIS CLASS DOES NOT DO, AND MUST NOT START DOING
 * -----------------------------------------------------------------------------
 * - It never decides whether ONE person may see ONE event. That is
 *   `EventVisibility`, which reads what this writes. Two places deciding the
 *   same thing is how a portal ends up disagreeing with itself.
 * - It never widens anything on its own. The widest answer it can produce
 *   without an administrator's agreement is the calendar's own setting,
 *   which an administrator chose.
 * - It never takes the lock and never starts a transaction. Every caller
 *   must already hold the calendar's row lock (`SELECT feedID FROM
 *   tblExternalFeeds WHERE feedID = ? FOR UPDATE`) inside its own
 *   transaction. That is what stops a refresh, a pause and an
 *   administrator's save from interleaving (#514 leak-hunt finding 13).
 * - It cannot notice a change of the organisation's time zone by itself.
 *   Stored `importRecheckAt` moments keep the old zone's day boundaries until
 *   each calendar is next worked out (within about twenty hours), so a date
 *   window can end up to the difference between the two zones early or late
 *   in that interval. Settings saves do not re-work every calendar; that
 *   belongs with the portal-wide time work in #549.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   2.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/514
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use DateTimeImmutable;
use DateTimeZone;

final class FeedResolver
{
    /**
     * The neutral name every repeating copied-in event is given.
     *
     * It is deliberately the same words for every calendar and every event.
     * A series name is printed on administrator pages that do not otherwise
     * check who may see an imported event, so putting the outside calendar's
     * own wording in it would leak the title of an event that may be hidden.
     * Kept here rather than in the importer because both parts need to agree
     * on it, and because the reason belongs beside the rule it protects.
     */
    public const IMPORTED_SERIES_NAME = 'Repeating imported event';

    /**
     * Every word `previewRule()` can answer with, in the order they are
     * tested (the first that applies wins). The administrator pages (part P8)
     * give each one a plain label; `duplicate_not_applied` and
     * `declined_before` were added in part P7 because the #514 plan's list
     * had no word for either case.
     */
    public const PREVIEW_OUTCOMES = [
        'rule_not_active_yet_or_ended',
        'duplicate_not_applied',
        'private_not_applied',
        'covered_by_choice',
        'conflicting_group_rules_hidden',
        'another_rule_is_stricter',
        'declined_before',
        'waits_for_approval',
        'applies_now',
    ];

    /** How wide each level is. hidden < groups < members < public. */
    private const LEVEL_RANK = ['hidden' => 0, 'groups' => 1, 'members' => 2, 'public' => 3];

    /** The database's own way of writing a moment. */
    private const SQL_MOMENT = 'Y-m-d H:i:s';

    /** The note written on a date approved because it was seen and saved. */
    private const NOTE_SEEN = 'Approved by saving the choice with this date shown';

    // =========================================================================
    // 🔄 resolveFeed() — work out and store the answer for one calendar
    // =========================================================================

    /**
     * Work out, and store, who may see each live event of one calendar.
     *
     * The caller must already be inside a transaction holding this
     * calendar's row lock. This method takes no lock of its own and starts
     * no transaction, on purpose: it is called from the middle of a refresh
     * that is already holding one, and starting a second would either fail
     * or quietly commit the refresh's half-finished work.
     *
     * @param \mysqli           $db          The connection.
     * @param int               $feedId      The calendar.
     * @param DateTimeImmutable $nowUtc      "Now" as a UTC moment. EVERY caller
     *                                       must pass the DATABASE's clock
     *                                       (`databaseNowUtc()`), because the
     *                                       decision times written here are
     *                                       compared with the ones
     *                                       `decideApproval()` writes with
     *                                       `UTC_TIMESTAMP()` ("the newest
     *                                       decision wins"), and two clocks
     *                                       would make that comparison
     *                                       meaningless.
     * @param array|null        $savedChoice Set only by the choice page's save
     *                                       (part P8): `['choiceID' => int,
     *                                       'byUserId' => int, 'seen' =>
     *                                       array<int eventID, string Cf>]` —
     *                                       the dates the administrator was
     *                                       shown, each with the full-detail
     *                                       fingerprint (`fullContentHash()`)
     *                                       of what they were shown. Used ONLY
     *                                       when the choice belongs to this
     *                                       calendar, and only for this
     *                                       calendar's events, so a tampered
     *                                       entry can do nothing.
     *
     * @return array{changed:int, newPending:int, pendingTotal:int, approvedBySave:int}
     *         `changed` = events whose stored answer was rewritten.
     *         `newPending` = approval rows waiting at the end that were not
     *         waiting before (what a notification says is new).
     *         `pendingTotal` = every approval row of this calendar waiting at
     *         the end. `approvedBySave` = dates moved from waiting or
     *         declined to approved because the administrator saw them on the
     *         choice page and saved (0 whenever `$savedChoice` is null); the
     *         choice page reports it, so a colleague's decline is never
     *         overturned without anybody being told.
     */
    public static function resolveFeed(
        \mysqli $db,
        int $feedId,
        DateTimeImmutable $nowUtc,
        ?array $savedChoice = null
    ): array {
        $state = self::loadFeedState($db, $feedId);
        if ($state === null) {
            // The calendar is gone. Nothing of it should be visible, and the
            // visibility rule already answers "no" for an event whose
            // calendar row does not exist, so there is nothing to write.
            return ['changed' => 0, 'newPending' => 0, 'pendingTotal' => 0, 'approvedBySave' => 0];
        }

        $zone = self::organisationZone((int) $state['feed']['siteID']);
        $plan = self::plan($state, $nowUtc, $zone, $savedChoice);

        $changed = self::writePlan($db, $state, $plan, $nowUtc);

        return [
            'changed'        => $changed,
            'newPending'     => $plan['counts']['newPending'],
            'pendingTotal'   => $plan['counts']['pendingTotal'],
            'approvedBySave' => $plan['counts']['approvedBySave'],
        ];
    }

    // =========================================================================
    // 🔭 previewRule() — what a rule WOULD do, without doing it
    // =========================================================================

    /**
     * What a draft rule would do to each event of the calendar, right now,
     * without writing anything.
     *
     * It loads exactly what `resolveFeed()` loads, puts the draft in place
     * of the saved rule with the same `ruleID` (or adds it with the number 0
     * when it is new — a new rule has no approval rows, so 0 can never match
     * one), and runs the very same `plan()`. So the preview cannot promise
     * something the real save would not do.
     *
     * The draft: `ruleID` (0 = new), `audienceLevel`, `detailLevel`,
     * `websiteOptIn`, `apiOptOut`, `fromDate`, `toDate` (Y-m-d or null),
     * `isActive` (default 1), `conditions` (a list of `isException`,
     * `matchField`, `matchType`, `matchValue`), and optionally `audience` (a
     * list of `['kind' => …, 'refID' => …]`; when absent, the saved rule's own
     * list is used).
     *
     * Only events the DRAFT matches are listed — valid, every condition,
     * no exception — whatever its dates, in start order. A draft that is
     * not valid (no ordinary condition, an empty or unreadable value, an
     * unknown level) matches nothing and returns an empty list. The words
     * are about the LEVEL only; the administrator page shows the draft's own
     * "Don't show via API" box in words beside the list.
     *
     * @return list<array{eventID:int, title:string, startDateTime:string, outcome:string}>
     */
    public static function previewRule(\mysqli $db, int $feedId, array $draftRule, DateTimeImmutable $nowUtc): array
    {
        $state = self::loadFeedState($db, $feedId);
        if ($state === null) {
            return [];
        }
        $zone  = self::organisationZone((int) $state['feed']['siteID']);
        $draft = self::normaliseDraft($draftRule, $state);

        // 🔁 The draft replaces the saved rule of the same number. A draft
        //    that is switched off takes the saved rule away and adds nothing,
        //    which is exactly what saving it would do.
        $rules = [];
        foreach ($state['rules'] as $rule) {
            if ((int) $rule['ruleID'] !== (int) $draft['ruleID']) {
                $rules[] = $rule;
            }
        }
        if ((int) $draft['isActive'] === 1) {
            $rules[] = $draft;
        }
        $state['rules'] = $rules;
        $state['audience']['rule'][(int) $draft['ruleID']] = $draft['list'];

        $plan = self::plan($state, $nowUtc, $zone, null);

        $draftActive = ((int) $draft['isActive'] === 1) && self::windowActive($draft, $nowUtc, $zone);
        $draftLevel   = (string) $draft['audienceLevel'];
        $draftWebsite = ((int) $draft['websiteOptIn'] === 1 && $draftLevel === 'public') ? 1 : 0;

        $lines = [];
        foreach ($state['events'] as $event) {
            $eventId = (int) $event['eventID'];
            if (self::ruleMatches($draft, $event, $state['tags'][$eventId] ?? []) === false) {
                continue;
            }
            $why = $plan['explain'][$eventId];

            if ($draftActive === false) {
                $outcome = 'rule_not_active_yet_or_ended';
            } elseif ($why['duplicate'] === true && $why['state'] !== 'applied') {
                // Round-1 check FIX B (24 September 2026): a duplicate whose
                //    candidate NARROWS the calendar's own setting is applied
                //    at once by plan() — see the header note above step 6 in
                //    plan(). This label is only for a duplicate that stayed
                //    at the base: a private mark blocked it, or a widening
                //    candidate had to be ignored. Without the state check
                //    here, a narrowing draft on a duplicate would be called
                //    "not applied" when plan() actually applies it — and the
                //    class header's own promise is that "the preview and the
                //    real thing cannot disagree".
                $outcome = 'duplicate_not_applied';
            } elseif ($why['private'] === true) {
                $outcome = 'private_not_applied';
            } elseif ($why['choiceDecides'] === true) {
                $outcome = 'covered_by_choice';
            } elseif ($why['conflict'] === true) {
                $outcome = 'conflicting_group_rules_hidden';
            } elseif ($why['combined'] === null
                || $why['combined']['level'] !== $draftLevel
                || $why['combined']['detail'] !== (string) $draft['detailLevel']
                || (int) $why['combined']['website'] !== $draftWebsite) {
                $outcome = 'another_rule_is_stricter';
            } elseif ($why['state'] === 'declined') {
                $outcome = 'declined_before';
            } elseif ($why['state'] === 'waiting') {
                $outcome = 'waits_for_approval';
            } else {
                $outcome = 'applies_now';
            }

            $lines[] = [
                'eventID'       => $eventId,
                'title'         => (string) $event['eventName'],
                'startDateTime' => (string) $event['startDateTime'],
                'outcome'       => $outcome,
            ];
        }

        // 📅 Start order, then event number, so the list reads like a diary
        //    and two events at the same moment always come out the same way.
        usort($lines, static function (array $a, array $b): int {
            return [$a['startDateTime'], $a['eventID']] <=> [$b['startDateTime'], $b['eventID']];
        });

        return $lines;
    }

    // =========================================================================
    // 🧾 Fingerprints, decisions and removals — used by the part P8 pages
    // =========================================================================

    /**
     * The FULL-detail content fingerprint (`Cf`) of one event: its title,
     * category, description, location and link. Dates and times are left
     * out on purpose (D14: a moved date is not a reason to ask again).
     *
     * The choice page (part P8) builds its `seen` list with this, from the
     * row's stored `categoryID`, so the page and the resolver can never work
     * it out two different ways. It is the full form whatever detail the
     * choice asks for: stricter than needed at "title, date and time only",
     * which is the safe direction.
     *
     * @param array<string,mixed> $eventRow Needs eventName, description,
     *                                      locationName and externalUrl.
     */
    public static function fullContentHash(array $eventRow, ?int $categoryId): string
    {
        return self::contentHash($eventRow, $categoryId, 'full');
    }

    /**
     * An administrator changed a calendar's ADDRESS: every widening decided
     * for the old address has to be decided again (#514 leak-hunt finding
     * 7c), because the new address may be a different calendar altogether.
     *
     * For each LIVE event whose current request (worked out now, read-only)
     * has an `approved` row, a `pending` copy is inserted with reason
     * `address_changed`. Then every `approved` and `declined` row of the
     * calendar becomes `superseded` — the old decisions stay in the history
     * (who decided, and when) rather than being overwritten, and none of them
     * can carry to another date through the sibling decision.
     *
     * Only live events under their CURRENT request are copied: a
     * soft-deleted date's approval, or an old request kept as history, would
     * only be withdrawn again by the next resolve, and counting them would
     * overstate how many events went back for approval.
     *
     * The caller holds the lock, passes `databaseNowUtc()`, and calls
     * `resolveFeed()` straight afterwards.
     *
     * @return int How many widened events went back for approval.
     */
    public static function onAddressChanged(\mysqli $db, int $feedId, DateTimeImmutable $nowUtc): int
    {
        $state = self::loadFeedState($db, $feedId);
        if ($state === null) {
            return 0;
        }
        $zone = self::organisationZone((int) $state['feed']['siteID']);
        $plan = self::plan($state, $nowUtc, $zone, null);

        $copyIds = [];
        foreach ($state['approvals'] as $row) {
            if ((string) $row['status'] !== 'approved') {
                continue;
            }
            $eventId = (int) $row['eventID'];
            $current = $plan['explain'][$eventId]['request'] ?? null;
            if ($current !== null && hash_equals($current, (string) $row['requestHash']) === true) {
                $copyIds[] = (int) $row['approvalID'];
            }
        }

        $nowText = self::utcText($nowUtc);
        if ($copyIds !== []) {
            $copy = $db->prepare(
                'INSERT INTO tblExternalEventApprovals (siteID, feedID, eventID, origin, originID, requestHash, '
                . 'contentHash, status, reason, requestedLevel, requestedDetail, requestedWebsite, '
                . 'requestedAudienceSummary, snapTitle, snapStart, snapEnd, snapTimezone, snapIsAllDay, '
                . 'snapCategoryID, snapDescription, snapLocation, snapUrl, decidedByID, decidedAt, decisionNote, '
                . 'createdAt, updatedAt) '
                . "SELECT siteID, feedID, eventID, origin, originID, requestHash, contentHash, 'pending', "
                . "'address_changed', requestedLevel, requestedDetail, requestedWebsite, requestedAudienceSummary, "
                . 'snapTitle, snapStart, snapEnd, snapTimezone, snapIsAllDay, snapCategoryID, snapDescription, '
                . 'snapLocation, snapUrl, NULL, NULL, NULL, ?, NULL '
                . 'FROM tblExternalEventApprovals WHERE approvalID = ?'
            );
            foreach ($copyIds as $approvalId) {
                $copy->bind_param('si', $nowText, $approvalId);
                $copy->execute();
            }
            $copy->close();
        }

        $supersede = $db->prepare(
            "UPDATE tblExternalEventApprovals SET status = 'superseded', updatedAt = UTC_TIMESTAMP() "
            . "WHERE feedID = ? AND status IN ('approved', 'declined')"
        );
        $supersede->bind_param('i', $feedId);
        $supersede->execute();
        $supersede->close();

        return count($copyIds);
    }

    /**
     * An administrator approves or declines one waiting date — the claim of
     * the #514 plan section 1.9, word for word.
     *
     * It changes the row only if it is STILL waiting, belongs to the given
     * organisation, and still carries exactly the request and content the
     * administrator was shown. Any other case changes nothing and returns
     * false, and the page says "This event changed since you opened the
     * page. Please review it again". So two administrators deciding at once,
     * or an event that changed while the page was open, can never be decided
     * on the strength of something nobody saw.
     *
     * The caller holds the calendar's lock and calls `resolveFeed()` straight
     * afterwards, so the decision takes effect — and other waiting dates of
     * the same repeating event follow it — in the same transaction.
     *
     * @throws \InvalidArgumentException For a decision other than `approved`
     *         or `declined`, or no decider — before any SQL is sent.
     */
    public static function decideApproval(
        \mysqli $db,
        int $approvalId,
        int $siteId,
        string $decision,
        int $byUserId,
        string $note,
        string $requestHash,
        string $contentHash
    ): bool {
        // 🚦 Checked FIRST, before the database is touched at all. A word
        //    other than these two must never reach the UPDATE: "status = ?"
        //    with an unexpected word would either fail inside the caller's
        //    transaction or, worse, write a status nothing else expects.
        if ($decision !== 'approved' && $decision !== 'declined') {
            throw new \InvalidArgumentException('FeedResolver: a decision must be "approved" or "declined".');
        }
        if ($byUserId <= 0) {
            throw new \InvalidArgumentException('FeedResolver: a decision needs the account that made it.');
        }

        $trimmed  = trim($note);
        $noteText = ($trimmed === '') ? null : mb_substr($trimmed, 0, 500, 'UTF-8');

        $stmt = $db->prepare(
            'UPDATE tblExternalEventApprovals SET status = ?, decidedByID = ?, decidedAt = UTC_TIMESTAMP(), '
            . 'decisionNote = ?, updatedAt = UTC_TIMESTAMP() '
            . "WHERE approvalID = ? AND siteID = ? AND status = 'pending' AND requestHash = ? AND contentHash = ?"
        );
        $stmt->bind_param('sisiiss', $decision, $byUserId, $noteText, $approvalId, $siteId, $requestHash, $contentHash);
        $stmt->execute();
        $claimed = ($stmt->affected_rows === 1);
        $stmt->close();

        return $claimed;
    }

    /**
     * A rule is being deleted, or a choice removed: withdraw the waiting rows
     * FILED UNDER it (their `originID`).
     *
     * A waiting row asked for by several rules at once is filed under the
     * one rule named as its source (plan section C4, step 9). That is the
     * only one this finds. The `resolveFeed()` the caller runs straight
     * afterwards withdraws every other waiting row whose request included
     * the removed rule, because that request no longer matches anything.
     *
     * @return int How many rows were withdrawn.
     */
    public static function withdrawForOrigin(\mysqli $db, int $feedId, string $origin, int $originId): int
    {
        if ($origin !== 'rule' && $origin !== 'choice') {
            throw new \InvalidArgumentException('FeedResolver: an origin must be "rule" or "choice".');
        }
        $stmt = $db->prepare(
            "UPDATE tblExternalEventApprovals SET status = 'withdrawn', updatedAt = UTC_TIMESTAMP() "
            . "WHERE feedID = ? AND origin = ? AND originID = ? AND status = 'pending'"
        );
        $stmt->bind_param('isi', $feedId, $origin, $originId);
        $stmt->execute();
        $count = max(0, $stmt->affected_rows);
        $stmt->close();

        return $count;
    }

    // =========================================================================
    // 🕰️ Clocks and zones
    // =========================================================================

    /**
     * The organisation's own time zone: the `site.timezone` setting for that
     * organisation, and UTC when it is empty or not a zone PHP knows.
     *
     * The ONE place #514 reads it. `FeedImporter::zoneFor()` uses it for a
     * calendar with no zone of its own, and date windows are counted in it,
     * so the zone events are stored in and the zone windows are counted in
     * can never be two different ideas.
     *
     * What this cannot settle: `web/_core/bootstrap.php` carries a comment
     * saying `tblSites.timezone` takes priority, but reads only the setting.
     * #514 follows what the code does; the disagreement is for #549.
     */
    public static function organisationZone(int $siteId): DateTimeZone
    {
        $named = trim((string) (App::settingForSite('site.timezone', $siteId) ?? ''));
        if ($named === '') {
            return new DateTimeZone('UTC');
        }
        try {
            return new DateTimeZone($named);
        } catch (\Throwable $unknown) {
            // A mistyped zone must not stop anything working; UTC is the
            // plain, predictable answer, and the same one the importer gives.
            return new DateTimeZone('UTC');
        }
    }

    /**
     * The database's own "now", as a UTC moment.
     *
     * Every caller of `resolveFeed()` passes this, never PHP's clock, for the
     * reason given on `resolveFeed()`'s `$nowUtc`. It throws rather than
     * quietly falling back to PHP's clock: a fallback would put exactly the
     * two-clock mix this exists to prevent into the approvals table.
     *
     * @throws \RuntimeException When the database cannot be asked.
     */
    public static function databaseNowUtc(\mysqli $db): DateTimeImmutable
    {
        $result = $db->query('SELECT UTC_TIMESTAMP() AS nowUtc');
        $row    = ($result === false || $result === true) ? null : $result->fetch_assoc();
        $text   = (string) ($row['nowUtc'] ?? '');
        $moment = DateTimeImmutable::createFromFormat('!' . self::SQL_MOMENT, $text, new DateTimeZone('UTC'));
        if ($moment === false) {
            throw new \RuntimeException('FeedResolver: the database did not say what time it is.');
        }

        return $moment;
    }

    // =========================================================================
    // 📥 LOAD — every input for one calendar, in a fixed number of statements
    // =========================================================================

    /**
     * Everything `plan()` needs about one calendar, or null when it is gone.
     *
     * Nine statements whatever the size of the calendar. Every choice, rule,
     * condition and list row is read only when it carries the calendar's OWN
     * organisation number; a row that does not (which the save pages refuse
     * to write) is simply ignored — the defence behind their own check.
     *
     * @return array<string,mixed>|null
     */
    private static function loadFeedState(\mysqli $db, int $feedId): ?array
    {
        $stmt = $db->prepare(
            'SELECT feedID, siteID, audienceLevel, websiteOptIn, apiOptOut, categoryID '
            . 'FROM tblExternalFeeds WHERE feedID = ? LIMIT 1'
        );
        $stmt->bind_param('i', $feedId);
        $stmt->execute();
        $feed = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($feed === null) {
            return null;
        }
        $siteId = (int) $feed['siteID'];

        return [
            'feed' => [
                'feedID'        => (int) $feed['feedID'],
                'siteID'        => $siteId,
                'audienceLevel' => (string) $feed['audienceLevel'],
                'websiteOptIn'  => (int) $feed['websiteOptIn'],
                'apiOptOut'     => (int) $feed['apiOptOut'],
                'categoryID'    => $feed['categoryID'] === null ? null : (int) $feed['categoryID'],
            ],
            'categoryMap' => self::categoryMap($db, $feedId),
            'events'      => self::liveEvents($db, $feedId),
            'tags'        => self::tagsByEvent($db, $feedId),
            'choices'     => self::choicesOf($db, $feedId, $siteId),
            'rules'       => self::rulesOf($db, $feedId, $siteId),
            'audience'    => self::audienceOf($db, $feedId, $siteId),
            'approvals'   => self::approvalsOf($db, $feedId, $siteId),
        ];
    }

    /**
     * This calendar's map from an outside category word to a portal category.
     *
     * Keyed in lower case. The database would compare these without regard
     * to capital letters anyway (`utf8mb4_general_ci`), but the comparison
     * happens in PHP here, and PHP does not, so the lowering is real work and
     * not decoration.
     *
     * A row whose `categoryID` is empty is kept in the map with the value
     * null. That is NOT the same as the word being absent: "this word is
     * known about and deliberately maps to nothing" means the next word is
     * tried, which is exactly what the absent case does too — but an
     * administrator page needs to be able to tell the two apart, and it can
     * only do that if the row survives.
     *
     * @return array<string,int|null>
     */
    private static function categoryMap(\mysqli $db, int $feedId): array
    {
        $map  = [];
        $stmt = $db->prepare(
            'SELECT externalCategory, categoryID FROM tblExternalCategoryMap WHERE feedID = ?'
        );
        $stmt->bind_param('i', $feedId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $key = mb_strtolower(trim((string) $row['externalCategory']), 'UTF-8');
            if ($key === '') {
                continue;
            }
            $map[$key] = $row['categoryID'] === null ? null : (int) $row['categoryID'];
        }
        $stmt->close();

        return $map;
    }

    /**
     * Every live event of this calendar, with the columns the answer depends
     * on and the columns the answer is written into.
     *
     * Soft-deleted rows are left out: they are not shown anywhere, so there
     * is nothing to decide about them. (Their approval rows ARE loaded —
     * see `approvalsOf()` — because a decision on a removed date still
     * counts as a decision on its repeating event.)
     *
     * `ORDER BY eventID`, so the order is fixed. The answer must not depend
     * on it — that is what the two passes are for — and a test proves it by
     * building the same calendar with its event numbers rising and falling.
     *
     * @return list<array<string,mixed>>
     */
    private static function liveEvents(\mysqli $db, int $feedId): array
    {
        $rows = [];
        $stmt = $db->prepare(
            'SELECT eventID, externalUidHash, externalRecurrenceKey, eventName, description, locationName, '
            . 'externalUrl, startDateTime, endDateTime, timezone, isAllDay, externalPrivate, externalDuplicate, '
            . 'importLevel, importDetail, importWebsite, importApiOptOut, importAudienceType, importAudienceID, '
            . 'importSource, importSourceID, importRecheckAt, categoryID '
            . 'FROM tblEvents WHERE externalFeedID = ? AND isDeleted = 0 ORDER BY eventID'
        );
        $stmt->bind_param('i', $feedId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    /**
     * The category words on each live event of this calendar.
     *
     * @return array<int, list<string>> Event number => its words, in
     *         alphabetical order ignoring capital letters.
     */
    private static function tagsByEvent(\mysqli $db, int $feedId): array
    {
        $tags = [];
        $stmt = $db->prepare(
            'SELECT t.eventID, t.tag FROM tblExternalEventTags t '
            . 'JOIN tblEvents e ON e.eventID = t.eventID '
            . 'WHERE e.externalFeedID = ? AND e.isDeleted = 0'
        );
        $stmt->bind_param('i', $feedId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $tags[(int) $row['eventID']][] = (string) $row['tag'];
        }
        $stmt->close();

        // 🔤 Sorted here rather than by the database. The importer writes
        //    these words in lower case, so `ORDER BY tag` would give the same
        //    answer today — but a row put in by hand, or by a later part that
        //    forgets, would not be lowered, and then the order would depend on
        //    how the outside calendar happened to spell the word. Sorting on
        //    the lowered text makes that impossible. (On a word that is not
        //    valid text `mb_strtolower()` quietly substitutes "?"; that only
        //    affects the ORDER here — step 2b hides such an event anyway
        //    whenever a rule could compare its text.)
        foreach ($tags as $eventId => $list) {
            usort($list, static function (string $a, string $b): int {
                return mb_strtolower($a, 'UTF-8') <=> mb_strtolower($b, 'UTF-8');
            });
            $tags[$eventId] = $list;
        }

        return $tags;
    }

    /**
     * Every choice of this calendar and organisation.
     *
     * @return list<array<string,mixed>>
     */
    private static function choicesOf(\mysqli $db, int $feedId, int $siteId): array
    {
        $rows = [];
        $stmt = $db->prepare(
            'SELECT choiceID, scope, externalUidHash, externalRecurrenceKey, audienceLevel, detailLevel, '
            . 'websiteOptIn, apiOptOut, fromDate, toDate, overridesPrivateMark '
            . 'FROM tblExternalEventChoices WHERE feedID = ? AND siteID = ? ORDER BY choiceID'
        );
        $stmt->bind_param('ii', $feedId, $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    /**
     * Every SWITCHED-ON rule of this calendar and organisation, each with its
     * conditions. A switched-off rule does nothing at all, so it is not even
     * read. Two statements: the rules, then all their conditions at once.
     *
     * @return list<array<string,mixed>>
     */
    private static function rulesOf(\mysqli $db, int $feedId, int $siteId): array
    {
        $rules = [];
        $stmt  = $db->prepare(
            'SELECT ruleID, audienceLevel, detailLevel, websiteOptIn, apiOptOut, fromDate, toDate '
            . 'FROM tblExternalFeedRules WHERE feedID = ? AND siteID = ? AND isActive = 1 ORDER BY ruleID'
        );
        $stmt->bind_param('ii', $feedId, $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $row['isActive']   = 1;
            $row['conditions'] = [];
            $rules[(int) $row['ruleID']] = $row;
        }
        $stmt->close();

        $stmt = $db->prepare(
            'SELECT c.ruleID, c.isException, c.matchField, c.matchType, c.matchValue '
            . 'FROM tblExternalRuleConditions c '
            . 'JOIN tblExternalFeedRules r ON r.ruleID = c.ruleID '
            . 'WHERE r.feedID = ? AND r.siteID = ? AND r.isActive = 1 ORDER BY c.conditionID'
        );
        $stmt->bind_param('ii', $feedId, $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $ruleId = (int) $row['ruleID'];
            if (isset($rules[$ruleId]) === true) {
                $rules[$ruleId]['conditions'][] = $row;
            }
        }
        $stmt->close();

        return array_values($rules);
    }

    /**
     * Every "who may see it" list of this calendar — its own, and those of
     * its choices and rules — as `kind:refID` texts, sorted, so two lists can
     * be compared as sets and put into a fingerprint in a fixed order.
     *
     * @return array<string, array<int, list<string>>> ownerType => ownerID => list
     */
    private static function audienceOf(\mysqli $db, int $feedId, int $siteId): array
    {
        $lists = ['feed' => [], 'choice' => [], 'rule' => []];
        $stmt  = $db->prepare(
            'SELECT ownerType, ownerID, kind, refID FROM tblExternalAudienceMembers WHERE feedID = ? AND siteID = ?'
        );
        $stmt->bind_param('ii', $feedId, $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $lists[(string) $row['ownerType']][(int) $row['ownerID']][] = $row['kind'] . ':' . (int) $row['refID'];
        }
        $stmt->close();

        foreach ($lists as $type => $owners) {
            foreach ($owners as $ownerId => $list) {
                $list = array_values(array_unique($list));
                sort($list, SORT_STRING);
                $lists[$type][$ownerId] = $list;
            }
        }

        return $lists;
    }

    /**
     * Every approval row of this calendar that is still live — waiting,
     * approved or declined — with the identity of its event beside it.
     *
     * Rows of SOFT-DELETED events are included on purpose (plan 1.8 step 6):
     * a decision on a date that has since gone still counts as a decision on
     * its repeating event, so a whole series moved at the source keeps it.
     * `superseded` and `withdrawn` rows are history and are not read.
     *
     * @return list<array<string,mixed>>
     */
    private static function approvalsOf(\mysqli $db, int $feedId, int $siteId): array
    {
        $rows = [];
        $stmt = $db->prepare(
            'SELECT a.approvalID, a.eventID, a.origin, a.originID, a.requestHash, a.contentHash, a.status, a.reason, '
            . 'a.requestedLevel, a.requestedDetail, a.requestedWebsite, a.requestedAudienceSummary, a.snapTitle, '
            . 'a.snapStart, a.snapEnd, a.snapTimezone, a.snapIsAllDay, a.snapCategoryID, a.snapDescription, '
            . 'a.snapLocation, a.snapUrl, a.decidedByID, a.decidedAt, a.decisionNote, a.createdAt, a.updatedAt, '
            . 'e.externalUidHash AS evUidHash '
            . 'FROM tblExternalEventApprovals a '
            . 'JOIN tblEvents e ON e.eventID = a.eventID AND e.externalFeedID = a.feedID '
            . "WHERE a.feedID = ? AND a.siteID = ? AND a.status IN ('pending', 'approved', 'declined') "
            . 'ORDER BY a.approvalID'
        );
        $stmt->bind_param('ii', $feedId, $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    // =========================================================================
    // 🧠 WORK OUT — pure: no database, no clock
    // =========================================================================

    /**
     * The answer for every live event, and every change to the approval
     * rows, worked out from what was loaded. Section C4 of the part P7 plan
     * is the exact logic; the numbered comments below follow its steps.
     *
     * Nothing here reads the database or the clock. "Now" is the argument,
     * so a test can ask about any moment, and the rule preview can run this
     * without any risk of writing.
     *
     * @param array<string,mixed> $state       From `loadFeedState()`.
     * @param DateTimeImmutable   $nowUtc      "Now", as a UTC moment.
     * @param DateTimeZone        $zone        The organisation's zone (for date windows).
     * @param array|null          $savedChoice See `resolveFeed()`.
     *
     * @return array{answers: array<int, array<string,mixed>>, rows: array<string, array<string,mixed>>,
     *               counts: array{newPending:int, pendingTotal:int, approvedBySave:int},
     *               explain: array<int, array<string,mixed>>}
     */
    private static function plan(array $state, DateTimeImmutable $nowUtc, DateTimeZone $zone, ?array $savedChoice): array
    {
        $feed      = $state['feed'];
        $feedId    = (int) $feed['feedID'];
        $feedLevel = (string) $feed['audienceLevel'];
        $feedList  = ($feedLevel === 'groups') ? ($state['audience']['feed'][$feedId] ?? []) : [];
        $nowText   = self::utcText($nowUtc);
        $haveRules = ($state['rules'] !== []);

        // 🗂️ Choices, looked up by identity. A series choice carrying a
        //    recurrence key is ignored (the table's own note says why).
        $dateChoices   = [];
        $seriesChoices = [];
        $choicesById   = [];
        foreach ($state['choices'] as $choice) {
            $choicesById[(int) $choice['choiceID']] = $choice;
            $hash = (string) $choice['externalUidHash'];
            $key  = (string) $choice['externalRecurrenceKey'];
            if ($choice['scope'] === 'date') {
                $dateChoices[$hash . "\x00" . $key] = $choice;
            } elseif ($key === '') {
                $seriesChoices[$hash] = $choice;
            }
        }

        // 💾 The choice just saved on the choice page, but ONLY when it is a
        //    choice of this calendar. Anything else in `$savedChoice` is
        //    ignored, so a tampered form can never reach another calendar.
        $saved = null;
        if ($savedChoice !== null && isset($savedChoice['choiceID'], $choicesById[(int) $savedChoice['choiceID']]) === true) {
            $saved = [
                'choiceID' => (int) $savedChoice['choiceID'],
                'byUserId' => (int) ($savedChoice['byUserId'] ?? 0),
                'seen'     => is_array($savedChoice['seen'] ?? null) ? $savedChoice['seen'] : [],
                'choice'   => $choicesById[(int) $savedChoice['choiceID']],
            ];
        }

        // 🧮 The approval rows, as they will stand after this resolve.
        //    Loaded rows are keyed 'a<approvalID>'; new ones 'n<number>'.
        //    `seq` is the order used for "then the highest approval number":
        //    loaded rows by their number, new rows after all of them in the
        //    order they are created, which is the order they are inserted.
        $rows    = [];
        $maxSeen = 0;
        foreach ($state['approvals'] as $loaded) {
            $key            = 'a' . (int) $loaded['approvalID'];
            $row            = $loaded;
            $row['key']     = $key;
            $row['isNew']   = false;
            $row['dirty']   = false;
            $row['seq']     = (int) $loaded['approvalID'];
            $row['loadedStatus'] = (string) $loaded['status'];
            $row['followsKey']   = null;
            $rows[$key]     = $row;
            $maxSeen        = max($maxSeen, (int) $loaded['approvalID']);
        }
        $newCount = 0;
        $counts   = ['newPending' => 0, 'pendingTotal' => 0, 'approvedBySave' => 0];

        /** @var array<int,string> $currentPending event => the key of its current waiting row */
        $currentPending = [];
        $answers        = [];
        $explain        = [];
        $pending2       = []; // event => [cand, base, R, C] for pass 2

        // ---------------------------------------------------------------------
        // Small helpers that change `$rows` (closures, so the arithmetic of
        // "is this row dirty?" lives in one place).
        // ---------------------------------------------------------------------
        // `$byEvent` indexes the rows by event, so finding one event's rows
        // does not mean reading every row of the calendar for every event (a
        // calendar may hold 2,000 dates and many more approval rows).
        $byEvent = [];
        foreach ($rows as $key => $row) {
            $byEvent[(int) $row['eventID']][] = (string) $key;
        }
        // A change to a row marks it for writing, and stamps `updatedAt`, ONLY
        // when a value really differs — so a waiting row whose event has not
        // changed is not rewritten on every refresh.
        $set = static function (string $key, array $fields) use (&$rows, $nowText): void {
            $changed = false;
            foreach ($fields as $name => $value) {
                $old = $rows[$key][$name] ?? null;
                $differs = ($old === null || $value === null) ? ($old !== $value) : ((string) $old !== (string) $value);
                if ($differs === true) {
                    $rows[$key][$name] = $value;
                    $changed = true;
                }
            }
            if ($changed === true) {
                $rows[$key]['dirty']     = true;
                $rows[$key]['updatedAt'] = $nowText;
            }
        };
        $insert = static function (array $fields) use (&$rows, &$byEvent, &$newCount, $maxSeen): string {
            $newCount++;
            $key = 'n' . $newCount;
            $rows[$key] = $fields + [
                'key'          => $key,
                'approvalID'   => null,
                'isNew'        => true,
                'dirty'        => true,
                'seq'          => $maxSeen + $newCount,
                'loadedStatus' => null,
                'followsKey'   => null,
            ];
            $byEvent[(int) $fields['eventID']][] = $key;

            return $key;
        };
        $liveFor = static function (int $eventId, string $request) use (&$rows, &$byEvent): array {
            $keys = [];
            foreach ($byEvent[$eventId] ?? [] as $key) {
                $row = $rows[$key];
                if ((string) $row['requestHash'] === $request
                    && in_array((string) $row['status'], ['pending', 'approved', 'declined'], true) === true) {
                    $keys[] = $key;
                }
            }

            return $keys;
        };

        foreach ($state['events'] as $event) {
            $eventId   = (int) $event['eventID'];
            $tags      = $state['tags'][$eventId] ?? [];
            $private   = ((int) $event['externalPrivate'] === 1);
            $duplicate = ((int) $event['externalDuplicate'] === 1);
            $uidHash   = $event['externalUidHash'] === null ? null : (string) $event['externalUidHash'];
            $recKey    = (string) $event['externalRecurrenceKey'];

            // 1️⃣ The category (D15), unchanged from part P6.
            $category = self::categoryFor($tags, $state['categoryMap'], $feed['categoryID']);

            // 2️⃣ The base: the calendar's own setting, or "administrators
            //    only" for an event the outside calendar marked private.
            if ($private === true) {
                $base = ['level' => 'hidden', 'website' => 0, 'list' => [], 'source' => 'private'];
            } else {
                $base = [
                    'level'   => $feedLevel,
                    'website' => ((int) $feed['websiteOptIn'] === 1 && $feedLevel === 'public') ? 1 : 0,
                    'list'    => $feedList,
                    'source'  => ($duplicate === true) ? 'duplicate' : 'calendar',
                ];
            }

            $why = [
                'duplicate' => $duplicate, 'private' => $private, 'unreadable' => false,
                'choiceDecides' => false, 'conflict' => false, 'combined' => null,
                'state' => 'base', 'request' => null, 'activeRuleIds' => [],
            ];

            // 2️⃣b Unreadable text (challenge finding 8). Text is only ever
            //    compared by rules, so this matters only when there is one.
            //    "Does not match" would NOT be safe: for a rule that narrows
            //    (a hidden level, or the API box), not matching is the WIDER
            //    answer. So such an event gets the answer that is closed in
            //    every direction. In practice it never happens — the reader
            //    and the importer store valid text — so this is a guard.
            if ($haveRules === true && self::eventTextUnreadable($event, $tags) === true) {
                $why['unreadable'] = true;
                $explain[$eventId] = $why;
                $answers[$eventId] = self::answerRow('hidden', 'full', 0, 1, null, null, 'conflict', null, null, $category);
                continue;
            }

            // 3️⃣ Which choices and rules could apply to this event at all.
            $dateChoice   = ($uidHash === null) ? null : ($dateChoices[$uidHash . "\x00" . $recKey] ?? null);
            $seriesChoice = ($uidHash === null) ? null : ($seriesChoices[$uidHash] ?? null);
            $matching     = [];
            foreach ($state['rules'] as $rule) {
                if (self::ruleMatches($rule, $event, $tags) === true) {
                    $matching[] = $rule;
                }
            }
            $dateActive   = ($dateChoice !== null && self::windowActive($dateChoice, $nowUtc, $zone) === true);
            $seriesActive = ($seriesChoice !== null && self::windowActive($seriesChoice, $nowUtc, $zone) === true);
            $activeRules  = [];
            foreach ($matching as $rule) {
                if (self::windowActive($rule, $nowUtc, $zone) === true) {
                    $activeRules[] = $rule;
                }
            }
            $why['activeRuleIds'] = array_map(static fn (array $r): int => (int) $r['ruleID'], $activeRules);

            // 4️⃣ "Don't show via API" (B2): ANY applying source is enough,
            //    worked out for EVERY event before anything below can stop
            //    early — a private event, a duplicate, a waiting or declined
            //    widening and a conflict all still count a rule's box.
            $api = ((int) $feed['apiOptOut'] === 1)
                || ($dateActive === true && (int) $dateChoice['apiOptOut'] === 1)
                || ($seriesActive === true && (int) $seriesChoice['apiOptOut'] === 1);
            foreach ($activeRules as $rule) {
                if ((int) $rule['apiOptOut'] === 1) {
                    $api = true;
                }
            }
            $apiValue = ($api === true) ? 1 : 0;

            // 5️⃣ When the stored answer stops being right: the first start or
            //    end of any choice or matching rule that is still ahead.
            $sources = $matching;
            if ($dateChoice !== null) {
                $sources[] = $dateChoice;
            }
            if ($seriesChoice !== null) {
                $sources[] = $seriesChoice;
            }
            $recheck = self::nextBoundary($sources, $nowUtc, $zone);
            $recheckText = ($recheck === null) ? null : self::utcText($recheck);

            $baseAnswer = self::answerRow(
                $base['level'],
                'full',
                (int) $base['website'],
                $apiValue,
                $base['level'] === 'hidden' ? null : 'feed',
                $base['level'] === 'hidden' ? null : $feedId,
                (string) $base['source'],
                null,
                $recheckText,
                $category
            );

            // 6️⃣ (plan step 1, corrected by round-1 check FIX B, 24
            //    September 2026) A duplicate no longer gets the base
            //    unconditionally here. Steps 7-9 below still work out what a
            //    choice or rule WOULD do; the decision at 🔟/1️⃣1️⃣ below then
            //    applies it when narrowing and ignores it, kept at the base,
            //    only when it would widen — see the header note above and
            //    the comment beside the widening branch for why. Before this
            //    fix every duplicate reached the base right here regardless
            //    of direction, which let a narrowing choice or rule's hide
            //    be silently undone by a duplicated listing.

            // 7️⃣ (plan step 3) The most specific ACTIVE choice decides. An
            //    inactive choice is treated as absent (gap 12).
            $choiceUsed = ($dateActive === true) ? $dateChoice : (($seriesActive === true) ? $seriesChoice : null);
            $candidate  = null;
            if ($choiceUsed !== null) {
                $why['choiceDecides'] = true;
                if ($private === true && (string) $choiceUsed['audienceLevel'] !== 'hidden'
                    && (int) $choiceUsed['overridesPrivateMark'] === 0) {
                    // A private mark stays unless the administrator ticked the
                    // warning and chose to show it anyway.
                    $candidate = null;
                } else {
                    $candidate = self::choiceCandidate($choiceUsed, $state['audience']);
                }
            } elseif ($private === false && $activeRules !== []) {
                // 9️⃣ (plan step 4) Rules, only when no choice decided. A
                //    private event never gets here (plan step 2).
                $candidate = self::combineRules($activeRules, $state['audience']);
                $why['conflict'] = ($candidate['source'] === 'conflict');
                $why['combined'] = ['level' => $candidate['level'], 'detail' => $candidate['detail'], 'website' => $candidate['website']];
            }

            if ($candidate === null) {
                // 8️⃣ No candidate: a private event with no choice (or with a
                //    choice that may not show it), or nothing that applies.
                $explain[$eventId] = $why;
                $answers[$eventId] = $baseAnswer;
            } elseif (self::isWider($candidate, $base) === false) {
                // 🔟 (plan step 5) Not wider: narrowing cannot expose anything,
                //    so it applies at once and keeps no waiting row.
                $why['state']      = 'applied';
                $explain[$eventId] = $why;
                $answers[$eventId] = self::candidateAnswer($candidate, $apiValue, $recheckText, $category);
            } elseif ($duplicate === true) {
                // Round-1 check FIX B (24 September 2026): wider, but this
                //    row is a duplicate. A duplicate never gets an approval
                //    row — recording one would be for a date no choice or
                //    rule can reliably be about, since the outside calendar
                //    listed this identity twice and there is no way to tell
                //    which of the two copies an administrator's choice was
                //    really deciding (the build report's choice 8, extended
                //    here to widening rules too). So a widening candidate on
                //    a duplicate is simply ignored and the base stands —
                //    exactly what EVERY duplicate did before this fix, kept
                //    unchanged for this one direction.
                $explain[$eventId] = $why;
                $answers[$eventId] = $baseAnswer;
            } else {
                // 1️⃣1️⃣ (plan step 6) Wider: the approval gate.
                $request  = self::requestHash($candidate);
                $content  = self::contentHash($event, $category, (string) $candidate['detail']);
                $contentF = self::contentHash($event, $category, 'full');
                $why['request'] = $request;

                $snapshot = self::snapshot($event, $category, $candidate, $feed, $request, $content, $uidHash, $eventId);

                $live = $liveFor($eventId, $request);
                if (count($live) > 1) {
                    // More than one live row for one request is a fault or a
                    // hand edit. Keeping "the newest" could keep an approval
                    // over a later decline, which is not the narrow direction
                    // (challenge finding 11), so all of them go and the event
                    // waits for a fresh decision.
                    foreach ($live as $key) {
                        $set($key, ['status' => 'superseded']);
                    }
                    $live = [];
                }
                $liveKey = $live[0] ?? null;

                $outcome        = null;
                $insertedReason = 'new_match';
                $seenHere = ($saved !== null && $candidate['origin'] === 'choice'
                    && $candidate['originIds'] === [$saved['choiceID']] && array_key_exists($eventId, $saved['seen']) === true);

                // (a) Saved just now, and seen — checked FIRST (A25), so the
                //     administrator's latest deliberate act wins over a date
                //     that was waiting, or had been declined.
                if ($seenHere === true) {
                    $seenPrint = $saved['seen'][$eventId];
                    if (is_string($seenPrint) === true && hash_equals($contentF, $seenPrint) === true) {
                        self::recordSeenApproval($rows, $set, $insert, $liveKey, $snapshot, $content, $saved['byUserId'], $nowText, $counts);
                        $outcome = 'applied';
                    } else {
                        // It changed while the page was open, so the page did
                        // not show what is there now. It never goes through on
                        // the strength of the page; it can only follow an
                        // identical approved date, like any waiting date.
                        $insertedReason = 'content_changed';
                    }
                }

                // (b) Otherwise, by the live row.
                if ($outcome === null && $liveKey !== null) {
                    $liveRow = $rows[$liveKey];
                    if ($liveRow['status'] === 'approved' && (string) $liveRow['contentHash'] === $content) {
                        $outcome = 'applied';
                    } elseif ($liveRow['status'] === 'declined' && (string) $liveRow['contentHash'] === $content) {
                        // Declined with the same content: the base, and NO
                        // new row (leak-hunt finding 25).
                        $outcome = 'declined';
                    } elseif ($liveRow['status'] === 'pending') {
                        $set($liveKey, self::refreshedPending($snapshot));
                        $currentPending[$eventId] = $liveKey;
                        $outcome = 'waiting';
                    } else {
                        // Approved or declined for DIFFERENT content: that
                        // decision was about something else (A15, D14).
                        $set($liveKey, ['status' => 'superseded']);
                        $insertedReason = 'content_changed';
                    }
                }
                if ($outcome === null) {
                    $currentPending[$eventId] = $insert($snapshot + [
                        'status' => 'pending', 'reason' => $insertedReason,
                        'decidedByID' => null, 'decidedAt' => null, 'decisionNote' => null,
                        'createdAt' => $nowText, 'updatedAt' => null,
                    ]);
                    $outcome = 'waiting';
                }

                $why['state'] = $outcome;
                $explain[$eventId] = $why;
                if ($outcome === 'applied') {
                    $answers[$eventId] = self::candidateAnswer($candidate, $apiValue, $recheckText, $category);
                } elseif ($outcome === 'declined') {
                    $answers[$eventId] = $baseAnswer;
                } else {
                    $answers[$eventId] = self::answerRow(
                        $base['level'], 'full', (int) $base['website'], $apiValue,
                        $base['level'] === 'hidden' ? null : 'feed', $base['level'] === 'hidden' ? null : $feedId,
                        'waiting', null, $recheckText, $category
                    );
                    $pending2[$eventId] = ['candidate' => $candidate, 'baseAnswer' => $baseAnswer,
                        'uidHash' => $uidHash, 'apiValue' => $apiValue, 'recheck' => $recheckText, 'category' => $category];
                }
            }

            // 1️⃣1️⃣b A choice saved just now that does NOT decide a date it
            //    covers (A26; widened by challenge finding 6): because it
            //    has not started yet, or because a single-date choice decides
            //    that date at the moment. The administrator SAW the date and
            //    chose it, so its approval is recorded now; otherwise, when
            //    the choice came to decide it, the date would wait although
            //    it was on the page. It changes nothing about the answer now.
            //    Round-1 check FIX B: a duplicate never reaches 11b either —
            //    it never gets an approval row, for the same reason step 6
            //    above never gives one to a widening candidate.
            if ($duplicate === false && $saved !== null && $uidHash !== null) {
                self::seenButNotDeciding($saved, $event, $uidHash, $recKey, $base, $choiceUsed, $dateChoice, $dateActive,
                    $private, $category, $feed, $state['audience'], $nowUtc, $zone, $rows, $set, $insert, $liveFor, $nowText, $counts);
            }
        }

        // ---------------------------------------------------------------------
        // 1️⃣2️⃣ Pass 2 — the sibling decision (owner answer 6).
        // ---------------------------------------------------------------------
        // Every date still waiting looks for a decision on ANOTHER date of the
        // same repeating event with the same request and the same visible
        // content. It looks only at decisions as they stood at the END of pass
        // 1 — not at ones pass 2 itself makes — so the answer can never depend
        // on the order the dates were read in. One pass 2 is enough: a row it
        // settles copies an existing decision, so it cannot change anything
        // for any other date.
        //    Indexed by identity, request and content, so each waiting date
        //    looks only at the decisions it could follow.
        $decided = [];
        foreach ($rows as $key => $row) {
            if ($row['evUidHash'] === null || in_array((string) $row['status'], ['approved', 'declined'], true) === false) {
                continue;
            }
            $bucket = (string) $row['evUidHash'] . "\x00" . $row['requestHash'] . "\x00" . $row['contentHash'];
            $decided[$bucket][] = [
                'key' => (string) $key, 'status' => (string) $row['status'], 'eventID' => (int) $row['eventID'],
                'decidedAt' => (string) ($row['decidedAt'] ?? ''), 'decidedByID' => $row['decidedByID'],
                'seq' => (int) $row['seq'],
            ];
        }
        foreach ($pending2 as $eventId => $info) {
            $key = $currentPending[$eventId] ?? null;
            if ($key === null || $info['uidHash'] === null) {
                continue;
            }
            $bucket = $info['uidHash'] . "\x00" . $rows[$key]['requestHash'] . "\x00" . $rows[$key]['contentHash'];
            $best   = null;
            foreach ($decided[$bucket] ?? [] as $d) {
                if ($d['eventID'] === $eventId) {
                    continue;
                }
                // The newest decision wins; on a tie, the highest approval
                // number (a row created in this resolve counts as higher than
                // every row that was loaded, as it will be once inserted).
                if ($best === null || [$d['decidedAt'], $d['seq']] > [$best['decidedAt'], $best['seq']]) {
                    $best = $d;
                }
            }
            if ($best === null) {
                continue;
            }
            $fields = [
                'status'       => $best['status'],
                'decidedByID'  => $best['decidedByID'],
                'decidedAt'    => $best['decidedAt'],
            ];
            if ($rows[$key]['isNew'] === true) {
                $fields['reason'] = 'series_match';
            }
            $set($key, $fields);
            // The note names the date it followed. When that date's row was
            // itself created in this resolve its number is not known yet, so
            // the write stage fills the note in (see `writePlan()`).
            $rows[$key]['followsKey'] = $best['key'];
            $rows[$key]['dirty']      = true;
            unset($currentPending[$eventId]);
            if ($best['status'] === 'approved') {
                $explain[$eventId]['state'] = 'applied';
                $answers[$eventId] = self::candidateAnswer($info['candidate'], $info['apiValue'], $info['recheck'], $info['category']);
            } else {
                $explain[$eventId]['state'] = 'declined';
                $answers[$eventId] = $info['baseAnswer'];
            }
        }

        // ---------------------------------------------------------------------
        // 1️⃣3️⃣ Withdrawals: every loaded waiting row that is not the current
        //    waiting row of a live event for its current request. That covers
        //    a removed (soft-deleted) event (A4 — here rather than in the
        //    importer, so whichever caller resolves first withdraws it), a
        //    changed request (a rule edited), a candidate that is no longer
        //    wider, and an event that became private or a duplicate.
        // ---------------------------------------------------------------------
        $stillCurrent = array_flip(array_values($currentPending));
        foreach ($rows as $key => $row) {
            if ($row['isNew'] === false && $row['loadedStatus'] === 'pending' && $row['status'] === 'pending'
                && isset($stillCurrent[$key]) === false) {
                $set((string) $key, ['status' => 'withdrawn']);
            }
        }

        foreach ($rows as $row) {
            if ($row['status'] === 'pending') {
                $counts['pendingTotal']++;
                if ($row['loadedStatus'] !== 'pending') {
                    $counts['newPending']++;
                }
            }
        }

        return ['answers' => $answers, 'rows' => $rows, 'counts' => $counts, 'explain' => $explain];
    }

    /**
     * Record that the administrator saw this date and saved — steps 11(a)
     * and 11b share this, so the two can never treat a date differently.
     *
     * - approved already, with this content: nothing to do;
     * - waiting: its snapshot is brought up to date and it becomes approved,
     *   by the saver, now (its reason is kept);
     * - declined, or approved for different content: that row is superseded
     *   and a new approved row, reason `choice`, is added;
     * - no row: a new approved row, reason `choice`.
     * Every date moved from waiting or declined to approved adds one to
     * `approvedBySave`, which the choice page reports.
     *
     * @param array<string, array<string,mixed>> $rows
     * @param array<string,mixed>                $snapshot
     * @param array<string,int>                  $counts
     */
    private static function recordSeenApproval(
        array &$rows,
        callable $set,
        callable $insert,
        ?string $liveKey,
        array $snapshot,
        string $content,
        int $byUserId,
        string $nowText,
        array &$counts
    ): void {
        $decision = ['decidedByID' => $byUserId > 0 ? $byUserId : null, 'decidedAt' => $nowText, 'decisionNote' => self::NOTE_SEEN];
        if ($liveKey !== null) {
            $status = (string) $rows[$liveKey]['status'];
            if ($status === 'approved' && (string) $rows[$liveKey]['contentHash'] === $content) {
                return;
            }
            if ($status === 'pending') {
                $set($liveKey, self::refreshedPending($snapshot) + ['status' => 'approved'] + $decision);
                $counts['approvedBySave']++;

                return;
            }
            $set($liveKey, ['status' => 'superseded']);
            if ($status === 'declined') {
                $counts['approvedBySave']++;
            }
        }
        $insert($snapshot + ['status' => 'approved', 'reason' => 'choice', 'createdAt' => $nowText, 'updatedAt' => null] + $decision);
    }

    /**
     * Step 11b: the choice just saved covers this date by identity but does
     * not decide it now — because its first day is still ahead, or because a
     * single-date choice decides this date at the moment. A choice whose last
     * day has already passed decides nothing and records nothing.
     *
     * Only when the date was on the page with exactly its current content,
     * and the saved choice would widen it, is an approval recorded for the
     * saved choice's request. A duplicate, an event with unreadable text, and
     * a private event the choice may not show, get nothing — the narrower
     * direction. The event's answer NOW is untouched.
     *
     * @param array<string,mixed>                $saved
     * @param array<string,mixed>                $event
     * @param array<string,mixed>                $base
     * @param array<string,mixed>|null           $choiceUsed
     * @param array<string,mixed>|null           $dateChoice
     * @param array<string,mixed>                $feed
     * @param array<string, array<int, list<string>>> $audience
     * @param array<string, array<string,mixed>> $rows
     * @param array<string,int>                  $counts
     */
    private static function seenButNotDeciding(
        array $saved,
        array $event,
        string $uidHash,
        string $recKey,
        array $base,
        ?array $choiceUsed,
        ?array $dateChoice,
        bool $dateActive,
        bool $private,
        ?int $category,
        array $feed,
        array $audience,
        DateTimeImmutable $nowUtc,
        DateTimeZone $zone,
        array &$rows,
        callable $set,
        callable $insert,
        callable $liveFor,
        string $nowText,
        array &$counts
    ): void {
        $choice  = $saved['choice'];
        $eventId = (int) $event['eventID'];
        if ((int) $event['externalDuplicate'] === 1 || array_key_exists($eventId, $saved['seen']) === false) {
            return;
        }
        if ((string) $choice['externalUidHash'] !== $uidHash) {
            return;
        }
        $isSeries = ((string) $choice['scope'] === 'series');
        if ($isSeries === true && (string) $choice['externalRecurrenceKey'] !== '') {
            return;
        }
        if ($isSeries === false && (string) $choice['externalRecurrenceKey'] !== $recKey) {
            return;
        }
        if ($choiceUsed !== null && (int) $choiceUsed['choiceID'] === (int) $choice['choiceID']) {
            return; // it DOES decide this date; steps 7-11 handled it
        }

        [$start, $end] = self::windowMoments($choice, $zone);
        if ($choice['fromDate'] !== null && $choice['toDate'] !== null && (string) $choice['toDate'] < (string) $choice['fromDate']) {
            return; // a window that ends before it starts never applies
        }
        $ended      = ($choice['toDate'] !== null && ($end === null || $nowUtc >= $end));
        $notStarted = ($choice['fromDate'] !== null && $start !== null && $nowUtc < $start);
        $otherDecides = ($isSeries === true && $dateChoice !== null && $dateActive === true
            && (int) $dateChoice['choiceID'] !== (int) $choice['choiceID']);
        if ($ended === true || ($notStarted === false && $otherDecides === false)) {
            return;
        }
        if ($private === true && (string) $choice['audienceLevel'] !== 'hidden' && (int) $choice['overridesPrivateMark'] === 0) {
            return;
        }

        $candidate = self::choiceCandidate($choice, $audience);
        if (self::isWider($candidate, $base) === false) {
            return;
        }
        $seenPrint = $saved['seen'][$eventId];
        if (is_string($seenPrint) === false || hash_equals(self::contentHash($event, $category, 'full'), $seenPrint) === false) {
            // Changed since the page showed it: nothing is recorded now. It
            // will wait, once the choice comes to decide it — the safe outcome.
            return;
        }

        $request  = self::requestHash($candidate);
        $content  = self::contentHash($event, $category, (string) $candidate['detail']);
        $snapshot = self::snapshot($event, $category, $candidate, $feed, $request, $content, $uidHash, $eventId);
        $live     = $liveFor($eventId, $request);
        if (count($live) > 1) {
            foreach ($live as $key) {
                $set($key, ['status' => 'superseded']);
            }
            $live = [];
        }
        self::recordSeenApproval($rows, $set, $insert, $live[0] ?? null, $snapshot, $content, $saved['byUserId'], $nowText, $counts);
    }

    /**
     * A choice as a candidate answer.
     *
     * @param array<string,mixed>                     $choice
     * @param array<string, array<int, list<string>>> $audience
     *
     * @return array<string,mixed>
     */
    private static function choiceCandidate(array $choice, array $audience): array
    {
        $level = (string) $choice['audienceLevel'];
        $id    = (int) $choice['choiceID'];

        return [
            'origin'    => 'choice',
            'originIds' => [$id],
            'originID'  => $id,
            'level'     => $level,
            'detail'    => (string) $choice['detailLevel'],
            'website'   => ((int) $choice['websiteOptIn'] === 1 && $level === 'public') ? 1 : 0,
            'list'      => ($level === 'groups') ? ($audience['choice'][$id] ?? []) : [],
            'source'    => ((string) $choice['scope'] === 'date') ? 'date' : 'series',
            'sourceID'  => $id,
            'audType'   => 'choice',
            'audID'     => $id,
        ];
    }

    /**
     * Several active matching rules, combined FAIL SAFE (D9, leak-hunt 11):
     * the narrowest level; "title, date and time only" if any rule says so;
     * the website box only if EVERY rule ticks it (and the level is public);
     * two rules at the groups level with DIFFERENT lists → hidden, a
     * conflict, because neither list can be trusted over the other.
     *
     * The rule named as the source — and the one an approval row is filed
     * under (`originID`) — is the lowest-numbered at the winning level. The
     * request fingerprint lists every combined rule, so deleting any one of
     * them changes the request (challenge finding 12a).
     *
     * @param list<array<string,mixed>>               $rules
     * @param array<string, array<int, list<string>>> $audience
     *
     * @return array<string,mixed>
     */
    private static function combineRules(array $rules, array $audience): array
    {
        $level   = 'public';
        $detail  = 'full';
        $website = 1;
        $ids     = [];
        $groupLists = [];
        foreach ($rules as $rule) {
            $ruleLevel = (string) $rule['audienceLevel'];
            $ids[] = (int) $rule['ruleID'];
            if (self::LEVEL_RANK[$ruleLevel] < self::LEVEL_RANK[$level]) {
                $level = $ruleLevel;
            }
            if ((string) $rule['detailLevel'] === 'basic') {
                $detail = 'basic';
            }
            if ((int) $rule['websiteOptIn'] !== 1) {
                $website = 0;
            }
            if ($ruleLevel === 'groups') {
                $groupLists[implode(',', $audience['rule'][(int) $rule['ruleID']] ?? [])] = true;
            }
        }
        sort($ids, SORT_NUMERIC);
        if ($level !== 'public') {
            $website = 0;
        }

        if (count($groupLists) >= 2) {
            // A conflict is hidden, so it can never be wider than anything
            // and never needs an approval row: no rule is named as its source.
            return [
                'origin' => 'rule', 'originIds' => $ids, 'originID' => null, 'level' => 'hidden',
                'detail' => $detail, 'website' => 0, 'list' => [], 'source' => 'conflict', 'sourceID' => null,
                'audType' => null, 'audID' => null,
            ];
        }

        $sourceId = null;
        foreach ($rules as $rule) {
            if ((string) $rule['audienceLevel'] === $level && ($sourceId === null || (int) $rule['ruleID'] < $sourceId)) {
                $sourceId = (int) $rule['ruleID'];
            }
        }

        return [
            'origin'    => 'rule',
            'originIds' => $ids,
            'detail'    => $detail,
            'website'   => $website,
            'originID' => $sourceId,
            'level'    => $level,
            'list'     => ($level === 'groups') ? ($audience['rule'][$sourceId] ?? []) : [],
            'source'   => 'rule',
            'sourceID' => $sourceId,
            'audType'  => 'rule',
            'audID'    => $sourceId,
        ];
    }

    /**
     * Is the candidate WIDER than the calendar's own answer (plan step 5)?
     * A higher level; or both public and only the candidate ticks the
     * website box (leak-hunt finding 10); or both "selected groups" and the
     * candidate's list names somebody the calendar's does not.
     *
     * The "Don't show via API" box plays NO part here (B2): it can only
     * narrow, so it never needs approval.
     *
     * @param array<string,mixed> $candidate
     * @param array<string,mixed> $base
     */
    private static function isWider(array $candidate, array $base): bool
    {
        $candRank = self::LEVEL_RANK[(string) $candidate['level']];
        $baseRank = self::LEVEL_RANK[(string) $base['level']];
        if ($candRank !== $baseRank) {
            return $candRank > $baseRank;
        }
        if ($candidate['level'] === 'public') {
            return (int) $candidate['website'] === 1 && (int) $base['website'] !== 1;
        }
        if ($candidate['level'] === 'groups') {
            return array_diff($candidate['list'], $base['list']) !== [];
        }

        return false;
    }

    /**
     * `R` — the fingerprint of WHAT is asked for (leak-hunt finding 9):
     * origin, every rule or choice number, level, detail, website box, and
     * the sorted list. Anything else changing (a different list, a rule
     * added to the combination) is a new request and needs a new decision.
     *
     * @param array<string,mixed> $candidate
     */
    private static function requestHash(array $candidate): string
    {
        $ids = array_map('intval', $candidate['originIds']);
        sort($ids, SORT_NUMERIC);
        $list = ($candidate['level'] === 'groups') ? array_values($candidate['list']) : [];
        sort($list, SORT_STRING);

        return hash('sha256', json_encode(
            [(string) $candidate['origin'], $ids, (string) $candidate['level'], (string) $candidate['detail'],
                ((int) $candidate['website'] === 1) ? 1 : 0, $list],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    /**
     * `C` — the fingerprint of what viewers would SEE at the given detail.
     * Dates and times are left OUT on purpose (D14): a new date or a moved
     * time is accepted automatically; a changed title, category — or, at
     * full detail, description, location or link — asks again.
     *
     * `\x1F` (the "unit separator" character) keeps the fields apart, so
     * moving text from one field to the next changes the fingerprint.
     *
     * @param array<string,mixed> $event
     */
    private static function contentHash(array $event, ?int $categoryId, string $detail): string
    {
        $categoryText = ($categoryId === null) ? '' : (string) $categoryId;
        $title        = (string) ($event['eventName'] ?? '');
        if ($detail === 'basic') {
            return hash('sha256', "b\x1F" . $title . "\x1F" . $categoryText);
        }

        return hash('sha256', "f\x1F" . $title . "\x1F" . $categoryText
            . "\x1F" . (string) ($event['description'] ?? '')
            . "\x1F" . (string) ($event['locationName'] ?? '')
            . "\x1F" . (string) ($event['externalUrl'] ?? ''));
    }

    /**
     * The columns of an approval row that describe the request and the
     * event, for a waiting row or a new one.
     *
     * The description, location and link are copied only when full detail
     * is asked for — an administrator deciding on "title, date and time
     * only" has no reason to keep the rest.
     *
     * `$uidHash` is null for a legacy imported row that has no identity hash
     * at all (round-1 check FIX C, 24 September 2026 — pre-#514 #327 rows;
     * migration 204 left them NULL, and `removeMissing()` clears them out on
     * the first non-empty download with a removal end point, so the window
     * is small). Such a row can still be snapshotted and still waits for
     * approval like any other; only the pass-2 sibling decision cannot use
     * it, because a null identity is skipped from that bucket on purpose
     * (`:1281`) — a row with no identity has no siblings to follow, or to be
     * followed by.
     *
     * @param array<string,mixed> $event
     * @param array<string,mixed> $candidate
     * @param array<string,mixed> $feed
     *
     * @return array<string,mixed>
     */
    private static function snapshot(
        array $event,
        ?int $category,
        array $candidate,
        array $feed,
        string $request,
        string $content,
        ?string $uidHash,
        int $eventId
    ): array {
        $full = ((string) $candidate['detail'] === 'full');
        $zone = trim((string) ($event['timezone'] ?? ''));

        return [
            'siteID'                   => (int) $feed['siteID'],
            'feedID'                   => (int) $feed['feedID'],
            'eventID'                  => $eventId,
            'evUidHash'                => $uidHash,
            'origin'                   => (string) $candidate['origin'],
            'originID'                 => (int) $candidate['originID'],
            'requestHash'              => $request,
            'contentHash'              => $content,
            'requestedLevel'           => (string) $candidate['level'],
            'requestedDetail'          => (string) $candidate['detail'],
            'requestedWebsite'         => (int) $candidate['website'],
            'requestedAudienceSummary' => self::audienceSummary((string) $candidate['level'], $candidate['list']),
            'snapTitle'                => (string) $event['eventName'],
            'snapStart'                => (string) $event['startDateTime'],
            'snapEnd'                  => $event['endDateTime'] === null ? null : (string) $event['endDateTime'],
            'snapTimezone'             => ($zone === '') ? 'UTC' : $zone,
            'snapIsAllDay'             => (int) $event['isAllDay'],
            'snapCategoryID'           => $category,
            'snapDescription'          => $full ? ($event['description'] === null ? null : (string) $event['description']) : null,
            'snapLocation'             => $full ? ($event['locationName'] === null ? null : (string) $event['locationName']) : null,
            'snapUrl'                  => $full ? ($event['externalUrl'] === null ? null : (string) $event['externalUrl']) : null,
        ];
    }

    /**
     * The fields a waiting row takes from a fresh snapshot: what is asked
     * for, and what the event looks like now. Its request, event and origin
     * never change (a different request is a different row). `updatedAt` is
     * stamped by the caller only when one of these really changed.
     *
     * @param array<string,mixed> $snapshot
     *
     * @return array<string,mixed>
     */
    private static function refreshedPending(array $snapshot): array
    {
        $fields = [];
        foreach (['contentHash', 'requestedLevel', 'requestedDetail', 'requestedWebsite', 'requestedAudienceSummary',
            'snapTitle', 'snapStart', 'snapEnd', 'snapTimezone', 'snapIsAllDay', 'snapCategoryID',
            'snapDescription', 'snapLocation', 'snapUrl'] as $name) {
            $fields[$name] = $snapshot[$name];
        }

        return $fields;
    }

    /**
     * "Who is asked for", in COUNTS only — never names (A11). A label built
     * from the list would carry the names of the people on it, and "delete my
     * data" could never find a name hidden inside a label. The names are
     * shown live from the list itself, which erasure does reach.
     *
     * @param list<string> $list `kind:refID` texts.
     */
    private static function audienceSummary(string $level, array $list): string
    {
        if ($level === 'public') {
            return 'Anyone (public)';
        }
        if ($level === 'members') {
            return 'Members of the organisation';
        }
        if ($level === 'hidden') {
            return 'Administrators only';
        }

        $words = [
            'small_group'     => ['small group', 'small groups'],
            'leadership_role' => ['leadership role', 'leadership roles'],
            'person'          => ['named person', 'named people'],
            'role'            => ['role', 'roles'],
            'user_group'      => ['user group', 'user groups'],
            'department'      => ['department', 'departments'],
        ];
        $counts = [];
        foreach ($list as $item) {
            $kind = explode(':', $item, 2)[0];
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
        }
        $parts = [];
        foreach ($words as $kind => [$one, $many]) {
            if (isset($counts[$kind]) === true) {
                $parts[] = $counts[$kind] . ' ' . ($counts[$kind] === 1 ? $one : $many);
            }
        }

        return 'Selected groups: ' . ($parts === [] ? 'nobody yet' : implode(', ', $parts));
    }

    /**
     * The stored answer for an applied candidate. At "hidden" nobody is on a
     * list, so both audience columns are emptied — a stale list left behind
     * would make an administrator page show people who cannot see it.
     *
     * @param array<string,mixed> $candidate
     *
     * @return array<string,mixed>
     */
    private static function candidateAnswer(array $candidate, int $apiValue, ?string $recheck, ?int $category): array
    {
        $hidden = ((string) $candidate['level'] === 'hidden');

        return self::answerRow(
            (string) $candidate['level'],
            (string) $candidate['detail'],
            (int) $candidate['website'],
            $apiValue,
            $hidden ? null : $candidate['audType'],
            $hidden ? null : $candidate['audID'],
            (string) $candidate['source'],
            $candidate['sourceID'],
            $recheck,
            $category
        );
    }

    /**
     * One event's stored answer, in the column names it is written to.
     *
     * @return array<string,mixed>
     */
    private static function answerRow(
        string $level,
        string $detail,
        int $website,
        int $api,
        ?string $audType,
        ?int $audId,
        string $source,
        ?int $sourceId,
        ?string $recheck,
        ?int $category
    ): array {
        return [
            'importLevel'        => $level,
            'importDetail'       => $detail,
            'importWebsite'      => $website,
            'importApiOptOut'    => $api,
            'importAudienceType' => $audType,
            'importAudienceID'   => $audId,
            'importSource'       => $source,
            'importSourceID'     => $sourceId,
            'importRecheckAt'    => $recheck,
            'categoryID'         => $category,
        ];
    }

    // =========================================================================
    // 🔤 Rules and text
    // =========================================================================

    /**
     * Does this rule match this event? Only a VALID rule matches anything
     * (`ruleIsValid()`); then every ordinary condition must match and no
     * exception may. Its dates are NOT looked at here — whether it applies
     * NOW is `windowActive()`'s question, asked separately, because its
     * dates still matter to `importRecheckAt` while it is not active.
     *
     * An event whose text is not valid text matches nothing here; whenever
     * a rule exists, step 2b has already hidden such an event.
     *
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $event
     * @param list<string>        $tags
     */
    private static function ruleMatches(array $rule, array $event, array $tags): bool
    {
        if (self::ruleIsValid($rule) === false || self::eventTextUnreadable($event, $tags) === true) {
            return false;
        }
        foreach ($rule['conditions'] as $condition) {
            $hit = self::conditionMatches($condition, $event, $tags);
            if ((int) $condition['isException'] === 1) {
                if ($hit === true) {
                    return false;
                }
            } elseif ($hit === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * A rule is valid when it has at least one condition that is not an
     * exception, a known level and detail, and every condition has a known
     * field, a known type and a value that is valid text and not empty once
     * normalised. An invalid rule matches NOTHING (plan 1.8 step 4, widened
     * to every malformed shape the rule form refuses) — a rule that matched
     * everything would really be the calendar's own setting, and failing
     * closed cannot expose anything. The rule form (part P8) refuses all of
     * these, so only a hand edit of the database can create one.
     *
     * @param array<string,mixed> $rule
     */
    private static function ruleIsValid(array $rule): bool
    {
        if (isset(self::LEVEL_RANK[(string) ($rule['audienceLevel'] ?? '')]) === false
            || in_array((string) ($rule['detailLevel'] ?? ''), ['basic', 'full'], true) === false
            || is_array($rule['conditions'] ?? null) === false) {
            return false;
        }
        $ordinary = 0;
        foreach ($rule['conditions'] as $condition) {
            if (in_array((string) ($condition['matchField'] ?? ''), ['category', 'title', 'location'], true) === false
                || in_array((string) ($condition['matchType'] ?? ''), ['equals', 'contains', 'word'], true) === false) {
                return false;
            }
            $value = self::normaliseText((string) ($condition['matchValue'] ?? ''));
            if ($value === null || $value === '') {
                return false;
            }
            if ((int) ($condition['isException'] ?? 0) !== 1) {
                $ordinary++;
            }
        }

        return $ordinary > 0;
    }

    /**
     * One condition against one event. A category condition is true when
     * any ONE of the event's own category words equals the value — always a
     * whole-word comparison, whatever `matchType` says, because "outreach"
     * must not match a word "outreach team".
     *
     * @param array<string,mixed> $condition
     * @param array<string,mixed> $event
     * @param list<string>        $tags
     */
    private static function conditionMatches(array $condition, array $event, array $tags): bool
    {
        $value = (string) self::normaliseText((string) $condition['matchValue']);
        $field = (string) $condition['matchField'];

        if ($field === 'category') {
            foreach ($tags as $tag) {
                if (self::normaliseText($tag) === $value) {
                    return true;
                }
            }

            return false;
        }

        $text = (string) self::normaliseText((string) (($field === 'title') ? $event['eventName'] : ($event['locationName'] ?? '')));
        $type = (string) $condition['matchType'];
        if ($type === 'equals') {
            return $text === $value;
        }
        if ($type === 'contains') {
            return str_contains($text, $value);
        }

        // `word`: the value, with no letter or digit immediately before or
        // after it. "open" is a word in "Open Day" and "Day (open)", but not
        // in "Reopening".
        return preg_match('/(?<![\p{L}\p{N}])' . preg_quote($value, '/') . '(?![\p{L}\p{N}])/u', $text) === 1;
    }

    /**
     * Text as a rule compares it: lower case, invisible formatting
     * characters (Unicode category Cf, such as a zero-width space) removed,
     * every run of white space turned into one space, trimmed.
     *
     * VALIDITY IS TESTED FIRST, and returns null for text that is not valid
     * UTF-8 (challenge finding 8). In the other order `mb_strtolower()`
     * quietly turns the broken bytes into "?" ("Caf\xC3" becomes "caf?"), so
     * the text would compare as if it were something else, and a check
     * waiting for a failure later could never run.
     */
    private static function normaliseText(string $text): ?string
    {
        if (preg_match('//u', $text) !== 1) {
            return null;
        }
        $text = mb_strtolower($text, 'UTF-8');
        $text = (string) preg_replace('/\p{Cf}/u', '', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim($text, ' ');
    }

    /**
     * Is any piece of this event's text that a rule can compare — its
     * title, its location, any of its category words — not valid text?
     *
     * @param array<string,mixed> $event
     * @param list<string>        $tags
     */
    private static function eventTextUnreadable(array $event, array $tags): bool
    {
        $texts   = $tags;
        $texts[] = (string) $event['eventName'];
        $texts[] = (string) ($event['locationName'] ?? '');
        foreach ($texts as $text) {
            if (preg_match('//u', $text) !== 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * A draft rule from the rule form, in the shape `plan()` reads. A value
     * of the wrong kind becomes one that makes the draft invalid (so it
     * matches nothing), never one that widens.
     *
     * @param array<string,mixed> $draft
     * @param array<string,mixed> $state
     *
     * @return array<string,mixed>
     */
    private static function normaliseDraft(array $draft, array $state): array
    {
        $ruleId = max(0, (int) ($draft['ruleID'] ?? 0));
        $conditions = [];
        foreach ((array) ($draft['conditions'] ?? []) as $condition) {
            if (is_array($condition) === false) {
                continue;
            }
            $conditions[] = [
                'isException' => (int) ($condition['isException'] ?? 0) === 1 ? 1 : 0,
                'matchField'  => (string) ($condition['matchField'] ?? ''),
                'matchType'   => (string) ($condition['matchType'] ?? ''),
                'matchValue'  => (string) ($condition['matchValue'] ?? ''),
            ];
        }

        if (array_key_exists('audience', $draft) === true) {
            $list = [];
            foreach ((array) $draft['audience'] as $member) {
                if (is_array($member) === true && isset($member['kind'], $member['refID']) === true) {
                    $list[] = (string) $member['kind'] . ':' . (int) $member['refID'];
                }
            }
            $list = array_values(array_unique($list));
            sort($list, SORT_STRING);
        } else {
            $list = $state['audience']['rule'][$ruleId] ?? [];
        }

        return [
            'ruleID'        => $ruleId,
            'audienceLevel' => (string) ($draft['audienceLevel'] ?? ''),
            'detailLevel'   => (string) ($draft['detailLevel'] ?? 'basic'),
            'websiteOptIn'  => (int) ($draft['websiteOptIn'] ?? 0) === 1 ? 1 : 0,
            'apiOptOut'     => (int) ($draft['apiOptOut'] ?? 0) === 1 ? 1 : 0,
            'fromDate'      => self::dateOrNull($draft['fromDate'] ?? null),
            'toDate'        => self::dateOrNull($draft['toDate'] ?? null),
            'isActive'      => (int) ($draft['isActive'] ?? 1) === 1 ? 1 : 0,
            'conditions'    => $conditions,
            'list'          => $list,
        ];
    }

    /** A Y-m-d date, or null for anything empty. Anything else is kept, and then counts as never active. */
    private static function dateOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return ($text === '') ? null : $text;
    }

    // =========================================================================
    // 📅 Date windows (plan section D1)
    // =========================================================================

    /**
     * Is a choice or a rule inside its dates at this moment?
     *
     * `fromDate` and `toDate` are days on the ORGANISATION's calendar. They
     * are turned into moments (`windowMoments()`) and compared with "now" as
     * moments, never as wall-clock readings — so the hour that happens twice
     * when the clocks go back is inside a window for that day both times.
     * A window whose last day is before its first is never active (the form
     * refuses one), and so is one with a date that cannot be read.
     *
     * @param array<string,mixed> $item A choice or a rule.
     */
    private static function windowActive(array $item, DateTimeImmutable $nowUtc, DateTimeZone $zone): bool
    {
        $from = $item['fromDate'] ?? null;
        $to   = $item['toDate'] ?? null;
        [$start, $end] = self::windowMoments($item, $zone);
        if (($from !== null && $start === null) || ($to !== null && $end === null)) {
            return false;
        }
        if ($from !== null && $to !== null && (string) $to < (string) $from) {
            return false;
        }

        return ($start === null || $start <= $nowUtc) && ($end === null || $nowUtc < $end);
    }

    /**
     * The first moment of `fromDate`, and the first moment of the day AFTER
     * `toDate`, both on the organisation's clock, as UTC moments (null when
     * the date is empty or cannot be read).
     *
     * @param array<string,mixed> $item
     *
     * @return array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable}
     */
    private static function windowMoments(array $item, DateTimeZone $zone): array
    {
        $start = null;
        $end   = null;
        $from  = $item['fromDate'] ?? null;
        $to    = $item['toDate'] ?? null;
        if ($from !== null && self::isCalendarDate((string) $from) === true) {
            $start = self::dayStartUtc((string) $from, $zone);
        }
        if ($to !== null && self::isCalendarDate((string) $to) === true) {
            // The day after, counted as a DATE (which has no clock and so no
            // clock change), then its first moment in the organisation's zone.
            $next = (new DateTimeImmutable((string) $to . ' 00:00:00', new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
            $end  = self::dayStartUtc($next, $zone);
        }

        return [$start, $end];
    }

    /**
     * The first moment of a day on a given clock, as a UTC moment. The ONE
     * place a window's start AND its end are worked out, so the two can
     * never be worked out two different ways.
     *
     * DAY ARITHMETIC IS NEVER DONE IN SECONDS: a day is 23 or 25 hours long
     * on the two nights a year the clocks change, so `+ 86,400` would land an
     * hour out (the same lesson `FeedImporter::windowFor()` records).
     *
     * `setTime(0, 0, 0)` settles on the earliest moment the day really has.
     * In a zone whose clocks go forward AT midnight (Chile in September) the
     * day has no 00:00 at all, and PHP lands on 01:00 — the same instant the
     * old midnight would have been, which is the instant of the change. In a
     * zone whose clocks go back at midnight (Chile in April) it is the hour
     * BEFORE midnight that repeats; the new day's midnight happens once,
     * one hour after the change. Both are measured in the self-test.
     */
    private static function dayStartUtc(string $ymd, DateTimeZone $zone): DateTimeImmutable
    {
        return (new DateTimeImmutable($ymd . ' 00:00:00', $zone))->setTime(0, 0, 0)->setTimezone(new DateTimeZone('UTC'));
    }

    /** Is this text a real date written Y-m-d? */
    private static function isCalendarDate(string $ymd): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) === true;
    }

    /**
     * The earliest start or end of any of these choices or rules that is
     * strictly after now — active or not — or null when there is none. It
     * becomes the event's `importRecheckAt`, so a window opens and closes on
     * time even if nothing else about the calendar changes.
     *
     * @param list<array<string,mixed>> $items
     */
    private static function nextBoundary(array $items, DateTimeImmutable $nowUtc, DateTimeZone $zone): ?DateTimeImmutable
    {
        $next = null;
        foreach ($items as $item) {
            foreach (self::windowMoments($item, $zone) as $moment) {
                if ($moment !== null && $moment > $nowUtc && ($next === null || $moment < $next)) {
                    $next = $moment;
                }
            }
        }

        return $next;
    }

    /** A moment written as UTC `Y-m-d H:i:s`. */
    private static function utcText(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format(self::SQL_MOMENT);
    }

    // =========================================================================
    // 🏷️ The category (unchanged from part P6)
    // =========================================================================

    /**
     * Which portal category an event gets (#514 decision D15).
     *
     * The event's own words in alphabetical order; the first that is mapped
     * to a real category wins. Failing that, the calendar's own category.
     * Failing that, none.
     *
     * Alphabetical order matters, and it is not arbitrary. An event carrying
     * both "Youth" and "Outreach" has to land in ONE portal category, and
     * whichever rule picks it has to give the same answer every time — a
     * category that changed with the order the file happened to list the
     * words in would move the event between coloured groups on the calendar
     * for no visible reason.
     *
     * @param list<string>           $tags         Already in order.
     * @param array<string,int|null> $categoryMap  Lower-cased keys.
     * @param int|null               $feedCategory The calendar's own.
     */
    private static function categoryFor(array $tags, array $categoryMap, ?int $feedCategory): ?int
    {
        foreach ($tags as $tag) {
            $key = mb_strtolower(trim($tag), 'UTF-8');
            if ($key === '') {
                continue;
            }
            if (array_key_exists($key, $categoryMap) === false) {
                continue;
            }
            if ($categoryMap[$key] === null) {
                continue;
            }

            return $categoryMap[$key];
        }

        return $feedCategory;
    }

    // =========================================================================
    // ✍️ WRITE — resolveFeed() only
    // =========================================================================

    /**
     * Apply what `plan()` worked out: the approval rows first, then each
     * event whose stored answer differs.
     *
     * The approval rows are written in an order that lets a row name the
     * date it followed: every row that follows another date (pass 2) is
     * written AFTER every other, so the number of the row it follows is
     * known by then even when that row was created in this same resolve.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $plan
     *
     * @return int How many events were rewritten.
     */
    private static function writePlan(\mysqli $db, array $state, array $plan, DateTimeImmutable $nowUtc): int
    {
        $nowText = self::utcText($nowUtc);
        $rows    = $plan['rows'];

        $ordered = [];
        foreach ([false, true] as $follower) {
            foreach ($rows as $key => $row) {
                if ($row['dirty'] === true && ($row['followsKey'] !== null) === $follower) {
                    $ordered[] = (string) $key;
                }
            }
        }

        $insert = null;
        $update = null;
        foreach ($ordered as $key) {
            $row = $rows[$key];
            if ($row['followsKey'] !== null) {
                $followed = $rows[$row['followsKey']]['approvalID'];
                $row['decisionNote'] = 'Follows the decision on another date of this repeating event (approval #'
                    . (int) $followed . ')';
            }

            // 🧷 `bind_param` binds by reference, so every value gets its own
            //    variable.
            $bContent  = (string) $row['contentHash'];
            $bStatus   = (string) $row['status'];
            $bReason   = (string) $row['reason'];
            $bLevel    = (string) $row['requestedLevel'];
            $bDetail   = (string) $row['requestedDetail'];
            $bWebsite  = (int) $row['requestedWebsite'];
            $bSummary  = (string) $row['requestedAudienceSummary'];
            $bTitle    = (string) $row['snapTitle'];
            $bStart    = (string) $row['snapStart'];
            $bEnd      = $row['snapEnd'] === null ? null : (string) $row['snapEnd'];
            $bZone     = (string) $row['snapTimezone'];
            $bAllDay   = (int) $row['snapIsAllDay'];
            $bCategory = $row['snapCategoryID'] === null ? null : (int) $row['snapCategoryID'];
            $bDesc     = $row['snapDescription'] === null ? null : (string) $row['snapDescription'];
            $bLocation = $row['snapLocation'] === null ? null : (string) $row['snapLocation'];
            $bUrl      = $row['snapUrl'] === null ? null : (string) $row['snapUrl'];
            $bDecider  = $row['decidedByID'] === null ? null : (int) $row['decidedByID'];
            $bDecided  = ($row['decidedAt'] === null || $row['decidedAt'] === '') ? null : (string) $row['decidedAt'];
            $bNote     = $row['decisionNote'] === null ? null : (string) $row['decisionNote'];

            if ($row['isNew'] === true) {
                if ($insert === null) {
                    $insert = $db->prepare(
                        'INSERT INTO tblExternalEventApprovals (siteID, feedID, eventID, origin, originID, requestHash, '
                        . 'contentHash, status, reason, requestedLevel, requestedDetail, requestedWebsite, '
                        . 'requestedAudienceSummary, snapTitle, snapStart, snapEnd, snapTimezone, snapIsAllDay, '
                        . 'snapCategoryID, snapDescription, snapLocation, snapUrl, decidedByID, decidedAt, '
                        . 'decisionNote, createdAt, updatedAt) '
                        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                }
                $bSite    = (int) $row['siteID'];
                $bFeed    = (int) $row['feedID'];
                $bEvent   = (int) $row['eventID'];
                $bOrigin  = (string) $row['origin'];
                $bOrigId  = (int) $row['originID'];
                $bRequest = (string) $row['requestHash'];
                $bCreated = (string) $row['createdAt'];
                $bUpdated = $row['updatedAt'] === null ? null : (string) $row['updatedAt'];
                // 🔢 27 values, 27 letters, in the column order above:
                //    siteID i, feedID i, eventID i, origin s, originID i,
                //    requestHash s, contentHash s, status s, reason s,
                //    requestedLevel s, requestedDetail s, requestedWebsite i,
                //    requestedAudienceSummary s, snapTitle s, snapStart s,
                //    snapEnd s, snapTimezone s, snapIsAllDay i,
                //    snapCategoryID i, snapDescription s, snapLocation s,
                //    snapUrl s, decidedByID i, decidedAt s, decisionNote s,
                //    createdAt s, updatedAt s.
                $insert->bind_param(
                    'iiisissssssisssssiisssissss',
                    $bSite,
                    $bFeed,
                    $bEvent,
                    $bOrigin,
                    $bOrigId,
                    $bRequest,
                    $bContent,
                    $bStatus,
                    $bReason,
                    $bLevel,
                    $bDetail,
                    $bWebsite,
                    $bSummary,
                    $bTitle,
                    $bStart,
                    $bEnd,
                    $bZone,
                    $bAllDay,
                    $bCategory,
                    $bDesc,
                    $bLocation,
                    $bUrl,
                    $bDecider,
                    $bDecided,
                    $bNote,
                    $bCreated,
                    $bUpdated
                );
                $insert->execute();
                $rows[$key]['approvalID'] = (int) $db->insert_id;
                continue;
            }

            if ($update === null) {
                $update = $db->prepare(
                    'UPDATE tblExternalEventApprovals SET contentHash = ?, status = ?, reason = ?, requestedLevel = ?, '
                    . 'requestedDetail = ?, requestedWebsite = ?, requestedAudienceSummary = ?, snapTitle = ?, '
                    . 'snapStart = ?, snapEnd = ?, snapTimezone = ?, snapIsAllDay = ?, snapCategoryID = ?, '
                    . 'snapDescription = ?, snapLocation = ?, snapUrl = ?, decidedByID = ?, decidedAt = ?, '
                    . 'decisionNote = ?, updatedAt = ? WHERE approvalID = ?'
                );
            }
            $bId = (int) $row['approvalID'];
            // 🔢 21 values, 21 letters, in the order above: contentHash s,
            //    status s, reason s, requestedLevel s, requestedDetail s,
            //    requestedWebsite i, requestedAudienceSummary s, snapTitle s,
            //    snapStart s, snapEnd s, snapTimezone s, snapIsAllDay i,
            //    snapCategoryID i, snapDescription s, snapLocation s,
            //    snapUrl s, decidedByID i, decidedAt s, decisionNote s,
            //    updatedAt s, approvalID i.
            $update->bind_param(
                'sssssisssssiisssisssi',
                $bContent,
                $bStatus,
                $bReason,
                $bLevel,
                $bDetail,
                $bWebsite,
                $bSummary,
                $bTitle,
                $bStart,
                $bEnd,
                $bZone,
                $bAllDay,
                $bCategory,
                $bDesc,
                $bLocation,
                $bUrl,
                $bDecider,
                $bDecided,
                $bNote,
                $nowText,
                $bId
            );
            $update->execute();
        }
        if ($insert !== null) {
            $insert->close();
        }
        if ($update !== null) {
            $update->close();
        }

        // 🗓️ The events, only where the answer differs from what is stored.
        $eventUpdate = $db->prepare(
            'UPDATE tblEvents SET importLevel = ?, importDetail = ?, importWebsite = ?, importApiOptOut = ?, '
            . 'importAudienceType = ?, importAudienceID = ?, importSource = ?, importSourceID = ?, '
            . 'importRecheckAt = ?, categoryID = ? WHERE eventID = ?'
        );
        $changed = 0;
        foreach ($state['events'] as $event) {
            $eventId = (int) $event['eventID'];
            $wanted  = $plan['answers'][$eventId];
            if (self::sameAsStored($wanted, $event) === true) {
                continue;
            }
            $bLevel    = (string) $wanted['importLevel'];
            $bDetail   = (string) $wanted['importDetail'];
            $bWebsite  = (int) $wanted['importWebsite'];
            $bApi      = (int) $wanted['importApiOptOut'];
            $bAudType  = $wanted['importAudienceType'];
            $bAudId    = $wanted['importAudienceID'];
            $bSource   = (string) $wanted['importSource'];
            $bSourceId = $wanted['importSourceID'];
            $bRecheck  = $wanted['importRecheckAt'];
            $bCategory = $wanted['categoryID'];
            // 🔢 Eleven values, eleven letters, in this order: level (text),
            //    detail (text), website (number), API opt-out (number),
            //    audience type (text), audience number, source (text), source
            //    number, re-check moment (text), category number, event number.
            $eventUpdate->bind_param(
                'ssiisisisii',
                $bLevel,
                $bDetail,
                $bWebsite,
                $bApi,
                $bAudType,
                $bAudId,
                $bSource,
                $bSourceId,
                $bRecheck,
                $bCategory,
                $eventId
            );
            $eventUpdate->execute();
            $changed++;
        }
        $eventUpdate->close();

        return $changed;
    }

    /**
     * Is the answer we have just worked out the same as the one already
     * stored?
     *
     * Every value is turned into text on both sides before it is compared,
     * with null kept apart from everything else. The reason is a trap this
     * codebase has already been bitten by and written down: a prepared
     * statement hands a whole number back as the number 1, not the text '1',
     * so `=== '1'` quietly says no and a row is rewritten on every single
     * refresh. Rewriting rows that have not changed is not merely wasteful —
     * it touches `updatedAt` on every event of every calendar, several times
     * a day, which makes "what changed recently?" useless.
     *
     * @param array<string,mixed> $wanted
     * @param array<string,mixed> $stored
     */
    private static function sameAsStored(array $wanted, array $stored): bool
    {
        foreach ($wanted as $column => $value) {
            $storedValue = $stored[$column] ?? null;
            if ($value === null || $storedValue === null) {
                if ($value !== null || $storedValue !== null) {
                    return false;
                }
                continue;
            }
            if ((string) $value !== (string) $storedValue) {
                return false;
            }
        }

        return true;
    }
}
