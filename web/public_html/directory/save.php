<?php
// Path: public_html/directory/save.php
/**
 * Member Directory — POST handler for own profile save.
 *
 * @package   Portal\Directory
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/261
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;

Auth::ensureSession();
Auth::requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$db     = App::db();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$validVisibility = static fn (string $v): string =>
    in_array($v, ['private','team','members','public'], true) ? $v : 'private';

$bio     = trim((string) ($_POST['displayBio'] ?? ''));
$phone   = trim((string) ($_POST['displayPhone'] ?? ''));
$address = trim((string) ($_POST['displayAddress'] ?? ''));
$vName    = $validVisibility((string) ($_POST['visibilityName']    ?? 'members'));
$vEmail   = $validVisibility((string) ($_POST['visibilityEmail']   ?? 'private'));
$vPhone   = $validVisibility((string) ($_POST['visibilityPhone']   ?? 'private'));
$vAddress = $validVisibility((string) ($_POST['visibilityAddress'] ?? 'private'));
$vBio     = $validVisibility((string) ($_POST['visibilityBio']     ?? 'members'));
$vRoles   = $validVisibility((string) ($_POST['visibilityRoles']   ?? 'members'));

try {
    $stmt = $db->prepare(
        'UPDATE tblUsers SET '
        . 'displayBio = ?, displayPhone = ?, displayAddress = ?, '
        . 'visibilityName = ?, visibilityEmail = ?, visibilityPhone = ?, '
        . 'visibilityAddress = ?, visibilityBio = ?, visibilityRoles = ? '
        . 'WHERE userID = ?'
    );
    if ($stmt !== false) {
        $stmt->bind_param('sssssssssi', $bio, $phone, $address, $vName, $vEmail, $vPhone, $vAddress, $vBio, $vRoles, $userId);
        $stmt->execute();
        $stmt->close();
    }
} catch (\Throwable $e) {
    \Portal\Core\Logger::errorPlatform('Directory', 'Warning', 'SAVE', $e->getMessage(), '');
}

header('Location: /directory/my-settings');
exit();
