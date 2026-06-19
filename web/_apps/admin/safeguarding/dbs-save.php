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
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/310
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /admin/safeguarding/dbs', true, 302); exit(); }

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
    header('Location: /admin/safeguarding/dbs', true, 302); exit();
}

// 🛡️ Confirm target user exists.
$stmt = $mysqli->prepare('SELECT userID FROM tblUsers WHERE userID = ? AND isActive = 1');
$stmt->bind_param('i', $userId);
$stmt->execute();
$ok = (bool) $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($ok === false) { http_response_code(404); exit('User not found'); }

$refArg = $ref !== '' ? $ref : null;
$stmt = $mysqli->prepare(
    'INSERT INTO tblDbsChecks (userID, dbsType, referenceNumber, issuedDate, expiresAt, status, recordedByID) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$stmt->bind_param('isssssi', $userId, $dbsType, $refArg, $issued, $expires, $status, $recorder);
$stmt->execute();
$stmt->close();

Logger::activity('SafeguardingDbsRecorded', 'User #' . $userId . ' type=' . $dbsType . ' status=' . $status . ' expires=' . $expires);

$_SESSION['flash_msg']  = 'DBS check recorded.';
$_SESSION['flash_type'] = 'success';
header('Location: /admin/safeguarding/dbs', true, 302);
exit();
