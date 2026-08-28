<?php
// Path: _apps/forms/submit.php
/**
 * -----------------------------------------------------------------------------
 * Forms Builder — Internal Submission Handler 📥
 * -----------------------------------------------------------------------------
 * POST-only. Re-runs EVERY fill-page gate server-side (published / audience
 * / open window / single-submission) — the GET page (fill.php) is display
 * only and must never be trusted as the source of truth for whether a
 * submission is allowed.
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
use Portal\Core\RateLimiter;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /forms', true, 302);
    exit();
}

$siteId = Site::id();
$userId = (int) (App::user()['userID'] ?? 0);
$formId = (int) ($_POST['formID'] ?? 0);

$backToFill = '/forms/fill?id=' . $formId;

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Security check failed — please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $backToFill, true, 302);
    exit();
}

$form = FormEngine::getForm($formId, $siteId);
if ($form === null) {
    $_SESSION['flash_msg']  = 'Form not found.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /forms', true, 302);
    exit();
}

// 🛡️ Re-run every gate fill.php already checked — never trust the GET page.
$audienceOk = in_array((string) $form['audience'], ['internal', 'both'], true);
$isOpen     = FormEngine::isOpen($form);
if ($audienceOk === false || $isOpen === false) {
    $_SESSION['flash_msg']  = 'This form is not currently accepting responses.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /forms', true, 302);
    exit();
}

$allowMultiple = (int) $form['allowMultiple'] === 1;
if ($allowMultiple === false) {
    $db = App::db();
    $chkStmt = $db->prepare('SELECT responseID FROM tblFormResponses WHERE formID = ? AND submitterID = ? LIMIT 1');
    $alreadySubmitted = false;
    if ($chkStmt !== false) {
        $chkStmt->bind_param('ii', $formId, $userId);
        $chkStmt->execute();
        $alreadySubmitted = $chkStmt->get_result()->fetch_assoc() !== null;
        $chkStmt->close();
    }
    if ($alreadySubmitted === true) {
        $_SESSION['flash_msg']  = 'You have already submitted this form.';
        $_SESSION['flash_type'] = 'success';
        header('Location: /forms', true, 302);
        exit();
    }
}

// 🚦 Modest per-user rate limit — a member accidentally double-clicking or
// scripting a loop, not an anti-abuse control (this route requires login).
$rateBucket = 'forms:submit:' . $userId;
if (RateLimiter::tooMany($rateBucket, 30, 3600) === true) {
    $_SESSION['flash_msg']  = 'You are submitting forms too quickly — please slow down and try again shortly.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: ' . $backToFill, true, 302);
    exit();
}

$fields = FormEngine::getFields($formId);
$result = FormEngine::validateSubmission($fields, $_POST);

if (count($result['errors']) > 0) {
    $_SESSION['forms_flash_old']    = $result['old'];
    $_SESSION['forms_flash_errors'] = $result['errors'];
    $_SESSION['forms_flash_formID'] = $formId;
    $_SESSION['flash_msg']  = 'Please correct the errors below.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $backToFill, true, 302);
    exit();
}

$answersJson = FormEngine::buildAnswersJson($fields, $result['values']);
$newId = FormEngine::saveResponse($formId, $siteId, 'internal', $userId, null, $answersJson);

RateLimiter::recordHit($rateBucket, 3600);

if ($newId <= 0) {
    $_SESSION['flash_msg']  = 'Something went wrong submitting your response. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $backToFill, true, 302);
    exit();
}

Logger::activity('FormResponseSubmitted', 'Form #' . $formId . ' response #' . $newId);

$confirmation = trim((string) ($form['confirmationText'] ?? ''));
$_SESSION['flash_msg']  = $confirmation !== '' ? $confirmation : 'Thank you — your response has been recorded.';
$_SESSION['flash_type'] = 'success';
header('Location: /forms', true, 302);
exit();
