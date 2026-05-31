<?php
// Path: public_html/admin/settings/dismiss-first-run.php
/**
 * Dismiss the first-run welcome panel (#222). Admin-only POST handler.
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /');
    exit();
}

$db = App::db();
try {
    $stmt = $db->prepare(
        "INSERT INTO tblSettings (siteID, settingKey, settingValue, defaultValue, isSensitive) "
        . "VALUES (NULL, 'portal.first_run.dismissed', '1', '0', 0) "
        . "ON DUPLICATE KEY UPDATE settingValue = '1'"
    );
    if ($stmt !== false) {
        $stmt->execute();
        $stmt->close();
    }
} catch (\mysqli_sql_exception $e) {
    // 🛡️ Non-fatal — admin can dismiss again.
}

header('Location: /');
exit();
