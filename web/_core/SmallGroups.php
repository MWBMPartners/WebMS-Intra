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
        // 🧩 Note on the two correlated subqueries below: neither aliases
        // `tblSmallGroupMembers` as the table immediately after its own
        // FROM — every `tblSmallGroup*` table name contains the bare
        // substring "Group", which trips a backtracking false-positive in
        // check_sql_columns.py's SELECT-column regex whenever a short
        // alias sits directly after `FROM tblSmallGroup*` (it backtracks
        // the table-name match down to "tblSmall" + "Group…", matching
        // "Group" as a false GROUP-BY terminator). Un-aliasing the primary
        // FROM table (or introducing it via JOIN instead, which the
        // checker's FROM-anchored regex never inspects) sidesteps it
        // without changing behaviour — verified against the real script.
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
     * admin || groups_coordinator || isLeader(). $user defaults App::user().
     *
     * @param array<string,mixed>|null $user
     */
    public static function canManage(int $siteId, int $groupId, ?array $user = null): bool
    {
        if (App::isAdmin() === true || App::hasRole('groups_coordinator') === true) {
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
    // internals
    // -------------------------------------------------------------------------

    private static function db(): \mysqli
    {
        return App::db();
    }
}
