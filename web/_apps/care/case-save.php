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
use Portal\Core\Capabilities;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false && App::can(Capabilities::CARE_REGISTER) === false) {
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
    header('Location: ' . Site::url('care'));
    exit();
}

// 🧾 WHO OPENED THE CASE IS WRITTEN BY NAME as well as by number (#703, the
//    owner's decision D-110, migration 272). An erasure KEEPS who opened a
//    pastoral case, and it empties that person's own account row, so a kept
//    NUMBER alone would point at "[Deleted User]". The name is read from the
//    account in the same statement, never from the session, by a sub-query so
//    the statement writes exactly one row whatever it finds.
//    `tools/gdpr-coverage-selftest.php` pins this statement and
//    `tools/erasure-pages-selftest.php` drives it.
try {
    $stmt = $db->prepare(
        'INSERT INTO `tblCareCase` (`siteID`, `personName`, `category`, `summary`, `openedByID`, `openedByName`) '
        . 'SELECT ?, ?, ?, ?, ?, (SELECT `u`.`fullName` FROM `tblUsers` `u` WHERE `u`.`userID` = ?)'
    );
    if ($stmt !== false) {
        $stmt->bind_param('isssii', $siteId, $person, $category, $summary, $userId, $userId);
        $stmt->execute();
        $stmt->close();
    }
} catch (\Throwable $e) {
    \Portal\Core\Logger::errorPlatform('Care', 'Warning', 'CASE_SAVE', $e->getMessage(), '');
}

header('Location: ' . Site::url('care'));
exit();
