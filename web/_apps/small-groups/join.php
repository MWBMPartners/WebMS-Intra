<?php
// Path: _apps/small-groups/join.php
/**
 * -----------------------------------------------------------------------------
 * Small Groups — Self-Service Join / Leave 🚪
 * -----------------------------------------------------------------------------
 * POST-only + CSRF. The actor is ALWAYS the current session user — userID
 * is never read from POST here.
 *
 *   action=join  — requires site `open_enrolment='1'`/'true', group
 *                  `isOpenEnrolment=1`, group active, and no existing
 *                  active/pending row. Creates status='pending' via
 *                  `SmallGroups::upsertMembership()`.
 *   action=leave — own active row -> status='ended'; refused if the actor
 *                  is the group's last active leader (same guard as
 *                  member-save.php).
 *
 * No email notifications in v1 — pending requests surface in-app only
 * (members.php for managers, a badge on mine.php for the requester).
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
use Portal\Core\Settings;
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
$backUrl = '/small-groups/group?id=' . $groupId;

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $backUrl);
    exit();
}

$group = SmallGroups::getGroup($siteId, $groupId);
if ($group === null || $userId <= 0) {
    $_SESSION['flash_msg']  = 'Group not found.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /small-groups');
    exit();
}

$action = (string) ($_POST['action'] ?? '');

if ($action === 'join') {
    $siteOpenEnrolment = (string) Settings::get('small-groups.open_enrolment', '1');
    $siteAllows = $siteOpenEnrolment === '1' || $siteOpenEnrolment === 'true';

    if ($siteAllows === false || (int) $group['isOpenEnrolment'] !== 1 || (int) $group['isActive'] !== 1) {
        $_SESSION['flash_msg']  = 'This group is not open for self-service join requests.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . $backUrl);
        exit();
    }
    if (SmallGroups::isMember($siteId, $groupId, $userId) === true) {
        $_SESSION['flash_msg']  = 'You are already a member of this group.';
        $_SESSION['flash_type'] = 'info';
        header('Location: ' . $backUrl);
        exit();
    }

    $requestNote = trim((string) ($_POST['requestNote'] ?? ''));
    $requestNote = $requestNote !== '' ? mb_substr($requestNote, 0, 500) : null;

    $ok = SmallGroups::upsertMembership($siteId, $groupId, $userId, 'member', 'pending', null, $requestNote);
    $_SESSION['flash_msg']  = $ok === true ? 'Request sent — a group leader will review it.' : 'Could not send your join request.';
    $_SESSION['flash_type'] = $ok === true ? 'success' : 'danger';
    if ($ok === true) {
        Logger::activity('SmallGroupJoinRequested', 'Requested to join group ' . $group['groupName'], $userId);
    }
} elseif ($action === 'leave') {
    $db = App::db();
    $mine = $db->prepare("SELECT membershipID, memberRole FROM tblSmallGroupMembers WHERE groupID = ? AND userID = ? AND status = 'active' LIMIT 1");
    $membershipId = 0;
    $memberRole = 'member';
    if ($mine !== false) {
        $mine->bind_param('ii', $groupId, $userId);
        $mine->execute();
        $row = $mine->get_result()->fetch_assoc();
        $mine->close();
        if ($row !== null) {
            $membershipId = (int) $row['membershipID'];
            $memberRole = (string) $row['memberRole'];
        }
    }

    if ($membershipId === 0) {
        $_SESSION['flash_msg']  = 'You are not an active member of this group.';
        $_SESSION['flash_type'] = 'info';
        header('Location: ' . $backUrl);
        exit();
    }

    // ⚖️ Last-leader guard (member-save.php precedent).
    if (in_array($memberRole, ['leader', 'co-leader'], true) === true) {
        $cnt = $db->prepare(
            "SELECT COUNT(*) AS c FROM tblSmallGroupMembers WHERE groupID = ? AND status = 'active' "
            . "AND memberRole IN ('leader','co-leader') AND membershipID != ?"
        );
        $remaining = 1;
        if ($cnt !== false) {
            $cnt->bind_param('ii', $groupId, $membershipId);
            $cnt->execute();
            $remaining = (int) ($cnt->get_result()->fetch_assoc()['c'] ?? 0);
            $cnt->close();
        }
        if ($remaining === 0) {
            $_SESSION['flash_msg']  = 'You are the last active leader of this group — assign another leader before leaving.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . $backUrl);
            exit();
        }
    }

    $upd = $db->prepare("UPDATE tblSmallGroupMembers SET status = 'ended', endedAt = NOW() WHERE membershipID = ? AND userID = ?");
    $ok = false;
    if ($upd !== false) {
        $upd->bind_param('ii', $membershipId, $userId);
        $upd->execute();
        $ok = $upd->affected_rows > 0;
        $upd->close();
    }
    $_SESSION['flash_msg']  = $ok === true ? 'You have left this group.' : 'Could not leave the group.';
    $_SESSION['flash_type'] = $ok === true ? 'success' : 'danger';
    if ($ok === true) {
        Logger::activity('SmallGroupLeft', 'Left group ' . $group['groupName'], $userId);
    }
} else {
    $_SESSION['flash_msg']  = 'Unknown action.';
    $_SESSION['flash_type'] = 'danger';
}

header('Location: ' . $backUrl);
exit();
