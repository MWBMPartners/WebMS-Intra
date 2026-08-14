<?php
// Path: _apps/account/my-prayer-list-save.php
/**
 * -----------------------------------------------------------------------------
 * Account — My Prayer List: partner self-service actions 🙏 (#311)
 * -----------------------------------------------------------------------------
 * POST-only handler for the two things an assigned partner can do to a
 * request from /account/my-prayer-list:
 *
 *   • mark-prayed — stamp partnerLastPrayedAt = NOW()
 *   • save-note   — write the private partnerNote
 *
 * ACL: every UPDATE below is scoped `WHERE requestID = ? AND siteID = ? AND
 * assignedToUserID = ?` (the CURRENT session user). If the request isn't
 * assigned to the caller, `affected_rows` is 0 and we silently redirect —
 * no error is leaked either way, so this can't be used to probe which
 * requestIDs exist or who they're assigned to.
 *
 * @package   Portal\Account
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/311
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /account/my-prayer-list', true, 302);
    exit();
}

if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    Logger::activity('PrayerListActionRejected', 'Invalid CSRF on account/my-prayer-list/save');
    header('Location: /account/my-prayer-list', true, 302);
    exit();
}

$siteId    = Site::id();
$userId    = (int) ($_SESSION['user_id'] ?? 0);
$requestId = (int) ($_POST['requestID'] ?? 0);
$action    = (string) ($_POST['action'] ?? '');

if ($requestId <= 0 || $userId <= 0) {
    header('Location: /account/my-prayer-list', true, 302);
    exit();
}

if ($action === 'mark-prayed') {
    // 🙏 Self-service "I've prayed for this" stamp — no ACL check needed
    //    beyond the WHERE clause itself (assignedToUserID = the caller).
    $stmt = $mysqli->prepare(
        'UPDATE tblPrayerRequests SET partnerLastPrayedAt = NOW() '
        . 'WHERE requestID = ? AND siteID = ? AND assignedToUserID = ? LIMIT 1'
    );
    if ($stmt !== false) {
        $stmt->bind_param('iii', $requestId, $siteId, $userId);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            Logger::activity('PrayerRequestMarkedPrayed', 'Request #' . $requestId . ' marked prayed-for by user #' . $userId);
        }
        $stmt->close();
    }
} elseif ($action === 'save-note') {
    // 🔒 Private partner note (#311). ACL: only the CURRENTLY assigned
    //    partner (assignedToUserID === current user) may write here — the
    //    admin write path is the separate `save-note` action on
    //    prayer-requests/moderate.php, gated by that handler's own
    //    App::isAdmin() check.
    $note = mb_substr(trim((string) ($_POST['partnerNote'] ?? '')), 0, 4000);
    $noteOrNull = $note === '' ? null : $note;

    $stmt = $mysqli->prepare(
        'UPDATE tblPrayerRequests SET partnerNote = ? '
        . 'WHERE requestID = ? AND siteID = ? AND assignedToUserID = ? LIMIT 1'
    );
    if ($stmt !== false) {
        $stmt->bind_param('siii', $noteOrNull, $requestId, $siteId, $userId);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            Logger::activity('PrayerRequestNoteSaved', 'Partner note saved for request #' . $requestId . ' by user #' . $userId);
        }
        $stmt->close();
    }
}

header('Location: /account/my-prayer-list', true, 302);
exit();
