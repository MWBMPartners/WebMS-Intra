<?php
// Path: _core/ExternalAudience.php
/**
 * -----------------------------------------------------------------------------
 * "Who may see it" lists for an outside calendar, a choice or a rule 👥🗓️
 * -----------------------------------------------------------------------------
 * When an administrator picks "Selected groups and people" for a calendar,
 * a single-date or series choice, or a rule, the actual list of who is
 * meant lives in `tblExternalAudienceMembers` — one row per small group,
 * leadership role, role, user group, department or named person.
 * `Portal\Core\EventVisibility` reads that
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
 * `OFFERED_KINDS` is the list of member kinds the forms can pick from. Part
 * P8 offered three: small groups, leadership roles and named people. Part
 * P10 of #514 added the other three the table's ENUM always had room for
 * (migration 204): `role` (#516), `user_group` and `department` (#517). So
 * today every kind the database knows is offered, and
 * `tools/feed-admin-selftest.php` fails if the two lists ever differ.
 *
 * THE KINDS LIVE IN FOUR PLACES, and a new one must be added to all four in
 * the same change: the rule (`EventVisibility`'s audience piece), its fast
 * copy (`FeedAdmin::whoCanSee()`), this class (the list, its labels and what
 * may be saved), and the picker (`admin/calendar/_audience-picker.php`).
 *
 * `not_offered` is what is left for a kind the DATABASE knows and this
 * code does not — which cannot happen today, and is kept as the guard for
 * the day somebody adds a kind to the ENUM and forgets this class.
 * `current()` shows such a row rather than pretending it is not there — and
 * says it WILL BE REMOVED when the list is next saved, exactly as it says
 * for a `gone` entry. It cannot be kept: posting it back is refused with
 * `REFUSED_MESSAGE`, like any kind the forms do not offer.
 *   Why removed, not kept (decided by the commissioning session, fix round
 *   1 of part P8, checker LOW 5; the first version of this header said
 *   "never dropped", while the code dropped it anyway, silently): a row of
 *   a kind the rule does not know
 *   matches NOBODY, so removing it changes nobody's access. Kept, it would
 *   silently START matching people the day the rule learns that kind — a
 *   widening nobody chose through a form. Saying so on the page, before
 *   the save, is what makes the removal announced rather than quiet.
 *   (That is exactly what part P10 would otherwise have done to any
 *   hand-made role, user group or department row.)
 *
 * A ROLE, USER GROUP OR DEPARTMENT OF ANOTHER ORGANISATION is never a
 * `not_offered` row. It is `gone`: its number names nothing in THIS
 * organisation, so its real name is never read, it cannot be kept, and it
 * is removed on the next save — the same as another organisation's small
 * group or leadership role. `EventVisibility` lets nobody in through it
 * while it is still there.
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
 * trap and is written normally. The user-group table, `tblGroups`, is
 * reached here ONLY through a `JOIN` (in `currentMany()`), for the same
 * reason; everything else about user groups, roles and departments is
 * asked of `UserGroups`, `Roles` and `Departments`, so this class holds no
 * second copy of their statements.
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
     * The kinds the forms offer: every kind the database's ENUM holds
     * (migration 204), in the order the picker draws them. `role`,
     * `user_group` and `department` joined in part P10 of #514. A row of any
     * OTHER kind — one the database learns before this class does — is read
     * here as `not_offered`, and is removed, openly, when its list is next
     * saved (see the class header).
     *
     * @var list<string>
     */
    public const OFFERED_KINDS = ['small_group', 'leadership_role', 'role', 'user_group', 'department', 'person'];

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
     * Everything this organisation could pick for a list today: its
     * switched-on small groups and leadership roles, EVERY role it has
     * (roles have no on/off switch), its user groups and departments that
     * are in use, and each ACTIVE account with an ACTIVE membership row of
     * this organisation (the strict "member" meaning the #514 plan settled
     * on). The keys come back in the order the picker draws them.
     *
     * The three kinds part P10 added are read through `Roles::forSite()`,
     * `UserGroups::forSite()` and `Departments::forSite()` — each already
     * asks for ONE organisation by its number — and only the number and the
     * name are passed on. A user group or department can be saved with no
     * name at all (the column allows it); it is then shown as "User group
     * #12" rather than as a tick box with nothing beside it.
     *
     * @return array{small_group: list<array{groupID:int, groupName:string}>,
     *               leadership_role: list<array{roleID:int, roleName:string}>,
     *               role: list<array{roleID:int, roleName:string}>,
     *               user_group: list<array{groupID:int, groupName:string}>,
     *               department: list<array{deptID:int, deptName:string}>,
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

        // 🏷️ Part P10: roles (#516), user groups and departments (#517).
        //    `true` asks for those in use only: a retired group or
        //    department cannot be freshly added (it matches nobody).
        $accessRoles = [];
        foreach (Roles::forSite($db, $siteId) as $role) {
            $accessRoles[] = ['roleID' => $role['roleID'], 'roleName' => self::nameOr($role['roleName'], 'Role', $role['roleID'])];
        }
        $userGroups = [];
        foreach (UserGroups::forSite($db, $siteId, true) as $group) {
            $userGroups[] = ['groupID' => $group['groupID'], 'groupName' => self::nameOr($group['groupName'], 'User group', $group['groupID'])];
        }
        $departments = [];
        foreach (Departments::forSite($db, $siteId, true) as $dept) {
            $departments[] = ['deptID' => $dept['deptID'], 'deptName' => self::nameOr($dept['deptName'], 'Department', $dept['deptID'])];
        }

        return [
            'small_group' => $groups, 'leadership_role' => $roles,
            'role' => $accessRoles, 'user_group' => $userGroups, 'department' => $departments,
            'person' => $people,
        ];
    }

    // =========================================================================
    // 📖 current() / currentMany() — saved lists, with a label and a state
    // =========================================================================

    /**
     * The saved list for one owner (a calendar, a choice or a rule), each
     * row with a live label and a state: `ok` (still exactly what it looks
     * like); `switched_off` (the small group, leadership role, user group
     * or department exists here but is switched off or retired — a role
     * has no switch, so it is never this); `left` (the person's membership
     * here is inactive, or their account is); `gone` (nothing by that
     * number exists in this organisation — deleted, or another
     * organisation's); `not_offered` (a kind this version's forms do not
     * offer — see the class header; removed when the list is saved).
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
     * explicitly, as a bound value — a small group, leadership role, role,
     * user group or department by its number AND `siteID = ?`; a person
     * only through their `tblUserSites` row for
     * `siteID = ?` (active or not). A row whose join finds nothing is
     * `gone`, and a gone PERSON is "someone no longer in this
     * organisation", never a name read from `tblUsers` on its own — that
     * name could belong to somebody now in a different organisation. The
     * same holds for a role, user group or department: another
     * organisation's is "a role that no longer exists" here, and its name is
     * never read. The
     * list rows themselves are read with `siteID = ?` too, so another
     * organisation's owner of the same number is simply not there.
     *
     * (The "Group" table-name trap, see the class header: `tblSmallGroups`
     * and `tblGroups` are reached through a `LEFT JOIN` with a short name,
     * which is not the trap; the statement's `FROM` table is
     * `tblExternalAudienceMembers`.)
     *
     * The statement binds the organisation SEVEN times — once for each of
     * the six joins that carry it and once for the list rows — and then
     * three values for each owner asked about. The type letters are built
     * beside the values, so the two cannot fall out of step.
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
            . 'u.isActive AS acctActive, '
            // Part P10: a role (no on/off column), a user group and a
            // department, each found only when it is THIS organisation's.
            . 'ro.roleID AS foundAccessRole, ro.roleName AS accessRoleName, '
            . 'gp.groupID AS foundUserGroup, gp.groupName AS userGroupName, gp.isActive AS userGroupActive, '
            . 'dp.deptID AS foundDept, dp.deptName, dp.isActive AS deptActive '
            . 'FROM tblExternalAudienceMembers m '
            . "LEFT JOIN tblSmallGroups g ON m.kind = 'small_group' AND g.groupID = m.refID AND g.siteID = ? "
            . "LEFT JOIN tblLeadershipRoles r ON m.kind = 'leadership_role' AND r.roleID = m.refID AND r.siteID = ? "
            . "LEFT JOIN tblUserSites us ON m.kind = 'person' AND us.userID = m.refID AND us.siteID = ? "
            . 'LEFT JOIN tblUsers u ON u.userID = us.userID '
            . "LEFT JOIN tblRoles ro ON m.kind = 'role' AND ro.roleID = m.refID AND ro.siteID = ? "
            . "LEFT JOIN tblGroups gp ON m.kind = 'user_group' AND gp.groupID = m.refID AND gp.siteID = ? "
            . "LEFT JOIN tblDepts dp ON m.kind = 'department' AND dp.deptID = m.refID AND dp.siteID = ? "
            . "WHERE m.siteID = ? AND ($ownerTests) "
            . 'ORDER BY m.audienceMemberID'
        );
        // One organisation number for each `siteID = ?` above, in the order
        // they are written: small groups, leadership roles, memberships,
        // roles, user groups, departments, then the list rows themselves.
        $types  = 'iiiiiii';
        $params = [$siteId, $siteId, $siteId, $siteId, $siteId, $siteId, $siteId];
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
            // A kind the database knows and this class does not (see the
            // class header). It has no name this class can read, so it is
            // shown by its number alone.
            return ['kind' => $kind, 'refID' => $refId, 'label' => 'an entry #' . $refId, 'state' => 'not_offered'];
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
        // 🏷️ Part P10 — a role (#516). It has no on/off switch, so it is
        //    either this organisation's ("ok") or it is not here at all.
        //    "Not here" covers a deleted role AND another organisation's:
        //    the two read the same on purpose, and no name is shown.
        if ($kind === 'role') {
            if ($row['foundAccessRole'] === null) {
                return ['kind' => $kind, 'refID' => $refId, 'label' => 'a role that no longer exists', 'state' => 'gone'];
            }

            return ['kind' => $kind, 'refID' => $refId, 'label' => self::nameOr($row['accessRoleName'], 'Role', $refId), 'state' => 'ok'];
        }
        // 👥 Part P10 — a user group (#517): in use, retired ("switched
        //    off": it then lets nobody in, but stays on the list until an
        //    administrator takes it off, like a switched-off small group),
        //    or not here at all.
        if ($kind === 'user_group') {
            if ($row['foundUserGroup'] === null) {
                return ['kind' => $kind, 'refID' => $refId, 'label' => 'a user group that no longer exists', 'state' => 'gone'];
            }

            return [
                'kind' => $kind, 'refID' => $refId, 'label' => self::nameOr($row['userGroupName'], 'User group', $refId),
                'state' => ((int) $row['userGroupActive'] === 1) ? 'ok' : 'switched_off',
            ];
        }
        // 🏢 Part P10 — a department (#517). Its on/off column may be EMPTY
        //    (the column allows it); empty reads as switched off here, as it
        //    does in `Departments::memberSql()`, so the label and the rule
        //    agree: `(int) null` is 0.
        if ($kind === 'department') {
            if ($row['foundDept'] === null) {
                return ['kind' => $kind, 'refID' => $refId, 'label' => 'a department that no longer exists', 'state' => 'gone'];
            }

            return [
                'kind' => $kind, 'refID' => $refId, 'label' => self::nameOr($row['deptName'], 'Department', $refId),
                'state' => ((int) $row['deptActive'] === 1) ? 'ok' : 'switched_off',
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
     * The same holds for the three kinds part P10 added: another
     * organisation's role, user group or department is refused (whether or
     * not it is already on the list), and a retired user group or a
     * switched-off department cannot be freshly added but may stay. A role
     * has no switch, so a role of this organisation can always be added.
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
     *
     * 🔒 THIS IS THE ONE GATE between a posted number and a saved list row.
     *    Every branch asks for the number AND this organisation together,
     *    so another organisation's number finds nothing — exactly as a
     *    made-up number does, which is why the caller can answer both with
     *    the same one sentence. Never add a branch that looks a number up
     *    on its own.
     *
     * The role, user group and department branches (part P10) ask `Roles`,
     * `UserGroups` and `Departments` instead of holding statements of their
     * own, so each of those tables is read in one class only. A role is
     * looked for in `Roles::forSite()`'s list because that class has no
     * "one role by number" reader; that is one statement per posted role,
     * and a list holds at most `MAX_MEMBERS` entries. A role has no on/off
     * switch, so `$requireActive` changes nothing for it.
     *
     * A kind this method does not know answers NO. It used to fall through
     * to the "person" branch at the bottom; that was safe only because
     * `fromPost()` refuses an unknown kind first, and "safe because of the
     * caller" is not a property worth keeping on a gate.
     */
    private static function belongsToOrganisation(\mysqli $db, int $siteId, string $kind, int $refId, bool $requireActive): bool
    {
        if ($kind === 'role') {
            foreach (Roles::forSite($db, $siteId) as $role) {
                if ($role['roleID'] === $refId) {
                    return true;
                }
            }

            return false;
        }
        if ($kind === 'user_group') {
            $group = UserGroups::get($db, $refId, $siteId);

            return $group !== null && ($requireActive === false || $group['isActive'] === true);
        }
        if ($kind === 'department') {
            $dept = Departments::get($db, $refId, $siteId);

            return $dept !== null && ($requireActive === false || $dept['isActive'] === true);
        }
        if ($kind !== 'small_group' && $kind !== 'leadership_role' && $kind !== 'person') {
            return false;
        }
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

    /**
     * A name to show, or "<kind> #<number>" when there is none. A user
     * group's and a department's name column may be empty (the tables allow
     * it), and a tick box with nothing beside it tells nobody anything.
     *
     * (Until part P10 a `kindWord()` helper sat here, giving the words "a
     * role", "a user group" and "a department" to rows of kinds the forms
     * did not offer. Those kinds are offered now and have real names, so it
     * was removed rather than left with nothing to do.)
     */
    private static function nameOr(mixed $name, string $kindWord, int $refId): string
    {
        $name = trim((string) ($name ?? ''));

        return ($name === '') ? ($kindWord . ' #' . $refId) : $name;
    }
}
