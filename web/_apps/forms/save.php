<?php
// Path: _apps/forms/save.php
/**
 * -----------------------------------------------------------------------------
 * Forms Builder — Create/Update Form Meta 💾
 * -----------------------------------------------------------------------------
 * POST-only handler for the meta card on edit.php. `formID = 0` creates a
 * new (draft, internal) form; `formID > 0` updates an existing one,
 * site-scoped throughout.
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

$siteId = Site::id();
$userId = (int) (App::user()['userID'] ?? 0);

/**
 * Bounce back to edit.php with a flash message — local closure so every
 * rejection branch below bails out identically.
 */
$fail = static function (string $msg, int $formIdForRedirect): never {
    $_SESSION['flash_msg']  = $msg;
    $_SESSION['flash_type'] = 'danger';
    $target = $formIdForRedirect > 0 ? '/forms/edit?id=' . $formIdForRedirect : '/forms/edit';
    header('Location: ' . $target, true, 302);
    exit();
};

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $fail('Security check failed — please try again.', (int) ($_POST['formID'] ?? 0));
}

$formId = (int) ($_POST['formID'] ?? 0);

// 🛡️ On update, confirm the form belongs to THIS site before touching it —
// no existence oracle for a foreign formID (same flash either way).
if ($formId > 0) {
    $db = App::db();
    $check = $db->prepare('SELECT formID FROM tblForms WHERE formID = ? AND siteID = ? LIMIT 1');
    $formExists = false;
    if ($check !== false) {
        $check->bind_param('ii', $formId, $siteId);
        $check->execute();
        $formExists = $check->get_result()->fetch_assoc() !== null;
        $check->close();
    }
    if ($formExists === false) {
        $fail('Form not found.', 0);
    }
}

// 📥 Validate meta fields.
$title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 200);
if ($title === '') {
    $fail('Title is required.', $formId);
}

$description = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 10000);
$descriptionOrNull = $description !== '' ? $description : null;

$confirmationText = mb_substr(trim((string) ($_POST['confirmationText'] ?? '')), 0, 500);
$confirmationOrNull = $confirmationText !== '' ? $confirmationText : null;

$allowPublicSite = (string) (App::settings('forms.allowPublic') ?? 'false') === 'true';
$audience = (string) ($_POST['audience'] ?? 'internal');
if (in_array($audience, ['internal', 'public', 'both'], true) === false) {
    $audience = 'internal';
}
if ($allowPublicSite === false) {
    // 🛡️ Server-side enforcement of the disabled <select> — a posted
    // 'public'/'both' is silently forced back to 'internal'.
    $audience = 'internal';
}

$allowMultiple = isset($_POST['allowMultiple']) ? 1 : 0;

/**
 * Parse a `datetime-local` value ('Y-m-d\TH:i') into 'Y-m-d H:i:s', or
 * null for a blank/invalid input.
 */
$parseDatetimeLocal = static function (string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $dt = \DateTime::createFromFormat('Y-m-d\TH:i', $raw);
    if ($dt === false) {
        return null;
    }
    return $dt->format('Y-m-d H:i:s');
};

$opensAt  = $parseDatetimeLocal((string) ($_POST['opensAt'] ?? ''));
$closesAt = $parseDatetimeLocal((string) ($_POST['closesAt'] ?? ''));

if ($opensAt !== null && $closesAt !== null && $closesAt <= $opensAt) {
    $fail('The closing time must be after the opening time.', $formId);
}

$db = App::db();

if ($formId === 0) {
    // ── Create ──────────────────────────────────────────────────────────
    $stmt = $db->prepare(
        'INSERT INTO tblForms (siteID, title, description, audience, confirmationText, allowMultiple, opensAt, closesAt, createdByID) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if ($stmt === false) {
        Logger::errorPlatform('MySQL', 'Error', 'FORM_INSERT_PREP', $db->error, '');
        $fail('Could not create the form. Please try again.', 0);
    }
    $stmt->bind_param(
        'issssissi',
        $siteId,
        $title,
        $descriptionOrNull,
        $audience,
        $confirmationOrNull,
        $allowMultiple,
        $opensAt,
        $closesAt,
        $userId
    );
    $ok = $stmt->execute();
    $newId = (int) $stmt->insert_id;
    $stmt->close();

    if ($ok === false || $newId <= 0) {
        Logger::errorPlatform('MySQL', 'Error', 'FORM_INSERT_FAIL', $db->error, '');
        $fail('Could not create the form. Please try again.', 0);
    }

    Logger::activity('FormSaved', 'Created form #' . $newId . ' (' . $title . ')');
    $_SESSION['flash_msg']  = 'Form created — now add some fields.';
    $_SESSION['flash_type'] = 'success';
    header('Location: /forms/edit?id=' . $newId, true, 302);
    exit();
}

// ── Update ──────────────────────────────────────────────────────────────
$stmt = $db->prepare(
    'UPDATE tblForms SET title = ?, description = ?, audience = ?, confirmationText = ?, allowMultiple = ?, opensAt = ?, closesAt = ? '
    . 'WHERE formID = ? AND siteID = ?'
);
if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'FORM_UPDATE_PREP', $db->error, 'formID=' . $formId);
    $fail('Could not save the form. Please try again.', $formId);
}
$stmt->bind_param(
    'ssssissii',
    $title,
    $descriptionOrNull,
    $audience,
    $confirmationOrNull,
    $allowMultiple,
    $opensAt,
    $closesAt,
    $formId,
    $siteId
);
$ok = $stmt->execute();
$stmt->close();

if ($ok === false) {
    Logger::errorPlatform('MySQL', 'Error', 'FORM_UPDATE_FAIL', $db->error, 'formID=' . $formId);
    $fail('Could not save the form. Please try again.', $formId);
}

Logger::activity('FormSaved', 'Updated form #' . $formId . ' (' . $title . ')');
$_SESSION['flash_msg']  = 'Form saved.';
$_SESSION['flash_type'] = 'success';
header('Location: /forms/edit?id=' . $formId, true, 302);
exit();
