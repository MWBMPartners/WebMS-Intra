<?php
// Path: _apps/forms/field-move.php
/**
 * -----------------------------------------------------------------------------
 * Forms Builder — Reorder Field Handler ↕️
 * -----------------------------------------------------------------------------
 * POST-only. Swaps a field's `position` with its immediate up/down neighbour
 * inside a transaction. Silently no-ops at either end of the list — moving
 * the first field up (or the last field down) is simply a redirect back,
 * not an error.
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
$direction = (string) ($_POST['direction'] ?? '');

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

$form = FormEngine::getForm($formId, $siteId);
if ($form === null) {
    $fail('Form not found.');
}

if (in_array($direction, ['up', 'down'], true) === false) {
    header('Location: /forms/edit?id=' . $formId, true, 302);
    exit();
}

$db = App::db();

// 🔍 Current field's position.
$curStmt = $db->prepare('SELECT position FROM tblFormFields WHERE fieldID = ? AND formID = ? LIMIT 1');
$currentPosition = null;
if ($curStmt !== false) {
    $curStmt->bind_param('ii', $fieldId, $formId);
    $curStmt->execute();
    $curRow = $curStmt->get_result()->fetch_assoc();
    $curStmt->close();
    $currentPosition = $curRow !== null ? (int) $curRow['position'] : null;
}
if ($currentPosition === null) {
    // 🛡️ Unknown/foreign field — silent no-op, back to the edit page.
    header('Location: /forms/edit?id=' . $formId, true, 302);
    exit();
}

// 🔍 Immediate neighbour in the requested direction.
$neighbourSql = $direction === 'up'
    ? 'SELECT fieldID, position FROM tblFormFields WHERE formID = ? AND position < ? ORDER BY position DESC LIMIT 1'
    : 'SELECT fieldID, position FROM tblFormFields WHERE formID = ? AND position > ? ORDER BY position ASC LIMIT 1';
$neighbourStmt = $db->prepare($neighbourSql);
$neighbour = null;
if ($neighbourStmt !== false) {
    $neighbourStmt->bind_param('ii', $formId, $currentPosition);
    $neighbourStmt->execute();
    $neighbour = $neighbourStmt->get_result()->fetch_assoc();
    $neighbourStmt->close();
}

if ($neighbour === null) {
    // ✅ Already at this end of the list — silent no-op (spec §7.7).
    header('Location: /forms/edit?id=' . $formId, true, 302);
    exit();
}

$neighbourId  = (int) $neighbour['fieldID'];
$neighbourPos = (int) $neighbour['position'];

App::beginTransaction();
$swapOk = true;

$upd1 = $db->prepare('UPDATE tblFormFields SET position = ? WHERE fieldID = ? AND formID = ?');
if ($upd1 === false) {
    $swapOk = false;
} else {
    $upd1->bind_param('iii', $neighbourPos, $fieldId, $formId);
    $swapOk = $upd1->execute() && $swapOk;
    $upd1->close();
}

if ($swapOk === true) {
    $upd2 = $db->prepare('UPDATE tblFormFields SET position = ? WHERE fieldID = ? AND formID = ?');
    if ($upd2 === false) {
        $swapOk = false;
    } else {
        $upd2->bind_param('iii', $currentPosition, $neighbourId, $formId);
        $swapOk = $upd2->execute() && $swapOk;
        $upd2->close();
    }
}

if ($swapOk === true) {
    App::commit();
    Logger::activity('FormFieldMoved', 'Moved field #' . $fieldId . ' ' . $direction . ' on form #' . $formId);
} else {
    App::rollback();
    Logger::errorPlatform('MySQL', 'Error', 'FORM_FIELD_MOVE_FAIL', $db->error, 'fieldID=' . $fieldId);
}

header('Location: /forms/edit?id=' . $formId, true, 302);
exit();
