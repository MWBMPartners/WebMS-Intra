<?php
// Path: public_html/leadership/save.php
/**
 * -----------------------------------------------------------------------------
 * Leadership — Assignment Save Handler 💾
 * -----------------------------------------------------------------------------
 * Handles create and update actions for leadership role assignments.
 * Admin-only endpoint. Validates role existence, person data, and date logic.
 *
 * @package   Portal\Leadership
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.9.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/38
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

// 🛡️ Admin access check
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

// 🛡️ Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /leadership');
    exit();
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(403);
    exit('Invalid CSRF token.');
}

$action = $_POST['action'] ?? '';
$userId = $_SESSION['user_id'] ?? null;
$siteId = Site::id();

// 📋 Collect form data
$roleID     = (int) ($_POST['roleID'] ?? 0);
$personType = $_POST['personType'] ?? 'user';
$assignUser = (int) ($_POST['userID'] ?? 0);
$personName = trim($_POST['personName'] ?? '');
$personEmail = trim($_POST['personEmail'] ?? '');
$startDate  = trim($_POST['startDate'] ?? '');
$endDate    = trim($_POST['endDate'] ?? '');
$notes      = trim($_POST['notes'] ?? '') !== '' ? trim($_POST['notes']) : null;

// 🔍 Validation — role required
if ($roleID <= 0) {
    $_SESSION['flash_msg']  = 'Please select a leadership role.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /leadership/assign');
    exit();
}

// 🔍 Validate role exists and belongs to this site
$roleValid = false;
$roleName  = '';
$stmtRole = $mysqli->prepare(
    'SELECT roleID, roleName FROM tblLeadershipRoles WHERE roleID = ? AND siteID = ? AND isActive = 1 LIMIT 1'
);
if ($stmtRole !== false) {
    $stmtRole->bind_param('ii', $roleID, $siteId);
    $stmtRole->execute();
    $roleRow = $stmtRole->get_result()->fetch_assoc();
    if ($roleRow !== null) {
        $roleValid = true;
        $roleName  = $roleRow['roleName'];
    }
    $stmtRole->close();
}
if ($roleValid === false) {
    $_SESSION['flash_msg']  = 'Invalid leadership role selected.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /leadership/assign');
    exit();
}

// 🔍 Validate person — either a portal user or external name
$assignUserID = null;
$assignName   = null;
$assignEmail  = null;

if ($personType === 'user') {
    if ($assignUser <= 0) {
        $_SESSION['flash_msg']  = 'Please select a portal user.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /leadership/assign?roleID=' . $roleID);
        exit();
    }
    $assignUserID = $assignUser;
} else {
    if ($personName === '') {
        $_SESSION['flash_msg']  = 'Please enter the person\'s name.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /leadership/assign?roleID=' . $roleID);
        exit();
    }
    $assignName  = $personName;
    $assignEmail = $personEmail !== '' ? $personEmail : null;
}

// 🔍 Validate dates (if provided)
$startDateVal = null;
$endDateVal   = null;

if ($startDate !== '') {
    $dateObj = \DateTime::createFromFormat('Y-m-d', $startDate);
    if ($dateObj === false || $dateObj->format('Y-m-d') !== $startDate) {
        $_SESSION['flash_msg']  = 'Invalid start date format.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /leadership/assign?roleID=' . $roleID);
        exit();
    }
    $startDateVal = $startDate;
}

if ($endDate !== '') {
    $dateObj = \DateTime::createFromFormat('Y-m-d', $endDate);
    if ($dateObj === false || $dateObj->format('Y-m-d') !== $endDate) {
        $_SESSION['flash_msg']  = 'Invalid end date format.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /leadership/assign?roleID=' . $roleID);
        exit();
    }
    $endDateVal = $endDate;
}

// 🔍 End date must be after start date (if both provided)
if ($startDateVal !== null && $endDateVal !== null && $endDateVal < $startDateVal) {
    $_SESSION['flash_msg']  = 'End date must be on or after the start date.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /leadership/assign?roleID=' . $roleID);
    exit();
}

// ➕ CREATE
if ($action === 'create') {
    // 🔄 Transition: end current holders if requested
    $endCurrent     = ($_POST['endCurrentHolders'] ?? '') === '1';
    $transitionDate = trim($_POST['transitionDate'] ?? '');
    $transitionedCount = 0;

    if ($endCurrent === true) {
        // 📋 Calculate the end date for outgoing holders (day before transition date)
        $transEndDate = null;
        if ($transitionDate !== '') {
            $transObj = \DateTime::createFromFormat('Y-m-d', $transitionDate);
            if ($transObj !== false && $transObj->format('Y-m-d') === $transitionDate) {
                $transObj->modify('-1 day');
                $transEndDate = $transObj->format('Y-m-d');
            }
        }
        // 📋 Fallback: if no valid transition date, use yesterday
        if ($transEndDate === null) {
            $transEndDate = date('Y-m-d', strtotime('-1 day'));
        }

        // 🔄 End all current active holders for this role
        $stmtEnd = $mysqli->prepare(
            'UPDATE tblLeadershipAssignments '
            . 'SET endDate = ?, updatedByID = ? '
            . 'WHERE roleID = ? AND siteID = ? AND isActive = 1 '
            . 'AND (endDate IS NULL OR endDate >= CURDATE())'
        );
        if ($stmtEnd !== false) {
            $stmtEnd->bind_param('siii', $transEndDate, $userId, $roleID, $siteId);
            $stmtEnd->execute();
            $transitionedCount = $stmtEnd->affected_rows;
            $stmtEnd->close();
        }
    }

    // 📋 Insert the new assignment
    $stmt = $mysqli->prepare(
        'INSERT INTO tblLeadershipAssignments '
        . '(siteID, roleID, userID, personName, personEmail, startDate, endDate, notes, createdByID, updatedByID) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if ($stmt === false) {
        $_SESSION['flash_msg']  = 'Database error.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /leadership/assign?roleID=' . $roleID);
        exit();
    }

    $stmt->bind_param(
        'iiisssssii',
        $siteId, $roleID, $assignUserID, $assignName, $assignEmail,
        $startDateVal, $endDateVal, $notes, $userId, $userId
    );
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();

    $displayName = $assignName ?? 'User #' . $assignUserID;
    $logDetail = 'Assigned ' . $displayName . ' to ' . $roleName . ' (ID:' . $newId . ')';
    if ($transitionedCount > 0) {
        $logDetail .= ' — transitioned ' . $transitionedCount . ' outgoing holder(s)';
    }
    Logger::activity('LeadershipAssigned', $logDetail, $userId);

    $flashMsg = 'Role assignment created successfully.';
    if ($transitionedCount > 0) {
        $flashMsg .= ' ' . $transitionedCount . ' previous holder(s) transitioned out.';
    }
    $_SESSION['flash_msg']  = $flashMsg;
    $_SESSION['flash_type'] = 'success';
    header('Location: /leadership');
    exit();
}

// ✏️ UPDATE
if ($action === 'update') {
    $assignmentID = (int) ($_POST['assignmentID'] ?? 0);
    if ($assignmentID <= 0) {
        $_SESSION['flash_msg']  = 'Invalid assignment ID.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /leadership');
        exit();
    }

    $stmt = $mysqli->prepare(
        'UPDATE tblLeadershipAssignments SET '
        . 'roleID = ?, userID = ?, personName = ?, personEmail = ?, '
        . 'startDate = ?, endDate = ?, notes = ?, updatedByID = ? '
        . 'WHERE assignmentID = ? AND siteID = ?'
    );
    if ($stmt === false) {
        $_SESSION['flash_msg']  = 'Database error.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /leadership');
        exit();
    }

    $stmt->bind_param(
        'iisssssiis',
        $roleID, $assignUserID, $assignName, $assignEmail,
        $startDateVal, $endDateVal, $notes, $userId,
        $assignmentID, $siteId
    );
    $stmt->execute();
    $stmt->close();

    $displayName = $assignName ?? 'User #' . $assignUserID;
    Logger::activity('LeadershipUpdated', 'Updated assignment #' . $assignmentID . ' (' . $displayName . ' as ' . $roleName . ')', $userId);

    $_SESSION['flash_msg']  = 'Assignment updated successfully.';
    $_SESSION['flash_type'] = 'success';
    header('Location: /leadership');
    exit();
}

// 🚫 Unknown action
$_SESSION['flash_msg']  = 'Unknown action.';
$_SESSION['flash_type'] = 'warning';
header('Location: /leadership');
exit();
