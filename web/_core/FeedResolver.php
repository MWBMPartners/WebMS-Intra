<?php
// Path: _core/FeedResolver.php
/**
 * -----------------------------------------------------------------------------
 * Who may see each copied-in event, worked out once and written down 👁️🗓️
 * -----------------------------------------------------------------------------
 * A copied-in event is one the portal downloaded from somebody else's
 * calendar (a Google, Microsoft 365 or other published calendar file). Who
 * may see it is NOT decided when somebody looks at it. It is decided here,
 * once, after every refresh, and the answer is written into the event's own
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
 * So the failure is closed, never open.
 *
 * -----------------------------------------------------------------------------
 * THE FULL SET OF RULES — SEVEN STEPS. THIS PART BUILDS 1, 2 AND 7.
 * -----------------------------------------------------------------------------
 * The #514 plan (section 1.8) sets out seven steps. Part P6 builds the three
 * that need nothing but the calendar's own settings. Part P7 adds the four
 * in the middle, which need tables that do not exist yet (a per-date choice,
 * a rule, and the approvals those can wait on). The whole list is written
 * out here so that P7 has one place to work from and so nobody reading this
 * file thinks the three steps below are all there is:
 *
 *   1. DUPLICATE. If the last download held this identity twice
 *      (`externalDuplicate = 1`), the answer is the calendar's own setting
 *      and nothing else is applied. The reason: when two events in one file
 *      claim to be the same event, there is no way to tell which of them an
 *      administrator's per-event choice was about, so applying that choice
 *      could easily apply it to the wrong one. Source is `duplicate`, or
 *      `private` when the file also marked it private.
 *      >>> BUILT HERE <<<
 *
 *   2. PRIVATE WITH NO CHOICE. An event the outside calendar marked private
 *      or confidential starts at "administrators only" and stays there
 *      unless an administrator deliberately says otherwise for that event.
 *      Source is `private`.
 *      >>> BUILT HERE (there are no choices yet, so every private event
 *      stops here) <<<
 *
 *   3. AN ACTIVE CHOICE for this exact date, or failing that for the whole
 *      repeating event.                                      (part P7)
 *   4. ACTIVE RULES, only when no choice applied.             (part P7)
 *   5. IS THE ANSWER WIDER than the calendar's own setting?   (part P7)
 *   6. IF IT IS WIDER, an administrator has to agree to it first. (part P7)
 *
 *   7. WRITE the answer, and only when it differs from what is already
 *      stored — so an unchanged refresh does not touch a single row.
 *      >>> BUILT HERE <<<
 *
 * -----------------------------------------------------------------------------
 * WHAT THIS CLASS DOES NOT DO, AND MUST NOT START DOING
 * -----------------------------------------------------------------------------
 * - It never decides whether ONE person may see ONE event. That is
 *   `EventVisibility`, which reads what this writes. Two places deciding the
 *   same thing is how a portal ends up disagreeing with itself.
 * - It never widens anything on its own. In this part the widest answer it
 *   can produce is the calendar's own setting, which an administrator chose.
 * - It never takes the lock. Every caller must already hold the calendar's
 *   row lock (`SELECT feedID FROM tblExternalFeeds WHERE feedID = ? FOR
 *   UPDATE`) inside its own transaction. That is what stops a refresh and a
 *   pause from interleaving — which the #514 leak hunt (finding 13) showed
 *   could otherwise let a refresh write new, visible rows for a calendar
 *   somebody had just switched off.
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

use DateTimeImmutable;

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
     * @param DateTimeImmutable $nowUtc      "Now" as a UTC moment. Passed in
     *                                       rather than read here so that
     *                                       every part of one refresh agrees
     *                                       about when it happened, and so a
     *                                       test can choose the moment.
     * @param array|null        $savedChoice Declared for part P7, which uses
     *                                       it to recognise the dates an
     *                                       administrator has just SEEN on a
     *                                       choice page and agreed to. This
     *                                       part accepts it and ignores it,
     *                                       so P7 can fill it in without
     *                                       changing a single caller. See the
     *                                       #514 plan, section 1.8, step 6.
     *
     * @return array{changed:int, newPending:int} `changed` is how many events
     *         had their stored answer rewritten. `newPending` is how many are
     *         waiting for an administrator to agree to something; it is
     *         always 0 in this part, because nothing here can ever produce an
     *         answer wider than the calendar's own setting, and only a wider
     *         answer ever waits.
     */
    public static function resolveFeed(
        \mysqli $db,
        int $feedId,
        DateTimeImmutable $nowUtc,
        ?array $savedChoice = null
    ): array {
        // 🙈 $savedChoice and $nowUtc are deliberately unused in this part.
        //    They are in the signature because part P7 needs them and every
        //    caller here would otherwise have to be edited again. Referencing
        //    them keeps static analysis honest about that being on purpose.
        unset($savedChoice, $nowUtc);

        $feed = self::feedRow($db, $feedId);
        if ($feed === null) {
            // The calendar is gone. Nothing of it should be visible, and the
            // visibility rule already answers "no" for an event whose
            // calendar row does not exist, so there is nothing to write.
            return ['changed' => 0, 'newPending' => 0];
        }

        $categoryMap = self::categoryMap($db, $feedId);
        $events      = self::liveEvents($db, $feedId);
        $tags        = self::tagsByEvent($db, $feedId);

        // 📐 The calendar's own setting, which is the answer for every event
        //    in this part unless the file marked the event private.
        $feedLevel   = (string) $feed['audienceLevel'];
        $feedWebsite = ((int) $feed['websiteOptIn'] === 1 && $feedLevel === 'public') ? 1 : 0;
        $feedCategory = $feed['categoryID'] === null ? null : (int) $feed['categoryID'];

        $update = $db->prepare(
            'UPDATE tblEvents SET importLevel = ?, importDetail = ?, importWebsite = ?, '
            . 'importAudienceType = ?, importAudienceID = ?, importSource = ?, importSourceID = ?, '
            . 'importRecheckAt = ?, categoryID = ? WHERE eventID = ?'
        );

        $changed = 0;
        foreach ($events as $event) {
            $eventId  = (int) $event['eventID'];
            $private  = ((int) $event['externalPrivate'] === 1);
            $duplicate = ((int) $event['externalDuplicate'] === 1);

            // 🏷️ Step "category" of the plan's section 1.8: the event's own
            //    words in alphabetical order, first one that is mapped to a
            //    real portal category wins; failing that the calendar's own
            //    category; failing that none.
            $category = self::categoryFor($tags[$eventId] ?? [], $categoryMap, $feedCategory);

            // 1️⃣ + 2️⃣ Duplicate, and private-with-no-choice. Both end at the
            //    calendar's own setting; only the reason recorded differs, and
            //    the reason is what an administrator is shown. A private mark
            //    outranks a duplicate mark, because "the calendar said this is
            //    confidential" is the more important thing to say.
            if ($private === true) {
                $level   = 'hidden';
                $website = 0;
                $source  = 'private';
            } else {
                $level   = $feedLevel;
                $website = $feedWebsite;
                $source  = ($duplicate === true) ? 'duplicate' : 'calendar';
            }

            // 👥 Which list of people the "groups" level uses. It is the
            //    calendar's own list here, because the calendar's own setting
            //    is the answer. At "hidden" nobody is on a list at all, so
            //    both columns are emptied — leaving a stale list behind would
            //    make an administrator page show people who cannot see it.
            $audienceType = ($level === 'hidden') ? null : 'feed';
            $audienceId   = ($level === 'hidden') ? null : $feedId;

            $wanted = [
                'importLevel'        => $level,
                // Detail is always "full" in this part: only a per-event
                // choice or a rule can cut an event down to title, date and
                // time, and neither exists yet (plan section 1.8, step 7).
                'importDetail'       => 'full',
                'importWebsite'      => $website,
                'importAudienceType' => $audienceType,
                'importAudienceID'   => $audienceId,
                'importSource'       => $source,
                // No choice and no rule, so there is no choice or rule number
                // to name.
                'importSourceID'     => null,
                // Nothing here has an end date, so the stored answer never
                // goes out of date and there is nothing to re-check.
                'importRecheckAt'    => null,
                'categoryID'         => $category,
            ];

            if (self::sameAsStored($wanted, $event) === true) {
                continue;
            }

            // 🧷 `bind_param` needs variables, not expressions, and it binds
            //    by reference — so every value gets its own named variable.
            $bLevel     = (string) $wanted['importLevel'];
            $bDetail    = (string) $wanted['importDetail'];
            $bWebsite   = (int) $wanted['importWebsite'];
            $bAudType   = $wanted['importAudienceType'];
            $bAudId     = $wanted['importAudienceID'];
            $bSource    = (string) $wanted['importSource'];
            $bSourceId  = $wanted['importSourceID'];
            $bRecheck   = $wanted['importRecheckAt'];
            $bCategory  = $wanted['categoryID'];
            // 🔢 Ten values, ten letters, in this order: level (text), detail
            //    (text), website (number), audience type (text), audience
            //    number, source (text), source number, re-check moment (text),
            //    category number, event number.
            $update->bind_param(
                'ssisisisii',
                $bLevel,
                $bDetail,
                $bWebsite,
                $bAudType,
                $bAudId,
                $bSource,
                $bSourceId,
                $bRecheck,
                $bCategory,
                $eventId
            );
            $update->execute();
            $changed++;
        }
        $update->close();

        return ['changed' => $changed, 'newPending' => 0];
    }

    // =========================================================================
    // 🧱 The pieces
    // =========================================================================

    /**
     * The calendar's own row, or null when it is gone.
     *
     * @return array<string,mixed>|null
     */
    private static function feedRow(\mysqli $db, int $feedId): ?array
    {
        $stmt = $db->prepare(
            'SELECT feedID, siteID, audienceLevel, websiteOptIn, categoryID '
            . 'FROM tblExternalFeeds WHERE feedID = ? LIMIT 1'
        );
        $stmt->bind_param('i', $feedId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row === null ? null : $row;
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
     * is nothing to decide about them, and including them would mean writing
     * to rows nobody reads on every single refresh.
     *
     * @return list<array<string,mixed>>
     */
    private static function liveEvents(\mysqli $db, int $feedId): array
    {
        $rows = [];
        $stmt = $db->prepare(
            'SELECT eventID, externalPrivate, externalDuplicate, importLevel, importDetail, '
            . 'importWebsite, importAudienceType, importAudienceID, importSource, importSourceID, '
            . 'importRecheckAt, categoryID '
            . 'FROM tblEvents WHERE externalFeedID = ? AND isDeleted = 0'
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
        //    the lowered text makes that impossible.
        foreach ($tags as $eventId => $list) {
            usort($list, static function (string $a, string $b): int {
                return mb_strtolower($a, 'UTF-8') <=> mb_strtolower($b, 'UTF-8');
            });
            $tags[$eventId] = $list;
        }

        return $tags;
    }

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
