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
     * One place, made comparable: every part trimmed text, an empty part as
     * '', and the two coordinates written to the seven decimal places the
     * columns hold, so `52.2053` typed and `52.2053000` read back are the same
     * place. Country alone is not a place (it always has a value), so it is
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
     *      nothing, whatever they post.
     *
     * `placeEnteredByID` keeps who last TYPED the place in (the person saving,
     * when it is new or changed). It decides nothing any more; it is the record
     * of who did it.
     *
     * @param ?array<string,mixed> $existing  The stored row, or null for a new one.
     * @param array<string,mixed>  $posted    The place as it will be stored, keyed by PLACE_COLUMNS.
     * @param ?int                 $host      The host it will be stored with (the handler has
     *                                        already checked who may be named), or null for none.
     *
     * @return array{enteredBy:?int, host:?int, agreed:int}
     */
    public static function placeAgreementAfterSave(?array $existing, array $posted, ?int $host, int $saverId, bool $boxDrawn, bool $ticked): array
    {
        $after = self::placeOf($posted);
        if (self::placeIsEmpty($after) === true) {
            return ['enteredBy' => null, 'host' => null, 'agreed' => 0];
        }
        $host = $host !== null && $host > 0 ? $host : null;

        $placeChanged = $existing === null || self::placeOf($existing) !== $after;
        $hadHost      = $existing !== null && isset($existing['hostUserID']) === true ? (int) $existing['hostUserID'] : 0;
        $hostChanged  = (int) ($host ?? 0) !== $hadHost;
        $hadEnterer   = $existing !== null && isset($existing['placeEnteredByID']) === true ? (int) $existing['placeEnteredByID'] : 0;
        $enteredBy    = $placeChanged === true ? ($saverId > 0 ? $saverId : null) : ($hadEnterer > 0 ? $hadEnterer : null);

        if ($host === null) {
            return ['enteredBy' => $enteredBy, 'host' => null, 'agreed' => 0];
        }
        $agreed = ($placeChanged === true || $hostChanged === true) ? 0 : ((int) ($existing['placeMapAgreed'] ?? 0) === 1 ? 1 : 0);
        if ($saverId > 0 && $saverId === $host && $boxDrawn === true) {
            $agreed = $ticked === true ? 1 : 0;
        }

        return ['enteredBy' => $enteredBy, 'host' => $host, 'agreed' => $agreed];
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
     *     Save must not change it; the map rule refuses them anyway);
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
     * Each row carries its OWN host (`placeEnteredByID`) and agreement
     * (`placeMapAgreed`): under D-123 each place is its own host's home, so
     * one host's agreement never stands for another's.
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
