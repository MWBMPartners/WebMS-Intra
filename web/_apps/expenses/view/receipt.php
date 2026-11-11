<?php
// Path: _apps/expenses/view/receipt.php
/**
 * -----------------------------------------------------------------------------
 * Expenses — open one receipt of a claim 📥 (#867)
 * -----------------------------------------------------------------------------
 * Reached at `expenses/receipt?claim=<claim number>&id=<receipt number>`
 * (registered in `tblRoutes`, migration 278). Sends the receipt as a file to
 * save. Nothing else.
 *
 * WHY IT EXISTS. Receipts are kept in `_uploads/expenses`, outside the web
 * root on purpose, so the web server cannot hand one out by itself — and until
 * #867 nothing in the portal did either. The claim page listed each receipt's
 * label, kind and size with no link, so an approver deciding a claim, and a
 * treasurer paying one, could not look at the evidence they were deciding or
 * paying against.
 *
 * WHO MAY OPEN ONE: EXACTLY WHO MAY OPEN THE CLAIM. This page asks the same
 * two calls the claim page (`expenses/view/index.php`) asks —
 * `ExpenseRules::reachToSee()` and `ExpenseRules::mayViewClaim()` — and holds
 * no rule of its own: the claimant; a lead, approver or required approver of
 * the claim's own department; a treasurer; an administrator (#648, D-103).
 * Do not put a role or department test here.
 *
 * A RECEIPT SOMEBODY MAY NOT OPEN IS ANSWERED EXACTLY AS ONE THAT DOES NOT
 * EXIST: the same 404, from the same call, after the same database work — the
 * reach is worked out BEFORE anything is looked up, exactly as on the claim
 * page — so nobody learns from the answer that a claim or a receipt is real.
 * So is a receipt number that belongs to a DIFFERENT claim from the one named,
 * a receipt of another organisation, the claim's own generated PDF (which is
 * not a receipt — see `ExpenseRules`, "Opening a receipt"), a number that is
 * not a plain positive number, and a stored file that is missing or whose
 * stored name or real location is not inside the receipts folder.
 *
 * NO NAME FROM THE REQUEST REACHES THE FILE SYSTEM. The address carries two
 * numbers. The path is built from the STORED name by
 * `ExpenseRules::receiptFile()`, which refuses anything that is not a plain
 * file name and anything that, with links followed, is not directly inside the
 * receipts folder.
 *
 * WHAT IT SENDS. The stored type (the server worked it out from the file's
 * contents at upload), `Content-Disposition: attachment` with the name encoded
 * as RFC 6266 and RFC 5987 require (`ExpenseRules::receiptDisposition()`),
 * `X-Content-Type-Options: nosniff`, a page rule that sandboxes the answer,
 * and `Cache-Control: no-store, private` — a receipt is personal financial
 * information and must not be kept by a shared cache or by the portal's own
 * offline copy.
 *
 * A RECEIPT THAT IS SERVED IS RECORDED IN THE ACTIVITY LOG (D-130, the owner,
 * 11 November 2026: all looking at expense records is recorded — the claim
 * page, the spreadsheet and a receipt). One `ExpenseReceiptOpen` record, naming
 * its person (`Logger::activity()` fills `userID` from the session, #626) and
 * carrying the receipt and claim NUMBERS only — never a file name, a label or
 * an amount. It is written once it is decided the file WILL be sent and before
 * a single header goes out, so it can never interrupt a download, and
 * `Logger::activity()` never throws. A REFUSAL IS NOT RECORDED, and neither is
 * a made-up number, so the two still leave the same trace (none): recording a
 * refusal would make "not yours" and "not there" differ in the log, which is
 * the existence leak the 404 exists to prevent. A receipt that is allowed but
 * cannot be served writes an error record instead, not a look.
 *
 * A read-only GET, so it asks for no form token (the house rule for a
 * read-only GET; `giving/statements-download.php` is the same).
 *
 * WHAT IT CANNOT DO: it does not serve the claim's generated PDF, and it does
 * not let anybody add or replace a receipt.
 *
 * @package   Portal\Expenses
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/867
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\ExpenseRules;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

// 🛡️ Signed in, or sent to sign in — the claim page's own first step. The
//    address is also protected in `tblRoutes`, so this is the second guard.
Auth::ensureSession();
if (Auth::check() === false) {
    Auth::requireLogin();
    return;
}

// 🔢 Two plain positive numbers, or nothing at all. A request chooses the TYPE
//    of what it sends as well as the value (#642): `(int)` would read
//    `id[]=1` as 1 and `1abc` as 1, so the text itself must be digits.
$number = static function (mixed $raw): int {
    if (is_string($raw) === false || preg_match('/^[1-9][0-9]{0,9}$/D', $raw) !== 1) {
        return 0;
    }
    $value = (int) $raw;

    return $value <= 2147483647 ? $value : 0;
};
$claimID = $number($_GET['claim'] ?? null);
$fileID  = $number($_GET['id'] ?? null);
if ($claimID === 0 || $fileID === 0) {
    Router::renderError(404);
    return;
}

// 👁️ Whose claims this person may open — worked out FIRST, before anything is
//    looked up, as the claim page does, so a "no" costs the same database
//    work whether the numbers are real or made up.
$siteId        = Site::id();
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$reach         = ExpenseRules::reachToSee($mysqli, $currentUserId, $siteId);

// 📋 The receipt, its claim and the claim's owner and department, in ONE
//    statement. The receipt must belong to the claim the address names, the
//    claim to this organisation, and the row must be a receipt (no stage).
$row  = null;
$stmt = $mysqli->prepare(
    'SELECT `F`.`fileID`, `F`.`fileLabel`, `F`.`storedFilename`, `F`.`fileType`, '
    . '`EC`.`userID` AS `claimantID`, `EC`.`deptID` AS `claimDeptID` '
    . 'FROM `tblExpenseClaimFiles` `F` '
    . 'JOIN `tblExpenseClaims` `EC` ON `EC`.`claimID` = `F`.`claimID` '
    . 'WHERE `F`.`fileID` = ? AND `F`.`claimID` = ? AND `EC`.`siteID` = ? AND `F`.`stage` IS NULL '
    . 'LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('iii', $fileID, $claimID, $siteId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// 🛡️ No such receipt, and a receipt this person may not open, get the SAME
//    answer. Ownership before anything else is said about a claim.
if ($row === null
    || ExpenseRules::mayViewClaim($reach, (int) $row['claimantID'], (int) $row['claimDeptID'], $currentUserId) === false) {
    Router::renderError(404);
    return;
}

// 📁 The file itself, from the STORED name only, and only from inside the
//    receipts folder. Somebody allowed to see this claim is told the same
//    "not found" as everybody else when the file cannot be served, and the
//    fault is recorded for an administrator: a receipt row with no usable
//    file is lost evidence. Only the numbers are recorded — never the stored
//    name, which before #714 could carry the uploader's own file name.
$path = ExpenseRules::receiptFile((string) ($row['storedFilename'] ?? ''));
if ($path === null) {
    Logger::errorPlatform(
        'Expenses',
        'Error',
        'RECEIPT_UNAVAILABLE',
        'A receipt could not be opened: its file is missing, or is not a plain file inside the receipts folder',
        'Claim #' . $claimID . ', receipt #' . $fileID . '. Check the receipts folder (_uploads/expenses).'
    );
    Router::renderError(404);
    return;
}
$size = filesize($path);

// 📝 D-130: a look at an expense record is recorded. Here, because it is now
//    decided the file WILL be sent, and before the session is released and
//    before any header goes out — `Logger::activity()` reads the session to
//    name the person, and nothing it does can then interrupt the download (it
//    catches everything itself). Numbers only: the label and the stored name
//    are never written here (before #714 either could hold the uploader's own
//    file name). The claim number goes in the WORDS, not in the log's
//    `claimID` column: that column carries a link to `tblExpenseClaims` with
//    no ON DELETE rule, so filling it would stop the API's delete from ever
//    removing a claim somebody had looked at.
Logger::activity('ExpenseReceiptOpen', 'Opened receipt #' . $fileID . ' of expense claim #' . $claimID);

// 🔓 Let the session go before the file is sent: a 10 MB receipt on a slow
//    connection must not hold every other tab of this person waiting.
session_write_close();

// 🧹 Anything start-up queued in an output buffer is not part of a file.
while (ob_get_level() > 0) {
    ob_end_clean();
}

// 📤 The headers. `header()` replaces any earlier header of the same name, so
//    start-up's own page rule and type do not survive.
header('Content-Type: ' . ExpenseRules::receiptContentType($row['fileType'] ?? null));
header('Content-Disposition: ' . ExpenseRules::receiptDisposition(
    (string) ($row['fileLabel'] ?? ''),
    (int) $row['fileID'],
    (string) ($row['storedFilename'] ?? '')
));
header('X-Content-Type-Options: nosniff');
// The same rule the public door uses for anything it hands out
// (`PublicDoor::DATA_CSP`): nothing may load, nothing may frame it, and a
// browser that shows it anyway treats it as a sandbox with no script.
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; sandbox");
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
if ($size !== false) {
    header('Content-Length: ' . $size);
}

if (readfile($path) === false) {
    // The headers are gone, so the answer cannot be changed now; the record is
    // what an administrator has.
    Logger::errorPlatform(
        'Expenses',
        'Error',
        'RECEIPT_READ_FAILED',
        'A receipt could not be read while it was being sent',
        'Claim #' . $claimID . ', receipt #' . $fileID . '.'
    );
}
exit();
