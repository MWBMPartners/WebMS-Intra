<?php
// Path: public_html/prayer-requests/moderate.php
/**
 * -----------------------------------------------------------------------------
 * Prayer Requests — Moderation Action Handler 🛡️
 * -----------------------------------------------------------------------------
 * POST endpoint that executes moderator actions on a single request:
 *   • approve                 — pending → active
 *   • archive                 — * → archived
 *   • answer                  — active → answered (optional testimony)
 *   • visibility-leadership   — flip visibility to leadership
 *   • visibility-congregation — flip visibility to congregation
 *
 * @package   Portal\PrayerRequests
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.10.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

// 🛡️ POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /prayer-requests/manage', true, 302);
    exit();
}

Auth::ensureSession();
Auth::requireLogin();

if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

// 🔐 CSRF
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    Logger::activity('PrayerRequestModRejected', 'Invalid CSRF on prayer-requests/moderate');
    header('Location: /prayer-requests/manage', true, 302);
    exit();
}

$siteId    = Site::id();
$user      = App::user();
$modId     = (int) ($user['userID'] ?? 0);
$requestId = (int) ($_POST['requestID'] ?? 0);
$action    = (string) ($_POST['action'] ?? '');
$testimony = trim((string) ($_POST['testimony'] ?? ''));

$redirect      = (string) ($_POST['redirect'] ?? 'manage');
$statusFilter  = (string) ($_POST['status_filter'] ?? '');

if ($requestId <= 0 || $action === '') {
    header('Location: /prayer-requests/manage', true, 302);
    exit();
}

// 🔍 Load the row (siteID-scoped — prevents cross-site tampering)
$stmt = $mysqli->prepare(
    'SELECT requestID, status, visibility FROM tblPrayerRequests '
    . 'WHERE siteID = ? AND requestID = ? LIMIT 1'
);
$row = null;
if ($stmt !== false) {
    $stmt->bind_param('ii', $siteId, $requestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($row === null) {
    header('Location: /prayer-requests/manage', true, 302);
    exit();
}

// 🛠️ Decide the SQL update
$newStatus     = null;
$newVisibility = null;
$setAnswered   = false;
$setTestimony  = null;

switch ($action) {
    case 'approve':
        $newStatus = 'active';
        break;
    case 'archive':
        $newStatus = 'archived';
        break;
    case 'answer':
        $newStatus   = 'answered';
        $setAnswered = true;
        if ((App::settings('prayerRequests.allowTestimony') ?? 'true') === 'true'
            && $testimony !== ''
        ) {
            $setTestimony = mb_substr($testimony, 0, 4000);
        }
        break;
    case 'visibility-leadership':
        $newVisibility = 'leadership';
        break;
    case 'visibility-congregation':
        if ((App::settings('prayerRequests.allowCongregationFeed') ?? 'true') === 'true') {
            $newVisibility = 'congregation';
        } else {
            $newVisibility = 'leadership';
        }
        break;
    default:
        header('Location: /prayer-requests/manage', true, 302);
        exit();
}

// 🛠️ Build dynamic UPDATE
$set    = ['moderatorID = ?', 'moderatedAt = NOW()'];
$types  = 'i';
$params = [$modId];

if ($newStatus !== null) {
    $set[]    = 'status = ?';
    $types   .= 's';
    $params[] = $newStatus;
}
if ($newVisibility !== null) {
    $set[]    = 'visibility = ?';
    $types   .= 's';
    $params[] = $newVisibility;
}
if ($setAnswered === true) {
    $set[] = 'answeredAt = NOW()';
}
if ($setTestimony !== null) {
    $set[]    = 'testimony = ?';
    $types   .= 's';
    $params[] = $setTestimony;
}

$sql = 'UPDATE tblPrayerRequests SET ' . implode(', ', $set)
     . ' WHERE siteID = ? AND requestID = ? LIMIT 1';
$types   .= 'ii';
$params[] = $siteId;
$params[] = $requestId;

$stmt = $mysqli->prepare($sql);
if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'PR_MOD_UPDATE_PREP', $mysqli->error, '');
    header('Location: /prayer-requests/manage', true, 302);
    exit();
}
$stmt->bind_param($types, ...$params);
$ok = $stmt->execute();
$stmt->close();

if ($ok === false) {
    Logger::errorPlatform('MySQL', 'Error', 'PR_MOD_UPDATE_FAIL', $mysqli->error, '');
} else {
    Logger::activity(
        'PrayerRequestModerated',
        'Request #' . $requestId . ' action=' . $action
    );
}

// 🔀 Redirect back to the right place
if ($redirect === 'view') {
    header('Location: /prayer-requests/view?id=' . $requestId, true, 302);
} else {
    $qs = $statusFilter !== '' ? '?status=' . urlencode($statusFilter) : '';
    header('Location: /prayer-requests/manage' . $qs, true, 302);
}
exit();
