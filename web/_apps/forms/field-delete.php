<?php
// Path: _apps/forms/field-delete.php
/**
 * -----------------------------------------------------------------------------
 * Forms Builder — Delete Field Handler 🗑️
 * -----------------------------------------------------------------------------
 * POST-only. Deletes a single field definition. Historical responses are
 * UNAFFECTED — `tblFormResponses.answersJson` is an immutable snapshot
 * (label/type/value captured at submission time), so deleting the field
 * definition never touches, orphans, or corrupts already-collected answers.
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
use Portal\Core\FormEngine;
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

$siteId  = Site::id();
$formId  = (int) ($_POST['formID'] ?? 0);
$fieldId = (int) ($_POST['fieldID'] ?? 0);

$fail = static function (string $msg) use ($formId): never {
    $_SESSION['flash_msg']  = $msg;
    $_SESSION['flash_type'] = 'danger';
    $target = $formId > 0 ? '/forms/edit?id=' . $formId : '/forms/manage';
    header('Location: ' . $target, true, 302);
    exit();
};

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $fail('Security check failed — please try again.');
}

// 🛡️ Parent form must belong to THIS site.
$form = FormEngine::getForm($formId, $siteId);
if ($form === null) {
    $fail('Form not found.');
}

$db = App::db();
$stmt = $db->prepare('DELETE FROM tblFormFields WHERE fieldID = ? AND formID = ?');
if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'FORM_FIELD_DELETE_PREP', $db->error, 'fieldID=' . $fieldId);
    $fail('Could not delete the field. Please try again.');
}
$stmt->bind_param('ii', $fieldId, $formId);
$ok = $stmt->execute();
$stmt->close();

if ($ok === false) {
    Logger::errorPlatform('MySQL', 'Error', 'FORM_FIELD_DELETE_FAIL', $db->error, 'fieldID=' . $fieldId);
    $fail('Could not delete the field. Please try again.');
}

Logger::activity('FormFieldDeleted', 'Deleted field #' . $fieldId . ' from form #' . $formId);
$_SESSION['flash_msg']  = 'Field deleted.';
$_SESSION['flash_type'] = 'success';
header('Location: /forms/edit?id=' . $formId, true, 302);
exit();
