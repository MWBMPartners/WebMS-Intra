<?php
// Path: public_html/admin/settings/dismiss-first-run.php
/**
 * -----------------------------------------------------------------------------
 * Dismiss the first-run welcome panel 👋 (#222)
 * -----------------------------------------------------------------------------
 * POST handler behind the "Dismiss" button on the dashboard's set-up
 * checklist. Stores portal.first_run.dismissed = 1.
 *
 * WHO MAY DISMISS IT, AND WHY
 * -----------------------------------------------------------------------------
 * The row is written with nothing in the siteID column, so it is portal-wide:
 * dismissing the panel hides it from every administrator of every
 * organisation on the installation, not only from the person who pressed the
 * button.
 *
 * Until 11 September 2026 the only check was App::isAdmin(), which is true for
 * an administrator of a SINGLE organisation as well as for a global
 * administrator (see web/_core/App.php). So an administrator of one
 * organisation could hide the set-up checklist from a global administrator
 * who was still working through it. The owner decided that settings affecting
 * every organisation are for a global administrator only, and this one is no
 * exception.
 *
 * Anybody else gets a short page saying why, rather than a bare "forbidden".
 * It cannot be a message carried back to the dashboard, because the dashboard
 * does not show those messages: the refusal would simply vanish, and the
 * person would press Dismiss again.
 *
 * What this cannot do: the dashboard (web/_apps/dashboard/index.php) still
 * shows the panel, with its Dismiss button, to every administrator. Hiding the
 * button from people who cannot use it has to be done there.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/222
 * -----------------------------------------------------------------------------
 */
declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;

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

// 🛡️ CSRF — state-changing handler.
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Invalid CSRF token.');
}

// 🚧 Portal-wide: a global administrator only (see the file header). This
//    comes after the form-token check, so a forged request from another
//    website cannot fill the activity log with refusals.
if (App::isRootAdmin() === false) {
    Logger::activity(
        'SettingsGroupSaveRefused',
        'Refused: portal-wide setting "portal.first_run.dismissed" may only be changed by a global administrator',
        $_SESSION['user_id'] ?? null
    );
    http_response_code(403);
    $pageTitle   = 'Only a global administrator can dismiss this';
    $pageSection = 'admin';
    $breadcrumbs = ['Dashboard' => '/', 'Set-up checklist' => ''];
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
    ?>
    <!-- 👀 Worded refusal. Nothing below this point has run: nothing was saved. -->
    <h1 class="h3 mb-3"><i class="fa-solid fa-circle-info me-2"></i>Only a global administrator can dismiss this</h1>
    <div class="alert alert-info">
        The set-up checklist is portal-wide: dismissing it hides it for
        <strong>every organisation</strong> on this installation, not only
        yours. Only a global administrator can change that. Nothing has been
        changed.
    </div>
    <a href="/" class="btn btn-primary">Back to the dashboard</a>
    <?php
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
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
