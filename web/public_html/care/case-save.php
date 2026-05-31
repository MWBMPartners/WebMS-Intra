<?php
// Path: public_html/care/case-save.php
/**
 * Care Register — open new case.
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
$category  = (string) ($_POST['category'] ?? 'other');
$summary   = trim((string) ($_POST['summary'] ?? ''));
$person    = trim((string) ($_POST['personName'] ?? ''));

if (in_array($category, ['illness','hospital','bereavement','family','transition','other'], true) === false) {
    $category = 'other';
}
if ($summary === '') {
    header('Location: /care');
    exit();
}

try {
    $stmt = $db->prepare(
        'INSERT INTO tblCareCase (siteID, personName, category, summary, openedByID) VALUES (?, ?, ?, ?, ?)'
    );
    if ($stmt !== false) {
        $stmt->bind_param('isssi', $siteId, $person, $category, $summary, $userId);
        $stmt->execute();
        $stmt->close();
    }
} catch (\Throwable $e) {
    \Portal\Core\Logger::errorPlatform('Care', 'Warning', 'CASE_SAVE', $e->getMessage(), '');
}

header('Location: /care');
exit();
