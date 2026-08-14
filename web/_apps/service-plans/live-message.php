<?php
// Path: _apps/service-plans/live-message.php
/**
 * -----------------------------------------------------------------------------
 * Service Plans — Operator → Confidence-Monitor Message Send/Clear (#300 v2) 💬
 * -----------------------------------------------------------------------------
 * POST endpoint. Operator (admin) sends a short cue that shows up on the
 * confidence monitor (`/service-plans/confidence`) within one poll cycle, or
 * clears the currently-active message. Plain form POST + 303 redirect —
 * not AJAX — matching this app's only existing submit idiom (live-toggle.php).
 *
 * action=send:  inserts a new active message (rejected once the plan is
 *               closed — read-only after close).
 * action=clear: flips every active message for the plan to cleared (allowed
 *               even after close — operator tidying).
 *
 * Messages are never DELETEd — `isCleared` + `clearedAt` keep the full
 * history as part of the service's audit record.
 *
 * @package   Portal\ServicePlans
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/300
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

// 🚫 POST only.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

// 🔐 Operator gate — admin only, identical to live.php/live-toggle.php.
Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

// 🛡️ CSRF check.
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    Logger::activity('ServicePlanMessageRejected', 'Invalid CSRF on live-message');
    http_response_code(400);
    exit('Bad request');
}

$planId = (int) ($_POST['planID'] ?? 0);
$action = (string) ($_POST['action'] ?? '');
$siteId = Site::id();

if (in_array($action, ['send', 'clear'], true) === false) {
    http_response_code(400);
    exit('Invalid action');
}

// 🔎 Plan ownership / site-scope check — belt and braces alongside every
// query below also carrying siteID = ?.
$plan = null;
$stmt = $mysqli->prepare('SELECT planID, closedAt FROM tblServicePlan WHERE planID = ? AND siteID = ?');
if ($stmt !== false) {
    $stmt->bind_param('ii', $planId, $siteId);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

if ($plan === null) {
    http_response_code(404);
    exit('Plan not found');
}

$isClosed = $plan['closedAt'] !== null;

if ($action === 'send') {
    // ✉️ Trim + cap; silently no-op on empty body or a closed plan —
    // matches live-toggle's idempotent-no-op style.
    $body = trim((string) ($_POST['body'] ?? ''));
    $body = mb_substr($body, 0, 255);

    if ($body !== '' && $isClosed === false) {
        $user        = App::user();
        $createdById = (int) ($user['userID'] ?? 0);
        $createdBy   = $createdById > 0 ? $createdById : null;

        $stmt = $mysqli->prepare(
            'INSERT INTO tblServicePlanMessages (planID, siteID, body, createdByID) VALUES (?, ?, ?, ?)'
        );
        if ($stmt !== false) {
            $stmt->bind_param('iisi', $planId, $siteId, $body, $createdBy);
            $stmt->execute();
            $stmt->close();
            Logger::activity('ServicePlanMessageSend', 'Plan #' . $planId);
        }
    }
} else {
    // 🧹 Clear all active messages for the plan — the monitor shows only
    // the latest, so clearing all is the only non-confusing semantic.
    // Allowed even after close (operator tidying).
    $stmt = $mysqli->prepare(
        'UPDATE tblServicePlanMessages SET isCleared = 1, clearedAt = NOW() '
        . 'WHERE planID = ? AND siteID = ? AND isCleared = 0'
    );
    if ($stmt !== false) {
        $stmt->bind_param('ii', $planId, $siteId);
        $stmt->execute();
        $stmt->close();
        Logger::activity('ServicePlanMessageClear', 'Plan #' . $planId);
    }
}

header('Location: /service-plans/live?id=' . $planId, true, 303);
exit();
