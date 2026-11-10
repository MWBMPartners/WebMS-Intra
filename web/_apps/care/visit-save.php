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

// 🧾 WHO MADE THE VISIT IS WRITTEN BY NAME as well as by number (#703, the
//    owner's decision D-016, migration 272). An erasure KEEPS the name of
//    whoever made a pastoral visit, and it empties that person's own account
//    row, so a kept NUMBER alone would point at "[Deleted User]" and say
//    nothing. The name is taken from the account itself, in the same
//    statement, never from the session: a session can carry a name that was
//    changed since sign-in, and this is a safeguarding record. A sub-query
//    rather than a join, so the statement writes exactly one row whatever it
//    finds, and fails exactly as it did before this change if it must.
//
//    Written here, when the visit is made, rather than copied in by the
//    erasure: copied at erasure, the name would depend on one step running
//    before another, and a run that failed at that step would lose it for
//    good. `tools/gdpr-coverage-selftest.php` pins this statement's shape and
//    `tools/erasure-pages-selftest.php` drives it.
try {
    $stmt = $db->prepare(
        'INSERT INTO `tblCareVisit` (`caseID`, `visitedByID`, `visitedByName`, `kind`, `notes`, `followUpAt`) '
        . 'SELECT ?, ?, (SELECT `u`.`fullName` FROM `tblUsers` `u` WHERE `u`.`userID` = ?), ?, ?, ?'
    );
    if ($stmt !== false) {
        $stmt->bind_param('iiisss', $caseId, $userId, $userId, $kind, $notes, $followUpDate);
        $stmt->execute();
        $stmt->close();
    }
} catch (\Throwable $e) {
    \Portal\Core\Logger::errorPlatform('Care', 'Warning', 'VISIT_SAVE', $e->getMessage(), '');
}

header('Location: ' . Site::url('care/case') . '?id=' . $caseId);
exit();
