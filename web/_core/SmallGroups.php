<?php
// Path: _core/SmallGroups.php
/**
 * -----------------------------------------------------------------------------
 * Small Groups — query/write surface 👥 (#150)
 * -----------------------------------------------------------------------------
 * Static helper mirroring the house pattern (Discipleship, Venues, Giving).
 * This class is the single tenant-safety choke-point for the Small Groups
 * app AND the stable contract later features (#304 group messaging, #321
 * watch-party rooms) are expected to consume — see the docblocks on
 * `membersOf()` / `isMember()` / `isLeader()` / `groupsFor()` below.
 *
 * Every method takes `$siteId` explicitly (Discipleship precedent) and
 * site-scopes every query — a foreign `groupID` behaves as nonexistent, the
 * same "cross-tenant id is indistinguishable from missing" discipline used
 * throughout this codebase (Venues, Workflow, …).
 *
 * The denormalised `siteID` carried on `tblSmallGroupMembers` /
 * `tblSmallGroupMeetings` is written ONLY by `upsertMembership()` (members)
 * and the meeting-save handler (meetings) — always copied from the GROUP
 * row, never taken from POST (tblVenueRooms precedent). Cross-site
 * membership is structurally impossible: `upsertMembership()` additionally
 * requires the target user to hold an ACTIVE `tblUserSites` row for the
 * group's own site (the leadership user-picker join,
 * `web/_apps/leadership/assign.php:87-93`).
 *
 * v1 stores NO minor/child rows — `tblSmallGroupMembers.userID` is a
 * `tblUsers` FK only (portal users/adults). Named children live exclusively
 * in the Kids app's `tblKidProfiles` (safeguarding model) — never here.
 *
 * MySQLi prepared statements only throughout.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/150
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class SmallGroups
{
    /** @var array<string,bool> */
    private const VALID_ROLES = ['leader' => true, 'co-leader' => true, 'member' => true];

    /** @var array<string,bool> */
    private const VALID_STATUSES = ['active' => true, 'pending' => true, 'ended' => true];

    // -------------------------------------------------------------------------
    // 🎛️ Feature gate
    // -------------------------------------------------------------------------

    /**
     * AppRegistry-backed enable check (Discipleship::isEnabled precedent) —
     * walks `$SETTINGS['small-groups']['enabled']`, accepting '1' or 'true'.
     */
    public static function isEnabled(): bool
    {
        return AppRegistry::isEnabled('small-groups');
    }

    // -------------------------------------------------------------------------
    // 📖 Reads
    // -------------------------------------------------------------------------

    /**
     * One group row (all columns) or null. Site-scoped:
     * WHERE groupID = ? AND siteID = ?.
     *
     * @return array<string,mixed>|null
     */
    public static function getGroup(int $siteId, int $groupId): ?array
    {
        if ($groupId <= 0) {
            return null;
        }
        $db = self::db();
        $stmt = $db->prepare('SELECT * FROM tblSmallGroups WHERE groupID = ? AND siteID = ? LIMIT 1');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ii', $groupId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row !== null ? $row : null;
    }

    /**
     * Groups for the site, with active-member counts + leader names.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function listGroups(int $siteId, bool $activeOnly = true): array
    {
        // 🧩 Neither sub-query below gives tblSmallGroupMembers a short name
        // straight after its FROM (the second brings it in with a JOIN), so
        // that tools/audit-checks/check_sql_columns.py gives no wrong report:
        // in "FROM tblSmallGroupMembers m WHERE" it takes the "Group" inside
        // the name as a GROUP BY and WRONGLY REPORTS "tblSmallGroup" as an
        // unknown table (blind spot 15 there; the older version, commit
        // 9c77216, said "tblSmall"). The outer FROM keeps its short name, g,
        // and is not reported only because that check cannot read a column
        // list across a line break and the double-quoted pieces above the
        // FROM are not joined onto its line (it joins single-quoted strings
        // only): make every piece single-quoted and it WOULD be reported.
        $db = self::db();
        $sql = 'SELECT g.*, '
            . "(SELECT COUNT(*) FROM tblSmallGroupMembers WHERE groupID = g.groupID AND status = 'active') AS memberCount, "
            . "(SELECT GROUP_CONCAT(u.fullName SEPARATOR ', ') "
            . 'FROM tblUsers u '
            . 'INNER JOIN tblSmallGroupMembers ON tblSmallGroupMembers.userID = u.userID '
            . "WHERE tblSmallGroupMembers.groupID = g.groupID AND tblSmallGroupMembers.status = 'active' "
            . "AND tblSmallGroupMembers.memberRole IN ('leader','co-leader')) AS leaderNames "
            . 'FROM tblSmallGroups g WHERE g.siteID = ? '
            . ($activeOnly === true ? 'AND g.isActive = 1 ' : '')
            . 'ORDER BY g.sortOrder, g.groupName';
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /**
     * Membership + group rows for one user — the "my groups" surface AND
     * the #321 "rooms/conversations available to me" listing surface.
     * $status defaults to the canonical 'active'.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function groupsFor(int $siteId, int $userId, string $status = 'active'): array
    {
        if (isset(self::VALID_STATUSES[$status]) === false) {
            $status = 'active';
        }
        $db = self::db();
        $stmt = $db->prepare(
            'SELECT tblSmallGroups.*, m.membershipID, m.memberRole, m.status AS membershipStatus, '
            . 'm.joinedAt, m.endedAt, m.requestNote '
            . 'FROM tblSmallGroups '
            . 'INNER JOIN tblSmallGroupMembers m ON m.groupID = tblSmallGroups.groupID '
            . 'WHERE m.userID = ? AND m.siteID = ? AND m.status = ? '
            . 'ORDER BY tblSmallGroups.groupName'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('iis', $userId, $siteId, $status);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /**
     * Roster for one group (JOIN tblUsers for fullName/emailAddress).
     * $status null = all statuses (leader/admin roster view); 'active' =
     * the #304 conversation-membership seed surface — consumers building a
     * group's chat/room membership list MUST call this (or `isMember()` /
     * `isLeader()` below), never roll their own join against
     * tblSmallGroupMembers directly.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function membersOf(int $siteId, int $groupId, ?string $status = 'active'): array
    {
        if ($status !== null && isset(self::VALID_STATUSES[$status]) === false) {
            $status = 'active';
        }
        $db = self::db();
        $sql = 'SELECT tblSmallGroupMembers.*, u.fullName, u.emailAddress '
            . 'FROM tblSmallGroupMembers '
            . 'INNER JOIN tblUsers u ON u.userID = tblSmallGroupMembers.userID '
            . 'WHERE tblSmallGroupMembers.groupID = ? AND tblSmallGroupMembers.siteID = ? '
            . ($status !== null ? 'AND tblSmallGroupMembers.status = ? ' : '')
            . "ORDER BY FIELD(tblSmallGroupMembers.memberRole, 'leader', 'co-leader', 'member'), u.fullName";
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        if ($status !== null) {
            $stmt->bind_param('iis', $groupId, $siteId, $status);
        } else {
            $stmt->bind_param('ii', $groupId, $siteId);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /**
     * True iff an ACTIVE membership row exists — the canonical "in the
     * group" test (#304/#321 contract; status='active' only).
     */
    public static function isMember(int $siteId, int $groupId, int $userId): bool
    {
        $db = self::db();
        $stmt = $db->prepare(
            "SELECT 1 FROM tblSmallGroupMembers WHERE groupID = ? AND siteID = ? AND userID = ? AND status = 'active' LIMIT 1"
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('iii', $groupId, $siteId, $userId);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_assoc() !== null;
        $stmt->close();
        return $found;
    }

    /**
     * True iff an ACTIVE membership with memberRole leader OR co-leader —
     * the #321 "group leader gate".
     */
    public static function isLeader(int $siteId, int $groupId, int $userId): bool
    {
        $db = self::db();
        $stmt = $db->prepare(
            "SELECT 1 FROM tblSmallGroupMembers WHERE groupID = ? AND siteID = ? AND userID = ? "
            . "AND status = 'active' AND memberRole IN ('leader','co-leader') LIMIT 1"
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('iii', $groupId, $siteId, $userId);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_assoc() !== null;
        $stmt->close();
        return $found;
    }

    /**
     * May this person manage THIS group? An administrator, or whoever this
     * organisation has pointed "Manage every small group" at, or one of this
     * group's own leaders. $user defaults to App::user().
     *
     * 🚪 #739: the middle ground was `App::hasRole('groups_coordinator')`,
     *    the role key written into this file. It is now the `groups.manage`
     *    capability, which an organisation may point at roles of its own on
     *    the Roles page. It SHIPS pointing at `groups_coordinator` and falls
     *    open for a global administrator, so on an installation that has
     *    never opened that page this answers exactly what it answered before.
     *
     *    The THIRD ground is untouched and is why pointing the capability at
     *    nobody is recoverable without anybody editing PHP: `isLeader()`
     *    reads this group's own membership rows and asks no role at all, so a
     *    group's leaders keep the running of their own group whatever the
     *    capability says.
     *
     * @param array<string,mixed>|null $user
     */
    public static function canManage(int $siteId, int $groupId, ?array $user = null): bool
    {
        if (App::isAdmin() === true || App::can(Capabilities::GROUPS_MANAGE) === true) {
            return true;
        }
        $user = $user ?? App::user();
        $userId = (int) ($user['userID'] ?? 0);
        if ($userId <= 0) {
            return false;
        }
        return self::isLeader($siteId, $groupId, $userId);
    }

    // -------------------------------------------------------------------------
    // ✍️ Writes
    // -------------------------------------------------------------------------

    /**
     * THE membership write choke-point. Verifies (1) the group belongs to
     * $siteId, (2) $userId holds an ACTIVE tblUserSites row for $siteId
     * (leadership user-picker join), (3) capacity not exceeded when
     * activating a NOT-already-active membership. Denormalises siteID from
     * the GROUP row (never from a caller-supplied value). UNIQUE-key
     * upsert: INSERT … ON DUPLICATE KEY UPDATE memberRole/status/joinedAt/
     * endedAt — joinedAt is stamped only on the active-status TRANSITION
     * (an already-active row's original join date survives a plain role
     * change); endedAt is stamped on transition to 'ended' and cleared on
     * transition back to 'active'. Returns false (never throws) on any
     * refusal. $status: 'active'|'pending'|'ended'. $addedById null =
     * self-service (join request / self-leave).
     */
    public static function upsertMembership(
        int $siteId,
        int $groupId,
        int $userId,
        string $role,
        string $status,
        ?int $addedById,
        ?string $requestNote = null
    ): bool {
        if ($userId <= 0) {
            return false;
        }
        $role = isset(self::VALID_ROLES[$role]) === true ? $role : 'member';
        if (isset(self::VALID_STATUSES[$status]) === false) {
            return false;
        }

        // 🚪 The group must belong to THIS site — a foreign groupID is
        // indistinguishable from a missing one.
        $group = self::getGroup($siteId, $groupId);
        if ($group === null) {
            return false;
        }
        $groupSiteId = (int) $group['siteID'];

        $db = self::db();

        // 🛡️ Cross-site membership structurally impossible — the target
        // user MUST hold an ACTIVE tblUserSites row for the group's own
        // site (leadership user-picker join precedent).
        $chk = $db->prepare('SELECT 1 FROM tblUserSites WHERE userID = ? AND siteID = ? AND isActive = 1 LIMIT 1');
        if ($chk === false) {
            return false;
        }
        $chk->bind_param('ii', $userId, $groupSiteId);
        $chk->execute();
        $hasSite = $chk->get_result()->fetch_assoc() !== null;
        $chk->close();
        if ($hasSite === false) {
            return false;
        }

        // 🚦 Capacity gate — only enforced when ACTIVATING a membership
        // that is not already active (role changes / re-saves on an
        // already-active row never trip this).
        if ($status === 'active' && $group['capacity'] !== null) {
            $already = self::isMember($groupSiteId, $groupId, $userId);
            if ($already === false) {
                $countStmt = $db->prepare(
                    "SELECT COUNT(*) AS c FROM tblSmallGroupMembers WHERE groupID = ? AND status = 'active'"
                );
                if ($countStmt !== false) {
                    $countStmt->bind_param('i', $groupId);
                    $countStmt->execute();
                    $cnt = (int) ($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
                    $countStmt->close();
                    if ($cnt >= (int) $group['capacity']) {
                        return false;
                    }
                }
            }
        }

        $joinedAt = $status === 'active' ? date('Y-m-d H:i:s') : null;
        $endedAt  = $status === 'ended' ? date('Y-m-d H:i:s') : null;

        $sql = 'INSERT INTO tblSmallGroupMembers '
            . '(siteID, groupID, userID, memberRole, status, joinedAt, endedAt, requestNote, addedByID) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'memberRole = VALUES(memberRole), '
            . 'status = VALUES(status), '
            . "joinedAt = IF(VALUES(status) = 'active' AND status <> 'active', NOW(), joinedAt), "
            . "endedAt = IF(VALUES(status) = 'ended', NOW(), IF(VALUES(status) = 'active', NULL, endedAt)), "
            . 'requestNote = VALUES(requestNote), '
            . 'addedByID = COALESCE(VALUES(addedByID), addedByID)';

        try {
            $stmt = $db->prepare($sql);
            if ($stmt === false) {
                return false;
            }
            $stmt->bind_param(
                'iiisssssi',
                $groupSiteId,
                $groupId,
                $userId,
                $role,
                $status,
                $joinedAt,
                $endedAt,
                $requestNote,
                $addedById
            );
            $stmt->execute();
            $stmt->close();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 🗑️ The SET clause that empties a listed home and takes it off the list.
     * THE ONE COPY: `small-groups/place-save.php`'s Remove and
     * `releaseHomesOf()` below both write it, so "removed by a leader" and
     * "removed because its host left" leave the row in the same state.
     *
     * Everything that says where the home is, who typed it and who agreed goes;
     * the row stays, switched off, so a past meeting held there still points at
     * something and reads "a place no longer recorded" (D-126: removing deletes
     * the address at once).
     */
    public const EMPTIED_HOME_SET = '`placeLabel` = NULL, `addressLine1` = NULL, `addressLine2` = NULL, '
        . '`city` = NULL, `region` = NULL, `postcode` = NULL, `countryCode` = NULL, `latitude` = NULL, '
        . '`longitude` = NULL, `what3words` = NULL, `placeEnteredByID` = NULL, `hostUserID` = NULL, '
        . '`placeMapAgreed` = 0, `notAHome` = 0, `isActive` = 0';

    /**
     * 🗑️ The SET clause that empties a group's USUAL meeting place when it was
     * the departing host's home. Every part of the place goes except the
     * country, exactly what a leader's "clear every part … and save" leaves
     * (`placeIsEmpty()` does not count the country, and the usual place's
     * country column is NOT NULL, `''` meaning none). The group itself stays.
     */
    private const EMPTIED_USUAL_PLACE_SET = '`addressLine1` = NULL, `addressLine2` = NULL, `city` = NULL, '
        . '`region` = NULL, `postcode` = NULL, `latitude` = NULL, `longitude` = NULL, `what3words` = NULL, '
        . '`placeEnteredByID` = NULL, `hostUserID` = NULL, `placeMapAgreed` = 0, `notAHome` = 0';

    /**
     * 🚪 THE ONE PLACE a membership of a small group ENDS (#952; the owner,
     * 12 November 2026 — D-168). A member leaving on their own
     * (`small-groups/join.php`, "leave") and a leader removing them
     * (`small-groups/member-save.php`, "remove") both come here.
     *
     * D-168: when a host leaves, their home STOPS BEING the group's meeting
     * place straight away, and the group's leaders are told to choose a new
     * place; it rules OUT keeping a former host's home until somebody removes
     * it. Before #952 the membership row went to `ended` and nothing else
     * changed: the home stayed on the list with its address, and the person
     * whose home it was could no longer remove it, because D-125 lets only an
     * ACTIVE member change their own home.
     *
     * ONE TRANSACTION, and the order is the design. It is opened FIRST and the
     * membership row is then read `FOR UPDATE` (#697: a lock outside a
     * transaction is decoration); the row is ended, and only then are the
     * person's homes in THIS group emptied, in the same transaction. A member
     * adding their own home at the same moment locks the same membership row
     * inside place-save's transaction (`hostStillBelongs()`), so either the
     * home is added first and emptied here, or this ends the membership first
     * and the add is refused: never a home left behind for somebody who has
     * gone.
     *
     * `$onlyFor`: when the member is leaving on their own, their own account
     * number, so the row must be theirs; null for a leader acting on the group.
     *
     * The leaders are told AFTER the commit, by `tellLeadersHomesReleased()`,
     * which never throws — a broken mail route must never undo a departure.
     *
     * @return array{ended:bool, userID:int, released:list<array{siteID:int, groupID:int, homes:int, usualPlace:bool, plannedMeetings:int}>}
     */
    public static function endMembership(\mysqli $db, int $siteId, int $groupId, int $membershipId, ?int $onlyFor = null): array
    {
        $none = ['ended' => false, 'userID' => 0, 'released' => []];
        if ($membershipId <= 0) {
            return $none;
        }

        $db->begin_transaction();
        try {
            $lock = $db->prepare(
                'SELECT `userID`, `status` FROM `tblSmallGroupMembers` '
                . 'WHERE `membershipID` = ? AND `groupID` = ? AND `siteID` = ?'
                . ($onlyFor !== null ? ' AND `userID` = ?' : '')
                . ' FOR UPDATE'
            );
            if ($onlyFor !== null) {
                $lock->bind_param('iiii', $membershipId, $groupId, $siteId, $onlyFor);
            } else {
                $lock->bind_param('iii', $membershipId, $groupId, $siteId);
            }
            $lock->execute();
            $row = $lock->get_result()->fetch_assoc();
            $lock->close();
            // Already ended (a second press, a stale page, another leader
            // first): nothing changes, and "ended" means THIS call ended it.
            if ($row === null || (string) $row['status'] === 'ended') {
                $db->rollback();

                return $none;
            }
            $userId    = (int) $row['userID'];
            $wasActive = (string) $row['status'] === 'active';

            $end = $db->prepare(
                "UPDATE `tblSmallGroupMembers` SET `status` = 'ended', `endedAt` = NOW() "
                . 'WHERE `membershipID` = ? AND `groupID` = ? AND `siteID` = ?'
            );
            $end->bind_param('iii', $membershipId, $groupId, $siteId);
            $end->execute();
            $ended = $end->affected_rows > 0;
            $end->close();

            // Only somebody who WAS an active member can be a host (a host is
            // named only from active members, `hostFromPost()`), so a pending
            // request being withdrawn releases nothing.
            $released = ($ended === true && $wasActive === true) ? self::releaseHomesOf($db, $userId, $siteId, $groupId) : [];
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        return ['ended' => $ended, 'userID' => $userId, 'released' => $released];
    }

    /**
     * 🏠 Empty every meeting place whose named host is `$userId` (D-168): every
     * listed home they host, and the group's usual place when it is theirs.
     * Scoped to one group (`$siteId` and `$groupId`), or — for offboarding,
     * which ends every membership the person has everywhere — to every group
     * in every organisation (both null).
     *
     * RUNS INSIDE THE CALLER'S TRANSACTION, and the caller must already have
     * ended the membership in it; this opens none of its own, so it can be one
     * step of offboarding's single transaction. The rows are read `FOR UPDATE`
     * before they are changed, so what it answers is exactly what it emptied.
     *
     * A FAILURE PART-WAY (round 1 of #952's check) throws, and the caller
     * decides what survives: `endMembership()` rolls its whole transaction
     * back, so the membership does not end either and the request fails with
     * nothing changed; offboarding wraps this in a SAVEPOINT and rolls back
     * to it, so the step is all-or-nothing while the rest of the offboarding
     * goes on, the step is recorded as failed, and no leader is told
     * (section F of tools/series-suggestion-host-selftest.php).
     *
     * The PLANNED meetings it counts (dated today or later on the organisation's
     * clock, `todayFor()`) are the ones that were to be held at the emptied
     * place: a meeting pointing at a removed home now reads "a place no longer
     * recorded", and one at the usual place reads the emptied place. They are
     * left as they are — choosing where to meet instead is the leaders'
     * decision, which is why D-168 has them told.
     *
     * @return list<array{siteID:int, groupID:int, homes:int, usualPlace:bool, plannedMeetings:int}>
     */
    public static function releaseHomesOf(\mysqli $db, int $userId, ?int $siteId = null, ?int $groupId = null): array
    {
        if ($userId <= 0) {
            return [];
        }
        $scope      = $groupId !== null && $siteId !== null ? ' AND `groupID` = ? AND `siteID` = ?' : '';
        $bindScope  = static function (\mysqli_stmt $stmt, int $userId) use ($scope, $siteId, $groupId): void {
            if ($scope !== '') {
                $stmt->bind_param('iii', $userId, $groupId, $siteId);
            } else {
                $stmt->bind_param('i', $userId);
            }
        };
        /** @var array<string, array{siteID:int, groupID:int, homes:int, usualPlace:bool, plannedMeetings:int, placeIDs:list<int>}> $byGroup */
        $byGroup = [];
        $entry   = static function (int $site, int $group) use (&$byGroup): string {
            $key = $site . ':' . $group;
            $byGroup[$key] ??= ['siteID' => $site, 'groupID' => $group, 'homes' => 0, 'usualPlace' => false, 'plannedMeetings' => 0, 'placeIDs' => []];

            return $key;
        };

        // 1. The listed homes they host.
        $homes = $db->prepare(
            'SELECT `placeID`, `siteID`, `groupID` FROM `tblSmallGroupPlaces` '
            . 'WHERE `hostUserID` = ? AND `isActive` = 1' . $scope . ' FOR UPDATE'
        );
        $bindScope($homes, $userId);
        $homes->execute();
        foreach ($homes->get_result()->fetch_all(MYSQLI_ASSOC) as $home) {
            $key = $entry((int) $home['siteID'], (int) $home['groupID']);
            $byGroup[$key]['homes']++;
            $byGroup[$key]['placeIDs'][] = (int) $home['placeID'];
        }
        $homes->close();
        if ($byGroup !== []) {
            $emptyHomes = $db->prepare(
                'UPDATE `tblSmallGroupPlaces` SET ' . self::EMPTIED_HOME_SET
                . ' WHERE `hostUserID` = ? AND `isActive` = 1' . $scope
            );
            $bindScope($emptyHomes, $userId);
            $emptyHomes->execute();
            $emptyHomes->close();
        }

        // 2. The group's usual place, when it is their home.
        $usual = $db->prepare(
            'SELECT `siteID`, `groupID` FROM `tblSmallGroups` WHERE `hostUserID` = ?' . $scope . ' FOR UPDATE'
        );
        $bindScope($usual, $userId);
        $usual->execute();
        $usualRows = $usual->get_result()->fetch_all(MYSQLI_ASSOC);
        $usual->close();
        foreach ($usualRows as $group) {
            $byGroup[$entry((int) $group['siteID'], (int) $group['groupID'])]['usualPlace'] = true;
        }
        if ($usualRows !== []) {
            $emptyUsual = $db->prepare(
                'UPDATE `tblSmallGroups` SET ' . self::EMPTIED_USUAL_PLACE_SET . ' WHERE `hostUserID` = ?' . $scope
            );
            $bindScope($emptyUsual, $userId);
            $emptyUsual->execute();
            $emptyUsual->close();
        }

        // 3. The planned meetings that were to be held at those places.
        foreach ($byGroup as $key => $group) {
            $today = self::todayFor($group['siteID']);
            $where = [];
            if ($group['placeIDs'] !== []) {
                $where[] = '`placeID` IN (' . implode(',', array_map('intval', $group['placeIDs'])) . ')';
            }
            if ($group['usualPlace'] === true) {
                $where[] = '`placeID` IS NULL';
            }
            $count = $db->prepare(
                'SELECT COUNT(*) AS `n` FROM `tblSmallGroupMeetings` WHERE `groupID` = ? AND `siteID` = ? '
                . 'AND `meetingDate` >= ? AND (' . implode(' OR ', $where) . ')'
            );
            $count->bind_param('iis', $group['groupID'], $group['siteID'], $today);
            $count->execute();
            $byGroup[$key]['plannedMeetings'] = (int) ($count->get_result()->fetch_assoc()['n'] ?? 0);
            $count->close();
            unset($byGroup[$key]['placeIDs']);
        }

        return array_values($byGroup);
    }

    /**
     * ✉️ Tell each affected group's leaders that a departing host's home is no
     * longer a meeting place, and to choose a new one (D-168). Called AFTER the
     * commit that released the homes; NEVER THROWS, so a mail route that is
     * broken or not set up never undoes or blocks a departure.
     *
     * Who: every ACTIVE leader and co-leader of the group whose account is
     * active, except the person who left. The message names the person who
     * left (the leaders already see them on the group's member list) and NEVER
     * the address, which has just been deleted (D-126) and must not survive in
     * a mailbox. Plain text (`bodyIsText`), because a group's name is typed by
     * a person (#859), and `siteId` so the footer and the link are the group's
     * own organisation's (#683).
     *
     * @param list<array{siteID:int, groupID:int, homes:int, usualPlace:bool, plannedMeetings:int}> $released
     *
     * @param ?array $outcome Filled with each group's NAME, sorted by what
     *                        became of its leaders, so a caller covering
     *                        several groups (offboarding) can say exactly that
     *                        (#952, rounds 2 and 3 of the check): `told` (a mail
     *                        service took at least one message), `noLeader`
     *                        (no other current leader to write to — the person
     *                        who left may have been the only one), `notSent`
     *                        (there were leaders, but nothing could be sent, or
     *                        they could not be read).
     *
     * @return int How many messages a mail service took or kept (for the caller's words).
     */
    public static function tellLeadersHomesReleased(\mysqli $db, array $released, int $hostId, ?array &$outcome = null): int
    {
        $told    = 0;
        $outcome = ['told' => [], 'noLeader' => [], 'notSent' => []];
        foreach ($released as $group) {
            $toldHere  = 0;
            $groupName = '';
            try {
                $info = $db->prepare('SELECT `groupName` FROM `tblSmallGroups` WHERE `groupID` = ? AND `siteID` = ? LIMIT 1');
                $info->bind_param('ii', $group['groupID'], $group['siteID']);
                $info->execute();
                $groupName = (string) ($info->get_result()->fetch_assoc()['groupName'] ?? '');
                $info->close();

                $leaders = $db->prepare(
                    'SELECT u.`emailAddress` FROM `tblSmallGroupMembers` m '
                    . 'INNER JOIN `tblUsers` u ON u.`userID` = m.`userID` '
                    . "WHERE m.`groupID` = ? AND m.`siteID` = ? AND m.`status` = 'active' "
                    . "AND m.`memberRole` IN ('leader','co-leader') AND u.`isActive` = 1 AND m.`userID` <> ?"
                );
                $leaders->bind_param('iii', $group['groupID'], $group['siteID'], $hostId);
                $leaders->execute();
                $addresses = array_column($leaders->get_result()->fetch_all(MYSQLI_ASSOC), 'emailAddress');
                $leaders->close();

                $words = self::homesReleasedMessage(
                    $groupName,
                    self::personName($hostId),
                    $group,
                    PortalAddress::absolute('small-groups/group?id=' . $group['groupID'], $group['siteID'])
                );
            } catch (\Throwable $e) {
                Logger::errorPlatform('SmallGroups', 'Warning', 'SG_HOST_LEFT_READ', 'The leaders of a group whose host left could not be read, so they were not told', '');
                $outcome['notSent'][] = $groupName !== '' ? $groupName : 'a small group';
                continue;
            }
            $addresses = array_values(array_filter(array_map(static fn ($a): string => trim((string) $a), $addresses),
                static fn (string $a): bool => $a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL) !== false));
            $label = $groupName !== '' ? $groupName : 'a small group';
            if ($addresses === []) {
                $outcome['noLeader'][] = $label;
                continue;
            }
            foreach ($addresses as $address) {
                try {
                    if (Mailer::send($address, $words['subject'], $words['body'], [], ['siteId' => $group['siteID'], 'bodyIsText' => true]) === true) {
                        $told++;
                        $toldHere++;
                    }
                } catch (\Throwable $e) {
                    // The fault's own words are never recorded: a mail server's
                    // refusal can quote the recipient's address.
                    Logger::errorPlatform('Mailer', 'Warning', 'SG_HOST_LEFT_MAIL', 'A leader could not be told that a host left their group', '');
                }
            }
            $outcome[$toldHere > 0 ? 'told' : 'notSent'][] = $label;
        }

        return $told;
    }

    /**
     * 🗣️ What offboarding's page says about the homes it took off small groups,
     * and the ONE place it is worded (#952, round 3 of the check). Each group is
     * NAMED under what became of its leaders, so an administrator knows whom to
     * contact, and a group whose only leader was the person who left is told
     * apart from one whose leaders simply could not be e-mailed — there are no
     * leaders to "tell" in the first case. '' when no home was taken off.
     *
     * @param array{told:list<string>, noLeader:list<string>, notSent:list<string>} $outcome From tellLeadersHomesReleased().
     */
    public static function offboardingHomesWords(int $groupCount, array $outcome): string
    {
        if ($groupCount <= 0) {
            return '';
        }
        $names = static function (array $list): string {
            if (count($list) <= 1) {
                return implode('', $list);
            }
            $last = array_pop($list);

            return implode(', ', $list) . ' and ' . $last;
        };
        $words = ' Their home was taken off ' . ($groupCount === 1 ? '1 small group' : $groupCount . ' small groups') . ' as a meeting place.';
        if ($outcome['told'] !== []) {
            $words .= ' The leaders of ' . $names($outcome['told']) . ' are being e-mailed to choose a new place.';
        }
        if ($outcome['notSent'] !== []) {
            $words .= ' The leaders of ' . $names($outcome['notSent']) . ' could not be e-mailed, so please tell them to choose a new place.';
        }
        if ($outcome['noLeader'] !== []) {
            $one    = count($outcome['noLeader']) === 1;
            $words .= ' ' . $names($outcome['noLeader']) . ($one === true ? ' has' : ' have') . ' no other current leader to tell, '
                . 'so please choose a new place for ' . ($one === true ? 'it' : 'them') . ' or name a new leader.';
        }

        return $words;
    }

    /**
     * ✍️ The leaders' message, and the ONE place it is worded (D-168). Plain
     * text. `$link` is the group's page, or '' when the portal has no address
     * to give (`PortalAddress::absolute()`), in which case no link is written.
     *
     * @param array{homes:int, usualPlace:bool, plannedMeetings:int} $released
     *
     * @return array{subject:string, body:string}
     */
    public static function homesReleasedMessage(string $groupName, string $hostName, array $released, string $link): array
    {
        $who   = $hostName !== '' ? $hostName : 'A member';
        $group = $groupName !== '' ? $groupName : 'your small group';
        $what  = [];
        if ($released['usualPlace'] === true) {
            $what[] = 'the group\'s usual meeting place';
        }
        if ($released['homes'] > 0) {
            $what[] = $released['homes'] === 1 ? 'one of the homes on the group\'s list' : $released['homes'] . ' of the homes on the group\'s list';
        }
        $body = $who . ' has left ' . $group . '. Their home was ' . implode(' and ', $what) . ', so it is no longer '
            . 'a meeting place for the group, and its address has been taken off the group\'s places. (If the portal '
            . 'looked up a map position for that address, it may remember the position until its keeping period ends.)' . "\n\n"
            . 'Please choose a new place for the group to meet.';
        if ($released['plannedMeetings'] > 0) {
            // Worded for BOTH kinds of place: a meeting at the usual place
            // follows whatever usual place is chosen next, while a meeting set
            // to a removed home keeps pointing at it until it is changed.
            $body .= ' ' . ($released['plannedMeetings'] === 1
                ? 'One planned meeting had been set to meet there, so please check where it will now meet.'
                : $released['plannedMeetings'] . ' planned meetings had been set to meet there, so please check where they will now meet.');
        }
        if ($link !== '') {
            $body .= "\n\n" . 'The group\'s page: ' . $link;
        }

        return ['subject' => 'A meeting place of ' . $group . ' needs replacing', 'body' => $body];
    }

    /**
     * 🔒 Is `$userId` still an ACTIVE member of this group, asked with their
     * membership row LOCKED (`FOR UPDATE`) inside the caller's transaction?
     * For a save that is about to name somebody as a home's host: it makes
     * that save and `endMembership()` take turns on the same row, so a home is
     * never stored for somebody whose departure has just emptied their homes
     * (D-168). Outside a transaction the lock would be decoration (#697).
     */
    public static function hostStillBelongs(\mysqli $db, int $siteId, int $groupId, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $stmt = $db->prepare(
            "SELECT 1 FROM `tblSmallGroupMembers` WHERE `groupID` = ? AND `siteID` = ? AND `userID` = ? AND `status` = 'active' FOR UPDATE"
        );
        $stmt->bind_param('iii', $groupId, $siteId, $userId);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_assoc() !== null;
        $stmt->close();

        return $found;
    }

    /**
     * Additive push of one meeting's headcount into the Attendance app.
     * Preconditions (all soft-fail → null): the meeting/group resolve
     * site-scoped; the group has a serviceTypeID; site setting
     * `small-groups.push_attendance` is '1'/'true'. Behaviour: reuse
     * `tblSmallGroupMeetings.attendanceSessionID` if set and that session
     * still exists; else find-or-create the `tblAttendanceSessions` row
     * keyed on (siteID, serviceTypeID, sessionDate) — several groups
     * sharing one serviceTypeID/date share the SAME session, each
     * contributing its own `tblAttendanceCounts` row labelled by
     * groupName; then upsert ONE `tblAttendanceCounts` row matched by
     * (sessionID, groupLabel), headcount = present rows + visitorCount.
     * Stores the resolved sessionID back on the meeting. Never touches any
     * other attendance row; wrapped try/catch (venue-overlay resilience
     * precedent) so an Attendance-side failure can never break the roll
     * save. Returns the sessionID, or null on any soft-fail.
     */
    public static function pushHeadcountToAttendance(int $siteId, int $meetingId): ?int
    {
        try {
            $db = self::db();

            $pushSetting = (string) (App::settingForSite('small-groups.push_attendance', $siteId) ?? '1');
            if ($pushSetting !== '1' && $pushSetting !== 'true') {
                return null;
            }

            $stmt = $db->prepare(
                'SELECT tblSmallGroupMeetings.meetingID, tblSmallGroupMeetings.siteID, tblSmallGroupMeetings.groupID, '
                . 'tblSmallGroupMeetings.meetingDate, tblSmallGroupMeetings.meetingTime, '
                . 'tblSmallGroupMeetings.visitorCount, tblSmallGroupMeetings.attendanceSessionID, '
                . 'g.groupName, g.serviceTypeID '
                . 'FROM tblSmallGroupMeetings '
                . 'INNER JOIN tblSmallGroups g ON g.groupID = tblSmallGroupMeetings.groupID '
                . 'WHERE tblSmallGroupMeetings.meetingID = ? AND tblSmallGroupMeetings.siteID = ? LIMIT 1'
            );
            if ($stmt === false) {
                return null;
            }
            $stmt->bind_param('ii', $meetingId, $siteId);
            $stmt->execute();
            $meeting = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($meeting === null || $meeting['serviceTypeID'] === null) {
                return null;
            }

            $serviceTypeId = (int) $meeting['serviceTypeID'];
            $groupLabel    = (string) $meeting['groupName'];
            $sessionDate   = (string) $meeting['meetingDate'];
            $sessionTime   = $meeting['meetingTime'];

            // 🔢 Headcount = present roll rows + counted (unnamed) visitors.
            $cntStmt = $db->prepare('SELECT COUNT(*) AS c FROM tblSmallGroupMeetingAttendance WHERE meetingID = ?');
            $presentCount = 0;
            if ($cntStmt !== false) {
                $cntStmt->bind_param('i', $meetingId);
                $cntStmt->execute();
                $presentCount = (int) ($cntStmt->get_result()->fetch_assoc()['c'] ?? 0);
                $cntStmt->close();
            }
            $headcount = $presentCount + (int) $meeting['visitorCount'];

            // 🔁 Reuse the previously-pushed session if it still exists.
            $sessionId = null;
            $existingSessionId = $meeting['attendanceSessionID'] !== null ? (int) $meeting['attendanceSessionID'] : null;
            if ($existingSessionId !== null) {
                $chk = $db->prepare(
                    'SELECT sessionID FROM tblAttendanceSessions WHERE sessionID = ? AND siteID = ? AND isDeleted = 0 LIMIT 1'
                );
                if ($chk !== false) {
                    $chk->bind_param('ii', $existingSessionId, $siteId);
                    $chk->execute();
                    $row = $chk->get_result()->fetch_assoc();
                    $chk->close();
                    if ($row !== null) {
                        $sessionId = (int) $row['sessionID'];
                    }
                }
            }

            // 🔎 Otherwise find-or-create by (siteID, serviceTypeID, sessionDate)
            // — several groups of the same service type/date share ONE session.
            if ($sessionId === null) {
                $find = $db->prepare(
                    'SELECT sessionID FROM tblAttendanceSessions '
                    . 'WHERE siteID = ? AND serviceTypeID = ? AND sessionDate = ? AND isDeleted = 0 LIMIT 1'
                );
                if ($find === false) {
                    return null;
                }
                $find->bind_param('iis', $siteId, $serviceTypeId, $sessionDate);
                $find->execute();
                $found = $find->get_result()->fetch_assoc();
                $find->close();

                if ($found !== null) {
                    $sessionId = (int) $found['sessionID'];
                } else {
                    $ins = $db->prepare(
                        'INSERT INTO tblAttendanceSessions (siteID, serviceTypeID, sessionDate, sessionTime, notes) '
                        . 'VALUES (?, ?, ?, ?, ?)'
                    );
                    if ($ins === false) {
                        return null;
                    }
                    $note = 'Small Groups auto-push';
                    $ins->bind_param('iisss', $siteId, $serviceTypeId, $sessionDate, $sessionTime, $note);
                    $ins->execute();
                    $sessionId = (int) $ins->insert_id;
                    $ins->close();
                }
            }

            if ($sessionId === null || $sessionId <= 0) {
                return null;
            }

            // 🔄 Upsert the count row matched by (sessionID, groupLabel) —
            // NOT "own the session" — several groups may share one session.
            $countCheck = $db->prepare(
                'SELECT countID FROM tblAttendanceCounts WHERE sessionID = ? AND groupLabel = ? LIMIT 1'
            );
            if ($countCheck === false) {
                return null;
            }
            $countCheck->bind_param('is', $sessionId, $groupLabel);
            $countCheck->execute();
            $existingCount = $countCheck->get_result()->fetch_assoc();
            $countCheck->close();

            if ($existingCount !== null) {
                $upd = $db->prepare('UPDATE tblAttendanceCounts SET headcount = ? WHERE countID = ?');
                if ($upd !== false) {
                    $countId = (int) $existingCount['countID'];
                    $upd->bind_param('ii', $headcount, $countId);
                    $upd->execute();
                    $upd->close();
                }
            } else {
                $ins2 = $db->prepare(
                    'INSERT INTO tblAttendanceCounts (sessionID, groupLabel, headcount) VALUES (?, ?, ?)'
                );
                if ($ins2 !== false) {
                    $ins2->bind_param('isi', $sessionId, $groupLabel, $headcount);
                    $ins2->execute();
                    $ins2->close();
                }
            }

            // 💾 Store the resolved sessionID back on the meeting.
            $save = $db->prepare('UPDATE tblSmallGroupMeetings SET attendanceSessionID = ? WHERE meetingID = ? AND siteID = ?');
            if ($save !== false) {
                $save->bind_param('iii', $sessionId, $meetingId, $siteId);
                $save->execute();
                $save->close();
            }

            return $sessionId;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Per-group attendance stats for report.php: per-meeting date/topic/
     * present/visitor totals, and per-(active)member presence counts +
     * attendance percentage over the meetings in range.
     *
     * @return array{meetings: array<int,array<string,mixed>>, members: array<int,array<string,mixed>>}
     */
    public static function attendanceStats(int $siteId, int $groupId, string $from, string $to): array
    {
        $db = self::db();

        // 🗓️ #809, D-127: a meeting dated in the future is PLANNED, not held,
        //    and has no roll yet. Counting it would read as a meeting nobody
        //    came to, so the range never runs past today on the organisation's
        //    clock (dates compare as text, both `Y-m-d`).
        $today = self::todayFor($siteId);
        if (strcmp($to, $today) > 0) {
            $to = $today;
        }

        $meetings = [];
        $mStmt = $db->prepare(
            'SELECT tblSmallGroupMeetings.meetingID, tblSmallGroupMeetings.meetingDate, '
            . 'tblSmallGroupMeetings.topic, tblSmallGroupMeetings.visitorCount, '
            . 'COUNT(a.attendanceID) AS presentCount '
            . 'FROM tblSmallGroupMeetings '
            . 'LEFT JOIN tblSmallGroupMeetingAttendance a ON a.meetingID = tblSmallGroupMeetings.meetingID '
            . 'WHERE tblSmallGroupMeetings.groupID = ? AND tblSmallGroupMeetings.siteID = ? '
            . 'AND tblSmallGroupMeetings.meetingDate BETWEEN ? AND ? '
            . 'GROUP BY tblSmallGroupMeetings.meetingID, tblSmallGroupMeetings.meetingDate, '
            . 'tblSmallGroupMeetings.topic, tblSmallGroupMeetings.visitorCount '
            . 'ORDER BY tblSmallGroupMeetings.meetingDate'
        );
        if ($mStmt !== false) {
            $mStmt->bind_param('iiss', $groupId, $siteId, $from, $to);
            $mStmt->execute();
            $result = $mStmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $row['presentCount'] = (int) $row['presentCount'];
                $row['visitorCount'] = (int) $row['visitorCount'];
                $row['total'] = $row['presentCount'] + $row['visitorCount'];
                $meetings[] = $row;
            }
            $mStmt->close();
        }
        $meetingCount = count($meetings);

        $members = [];
        $memStmt = $db->prepare(
            'SELECT u.userID, u.fullName, COUNT(a.attendanceID) AS presentCount '
            . 'FROM tblSmallGroupMembers '
            . 'INNER JOIN tblUsers u ON u.userID = tblSmallGroupMembers.userID '
            . 'LEFT JOIN tblSmallGroupMeetings m ON m.groupID = tblSmallGroupMembers.groupID AND m.meetingDate BETWEEN ? AND ? '
            . 'LEFT JOIN tblSmallGroupMeetingAttendance a ON a.meetingID = m.meetingID AND a.userID = tblSmallGroupMembers.userID '
            . "WHERE tblSmallGroupMembers.groupID = ? AND tblSmallGroupMembers.siteID = ? AND tblSmallGroupMembers.status = 'active' "
            . 'GROUP BY u.userID, u.fullName ORDER BY u.fullName'
        );
        if ($memStmt !== false) {
            $memStmt->bind_param('ssii', $from, $to, $groupId, $siteId);
            $memStmt->execute();
            $result = $memStmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $present = (int) $row['presentCount'];
                $row['presentCount'] = $present;
                $row['meetingsCount'] = $meetingCount;
                $row['percentage'] = $meetingCount > 0 ? (int) round(($present / $meetingCount) * 100) : 0;
                $members[] = $row;
            }
            $memStmt->close();
        }

        return ['meetings' => $meetings, 'members' => $members];
    }

    // -------------------------------------------------------------------------
    // 🏠 Who entered the meeting place, and whether they agreed to a map
    //    (#809, D-120)
    // -------------------------------------------------------------------------

    /** The columns that together are "where the group meets". */
    public const PLACE_COLUMNS = [
        'addressLine1', 'addressLine2', 'city', 'region', 'postcode', 'countryCode',
        'latitude', 'longitude', 'what3words',
    ];

    /**
     * 🏷️ Each part of a place in the words a person reads (#809, check round
     * 5). The route sentences ("clear every part … except the country (…)")
     * are built from THIS list by `placePartsInWords()`, so a column added to
     * PLACE_COLUMNS needs its label added HERE, in the same change, or the
     * sentence would silently leave it out while telling people what "every
     * part" means. The country is not a part: a country alone does not say
     * where anybody meets, so an emptied place may keep one (the group's usual
     * place always has a country; a listed home's may be empty, #935). Two columns may share a label (the position).
     * `outside-services-selftest.php` fails when a place column has no label.
     */
    public const PLACE_PART_LABELS = [
        'addressLine1' => 'the first address line',
        'addressLine2' => 'the second address line',
        'city'         => 'the town',
        'region'       => 'the region',
        'postcode'     => 'the postcode',
        'latitude'     => 'the map position',
        'longitude'    => 'the map position',
        'what3words'   => 'the what3words address',
    ];

    /**
     * Every part of a place except the country, as one phrase: "the first
     * address line, …, the map position and the what3words address". Built
     * from PLACE_COLUMNS in order, through PLACE_PART_LABELS, each label once.
     * A column with no label is named by its column name rather than left out,
     * so a forgotten label shows up on the page instead of vanishing.
     */
    public static function placePartsInWords(): string
    {
        $words = [];
        foreach (self::PLACE_COLUMNS as $column) {
            if ($column === 'countryCode') {
                continue;
            }
            $label = self::PLACE_PART_LABELS[$column] ?? $column;
            if (in_array($label, $words, true) === false) {
                $words[] = $label;
            }
        }
        if (count($words) <= 1) {
            return implode('', $words);
        }
        $last = array_pop($words);

        return implode(', ', $words) . ' and ' . $last;
    }

    /**
     * One place, made comparable: every part trimmed text, an empty part as
     * '', and the two coordinates written to the seven decimal places the
     * columns hold, so `52.2053` typed and `52.2053000` read back are the same
     * place. Country alone is not a place (a country does not say where anybody
     * meets; a listed home's country may even be empty, #935), so it is
     * compared but does not make a place "entered" on its own.
     *
     * @param array<string,mixed> $row Keyed by the column names in PLACE_COLUMNS.
     *
     * @return array<string,string>
     */
    public static function placeOf(array $row): array
    {
        $out = [];
        foreach (self::PLACE_COLUMNS as $column) {
            $value = $row[$column] ?? null;
            if ($value === null || (is_string($value) === true && trim($value) === '')) {
                $out[$column] = '';
                continue;
            }
            if ($column === 'latitude' || $column === 'longitude') {
                $out[$column] = number_format((float) $value, 7, '.', '');
                continue;
            }
            $out[$column] = trim((string) $value);
        }

        return $out;
    }

    /** Whether a place (from placeOf()) holds anything that says where somewhere is. */
    public static function placeIsEmpty(array $place): bool
    {
        foreach ($place as $column => $value) {
            if ($column !== 'countryCode' && $value !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * ✍️ THE RULE for a meeting place after one save: who is its HOST, who
     * typed it in, and whether the host has agreed to a map of it. On FACTS
     * (#647): the handler reads the stored row under a lock and asks this, so
     * the answer is about the place as it really stands at the moment of the
     * write.
     *
     * THE HOST IS A NAMED MEMBER (the owner, D-124, superseding D-120 and D-122
     * in part). A place is its host's home, and only THAT member can agree to a
     * map of it. A leader who types in Ann's address names Ann as the host; the
     * leader cannot tick on Ann's behalf. D-123: a group may take turns in
     * several hosts' homes, so this ONE rule serves both the group's usual place
     * (`tblSmallGroups`) and every home on its list (`tblSmallGroupPlaces`, the
     * same column names), asked separately for each row:
     *
     *   1. the place is now EMPTY                → no host, nobody, not agreed;
     *   2. no host is named                      → not agreed (nobody's
     *      agreement exists to count);
     *   3. the HOST changed, or the PLACE changed → the old agreement is gone:
     *      it was one person's agreement about one address, and must never be
     *      carried to another person or another address;
     *   4. otherwise the stored agreement stands;
     *   5. and ONLY IF the person saving IS the host and the form DREW the box
     *      (#677: ask whether a field ARRIVED), their own tick replaces it.
     *      Anybody else's tick — a leader's, an administrator's — counts for
     *      nothing, whatever they post;
     *  5b. but a host who has only just BEEN NAMED on an existing place starts
     *      not agreed, whoever saves — even when the person saving named
     *      THEMSELVES and ticked (check round 1 of #809, X1). An agreement
     *      belongs to the person who gave it, so it is given on a later save by
     *      the host as stored. Only a brand-new place (`$existing === null`)
     *      may be agreed in the save that creates it, and only by its host.
     *
     * Who may change the host, or mark the place, is a separate question
     * answered BEFORE this rule by `whyHostChangeRefused()`.
     *
     * `placeEnteredByID` keeps who last TYPED the place in (the person saving,
     * when it is new or changed). It decides nothing any more; it is the record
     * of who did it.
     *
     * "NOT ANYBODY'S HOME" (the owner, D-128, superseding D-120 in part). A
     * place that is nobody's home — a church hall, a café — may be MARKED so,
     * and may then show a map with no host. The mark and a host are mutually
     * exclusive (the handler refuses a save asking for both), and:
     *
     *   6. a place WITH a host is never marked: it is somebody's home;
     *   7. a NEW place — or one that held no address before — is marked only if
     *      the box was drawn and ticked;
     *   8. a CHANGED ADDRESS clears the mark, whatever is posted — exactly as
     *      it clears a host's agreement — because a mark about one address must
     *      never carry to another, and a home address ticked by mistake would be
     *      shown. Whoever sets it ticks it again on a later save;
     *   9. otherwise a drawn box decides, and a form that did not draw it keeps
     *      what is stored (#677).
     *
     * Who may draw that box at all is the handler's business: only somebody who
     * may choose a host (D-125) — a member adding their own home is its host.
     *
     * @param ?array<string,mixed> $existing  The stored row, or null for a new one.
     * @param array<string,mixed>  $posted    The place as it will be stored, keyed by PLACE_COLUMNS.
     * @param ?int                 $host      The host it will be stored with (the handler has
     *                                        already checked who may be named), or null for none.
     * @param bool                 $markDrawn  The form drew "not anybody's home" (its marker arrived).
     * @param bool                 $markTicked ...and it was ticked.
     *
     * @return array{enteredBy:?int, host:?int, agreed:int, notAHome:int}
     */
    public static function placeAgreementAfterSave(
        ?array $existing,
        array $posted,
        ?int $host,
        int $saverId,
        bool $boxDrawn,
        bool $ticked,
        bool $markDrawn = false,
        bool $markTicked = false
    ): array {
        $after = self::placeOf($posted);
        if (self::placeIsEmpty($after) === true) {
            return ['enteredBy' => null, 'host' => null, 'agreed' => 0, 'notAHome' => 0];
        }
        $host = $host !== null && $host > 0 ? $host : null;

        $placeChanged = $existing === null || self::placeOf($existing) !== $after;
        $hadHost      = $existing !== null && isset($existing['hostUserID']) === true ? (int) $existing['hostUserID'] : 0;
        // A host "changes" on any EXISTING row whose host is not the one being
        // stored — including a row that had none (5b). A new row has no host to
        // change from.
        $hostChanged  = $existing !== null && (int) ($host ?? 0) !== $hadHost;
        $hadEnterer   = $existing !== null && isset($existing['placeEnteredByID']) === true ? (int) $existing['placeEnteredByID'] : 0;
        $enteredBy    = $placeChanged === true ? ($saverId > 0 ? $saverId : null) : ($hadEnterer > 0 ? $hadEnterer : null);

        if ($host === null) {
            // A place that held NO address before counts as new here: the
            // group's usual place has a row even while it is empty, and typing
            // a hall's address into it is entering it, not "changing" it.
            if ($existing === null || self::placeIsEmpty(self::placeOf($existing)) === true) {
                $notAHome = ($markDrawn === true && $markTicked === true) ? 1 : 0;
            } elseif ($placeChanged === true) {
                $notAHome = 0;
            } elseif ($markDrawn === true) {
                $notAHome = $markTicked === true ? 1 : 0;
            } else {
                $notAHome = (int) ($existing['notAHome'] ?? 0) === 1 ? 1 : 0;
            }

            return ['enteredBy' => $enteredBy, 'host' => null, 'agreed' => 0, 'notAHome' => $notAHome];
        }
        $agreed = ($placeChanged === true || $hostChanged === true) ? 0 : ((int) ($existing['placeMapAgreed'] ?? 0) === 1 ? 1 : 0);
        if ($saverId > 0 && $saverId === $host && $boxDrawn === true && $hostChanged === false) {
            $agreed = $ticked === true ? 1 : 0;
        }

        return ['enteredBy' => $enteredBy, 'host' => $host, 'agreed' => $agreed, 'notAHome' => 0];
    }

    /**
     * 🛡️ Is this save trying to take a place away from its named host? (Check
     * rounds 1 and 2 of #809; D-124 and D-128 "rule out" a host's home shown
     * without that host's agreement, and D-124 rules out an erasure that no
     * longer reaches the host's address.)
     *
     * THE RULE: while a place has a stored named host and the person saving is
     * NOT that host, the save may not change the host (to somebody else, or to
     * nobody) and may not mark the place "not anybody's home" — WHATEVER
     * happens to the address. The host keeps full control of their own home.
     * The ONE way past is to EMPTY the place (the usual place) or REMOVE it
     * (a listed home, which never reaches this method): either deletes the
     * address at once (D-126), so nothing of the old host's home survives, and
     * a new place is then entered afresh.
     *
     * A non-host MAY still edit the address with the host kept (a typo fix);
     * a changed address clears the host's agreement and the mark, as it always
     * has (rules 3 and 8 of `placeAgreementAfterSave()`).
     *
     * WHY THERE IS NO "the address changed, so it is a new place" EXEMPTION.
     * Round 1's version had one, and round 2 of the independent check drove
     * five takeovers through it over HTTP: change the country, the case of one
     * letter or the seventh decimal of the position while dropping or
     * replacing the host, then put the address back and agree, or mark it a
     * hall, on the next save — the identical home mapped with no host, in
     * three saves. "Different" cannot be judged by comparing fields, so the
     * rule does not try: only an EMPTY place counts as starting again.
     *
     * "EMPTY" means `placeIsEmpty()`: every part of the place except the
     * country — both address lines, the town, the region, the postcode, the
     * map position AND the what3words address. A place with only its position,
     * only its postcode or only its what3words left is NOT empty and is
     * refused like any other change (check round 3: clearing the written
     * address while keeping the position, then marking it, put the host's home
     * on a map). Never judge "empty" here on a narrower test.
     *
     * A KNOWN LIMIT, accepted by the owner in D-131: a non-host who empties a
     * place completely and then types the same address in afresh as "not
     * anybody's home". The portal cannot know whose home an address is, so
     * this rule does not stop it. D-131 records this DELIBERATE route (D-128
     * covers only a home ticked by mistake). What D-131 asks instead is a
     * WARNING in the tick's own words, on both forms
     * (`partials/place-map-agreement.php`), and it rules OUT keeping a
     * fingerprint of past addresses (that would contradict deleting an
     * address at once, D-126) and restricting the tick to administrators.
     *
     * The `$markTicked` term: both handlers refuse a host AND the mark
     * together (`$refuseBoth`) before asking this, so through a handler the
     * mark only arrives here with the host changed, which is refused anyway.
     * It is kept so the rule is complete on its own and does not lean on the
     * order of two calls in two files; PA13e pins it on facts.
     *
     * @param ?array<string,mixed> $existing The stored row (locked), or null for a new place.
     * @param array<string,mixed>  $posted   The place as it will be stored, keyed by PLACE_COLUMNS.
     * @param ?int                 $newHost  The host the save would store.
     * @param string               $how      How to start again, in words, for the refusal.
     *
     * @return string '' when allowed, else the plain reason it is refused.
     */
    public static function whyHostChangeRefused(?array $existing, array $posted, ?int $newHost, bool $markTicked, int $saverId, string $how): string
    {
        if ($existing === null) {
            return '';
        }
        $storedHost = (int) ($existing['hostUserID'] ?? 0);
        if ($storedHost <= 0 || $storedHost === $saverId) {
            return '';
        }
        if (self::placeIsEmpty(self::placeOf($posted)) === true) {
            return '';
        }
        if ((int) ($newHost ?? 0) === $storedHost && $markTicked === false) {
            return '';
        }

        return 'This place is the home of the member named as its host. Only they can name a different host, remove '
            . 'themselves as host, or mark it as not anybody\'s home. You can still correct its address, but they will '
            . 'then need to agree to a map again. To meet somewhere else, ' . $how . ', then enter the new place '
            . 'afresh; that deletes this address. Nothing was saved.';
    }

    /**
     * 🚪 May this person add a home to the group's list, or change this one?
     * (The owner, D-125.) Administrators, whoever manages small groups and the
     * group's own leaders (`$canManage`, which is `canManage()`) may add or
     * change ANY home. Any other ACTIVE member of the group may add their OWN
     * home — as its host — and change only a home whose host they are. Nobody
     * else may do either. On facts, so the page and the handler cannot disagree.
     *
     * @param ?array<string,mixed> $place The home being changed, or null when adding one.
     */
    public static function mayEditHome(bool $canManage, bool $isActiveMember, ?array $place, int $userId): bool
    {
        if ($canManage === true) {
            return true;
        }
        if ($isActiveMember === false || $userId <= 0) {
            return false;
        }

        return $place === null || (int) ($place['hostUserID'] ?? 0) === $userId;
    }

    /**
     * 🏠 The host a save will store, from what was posted (D-124, D-125). The
     * ONE place both handlers resolve it.
     *
     *   - somebody who may NOT choose (a member adding or changing their own
     *     home) is always the host, whatever is posted;
     *   - the field not sent at all → the stored host is kept (#677: ask
     *     whether a field ARRIVED, never what it holds);
     *   - an empty choice → nobody named;
     *   - the host already stored → kept, even if they have since left the
     *     group (the form shows them as the current choice, so an untouched
     *     Save must not change it; the map rule refuses them anyway). Since
     *     #952 (D-168) leaving the group empties the leaver's homes in the
     *     same transaction (`endMembership()`, and offboarding through
     *     `releaseHomesOf()`), so this case is reached only by a way out of a
     *     group that does not end the membership row — removing somebody
     *     from the whole organisation is one (`admin/sites/users.php`);
     *   - anybody else → only an ACTIVE member of THIS group. Anything else is
     *     refused (`ok` false), never quietly stored as "nobody".
     *
     * @param array<string,mixed> $post The request's posted fields.
     *
     * @return array{ok:bool, host:?int}
     */
    public static function hostFromPost(int $siteId, int $groupId, ?int $storedHost, bool $canChoose, int $saverId, array $post): array
    {
        if ($canChoose === false) {
            return ['ok' => $saverId > 0, 'host' => $saverId > 0 ? $saverId : null];
        }
        if (array_key_exists('hostUserID', $post) === false) {
            return ['ok' => true, 'host' => $storedHost !== null && $storedHost > 0 ? $storedHost : null];
        }
        $raw = trim((string) (is_string($post['hostUserID']) === true ? $post['hostUserID'] : ''));
        if ($raw === '') {
            return ['ok' => true, 'host' => null];
        }
        if (preg_match('/^[1-9][0-9]{0,9}$/', $raw) !== 1) {
            return ['ok' => false, 'host' => null];
        }
        $chosen = (int) $raw;
        if ($storedHost !== null && $chosen === $storedHost) {
            return ['ok' => true, 'host' => $chosen];
        }

        return self::isActiveMemberOf($siteId, $groupId, $chosen) === true
            ? ['ok' => true, 'host' => $chosen]
            : ['ok' => false, 'host' => null];
    }

    /**
     * Whether a person may be named as a host: an ACTIVE member of THIS group.
     * A home is its host's, and the map rule asks whether the host still
     * belongs to the group, so somebody outside it can never be named.
     */
    public static function isActiveMemberOf(int $siteId, int $groupId, int $userId): bool
    {
        return $userId > 0 && self::isMember($siteId, $groupId, $userId) === true;
    }

    // -------------------------------------------------------------------------
    // 🔁 The homes a group takes turns meeting in (#809, D-123)
    // -------------------------------------------------------------------------

    /**
     * The places a group rotates between, oldest first. Site-scoped, like
     * every read here: another organisation's group answers nothing.
     *
     * Each row carries its OWN named host (`hostUserID`, D-124) and that
     * host's agreement (`placeMapAgreed`), or the "not anybody's home" mark
     * (`notAHome`, D-128); `placeEnteredByID` only records who typed it. Under
     * D-123 each place is its own host's home, so one host's agreement never
     * stands for another's.
     *
     * @return list<array<string,mixed>>
     */
    public static function placesOf(int $siteId, int $groupId, bool $activeOnly = true): array
    {
        $stmt = self::db()->prepare(
            'SELECT * FROM `tblSmallGroupPlaces` WHERE `groupID` = ? AND `siteID` = ?'
            . ($activeOnly === true ? ' AND `isActive` = 1' : '')
            . ' ORDER BY `placeID`'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('ii', $groupId, $siteId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    /**
     * One place of ONE group, or null. A place of another group, or another
     * organisation, is indistinguishable from a missing one.
     *
     * @return array<string,mixed>|null
     */
    public static function getPlace(int $siteId, int $groupId, int $placeId): ?array
    {
        if ($placeId <= 0) {
            return null;
        }
        $stmt = self::db()->prepare(
            'SELECT * FROM `tblSmallGroupPlaces` WHERE `placeID` = ? AND `groupID` = ? AND `siteID` = ? LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('iii', $placeId, $groupId, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row !== null ? $row : null;
    }

    /**
     * 📅 Today's DATE on the organisation's own clock, as `Y-m-d`. A meeting's
     * date is a date on the organisation's calendar, never a moment, so "is it
     * still to come" is a comparison of dates as text with this (#777's rule:
     * never build a moment from a reading). An organisation with no usable
     * zone falls back to UTC rather than to the server's.
     */
    public static function todayFor(int $siteId): string
    {
        $zoneName = Site::timezone($siteId);
        $zone     = Site::isZoneName($zoneName) === true ? new \DateTimeZone($zoneName) : new \DateTimeZone('UTC');

        return substr(I18n::wallClockNow($zone), 0, 10);
    }

    /**
     * 🗓️ The group's NEXT meeting: the earliest one dated today or later on the
     * organisation's clock, or null. A meeting with a date still to come is a
     * PLANNED meeting (#809, D-127): the same row a leader records the roll on
     * once it has happened.
     *
     * @return array<string,mixed>|null
     */
    public static function nextMeeting(int $siteId, int $groupId): ?array
    {
        $today = self::todayFor($siteId);
        $stmt  = self::db()->prepare(
            'SELECT `meetingID`, `meetingDate`, `meetingTime`, `placeID`, `topic` FROM `tblSmallGroupMeetings` '
            . 'WHERE `groupID` = ? AND `siteID` = ? AND `meetingDate` >= ? ORDER BY `meetingDate` LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('iis', $groupId, $siteId, $today);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row !== null ? $row : null;
    }

    /**
     * What a place is called on the pages: its own short name, else the first
     * line of its address and its town, else — for a place that was removed
     * and emptied — a plain statement that it is no longer recorded.
     *
     * @param array<string,mixed> $place
     */
    public static function placeName(array $place): string
    {
        $label = trim((string) ($place['placeLabel'] ?? ''));
        if ($label !== '') {
            return $label;
        }
        $parts = array_values(array_filter([
            trim((string) ($place['addressLine1'] ?? '')),
            trim((string) ($place['city'] ?? '')),
        ], static fn (string $p): bool => $p !== ''));
        if ($parts !== []) {
            return implode(', ', $parts);
        }

        return 'A place no longer recorded';
    }

    /**
     * A person's name, for the meeting-place forms only (so a leader knows
     * whose home a place is and whether they have agreed). '' when there is
     * nobody, or the account cannot be read.
     */
    public static function personName(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }
        $stmt = self::db()->prepare('SELECT `fullName` FROM `tblUsers` WHERE `userID` = ? LIMIT 1');
        if ($stmt === false) {
            return '';
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $name = (string) ($stmt->get_result()->fetch_assoc()['fullName'] ?? '');
        $stmt->close();

        return $name;
    }

    // -------------------------------------------------------------------------
    // internals
    // -------------------------------------------------------------------------

    private static function db(): \mysqli
    {
        return App::db();
    }
}
