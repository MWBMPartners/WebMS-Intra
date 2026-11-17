<?php
// Path: public_html/care/case-save.php
/**
 * Care Register — open new case.
 *
 * @package   Portal\Care
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/257
 */

declare(strict_types=1);

use Portal\Core\AccountGuard;
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

// 👤 WHICH MEMBER THE CASE IS ABOUT (#1066). Until now a case was saved with
//    only a typed name, so it was invisible to that member's "My data", their
//    data copy and an erasure (the column `personUserID` existed and nothing
//    wrote it). The form now makes the worker choose.
//
//    ASK WHETHER THE FIELD ARRIVED, never read it with `?? ''` (#677): an
//    empty value is not the same as no value. When it did NOT arrive (an
//    older copy of the form, or a caller that never sent it) the handler
//    behaves exactly as before - typed name only - because the FORM is what
//    forces the choice and this stays compatible with older posters.
//
//    When it did arrive it must be the word `none` or a plain whole number
//    (a request chooses the TYPE as well as the value: `personUserID[]=1`
//    would cast to 1 in PHP, so a string is required first). A number must
//    name somebody who belongs to THIS organisation, by the one membership
//    test (`Site::isOwnOrganisation()`), and whose account is not erased.
//    Anything else is refused with nothing written.
$personUserId = null;
if (array_key_exists('personUserID', $_POST) === true) {
    $chosen  = $_POST['personUserID'];
    $refused = false;
    if (is_string($chosen) === false) {
        $refused = true;
    } elseif ($chosen === 'none') {
        $personUserId = null;
    } elseif (preg_match('/^[1-9][0-9]{0,9}$/', $chosen) === 1) {
        $candidate = (int) $chosen;
        try {
            $mayChoose = Site::isOwnOrganisation($candidate, $siteId, $db) === true
                && AccountGuard::checkNotErased($candidate, 'care.case.open') === AccountGuard::ALLOW;
        } catch (\Throwable $e) {
            // A failed read is "cannot tell", which must never become "go ahead".
            $mayChoose = false;
        }
        if ($mayChoose === true) {
            $personUserId = $candidate;
            // The name comes from the account (an erasure empties the account,
            // and so the name goes with it), so none is kept on the case.
            $person = '';
        } else {
            $refused = true;
        }
    } else {
        $refused = true;
    }
    if ($refused === true) {
        // Numbers and fixed words only (#933): the typed value is never logged.
        \Portal\Core\Logger::activity('CareCaseRefused', 'A care case was not opened: the person chosen could not be used', $userId);
        $_SESSION['flash_type'] = 'danger';
        $_SESSION['flash_msg']  = 'That person cannot be chosen here. Nothing was saved.';
        header('Location: ' . Site::url('care'));
        exit();
    }
}
$personName = $person === '' ? null : $person;

// 🧾 WHO OPENED THE CASE IS WRITTEN BY NAME as well as by number (#703, the
//    owner's decision D-110, migration 272). An erasure KEEPS who opened a
//    pastoral case, and it empties that person's own account row, so a kept
//    NUMBER alone would point at "[Deleted User]". The name is read from the
//    account in the same statement, never from the session, by a sub-query so
//    the statement writes exactly one row whatever it finds.
//    `tools/gdpr-coverage-selftest.php` pins this statement and
//    `tools/erasure-pages-selftest.php` drives it.
$saved = false;
try {
    $stmt = $db->prepare(
        'INSERT INTO `tblCareCase` (`siteID`, `personUserID`, `personName`, `category`, `summary`, `openedByID`, `openedByName`) '
        . 'SELECT ?, ?, ?, ?, ?, ?, (SELECT `u`.`fullName` FROM `tblUsers` `u` WHERE `u`.`userID` = ?)'
    );
    if ($stmt !== false) {
        $stmt->bind_param('iisssii', $siteId, $personUserId, $personName, $category, $summary, $userId, $userId);
        $saved = $stmt->execute();
        $stmt->close();
    }
} catch (\Throwable $e) {
    \Portal\Core\Logger::errorPlatform('Care', 'Warning', 'CASE_SAVE', $e->getMessage(), '');
}
if ($saved === false) {
    // It used to say nothing at all, so a failed save looked like a saved one.
    $_SESSION['flash_type'] = 'danger';
    $_SESSION['flash_msg']  = 'The case was not saved. Please try again.';
}

header('Location: ' . Site::url('care'));
exit();
