<?php
// Path: _core/Roles.php
/**
 * -----------------------------------------------------------------------------
 * Roles, per organisation 🏷️ (#516)
 * -----------------------------------------------------------------------------
 * The class that owns granting, revoking and seeding roles, and the shared
 * "does this person hold this role here" lookups (`has()`, `holdsSql()`).
 * It is NOT the only code that touches `tblRoles` / `tblUserRoles`: the
 * role-list page (`web/_apps/admin/roles/save.php`) writes `tblRoles`
 * itself, and about a dozen hand-written queries elsewhere still read both
 * tables directly, each repeating the same per-organisation join rule by
 * hand. Anyone changing that rule must change those sites too; searching
 * for `tblUserRoles` finds them. Before this class existed, nineteen files each wrote
 * their own copy of the same join by hand, and NOTHING anywhere could
 * ever put a row into `tblUserRoles` at all — the members page SHOWED a
 * person's roles, the help page DESCRIBED where they live, but no
 * button, form or script could give one. Every one of the 61 files
 * (66 call sites) that call `App::hasRole()` — plus `App.php` itself —
 * only ever answered yes for a global administrator, because a global
 * administrator is given every role automatically regardless of what
 * `tblUserRoles` contains.
 *
 * WHAT CHANGED, AND WHY IT IS SAFE FOR A KEY TO STAY FIXED WHILE THE LABEL
 * CHANGES
 * -------------------------------------------------------------------------
 * Every role now belongs to ONE organisation. Each organisation starts with
 * the same fourteen "standard" roles (`STANDARD` below), may RENAME any of
 * them, and may add roles of its own. The role's KEY (`roleKey`, e.g.
 * 'treasurer') never changes once a role exists — it is what every one of
 * the 66 `App::hasRole()` call sites, every workflow assignee, every
 * newsletter segment and every report gate compares against. The role's
 * LABEL (`roleName`, e.g. "Treasurer") is what a person actually sees, and
 * an organisation may change it freely at any time — see
 * `web/_apps/admin/roles/save.php`, which never even reads a posted key on
 * an update. Renaming "Treasurer" to "Finance Officer" for one organisation
 * therefore changes nothing about whether `App::hasRole('treasurer')`
 * still answers correctly for that organisation's own treasurer.
 *
 * WHAT THIS CLASS DELIBERATELY DOES NOT DO
 * -------------------------------------------------------------------------
 * It never decides WHO may grant or revoke a role, or WHO may rename or
 * delete one — that is the account-change guard class's job (for granting
 * a role to a specific account) or a plain `App::isAdmin()` gate (for
 * managing the list itself), and every method here trusts its caller to
 * have already decided that. `grant()` in particular can be asked to place
 * a holding into an organisation OTHER than the one currently open (the
 * "roles awaiting placement" pen page does exactly this for a global
 * administrator), so it cannot run that guard's own check method itself —
 * that method always reads `Site::id()`, the organisation open RIGHT NOW,
 * which is the wrong organisation for that one caller. See
 * `tools/audit-checks/check_account_writes_guarded.py`'s `ALLOWED` entry
 * for this file for the same point stated from the check's side — every
 * caller of this class is where the real reach decision is made instead
 * (the members-page save handler, the pen page, and the new-organisation
 * seed, each named in that entry).
 *
 * WHAT THIS CLASS CANNOT DO
 * -------------------------------------------------------------------------
 * It has no cache of any kind — a granted or revoked role takes effect on
 * the very next request, which matters because there was never a cache to
 * invalidate before either. It has no global-administrator shortcut in
 * SQL: `Roles::has()` answers strictly from `tblUserRoles`, and the
 * shortcut that lets a global administrator pass every check lives in
 * `App::hasRole()` (PHP, not SQL) so that SQL-side callers — including the
 * #514 import feature's own audience query — never need to special-case a
 * global administrator inside a hand-written WHERE clause.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/516
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;

final class Roles
{
    /**
     * The fourteen roles every organisation starts with, in the exact
     * order and with the exact key/label/description text migration 202's
     * A9 seed and `full_schema.sql`'s matching fold-in block use.
     * `tools/audit-checks/check_role_keys.py` compares all three lists
     * (this one, the migration's, the fold's) and fails the build if they
     * ever drift apart — a key nobody can hold is exactly the shape #516
     * itself was.
     *
     * @var array<string, array{label: string, description: string}>
     */
    public const STANDARD = [
        'treasurer' => [
            'label'       => 'Treasurer',
            'description' => 'Records giving, sees every expense claim and pays approved ones',
        ],
        'approver' => [
            'label'       => 'Expense Approver',
            'description' => 'Approves or rejects expense claims',
        ],
        'care_team' => [
            'label'       => 'Care Team',
            'description' => 'Opens the confidential pastoral care register',
        ],
        'kids_team' => [
            'label'       => 'Kids Team',
            'description' => "Runs children's check-in and check-out",
        ],
        'prayer_team' => [
            'label'       => 'Prayer Team',
            'description' => 'Moderates prayer requests and can be assigned them',
        ],
        'asset_manager' => [
            'label'       => 'Asset Manager',
            'description' => 'Manages the asset register',
        ],
        'venue_manager' => [
            'label'       => 'Venue Manager',
            'description' => 'Manages venue bookings',
        ],
        'announcement_approver' => [
            'label'       => 'Announcement Approver',
            'description' => 'Approves announcements before they publish',
        ],
        'groups_coordinator' => [
            'label'       => 'Small Groups Coordinator',
            'description' => 'Manages every small group',
        ],
        'stream_moderator' => [
            'label'       => 'Stream Moderator',
            'description' => 'Moderates livestream chat',
        ],
        'staff' => [
            'label'       => 'Staff',
            'description' => 'Sees photos shared with staff',
        ],
        'volunteer' => [
            'label'       => 'Volunteer',
            'description' => 'Sees photos shared with volunteers',
        ],
        'visitor_coordinator' => [
            'label'       => 'Visitor Coordinator',
            'description' => 'Can be assigned first-time visitors to follow up',
        ],
        'event_coordinator' => [
            'label'       => 'Event Coordinator',
            'description' => 'An audience for newsletters, workflows, reminders and shared calendars. '
                . 'Coordinating a particular event is set on that event, not here.',
        ],
    ];

    // =========================================================================
    // 🧹 Pure helpers — no database, safe to call with nothing set up at all
    // =========================================================================

    /**
     * Lower-case, trimmed form of a key, for comparing what a person typed
     * or what an older hand-edited row holds against the canonical stored
     * form. `has()` normalises through this before every lookup.
     *
     * @param string $key
     *
     * @return string
     */
    public static function normaliseKey(string $key): string
    {
        return strtolower(trim($key));
    }

    /**
     * Is this a syntactically valid key for a NEW, organisation-added
     * role? Lower-case letters, digits and underscores, 2-50 characters,
     * starting with a letter — the same shape as every standard key, and
     * deliberately unable to match a ReportRegistry gate word (`@siteAdmin`,
     * `@rootAdmin`), which always starts with `@`.
     *
     * @param string $key
     *
     * @return bool
     */
    public static function isValidNewKey(string $key): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{1,49}$/', $key) === 1;
    }

    /**
     * Build the SQL fragment `App::hasRole()`'s SQL-side callers (starting
     * with #514's calendar-import audience query) can drop straight into a
     * WHERE clause to test "does this person, referred to by $userExpr,
     * hold the role whose tblRoles.roleID is $roleIdExpr, in the
     * organisation $siteExpr" — WITHOUT a subquery of their own and
     * without ever needing to know this class's table names.
     *
     * Every argument must be a plain, developer-written `table.column` (or
     * bare column) identifier, or the literal `?` placeholder — NEVER
     * built from a request. This is checked FIRST, before anything else,
     * so a bad argument throws with no database connection needed at all
     * (`tools/roles-selftest.php` relies on exactly this).
     *
     * WHAT THIS CANNOT DO: it has no global-administrator shortcut (see
     * the class docblock) and it does not itself decide which roleID
     * belongs to which organisation — the caller supplies $roleIdExpr and
     * $siteExpr and is responsible for them agreeing (in practice, both
     * come from a picker built with `forSite()`, which already scopes the
     * roleIDs it offers to one organisation).
     *
     * @param string $userExpr   Developer identifier or '?' for the account.
     * @param string $roleIdExpr Developer identifier or '?' for tblRoles.roleID.
     * @param string $siteExpr   Developer identifier or '?' for the organisation.
     *
     * @return string
     *
     * @throws \InvalidArgumentException When any argument is neither '?' nor a plain identifier.
     */
    public static function holdsSql(string $userExpr, string $roleIdExpr, string $siteExpr): string
    {
        foreach (['userExpr' => $userExpr, 'roleIdExpr' => $roleIdExpr, 'siteExpr' => $siteExpr] as $name => $value) {
            if ($value === '?') {
                continue;
            }
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $value) !== 1) {
                throw new \InvalidArgumentException(
                    "Roles::holdsSql(): {$name} must be '?' or a plain identifier, got: " . $value
                );
            }
        }

        return 'EXISTS (SELECT 1 FROM tblUserRoles ur16 '
            . 'JOIN tblUserSites us16 ON us16.userID = ur16.userID AND us16.siteID = ur16.siteID AND us16.isActive = 1 '
            . "WHERE ur16.userID = {$userExpr} AND ur16.roleID = {$roleIdExpr} AND ur16.siteID = {$siteExpr})";
    }

    // =========================================================================
    // 🔍 Reading
    // =========================================================================

    /**
     * Does this account hold this role, IN THIS ORGANISATION, through an
     * ACTIVE membership? Answers for ONE organisation only — a global
     * administrator's shortcut lives in `App::hasRole()`, not here, so
     * this method (and anything built on `holdsSql()`) never needs to
     * special-case one.
     *
     * @param mysqli $db      An open connection.
     * @param int    $userId  The account.
     * @param int    $siteId  The organisation.
     * @param string $roleKey The role's fixed key (case-insensitive).
     *
     * @return bool
     */
    public static function has(mysqli $db, int $userId, int $siteId, string $roleKey): bool
    {
        $key  = self::normaliseKey($roleKey);
        $stmt = $db->prepare(
            'SELECT 1 FROM tblUserRoles ur '
            . 'JOIN tblRoles r ON r.roleID = ur.roleID AND r.siteID = ur.siteID '
            . 'JOIN tblUserSites us ON us.userID = ur.userID AND us.siteID = ur.siteID AND us.isActive = 1 '
            . 'WHERE ur.userID = ? AND ur.siteID = ? AND r.roleKey = ? LIMIT 1'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('iis', $userId, $siteId, $key);
        $stmt->execute();
        $has = $stmt->get_result()->fetch_assoc() !== null;
        $stmt->close();

        return $has;
    }

    /**
     * Does this account hold this role in ANY organisation where their
     * membership is active? For `Gatekeeper` only — the pre-release
     * channel gate (`portal.devAccessRoles`) is a portal-wide setting, not
     * scoped to one organisation, so it genuinely needs "anywhere" rather
     * than "here" (owner decision, #516 plan Q3).
     *
     * @param mysqli   $db       An open connection.
     * @param int      $userId   The account.
     * @param string[] $roleKeys Role keys to test (case-insensitive); an empty list always answers false.
     *
     * @return bool
     */
    public static function hasAnywhere(mysqli $db, int $userId, array $roleKeys): bool
    {
        if (count($roleKeys) === 0) {
            return false;
        }
        $keys = array_map([self::class, 'normaliseKey'], $roleKeys);

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $db->prepare(
            'SELECT 1 FROM tblUserRoles ur '
            . 'JOIN tblRoles r ON r.roleID = ur.roleID AND r.siteID = ur.siteID '
            . 'JOIN tblUserSites us ON us.userID = ur.userID AND us.siteID = ur.siteID AND us.isActive = 1 '
            . 'WHERE ur.userID = ? AND r.roleKey IN (' . $placeholders . ') LIMIT 1'
        );
        if ($stmt === false) {
            return false;
        }
        $types  = 'i' . str_repeat('s', count($keys));
        $params = array_merge([$userId], $keys);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $has = $stmt->get_result()->fetch_assoc() !== null;
        $stmt->close();

        return $has;
    }

    /**
     * Lower-cased role keys this account holds in this organisation
     * through an active membership. Replaces `Workflow::userRoleKeys()`,
     * which read the same shape without the organisation scope #516 adds.
     *
     * @param mysqli $db     An open connection.
     * @param int    $userId The account.
     * @param int    $siteId The organisation.
     *
     * @return list<string>
     */
    public static function keysHeldBy(mysqli $db, int $userId, int $siteId): array
    {
        $keys = [];
        $stmt = $db->prepare(
            'SELECT r.roleKey FROM tblUserRoles ur '
            . 'JOIN tblRoles r ON r.roleID = ur.roleID AND r.siteID = ur.siteID '
            . 'JOIN tblUserSites us ON us.userID = ur.userID AND us.siteID = ur.siteID AND us.isActive = 1 '
            . 'WHERE ur.userID = ? AND ur.siteID = ?'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('ii', $userId, $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $keys[] = strtolower((string) $row['roleKey']);
        }
        $stmt->close();

        return $keys;
    }

    /**
     * `tblRoles.roleID`s this account holds in this organisation — used by
     * the members page's Roles modal (to pre-tick checkboxes) and by
     * `roles-save.php` (to work out what changed).
     *
     * Deliberately does NOT require an active membership, unlike
     * `keysHeldBy()`/`has()`: a page that is DECIDING what to draw for an
     * account that may have just lost its membership still needs to know
     * what is currently recorded, not what currently grants access.
     *
     * @param mysqli $db     An open connection.
     * @param int    $userId The account.
     * @param int    $siteId The organisation.
     *
     * @return list<int>
     */
    public static function idsHeldBy(mysqli $db, int $userId, int $siteId): array
    {
        $ids  = [];
        $stmt = $db->prepare('SELECT roleID FROM tblUserRoles WHERE userID = ? AND siteID = ?');
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('ii', $userId, $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $ids[] = (int) $row['roleID'];
        }
        $stmt->close();

        return $ids;
    }

    /**
     * Every role this organisation has — the picker `admin/roles`, the
     * members page's Roles modal and #514's audience picker all use this.
     *
     * @param mysqli $db     An open connection.
     * @param int    $siteId The organisation.
     *
     * @return list<array{roleID:int, roleKey:string, roleName:string, description:?string, isStandard:bool}>
     */
    public static function forSite(mysqli $db, int $siteId): array
    {
        $roles = [];
        $stmt  = $db->prepare(
            'SELECT roleID, roleKey, roleName, description, isStandard FROM tblRoles WHERE siteID = ? ORDER BY roleName'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $roles[] = [
                'roleID'      => (int) $row['roleID'],
                'roleKey'     => (string) $row['roleKey'],
                'roleName'    => (string) $row['roleName'],
                'description' => $row['description'] !== null ? (string) $row['description'] : null,
                // #497 — a prepared statement returns the number 1, not the text '1'.
                'isStandard'  => $row['isStandard'] === 1 || $row['isStandard'] === '1',
            ];
        }
        $stmt->close();

        return $roles;
    }

    /**
     * Every holding in this organisation, in one query — `userID => [roleID
     * => roleName]` — so the members page can draw every row's badges
     * without one query per row.
     *
     * @param mysqli $db     An open connection.
     * @param int    $siteId The organisation.
     *
     * @return array<int, array<int, string>>
     */
    public static function holdingsForSite(mysqli $db, int $siteId): array
    {
        $out  = [];
        $stmt = $db->prepare(
            'SELECT ur.userID, r.roleID, r.roleName FROM tblUserRoles ur '
            . 'JOIN tblRoles r ON r.roleID = ur.roleID AND r.siteID = ur.siteID '
            . 'WHERE ur.siteID = ?'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $uid = (int) $row['userID'];
            if (isset($out[$uid]) === false) {
                $out[$uid] = [];
            }
            $out[$uid][(int) $row['roleID']] = (string) $row['roleName'];
        }
        $stmt->close();

        return $out;
    }

    /**
     * How many accounts currently hold this role, in this organisation —
     * used by `admin/roles` to refuse deleting a role that is still held,
     * and to show the count on each row.
     *
     * @param mysqli $db     An open connection.
     * @param int    $roleId The role.
     * @param int    $siteId The organisation (defensive: a roleID never
     *                       belongs to more than one organisation, but the
     *                       count is scoped anyway rather than trusting that).
     *
     * @return int
     */
    public static function countHolders(mysqli $db, int $roleId, int $siteId): int
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) AS cnt FROM tblUserRoles ur '
            . 'JOIN tblRoles r ON r.roleID = ur.roleID AND r.siteID = ur.siteID '
            . 'WHERE ur.roleID = ? AND ur.siteID = ?'
        );
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param('ii', $roleId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['cnt'] ?? 0);
    }

    // =========================================================================
    // ✏️ Writing
    // =========================================================================

    /**
     * Seed the fourteen standard roles for one organisation — called both
     * from migration 202's A9 statement (existing organisations, and every
     * fresh install via the `full_schema.sql` fold) and from
     * `admin/sites/save.php`'s create branch (a brand-new organisation, in
     * the SAME transaction as the `tblSites` INSERT, so an organisation
     * can never exist with no roles to grant).
     *
     * @param mysqli $db     An open connection.
     * @param int    $siteId The organisation to seed.
     *
     * @return int How many rows were actually inserted (0 on a re-run — every key already exists).
     *
     * @throws \RuntimeException On a database error — the caller decides what to do; never swallowed.
     */
    public static function seedStandardSet(mysqli $db, int $siteId): int
    {
        $stmt = $db->prepare(
            'INSERT INTO tblRoles (siteID, roleKey, roleName, description, isStandard) '
            . 'SELECT ?, ?, ?, ?, 1 FROM DUAL WHERE NOT EXISTS ('
            . 'SELECT 1 FROM tblRoles WHERE siteID = ? AND roleKey = ?)'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Roles::seedStandardSet: failed to prepare: ' . $db->error);
        }

        $inserted = 0;
        foreach (self::STANDARD as $key => $role) {
            // 6 placeholders: SELECT ?,?,?,? (i,s,s,s) then WHERE siteID=? AND roleKey=? (i,s) = "isssis".
            $stmt->bind_param(
                'isssis',
                $siteId,
                $key,
                $role['label'],
                $role['description'],
                $siteId,
                $key
            );
            $stmt->execute();
            $inserted += $stmt->affected_rows > 0 ? 1 : 0;
        }
        $stmt->close();

        return $inserted;
    }

    /**
     * Give an account a role, in one organisation. Returns a plain word
     * rather than throwing for the two everyday "nothing changed" cases,
     * so a caller can show a calm message instead of an error page for
     * something that is not really a fault.
     *
     * WHY A PLAIN `INSERT`, NOT `INSERT IGNORE` (recorded so nobody
     * "simplifies" this back to IGNORE): `INSERT IGNORE` would turn a
     * genuine database-level refusal — a non-member, or another
     * organisation's role, caught by the composite foreign keys added in
     * migration 202 — into a silent no-op with only a warning, which is
     * indistinguishable from "already had it". Catching the two specific
     * MySQL error codes below keeps that distinction visible to the
     * caller (`not_found` vs `unchanged`) while still never letting an
     * unexpected database error surface as an uncaught exception to the
     * page. Proven on the prototype database during this feature's own
     * planning: a duplicate INSERT raises 1062 (`uq_user_role`); a
     * non-member raises 1452 (`fk_user_role_membership`); another
     * organisation's role raises 1452 (`fk_user_role_role_site`); the
     * `INSERT IGNORE` form of the SAME non-member attempt instead reports
     * "0 rows affected, 1 warning" with no error at all.
     *
     * @param mysqli   $db      An open connection.
     * @param int      $userId  The account to give the role to.
     * @param int      $roleId  The role (`tblRoles.roleID`).
     * @param int      $siteId  The organisation this holding applies to.
     * @param int|null $actorId Who is granting it, for the audit trail.
     *
     * @return string 'ok' | 'unchanged' | 'not_found'
     */
    public static function grant(mysqli $db, int $userId, int $roleId, int $siteId, ?int $actorId): string
    {
        // 1. The role must exist, in THIS organisation.
        $roleStmt = $db->prepare('SELECT roleKey, roleName FROM tblRoles WHERE roleID = ? AND siteID = ?');
        if ($roleStmt === false) {
            return 'not_found';
        }
        $roleStmt->bind_param('ii', $roleId, $siteId);
        $roleStmt->execute();
        $roleRow = $roleStmt->get_result()->fetch_assoc();
        $roleStmt->close();
        if ($roleRow === null) {
            return 'not_found';
        }

        // 2. The account must be an ACTIVE member of THIS organisation —
        //    costs one lookup that finds nothing, the same work as a
        //    genuinely missing role or account, so this refusal takes no
        //    less time than the one above (no timing oracle).
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

        // 3. Write it. See the docblock above for why this is a plain
        //    INSERT, caught rather than IGNOREd.
        $insStmt = $db->prepare('INSERT INTO tblUserRoles (userID, roleID, siteID, grantedByID) VALUES (?, ?, ?, ?)');
        if ($insStmt === false) {
            return 'not_found';
        }
        try {
            $insStmt->bind_param('iiii', $userId, $roleId, $siteId, $actorId);
            $insStmt->execute();
            $newId = (int) $insStmt->insert_id;
            $insStmt->close();
        } catch (\mysqli_sql_exception $e) {
            $insStmt->close();
            // 1062 = uq_user_role (already held) — a calm "nothing to do".
            if ($e->getCode() === 1062) {
                return 'unchanged';
            }
            // 1452 = either composite foreign key (non-member, or another
            // organisation's role) — both database-level backstops that
            // should never actually fire given the checks above, but if a
            // race slipped past them, this is the honest answer.
            if ($e->getCode() === 1452) {
                return 'not_found';
            }
            throw $e;
        }

        // 4. Audit + activity — account NUMBERS only, never names (the
        //    #518 AccountGuard convention: this record is read by
        //    administrators of the organisation the grant happened in, who
        //    already know who their own members are by number).
        Logger::audit(
            'tblUserRoles',
            $newId,
            'create',
            null,
            ['userID' => $userId, 'roleID' => $roleId, 'siteID' => $siteId, 'roleKey' => (string) $roleRow['roleKey']],
            $actorId
        );
        Logger::activity(
            'UserRoleGrant',
            'Gave role #' . $roleId . ' (' . $roleRow['roleKey'] . ') to account #' . $userId . ' in organisation #' . $siteId,
            $actorId
        );

        return 'ok';
    }

    /**
     * Take a role away from an account, in one organisation.
     *
     * @param mysqli   $db      An open connection.
     * @param int      $userId  The account.
     * @param int      $roleId  The role.
     * @param int      $siteId  The organisation.
     * @param int|null $actorId Who is revoking it, for the audit trail.
     *
     * @return string 'ok' | 'unchanged'
     */
    public static function revoke(mysqli $db, int $userId, int $roleId, int $siteId, ?int $actorId): string
    {
        $stmt = $db->prepare('DELETE FROM tblUserRoles WHERE userID = ? AND roleID = ? AND siteID = ?');
        if ($stmt === false) {
            return 'unchanged';
        }
        $stmt->bind_param('iii', $userId, $roleId, $siteId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected <= 0) {
            return 'unchanged';
        }

        Logger::audit(
            'tblUserRoles',
            0,
            'delete',
            ['userID' => $userId, 'roleID' => $roleId, 'siteID' => $siteId],
            null,
            $actorId
        );
        Logger::activity(
            'UserRoleRevoke',
            'Removed role #' . $roleId . ' from account #' . $userId . ' in organisation #' . $siteId,
            $actorId
        );

        return 'ok';
    }
}
