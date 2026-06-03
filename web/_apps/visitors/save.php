<?php
// Path: public_html/visitors/save.php
/**
 * Visitor Tracking — POST handler for new visitor + status updates.
 *
 * @package   Portal\Visitors
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/258
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

try {
    if (isset($_POST['updateStatus']) === true) {
        // Status / assignee update from the profile page.
        $id     = (int) ($_POST['visitorID'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'new');
        $assignedToID = (int) ($_POST['assignedToID'] ?? 0);
        if (in_array($status, ['new','in-touch','converted','lost'], true) === false) {
            $status = 'new';
        }
        $assignee = $assignedToID > 0 ? $assignedToID : null;
        $stmt = $db->prepare('UPDATE tblVisitor SET status = ?, assignedToID = ? WHERE visitorID = ? AND siteID = ?');
        if ($stmt !== false) {
            $stmt->bind_param('siii', $status, $assignee, $id, $siteId);
            $stmt->execute();
            $stmt->close();
        }
        header('Location: /visitors/profile?id=' . $id);
        exit();
    }

    // Create new visitor
    $name   = trim((string) ($_POST['fullName'] ?? ''));
    $email  = trim((string) ($_POST['email'] ?? '')) ?: null;
    $phone  = trim((string) ($_POST['phone'] ?? '')) ?: null;
    $source = (string) ($_POST['source'] ?? 'in-person');
    $assignedToID = (int) ($_POST['assignedToID'] ?? 0);
    $notes  = trim((string) ($_POST['notes'] ?? '')) ?: null;
    if ($name === '') {
        header('Location: /visitors/new');
        exit();
    }
    if (in_array($source, ['in-person','public-form','referral','website','other'], true) === false) {
        $source = 'other';
    }
    $assignee = $assignedToID > 0 ? $assignedToID : null;
    $stmt = $db->prepare(
        'INSERT INTO tblVisitor (siteID, fullName, email, phone, source, assignedToID, notes, createdByID) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if ($stmt !== false) {
        $stmt->bind_param('issssisi', $siteId, $name, $email, $phone, $source, $assignee, $notes, $userId);
        $stmt->execute();
        $stmt->close();
    }
} catch (\Throwable $e) {
    \Portal\Core\Logger::errorPlatform('Visitors', 'Warning', 'SAVE', $e->getMessage(), '');
}

header('Location: /visitors');
exit();
