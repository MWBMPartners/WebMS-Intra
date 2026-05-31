<?php
// Path: public_html/visitors/contact-save.php
/**
 * Visitor Tracking — POST handler for recording a contact on a visitor.
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
$id     = (int) ($_POST['visitorID'] ?? 0);
$method = (string) ($_POST['method'] ?? 'call');
$summary = trim((string) ($_POST['summary'] ?? ''));
$next   = trim((string) ($_POST['nextContactAt'] ?? ''));

if (in_array($method, ['visit','call','email','text','other'], true) === false) {
    $method = 'other';
}
$nextDate = ($next !== '' && strtotime($next) !== false) ? $next : null;

// 🛡️ Confirm visitor exists in this site
$stmt = $db->prepare('SELECT 1 FROM tblVisitor WHERE visitorID = ? AND siteID = ?');
if ($stmt !== false) {
    $stmt->bind_param('ii', $id, $siteId);
    $stmt->execute();
    $ok = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    if ($ok === false) {
        http_response_code(404);
        exit('Visitor not found');
    }
}

try {
    $stmt = $db->prepare(
        'INSERT INTO tblVisitorContact (visitorID, contactedByID, method, summary, nextContactAt) VALUES (?, ?, ?, ?, ?)'
    );
    if ($stmt !== false) {
        $stmt->bind_param('iisss', $id, $userId, $method, $summary, $nextDate);
        $stmt->execute();
        $stmt->close();
    }
    // 🪞 Auto-bump status from 'new' to 'in-touch' on first contact.
    $db->query("UPDATE tblVisitor SET status = 'in-touch' WHERE visitorID = " . $id . " AND status = 'new'");
} catch (\Throwable $e) {
    \Portal\Core\Logger::errorPlatform('Visitors', 'Warning', 'CONTACT_SAVE', $e->getMessage(), '');
}

header('Location: /visitors/profile?id=' . $id);
exit();
