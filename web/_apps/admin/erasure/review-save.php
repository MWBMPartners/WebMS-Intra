<?php
// Path: _apps/admin/erasure/review-save.php
/**
 * Admin — record one decision about a line of free text an erasure cannot
 * decide about on its own (#724).
 *
 * One of two answers: empty it, or keep it. There is no third, and there is no
 * box to type a reason in — #710's reason, in a new place: asking somebody to
 * describe what they did invites them to quote the words being erased into
 * `tblErasureAudit`, which is deliberately absent from the personal-data
 * catalogue (#510 rule 7d) and is never erased.
 *
 * This handler works nothing out. `ErasureReview::record()` is the ONE place a
 * decision is recorded and the ONE thing that acts on one: it checks the line
 * is still a review item, holds the row, empties the cell if that is the
 * answer, READS IT BACK, and writes the decision row and the audit line in one
 * transaction. So this file's whole job is the gate, the form token, and saying
 * what happened.
 *
 * ⚠️ THE GATE ASKS THE CAPABILITY REGISTRY (#727), not `App::hasRole()`. See
 *    the long note at the top of `review.php`: `App::hasRole()` answers true
 *    for any global administrator with no role row (#516), which would let a
 *    hosting provider's administrator do exactly what the role exists to stop.
 *    What keeps that out is one word in `Capabilities::CAPABILITIES` — the
 *    `erasure.review` entry's `globalAdminFallsOpen => false` — and not
 *    anything in this file. Do not "fix" it back for consistency with the
 *    other role gates. AND IT MUST BE ON BOTH FILES — a gate on the page that
 *    draws the buttons is not a gate at all, because anything can be posted
 *    (#672's two-gates lesson).
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/724
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Capabilities;
use Portal\Core\ErasureReview;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$siteId = Site::id();
$meId   = (int) ($_SESSION['user_id'] ?? 0);

// 🛡️ The same gate as the page, and for the same reason.
if (App::can(Capabilities::ERASURE_REVIEW) === false) {
    Router::renderError(403);
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$requestId = (int) ($_POST['requestID'] ?? 0);
$table     = (string) ($_POST['tableName'] ?? '');
$column    = (string) ($_POST['columnName'] ?? '');
$rowKey    = (int) ($_POST['rowKey'] ?? 0);
$decision  = (string) ($_POST['decision'] ?? '');

$back = Site::url(ErasureReview::PAGE_ROUTE) . '?id=' . (string) $requestId;

$say = static function (string $message, string $type) use ($back): void {
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . $back);
    exit();
};

// The request has to be THIS organisation's, and has to still name an account.
// Read here rather than taken from the form, because the account number is what
// every look-up matches on.
//
// ⚠️ THE `AND siteID = ?` IS A GATE AND NOTHING GUARDED IT until 3 November
//    2026. An independent check removed it from this statement and from
//    `review.php`'s, and removed the `Auth::verifyCsrf()` call above, in one
//    edit: all 52 audit scripts exited 0, `tools/erasure-review-selftest.php`
//    passed 91 of 91, and `php -l` was clean. Without it a data controller of
//    one organisation could read and empty another organisation's members'
//    own words. `$siteId` is `Site::id()` and never anything posted. Section I
//    of the self-test now pins this statement, its bind, and the form-token
//    test, each as a WHOLE condition with comments stripped by the tokeniser —
//    a text search for a call name is satisfied by `&& false` beside it.
$row = null;
$stmt = $db->prepare(
    'SELECT `requestID`, `userID` FROM `tblErasureRequest` WHERE `requestID` = ? AND `siteID` = ? LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $requestId, $siteId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($row === null) {
    $_SESSION['flash_msg']  = 'That erasure request is not one of this organisation\'s. Nothing was recorded.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Site::url(ErasureReview::PAGE_ROUTE));
    exit();
}
$userId = (int) ($row['userID'] ?? 0);
if ($userId === 0) {
    $say(
        'That request no longer names an account, so there is nothing to look up or decide about. Nothing was '
        . 'recorded.',
        'info'
    );
}

// 🪪 THE POSTED TABLE AND COLUMN ARE NEVER TRUSTED, and they are not merely
//    escaped either: `ErasureReview::record()` refuses anything that is not one
//    of the lines it just worked out for this request from the catalogue. That
//    is what makes the names safe to put in a statement, the same reasoning as
//    `DbBackup::CASCADE_RESCUE` (#694). The length test here is only so that a
//    silly post is turned away before any work is done.
if (strlen($table) > 64 || strlen($column) > 64) {
    $say('That is not a line this review knows about. Nothing was recorded.', 'danger');
}

$problem = ErasureReview::record($db, $requestId, $userId, $table, $column, $rowKey, $decision, $meId);

if ($problem !== '') {
    // 📏 The refusal is shown as it is given. It names the table, the column
    //    and the row and never the text, because `ErasureReview` composes it
    //    from names and counts only.
    Logger::activity(
        'GdprErasureReviewRefused',
        'A decision about free text on erasure request #' . (string) $requestId . ' was refused',
        $meId
    );
    $say($problem, 'warning');
}

// ✅ Recorded. The activity log says WHICH line and WHAT was decided, and no
//    more: the text is not written here any more than it is written to the
//    request's own report.
Logger::activity(
    'GdprErasureReviewDecided',
    'Erasure request #' . (string) $requestId . ': `' . $table . '`.`' . $column . '` on row '
    . (string) $rowKey . ' was reviewed by hand and '
    . ($decision === ErasureReview::DECISION_EMPTY ? 'EMPTIED' : 'KEPT'),
    $meId
);

$say(
    $decision === ErasureReview::DECISION_EMPTY
        ? 'That line is emptied, and the request\'s report records that you emptied it. The text is not written '
            . 'down anywhere.'
        : 'That line is recorded as kept, and nothing in the row was changed. The request can be completed once '
            . 'every such line has been decided about.',
    'success'
);
