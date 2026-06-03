<?php
// Path: public_html/invites/revoke.php
/**
 * Invite Onboarding — revoke a pending invitation.
 *
 * @package   Portal\Invites
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/239
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

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

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$id     = (int) ($_POST['invitationID'] ?? 0);

if ($id > 0) {
    $stmt = $db->prepare(
        'UPDATE tblInvitation SET revokedAt = NOW(), revokedByID = ? '
        . 'WHERE invitationID = ? AND siteID = ? AND acceptedAt IS NULL AND revokedAt IS NULL'
    );
    if ($stmt !== false) {
        $stmt->bind_param('iii', $userId, $id, $siteId);
        $stmt->execute();
        $stmt->close();
    }
}

header('Location: /invites');
exit();
