<?php
// Path: public_html/approvals/act.php
/**
 * -----------------------------------------------------------------------------
 * Approvals — Act Handler 🖊️ (#443)
 * -----------------------------------------------------------------------------
 * POST-only, CSRF-first, transport ONLY — every authorisation guard and every
 * state-transition guard lives inside Workflow::act(). This handler's whole
 * job is: verify the session + CSRF token, cast/whitelist the input, call
 * the engine, and translate its result array into a flash message.
 *
 * @package   Portal\Approvals
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/443
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Workflow;

// 🛡️ POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /approvals');
    exit();
}

Auth::requireLogin();

// 🛡️ CSRF first — before any state read.
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $_SESSION['flash_msg']  = 'Invalid or expired form token. Please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /approvals');
    exit();
}

$userId     = (int) ($_SESSION['user_id'] ?? 0);
$instanceId = (int) ($_POST['instanceID'] ?? 0);
$stepId     = (int) ($_POST['stepID'] ?? 0);
$comment    = trim((string) ($_POST['comment'] ?? ''));

$rawDecision = (string) ($_POST['decision'] ?? '');
$decision = in_array($rawDecision, ['approve', 'reject', 'comment'], true) ? $rawDecision : '';

if ($instanceId <= 0 || $stepId <= 0 || $decision === '') {
    $_SESSION['flash_msg']  = 'Invalid request.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /approvals');
    exit();
}

// 🛡️ Reject and comment-only decisions must carry a reason — the approver
// must say why, and an outsider must not write a blank comment row.
if (($decision === 'reject' || $decision === 'comment') && $comment === '') {
    $_SESSION['flash_msg']  = $decision === 'reject'
        ? 'A comment is required when rejecting a request.'
        : 'Enter a comment to add before submitting.';
    $_SESSION['flash_type'] = 'warning';
    header('Location: /approvals');
    exit();
}

$result = Workflow::act($instanceId, $stepId, $userId, $decision, $comment);

if ($result['ok'] === true) {
    if ($decision === 'comment') {
        $_SESSION['flash_msg']  = 'Comment added.';
        $_SESSION['flash_type'] = 'success';
    } elseif ($result['completed'] === true) {
        $_SESSION['flash_msg']  = $result['outcome'] === 'approved'
            ? 'Approved — request fully approved and published.'
            : 'Rejected — the request has been closed.';
        $_SESSION['flash_type'] = $result['outcome'] === 'approved' ? 'success' : 'warning';
    } else {
        $_SESSION['flash_msg']  = ucfirst($decision) . 'd — moved to the next step.';
        $_SESSION['flash_type'] = 'success';
    }
} else {
    $_SESSION['flash_msg'] = match ($result['error']) {
        'not_found'  => 'That approval could not be found.',
        'not_active' => 'This request has already been finalised.',
        'stale_step' => 'This request has moved on since the page loaded — please refresh and try again.',
        'forbidden'  => 'You are not authorised to decide this step.',
        'conflict'   => 'This step has already been decided by someone else.',
        default      => 'Could not record your decision. Please try again.',
    };
    $_SESSION['flash_type'] = $result['error'] === 'conflict' ? 'warning' : 'danger';
}

header('Location: /approvals');
exit();
