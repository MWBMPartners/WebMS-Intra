<?php
// Path: public_html/milestones/save.php
/**
 * Milestones — POST handler for add / delete.
 *
 * @package   Portal\Milestones
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/259
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;

Auth::ensureSession();
Auth::requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$db     = App::db();
$userId = (int) ($_SESSION['user_id'] ?? 0);

try {
    if (isset($_POST['delete']) === true) {
        $id = (int) $_POST['delete'];
        $stmt = $db->prepare('DELETE FROM tblUserMilestone WHERE milestoneID = ? AND userID = ?');
        if ($stmt !== false) {
            $stmt->bind_param('ii', $id, $userId);
            $stmt->execute();
            $stmt->close();
        }
    } else {
        $kind    = (string) ($_POST['kind'] ?? 'other');
        $label   = trim((string) ($_POST['label'] ?? ''));
        $date    = (string) ($_POST['date'] ?? '');
        $privacy = (string) ($_POST['privacy'] ?? 'members');
        if (in_array($kind, ['birthday','anniversary','baptism','joining','wedding','other'], true) === false) {
            $kind = 'other';
        }
        if (in_array($privacy, ['private','team','members','public'], true) === false) {
            $privacy = 'members';
        }
        $ts = strtotime($date);
        if ($ts === false) {
            throw new \RuntimeException('Invalid date');
        }
        $monthDay   = date('m-d', $ts);
        $originYear = (int) date('Y', $ts);
        if ($label === '') {
            $label = null;
        }
        $stmt = $db->prepare(
            'INSERT INTO tblUserMilestone (userID, kind, label, monthDay, originYear, privacy) VALUES (?, ?, ?, ?, ?, ?)'
        );
        if ($stmt !== false) {
            $stmt->bind_param('isssis', $userId, $kind, $label, $monthDay, $originYear, $privacy);
            $stmt->execute();
            $stmt->close();
        }
    }
} catch (\Throwable $e) {
    \Portal\Core\Logger::errorPlatform('Milestones', 'Warning', 'SAVE', $e->getMessage(), '');
}

header('Location: /milestones/me');
exit();
