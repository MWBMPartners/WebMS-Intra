<?php
// Path: _apps/small-groups/member-save.php
/**
 * -----------------------------------------------------------------------------
 * Small Groups — Membership Mutations 🧑‍🤝‍🧑💾
 * -----------------------------------------------------------------------------
 * POST-only + CSRF. Actions: add, approve, decline, remove, change-role,
 * rejoin. Every action fetches the group by (groupID, siteID) FIRST and
 * gates `SmallGroups::canManage()` before doing anything. Mutations that
 * (re)activate/change-role delegate to `SmallGroups::upsertMembership()`
 * (the tenant-safety choke-point); `remove` flips the row to
 * `status='ended'` (history retained); `decline` HARD-deletes a pending
 * request (a declined request must not block a later invite through the
 * UNIQUE(groupID, userID) key the way an `ended` row would need
 * `upsertMembership()`'s re-activation path to clear).
 *
 * A leader may not demote/remove THEMSELVES if doing so would leave the
 * group with zero active leaders/co-leaders.
 *
 * @package   Portal\SmallGroups
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/150
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;
use Portal\Core\SmallGroups;

Auth::ensureSession();
Auth::requireLogin();

if (SmallGroups::isEnabled() === false) {
    Router::renderError(404);
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /small-groups');
    exit();
}

$siteId  = Site::id();
$user    = App::user();
$userId  = (int) ($user['userID'] ?? 0);
$groupId = (int) ($_POST['groupID'] ?? 0);

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /small-groups/members?id=' . $groupId);
    exit();
}

$group = SmallGroups::getGroup($siteId, $groupId);
if ($group === null) {
    $_SESSION['flash_msg']  = 'Group not found.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /small-groups');
    exit();
}
if (SmallGroups::canManage($siteId, $groupId) === false) {
    Router::renderError(403);
    return;
}

$backUrl = '/small-groups/members?id=' . $groupId;
$action  = (string) ($_POST['action'] ?? '');
$db = App::db();

/**
 * ⚖️ Count active leaders/co-leaders for this group, optionally excluding
 * one membershipID (the row about to be demoted/removed).
 */
$countActiveLeaders = static function (int $groupId, ?int $excludeMembershipId = null) use ($db): int {
    $sql = "SELECT COUNT(*) AS c FROM tblSmallGroupMembers WHERE groupID = ? AND status = 'active' AND memberRole IN ('leader','co-leader')";
    if ($excludeMembershipId !== null) {
        $sql .= ' AND membershipID != ?';
    }
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        return 1; // fail safe — never allow the guard to be bypassed on a prepare error.
    }
    if ($excludeMembershipId !== null) {
        $stmt->bind_param('ii', $groupId, $excludeMembershipId);
    } else {
        $stmt->bind_param('i', $groupId);
    }
    $stmt->execute();
    $c = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    return $c;
};

