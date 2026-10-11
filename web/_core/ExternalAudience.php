<?php
// Path: _core/ExternalAudience.php
/**
 * -----------------------------------------------------------------------------
 * "Who may see it" lists for an outside calendar, a choice or a rule 👥🗓️
 * -----------------------------------------------------------------------------
 * When an administrator picks "Selected groups and people" for a calendar,
 * a single-date or series choice, or a rule, the actual list of who is
 * meant lives in `tblExternalAudienceMembers` — one row per small group,
 * leadership role or named person. `Portal\Core\EventVisibility` reads that
 * table live, at the moment somebody looks at the calendar, so a person
 * taken off a small group or removed from the organisation stops seeing the
 * event at once, with nothing to "resolve" or refresh.
 *
 * This class is the ONE place the administrator pages (#514 part P8) read
 * or write that table. It never decides who may SEE an event — that stays
 * `EventVisibility`'s job — it only manages the LIST an administrator is
 * offered, ticks and saves.
 *
 * WHAT "OFFERED" MEANS, AND WHY IT MATTERS FOR "DELETE MY DATA"
 * ---------------------------------------------------------------------------
 * `OFFERED_KINDS` is the list of member kinds part P8's forms can actually
 * pick from today: small groups, leadership roles and named people. Part
 * P10 (a later part of #514) is expected to add `role`, `user_group` and
 * `department` — the ENUM on the table already has room for them
 * (migration 204), so a hand-made row of one of those kinds can already
 * exist. `current()` shows such a row, labelled `not_offered`, rather than
 * pretending it is not there — and says it WILL BE REMOVED when the list is
 * next saved, exactly as it says for a `gone` entry. It cannot be kept:
 * posting it back is refused with `REFUSED_MESSAGE`, like any kind the
 * forms do not offer.
 *   Why removed, not kept (decided by the commissioning session, fix round
 *   1, checker LOW 5; the first version of this header said "never
 *   dropped", while the code dropped it anyway, silently): such a row
 *   matches NOBODY today (`EventVisibility` answers `0 = 1` for those
 *   kinds), so removing it changes nobody's access now. Kept, it would
 *   silently START matching people the day part P10 teaches
 *   `EventVisibility` those kinds — a widening nobody chose through a form.
 *   Saying so on the page, before the save, is what makes the removal
 *   announced rather than quiet.
 *
 * `audienceLabel()` in `Portal\Core\FeedAdmin` (part P8) shows COUNTS only,
 * never names, for the same reason `FeedResolver::audienceSummary()` does:
 * a name baked into a label could survive after the person themselves was
 * erased. The live names shown on an administrator page come from
 * `current()`, which `GdprEraser`'s catalogue entry for this table reaches
 * directly by deleting the row.
 *
 * WHY A "GONE" PERSON IS NEVER NAMED (challenge finding L16)
 * ---------------------------------------------------------------------------
 * A stored `person` row can point at an account that is no longer a member
 * of THIS organisation at all — its only `tblUserSites` row for this
 * organisation was deleted, most often by the offboarding tool. Looking
 * the account up in `tblUsers` alone, with no organisation in the query,
 * would still find a name — but it could be the name of somebody who is
 * now a member of a DIFFERENT organisation, and showing it here would leak
 * that they exist to an administrator who has no business seeing them.
 * So `current()` labels such a row "someone no longer in this
 * organisation" and never reads `tblUsers.fullName` for it. Every other
 * name this class shows comes from a join that names THIS organisation's
 * `siteID` explicitly, never from `tblUsers` on its own.
 *
 * THE "Group" TABLE-NAME TRAP (house workaround, same shape as
 * `EventVisibility.php` and `SmallGroups::listGroups()`)
 * ---------------------------------------------------------------------------
 * `tools/audit-checks/check_sql_columns.py` reads the letters "Group"
 * inside `tblSmallGroups` as the start of a `GROUP BY` clause and
 * mis-reports an unknown table `tblSmallGroup`. The fix already used
 * elsewhere in this codebase is to never write a short alias straight
 * after `FROM tblSmallGroups` — every statement here that reads that table
 * as its FROM target writes the column names out in full instead. A
 * `tblSmallGroups` reached through a `JOIN` (with an alias) is not the
 * trap and is written normally.
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

final class ExternalAudience
{
    /**
     * The kinds part P8's forms can actually offer today. `role`,
     * `user_group` and `department` exist on the database ENUM
     * (migration 204) for a later part of #514 (P10) to use; a row of one
     * of those kinds is read here as `not_offered`, and is removed — openly,
     * see the class header — when its list is next saved.
     *
     * @var list<string>
     */
    public const OFFERED_KINDS = ['small_group', 'leadership_role', 'person'];

    /** The most groups and people one list may name (plan section 1.5). */
    public const MAX_MEMBERS = 50;

    /**
     * ONE sentence for every reason a posted list is refused (leak-hunt
     * finding 26c): a wrong kind, a number that is not a whole number, a
     * group/role/person that belongs to another organisation, or a NEW
     * entry that is currently switched off or inactive. Naming the exact
     * reason back to the administrator would mean telling them, for
     * example, that account number 47 exists somewhere — which is exactly
     * the kind of leak the rest of #514 is built to close.
     */
    public const REFUSED_MESSAGE = 'One of the chosen groups or people is not part of this organisation.';

    /** The words each state is shown with on an administrator page. */
    private const STATE_LABELS = [
        'switched_off' => '(switched off)',
        'left'         => '(no longer a member)',
        'gone'         => '(will be removed when you save)',
        'not_offered'  => '(not used by this version, so it will be removed when you save)',
    ];

    // =========================================================================
    // 📋 options() — what an administrator may pick from
    // =========================================================================

    /**
     * Every small group, leadership role and member this organisation could
     * pick for a list today: active groups, active roles, and an ACTIVE
     * account with an ACTIVE membership row of this organisation (the
     * strict "member" meaning the #514 plan settled on).
     *
     * @return array{small_group: list<array{groupID:int, groupName:string}>,
     *               leadership_role: list<array{roleID:int, roleName:string}>,
     *               person: list<array{userID:int, fullName:string}>}
     */
    public static function options(\mysqli $db, int $siteId): array
    {
        $groups = [];
        // 🪤 No short alias straight after FROM tblSmallGroups — see the
        //    class header's "Group" trap note.
        $stmt = $db->prepare(
            'SELECT groupID, groupName FROM tblSmallGroups '
            . 'WHERE siteID = ? AND isActive = 1 ORDER BY groupName, groupID'
        );
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $groups[] = ['groupID' => (int) $row['groupID'], 'groupName' => (string) $row['groupName']];
        }
        $stmt->close();

        $roles = [];
        $stmt  = $db->prepare(
            'SELECT roleID, roleName FROM tblLeadershipRoles WHERE siteID = ? AND isActive = 1 ORDER BY roleName, roleID'
        );
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $roles[] = ['roleID' => (int) $row['roleID'], 'roleName' => (string) $row['roleName']];
        }
        $stmt->close();

        $people = [];
        $stmt   = $db->prepare(
            'SELECT u.userID, u.fullName FROM tblUsers u '
            . 'JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
            . 'WHERE u.isActive = 1 ORDER BY u.fullName, u.userID'
        );
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $people[] = ['userID' => (int) $row['userID'], 'fullName' => (string) ($row['fullName'] ?? ('Member #' . $row['userID']))];
        }
        $stmt->close();

        return ['small_group' => $groups, 'leadership_role' => $roles, 'person' => $people];
    }

    // =========================================================================
    // 📖 current() / currentMany() — saved lists, with a label and a state
    // =========================================================================

    /**
     * The saved list for one owner (a calendar, a choice or a rule), each
     * row with a live label and a state: `ok` (still exactly what it looks
     * like); `switched_off` (the group or role exists here but is
     * inactive); `left` (the person's membership here is inactive, or
     * their account is); `gone` (nothing of theirs exists in this
     * organisation any more); `not_offered` (a kind this version's forms do
     * not offer — see the class header; removed when the list is saved).
     *
     * One owner's list through `currentMany()`, so a single list and a page
     * of lists can never be labelled two different ways.
     *
     * @return list<array{kind:string, refID:int, label:string, state:string}>
     */
    public static function current(\mysqli $db, int $siteId, int $feedId, string $ownerType, int $ownerId): array
    {
        $lists = self::currentMany($db, $siteId, [['feedID' => $feedId, 'ownerType' => $ownerType, 'ownerID' => $ownerId]]);

        return $lists[self::ownerKey($feedId, $ownerType, $ownerId)] ?? [];
    }

    /**
     * The key `currentMany()` files one owner's list under.
     */
    public static function ownerKey(int $feedId, string $ownerType, int $ownerId): string
    {
        return $feedId . ':' . $ownerType . ':' . $ownerId;
    }

    /**
     * The saved lists of MANY owners at once, in ONE statement however many
     * owners and rows there are (fix round 1, checker M6: the calendar list
     * and the Approvals inbox used to read each list separately, with one
     * more statement per group, role or person on it — about 1,000
     * statements for a full inbox page). Exactly the labels and states
     * `current()` gives, because `current()` is this method for one owner.
     *
     * `$owners` is a list of `['feedID' => int, 'ownerType' => string,
     * 'ownerID' => int]`. The answer maps `ownerKey()` of EVERY owner asked
     * for to its list (an empty list when it has none), each list in the
     * order its rows were saved.
     *
     * HOW EVERY NAME STAYS INSIDE THIS ORGANISATION (challenge L16): each
     * label comes through a join that names THIS organisation's `siteID`
     * explicitly, as a bound value — a group or role by its number AND
     * `siteID = ?`; a person only through their `tblUserSites` row for
     * `siteID = ?` (active or not). A row whose join finds nothing is
     * `gone`, and a gone PERSON is "someone no longer in this
     * organisation", never a name read from `tblUsers` on its own — that
     * name could belong to somebody now in a different organisation. The
     * list rows themselves are read with `siteID = ?` too, so another
     * organisation's owner of the same number is simply not there.
     *
     * (The "Group" table-name trap, see the class header: `tblSmallGroups`
     * is reached through a `LEFT JOIN` with a short name, which is not the
     * trap; the statement's `FROM` table is `tblExternalAudienceMembers`.)
     *
     * @param list<array{feedID:int, ownerType:string, ownerID:int}> $owners
     *
     * @return array<string, list<array{kind:string, refID:int, label:string, state:string}>>
     */
    public static function currentMany(\mysqli $db, int $siteId, array $owners): array
    {
        $out    = [];
        $wanted = [];
        foreach ($owners as $o) {
            $key = self::ownerKey((int) $o['feedID'], (string) $o['ownerType'], (int) $o['ownerID']);
            if (isset($wanted[$key]) === false) {
                $wanted[$key] = [(int) $o['feedID'], (string) $o['ownerType'], (int) $o['ownerID']];
                $out[$key]    = [];
            }
        }
        if ($wanted === []) {
            return [];
        }

        $ownerTests = implode(' OR ', array_fill(0, count($wanted), '(m.feedID = ? AND m.ownerType = ? AND m.ownerID = ?)'));
        $stmt       = $db->prepare(
            'SELECT m.feedID, m.ownerType, m.ownerID, m.kind, m.refID, '
            . 'g.groupID AS foundGroup, g.groupName, g.isActive AS groupActive, '
            . 'r.roleID AS foundRole, r.roleName, r.isActive AS roleActive, '
            . 'us.userID AS foundMember, us.isActive AS memberActive, u.userID AS foundAccount, u.fullName, '
            . 'u.isActive AS acctActive '
            . 'FROM tblExternalAudienceMembers m '
            . "LEFT JOIN tblSmallGroups g ON m.kind = 'small_group' AND g.groupID = m.refID AND g.siteID = ? "
            . "LEFT JOIN tblLeadershipRoles r ON m.kind = 'leadership_role' AND r.roleID = m.refID AND r.siteID = ? "
            . "LEFT JOIN tblUserSites us ON m.kind = 'person' AND us.userID = m.refID AND us.siteID = ? "
            . 'LEFT JOIN tblUsers u ON u.userID = us.userID '
            . "WHERE m.siteID = ? AND ($ownerTests) "
            . 'ORDER BY m.audienceMemberID'
        );
        $types  = 'iiii';
        $params = [$siteId, $siteId, $siteId, $siteId];
        foreach ($wanted as [$fid, $type, $oid]) {
            $types   .= 'isi';
            $params[] = $fid;
            $params[] = $type;
            $params[] = $oid;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $key = self::ownerKey((int) $row['feedID'], (string) $row['ownerType'], (int) $row['ownerID']);
            if (isset($out[$key]) === false) {
                continue; // not asked for (cannot happen with the WHERE above; kept as a guard)
            }
            $out[$key][] = self::labelled($row);
        }
        $stmt->close();

        return $out;
    }

    /**
     * One list row of `currentMany()`'s statement as `{kind, refID, label,
     * state}` — the ONE place the words and states are decided.
     *
     * @param array<string,mixed> $row
     *
     * @return array{kind:string, refID:int, label:string, state:string}
     */
    private static function labelled(array $row): array
    {
        $kind  = (string) $row['kind'];
        $refId = (int) $row['refID'];

        if (in_array($kind, self::OFFERED_KINDS, true) === false) {
            return ['kind' => $kind, 'refID' => $refId, 'label' => self::kindWord($kind) . ' #' . $refId, 'state' => 'not_offered'];
        }
        if ($kind === 'small_group') {
            if ($row['foundGroup'] === null) {
                return ['kind' => $kind, 'refID' => $refId, 'label' => 'a small group that no longer exists', 'state' => 'gone'];
            }

            return [
                'kind' => $kind, 'refID' => $refId, 'label' => (string) $row['groupName'],
                'state' => ((int) $row['groupActive'] === 1) ? 'ok' : 'switched_off',
            ];
        }
        if ($kind === 'leadership_role') {
            if ($row['foundRole'] === null) {
                return ['kind' => $kind, 'refID' => $refId, 'label' => 'a leadership role that no longer exists', 'state' => 'gone'];
            }

            return [
                'kind' => $kind, 'refID' => $refId, 'label' => (string) $row['roleName'],
                'state' => ((int) $row['roleActive'] === 1) ? 'ok' : 'switched_off',
            ];
        }

        // 👤 person — the only kind where a "gone" row must never carry a
        //    name (challenge L16; see the class header). Both the membership
        //    row HERE and the account must be found, exactly as the old
        //    single-list query's inner join required.
        if ($row['foundMember'] === null || $row['foundAccount'] === null) {
            return ['kind' => $kind, 'refID' => $refId, 'label' => 'someone no longer in this organisation', 'state' => 'gone'];
        }
        $active = ((int) $row['memberActive'] === 1 && (int) $row['acctActive'] === 1);

        return [
            'kind' => $kind, 'refID' => $refId, 'label' => (string) ($row['fullName'] ?? ('Member #' . $refId)),
            'state' => ($active === true) ? 'ok' : 'left',
        ];
    }

    /** The plain-English suffix shown beside a not-fully-live entry, or ''. */
    public static function stateLabel(string $state): string
    {
        return self::STATE_LABELS[$state] ?? '';
    }

    // =========================================================================
    // ✅ fromPost() — read and validate a posted list, against what is offered
    // =========================================================================

    /**
     * Read `audience[<kind>][]` from a posted form and validate it: every
     * value a whole number; every kind one of `OFFERED_KINDS`; every entry
     * belonging to this organisation; and a NEW entry (not already on
     * `$current`) must also be active today — a switched-off group or a
     * person who has left cannot be freshly ADDED, but one already on the
     * list stays until an administrator deliberately removes it (D7).
     * A kind the forms do not offer (a `not_offered` row) is refused like any
     * unknown kind, even when it is already on the list: it is removed on
     * save, openly (see the class header, fix round 1 checker LOW 5). A
     * `gone` entry posted back is refused too — it no longer belongs to this
     * organisation.
     *
     * @param array<string,mixed>                                       $post
     * @param list<array{kind:string, refID:int, label:string, state:string}> $current
     *
     * @return array{ok:bool, message:string, members:list<array{kind:string, refID:int}>}
     */
    public static function fromPost(\mysqli $db, int $siteId, array $post, array $current): array
    {
        $currentKeys = [];
        foreach ($current as $c) {
            $currentKeys[$c['kind'] . ':' . $c['refID']] = true;
        }

        $audience = $post['audience'] ?? [];
        if (is_array($audience) === false) {
            return ['ok' => false, 'message' => self::REFUSED_MESSAGE, 'members' => []];
        }

        $collected = [];
        foreach ($audience as $kind => $values) {
            $kind = (string) $kind;
            if (in_array($kind, self::OFFERED_KINDS, true) === false) {
                return ['ok' => false, 'message' => self::REFUSED_MESSAGE, 'members' => []];
            }
            if (is_array($values) === false) {
                return ['ok' => false, 'message' => self::REFUSED_MESSAGE, 'members' => []];
            }
            foreach ($values as $value) {
                // 🔢 A whole number only — `"12abc"`, `"1.5"`, an array, a
                //    negative or zero all refuse. `FILTER_VALIDATE_INT`
                //    reads leading/trailing spaces safely and refuses
                //    anything else outright.
                $refId = filter_var($value, FILTER_VALIDATE_INT);
                if ($refId === false || $refId <= 0) {
                    return ['ok' => false, 'message' => self::REFUSED_MESSAGE, 'members' => []];
                }
                $collected[$kind . ':' . $refId] = ['kind' => $kind, 'refID' => $refId];
            }
        }

        if (count($collected) > self::MAX_MEMBERS) {
            return ['ok' => false, 'message' => 'A list can name at most ' . self::MAX_MEMBERS . ' groups and people.', 'members' => []];
        }

        foreach ($collected as $key => $member) {
            $isNew = isset($currentKeys[$key]) === false;
            if (self::belongsToOrganisation($db, $siteId, $member['kind'], $member['refID'], $isNew) === false) {
                return ['ok' => false, 'message' => self::REFUSED_MESSAGE, 'members' => []];
            }
        }

        return ['ok' => true, 'message' => '', 'members' => array_values($collected)];
    }

    // =========================================================================
    // 💾 save() / remove() — the only two writers of this table for part P8
    // =========================================================================

    /**
     * Replace one owner's list wholesale: every existing row for
     * `(ownerType, ownerID, feedID, siteID)` is removed, then every member
     * given is inserted fresh. Called from inside the caller's own
     * transaction (`FeedAdmin::inFeedLock()`), never its own.
     *
     * @param list<array{kind:string, refID:int}> $members
     */
    public static function save(\mysqli $db, int $siteId, int $feedId, string $ownerType, int $ownerId, array $members, int $byUserId): void
    {
        self::deleteOwnerRows($db, $siteId, $feedId, $ownerType, $ownerId);
        if ($members === []) {
            return;
        }

        $stmt = $db->prepare(
            'INSERT INTO tblExternalAudienceMembers (siteID, feedID, ownerType, ownerID, kind, refID, userID, createdByID, createdAt) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        foreach ($members as $member) {
            $kind   = (string) $member['kind'];
            $refId  = (int) $member['refID'];
            $userId = ($kind === 'person') ? $refId : null;
            $stmt->bind_param('iisisiii', $siteId, $feedId, $ownerType, $ownerId, $kind, $refId, $userId, $byUserId);
            $stmt->execute();
        }
        $stmt->close();
    }

    /** Remove one owner's list entirely (a choice removed, a rule deleted). */
    public static function remove(\mysqli $db, int $siteId, int $feedId, string $ownerType, int $ownerId): void
    {
        self::deleteOwnerRows($db, $siteId, $feedId, $ownerType, $ownerId);
    }

    // =========================================================================
    // 🔒 Private helpers
    // =========================================================================

    private static function deleteOwnerRows(\mysqli $db, int $siteId, int $feedId, string $ownerType, int $ownerId): void
    {
        $stmt = $db->prepare(
            'DELETE FROM tblExternalAudienceMembers WHERE ownerType = ? AND ownerID = ? AND feedID = ? AND siteID = ?'
        );
        $stmt->bind_param('siii', $ownerType, $ownerId, $feedId, $siteId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * One row, or null. `$types`/`$params` follow `mysqli_stmt::bind_param()`.
     *
     * @param list<int|string> $params
     *
     * @return array<string,mixed>|null
     */
    private static function oneRow(\mysqli $db, string $sql, string $types, array $params): ?array
    {
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row;
    }

    /**
     * Does this group/role/person belong to this organisation? `$requireActive`
     * is true only for a NEW entry (D7): a group, role or membership already
     * on the list may stay even though it has since been switched off or the
     * person has left — only a fresh addition must be live today.
     */
    private static function belongsToOrganisation(\mysqli $db, int $siteId, string $kind, int $refId, bool $requireActive): bool
    {
        if ($kind === 'small_group') {
            $sql = $requireActive === true
                ? 'SELECT 1 FROM tblSmallGroups WHERE groupID = ? AND siteID = ? AND isActive = 1 LIMIT 1'
                : 'SELECT 1 FROM tblSmallGroups WHERE groupID = ? AND siteID = ? LIMIT 1';

            return self::oneRow($db, $sql, 'ii', [$refId, $siteId]) !== null;
        }
        if ($kind === 'leadership_role') {
            $sql = $requireActive === true
                ? 'SELECT 1 FROM tblLeadershipRoles WHERE roleID = ? AND siteID = ? AND isActive = 1 LIMIT 1'
                : 'SELECT 1 FROM tblLeadershipRoles WHERE roleID = ? AND siteID = ? LIMIT 1';

            return self::oneRow($db, $sql, 'ii', [$refId, $siteId]) !== null;
        }
        // person
        $sql = $requireActive === true
            ? 'SELECT 1 FROM tblUserSites us JOIN tblUsers u ON u.userID = us.userID '
                . 'WHERE us.userID = ? AND us.siteID = ? AND us.isActive = 1 AND u.isActive = 1 LIMIT 1'
            : 'SELECT 1 FROM tblUserSites WHERE userID = ? AND siteID = ? LIMIT 1';

        return self::oneRow($db, $sql, 'ii', [$refId, $siteId]) !== null;
    }

    /** The plain-English word for a kind, used only for a `not_offered` label. */
    private static function kindWord(string $kind): string
    {
        return match ($kind) {
            'role'        => 'a role',
            'user_group'  => 'a user group',
            'department'  => 'a department',
            default       => 'an entry',
        };
    }
}
