<?php
// Path: public_html/offboarding/rehire.php
/**
 * Offboarding — reactivate a user within the undo window. Restores
 * isActive flags but NOT credentials; the user must reset password
 * via /forgot-password to sign in.
 *
 * @package   Portal\Offboarding
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/240
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$db      = App::db();
$adminId = (int) ($_SESSION['user_id'] ?? 0);
$id      = (int) ($_POST['offboardingID'] ?? 0);

$undoWindowDays = (int) (App::settings()['offboarding']['undo_window_days'] ?? 7);

// Locate the offboarding row + confirm within undo window.
$o = null;
$stmt = $db->prepare(
    'SELECT offboardingID, userID, offboardedAt, rehiredAt FROM tblOffboarding WHERE offboardingID = ? LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $o = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($o === null || $o['rehiredAt'] !== null) {
    header('Location: /offboarding');
    exit();
}
$ageDays = (time() - strtotime((string) $o['offboardedAt'])) / 86400;
if ($ageDays > $undoWindowDays) {
    $_SESSION['flash_msg']  = 'Undo window has passed.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /offboarding');
    exit();
}

$userId = (int) $o['userID'];

try {
    $db->begin_transaction();
    // Reactivate user + site membership. NOT credentials — user must reset.
    $stmt = $db->prepare('UPDATE tblUsers SET isActive = 1 WHERE userID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }
    $stmt = $db->prepare('UPDATE tblUserSites SET isActive = 1 WHERE userID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }
    $stmt = $db->prepare(
        'UPDATE tblOffboarding SET rehiredAt = NOW(), rehiredByID = ? WHERE offboardingID = ?'
    );
    if ($stmt !== false) {
        $stmt->bind_param('ii', $adminId, $id);
        $stmt->execute();
        $stmt->close();
    }
    $db->commit();
    $_SESSION['flash_msg']  = 'User rehired. They must reset their password to sign in.';
    $_SESSION['flash_type'] = 'success';
} catch (\Throwable $e) {
    $db->rollback();
    $_SESSION['flash_msg']  = 'Rehire failed: ' . $e->getMessage();
    $_SESSION['flash_type'] = 'danger';
}

header('Location: /offboarding');
exit();
