<?php
// Path: public_html/offboarding/do.php
/**
 * Offboarding — POST handler that performs revocation across multiple
 * tables in a transaction. Records per-step outcomes in tblOffboarding.stepsLog.
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

$db        = App::db();
$adminId   = (int) ($_SESSION['user_id'] ?? 0);
$userId    = (int) ($_POST['userID'] ?? 0);
$reason    = trim((string) ($_POST['reason'] ?? ''));
$disp      = (string) ($_POST['dataDisposition'] ?? 'retain');
$effective = (string) ($_POST['effectiveDate'] ?? date('Y-m-d'));

if ($userId <= 0 || $reason === '') {
    header('Location: /admin/users');
    exit();
}
if (in_array($disp, ['retain','anonymise','delete'], true) === false) {
    $disp = 'retain';
}
if ($userId === $adminId) {
    $_SESSION['flash_msg']  = "You can't offboard your own account.";
    $_SESSION['flash_type'] = 'danger';
    header('Location: /admin/users');
    exit();
}

$stepsLog = [];

/**
 * Run a DML statement; record success / error.
 *
 * @return int Affected rows (0 on error or no matches).
 */
$run = function (string $label, string $sql, array $params, string $types) use ($db, &$stepsLog): int {
    try {
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            $stepsLog[] = ['step' => $label, 'ok' => false, 'error' => $db->error];
            return 0;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        $stepsLog[] = ['step' => $label, 'ok' => true, 'affected' => $affected];
        return $affected;
    } catch (\Throwable $e) {
        $stepsLog[] = ['step' => $label, 'ok' => false, 'error' => $e->getMessage()];
        return 0;
    }
};

try {
    $db->begin_transaction();

    // 1. Deactivate the user (no sign-in).
    $run('deactivate_user',
        'UPDATE tblUsers SET isActive = 0 WHERE userID = ?',
        [$userId], 'i'
    );

    // 2. Delete passkeys / WebAuthn credentials.
    $run('delete_webauthn',
        'DELETE FROM tblWebAuthnCredentials WHERE userID = ?',
        [$userId], 'i'
    );

    // 3. Disable the local-account password — clear hash so it's unusable.
    //    A future rehire requires the user to reset via the forgot-password
    //    flow.
    $run('clear_password_hash',
        "UPDATE tblLocalAccounts SET passwordHash = '' WHERE userID = ?",
        [$userId], 'i'
    );

    // 4. End site memberships.
    $run('end_site_memberships',
        'UPDATE tblUserSites SET isActive = 0 WHERE userID = ?',
        [$userId], 'i'
    );

    // 5. End leadership assignments (any with NULL endDate).
    $run('end_leadership_assignments',
        'UPDATE tblLeadershipAssignments SET endDate = ? WHERE userID = ? AND endDate IS NULL',
        [$effective, $userId], 'si'
    );

    // 6. Remove user role assignments.
    $run('delete_user_roles',
        'DELETE FROM tblUserRoles WHERE userID = ?',
        [$userId], 'i'
    );

    // 7. Audit row.
    $logJson = json_encode($stepsLog);
    $stmt = $db->prepare(
        'INSERT INTO tblOffboarding (userID, effectiveDate, reason, dataDisposition, offboardedByID, stepsLog) '
        . 'VALUES (?, ?, ?, ?, ?, ?)'
    );
    if ($stmt !== false) {
        $stmt->bind_param('isssis', $userId, $effective, $reason, $disp, $adminId, $logJson);
        $stmt->execute();
        $stmt->close();
    }

    $db->commit();

    $_SESSION['flash_msg']  = 'User offboarded. Audit row + per-step outcomes recorded.';
    $_SESSION['flash_type'] = 'success';
} catch (\Throwable $e) {
    $db->rollback();
    \Portal\Core\Logger::errorPlatform('Offboarding', 'Critical', 'OFFBOARD_FAIL', $e->getMessage(), 'userID=' . $userId);
    $_SESSION['flash_msg']  = 'Offboarding failed: ' . $e->getMessage();
    $_SESSION['flash_type'] = 'danger';
}

header('Location: /offboarding');
exit();
