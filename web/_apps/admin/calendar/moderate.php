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
        'UPDATE `tblEvents` SET `status` = "published", '
        . '    `submissionStatus` = "approved", `moderatedByID` = ?, `moderatedAt` = NOW() '
        . 'WHERE `eventID` = ? AND `siteID` = ? AND `submissionStatus` = "pending" AND `isDeleted` = 0'
    );
} else {
    $stmt = $mysqli->prepare(
        'UPDATE `tblEvents` SET `status` = "draft", `isPublic` = 0, '
        . '    `submissionStatus` = "rejected", `moderatedByID` = ?, `moderatedAt` = NOW() '
        . 'WHERE `eventID` = ? AND `siteID` = ? AND `submissionStatus` = "pending" AND `isDeleted` = 0'
    );
}
// 🗑️ `isDeleted = 0` on BOTH (round 1 of #859's check). A pending suggestion
//    can be deleted on Manage Events, and this statement used to match it all
//    the same: an Approve then published a row nobody could see and e-mailed
//    the suggester that it was "now on their calendar". A deleted suggestion
//    is no longer moderated at all; the moderation list leaves it out too.

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
        //    The result has a session key of its OWN (`moderation_result`), so
        //    the moderation page never shows a message another page left.
        if ($action === 'approve') {
            $told = EventSuggestions::tellSuggesterApproved($mysqli, $eventId, $siteId);
            // A warning, not a green tick, when the promised e-mail did not go.
            $_SESSION['moderation_result'] = [
                'type'  => $told === EventSuggestions::TOLD_NOT_SENT ? 'warning' : 'success',
                'words' => EventSuggestions::approvedWords($told),
            ];
        } else {
            $_SESSION['moderation_result'] = ['type' => 'info', 'words' => 'Rejected. Nothing was sent to the person who suggested it.'];
        }

        // 🪝 Webhook on moderation (#324).
        if (class_exists(\Portal\Core\WebhookDispatcher::class) === true) {
            \Portal\Core\WebhookDispatcher::emit(
                'calendar.event.' . ($action === 'approve' ? 'approved' : 'rejected'),
                ['eventID' => $eventId]
            );
        }
    } else {
        // 🔁 Nothing changed: decided already (a second press, a stale page,
        //    another administrator first) or deleted. Nothing is sent.
        $_SESSION['moderation_result'] = ['type' => 'info',
            'words' => 'Nothing changed: that suggestion has already been decided, or has been deleted. Nobody was e-mailed.'];
    }
}

header('Location: ' . Site::url('admin/calendar/moderation') . '?status=pending', true, 302);
exit();
