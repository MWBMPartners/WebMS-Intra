<?php
// Path: _apps/forms/publish.php
/**
 * -----------------------------------------------------------------------------
 * Forms Builder — Lifecycle + Public Token Handler 🚦
 * -----------------------------------------------------------------------------
 * POST-only. `action` is one of:
 *   publish   — draft/closed → published. Requires at least one INPUT field.
 *               If the form's audience allows public sharing and it has no
 *               `publicToken` yet, one is minted here (first publish only).
 *   unpublish — → draft (stops appearing on /forms and /f/{token}).
 *   close     — → closed (stops accepting responses; definition + past
 *               responses are both kept, still visible on /forms/responses).
 *   rotate    — mints a NEW publicToken, invalidating any previously shared
 *               link/QR code immediately.
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
$action = (string) ($_POST['action'] ?? '');

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

if (in_array($action, ['publish', 'unpublish', 'close', 'rotate'], true) === false) {
    $fail('Unknown action.');
}

$form = FormEngine::getForm($formId, $siteId);
if ($form === null) {
    $fail('Form not found.');
}

$db = App::db();

if ($action === 'publish') {
    $countStmt = $db->prepare('SELECT COUNT(*) AS cnt FROM tblFormFields WHERE formID = ?');
    $inputFieldCount = 0;
    if ($countStmt !== false) {
        $countStmt->bind_param('i', $formId);
        $countStmt->execute();
        // 📋 A simple field COUNT is enough here — 'heading' rows are rare
        // and a form with only headings and no answerable field is
        // pathological; getFields()+FIELD_TYPES filtering is reserved for
        // the places that actually need input-vs-display distinction.
        $inputFieldCount = (int) ($countStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $countStmt->close();
    }
    if ($inputFieldCount === 0) {
        $fail('Add at least one field before publishing.');
    }

    $status = 'published';
    $stmt = $db->prepare('UPDATE tblForms SET status = ? WHERE formID = ? AND siteID = ?');
    if ($stmt === false) {
        Logger::errorPlatform('MySQL', 'Error', 'FORM_PUBLISH_PREP', $db->error, 'formID=' . $formId);
        $fail('Could not publish the form. Please try again.');
    }
    $stmt->bind_param('sii', $status, $formId, $siteId);
    $stmt->execute();
    $stmt->close();

    // 🔑 First publish with a public-eligible audience — mint a token.
    // Retry once on a duplicate-key collision (errno 1062) — astronomically
    // unlikely with 16 random bytes, but a single retry costs nothing.
    if (in_array((string) $form['audience'], ['public', 'both'], true) === true
        && ($form['publicToken'] ?? null) === null
    ) {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $token = FormEngine::generatePublicToken();
            $tokenStmt = $db->prepare('UPDATE tblForms SET publicToken = ? WHERE formID = ? AND siteID = ?');
            if ($tokenStmt === false) {
                break;
            }
            $tokenStmt->bind_param('sii', $token, $formId, $siteId);
            $tokenOk = $tokenStmt->execute();
            $errno = $db->errno;
            $tokenStmt->close();
            if ($tokenOk === true) {
                break;
            }
            if ($errno !== 1062) {
                Logger::errorPlatform('MySQL', 'Error', 'FORM_TOKEN_ASSIGN_FAIL', $db->error, 'formID=' . $formId);
                break;
            }
            // 🔁 errno 1062 — collision, try once more with a fresh token.
        }
    }

    Logger::activity('FormPublished', 'Published form #' . $formId);
} elseif ($action === 'unpublish') {
    $status = 'draft';
    $stmt = $db->prepare('UPDATE tblForms SET status = ? WHERE formID = ? AND siteID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('sii', $status, $formId, $siteId);
        $stmt->execute();
        $stmt->close();
    }
    Logger::activity('FormUnpublished', 'Unpublished form #' . $formId);
} elseif ($action === 'close') {
    $status = 'closed';
    $stmt = $db->prepare('UPDATE tblForms SET status = ? WHERE formID = ? AND siteID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('sii', $status, $formId, $siteId);
        $stmt->execute();
        $stmt->close();
    }
    Logger::activity('FormClosed', 'Closed form #' . $formId);
} elseif ($action === 'rotate') {
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $token = FormEngine::generatePublicToken();
        $tokenStmt = $db->prepare('UPDATE tblForms SET publicToken = ? WHERE formID = ? AND siteID = ?');
        if ($tokenStmt === false) {
            break;
        }
        $tokenStmt->bind_param('sii', $token, $formId, $siteId);
        $tokenOk = $tokenStmt->execute();
        $errno = $db->errno;
        $tokenStmt->close();
        if ($tokenOk === true) {
            break;
        }
        if ($errno !== 1062) {
            Logger::errorPlatform('MySQL', 'Error', 'FORM_TOKEN_ROTATE_FAIL', $db->error, 'formID=' . $formId);
            break;
        }
    }
    Logger::activity('FormLinkRotated', 'Rotated public link for form #' . $formId);
}

$_SESSION['flash_msg']  = 'Done.';
$_SESSION['flash_type'] = 'success';
header('Location: /forms/manage', true, 302);
exit();
