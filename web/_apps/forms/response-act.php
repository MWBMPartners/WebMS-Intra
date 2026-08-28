<?php
// Path: _apps/forms/response-act.php
/**
 * -----------------------------------------------------------------------------
 * Forms Builder — Response Status/Delete Handler 🛡️
 * -----------------------------------------------------------------------------
 * POST-only. `action` is one of 'reviewed' | 'new' | 'delete'. Every
 * statement is scoped `WHERE responseID = ? AND siteID = ?` — the SITE pin,
 * not just formID — so a response can never be acted on across a tenant
 * boundary even if formID were somehow guessed/forged.
 *
 * @package   Portal\Forms
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/153
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /forms/manage', true, 302);
    exit();
}

if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

$siteId     = Site::id();
$formId     = (int) ($_POST['formID'] ?? 0);
$responseId = (int) ($_POST['responseID'] ?? 0);
$action     = (string) ($_POST['action'] ?? '');

$back = '/forms/responses?id=' . $formId;

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Security check failed — please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $back, true, 302);
    exit();
}

if (in_array($action, ['reviewed', 'new', 'delete'], true) === false) {
    header('Location: ' . $back, true, 302);
    exit();
}

$db = App::db();

if ($action === 'delete') {
    $stmt = $db->prepare('DELETE FROM tblFormResponses WHERE responseID = ? AND siteID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('ii', $responseId, $siteId);
        $stmt->execute();
        $stmt->close();
    }
    Logger::activity('FormResponseDeleted', 'Deleted response #' . $responseId . ' from form #' . $formId);
} else {
    $status = $action; // 'reviewed' | 'new'
    $stmt = $db->prepare('UPDATE tblFormResponses SET status = ? WHERE responseID = ? AND siteID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('sii', $status, $responseId, $siteId);
        $stmt->execute();
        $stmt->close();
    }
    Logger::activity('FormResponseStatusChanged', 'Response #' . $responseId . ' marked ' . $status);
}

header('Location: ' . $back, true, 302);
exit();
