<?php
// Path: public_html/admin/email-templates/save.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Email Template save handler 📨
 * -----------------------------------------------------------------------------
 * POST-only. Either upserts a site override OR (action=revert) deletes
 * the site override and falls back to the global default.
 *
 * @package   Portal\Admin
 * @license   All Rights Reserved
 * @version   1.0.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/email-templates', true, 302);
    exit();
}
Auth::ensureSession();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['email_template_flash']      = 'Invalid or expired form token.';
    $_SESSION['email_template_flash_type'] = 'danger';
    header('Location: /admin/email-templates', true, 302);
    exit();
}

$key    = trim((string) ($_POST['templateKey'] ?? ''));
$siteId = Site::id();
$action = (string) ($_POST['action'] ?? 'save');

if ($key === '' || preg_match('/^[a-zA-Z0-9_\-\.]+$/', $key) !== 1) {
    $_SESSION['email_template_flash']      = 'Invalid template key.';
    $_SESSION['email_template_flash_type'] = 'danger';
    header('Location: /admin/email-templates', true, 302);
    exit();
}

// 🚫 Revert: delete the site override for this key (global default takes over)
if ($action === 'revert') {
    $stmt = $mysqli->prepare(
        'DELETE FROM tblEmailTemplates WHERE templateKey = ? AND siteID = ?'
    );
    if ($stmt !== false) {
        $stmt->bind_param('si', $key, $siteId);
        $stmt->execute();
        $stmt->close();
    }
    Logger::activity('EmailTemplateRevert', 'Site override removed for: ' . $key);
    $_SESSION['email_template_flash']      = 'Site override removed — template now uses the global default.';
    $_SESSION['email_template_flash_type'] = 'success';
    header('Location: /admin/email-templates/edit?key=' . urlencode($key), true, 302);
    exit();
}

// ✏️ Save: upsert site override
$subject  = trim((string) ($_POST['subject']  ?? ''));
$bodyHtml = (string) ($_POST['bodyHtml'] ?? '');

if ($subject === '' || $bodyHtml === '') {
    $_SESSION['email_template_flash']      = 'Subject and body are required.';
    $_SESSION['email_template_flash_type'] = 'danger';
    header('Location: /admin/email-templates/edit?key=' . urlencode($key), true, 302);
    exit();
}
if (mb_strlen($subject) > 255) {
    $subject = mb_substr($subject, 0, 255);
}

// Borrow description + availableTokens from the global default (admin can't
// edit those — they describe how the caller invokes the template).
$descr  = null;
$tokens = null;
$stmt = $mysqli->prepare(
    'SELECT description, availableTokens FROM tblEmailTemplates '
    . 'WHERE templateKey = ? AND siteID IS NULL LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row !== null) {
        $descr  = $row['description'];
        $tokens = $row['availableTokens'];
    }
    $stmt->close();
}

$stmt = $mysqli->prepare(
    'INSERT INTO tblEmailTemplates '
    . '(siteID, templateKey, subject, bodyHtml, description, availableTokens) '
    . 'VALUES (?, ?, ?, ?, ?, ?) '
    . 'ON DUPLICATE KEY UPDATE subject = VALUES(subject), bodyHtml = VALUES(bodyHtml)'
);
if ($stmt === false) {
    Logger::errorPlatform('MySQL', 'Error', 'EMAIL_TEMPLATE_UPSERT_PREP', $mysqli->error, '');
    $_SESSION['email_template_flash']      = t('error.db_save_template');
    $_SESSION['email_template_flash_type'] = 'danger';
    header('Location: /admin/email-templates/edit?key=' . urlencode($key), true, 302);
    exit();
}
$stmt->bind_param('isssss', $siteId, $key, $subject, $bodyHtml, $descr, $tokens);
$stmt->execute();
$stmt->close();

Logger::activity('EmailTemplateUpdated', 'Site override saved for: ' . $key);
$_SESSION['email_template_flash']      = 'Template saved as site override.';
$_SESSION['email_template_flash_type'] = 'success';
header('Location: /admin/email-templates/edit?key=' . urlencode($key), true, 302);
exit();
