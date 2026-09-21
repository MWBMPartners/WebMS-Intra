<?php
// Path: _apps/admin/users/memberships-unplaced-save.php
/**
 * -----------------------------------------------------------------------------
 * Place or discard a parked group, group membership or department
 * membership 🖊️ (#517)
 * -----------------------------------------------------------------------------
 * The write half of `admin/users/memberships-unplaced.php` — see that
 * file's header for how a row lands here. Cloned from the proven
 * `roles-unplaced-save.php` (#516) pattern. Six actions, each in one
 * transaction:
 *   place_group / discard_group             (tblGroupsUnplaced)
 *   place_member / discard_member           (tblUserGroupsUnplaced)
 *   place_dept_member / discard_dept_member (tblUserDeptsUnplaced)
 *
 * GLOBAL ADMINISTRATOR ONLY. No oracle concern in the precise refusal
 * wording below: only a global administrator ever reaches this page.
 *
 * PLACING A GROUP (the owner's answer Q1, 21 September 2026): the group is
 * re-created in the chosen organisation WITH ITS ORIGINAL NUMBER (workflow
 * steps name a group by typing its number), then every parked member who is
 * an ACTIVE member of that organisation is added; anybody else stays parked,
 * with their own Place and Discard buttons, and the message says how many.
 * Nothing is dropped silently.
 *
 * WHAT THIS CANNOT DO: it never creates an organisation membership as a
 * side effect (the #516 Q4 rule) — a person who is not an active member of
 * the organisation stays parked with an explanation. It cannot bring back a
 * parked group's asset ownership (migration 203 did not keep it).
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026 MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/517
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\AccountGuard;
use Portal\Core\ApiAuth;
use Portal\Core\Auth;
use Portal\Core\Departments;
use Portal\Core\Logger;
use Portal\Core\Site;
use Portal\Core\UserGroups;

$backUrl = Site::url('admin/users/memberships-unplaced');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $backUrl, true, 302);
    exit();
}

// 🛡️ Global administrator only — see memberships-unplaced.php for why
//    AccountGuard:: is the spelling used.
if (AccountGuard::actorIsGlobal() === false) {
    http_response_code(403);
    echo t('error.umbrella_admin_only');
    exit();
}

$finish = static function (string $message, string $type) use ($backUrl): void {
    // 🔐 Not pre-escaped: the page escapes every flash message when it
    //    draws it, so escaping here too would show "&amp;" and the like.
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . $backUrl, true, 302);
    exit();
};

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $finish('Invalid or expired form token. Please try again.', 'danger');
}

$action     = (string) ($_POST['action'] ?? '');
$unplacedId = (int) ($_POST['unplacedID'] ?? 0);
$actorId    = ApiAuth::actorUserId();
$allowed    = ['place_group', 'discard_group', 'place_member', 'discard_member', 'place_dept_member', 'discard_dept_member'];

if ($unplacedId <= 0 || in_array($action, $allowed, true) === false) {
    $finish('Choose a valid entry. Nothing was changed.', 'danger');
}

// 🔎 Small helpers, each a single prepared lookup.
$fetchOne = static function (string $sql, string $types, array $params) use ($mysqli): ?array {
    $stmt = $mysqli->prepare($sql);
    if ($stmt === false) {
        return null;
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row;
};
$runOne = static function (string $sql, string $types, array $params) use ($mysqli): int {
    $stmt = $mysqli->prepare($sql);
    if ($stmt === false) {
        throw new \RuntimeException('Failed to prepare: ' . $mysqli->error);
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $affected = (int) $stmt->affected_rows;
    $stmt->close();
    return $affected;
};
// The organisation's name, but only if it exists and is switched on — a
// switched-off organisation is not a real destination for anybody (the
// roles-unplaced-save.php rule).
$activeOrgName = static function (int $siteId) use ($fetchOne): ?string {
    $row = $fetchOne('SELECT siteName FROM tblSites WHERE siteID = ? AND isActive = 1 LIMIT 1', 'i', [$siteId]);
    return $row !== null ? (string) $row['siteName'] : null;
};
$notActiveMember = static function (string $orgName): string {
    return 'That person is not an active member of ' . $orgName
        . '. Add them first (Admin -> Sites -> Users, or Admin -> Users -> No organisation).';
};
$dealtWith = 'That entry has already been dealt with.';
$orgOff    = 'That organisation is switched off or does not exist. Nothing was changed.';

try {
    // =========================================================================
    // 👥 A whole parked group
    // =========================================================================
    if ($action === 'place_group' || $action === 'discard_group') {
        $pen = $fetchOne(
            'SELECT originalGroupID, groupName, description FROM tblGroupsUnplaced WHERE unplacedID = ? LIMIT 1',
            'i',
            [$unplacedId]
        );
        if ($pen === null) {
            $finish($dealtWith, 'danger');
        }
        $originalId = (int) $pen['originalGroupID'];
        $groupName  = $pen['groupName'] !== null ? (string) $pen['groupName'] : null;

        if ($action === 'discard_group') {
            $mysqli->begin_transaction();
            $members = $runOne('DELETE FROM tblUserGroupsUnplaced WHERE groupID = ?', 'i', [$originalId]);
            $runOne('DELETE FROM tblGroupsUnplaced WHERE unplacedID = ?', 'i', [$unplacedId]);
            $mysqli->commit();
            Logger::activity(
                'MembershipUnplacedDiscard',
                'Discarded parked group #' . $originalId . ' and ' . $members . ' parked membership(s) of it',
                $actorId
            );
            $finish('Discarded the group and ' . $members . ' parked membership' . ($members === 1 ? '' : 's') . ' of it.', 'success');
        }

        $siteId  = (int) ($_POST['siteID'] ?? 0);
        $orgName = $activeOrgName($siteId);
        if ($orgName === null) {
            $finish($orgOff, 'danger');
        }

        $mysqli->begin_transaction();

        // ➕ Re-create the group WITH ITS ORIGINAL NUMBER. A plain INSERT
        //    (never IGNORE) so a clash is SEEN: error 1062 means another
        //    group already has that number — only possible if the number
        //    counter was reset, for example by a dump-and-restore. Then the
        //    group is created with a new number, and the message says so.
        //
        //    THE NEW NUMBER IS CHOSEN, NOT LEFT TO THE COUNTER, and the
        //    leftover parked members are moved to it below. The first version
        //    (as #517's plan described it) let the counter pick the number
        //    and left the leftover parked members pointing at the OLD number.
        //    Proven on a real database during the #517 build: after a counter
        //    reset, the old number belonged to a different group in a
        //    different organisation, and the "Place" button put a person
        //    parked from Finance Committee into that other group. The counter
        //    could also hand out a number that another parked group was still
        //    waiting to get back. So the new number is one higher than any
        //    number in use OR waiting in either pen, which nothing else can
        //    claim, and every leftover parked member of this group follows it.
        $newId         = $originalId;
        $numberChanged = false;
        try {
            $runOne(
                'INSERT INTO tblGroups (groupID, siteID, groupName, description, isActive) VALUES (?, ?, ?, ?, 1)',
                'iiss',
                [$originalId, $siteId, $groupName, $pen['description']]
            );
        } catch (\mysqli_sql_exception $e) {
            if ($e->getCode() !== 1062) {
                throw $e;
            }
            $nextResult = $mysqli->query(
                'SELECT GREATEST('
                . '(SELECT COALESCE(MAX(g.groupID), 0) FROM tblGroups g), '
                . '(SELECT COALESCE(MAX(gu.originalGroupID), 0) FROM tblGroupsUnplaced gu), '
                . '(SELECT COALESCE(MAX(p.groupID), 0) FROM tblUsers u JOIN tblUserGroupsUnplaced p ON p.userID = u.userID)'
                . ') + 1 AS nextID'
            );
            $newId = $nextResult !== false ? (int) ($nextResult->fetch_assoc()['nextID'] ?? 0) : 0;
            if ($newId <= 0) {
                throw new \RuntimeException('Could not work out a free group number.');
            }
            $runOne(
                'INSERT INTO tblGroups (groupID, siteID, groupName, description, isActive) VALUES (?, ?, ?, ?, 1)',
                'iiss',
                [$newId, $siteId, $groupName, $pen['description']]
            );
            $numberChanged = true;
        }
        Logger::audit(
            'tblGroups',
            $newId,
            'create',
            null,
            ['siteID' => $siteId, 'groupName' => $groupName, 'description' => $pen['description'], 'placedFromParkedGroup' => $originalId],
            $actorId
        );

        // 👤 Every parked member of that group who is an ACTIVE member of the
        //    organisation is added; anybody else stays parked (owner Q1).
        $added   = 0;
        $skipped = 0;
        $parkedStmt = $mysqli->prepare('SELECT unplacedID, userID FROM tblUserGroupsUnplaced WHERE groupID = ?');
        if ($parkedStmt === false) {
            throw new \RuntimeException('Failed to prepare: ' . $mysqli->error);
        }
        $parkedStmt->bind_param('i', $originalId);
        $parkedStmt->execute();
        $parkedRows = $parkedStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $parkedStmt->close();
        foreach ($parkedRows as $parkedRow) {
            $result = UserGroups::addMember($mysqli, (int) $parkedRow['userID'], $newId, $siteId, $actorId);
            if ($result === 'ok' || $result === 'unchanged') {
                $runOne('DELETE FROM tblUserGroupsUnplaced WHERE unplacedID = ?', 'i', [(int) $parkedRow['unplacedID']]);
                $added++;
            } else {
                $skipped++;
            }
        }

        // 🔀 The number changed: the members still parked (not active
        //    members here) now point at the group's NEW number, so their own
        //    Place button can only ever put them into the group they came
        //    from. See the comment on the INSERT above for why.
        if ($numberChanged === true && $skipped > 0) {
            $runOne('UPDATE tblUserGroupsUnplaced SET groupID = ? WHERE groupID = ?', 'ii', [$newId, $originalId]);
        }

        $runOne('DELETE FROM tblGroupsUnplaced WHERE unplacedID = ?', 'i', [$unplacedId]);
        $mysqli->commit();

        Logger::activity(
            'MembershipUnplacedPlace',
            'Placed parked group #' . $originalId . ' as group #' . $newId . ' in organisation #' . $siteId
                . ' (' . $added . ' member(s) added, ' . $skipped . ' left parked)',
            $actorId
        );

        $message = 'Placed: ' . ($groupName ?? ('group #' . $originalId)) . ' in ' . $orgName . ' with '
            . $added . ' member' . ($added === 1 ? '' : 's') . '.';
        if ($skipped > 0) {
            $message .= ' ' . $skipped . ' member' . ($skipped === 1 ? '' : 's') . ' could not be added because '
                . ($skipped === 1 ? 'they are not an active member' : 'they are not active members') . ' of ' . $orgName
                . ' — they stay listed below.';
        }
        if ($numberChanged === true) {
            $message .= ' The group\'s number changed from #' . $originalId . ' to #' . $newId
                . ', because #' . $originalId . ' is already used by another group. Update any workflow step that names #'
                . $originalId . '.';
        }
        $finish($message, $skipped > 0 || $numberChanged === true ? 'warning' : 'success');
    }

    // =========================================================================
    // 👤 One parked group membership
    // =========================================================================
    if ($action === 'place_member' || $action === 'discard_member') {
        $pen = $fetchOne('SELECT userID, groupID FROM tblUserGroupsUnplaced WHERE unplacedID = ? LIMIT 1', 'i', [$unplacedId]);
        if ($pen === null) {
            $finish($dealtWith, 'danger');
        }
        $userId  = (int) $pen['userID'];
        $groupId = (int) $pen['groupID'];

        if ($action === 'discard_member') {
            $runOne('DELETE FROM tblUserGroupsUnplaced WHERE unplacedID = ?', 'i', [$unplacedId]);
            Logger::activity('MembershipUnplacedDiscard', 'Discarded parked membership of account #' . $userId . ' in group #' . $groupId, $actorId);
            $finish('Discarded.', 'success');
        }

        // The group itself must have been placed first. Checked BEFORE
        // looking in tblGroups, so a member of a still-parked group can
        // never be put into some other group that happens to hold the same
        // number.
        if ($fetchOne('SELECT 1 FROM tblGroupsUnplaced WHERE originalGroupID = ? LIMIT 1', 'i', [$groupId]) !== null) {
            $finish('Place the group itself first.', 'danger');
        }
        $group = $fetchOne('SELECT siteID FROM tblGroups WHERE groupID = ? LIMIT 1', 'i', [$groupId]);
        if ($group === null) {
            $finish('That group no longer exists. Discard this entry instead.', 'danger');
        }
        $siteId  = (int) $group['siteID'];
        $orgName = $activeOrgName($siteId);
        if ($orgName === null) {
            $finish($orgOff, 'danger');
        }

        $mysqli->begin_transaction();
        $result = UserGroups::addMember($mysqli, $userId, $groupId, $siteId, $actorId);
        if ($result === 'not_found' || $result === 'inactive') {
            $mysqli->rollback();
            $finish($result === 'inactive' ? 'That group is retired; reinstate it first.' : $notActiveMember($orgName), 'danger');
        }
        $runOne('DELETE FROM tblUserGroupsUnplaced WHERE unplacedID = ?', 'i', [$unplacedId]);
        $mysqli->commit();
        Logger::activity('MembershipUnplacedPlace', 'Placed parked membership of account #' . $userId . ' in group #' . $groupId . ' in organisation #' . $siteId, $actorId);
        $finish('Placed in ' . $orgName . '.', 'success');
    }

    // =========================================================================
    // 🏢 One parked department membership
    // =========================================================================
    $pen = $fetchOne(
        'SELECT userID, deptID, isDeptLead, isDeptAssistant, isDeptSecretary, isApprover, isMandatoryApprover '
        . 'FROM tblUserDeptsUnplaced WHERE unplacedID = ? LIMIT 1',
        'i',
        [$unplacedId]
    );
    if ($pen === null) {
        $finish($dealtWith, 'danger');
    }
    $userId = (int) $pen['userID'];
    $deptId = (int) $pen['deptID'];

    if ($action === 'discard_dept_member') {
        $runOne('DELETE FROM tblUserDeptsUnplaced WHERE unplacedID = ?', 'i', [$unplacedId]);
        Logger::activity('MembershipUnplacedDiscard', 'Discarded parked membership of account #' . $userId . ' in department #' . $deptId, $actorId);
        $finish('Discarded.', 'success');
    }

    $dept = $fetchOne('SELECT siteID FROM tblDepts WHERE deptID = ? LIMIT 1', 'i', [$deptId]);
    if ($dept === null) {
        $finish('That department no longer exists. Discard this entry instead.', 'danger');
    }
    $siteId  = (int) $dept['siteID'];
    $orgName = $activeOrgName($siteId);
    if ($orgName === null) {
        $finish($orgOff, 'danger');
    }

    $mysqli->begin_transaction();
    $result = Departments::addMember($mysqli, $userId, $deptId, $siteId, $actorId, $pen);
    if ($result === 'not_found' || $result === 'inactive') {
        $mysqli->rollback();
        $finish($result === 'inactive' ? 'That department is retired; reinstate it first.' : $notActiveMember($orgName), 'danger');
    }
    $runOne('DELETE FROM tblUserDeptsUnplaced WHERE unplacedID = ?', 'i', [$unplacedId]);
    $mysqli->commit();
    Logger::activity('MembershipUnplacedPlace', 'Placed parked membership of account #' . $userId . ' in department #' . $deptId . ' in organisation #' . $siteId, $actorId);
    $finish('Placed in ' . $orgName . ', with the flags it had.', 'success');
} catch (\Throwable $e) {
    $mysqli->rollback();
    Logger::errorPlatform('Memberships', 'Error', 'MEMBERSHIP_UNPLACED_SAVE_FAILED', 'Failed to ' . $action . ' parked entry #' . $unplacedId, $e->getMessage());
    $finish(t('error.database'), 'danger');
}
