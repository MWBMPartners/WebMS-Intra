<?php
// Path: public_html/care/visit-save.php
/**
 * Care Register — record a visit / contact on a case.
 *
 * @package   Portal\Care
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/257
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false && App::hasRole('care_team') === false) {
    http_response_code(403);
    exit('Forbidden');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$db        = App::db();
$siteId    = Site::id();
$userId    = (int) ($_SESSION['user_id'] ?? 0);
$caseId    = (int) ($_POST['caseID'] ?? 0);
$kind      = (string) ($_POST['kind'] ?? 'visit');
$notes     = trim((string) ($_POST['notes'] ?? ''));
$followUp  = trim((string) ($_POST['followUpAt'] ?? ''));

if (in_array($kind, ['visit','call','message','prayer','other'], true) === false) {
    $kind = 'visit';
}
$followUpDate = ($followUp !== '' && strtotime($followUp) !== false) ? $followUp : null;

// 🛡️ Confirm the case exists in this site (no cross-site leakage).
$stmt = $db->prepare('SELECT 1 FROM tblCareCase WHERE caseID = ? AND siteID = ? LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('ii', $caseId, $siteId);
    $stmt->execute();
    $ok = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    if ($ok === false) {
        http_response_code(404);
        exit('Case not found.');
    }
}

try {
    $stmt = $db->prepare(
        'INSERT INTO tblCareVisit (caseID, visitedByID, kind, notes, followUpAt) VALUES (?, ?, ?, ?, ?)'
    );
    if ($stmt !== false) {
        $stmt->bind_param('iisss', $caseId, $userId, $kind, $notes, $followUpDate);
        $stmt->execute();
        $stmt->close();
    }
} catch (\Throwable $e) {
    \Portal\Core\Logger::errorPlatform('Care', 'Warning', 'VISIT_SAVE', $e->getMessage(), '');
}

header('Location: /care/case?id=' . $caseId);
exit();
