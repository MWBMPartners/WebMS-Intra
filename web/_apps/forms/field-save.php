<?php
// Path: _apps/forms/field-save.php
/**
 * -----------------------------------------------------------------------------
 * Forms Builder — Add/Edit Field Handler 🧩
 * -----------------------------------------------------------------------------
 * POST-only. `fieldID = 0` adds a new field; `fieldID > 0` edits an existing
 * one. `fieldType` is IMMUTABLE once a field is created — the edit path
 * always re-reads the field's own stored type from the database and never
 * accepts one from POST, so a tampered edit request can't change what a
 * field type is after responses have already been recorded against it
 * (changing type would corrupt the historical answersJson snapshot
 * semantics — see FormEngine's file header and spec §7.5).
 *
 * All config coming off the wire — options / maxLength / placeholder / rows
 * / min / max — is DATA, sanitised through
 * `Portal\Core\FormEngine::sanitiseConfig()` before it is ever json_encode()d
 * into `tblFormFields.configJson`. Nothing from this request is ever
 * interpolated into SQL.
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

$siteId = Site::id();
$formId = (int) ($_POST['formID'] ?? 0);

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

// 🛡️ Parent form must belong to THIS site — no existence oracle.
$form = FormEngine::getForm($formId, $siteId);
if ($form === null) {
    $fail('Form not found.');
}

$db = App::db();
$fieldId = (int) ($_POST['fieldID'] ?? 0);
$label = mb_substr(trim((string) ($_POST['label'] ?? '')), 0, 200);
if ($label === '') {
    $fail('Field label is required.');
}
$helpText = mb_substr(trim((string) ($_POST['helpText'] ?? '')), 0, 500);
$helpTextOrNull = $helpText !== '' ? $helpText : null;
$isRequired = isset($_POST['isRequired']) ? 1 : 0;

// 🔒 fieldType: for an edit, always re-read the EXISTING stored type from
// the database — the edit UI never offers a type selector, and a tampered
// POST must not be able to change it after the fact (see file header).
if ($fieldId > 0) {
    $existingStmt = $db->prepare('SELECT fieldType FROM tblFormFields WHERE fieldID = ? AND formID = ? LIMIT 1');
    $type = null;
    if ($existingStmt !== false) {
        $existingStmt->bind_param('ii', $fieldId, $formId);
        $existingStmt->execute();
        $existingRow = $existingStmt->get_result()->fetch_assoc();
        $existingStmt->close();
        $type = $existingRow !== null ? (string) $existingRow['fieldType'] : null;
    }
    if ($type === null) {
        $fail('Field not found.');
    }
} else {
    $type = (string) ($_POST['fieldType'] ?? '');
    if (isset(FormEngine::FIELD_TYPES[$type]) === false) {
        $fail('Please choose a valid field type.');
    }
}

// 🧹 Build the RAW config from posted inputs, keyed exactly the way
// sanitiseConfig() expects — the whitelist inside that method is the only
// thing that decides what actually survives into storage.
$rawOptions = [];
if (isset($_POST['options']) === true) {
    $lines = preg_split('/\r\n|\r|\n/', (string) $_POST['options']) ?: [];
    foreach ($lines as $line) {
        $rawOptions[] = trim($line);
    }
}
$rawConfig = [
    'options'     => $rawOptions,
    'placeholder' => (string) ($_POST['placeholder'] ?? ''),
    'maxLength'   => (string) ($_POST['maxLength'] ?? ''),
    'rows'        => (string) ($_POST['rows'] ?? ''),
    'min'         => (string) ($_POST['min'] ?? ''),
    'max'         => (string) ($_POST['max'] ?? ''),
];
$config = FormEngine::sanitiseConfig($type, $rawConfig);

if (in_array($type, ['select', 'radio', 'checkboxes'], true) === true && count($config['options'] ?? []) === 0) {
    $fail('Please provide at least one option for this field type.');
}

$configJson = count($config) > 0 ? json_encode($config, JSON_UNESCAPED_UNICODE) : null;

if ($fieldId === 0) {
    // ── Add ─────────────────────────────────────────────────────────────
    $countStmt = $db->prepare('SELECT COUNT(*) AS cnt FROM tblFormFields WHERE formID = ?');
    $fieldCount = 0;
    if ($countStmt !== false) {
        $countStmt->bind_param('i', $formId);
        $countStmt->execute();
        $fieldCount = (int) ($countStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $countStmt->close();
    }
    if ($fieldCount >= 60) {
        $fail('This form has reached the maximum of 60 fields.');
    }

    $fieldKey = FormEngine::generateFieldKey($formId, $label);

    $posStmt = $db->prepare('SELECT COALESCE(MAX(position), -1) + 1 AS nextPos FROM tblFormFields WHERE formID = ?');
    $nextPosition = 0;
    if ($posStmt !== false) {
        $posStmt->bind_param('i', $formId);
        $posStmt->execute();
        $nextPosition = (int) ($posStmt->get_result()->fetch_assoc()['nextPos'] ?? 0);
        $posStmt->close();
    }

    $stmt = $db->prepare(
        'INSERT INTO tblFormFields (formID, fieldKey, label, fieldType, helpText, configJson, isRequired, position) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if ($stmt === false) {
        Logger::errorPlatform('MySQL', 'Error', 'FORM_FIELD_INSERT_PREP', $db->error, 'formID=' . $formId);
        $fail('Could not add the field. Please try again.');
    }
    $stmt->bind_param(
        'isssssii',
        $formId,
        $fieldKey,
        $label,
        $type,
        $helpTextOrNull,
        $configJson,
        $isRequired,
        $nextPosition
    );
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok === false) {
        Logger::errorPlatform('MySQL', 'Error', 'FORM_FIELD_INSERT_FAIL', $db->error, 'formID=' . $formId);
        $fail('Could not add the field. Please try again.');
    }

    Logger::activity('FormFieldSaved', 'Added field "' . $label . '" to form #' . $formId);
} else {
    // ── Edit ────────────────────────────────────────────────────────────
    $stmt = $db->prepare(
        'UPDATE tblFormFields SET label = ?, helpText = ?, configJson = ?, isRequired = ? WHERE fieldID = ? AND formID = ?'
    );
    if ($stmt === false) {
        Logger::errorPlatform('MySQL', 'Error', 'FORM_FIELD_UPDATE_PREP', $db->error, 'fieldID=' . $fieldId);
        $fail('Could not save the field. Please try again.');
    }
    $stmt->bind_param('sssiii', $label, $helpTextOrNull, $configJson, $isRequired, $fieldId, $formId);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok === false) {
        Logger::errorPlatform('MySQL', 'Error', 'FORM_FIELD_UPDATE_FAIL', $db->error, 'fieldID=' . $fieldId);
        $fail('Could not save the field. Please try again.');
    }

    Logger::activity('FormFieldSaved', 'Updated field #' . $fieldId . ' on form #' . $formId);
}

$_SESSION['flash_msg']  = 'Field saved.';
$_SESSION['flash_type'] = 'success';
header('Location: /forms/edit?id=' . $formId, true, 302);
exit();
