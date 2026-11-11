<?php
// Path: _apps/admin/calendar/moderate.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Calendar Submission Moderation Action Handler 🛡️ (#326)
 * -----------------------------------------------------------------------------
 * POST endpoint. Approve = status='published', submissionStatus='approved',
 * and isPublic LEFT AS IT IS — the submission form stores 0, so an approved
 * suggestion is for members only until an administrator ticks "Public" on the
 * event (D-092, following D-079: approving used to set isPublic=1 too, which
 * made it public with nobody ever seeing a "Public" choice). Reject =
 * status='draft', isPublic=0, submissionStatus='rejected'. Stamps moderatedBy/At.
 *
 * ✉️ APPROVING E-MAILS THE PERSON WHO SUGGESTED IT (#859, D-134), at the
 *    address they gave on the public form, through
 *    `EventSuggestions::tellSuggesterApproved()`. Rejecting sends nothing.
 *    Three things decide how it is done, and each is load-bearing:
 *    - ONCE ONLY. The approval is ONE conditional statement (`WHERE ...
 *      submissionStatus = "pending"`), so of two presses, a double click or a
 *      stale page only the request that really changed the row is told
 *      "1 row", and only that one sends. A second e-mail would need the row
 *      to go back to `pending`, which nothing in the portal does.
 *    - AFTER THE APPROVAL IS STORED. Nothing here opens a transaction, so the
 *      statement is its own and is stored when `execute()` returns. The send
 *      comes after it, so a broken or missing mail route can never undo or
 *      block an approval.
 *    - NEVER THROWS. The send is in its own try/catch inside that method: the
 *      Microsoft 365 route throws when it has no sender, which is how this
 *      portal ships.
 *
 * ⚠️ This used to say "Reject = status='archived'", which was never true: the
 *    code has always written 'draft' (see the UPDATE below), and the column has
 *    never had an 'archived' value at all, so had it done what the comment
 *    said, every rejection would have been a 500. Corrected under #676.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/326
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\EventSuggestions;
use Portal\Core\Logger;
use Portal\Core\Site;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . Site::url('admin/calendar/moderation'), true, 302);
    exit();
}

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    Logger::activity('EventModerateRejected', 'Invalid CSRF on /admin/calendar/moderate');
    header('Location: ' . Site::url('admin/calendar/moderation'), true, 302);
    exit();
}

$eventId = (int) ($_POST['eventID'] ?? 0);
$action  = (string) ($_POST['action'] ?? '');
$modId   = (int) ($_SESSION['user_id'] ?? 0);
$siteId  = Site::id();

if ($eventId <= 0 || in_array($action, ['approve', 'reject'], true) === false) {
    header('Location: ' . Site::url('admin/calendar/moderation'), true, 302);
    exit();
}

if ($action === 'approve') {
    $stmt = $mysqli->prepare(
        'UPDATE tblEvents SET status = "published", '
        . '    submissionStatus = "approved", moderatedByID = ?, moderatedAt = NOW() '
        . 'WHERE eventID = ? AND siteID = ? AND submissionStatus = "pending"'
    );
} else {
    $stmt = $mysqli->prepare(
        'UPDATE tblEvents SET status = "draft", isPublic = 0, '
        . '    submissionStatus = "rejected", moderatedByID = ?, moderatedAt = NOW() '
        . 'WHERE eventID = ? AND siteID = ? AND submissionStatus = "pending"'
    );
}

if ($stmt !== false) {
    $stmt->bind_param('iii', $modId, $eventId, $siteId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected > 0) {
        Logger::activity('EventModerated', 'Event #' . $eventId . ' action=' . $action);

        // ✉️ D-134: tell the person who suggested it, ONLY here, inside "this
        //    request changed the row", which is what makes it once only. The
        //    page says what became of the e-mail; it never names the address.
        if ($action === 'approve') {
            $told = EventSuggestions::tellSuggesterApproved($mysqli, $eventId, $siteId);
            $_SESSION['flash_msg']  = EventSuggestions::approvedWords($told);
            // A warning, not a green tick, when the promised e-mail did not go.
            $_SESSION['flash_type'] = $told === EventSuggestions::TOLD_NOT_SENT ? 'warning' : 'success';
        } else {
            $_SESSION['flash_msg']  = 'Rejected. Nothing was sent to the person who suggested it.';
            $_SESSION['flash_type'] = 'info';
        }

        // 🪝 Webhook on moderation (#324).
        if (class_exists(\Portal\Core\WebhookDispatcher::class) === true) {
            \Portal\Core\WebhookDispatcher::emit(
                'calendar.event.' . ($action === 'approve' ? 'approved' : 'rejected'),
                ['eventID' => $eventId]
            );
        }
    }
}

header('Location: ' . Site::url('admin/calendar/moderation') . '?status=pending', true, 302);
exit();
