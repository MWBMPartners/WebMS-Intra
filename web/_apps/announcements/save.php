<?php
// Path: public_html/announcements/save.php
/**
 * -----------------------------------------------------------------------------
 * Announcements — Save Handler
 * -----------------------------------------------------------------------------
 * Creates or updates an announcement (POST only, admin only).
 *
 * Publish-approval wiring (#443): when `workflows.announcements.enabled` is
 * on for this site AND the poster is requesting a NEW publish (posted
 * isPublished=1 on create, or flipped 0→1 on update), `isPublished` is
 * withheld (forced to 0) and `Workflow::start('announcement_publish', …)` is
 * called instead — the workflow's final approval flips the flag inside its
 * own transaction (Workflow::applySubjectEffect()). Editing an already-
 * published row, or unchecking Published (retract), always passes straight
 * through — no approval needed to edit or unpublish in v1. Flag off ⇒ not
 * one line of this changes; `$isPublished` flows exactly as it always has.
 * Fail-open (#443 decision 4): a gate that's on but has no active/steppable
 * definition NEVER blocks publishing — it publishes directly and logs a
 * platform warning so admins see the misconfiguration in /admin/errors. The
 * actual gate mechanics (already-pending check, `Workflow::start()`, the
 * fail-open publish) live in the shared `_workflow-gate.php` in this same
 * directory — `announcements/api/create.php` and `announcements/api/
 * update.php` require the identical file so the REST write API can never
 * bypass this gate (security review SEC-02/SEC-03).
 *
 * @package   Portal\Announcements
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.9.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/89
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/443
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Settings;
use Portal\Core\Site;

require_once __DIR__ . DIRECTORY_SEPARATOR . '_workflow-gate.php';

// 🛡️ POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /announcements/manage');
    exit();
}

Auth::requireLogin();

if (App::isAdmin() !== true) {
    $_SESSION['flash_msg']  = 'You do not have permission to manage announcements.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /announcements');
    exit();
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /announcements/manage');
    exit();
}

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$announcementId = (int) ($_POST['announcementID'] ?? 0);
$title          = trim((string) ($_POST['title'] ?? ''));
$body           = trim((string) ($_POST['body'] ?? ''));
$priority       = $_POST['priority'] ?? 'normal';
$isPinned       = isset($_POST['isPinned']) === true ? 1 : 0;
$isPublished    = isset($_POST['isPublished']) === true ? 1 : 0;
$publishAt      = trim($_POST['publishAt'] ?? '') !== '' ? $_POST['publishAt'] : null;
$expiresAt      = trim($_POST['expiresAt'] ?? '') !== '' ? $_POST['expiresAt'] : null;

// 🔍 Validate
if ($title === '' || $body === '') {
    $_SESSION['flash_msg']  = 'Title and body are required.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /announcements/manage?edit=' . $announcementId);
    exit();
}

$validPriorities = ['normal', 'important', 'urgent'];
if (in_array($priority, $validPriorities, true) === false) {
    $priority = 'normal';
}

// 📋 Generate slug from title
$slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
if (strlen($slug) > 200) {
    $slug = substr($slug, 0, 200);
}

// 🚦 Workflow gate (#443) — per-site, default off. Read via Settings::get()
// (ambient snapshot is correct here — a normal web request, not a
// forceContext loop).
$workflowGate = (Settings::get('workflows.announcements.enabled', 'false') === 'true');

if ($announcementId > 0) {
    // 📋 Update existing
    // 🔍 Ensure slug is unique (excluding self)
    $chkStmt = $mysqli->prepare(
        'SELECT announcementID FROM tblAnnouncements WHERE slug = ? AND siteID = ? AND announcementID != ? AND isDeleted = 0 LIMIT 1'
    );
    if ($chkStmt !== false) {
        $chkStmt->bind_param('sii', $slug, $siteId, $announcementId);
        $chkStmt->execute();
        if ($chkStmt->get_result()->num_rows > 0) {
            $slug .= '-' . $announcementId;
        }
        $chkStmt->close();
    }

    // 🚦 A "publish request" is: gate on, poster wants Published=1, AND the
    // stored row is currently unpublished (0→1). Already-published rows
    // being edited, and an unpublish (checkbox cleared), pass through
    // completely unchanged — no approval needed to edit or retract in v1.
    $storedPublished = 0;
    $exStmt = $mysqli->prepare('SELECT isPublished FROM tblAnnouncements WHERE announcementID = ? AND siteID = ? LIMIT 1');
    if ($exStmt !== false) {
        $exStmt->bind_param('ii', $announcementId, $siteId);
        $exStmt->execute();
        $exRow = $exStmt->get_result()->fetch_assoc();
        $storedPublished = $exRow !== null ? (int) $exRow['isPublished'] : 0;
        $exStmt->close();
    }
    $isPublishRequest = ($workflowGate === true && $isPublished === 1 && $storedPublished === 0);
    if ($isPublishRequest === true) {
        $isPublished = 0; // withhold until approved
    }

    $stmt = $mysqli->prepare(
        'UPDATE tblAnnouncements SET title = ?, slug = ?, body = ?, priority = ?, '
        . 'isPinned = ?, isPublished = ?, publishAt = ?, expiresAt = ?, updatedByID = ? '
        . 'WHERE announcementID = ? AND siteID = ?'
    );
    if ($stmt !== false) {
        $stmt->bind_param(
            'ssssiisssii',
            $title, $slug, $body, $priority,
            $isPinned, $isPublished, $publishAt, $expiresAt, $userId,
            $announcementId, $siteId
        );
        $stmt->execute();
        $stmt->close();
    }

    Logger::activity('AnnouncementUpdated', 'Updated announcement: ' . $title, $userId);

    if ($isPublishRequest === true) {
        announcementsHandlePublishRequest($mysqli, $siteId, $announcementId, $userId, $title, $slug, 'updated');
    } else {
        $_SESSION['flash_msg']  = 'Announcement updated.';
        $_SESSION['flash_type'] = 'success';
    }
} else {
    // 📋 Create new
    // 🔍 Ensure slug is unique
    $chkStmt = $mysqli->prepare(
        'SELECT announcementID FROM tblAnnouncements WHERE slug = ? AND siteID = ? AND isDeleted = 0 LIMIT 1'
    );
    if ($chkStmt !== false) {
        $chkStmt->bind_param('si', $slug, $siteId);
        $chkStmt->execute();
        if ($chkStmt->get_result()->num_rows > 0) {
            $slug .= '-' . time();
        }
        $chkStmt->close();
    }

    // 🚦 A create-flow publish request is simply: gate on AND Published
    // ticked. Force isPublished=0 before the INSERT so a workflow-gated
    // site can never self-publish past the approval step.
    $isPublishRequest = ($workflowGate === true && $isPublished === 1);
    if ($isPublishRequest === true) {
        $isPublished = 0;
    }

    $stmt = $mysqli->prepare(
        'INSERT INTO tblAnnouncements (siteID, title, slug, body, priority, isPinned, isPublished, publishAt, expiresAt, createdByID) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if ($stmt !== false) {
        $stmt->bind_param(
            'issssiissi',
            $siteId, $title, $slug, $body, $priority,
            $isPinned, $isPublished, $publishAt, $expiresAt, $userId
        );
        $stmt->execute();
        $announcementId = (int) $mysqli->insert_id;
        $stmt->close();
    }

    Logger::activity('AnnouncementCreated', 'Created announcement: ' . $title, $userId);

    if ($isPublishRequest === true) {
        announcementsHandlePublishRequest($mysqli, $siteId, $announcementId, $userId, $title, $slug, 'created');
    } else {
        $_SESSION['flash_msg']  = 'Announcement created.';
        $_SESSION['flash_type'] = 'success';
    }
}

header('Location: /announcements/manage');
exit();

/**
 * Shared publish-request tail for both the create and update branches
 * above — delegates the actual gate mechanics to the shared
 * `announcements_workflow_gate_publish()` helper (`_workflow-gate.php`,
 * same directory) and translates its result into a flash message.
 */
function announcementsHandlePublishRequest(
    \mysqli $mysqli,
    int $siteId,
    int $announcementId,
    int $userId,
    string $title,
    string $slug,
    string $verb
): void {
    $gate = announcements_workflow_gate_publish($mysqli, $siteId, $announcementId, $userId, $title, $slug, $verb);
    $_SESSION['flash_msg']  = $gate['message'];
    $_SESSION['flash_type'] = $gate['flashType'];
}