/** Fetch one membership row (site-scoped via its group) by membershipID. */
$fetchMembership = static function (int $membershipId, int $groupId) use ($db): ?array {
    $stmt = $db->prepare('SELECT * FROM tblSmallGroupMembers WHERE membershipID = ? AND groupID = ? LIMIT 1');
    if ($stmt === false) {
        return null;
    }
    $stmt->bind_param('ii', $membershipId, $groupId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row !== null ? $row : null;
};

if ($action === 'add') {
    $targetUserId = (int) ($_POST['userID'] ?? 0);
    $role = (string) ($_POST['memberRole'] ?? 'member');
    $ok = SmallGroups::upsertMembership($siteId, $groupId, $targetUserId, $role, 'active', $userId);
    $_SESSION['flash_msg']  = $ok === true ? 'Member added.' : 'Could not add that member — check they belong to this site.';
    $_SESSION['flash_type'] = $ok === true ? 'success' : 'danger';
    if ($ok === true) {
        Logger::activity('SmallGroupMemberChanged', 'Added member to group ' . $group['groupName'], $userId > 0 ? $userId : null);
    }
} elseif ($action === 'approve') {
    $membershipId = (int) ($_POST['membershipID'] ?? 0);
    $m = $fetchMembership($membershipId, $groupId);
    if ($m === null) {
        $_SESSION['flash_msg']  = 'Request not found.';
        $_SESSION['flash_type'] = 'danger';
    } else {
        $ok = SmallGroups::upsertMembership($siteId, $groupId, (int) $m['userID'], (string) $m['memberRole'], 'active', $userId);
        $_SESSION['flash_msg']  = $ok === true ? 'Join request approved.' : 'Could not approve that request.';
        $_SESSION['flash_type'] = $ok === true ? 'success' : 'danger';
        if ($ok === true) {
            Logger::activity('SmallGroupMemberChanged', 'Approved join request for group ' . $group['groupName'], $userId > 0 ? $userId : null);
        }
    }
} elseif ($action === 'decline') {
    $membershipId = (int) ($_POST['membershipID'] ?? 0);
    $m = $fetchMembership($membershipId, $groupId);
    if ($m === null || $m['status'] !== 'pending') {
        $_SESSION['flash_msg']  = 'Request not found.';
        $_SESSION['flash_type'] = 'danger';
    } else {
        // 🗑️ Hard-delete a declined PENDING request — not "ended" — so a
        // later invite/join-request is a fresh row, not blocked by a stale
        // history row under the UNIQUE(groupID, userID) key.
        $del = $db->prepare("DELETE FROM tblSmallGroupMembers WHERE membershipID = ? AND groupID = ? AND status = 'pending'");
        $ok = false;
        if ($del !== false) {
            $del->bind_param('ii', $membershipId, $groupId);
            $del->execute();
            $ok = $del->affected_rows > 0;
            $del->close();
        }
        $_SESSION['flash_msg']  = $ok === true ? 'Request declined.' : 'Could not decline that request.';
        $_SESSION['flash_type'] = $ok === true ? 'success' : 'danger';
        if ($ok === true) {
            Logger::activity('SmallGroupMemberChanged', 'Declined join request for group ' . $group['groupName'], $userId > 0 ? $userId : null);
        }
    }
} elseif ($action === 'remove') {
    $membershipId = (int) ($_POST['membershipID'] ?? 0);
    $m = $fetchMembership($membershipId, $groupId);
    if ($m === null) {
        $_SESSION['flash_msg']  = 'Member not found.';
        $_SESSION['flash_type'] = 'danger';
    } elseif (in_array($m['memberRole'], ['leader', 'co-leader'], true) === true
        && $m['status'] === 'active'
        && $countActiveLeaders($groupId, $membershipId) === 0
    ) {
        $_SESSION['flash_msg']  = 'Cannot remove the last active leader — assign another leader first.';
        $_SESSION['flash_type'] = 'danger';
    } else {
        $upd = $db->prepare("UPDATE tblSmallGroupMembers SET status = 'ended', endedAt = NOW() WHERE membershipID = ? AND groupID = ?");
        $ok = false;
        if ($upd !== false) {
            $upd->bind_param('ii', $membershipId, $groupId);
            $upd->execute();
            $ok = $upd->affected_rows > 0;
            $upd->close();
        }
        $_SESSION['flash_msg']  = $ok === true ? 'Member removed.' : 'Could not remove that member.';
        $_SESSION['flash_type'] = $ok === true ? 'success' : 'danger';
        if ($ok === true) {
            Logger::activity('SmallGroupMemberChanged', 'Removed member from group ' . $group['groupName'], $userId > 0 ? $userId : null);
        }
    }
} elseif ($action === 'change-role') {
    $membershipId = (int) ($_POST['membershipID'] ?? 0);
    $newRole = (string) ($_POST['memberRole'] ?? 'member');
    $m = $fetchMembership($membershipId, $groupId);
    if ($m === null) {
        $_SESSION['flash_msg']  = 'Member not found.';
        $_SESSION['flash_type'] = 'danger';
    } elseif (
        in_array($m['memberRole'], ['leader', 'co-leader'], true) === true
        && in_array($newRole, ['leader', 'co-leader'], true) === false
        && $m['status'] === 'active'
        && $countActiveLeaders($groupId, $membershipId) === 0
    ) {
        $_SESSION['flash_msg']  = 'Cannot demote the last active leader — assign another leader first.';
        $_SESSION['flash_type'] = 'danger';
    } else {
        $ok = SmallGroups::upsertMembership($siteId, $groupId, (int) $m['userID'], $newRole, (string) $m['status'], $userId);
        $_SESSION['flash_msg']  = $ok === true ? 'Role updated.' : 'Could not update that role.';
        $_SESSION['flash_type'] = $ok === true ? 'success' : 'danger';
        if ($ok === true) {
            Logger::activity('SmallGroupMemberChanged', 'Changed member role in group ' . $group['groupName'], $userId > 0 ? $userId : null);
        }
    }
} elseif ($action === 'rejoin') {
    $membershipId = (int) ($_POST['membershipID'] ?? 0);
    $m = $fetchMembership($membershipId, $groupId);
    if ($m === null) {
        $_SESSION['flash_msg']  = 'Member not found.';
        $_SESSION['flash_type'] = 'danger';
    } else {
        $ok = SmallGroups::upsertMembership($siteId, $groupId, (int) $m['userID'], (string) $m['memberRole'], 'active', $userId);
        $_SESSION['flash_msg']  = $ok === true ? 'Member re-added.' : 'Could not re-add that member.';
        $_SESSION['flash_type'] = $ok === true ? 'success' : 'danger';
        if ($ok === true) {
            Logger::activity('SmallGroupMemberChanged', 'Re-added member to group ' . $group['groupName'], $userId > 0 ? $userId : null);
        }
    }
} else {
    $_SESSION['flash_msg']  = 'Unknown action.';
    $_SESSION['flash_type'] = 'danger';
}

header('Location: ' . $backUrl);
exit();
