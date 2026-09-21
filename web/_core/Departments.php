<?php
// Path: _core/Departments.php
/**
 * -----------------------------------------------------------------------------
 * Departments, per organisation 🏢 (#517)
 * -----------------------------------------------------------------------------
 * The class that owns every write to `tblDepts` and `tblUserDepts`, and the
 * shared "is this person in this department, here" lookups (`isMember()`,
 * `memberSql()`).
 *
 * WHAT A DEPARTMENT IS FOR
 * -------------------------------------------------------------------------
 * What an expense claim is charged to. A member of a department can carry
 * five flags (`FLAGS` below). Three of them decide who approves expense
 * claims charged to the department (`expenses/approve/save.php`); the other
 * two are recorded for the department's own use and nothing in the portal
 * acts on them yet. A department can also own an asset, and — from #514 —
 * be the audience of a shared calendar. Departments and user groups
 * (`UserGroups`) are deliberately two different things (owner, 21 September
 * 2026): only departments carry flags.
 *
 * WHAT WAS WRONG BEFORE THIS CLASS
 * -------------------------------------------------------------------------
 * Nothing anywhere could create a department or add a person to one, so
 * department-based expense approval only ever worked if somebody edited the
 * database by hand; the installer seeds no department. `tblDepts` already
 * had an organisation (`siteID`, since migration 015), but `tblUserDepts`
 * did not, and nothing tied a membership to the person's own membership of
 * that organisation. Migration 203 adds both, with composite foreign keys
 * (rules the database enforces over two columns at once), so a membership
 * for a non-member, or naming another organisation's department, is refused
 * by the database itself.
 *
 * WHAT THIS CLASS DELIBERATELY DOES NOT DECIDE
 * -------------------------------------------------------------------------
 * WHO may act — exactly as `UserGroups` (see its docblock): `App::isAdmin()`
 * for the list, the account-change guard's check (AccountGuard, "this
 * organisation" reach) on the page for
 * a person's membership and flags. `addMember()` cannot run the guard
 * itself because the "awaiting placement" page places into an organisation
 * that is not the one open.
 *
 * WHAT "RETIRED" MEANS FOR A DEPARTMENT (owner's answer Q3, 21 September 2026)
 * -------------------------------------------------------------------------
 * A retired department (`isActive` 0 or NULL) accepts no NEW expense claims
 * (the claim form's list and both claim save handlers test `isActive = 1`)
 * and matches nobody in `memberSql()` or in the asset register. But the
 * expense APPROVAL queries deliberately do not test it: claims already
 * charged to it are finished by its own approvers, exactly as before it was
 * retired. The alternative — only an administrator may finish them — was
 * proven during planning to strand a claim for ever, because an
 * administrator's approval never satisfies the rule that every lead and
 * required approver must approve.
 *
 * WHAT THIS CLASS CANNOT DO
 * -------------------------------------------------------------------------
 * - No cache; no global-administrator shortcut in SQL (as `UserGroups`).
 * - A retired department still exists and still names its old claims.
 * - `tblDepts.isActive` may hold NULL (from a hand edit). NULL means "off"
 *   everywhere it is read; `setActive()` is written to cope with it.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.1
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/517
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

use mysqli;

final class Departments
{
    /** Longest department name `tblDepts.deptName` (VARCHAR(100)) can hold, in characters. */
    public const NAME_MAX = 100;

    /** Longest short code `tblDepts.deptCode` (VARCHAR(50)) can hold, in characters. */
    public const CODE_MAX = 50;

    /**
     * The five flags a department member can carry, keyed by their
     * `tblUserDepts` column, with the label and one-sentence description the
     * pages and the help page show. The pages render these straight from
     * here, so the explanation cannot drift from the code.
     *
     * The first three describe `expenses/approve/save.php` exactly: any
     * rejection by an approver rejects the claim at once; every lead and
     * every required approver must approve before a claim is fully
     * approved; when a department has neither, one approval is enough. The
     * last two are read by NOTHING in the portal (checked: only the schema
     * files name them) — the descriptions say so, so nobody is promised a
     * feature that does not exist.
     *
     * @var array<string, array{label: string, description: string}>
     */
    public const FLAGS = [
        'isDeptLead' => [
            'label'       => 'Lead',
            'description' => 'Approves expense claims charged to this department; a claim is not fully approved until every lead has approved it',
        ],
        'isMandatoryApprover' => [
            'label'       => 'Required approver',
            'description' => 'A claim is not fully approved until this person approves it',
        ],
        'isApprover' => [
            'label'       => 'Approver',
            'description' => 'May approve or reject expense claims charged to this department',
        ],
        'isDeptAssistant' => [
            'label'       => 'Assistant',
            'description' => 'Recorded for the department\'s own use; nothing in the portal acts on it yet',
        ],
        'isDeptSecretary' => [
            'label'       => 'Secretary',
            'description' => 'Recorded for the department\'s own use; nothing in the portal acts on it yet',
        ],
    ];

    // =========================================================================
    // 🧹 Pure helpers — no database, safe to call with nothing set up at all
    // =========================================================================

    /**
     * Build the SQL fragment #514's shared-calendar audience query drops into
     * a WHERE clause to ask "is the person $userExpr a CURRENT member of
     * department $deptIdExpr, in organisation $siteExpr?" — the #516
     * `Roles::holdsSql()` shape, and the twin of `UserGroups::memberSql()`.
     *
     * "Current" means: a membership row for that department in that
     * organisation; the department belongs to that organisation; the
     * department is not retired (`isActive = 1`, so a NULL also answers no);
     * and the person's own membership of the organisation is active. Proven
     * on a real database during planning, including a department whose
     * `isActive` is NULL answering 0.
     *
     * It does NOT look at the five flags: being IN a department is the
     * question. Arguments are checked first, exactly as
     * `UserGroups::memberSql()`.
     *
     * @param string $userExpr   Developer identifier or '?' for the account.
     * @param string $deptIdExpr Developer identifier or '?' for tblDepts.deptID.
     * @param string $siteExpr   Developer identifier or '?' for the organisation.
     *
     * @return string
     *
     * @throws \InvalidArgumentException When any argument is neither '?' nor a plain identifier.
     */
    public static function memberSql(string $userExpr, string $deptIdExpr, string $siteExpr): string
    {
        foreach (['userExpr' => $userExpr, 'deptIdExpr' => $deptIdExpr, 'siteExpr' => $siteExpr] as $name => $value) {
            if ($value === '?') {
                continue;
            }
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $value) !== 1) {
                throw new \InvalidArgumentException(
                    "Departments::memberSql(): {$name} must be '?' or a plain identifier, got: " . $value
                );
            }
        }

        return 'EXISTS (SELECT 1 FROM tblDepts d17 '
            . 'JOIN tblUserDepts ud17 ON ud17.deptID = d17.deptID AND ud17.siteID = d17.siteID '
            . 'JOIN tblUserSites us17 ON us17.userID = ud17.userID AND us17.siteID = ud17.siteID AND us17.isActive = 1 '
            . "WHERE ud17.userID = {$userExpr} AND ud17.deptID = {$deptIdExpr} AND ud17.siteID = {$siteExpr} "
            . 'AND d17.isActive = 1)';
    }

    /**
     * Turn whatever the caller has (posted checkboxes, a parked row from the
     * database) into exactly the five flag columns, each 0 or 1. Any key
     * that is not in `FLAGS` is ignored; a missing key is 0. "On" means the
     * value `true`, the number 1 or the text '1' — a prepared statement
     * returns the number (#497), a form posts the text.
     *
     * @param array<string, mixed> $flags
     *
     * @return array<string, int>
     */
    public static function normaliseFlags(array $flags): array
    {
        $out = [];
        foreach (array_keys(self::FLAGS) as $key) {
            $value     = $flags[$key] ?? 0;
            $out[$key] = ($value === true || $value === 1 || $value === '1') ? 1 : 0;
        }

        return $out;
    }

    /**
     * Read a database yes/no flag (see `normaliseFlags()`); NULL is "off".
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
     * Is this account a CURRENT member of this department, in this
     * organisation? The same conditions as `memberSql()`.
     *
     * @param mysqli $db     An open connection.
     * @param int    $userId The account.
     * @param int    $siteId The organisation.
     * @param int    $deptId The department.
     *
     * @return bool
     */
    public static function isMember(mysqli $db, int $userId, int $siteId, int $deptId): bool
    {
        $stmt = $db->prepare(
            'SELECT 1 FROM tblDepts d '
            . 'JOIN tblUserDepts ud ON ud.deptID = d.deptID AND ud.siteID = d.siteID '
            . 'JOIN tblUserSites us ON us.userID = ud.userID AND us.siteID = ud.siteID AND us.isActive = 1 '
            . 'WHERE ud.userID = ? AND ud.deptID = ? AND ud.siteID = ? AND d.isActive = 1 LIMIT 1'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('iii', $userId, $deptId, $siteId);
        $stmt->execute();
        $is = $stmt->get_result()->fetch_assoc() !== null;
        $stmt->close();

        return $is;
    }

    /**
     * The numbers of every department, in this organisation, that this
     * account is a CURRENT member of (the same conditions as `isMember()`).
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
            'SELECT d.deptID FROM tblDepts d '
            . 'JOIN tblUserDepts ud ON ud.deptID = d.deptID AND ud.siteID = d.siteID '
            . 'JOIN tblUserSites us ON us.userID = ud.userID AND us.siteID = ud.siteID AND us.isActive = 1 '
            . 'WHERE ud.userID = ? AND ud.siteID = ? AND d.isActive = 1'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('ii', $userId, $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $ids[] = (int) $row['deptID'];
        }
        $stmt->close();

        return $ids;
    }

    /**
     * The departments of ONE organisation whose expense claims this account
     * may decide, keyed by department number, each with which of the three
     * approval flags the person holds there (#542).
     *
     * This is the per-department flag test `expenses/approve/save.php` has
     * always made before recording a decision, lifted into the class so the
     * decision handler and the claim page (`expenses/view/index.php`) ask
     * the same question from one place rather than a third copy of the
     * join. The conditions are exactly the handler's: a membership row for
     * THIS organisation (`ud.siteID`), an ACTIVE membership of the
     * organisation itself (the `tblUserSites` join), and at least one of
     * lead, approver or required approver.
     *
     * WHAT WAS WRONG BEFORE (#542): the decision handler and the claim page
     * both asked for the Expense Approver ROLE before they looked at any
     * department flag. The role (#516) and the flags (#517) are set on
     * different pages, so a lead or required approver without the role was
     * refused, while the claim still waited for their approval, which an
     * administrator's approval never stands in for. Such a claim could never
     * be approved, and so never paid. The owner's decision (21 September
     * 2026): a flag in the claim's own department is enough.
     *
     * DELIBERATELY NOT `isMember()` or `memberSql()`: those answer "is this
     * person in this department NOW?" and leave out a retired department.
     * Here a retired department is INCLUDED when the person holds a flag in
     * it, because its pending claims are finished by its own approvers
     * (owner's answer Q3, 21 September 2026); nothing tests `tblDepts` at all.
     *
     * A flag held in another organisation's department never appears: the
     * query asks for one `siteID`, and the composite foreign keys on
     * `tblUserDepts` stop a row naming another organisation's department
     * from existing in the first place. An empty array means "no authority
     * over any department here".
     *
     * @param mysqli $db     An open connection.
     * @param int    $userId The account.
     * @param int    $siteId The organisation.
     *
     * @return array<int, array{isDeptLead: bool, isMandatoryApprover: bool, isApprover: bool}>
     */
    public static function approverDepts(mysqli $db, int $userId, int $siteId): array
    {
        $out  = [];
        $stmt = $db->prepare(
            'SELECT ud.deptID, ud.isDeptLead, ud.isMandatoryApprover, ud.isApprover FROM tblUserDepts ud '
            . 'JOIN tblUserSites us ON us.userID = ud.userID AND us.siteID = ud.siteID AND us.isActive = 1 '
            . 'WHERE ud.userID = ? AND ud.siteID = ? '
            . 'AND (ud.isDeptLead = 1 OR ud.isApprover = 1 OR ud.isMandatoryApprover = 1)'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('ii', $userId, $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $out[(int) $row['deptID']] = [
                'isDeptLead'          => self::flagOn($row['isDeptLead']),
                'isMandatoryApprover' => self::flagOn($row['isMandatoryApprover']),
                'isApprover'          => self::flagOn($row['isApprover']),
            ];
        }
        $stmt->close();

        return $out;
    }

    /**
     * Every department of this organisation, in ONE query, ordered by name.
     * With `$activeOnly = true` this is the picker #514 uses (retired
     * departments, including a NULL `isActive`, are left out).
     *
     * @param mysqli $db         An open connection.
     * @param int    $siteId     The organisation.
     * @param bool   $activeOnly True to leave out retired departments.
     *
     * @return list<array{deptID:int, deptName:string, deptCode:?string, isActive:bool}>
     */
    public static function forSite(mysqli $db, int $siteId, bool $activeOnly = false): array
    {
        $depts = [];
        $sql   = 'SELECT deptID, deptName, deptCode, isActive FROM tblDepts WHERE siteID = ?';
        if ($activeOnly === true) {
            $sql .= ' AND isActive = 1';
        }
        $sql .= ' ORDER BY deptName';

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $depts[] = [
                'deptID'   => (int) $row['deptID'],
                'deptName' => (string) ($row['deptName'] ?? ''),
                'deptCode' => $row['deptCode'] !== null ? (string) $row['deptCode'] : null,
                'isActive' => self::flagOn($row['isActive']),
            ];
        }
        $stmt->close();

        return $depts;
    }

    /**
     * One department, looked up by (deptID, siteID) — another organisation's
     * number costs one lookup that finds nothing, exactly like a made-up one.
     *
     * @param mysqli $db     An open connection.
     * @param int    $deptId The department.
     * @param int    $siteId The organisation it must belong to.
     *
     * @return array{deptID:int, siteID:int, deptName:string, deptCode:?string, isActive:bool}|null
     */
    public static function get(mysqli $db, int $deptId, int $siteId): ?array
    {
        $stmt = $db->prepare(
            'SELECT deptID, siteID, deptName, deptCode, isActive FROM tblDepts WHERE deptID = ? AND siteID = ? LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ii', $deptId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return null;
        }

        return [
            'deptID'   => (int) $row['deptID'],
            'siteID'   => (int) $row['siteID'],
            'deptName' => (string) ($row['deptName'] ?? ''),
            'deptCode' => $row['deptCode'] !== null ? (string) $row['deptCode'] : null,
            'isActive' => self::flagOn($row['isActive']),
        ];
    }

    /**
     * Everyone recorded in this department, with their five flags, for the
     * roster page. Like `UserGroups::membersOf()`, it does not require an
     * active organisation membership; `memberActive` says whether it is.
     *
     * @param mysqli $db     An open connection.
     * @param int    $deptId The department.
     * @param int    $siteId The organisation.
     *
     * @return list<array<string, mixed>> Each row: userID, fullName, emailAddress, addedAt,
     *                                    memberActive (bool) and one bool per key of FLAGS.
     */
    public static function membersOf(mysqli $db, int $deptId, int $siteId): array
    {
        $members = [];
        $stmt    = $db->prepare(
            'SELECT u.userID, u.fullName, u.emailAddress, ud.addedAt, us.isActive AS memberActive, '
            . 'ud.isDeptLead, ud.isMandatoryApprover, ud.isApprover, ud.isDeptAssistant, ud.isDeptSecretary '
            . 'FROM tblDepts d '
            . 'JOIN tblUserDepts ud ON ud.deptID = d.deptID AND ud.siteID = d.siteID '
            . 'JOIN tblUsers u ON u.userID = ud.userID '
            . 'JOIN tblUserSites us ON us.userID = ud.userID AND us.siteID = ud.siteID '
            . 'WHERE d.deptID = ? AND d.siteID = ? '
            . 'ORDER BY u.fullName'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('ii', $deptId, $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($row = $result->fetch_assoc()) !== null) {
            $member = [
                'userID'       => (int) $row['userID'],
                'fullName'     => (string) ($row['fullName'] ?? ''),
                'emailAddress' => (string) ($row['emailAddress'] ?? ''),
                'addedAt'      => (string) ($row['addedAt'] ?? ''),
                'memberActive' => self::flagOn($row['memberActive']),
            ];
            foreach (array_keys(self::FLAGS) as $key) {
                $member[$key] = self::flagOn($row[$key]);
            }
            $members[] = $member;
        }
        $stmt->close();

        return $members;
    }

    /**
     * How many membership rows this department has in this organisation.
     *
     * @param mysqli $db     An open connection.
     * @param int    $deptId The department.
     * @param int    $siteId The organisation.
     *
     * @return int
     */
    public static function countMembers(mysqli $db, int $deptId, int $siteId): int
    {
        $stmt = $db->prepare('SELECT COUNT(*) AS cnt FROM tblUserDepts WHERE deptID = ? AND siteID = ?');
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param('ii', $deptId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * What still points at this department, and so stops it being deleted
     * (the owner's rule, 21 September 2026: only while it has no members,
     * owns no assets and has no expense claims; otherwise retire it).
     *
     * @param mysqli $db     An open connection.
     * @param int    $deptId The department.
     * @param int    $siteId The organisation.
     *
     * @return array{members:int, assetOwners:int, expenseClaims:int}
     */
    public static function deleteBlockers(mysqli $db, int $deptId, int $siteId): array
    {
        $out = [
            'members'       => self::countMembers($db, $deptId, $siteId),
            'assetOwners'   => 0,
            'expenseClaims' => 0,
        ];

        $ownerStmt = $db->prepare(
            "SELECT COUNT(*) AS cnt FROM tblAssetOwners WHERE partyType = 'dept' AND deptID = ? AND siteID = ?"
        );
        if ($ownerStmt !== false) {
            $ownerStmt->bind_param('ii', $deptId, $siteId);
            $ownerStmt->execute();
            $out['assetOwners'] = (int) ($ownerStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
            $ownerStmt->close();
        }

        $claimStmt = $db->prepare('SELECT COUNT(*) AS cnt FROM tblExpenseClaims WHERE deptID = ? AND siteID = ?');
        if ($claimStmt !== false) {
            $claimStmt->bind_param('ii', $deptId, $siteId);
            $claimStmt->execute();
            $out['expenseClaims'] = (int) ($claimStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
            $claimStmt->close();
        }

        return $out;
    }

    // =========================================================================
    // ✏️ Writing the list of departments
    // =========================================================================

    /**
     * Check a name and code before they are written; returns the cleaned
     * values or throws (the pages check first and show a calm message).
     *
     * @param string      $name
     * @param string|null $code
     *
     * @return array{0:string, 1:?string}
     *
     * @throws \InvalidArgumentException
     */
    private static function cleanNameAndCode(string $name, ?string $code): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name, 'UTF-8') > self::NAME_MAX) {
            throw new \InvalidArgumentException('A department name is required and may be at most ' . self::NAME_MAX . ' characters.');
        }
        if ($code !== null) {
            $code = trim($code);
            if ($code === '') {
                $code = null;
            } elseif (mb_strlen($code, 'UTF-8') > self::CODE_MAX) {
                throw new \InvalidArgumentException('A department code may be at most ' . self::CODE_MAX . ' characters.');
            }
        }

        return [$name, $code];
    }

    /**
     * Create a department in one organisation (switched on).
     *
     * @param mysqli      $db      An open connection.
     * @param int         $siteId  The organisation.
     * @param string      $name    1-100 characters after trimming.
     * @param string|null $code    Optional short code, up to 50 characters.
     * @param int|null    $actorId Who is creating it.
     *
     * @return int The new department's number.
     *
     * @throws \InvalidArgumentException On a name or code that does not fit.
     * @throws \RuntimeException         When the statement cannot be prepared.
     */
    public static function create(mysqli $db, int $siteId, string $name, ?string $code, ?int $actorId): int
    {
        [$name, $code] = self::cleanNameAndCode($name, $code);

        $stmt = $db->prepare('INSERT INTO tblDepts (siteID, deptName, deptCode, isActive) VALUES (?, ?, ?, 1)');
        if ($stmt === false) {
            throw new \RuntimeException('Departments::create: failed to prepare: ' . $db->error);
        }
        $stmt->bind_param('iss', $siteId, $name, $code);
        $stmt->execute();
        $newId = (int) $stmt->insert_id;
        $stmt->close();

        Logger::audit(
            'tblDepts',
            $newId,
            'create',
            null,
            ['siteID' => $siteId, 'deptName' => $name, 'deptCode' => $code],
            $actorId
        );
        Logger::activity(
            'DeptCreate',
            'Created department "' . $name . '" (#' . $newId . ') in organisation #' . $siteId,
            $actorId
        );

        return $newId;
    }

    /**
     * Rename a department and/or change its code.
     *
     * @param mysqli      $db      An open connection.
     * @param int         $deptId  The department.
     * @param int         $siteId  The organisation it must belong to.
     * @param string      $name    1-100 characters after trimming.
     * @param string|null $code    Optional short code.
     * @param int|null    $actorId Who is changing it.
     *
     * @return string 'ok' | 'not_found'
     *
     * @throws \InvalidArgumentException On a name or code that does not fit.
     */
    public static function update(mysqli $db, int $deptId, int $siteId, string $name, ?string $code, ?int $actorId): string
    {
        [$name, $code] = self::cleanNameAndCode($name, $code);

        $old = self::get($db, $deptId, $siteId);
        if ($old === null) {
            return 'not_found';
        }

        $stmt = $db->prepare('UPDATE tblDepts SET deptName = ?, deptCode = ? WHERE deptID = ? AND siteID = ?');
        if ($stmt === false) {
            return 'not_found';
        }
        $stmt->bind_param('ssii', $name, $code, $deptId, $siteId);
        $stmt->execute();
        $stmt->close();

        Logger::audit(
            'tblDepts',
            $deptId,
            'update',
            ['deptName' => $old['deptName'], 'deptCode' => $old['deptCode']],
            ['deptName' => $name, 'deptCode' => $code],
            $actorId
        );
        Logger::activity(
            'DeptUpdate',
            'Updated department "' . $name . '" (#' . $deptId . ') in organisation #' . $siteId,
            $actorId
        );

        return 'ok';
    }

    /**
     * Retire (switch off) or reinstate (switch on) a department.
     *
     * WHY `COALESCE(isActive, 0) <> ?` AND NOT A PLAIN `isActive <> ?`:
     * `tblDepts.isActive` is nullable and a hand-inserted row can hold NULL.
     * In SQL any comparison with NULL is "unknown", which a WHERE treats as
     * false — so `isActive <> 1` matches nothing for such a row, and it
     * could never be reinstated OR retired from the page. Proven during
     * planning: the plain form matched 0 rows, this form matched 1. NULL is
     * counted as "off" here, exactly as everywhere else that reads it.
     *
     * @param mysqli   $db      An open connection.
     * @param int      $deptId  The department.
     * @param int      $siteId  The organisation it must belong to.
     * @param bool     $active  True to reinstate, false to retire.
     * @param int|null $actorId Who is changing it.
     *
     * @return string 'ok' | 'unchanged' | 'not_found'
     */
    public static function setActive(mysqli $db, int $deptId, int $siteId, bool $active, ?int $actorId): string
    {
        $old = self::get($db, $deptId, $siteId);
        if ($old === null) {
            return 'not_found';
        }

        $flag = $active === true ? 1 : 0;
        $stmt = $db->prepare(
            'UPDATE tblDepts SET isActive = ? WHERE deptID = ? AND siteID = ? AND COALESCE(isActive, 0) <> ?'
        );
        if ($stmt === false) {
            return 'unchanged';
        }
        $stmt->bind_param('iiii', $flag, $deptId, $siteId, $flag);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected <= 0) {
            return 'unchanged';
        }

        Logger::audit(
            'tblDepts',
            $deptId,
            'update',
            ['isActive' => $old['isActive'] === true ? 1 : 0],
            ['isActive' => $flag],
            $actorId
        );
        Logger::activity(
            $active === true ? 'DeptReinstate' : 'DeptRetire',
            ($active === true ? 'Reinstated' : 'Retired') . ' department "' . $old['deptName'] . '" (#' . $deptId
                . ') in organisation #' . $siteId,
            $actorId
        );

        return 'ok';
    }

    /**
     * Delete a department — only while nothing still points at it (see
     * `deleteBlockers()`). An expense claim charged to it also makes the
     * database itself refuse (error 1451, "a row still points at this":
     * `tblExpenseClaims`' foreign key has no "delete these too" rule); that
     * error is caught and answered as `in_use`, never a crashed page, should
     * a claim appear between the count and the delete.
     *
     * Deleting a department also removes, through their foreign keys, any
     * parked memberships for it on the "awaiting placement" page — they name
     * a department that no longer exists, so there is nothing left to place
     * them into.
     *
     * @param mysqli   $db      An open connection.
     * @param int      $deptId  The department.
     * @param int      $siteId  The organisation it must belong to.
     * @param int|null $actorId Who is deleting it.
     *
     * @return string 'ok' | 'not_found' | 'in_use'
     */
    public static function delete(mysqli $db, int $deptId, int $siteId, ?int $actorId): string
    {
        $old = self::get($db, $deptId, $siteId);
        if ($old === null) {
            return 'not_found';
        }

        $blockers = self::deleteBlockers($db, $deptId, $siteId);
        if ($blockers['members'] > 0 || $blockers['assetOwners'] > 0 || $blockers['expenseClaims'] > 0) {
            return 'in_use';
        }

        $stmt = $db->prepare('DELETE FROM tblDepts WHERE deptID = ? AND siteID = ?');
        if ($stmt === false) {
            return 'not_found';
        }
        try {
            $stmt->bind_param('ii', $deptId, $siteId);
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
            'tblDepts',
            $deptId,
            'delete',
            ['siteID' => $siteId, 'deptName' => $old['deptName'], 'deptCode' => $old['deptCode']],
            null,
            $actorId
        );
        Logger::activity(
            'DeptDelete',
            'Deleted department "' . $old['deptName'] . '" (#' . $deptId . ') in organisation #' . $siteId,
            $actorId
        );

        return 'ok';
    }

    // =========================================================================
    // 👤 Writing memberships and flags
    // =========================================================================

    /**
     * Add an account to a department, in one organisation, with its starting
     * flags. The same rules, the same plain-INSERT reasoning and the same
     * answers as `UserGroups::addMember()` (see its docblock for why this is
     * never `INSERT IGNORE`).
     *
     * @param mysqli               $db      An open connection.
     * @param int                  $userId  The account to add.
     * @param int                  $deptId  The department.
     * @param int                  $siteId  The organisation the department must belong to.
     * @param int|null             $actorId Who is adding them.
     * @param array<string, mixed> $flags   Starting flags; see `normaliseFlags()`.
     *
     * @return string 'ok' | 'unchanged' | 'not_found' | 'inactive'
     */
    public static function addMember(mysqli $db, int $userId, int $deptId, int $siteId, ?int $actorId, array $flags = []): string
    {
        // 1. The department must exist here, and not be retired.
        $dept = self::get($db, $deptId, $siteId);
        if ($dept === null) {
            return 'not_found';
        }
        if ($dept['isActive'] === false) {
            return 'inactive';
        }

        // 2. The account must be an ACTIVE member of this organisation (one
        //    lookup either way — see UserGroups::addMember()).
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

        // 3. Write it, flags included, in one statement.
        $f       = self::normaliseFlags($flags);
        $insStmt = $db->prepare(
            'INSERT INTO tblUserDepts (userID, deptID, siteID, isDeptLead, isDeptAssistant, isDeptSecretary, '
            . 'isApprover, isMandatoryApprover, addedByID) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($insStmt === false) {
            return 'not_found';
        }
        try {
            $insStmt->bind_param(
                'iiiiiiiii',
                $userId,
                $deptId,
                $siteId,
                $f['isDeptLead'],
                $f['isDeptAssistant'],
                $f['isDeptSecretary'],
                $f['isApprover'],
                $f['isMandatoryApprover'],
                $actorId
            );
            $insStmt->execute();
            $newId = (int) $insStmt->insert_id;
            $insStmt->close();
        } catch (\mysqli_sql_exception $e) {
            $insStmt->close();
            if ($e->getCode() === 1062) {
                return 'unchanged';
            }
            if ($e->getCode() === 1452) {
                return 'not_found';
            }
            throw $e;
        }

        Logger::audit(
            'tblUserDepts',
            $newId,
            'create',
            null,
            array_merge(['userID' => $userId, 'deptID' => $deptId, 'siteID' => $siteId], $f),
            $actorId
        );
        Logger::activity(
            'DeptMemberAdd',
            'Added account #' . $userId . ' to department #' . $deptId . ' in organisation #' . $siteId,
            $actorId
        );

        return 'ok';
    }

    /**
     * Change a member's five flags. Allowed on a retired department too, on
     * purpose: its pending claims are still finished by its own approvers
     * (owner's answer Q3), so an administrator may need to change who that is.
     *
     * @param mysqli               $db      An open connection.
     * @param int                  $userId  The account.
     * @param int                  $deptId  The department.
     * @param int                  $siteId  The organisation.
     * @param array<string, mixed> $flags   The new flags; see `normaliseFlags()`.
     * @param int|null             $actorId Who is changing them.
     *
     * @return string 'ok' | 'unchanged' | 'not_found'
     */
    public static function setFlags(mysqli $db, int $userId, int $deptId, int $siteId, array $flags, ?int $actorId): string
    {
        $readStmt = $db->prepare(
            'SELECT userDeptID, isDeptLead, isDeptAssistant, isDeptSecretary, isApprover, isMandatoryApprover '
            . 'FROM tblUserDepts WHERE userID = ? AND deptID = ? AND siteID = ? LIMIT 1'
        );
        if ($readStmt === false) {
            return 'not_found';
        }
        $readStmt->bind_param('iii', $userId, $deptId, $siteId);
        $readStmt->execute();
        $row = $readStmt->get_result()->fetch_assoc();
        $readStmt->close();
        if ($row === null) {
            return 'not_found';
        }

        $old = [];
        foreach (array_keys(self::FLAGS) as $key) {
            $old[$key] = self::flagOn($row[$key]) === true ? 1 : 0;
        }
        $new = self::normaliseFlags($flags);

        $stmt = $db->prepare(
            'UPDATE tblUserDepts SET isDeptLead = ?, isDeptAssistant = ?, isDeptSecretary = ?, isApprover = ?, '
            . 'isMandatoryApprover = ? WHERE userID = ? AND deptID = ? AND siteID = ?'
        );
        if ($stmt === false) {
            return 'unchanged';
        }
        $stmt->bind_param(
            'iiiiiiii',
            $new['isDeptLead'],
            $new['isDeptAssistant'],
            $new['isDeptSecretary'],
            $new['isApprover'],
            $new['isMandatoryApprover'],
            $userId,
            $deptId,
            $siteId
        );
        $stmt->execute();
        // MySQL counts a row as "affected" only when a value really changed,
        // so saving the same flags again answers 'unchanged'.
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected <= 0) {
            return 'unchanged';
        }

        // The audit record names whose membership and which organisation,
        // like the add and remove records do, not just the flags.
        $identity = ['userID' => $userId, 'deptID' => $deptId, 'siteID' => $siteId];
        Logger::audit('tblUserDepts', (int) $row['userDeptID'], 'update', $identity + $old, $identity + $new, $actorId);
        Logger::activity(
            'DeptMemberFlags',
            'Changed the flags of account #' . $userId . ' in department #' . $deptId . ' in organisation #' . $siteId,
            $actorId
        );

        return 'ok';
    }

    /**
     * Remove an account from a department, in one organisation.
     *
     * @param mysqli   $db      An open connection.
     * @param int      $userId  The account.
     * @param int      $deptId  The department.
     * @param int      $siteId  The organisation.
     * @param int|null $actorId Who is removing them.
     *
     * @return string 'ok' | 'unchanged'
     */
    public static function removeMember(mysqli $db, int $userId, int $deptId, int $siteId, ?int $actorId): string
    {
        $readStmt = $db->prepare(
            'SELECT userDeptID, addedAt, isDeptLead, isDeptAssistant, isDeptSecretary, isApprover, isMandatoryApprover '
            . 'FROM tblUserDepts WHERE userID = ? AND deptID = ? AND siteID = ? LIMIT 1'
        );
        if ($readStmt === false) {
            return 'unchanged';
        }
        $readStmt->bind_param('iii', $userId, $deptId, $siteId);
        $readStmt->execute();
        $row = $readStmt->get_result()->fetch_assoc();
        $readStmt->close();
        if ($row === null) {
            return 'unchanged';
        }

        $stmt = $db->prepare('DELETE FROM tblUserDepts WHERE userID = ? AND deptID = ? AND siteID = ?');
        if ($stmt === false) {
            return 'unchanged';
        }
        $stmt->bind_param('iii', $userId, $deptId, $siteId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected <= 0) {
            return 'unchanged';
        }

        $oldRow = ['userID' => $userId, 'deptID' => $deptId, 'siteID' => $siteId, 'addedAt' => (string) $row['addedAt']];
        foreach (array_keys(self::FLAGS) as $key) {
            $oldRow[$key] = self::flagOn($row[$key]) === true ? 1 : 0;
        }
        Logger::audit('tblUserDepts', (int) $row['userDeptID'], 'delete', $oldRow, null, $actorId);
        Logger::activity(
            'DeptMemberRemove',
            'Removed account #' . $userId . ' from department #' . $deptId . ' in organisation #' . $siteId,
            $actorId
        );

        return 'ok';
    }
}
