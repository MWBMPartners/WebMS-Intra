<?php
// Path: _apps/admin/safeguarding/dbs-save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — DBS check record handler (#310)
 * -----------------------------------------------------------------------------
 * POST. Inserts a NEW row (never updates an existing one — keeps the audit
 * trail of all DBS checks per user). The DBS list reads the latest row via
 * a window query.
 *
 * #518 FIX (17 September 2026): App::isAdmin() plus "does the account
 * exist and is it active" let an administrator of ANY organisation record
 * a DBS safeguarding check — a confidential record — against ANY
 * account. tblDbsChecks has no organisation column of its own; it is now
 * guarded by Portal\Core\AccountGuard reading the account's OWN
 * organisation membership instead.
 *
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/310
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\AccountGuard;
use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . Site::url('admin/safeguarding/dbs'), true, 302); exit(); }

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) { http_response_code(403); exit('Forbidden'); }
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) { http_response_code(400); exit('Bad request'); }

$userId   = (int) ($_POST['userID'] ?? 0);
$dbsType  = (string) ($_POST['dbsType'] ?? 'enhanced');
$ref      = mb_substr(trim((string) ($_POST['referenceNumber'] ?? '')), 0, 60);
$issued   = trim((string) ($_POST['issuedDate'] ?? ''));
$expires  = trim((string) ($_POST['expiresAt'] ?? ''));
$status   = (string) ($_POST['status'] ?? 'valid');
$recorder = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0
    || in_array($dbsType, ['basic', 'standard', 'enhanced', 'enhanced-barred'], true) === false
    || in_array($status, ['valid', 'expired', 'revoked'], true) === false
    || preg_match('/^\d{4}-\d{2}-\d{2}$/', $issued) !== 1
    || preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) !== 1
    || $expires < $issued) {
    $_SESSION['flash_msg']  = 'Invalid input.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/safeguarding/dbs'), true, 302); exit();
}

// 🛡️ #518: tblDbsChecks has no organisation column of its own — a DBS
//    check is only ever "for this organisation" through the account it
//    names, so AccountGuard reads that account's own membership.
$verdict = AccountGuard::check($userId, AccountGuard::REACH_ACCOUNT, 'record a DBS check for account #' . $userId);
if ($verdict === AccountGuard::NOT_FOUND) {
    http_response_code(404); exit('User not found');
}
if ($verdict !== AccountGuard::ALLOW) {
    $_SESSION['flash_msg']  = AccountGuard::message($verdict, AccountGuard::REACH_ACCOUNT);
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url('admin/safeguarding/dbs'), true, 302); exit();
}

// 🛡️ Confirm target user exists and is active.
$stmt = $mysqli->prepare('SELECT userID FROM tblUsers WHERE userID = ? AND isActive = 1');
$stmt->bind_param('i', $userId);
$stmt->execute();
$ok = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($ok === false) { http_response_code(404); exit('User not found'); }

$refArg = $ref !== '' ? $ref : null;
// 🧾 WHO RECORDED THE CHECK IS WRITTEN BY NAME as well as by number (#703, the
//    owner's decision D-016, migration 272). An erasure KEEPS the name of
//    whoever recorded a safeguarding check, and it empties that person's own
//    account row, so a kept NUMBER alone would point at "[Deleted User]". The
//    name is read from the recorder's account in the same statement, never
//    from the session. A sub-query rather than a join, so the statement still
//    writes exactly one row whatever it finds: the check itself matters more
//    than the label on it, and nothing about recording one changed but this.
$stmt = $mysqli->prepare(
    'INSERT INTO `tblDbsChecks` (`userID`, `dbsType`, `referenceNumber`, `issuedDate`, `expiresAt`, `status`, `recordedByID`, `recordedByName`) '
    . 'SELECT ?, ?, ?, ?, ?, ?, ?, (SELECT `r`.`fullName` FROM `tblUsers` `r` WHERE `r`.`userID` = ?)'
);
$stmt->bind_param('isssssii', $userId, $dbsType, $refArg, $issued, $expires, $status, $recorder, $recorder);
$stmt->execute();
$stmt->close();

Logger::activity('SafeguardingDbsRecorded', 'User #' . $userId . ' type=' . $dbsType . ' status=' . $status . ' expires=' . $expires);

$_SESSION['flash_msg']  = 'DBS check recorded.';
$_SESSION['flash_type'] = 'success';
header('Location: ' . Site::url('admin/safeguarding/dbs'), true, 302);
exit();
