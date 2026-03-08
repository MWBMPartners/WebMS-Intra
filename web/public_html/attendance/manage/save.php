<?php
// Path: public_html/attendance/manage/save.php
/**
 * -----------------------------------------------------------------------------
 * Attendance Tracker — Service Type Save Handler 💾
 * -----------------------------------------------------------------------------
 * Handles create and toggle (activate/deactivate) actions for service types.
 * Admin-only endpoint.
 *
 * @package   Portal\Attendance
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.3.0
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
    header('Location: /attendance/manage');
    exit();
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(403);
    exit('Invalid CSRF token.');
}

$action = $_POST['action'] ?? '';
$userId = $_SESSION['user_id'] ?? null;

// 🌐 Multi-site scope
$siteId = Site::id();

// -----------------------------------------------------------------------------
// ➕ Create service type
// -----------------------------------------------------------------------------
if ($action === 'create') {
    $typeName    = trim($_POST['typeName'] ?? '');
    $parentID    = ((int) ($_POST['parentID'] ?? 0)) > 0 ? (int) $_POST['parentID'] : null;
    $description = trim($_POST['description'] ?? '') !== '' ? trim($_POST['description']) : null;
    $sortOrder   = (int) ($_POST['sortOrder'] ?? 0);

    // 🔍 Validation
    if ($typeName === '') {
        $_SESSION['flash_msg']  = 'Type name is required.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /attendance/manage');
        exit();
    }

    // 🔤 Generate slug from type name
    $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $typeName), '-'));

    // 🔍 Ensure slug is unique
    $originalSlug = $slug;
    $counter = 1;
    while (true) {
        $stmt = $mysqli->prepare('SELECT serviceTypeID FROM tblAttendanceServiceTypes WHERE typeSlug = ? AND siteID = ? LIMIT 1');
        if ($stmt !== false) {
            $stmt->bind_param('si', $slug, $siteId);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($exists === null) {
                break;
            }
        }
        $slug = $originalSlug . '-' . $counter;
        $counter++;
    }

    $stmt = $mysqli->prepare(
        'INSERT INTO tblAttendanceServiceTypes (siteID, parentID, typeName, typeSlug, description, sortOrder) '
        . 'VALUES (?, ?, ?, ?, ?, ?)'
    );
    if ($stmt === false) {
        $_SESSION['flash_msg']  = 'Database error creating service type.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /attendance/manage');
        exit();
    }

    $stmt->bind_param('iisssi', $siteId, $parentID, $typeName, $slug, $description, $sortOrder);
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();

    Logger::activity('AttendanceTypeCreated', 'Created service type: ' . $typeName . ' (ID:' . $newId . ')', $userId);

    $_SESSION['flash_msg']  = 'Service type "' . $typeName . '" created successfully.';
    $_SESSION['flash_type'] = 'success';
    header('Location: /attendance/manage');
    exit();
}

// -----------------------------------------------------------------------------
// 🔄 Toggle active/inactive
// -----------------------------------------------------------------------------
if ($action === 'toggle') {
    $serviceTypeID = (int) ($_POST['serviceTypeID'] ?? 0);
    if ($serviceTypeID <= 0) {
        $_SESSION['flash_msg']  = 'Invalid service type ID.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /attendance/manage');
        exit();
    }

    // 📋 Get current state
    $currentActive = null;
    $typeName = '';
    $stmt = $mysqli->prepare('SELECT isActive, typeName FROM tblAttendanceServiceTypes WHERE serviceTypeID = ? AND siteID = ? LIMIT 1');
    if ($stmt !== false) {
        $stmt->bind_param('ii', $serviceTypeID, $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row !== null) {
            $currentActive = (int) $row['isActive'];
            $typeName = $row['typeName'];
        }
        $stmt->close();
    }

    if ($currentActive === null) {
        $_SESSION['flash_msg']  = 'Service type not found.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /attendance/manage');
        exit();
    }

    $newActive = $currentActive === 1 ? 0 : 1;
    $stmt = $mysqli->prepare('UPDATE tblAttendanceServiceTypes SET isActive = ? WHERE serviceTypeID = ? AND siteID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('iii', $newActive, $serviceTypeID, $siteId);
        $stmt->execute();
        $stmt->close();
    }

    $stateLabel = $newActive === 1 ? 'activated' : 'deactivated';
    Logger::activity('AttendanceTypeToggled', ucfirst($stateLabel) . ' service type: ' . $typeName . ' (ID:' . $serviceTypeID . ')', $userId);

    $_SESSION['flash_msg']  = 'Service type "' . $typeName . '" ' . $stateLabel . '.';
    $_SESSION['flash_type'] = 'success';
    header('Location: /attendance/manage');
    exit();
}

// 🚫 Unknown action
$_SESSION['flash_msg']  = 'Unknown action.';
$_SESSION['flash_type'] = 'warning';
header('Location: /attendance/manage');
exit();
