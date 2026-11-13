<?php
// Path: _apps/expenses/view/claim-pdf.php
/**
 * -----------------------------------------------------------------------------
 * Expenses — open a claim's generated PDF 📄 (#954)
 * -----------------------------------------------------------------------------
 * Reached at `expenses/claim-pdf?claim=<claim number>` (registered in
 * `tblRoutes`, migration 290). Sends the claim's own generated PDF as a file
 * to save. Nothing else.
 *
 * WHY IT EXISTS. The claim page listed the generated PDF by name with no link,
 * and nothing in the portal served it: it is kept in `_uploads/pdfs`, outside
 * the web root, so the web server cannot hand it out either. #867 did the
 * same for receipts (`expenses/receipt`, `receipt.php` beside this file), and
 * this page is built the same way on purpose.
 *
 * WHO MAY OPEN IT: EXACTLY WHO MAY OPEN THE CLAIM. It asks
 * `ExpenseRules::reachToSee()` and `ExpenseRules::mayViewClaim()`, the same
 * two calls as the claim page and the receipt address, and holds no rule of
 * its own. Do not put a role or department test here.
 *
 * REFUSED IS ANSWERED EXACTLY AS NOT THERE: the same 404, from the same call,
 * after the same database work (the reach is worked out BEFORE anything is
 * looked up). So is a claim of another organisation, a claim with no PDF, a
 * claim whose stored name is not the one the portal writes for it, and a
 * file that is missing or not inside the PDF folder.
 *
 * WHICH VERSION. Every stage of a claim (Pending, Approved, Not Approved,
 * Complete) overwrites ONE file, so only the newest version exists. The
 * address therefore takes the claim number alone — never a stage or a row
 * number — and anybody following it, including from a link saved while the
 * claim was at an earlier stage, gets the NEWEST version, named after the
 * newest stage that made it (`Claim_<n>_<Stage>.pdf`). Older versions cannot
 * be opened because they are not kept; the claim page says so beside them.
 * A withdrawn claim keeps the version last made before it was withdrawn
 * (withdrawing does not remake it, #587).
 *
 * NO NAME FROM THE REQUEST REACHES THE FILE SYSTEM. The path is
 * `ExpensePdf::pathFor()` of the claim number, checked by
 * `ExpenseRules::claimPdfFile()`.
 *
 * THE BYTES GO OUT THROUGH `ByteRange::send()` (#880), the ONE place a
 * `Range:` request is answered, and only after every check above has passed.
 *
 * A PDF THAT IS SERVED IS RECORDED (D-130): one `ExpenseClaimPdfOpen` record
 * naming the person, carrying the claim NUMBER only. A refusal records
 * nothing (D-132's rule, and the existence-leak rule: "not yours" and "not
 * there" must leave the same trace). A PDF that is allowed but cannot be
 * served writes an error record instead.
 *
 * A read-only GET, so it asks for no form token.
 *
 * @package   Portal\Expenses
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/954
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\ByteRange;
use Portal\Core\ExpenseRules;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

// 🛡️ Signed in, or sent to sign in. The address is also protected in
//    `tblRoutes`, so this is the second guard.
Auth::ensureSession();
if (Auth::check() === false) {
    Auth::requireLogin();
    return;
}

// 🔢 A plain positive number, or nothing. The TEXT must be digits: `(int)`
//    would read `claim[]=1` as 1 and `1abc` as 1 (#642).
$raw     = $_GET['claim'] ?? null;
$claimID = 0;
if (is_string($raw) === true && preg_match('/^[1-9][0-9]{0,9}$/D', $raw) === 1 && (int) $raw <= 2147483647) {
    $claimID = (int) $raw;
}
if ($claimID === 0) {
    Router::renderError(404);
    return;
}

// 👁️ Whose claims this person may open — FIRST, before anything is looked
//    up, so a "no" costs the same database work as a made-up number.
$siteId        = Site::id();
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$reach         = ExpenseRules::reachToSee($mysqli, $currentUserId, $siteId);

// 📋 The claim, its owner and department, and the NEWEST stage row naming the
//    claim's stored PDF, in ONE statement. No such row means the portal never
//    made a PDF for this claim (or the record of it is gone): not found.
$row  = null;
$stmt = $mysqli->prepare(
    'SELECT `EC`.`userID` AS `claimantID`, `EC`.`deptID` AS `claimDeptID`, `EC`.`fileName`, '
    . '`F`.`fileID`, `F`.`fileLabel` '
    . 'FROM `tblExpenseClaims` `EC` '
    . 'JOIN `tblExpenseClaimFiles` `F` ON `F`.`claimID` = `EC`.`claimID` '
    . 'AND `F`.`storedFilename` = `EC`.`fileName` AND `F`.`stage` IS NOT NULL AND `F`.`stage` <> \'\' '
    . 'WHERE `EC`.`claimID` = ? AND `EC`.`siteID` = ? '
    . 'ORDER BY `F`.`fileID` DESC LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $claimID, $siteId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// 🛡️ No such PDF, and one this person may not open, get the SAME answer.
if ($row === null
    || ExpenseRules::mayViewClaim($reach, (int) $row['claimantID'], (int) $row['claimDeptID'], $currentUserId) === false) {
    Router::renderError(404);
    return;
}

// 📁 The file, from the claim number only, and only from inside the PDF
//    folder. Somebody allowed to see the claim is told the same "not found"
//    when it cannot be served, and the fault is recorded (numbers only).
$path = ExpenseRules::claimPdfFile($claimID, (string) ($row['fileName'] ?? ''));
if ($path === null) {
    Logger::errorPlatform(
        'Expenses',
        'Error',
        'CLAIM_PDF_UNAVAILABLE',
        'A claim\'s generated PDF could not be opened: its file is missing, or is not the file the portal writes for that claim',
        'Claim #' . $claimID . '. Check the PDF folder (_uploads/pdfs).'
    );
    Router::renderError(404);
    return;
}

// 📝 D-130: the look is recorded once it is decided the file WILL be sent,
//    before the session is released and before any header goes out. The
//    claim number is in the WORDS, not the log's `claimID` column (which has
//    no ON DELETE rule and would stop the API removing the claim).
Logger::activity('ExpenseClaimPdfOpen', 'Opened the generated PDF of expense claim #' . $claimID);

// 🔓 Let the session go before the file is sent.
session_write_close();

// 📤 The headers, then the bytes. `header()` replaces start-up's own type and
//    page rule. The type is always PDF: the portal wrote this file itself.
$label = (string) ($row['fileLabel'] ?? '');
header('Content-Type: application/pdf');
header('Content-Disposition: ' . ExpenseRules::receiptDisposition(
    $label !== '' ? $label : 'Claim_' . $claimID . '.pdf',
    (int) $row['fileID'],
    (string) $row['fileName']
));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; sandbox");
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
if (ByteRange::send($path) === false) {
    // `send()` sent nothing, so the ordinary "not found" can still be drawn,
    // without the headers set above for a file that is not coming.
    header_remove('Content-Type');
    header_remove('Content-Disposition');
    header_remove('Content-Security-Policy');
    Logger::errorPlatform(
        'Expenses',
        'Error',
        'CLAIM_PDF_READ_FAILED',
        'A claim\'s generated PDF could not be read while it was being sent',
        'Claim #' . $claimID . '.'
    );
    Router::renderError(404);
    return;
}
exit();
