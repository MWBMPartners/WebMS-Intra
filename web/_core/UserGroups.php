<?php
// Path: _core/UserGroups.php
/**
 * -----------------------------------------------------------------------------
 * User groups, per organisation 👥 (#517)
 * -----------------------------------------------------------------------------
 * The class that owns every write to `tblGroups` and `tblUserGroups`, and the
 * shared "is this person in this group, here" lookups (`isMember()`,
 * `memberSql()`).
 *
 * WHAT A USER GROUP IS FOR
 * -------------------------------------------------------------------------
 * A committee or a working group of ONE organisation — a finance committee,
 * a building committee. A group can be named as the approver of a workflow
 * step (`Portal\Core\Workflow`), can own an asset (`Portal\Core\AssetRegister`),
 * and — from #514 — can be the audience of a shared calendar. It is NOT a
 * home group or a class with a meeting roll: those are the separate Small
 * Groups app (`Portal\Core\SmallGroups`, `tblSmallGroup*`).
 *
 * WHAT WAS WRONG BEFORE THIS CLASS
 * -------------------------------------------------------------------------
 * Nothing anywhere could create a group or add a person to one. The code
 * READ the two tables (workflow approval, asset ownership) but no page,
 * form or script ever wrote either of them, so those options could never
 * match anyone unless somebody edited the database by hand. Worse,
 * `tblGroups` had no organisation column: a group made in one organisation
 * would have been visible, and usable as an approver or an asset owner, in
 * every organisation on the portal. Migration 203 gives every group and
 * every membership an organisation, and two "composite" foreign keys (rules
 * the database enforces over two columns at once) so a membership for a
 * non-member, or naming another organisation's group, is refused by the
 * database itself.
 *
 * WHAT THIS CLASS DELIBERATELY DOES NOT DECIDE
 * -------------------------------------------------------------------------
 * WHO may act. Managing the LIST of groups (create, rename, retire, delete)
 * is gated by `App::isAdmin()` on the page; changing a PERSON's membership
 * is gated by the account-change guard's check (AccountGuard, "this
 * organisation" reach) on the page
 * (`web/_apps/admin/groups/members-save.php`), so that a refusal looks
 * exactly like "that account could not be found". `addMember()` cannot run
 * that guard itself: the guard always reads `Site::id()`, the organisation
 * open RIGHT NOW, and one caller — the "awaiting placement" page for global
 * administrators (`admin/users/memberships-unplaced-save.php`) — places a
 * membership into an organisation that is not the one open. The same
 * reasoning `Roles::grant()` gives; see
 * `tools/audit-checks/check_account_writes_guarded.py`'s ALLOWED entry for
 * this file. (This docblock deliberately never writes the guard's class
 * name followed by two colons: that check treats that exact text, even
 * inside a comment, as proof a file is guarded, which would quietly make
 * its ALLOWED entry for this file look stale. `Roles.php` avoids it too.)
 *
 * WHAT THIS CLASS CANNOT DO
 * -------------------------------------------------------------------------
 * - It has no cache: a change takes effect on the very next request.
 * - It has no global-administrator shortcut in SQL. `memberSql()` and
 *   `isMember()` answer strictly from the tables; anything that should let a
 *   global administrator through must decide that in PHP.
 * - A RETIRED group (`isActive = 0`) still exists, still has its members'
 *   rows and still names old data; it simply matches nobody anywhere.
 * - Never write `FROM tblUserGroups <short name>` on the same line as the
 *   SELECT's column list: `tools/audit-checks/check_sql_columns.py` then
 *   wrongly reports an unknown table (its own header, blind spot 15). Every
 *   query here starts from `tblGroups`, or names `tblUserGroups` without a
 *   short name, or only after a JOIN — all shapes that checker reads safely.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/517
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;

final class UserGroups
{
    /** Longest group name `tblGroups.groupName` (VARCHAR(100)) can hold, in characters. */
    public const NAME_MAX = 100;

    /**
     * Longest description `tblGroups.description` (a MySQL TEXT column) can
     * hold. TEXT is measured in BYTES, not characters, so this is compared
     * with `strlen()`, never `mb_strlen()`.
     */
    public const DESCRIPTION_MAX_BYTES = 65535;

    // =========================================================================
    // 🧹 Pure helpers — no database, safe to call with nothing set up at all
    // =========================================================================

    /**
     * Build the SQL fragment #514's shared-calendar audience query (and
     * anything else that needs it) drops straight into a WHERE clause to ask
     * "is the person $userExpr a CURRENT member of user group $groupIdExpr,
     * in organisation $siteExpr?" — the #516 `Roles::holdsSql()` shape.
     *
     * "Current" means all four of: a membership row for that group in that
     * organisation; the group belongs to that organisation; the group is not
     * retired (`isActive = 1`); and the person's own membership of the
     * organisation is active. A retired or deleted group, an ended
     * organisation membership, or the same group asked about from another
     * organisation, all answer no (fail closed). Proven on a real database
     * during planning: 1 for a member; 0 for a non-member, for another
     * organisation, for an ended membership, after retiring and after
     * deleting the group; 1 again after reinstating.
     *
     * Every argument must be a plain, developer-written `table.column` (or
     * bare column) identifier, or the literal `?` placeholder — NEVER built
     * from a request. This is checked FIRST, before anything else, so a bad
     * argument throws with no database at all (`tools/memberships-selftest.php`
     * relies on that).
     *
     * The short names end in 17 (`g17`, `ug17`, `us17`) so they cannot clash
     * with a short name in the caller's own query (the #516 fragment uses 16).
     * It starts `FROM tblGroups`, so the column checker's short-name trap
     * (class docblock) cannot fire on it.
     *
     * @param string $userExpr    Developer identifier or '?' for the account.
     * @param string $groupIdExpr Developer identifier or '?' for tblGroups.groupID.
     * @param string $siteExpr    Developer identifier or '?' for the organisation.
     *
     * @return string
     *
     * @throws \InvalidArgumentException When any argument is neither '?' nor a plain identifier.
     */
    public static function memberSql(string $userExpr, string $groupIdExpr, string $siteExpr): string
    {
        foreach (['userExpr' => $userExpr, 'groupIdExpr' => $groupIdExpr, 'siteExpr' => $siteExpr] as $name => $value) {
            if ($value === '?') {
                continue;
            }
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $value) !== 1) {
                throw new \InvalidArgumentException(
                    "UserGroups::memberSql(): {$name} must be '?' or a plain identifier, got: " . $value
                );
            }
        }

        return 'EXISTS (SELECT 1 FROM tblGroups g17 '
            . 'JOIN tblUserGroups ug17 ON ug17.groupID = g17.groupID AND ug17.siteID = g17.siteID '
            . 'JOIN tblUserSites us17 ON us17.userID = ug17.userID AND us17.siteID = ug17.siteID AND us17.isActive = 1 '
            . "WHERE ug17.userID = {$userExpr} AND ug17.groupID = {$groupIdExpr} AND ug17.siteID = {$siteExpr} "
            . 'AND g17.isActive = 1)';
    }

    /**
     * Read a database yes/no flag. A prepared statement hands back the whole
     * number 1, not the text '1' (#497), and a plain query hands back text;
     * both mean "on". Anything else — 0, NULL, '0' — means "off".
     *
     * @param mixed $value
     *
     * @return bool
     */
    private static function flagOn(mixed $value): bool
    {
        return $value === 1 || $value === '1';
    }

    // =========================================================================
    // 🔍 Reading
    // =========================================================================

    /**
     * Is this account a CURRENT member of this group, in this organisation?
     * The same four conditions as `memberSql()`, as a prepared statement.
     *
     * @param mysqli $db      An open connection.
     * @param int    $userId  The account.
     * @param int    $siteId  The organisation.
     * @param int    $groupId The group.
     *
     * @return bool
     */
    public static function isMember(mysqli $db, int $userId, int $siteId, int $groupId): bool
    {
        $stmt = $db->prepare(
            'SELECT 1 FROM tblGroups g '
            . 'JOIN tblUserGroups ug ON ug.groupID = g.groupID AND ug.siteID = g.siteID '
            . 'JOIN tblUserSites us ON us.userID = ug.userID AND us.siteID = ug.siteID AND us.isActive = 1 '
            . 'WHERE ug.userID = ? AND ug.groupID = ? AND ug.siteID = ? AND g.isActive = 1 LIMIT 1'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('iii', $userId, $groupId, $siteId);
        $stmt->execute();
        $is = $stmt->get_result()->fetch_assoc() !== null;
        $stmt->close();

        return $is;
    }

    /**
     * The numbers of every group, in this organisation, that this account is
     * a CURRENT member of (the same four conditions as `isMember()`).
     * Replaces `Workflow::userGroupIds()`, which read `tblUserGroups` with no
     * organisation and no active test at all.
     *
     * @param mysqli $db     An open connection.
     * @param int    $userId The account.
     * @param int    $siteId The organisation.
     *
     * @return list<int>
     */
    public static function idsForUser(mysqli $db, int $userId, int $siteId): array
    {
        $ids  = [];
        $stmt = $db->prepare(
            'SELECT g.groupID FROM tblGroups g '
            . 'JOIN tblUserGroups ug ON ug.groupID = g.groupID AND ug.siteID = g.siteID '
            . 'JOIN tblUserSites us ON us.userID = ug.userID AND us.siteID = ug.siteID AND us.isActive = 1 '
            . 'WHERE ug.userID = ? AND ug.siteID = ? AND g.isActive = 1'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('ii', $userId, $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $ids[] = (int) $row['groupID'];
        }
        $stmt->close();

        return $ids;
    }

    /**
     * Every group of this organisation, in ONE query, ordered by name. With
     * `$activeOnly = true` this is the picker #514 and the asset owner form
     * use: retired groups are left out.
     *
     * @param mysqli $db         An open connection.
     * @param int    $siteId     The organisation.
     * @param bool   $activeOnly True to leave out retired groups.
     *
     * @return list<array{groupID:int, groupName:string, description:?string, isActive:bool}>
     */
    public static function forSite(mysqli $db, int $siteId, bool $activeOnly = false): array
    {
        $groups = [];
        $sql    = 'SELECT groupID, groupName, description, isActive FROM tblGroups WHERE siteID = ?';
        if ($activeOnly === true) {
            $sql .= ' AND isActive = 1';
        }
        $sql .= ' ORDER BY groupName';

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $groups[] = [
                'groupID'     => (int) $row['groupID'],
                'groupName'   => (string) ($row['groupName'] ?? ''),
                'description' => $row['description'] !== null ? (string) $row['description'] : null,
                'isActive'    => self::flagOn($row['isActive']),
            ];
        }
        $stmt->close();

        return $groups;
    }

    /**
     * One group, looked up by (groupID, siteID). Another organisation's group
     * number costs exactly one lookup that finds nothing — the same work and
     * the same answer as a made-up number — so a caller can never tell the
     * two apart.
     *
     * @param mysqli $db      An open connection.
     * @param int    $groupId The group.
     * @param int    $siteId  The organisation it must belong to.
     *
     * @return array{groupID:int, siteID:int, groupName:string, description:?string, isActive:bool}|null
     */
    public static function get(mysqli $db, int $groupId, int $siteId): ?array
    {
        $stmt = $db->prepare(
            'SELECT groupID, siteID, groupName, description, isActive FROM tblGroups WHERE groupID = ? AND siteID = ? LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ii', $groupId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return null;
        }

        return [
            'groupID'     => (int) $row['groupID'],
            'siteID'      => (int) $row['siteID'],
            'groupName'   => (string) ($row['groupName'] ?? ''),
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'isActive'    => self::flagOn($row['isActive']),
        ];
    }

    /**
     * Everyone recorded in this group, for the roster page. Deliberately
     * does NOT require an active organisation membership: `memberActive`
     * says whether it is, so the roster can show "(membership ended)"
     * honestly instead of hiding a row that still exists.
     *
     * @param mysqli $db      An open connection.
     * @param int    $groupId The group.
     * @param int    $siteId  The organisation.
     *
     * @return list<array{userID:int, fullName:string, emailAddress:string, addedAt:string, memberActive:bool}>
     */
    public static function membersOf(mysqli $db, int $groupId, int $siteId): array
    {
        $members = [];
        $stmt    = $db->prepare(
            'SELECT u.userID, u.fullName, u.emailAddress, ug.addedAt, us.isActive AS memberActive '
            . 'FROM tblGroups g '
            . 'JOIN tblUserGroups ug ON ug.groupID = g.groupID AND ug.siteID = g.siteID '
            . 'JOIN tblUsers u ON u.userID = ug.userID '
            . 'JOIN tblUserSites us ON us.userID = ug.userID AND us.siteID = ug.siteID '
            . 'WHERE g.groupID = ? AND g.siteID = ? '
            . 'ORDER BY u.fullName'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('ii', $groupId, $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $members[] = [
                'userID'       => (int) $row['userID'],
                'fullName'     => (string) ($row['fullName'] ?? ''),
                'emailAddress' => (string) ($row['emailAddress'] ?? ''),
                'addedAt'      => (string) ($row['addedAt'] ?? ''),
                'memberActive' => self::flagOn($row['memberActive']),
            ];
        }
        $stmt->close();

        return $members;
    }

    /**
     * How many membership rows this group has in this organisation (active or
     * ended — every row counts, because every row blocks a delete).
     *
     * @param mysqli $db      An open connection.
     * @param int    $groupId The group.
     * @param int    $siteId  The organisation.
     *
     * @return int
     */
    public static function countMembers(mysqli $db, int $groupId, int $siteId): int
    {
        $stmt = $db->prepare('SELECT COUNT(*) AS cnt FROM tblUserGroups WHERE groupID = ? AND siteID = ?');
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param('ii', $groupId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * What still points at this group, and so stops it being deleted (the
     * owner's rule, 21 September 2026: a group may be deleted only while it
     * has no members, owns no assets and is named by no workflow step of its
     * organisation; otherwise the administrator retires it instead).
     *
     * A workflow step names a group by TEXT (`tblWorkflowSteps.assigneeValue`
     * is VARCHAR), so the number is compared as text. WHAT THIS CANNOT SEE: a
     * step whose value was typed with a leading zero ('012') or spaces does
     * not match '12'. That is acceptable because the step form tells people
     * to type the number exactly as Admin → Groups shows it, and such a step
     * would never have matched the group at run time either.
     *
     * @param mysqli $db      An open connection.
     * @param int    $groupId The group.
     * @param int    $siteId  The organisation.
     *
     * @return array{members:int, assetOwners:int, workflowSteps:int}
     */
    public static function deleteBlockers(mysqli $db, int $groupId, int $siteId): array
    {
        $out = [
            'members'       => self::countMembers($db, $groupId, $siteId),
            'assetOwners'   => 0,
            'workflowSteps' => 0,
        ];

        $ownerStmt = $db->prepare(
            "SELECT COUNT(*) AS cnt FROM tblAssetOwners WHERE partyType = 'group' AND groupID = ? AND siteID = ?"
        );
        if ($ownerStmt !== false) {
            $ownerStmt->bind_param('ii', $groupId, $siteId);
            $ownerStmt->execute();
            $out['assetOwners'] = (int) ($ownerStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
            $ownerStmt->close();
        }

        $groupText = (string) $groupId;
        $stepStmt  = $db->prepare(
            'SELECT COUNT(*) AS cnt FROM tblWorkflowSteps s '
            . 'JOIN tblWorkflows w ON w.workflowID = s.workflowID '
            . "WHERE w.siteID = ? AND s.assigneeType = 'group' AND s.assigneeValue = ?"
        );
        if ($stepStmt !== false) {
            $stepStmt->bind_param('is', $siteId, $groupText);
            $stepStmt->execute();
            $out['workflowSteps'] = (int) ($stepStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
            $stepStmt->close();
        }

        return $out;
    }

    // =========================================================================
    // ✏️ Writing the list of groups
    // =========================================================================

    /**
     * Check a name and description before they are written. Returns the
     * cleaned values, or throws. The pages check the same limits first and
     * show a calm message; this is the belt, so a direct caller cannot write
     * something the column would silently cut short.
     *
     * @param string      $name
     * @param string|null $description
     *
     * @return array{0:string, 1:?string}
     *
     * @throws \InvalidArgumentException
     */
    private static function cleanNameAndDescription(string $name, ?string $description): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name, 'UTF-8') > self::NAME_MAX) {
            throw new \InvalidArgumentException('A group name is required and may be at most ' . self::NAME_MAX . ' characters.');
        }
        if ($description !== null) {
            $description = trim($description);
            if ($description === '') {
                $description = null;
            } elseif (strlen($description) > self::DESCRIPTION_MAX_BYTES) {
                throw new \InvalidArgumentException('A group description is too long.');
            }
        }

        return [$name, $description];
    }

    /**
     * Create a group in one organisation.
     *
     * @param mysqli      $db          An open connection.
     * @param int         $siteId      The organisation it belongs to.
     * @param string      $name        1-100 characters after trimming.
     * @param string|null $description Optional.
     * @param int|null    $actorId     Who is creating it, for the audit trail.
     *
     * @return int The new group's number.
     *
     * @throws \InvalidArgumentException On a name or description that does not fit.
     * @throws \RuntimeException         When the statement cannot be prepared.
     */
    public static function create(mysqli $db, int $siteId, string $name, ?string $description, ?int $actorId): int
    {
        [$name, $description] = self::cleanNameAndDescription($name, $description);

        $stmt = $db->prepare('INSERT INTO tblGroups (siteID, groupName, description, isActive) VALUES (?, ?, ?, 1)');
        if ($stmt === false) {
            throw new \RuntimeException('UserGroups::create: failed to prepare: ' . $db->error);
        }
        $stmt->bind_param('iss', $siteId, $name, $description);
        $stmt->execute();
        $newId = (int) $stmt->insert_id;
        $stmt->close();

        // 📝 A group's NAME is organisation material, not personal data, so
        //    it may appear in the activity line (the #516 `RoleCreate`
        //    precedent). Account names never do (#518).
        Logger::audit(
            'tblGroups',
            $newId,
            'create',
            null,
            ['siteID' => $siteId, 'groupName' => $name, 'description' => $description],
            $actorId
        );
        Logger::activity(
            'UserGroupCreate',
            'Created group "' . $name . '" (#' . $newId . ') in organisation #' . $siteId,
            $actorId
        );

        return $newId;
    }

    /**
     * Rename a group and/or change its description.
     *
     * @param mysqli      $db          An open connection.
     * @param int         $groupId     The group.
     * @param int         $siteId      The organisation it must belong to.
     * @param string      $name        1-100 characters after trimming.
     * @param string|null $description Optional.
     * @param int|null    $actorId     Who is changing it.
     *
     * @return string 'ok' | 'not_found'
     *
     * @throws \InvalidArgumentException On a name or description that does not fit.
     */
    public static function update(mysqli $db, int $groupId, int $siteId, string $name, ?string $description, ?int $actorId): string
    {
        [$name, $description] = self::cleanNameAndDescription($name, $description);

        $old = self::get($db, $groupId, $siteId);
        if ($old === null) {
            return 'not_found';
        }

        $stmt = $db->prepare('UPDATE tblGroups SET groupName = ?, description = ? WHERE groupID = ? AND siteID = ?');
        if ($stmt === false) {
            return 'not_found';
        }
        $stmt->bind_param('ssii', $name, $description, $groupId, $siteId);
        $stmt->execute();
        $stmt->close();

        Logger::audit(
            'tblGroups',
            $groupId,
            'update',
            ['groupName' => $old['groupName'], 'description' => $old['description']],
            ['groupName' => $name, 'description' => $description],
            $actorId
        );
        Logger::activity(
            'UserGroupUpdate',
            'Updated group "' . $name . '" (#' . $groupId . ') in organisation #' . $siteId,
            $actorId
        );

        return 'ok';
    }

    /**
     * Retire (switch off) or reinstate (switch on) a group. A retired group
     * keeps its history and its members' rows, but matches nobody anywhere.
     *
     * @param mysqli   $db      An open connection.
     * @param int      $groupId The group.
     * @param int      $siteId  The organisation it must belong to.
     * @param bool     $active  True to reinstate, false to retire.
     * @param int|null $actorId Who is changing it.
     *
     * @return string 'ok' | 'unchanged' | 'not_found'
     */
    public static function setActive(mysqli $db, int $groupId, int $siteId, bool $active, ?int $actorId): string
    {
        $old = self::get($db, $groupId, $siteId);
        if ($old === null) {
            return 'not_found';
        }

        // `tblGroups.isActive` is NOT NULL (migration 203), so a plain `<>`
        // is safe here. Compare `Departments::setActive()`, which cannot do
        // the same because `tblDepts.isActive` may hold NULL.
        $flag = $active === true ? 1 : 0;
        $stmt = $db->prepare('UPDATE tblGroups SET isActive = ? WHERE groupID = ? AND siteID = ? AND isActive <> ?');
        if ($stmt === false) {
            return 'unchanged';
        }
        $stmt->bind_param('iiii', $flag, $groupId, $siteId, $flag);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected <= 0) {
            return 'unchanged';
        }

        Logger::audit(
            'tblGroups',
            $groupId,
            'update',
            ['isActive' => $old['isActive'] === true ? 1 : 0],
            ['isActive' => $flag],
            $actorId
        );
        Logger::activity(
            $active === true ? 'UserGroupReinstate' : 'UserGroupRetire',
            ($active === true ? 'Reinstated' : 'Retired') . ' group "' . $old['groupName'] . '" (#' . $groupId
                . ') in organisation #' . $siteId,
            $actorId
        );

        return 'ok';
    }

    /**
     * Delete a group — only while nothing still points at it (the owner's
     * rule; see `deleteBlockers()`). Otherwise the answer is `in_use` and the
     * administrator retires it instead.
     *
     * WHY BOTH A COUNT AND A CAUGHT ERROR: the count gives the page the
     * numbers to show; the caught error 1451 ("a row still points at this")
     * covers a reference that appears between the count and the delete. In
     * practice today no table blocks a group delete at the database level
     * (memberships and asset ownership rows would simply be removed with it,
     * which is exactly the quiet loss the count exists to prevent), so the
     * catch is a belt for any future foreign key.
     *
     * @param mysqli   $db      An open connection.
     * @param int      $groupId The group.
     * @param int      $siteId  The organisation it must belong to.
     * @param int|null $actorId Who is deleting it.
     *
     * @return string 'ok' | 'not_found' | 'in_use'
     */
    public static function delete(mysqli $db, int $groupId, int $siteId, ?int $actorId): string
    {
        $old = self::get($db, $groupId, $siteId);
        if ($old === null) {
            return 'not_found';
        }

        $blockers = self::deleteBlockers($db, $groupId, $siteId);
        if ($blockers['members'] > 0 || $blockers['assetOwners'] > 0 || $blockers['workflowSteps'] > 0) {
            return 'in_use';
        }

        $stmt = $db->prepare('DELETE FROM tblGroups WHERE groupID = ? AND siteID = ?');
        if ($stmt === false) {
            return 'not_found';
        }
        try {
            $stmt->bind_param('ii', $groupId, $siteId);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
        } catch (\mysqli_sql_exception $e) {
            $stmt->close();
            if ($e->getCode() === 1451) {
                return 'in_use';
            }
            throw $e;
        }
        if ($affected <= 0) {
            return 'not_found';
        }

        Logger::audit(
            'tblGroups',
            $groupId,
            'delete',
            ['siteID' => $siteId, 'groupName' => $old['groupName'], 'description' => $old['description']],
            null,
            $actorId
        );
        Logger::activity(
            'UserGroupDelete',
            'Deleted group "' . $old['groupName'] . '" (#' . $groupId . ') in organisation #' . $siteId,
            $actorId
        );

        return 'ok';
    }

    // =========================================================================
    // 👤 Writing memberships
    // =========================================================================

    /**
     * Add an account to a group, in one organisation. Returns a plain word
     * rather than throwing for the everyday "nothing changed" cases, so the
     * page can show a calm message.
     *
     * WHY A PLAIN `INSERT`, NOT `INSERT IGNORE` (recorded so nobody
     * "simplifies" it): `INSERT IGNORE` turns a real refusal by the database
     * — the composite foreign keys added in migration 203, for a non-member
     * or for another organisation's group — into a silent no-op with only a
     * warning, indistinguishable from "already a member". Proven while
     * planning #517: `INSERT IGNORE` of a non-member answered success with
     * "0 rows affected" and only "Warning 1452". Catching the two specific
     * error codes keeps `not_found` and `unchanged` apart.
     *
     * The refusals cost the same work either way: a missing group, and an
     * account that is not an active member here, each cost one lookup that
     * finds nothing (the `Roles::grant()` reasoning — no timing difference
     * to learn from).
     *
     * @param mysqli   $db      An open connection.
     * @param int      $userId  The account to add.
     * @param int      $groupId The group.
     * @param int      $siteId  The organisation the group must belong to.
     * @param int|null $actorId Who is adding them, for the audit trail.
     *
     * @return string 'ok' | 'unchanged' | 'not_found' | 'inactive'
     */
    public static function addMember(mysqli $db, int $userId, int $groupId, int $siteId, ?int $actorId): string
    {
        // 1. The group must exist, in THIS organisation. A retired group
        //    takes no new members. (Saying "retired" is not a leak: the
        //    caller has already passed the organisation's administrator gate
        //    and can see the group on its own list.)
        $group = self::get($db, $groupId, $siteId);
        if ($group === null) {
            return 'not_found';
        }
        if ($group['isActive'] === false) {
            return 'inactive';
        }

        // 2. The account must be an ACTIVE member of THIS organisation. On a
        //    single-organisation portal `AccountGuard` skips its own
        //    membership test, so this lookup (and the composite foreign key
        //    behind it) is what refuses a person with no membership row.
        $memberStmt = $db->prepare('SELECT 1 FROM tblUserSites WHERE userID = ? AND siteID = ? AND isActive = 1 LIMIT 1');
        if ($memberStmt === false) {
            return 'not_found';
        }
        $memberStmt->bind_param('ii', $userId, $siteId);
        $memberStmt->execute();
        $isMember = $memberStmt->get_result()->fetch_assoc() !== null;
        $memberStmt->close();
        if ($isMember === false) {
            return 'not_found';
        }

        // 3. Write it — see the docblock for why this is a plain INSERT.
        $insStmt = $db->prepare('INSERT INTO tblUserGroups (userID, groupID, siteID, addedByID) VALUES (?, ?, ?, ?)');
        if ($insStmt === false) {
            return 'not_found';
        }
        try {
            $insStmt->bind_param('iiii', $userId, $groupId, $siteId, $actorId);
            $insStmt->execute();
            $newId = (int) $insStmt->insert_id;
            $insStmt->close();
        } catch (\mysqli_sql_exception $e) {
            $insStmt->close();
            // 1062 = uq_user_group: already a member — a calm "nothing to do".
            if ($e->getCode() === 1062) {
                return 'unchanged';
            }
            // 1452 = a composite foreign key refused it (the membership or
            // the group vanished between the checks above and this write).
            if ($e->getCode() === 1452) {
                return 'not_found';
            }
            throw $e;
        }

        // 4. Audit + activity — account NUMBERS only, never names (#518).
        Logger::audit(
            'tblUserGroups',
            $newId,
            'create',
            null,
            ['userID' => $userId, 'groupID' => $groupId, 'siteID' => $siteId],
            $actorId
        );
        Logger::activity(
            'UserGroupMemberAdd',
            'Added account #' . $userId . ' to group #' . $groupId . ' in organisation #' . $siteId,
            $actorId
        );

        return 'ok';
    }

    /**
     * Remove an account from a group, in one organisation.
     *
     * @param mysqli   $db      An open connection.
     * @param int      $userId  The account.
     * @param int      $groupId The group.
     * @param int      $siteId  The organisation.
     * @param int|null $actorId Who is removing them.
     *
     * @return string 'ok' | 'unchanged'
     */
    public static function removeMember(mysqli $db, int $userId, int $groupId, int $siteId, ?int $actorId): string
    {
        // 🔎 Read the row first, so the audit record says exactly what was
        //    removed (its own number and when it was added).
        $readStmt = $db->prepare(
            'SELECT userGroupID, addedAt FROM tblUserGroups WHERE userID = ? AND groupID = ? AND siteID = ? LIMIT 1'
        );
        if ($readStmt === false) {
            return 'unchanged';
        }
        $readStmt->bind_param('iii', $userId, $groupId, $siteId);
        $readStmt->execute();
        $row = $readStmt->get_result()->fetch_assoc();
        $readStmt->close();
        if ($row === null) {
            return 'unchanged';
        }

        $stmt = $db->prepare('DELETE FROM tblUserGroups WHERE userID = ? AND groupID = ? AND siteID = ?');
        if ($stmt === false) {
            return 'unchanged';
        }
        $stmt->bind_param('iii', $userId, $groupId, $siteId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected <= 0) {
            return 'unchanged';
        }

        Logger::audit(
            'tblUserGroups',
            (int) $row['userGroupID'],
            'delete',
            ['userID' => $userId, 'groupID' => $groupId, 'siteID' => $siteId, 'addedAt' => (string) $row['addedAt']],
            null,
            $actorId
        );
        Logger::activity(
            'UserGroupMemberRemove',
            'Removed account #' . $userId . ' from group #' . $groupId . ' in organisation #' . $siteId,
            $actorId
        );

        return 'ok';
    }
}
