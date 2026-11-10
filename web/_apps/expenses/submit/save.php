<?php
// Path: public_html/expenses/submit/save.php
/**
 * -----------------------------------------------------------------------------
 * Expenses — Claim Submission Save Handler 💾
 * -----------------------------------------------------------------------------
 * Handles new expense claim submission: inserts DB rows, uploads files, creates
 * a "Pending" PDF via ExpensePdf helper, sends email notification to approvers,
 * logs activity, and redirects.
 *
 * A RECEIPT THAT IS NOT ATTACHED IS SAID OUT LOUD (#649, 9 November 2026)
 * -------------------------------------------------------------------------
 * Until then a file that was too big, of the wrong kind, refused by the server
 * before PHP saw it, or that could not be moved into the receipts folder was
 * skipped with a bare `continue`; the claim was saved anyway and the claimant
 * sent on as though every receipt had gone. Now:
 *
 *  - each file is judged by `ExpenseRules::whyReceiptRefused()` (the ONE rule);
 *  - a file that is not attached does NOT stop the claim: the claim is saved
 *    and the claimant is told, by the file's own name, which were not attached
 *    and why (`ExpenseRules::submittedMessage()`, the ONE place the words are);
 *  - the ONE case that refuses the whole claim is the portal being unable to
 *    make its receipts folder, checked BEFORE anything is written;
 *  - files PHP DROPS for being past `max_file_uploads` are reported too
 *    (unnamed — PHP never passes their names on);
 *  - a claim that fails BEFORE its commit is sent takes the receipt files it
 *    had already moved with it, removed BEFORE the rollback is tried, so a
 *    lost connection cannot stop it — a file nothing points at could never
 *    be reached by an erasure; one that cannot be removed is logged by path;
 *  - ONCE COMMIT HAS BEEN SENT, NO RECEIPT FILE IS EVER DELETED: the server
 *    may have saved the claim. Every file is kept and logged by its path, and
 *    a NEW connection is asked only which message to show ("saved, do not
 *    submit again", or "not known, check your claims list first");
 *  - and once the claim is COMMITTED, nothing after it (the activity record,
 *    the e-mail to approvers) can make this page say "Error saving claim":
 *    that sentence used to be reachable for a claim that WAS saved, inviting
 *    the claimant to submit the same claim twice.
 *
 * The claimant's own file name is shown back to them in the one-off message
 * and NOWHERE else — never the database, the activity log or the error log
 * (#714). See `ExpenseRules::shownFileName()` for why that is safe.
 *
 * THE CLAIM'S PDF FOLLOWS THE SAME TWO HALVES (#869, 9 November 2026)
 * ---------------------------------------------------------------------
 * `ExpensePdf::generate()` writes `_uploads/pdfs/claim_<id>.pdf` — the
 * claimant's name, department and every figure — INSIDE the transaction, so
 * a claim undone after that step used to leave the file behind with no row
 * pointing at it, where no erasure could find it. Now: before COMMIT is sent
 * it is removed with the receipts (logged by path if it cannot be); after
 * COMMIT is sent it is KEPT and logged by path, like every receipt.
 *
 * A REFUSED CLAIM KEEPS WHAT WAS TYPED (#870). Every refusal after the form
 * token has been accepted keeps the title, department and item lines for the
 * form to fill itself in from (`ExpenseRules::claimDraftFrom()`); never the
 * receipts, which a page cannot choose for somebody. And a submission larger
 * than the server's `post_max_size` — which PHP empties before this page
 * runs, token and all — is told THAT, instead of "Invalid CSRF token.".
 *
 * WHAT IT STILL CANNOT DO: add a receipt to a claim afterwards. The advice is
 * to withdraw the claim and submit it again, which is the only route the
 * portal offers today.
 *
 * @package   Portal\Expenses
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.6.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/649
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Captcha;
use Portal\Core\DbServer;
use Portal\Core\Logger;
use Portal\Core\ExpensePdf;
use Portal\Core\ExpenseMailer;
use Portal\Core\ExpenseRules;
use Portal\Core\Router;
use Portal\Core\Site;

// 📮 READ PHP'S LAST WARNING FIRST, before this page does anything that could
//    replace it (#649, round 1 of the independent check). When a submission
//    carries more files than `max_file_uploads` allows, PHP drops the extra
//    files before any script runs and leaves only a start-up warning, which
//    `error_get_last()` still answers here. Anything later — even a warning
//    silenced with `@` — would overwrite it, so it is taken now and judged by
//    `ExpenseRules::uploadsCutShort()` further down.
$startupWarning = error_get_last();

// 🛡️ Session and security checks
Auth::ensureSession();
Auth::requireLogin();

// ↩️ EVERY REFUSAL GOES BACK TO THE FORM THROUGH HERE (#870), and says
//    whether what was typed is kept for the form to fill itself in from.
//    Before, each refusal wrote the message and redirected by itself, and
//    none kept anything, so the claimant typed the whole claim again. The
//    receipts are NEVER kept: a page cannot choose files for somebody.
//    `$keepTyped` is false in exactly the cases `ExpenseRules` lists under
//    "WHEN NOTHING IS KEPT" — read there before changing a call below.
$sendBack = static function (string $message, bool $keepTyped): never {
    unset($_SESSION[ExpenseRules::DRAFT_SESSION_KEY]);
    if ($keepTyped === true) {
        $_SESSION[ExpenseRules::DRAFT_SESSION_KEY] = ExpenseRules::claimDraftFrom($_POST, Site::id(), (int) ($_SESSION['user_id'] ?? 0));
    }
    $_SESSION['flash_msg']  = $message;
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . Router::url('expenses/submit'));
    exit();
};

// 📦 A SUBMISSION LARGER THAN THE SERVER TAKES (#870). PHP has emptied
//    `$_POST` and `$_FILES` before this page ran, so the form token is
//    missing too, and the check below would tell the claimant "Invalid CSRF
//    token." — untrue: the cause is the size. Asked FIRST, for that reason.
//    Nothing is kept, because nothing arrived.
if (ExpenseRules::postWasTooLarge(
    (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
    count($_POST),
    count($_FILES),
    $_SERVER['CONTENT_LENGTH'] ?? null,
    (string) ini_get('post_max_size')
) === true) {
    $sendBack(
        ExpenseRules::postTooLargeMessage(
            ExpenseRules::declaredLength($_SERVER['CONTENT_LENGTH'] ?? null),
            (string) ini_get('post_max_size')
        ),
        false
    );
}

// A refused token keeps NOTHING: the request may have come from another
// website posting to this address, and keeping it would let that website
// fill in the claim form this member then sees as their own.
if (Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    $sendBack('Invalid CSRF token.', false);
}

if (Captcha::verify($_POST, 'expense-claim') === false) {
    $sendBack('Captcha verification failed. Please try again.', true);
}

// 📝 Validate required fields
$userId     = (int) ($_SESSION['user_id'] ?? 0);
$deptID     = (int) ($_POST['deptID'] ?? 0);
$claimTitle = trim($_POST['claimTitle'] ?? '');

if ($deptID === 0 || $claimTitle === '') {
    $sendBack('Missing required fields (department and title).', true);
}

// 🧮 Recalculate total server-side (do not trust client-side hidden field)
$totalAmt = 0.0;
foreach ($_POST['itemDesc'] ?? [] as $i => $desc) {
    if (trim($desc) === '') {
        continue;
    }
    $qty  = (int) ($_POST['itemQty'][$i] ?? 1);
    $unit = (float) ($_POST['itemUnit'][$i] ?? 0);
    $totalAmt += $qty * $unit;
}
if ($totalAmt <= 0) {
    $sendBack('Total must be greater than zero.', true);
}

$siteId = Site::id();

// 🛡️ Site-scoped department check — mirror expenses/api/create.php:94-105 so
// a claim can't be filed against another site's department (deptID is a
// plain client-supplied int).
//
// #517: the department must also be switched on (`isActive = 1`), matching
// the claim form's own list (submit/index.php) and the API description.
// Retiring a department means "no new claims through any door"; before #517
// the list hid a retired department but a crafted POST could still charge a
// new claim to it. A NULL `isActive` (from a hand edit) counts as off here,
// as everywhere else. Claims ALREADY charged to a retired department are
// untouched: its own approvers still finish them (owner's answer Q3).
$deptCheck = $mysqli->prepare('SELECT deptID FROM tblDepts WHERE deptID = ? AND siteID = ? AND isActive = 1 LIMIT 1');
if ($deptCheck === false) {
    $sendBack('Error saving claim. Please try again.', true);
}
$deptCheck->bind_param('ii', $deptID, $siteId);
$deptCheck->execute();
$deptExists = $deptCheck->get_result()->fetch_assoc() !== null;
$deptCheck->close();
if ($deptExists === false) {
    $sendBack('Selected department is not valid for this site.', true);
}

// 📎 JUDGE EVERY FILE FIRST, before anything is written (#649).
//    `ExpenseRules::whyReceiptRefused()` is the ONE rule. A file it refuses is
//    REMEMBERED with its reason, never skipped in silence: the claimant is told
//    by name, once the claim is saved. An empty slot (nothing chosen) is not a
//    receipt at all and is not reported. A file box with nothing chosen still
//    sends an empty entry, so a claim submitted with no receipt carries one;
//    and with JavaScript switched off the form shows a second box (inside
//    `<noscript>`), so one left unused arrives empty beside the other's files.
//
//    The claimant's own file name is held here ONLY to be shown back to them
//    in the one-off message (#714: it is never stored). It is not put in the
//    activity log, the error log or the database. `adminNote` is what the
//    error log says instead, for a file the server failed to store.
$uploaded        = ExpenseRules::receiptsFromUpload($_FILES['files'] ?? null);
$receiptsToStore = [];
$notAttached     = [];
foreach ($uploaded as $upload) {
    $why = ExpenseRules::whyReceiptRefused($upload['error'], $upload['size'], $upload['name']);
    if ($why === ExpenseRules::RECEIPT_NOTHING_CHOSEN) {
        continue;
    }
    if ($why !== ExpenseRules::RECEIPT_OK) {
        $notAttached[] = [
            'name'      => $upload['name'],
            'reason'    => $why,
            'adminNote' => 'PHP refused one file with upload error code ' . $upload['error'] . ' before the portal saw it',
        ];
        continue;
    }
    $receiptsToStore[] = $upload;
}

// 🔢 FILES PHP DROPPED FOR BEING PAST `max_file_uploads` (round 1 of the
//    independent check). They are not in `$_FILES` at all, so the loop above
//    cannot see them; `uploadsCutShort()` reads PHP's warning, taken at the
//    top of this page, and falls back to "as many arrived as are allowed".
//    The claimant is told; the files cannot be named, because PHP never
//    passed their names on. An EMPTY box is not counted: measured, PHP does
//    not count one towards its limit either, so counting it made two files
//    and an unused box read as "as many as allowed".
$countLimit = (int) ini_get('max_file_uploads');
$filesSent  = count(array_filter($uploaded, static fn (array $u): bool => $u['error'] !== UPLOAD_ERR_NO_FILE));
$cutShort   = ExpenseRules::uploadsCutShort($startupWarning, $filesSent, $countLimit);

// 📂 THE RECEIPTS FOLDER, checked before the claim is written (#649).
//    `mkdir()`'s answer used to be thrown away, so a folder that could not be
//    made let the claim save and every receipt then fail to move, silently.
//    The issue asks for the whole submission to be refused in that case,
//    because a claim would otherwise reach the approver with none of its
//    evidence. Checked only when there is a receipt to store: a claim with no
//    receipts needs no folder, and refusing it would be a fault of our own.
//
//    `is_dir()` is asked again after a failed `mkdir()` because two claims
//    submitted at once may race to make it, and the loser's `mkdir()` answers
//    false for a folder that now exists — that must not refuse a claim.
$upDir = PORTAL_ROOT . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . 'expenses';
if (count($receiptsToStore) > 0 && is_dir($upDir) === false) {
    if (@mkdir($upDir, 0755, true) === false && is_dir($upDir) === false) {
        Logger::errorPlatform(
            'Expenses',
            'Error',
            'RECEIPT_FOLDER',
            'The expense receipts folder could not be made, so a claim was refused',
            'Folder: ' . $upDir . ' — check that the portal may write to _uploads.'
        );
        $sendBack(ExpenseRules::MSG_RECEIPT_FOLDER_MISSING, true);
    }
}

// 🔄 Wrap multi-table insert in a transaction for atomicity
$claimID = 0;
// Every receipt file this request has put in the folder, so a rollback can
// take them away again (see the catch below).
$movedPaths = [];
// Set just before COMMIT is sent; see the catch below for why it matters.
$commitAttempted = false;
// Set just before the claim's PDF is made (#869), so a failure can tell
// whether THIS request may have written `ExpensePdf::pathFor($claimID)`.
$pdfAttempted = false;
App::beginTransaction();
try {
    // 1. 📋 Insert claim header
    $stmt = $mysqli->prepare('INSERT INTO tblExpenseClaims (userID, deptID, claimTitle, claimDate, totalAmount, siteID) VALUES (?, ?, ?, CURDATE(), ?, ?)');
    if ($stmt === false) {
        throw new \RuntimeException('Failed to prepare claim insert: ' . $mysqli->error);
    }
    $stmt->bind_param('iisdi', $userId, $deptID, $claimTitle, $totalAmt, $siteId);
    $stmt->execute();
    $claimID = $stmt->insert_id;
    $stmt->close();

    // 2. 📦 Items
    $itemStmt = $mysqli->prepare('INSERT INTO tblExpenseClaimItems (claimID, itemName, quantity, unitCost, lineTotal) VALUES (?, ?, ?, ?, ?)');
    if ($itemStmt === false) {
        throw new \RuntimeException('Failed to prepare item insert: ' . $mysqli->error);
    }
    foreach ($_POST['itemDesc'] as $i => $desc) {
        $desc = trim($desc);
        if ($desc === '') {
            continue;
        }
        $qty  = (int) ($_POST['itemQty'][$i] ?? 1);
        $unit = (float) ($_POST['itemUnit'][$i] ?? 0);
        $line = $qty * $unit;
        $itemStmt->bind_param('isidd', $claimID, $desc, $qty, $unit, $line);
        $itemStmt->execute();
    }
    $itemStmt->close();

    // 3. 📎 File uploads
    // 📝 THE UPLOADER'S OWN FILENAME IS NEVER STORED (#714). It used to be,
    //    `basename()`'d and nothing else, so "Jane Smith receipt.pdf" was kept
    //    exactly as given and shown back on the claim. A claim is kept for six
    //    years for HMRC and is never deleted on erasure (it keeps the claimant's
    //    account number on purpose - the owner's decision of 3 November 2026,
    //    #492 part 4; this comment used to say the number was detached, which
    //    was untrue), so the cascade never fires, and an
    //    erasure request could
    //    not reach that name — it would have sat there for six years after
    //    somebody asked to be forgotten.
    //
    //    The owner's decision (3 November 2026) was to name the file generically
    //    AT SUBMISSION rather than rename it on erasure: then there is nothing
    //    to clear later and no link to break. What HMRC needs is the claim, the
    //    figures and the evidence — not what the claimant happened to call the
    //    file on their laptop.
    //
    //    `ExpensePdf::generate()` already wrote a generated name into this same
    //    column (`Claim_412_Approved.pdf`), so the shape below matches what the
    //    column already held half the time.
    if (count($receiptsToStore) > 0) {
        // A statement that cannot be prepared used to skip EVERY receipt with
        // no word (`if ($fileStmt !== false) { … }`). It now stops the claim,
        // like the two statements above, so the claimant is told it failed.
        $fileStmt = $mysqli->prepare('INSERT INTO tblExpenseClaimFiles (claimID, fileLabel, storedFilename, fileSize, fileType) VALUES (?, ?, ?, ?, ?)');
        if ($fileStmt === false) {
            throw new \RuntimeException('Failed to prepare receipt insert: ' . $mysqli->error);
        }
        // Counts the receipts actually SAVED, so the numbering has no gaps when
        // a file is not attached.
        $receiptNo = 0;
        foreach ($receiptsToStore as $upload) {
            $tmp  = $upload['tmp'];
            $size = $upload['size'];
            // The uploader's own name, read ONLY for its extension (#714).
            // It is never stored, in the database or on disk.
            $ext  = strtolower(pathinfo(basename($upload['name']), PATHINFO_EXTENSION));

            // 🛡️ Server-side MIME detection (do not trust client-supplied type)
            $type = mime_content_type($tmp) ?: 'application/octet-stream';

            // 🏷️ BOTH NAMES ARE GENERATED, and the second one matters more than
            //    it looks. The stored name used to be
            //    `$claimID . '_' . time() . '_' . preg_replace(…, $orig)` — so
            //    "Jane Smith receipt.pdf" was kept TWICE, once in the database
            //    and once as the name of the file on disk, where no erasure
            //    could reach it either and where it appears in any directory
            //    listing or backup. The personal-data catalogue said the stored
            //    name was "made up by the portal"; it was derived from the
            //    uploader's.
            //
            //    Nothing is lost by generating both. The claim number says which
            //    claim, the receipt number says which attachment, the date says
            //    when, and the extension keeps the file openable — which is all
            //    a treasurer needs to tell one receipt from another, and all
            //    HMRC needs beside the figures.
            $nextNo = $receiptNo + 1;
            $label  = 'Claim_' . $claimID . '_Receipt_' . $nextNo . '_'
                    . date('Y-m-d') . '.' . $ext;
            $stored = $claimID . '_' . time() . '_receipt' . $nextNo . '.' . $ext;
            $path   = $upDir . DIRECTORY_SEPARATOR . $stored;

            // 📦 `move_uploaded_file()` answers a plain true or false (#720).
            //    A false used to mean "no row, no word". It is now a receipt
            //    the claimant is told was NOT attached, and a line in the error
            //    log for the administrator, written with every other "could not
            //    store it" after the commit (step 5) — without the claimant's
            //    own file name (#714), which is why the note names the receipt
            //    number and the folder instead.
            if (@move_uploaded_file($tmp, $path) !== true) {
                $notAttached[] = [
                    'name'      => $upload['name'],
                    'reason'    => ExpenseRules::RECEIPT_NOT_STORED,
                    'adminNote' => 'receipt ' . $nextNo . ' of this submission could not be moved into the receipts folder ' . $upDir,
                ];
                continue;
            }
            $movedPaths[] = $path;
            $fileStmt->bind_param('issis', $claimID, $label, $stored, $size, $type);
            $fileStmt->execute();
            $receiptNo = $nextNo;
        }
        $fileStmt->close();
    }

    // 4. 📄 Pending PDF. It is written to disk HERE, inside the transaction,
    //    and its two rows are written after it — so a failure from this point
    //    on (one of those rows refused, the connection lost) can leave the
    //    file on disk for a claim that is then undone (#869). The flag lets
    //    the catch below take it away.
    $pdfAttempted = true;
    ExpensePdf::generate($claimID, 'Pending');

    // A commit that does not happen must not be reported as a saved claim.
    // Under the portal's strict reporting a failed commit throws; this is the
    // same answer for a connection that does not.
    //
    // 🔌 FROM HERE ON, NO RECEIPT FILE IS EVER DELETED. Once COMMIT has been
    //    sent, a failure is not proof the claim was lost: the server may have
    //    committed it and only the answer failed to arrive. See the catch.
    $commitAttempted = true;
    if (App::commit() === false) {
        throw new \RuntimeException('The claim could not be committed');
    }
} catch (\Throwable $ex) {
    if ($commitAttempted === false) {
        // ═══════════════════════════════════════════════════════════════════
        // BEFORE COMMIT WAS SENT: the claim was not saved.
        // ═══════════════════════════════════════════════════════════════════
        // 🧹 A CLAIM THAT IS NOT SAVED TAKES ITS RECEIPT FILES WITH IT (#649).
        //    Before, a failure after a receipt had been moved (its row
        //    refused, the PDF step throwing) left the file in the folder with
        //    no claim and no row pointing at it — a copy of somebody's receipt
        //    that nothing lists and no erasure could ever find. Only files
        //    THIS request moved are named.
        //
        //    ⚠️ THE FILES GO BEFORE THE ROLLBACK, AND EVERYTHING AFTER THEM IS
        //       GUARDED (round 1 of the check). The first version rolled back
        //       first, with nothing around it, and on a lost connection
        //       `rollback()` THROWS too ("MySQL server has gone away"): the
        //       second exception escaped, the files stayed, and the claimant
        //       got no message. Removing a file needs no database.
        $leftBehind = [];
        foreach ($movedPaths as $moved) {
            if (is_file($moved) === true && @unlink($moved) === false) {
                $leftBehind[] = $moved;
            }
        }

        // 📄 THE CLAIM'S PDF GOES TOO (#869), for the same reason and in the
        //    same place — before the rollback, needing no database. It holds
        //    the claimant's name, department and every figure, and with the
        //    claim undone no row points at it, so no erasure could reach it.
        //
        //    Removing whatever is at that path is safe, not merely likely to
        //    be: the name is built from the claim number alone, and this
        //    request's INSERT has just been given that number, so no SAVED
        //    claim can point at the file. It is only looked for once the PDF
        //    step was reached, so a failure earlier — before this request
        //    could have written anything there — touches nothing.
        $pdfLeftBehind = '';
        if ($pdfAttempted === true && $claimID > 0) {
            $pdfPath = ExpensePdf::pathFor($claimID);
            clearstatcache(true, $pdfPath);
            if (is_file($pdfPath) === true && @unlink($pdfPath) === false) {
                $pdfLeftBehind = $pdfPath;
            }
        }

        // Undo the claim. On a lost connection this throws too, and the server
        // throws an unfinished transaction away by itself once the connection
        // is gone — nothing was committed, because COMMIT was never sent.
        $rollbackFault = null;
        try {
            App::rollback();
        } catch (\Throwable $second) {
            $rollbackFault = $second;
        }

        // A file that could not be removed is named by its PATH only — a name
        // the portal made up, never the claimant's (#714) — and goes to PHP's
        // own error log as well, because the database log may have failed.
        foreach ($leftBehind as $path) {
            error_log('[expenses/submit] a receipt file for a claim that was not saved could not be removed: ' . $path);
        }
        if ($pdfLeftBehind !== '') {
            error_log('[expenses/submit] the PDF of a claim that was not saved could not be removed: ' . $pdfLeftBehind);
        }
        try {
            Logger::exception($ex);
            if ($rollbackFault !== null) {
                Logger::exception($rollbackFault);
            }
            foreach ($leftBehind as $path) {
                Logger::errorPlatform(
                    'Expenses',
                    'Error',
                    'RECEIPT_LEFT_BEHIND',
                    'A receipt file for a claim that was not saved could not be removed',
                    'File: ' . $path . ' — nothing in the database points at it; remove it by hand.'
                );
            }
            if ($pdfLeftBehind !== '') {
                Logger::errorPlatform(
                    'Expenses',
                    'Error',
                    'CLAIM_PDF_LEFT_BEHIND',
                    'The PDF of a claim that was not saved could not be removed',
                    'File: ' . $pdfLeftBehind . ' — it holds the claimant\'s name and figures, and nothing in the database points at it; remove it by hand.'
                );
            }
        } catch (\Throwable $ignored) {
            error_log('[expenses/submit] a claim was not saved and the error log could not be written: ' . $ex->getMessage());
        }

        // Nothing was saved, so what was typed is kept for the form (#870).
        $sendBack('Error saving claim. Please try again.', true);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // COMMIT WAS SENT AND ITS SUCCESS COULD NOT BE CONFIRMED.
    // ═══════════════════════════════════════════════════════════════════════
    // 🔒 NO RECEIPT FILE IS DELETED ON THIS PATH, WHATEVER HAPPENS. The server
    //    may have committed the claim and only the answer failed to arrive, or
    //    it may have refused it with the connection still up. Two earlier
    //    designs tried to tell which and then delete; both deleted the receipt
    //    of a SAVED claim (measured — see ExpenseRules, "A claim whose save
    //    could not be confirmed"). The design is simplified on purpose: keep
    //    every file, record each by its stored path, and ask a NEW connection
    //    only which MESSAGE to show. Nothing that look answers can delete.
    //
    //    The honest cost: when the commit really did fail, those files stay on
    //    disk with no claim pointing at them, for the administrator to remove
    //    from the error log's list. Deleting a saved claim's evidence cannot be
    //    undone; an orphan file can be.
    $keptFiles = [];
    foreach ($movedPaths as $moved) {
        if (is_file($moved) === true) {
            $keptFiles[] = $moved;
        }
    }

    // 📄 THE CLAIM'S PDF IS KEPT TOO, under the same rule and for the same
    //    reason (#869): if the claim WAS saved, its row points at this file.
    //    It is recorded by path under its own code, so an administrator
    //    reading the list can tell the claim's own document from a receipt.
    $keptPdf = '';
    if ($pdfAttempted === true && $claimID > 0) {
        $pdfPath = ExpensePdf::pathFor($claimID);
        clearstatcache(true, $pdfPath);
        if (is_file($pdfPath) === true) {
            $keptPdf = $pdfPath;
        }
    }

    // Nothing typed is kept for the form on THIS path: the claim may well be
    // saved, and a filled-in form would invite claiming the same money twice.
    unset($_SESSION[ExpenseRules::DRAFT_SESSION_KEY]);

    // Tidy the request's own transaction if its connection still works. A
    // failure is passed over: it can change nothing about what was committed.
    $rollbackFault = null;
    try {
        App::rollback();
    } catch (\Throwable $second) {
        $rollbackFault = $second;
    }

    // A NEW connection, with a short deadline (`DbServer::connectAgain()`), so
    // the records below can be written even when the request's own connection
    // has failed, and so the claim can be looked for. None is a fault: without
    // it the records go to PHP's own error log and the answer is "not known".
    $fresh = null;
    try {
        $fresh = DbServer::connectAgain();
        Logger::useConnection($fresh);
    } catch (\Throwable $noConnection) {
        $fresh = null;
    }

    // Record every kept file by its PATH — a name the portal made up, never
    // the claimant's (#714) — one row each, so an administrator can find them;
    // and in PHP's own error log too, in case even the new connection failed.
    foreach ($keptFiles as $path) {
        error_log('[expenses/submit] claim #' . $claimID . ': its save could not be confirmed, so this receipt file was KEPT: ' . $path);
    }
    if ($keptPdf !== '') {
        error_log('[expenses/submit] claim #' . $claimID . ': its save could not be confirmed, so its PDF was KEPT: ' . $keptPdf);
    }
    try {
        Logger::exception($ex);
        if ($rollbackFault !== null) {
            Logger::exception($rollbackFault);
        }
        foreach ($keptFiles as $path) {
            Logger::errorPlatform(
                'Expenses',
                'Error',
                'RECEIPT_KEPT_AFTER_FAILED_COMMIT',
                'A claim\'s save could not be confirmed, so its receipt file was kept',
                'Claim #' . $claimID . '. File: ' . $path . ' — if no such claim exists, nothing points at this file; remove it by hand.'
            );
        }
        if ($keptPdf !== '') {
            Logger::errorPlatform(
                'Expenses',
                'Error',
                'CLAIM_PDF_KEPT_AFTER_FAILED_COMMIT',
                'A claim\'s save could not be confirmed, so its PDF was kept',
                'Claim #' . $claimID . '. File: ' . $keptPdf . ' — it holds the claimant\'s name and figures; if no such claim exists, nothing points at this file, so remove it by hand.'
            );
        }
        // The same "could not store it" lines the ordinary path writes after
        // its commit, because the claimant is told they were recorded.
        foreach ($notAttached as $file) {
            if (($file['reason'] ?? '') === ExpenseRules::RECEIPT_NOT_STORED) {
                Logger::errorPlatform(
                    'Expenses',
                    'Error',
                    'RECEIPT_NOT_STORED',
                    'A receipt could not be stored, so it was not attached to a claim',
                    'Claim #' . $claimID . ': ' . (string) ($file['adminNote'] ?? 'no further detail was recorded')
                );
            }
        }
    } catch (\Throwable $ignored) {
        error_log('[expenses/submit] a claim\'s save could not be confirmed and the error log could not be written: ' . $ex->getMessage());
    }

    // Was it saved? This decides the MESSAGE and nothing else.
    $found = false;
    if ($fresh instanceof \mysqli) {
        try {
            $found = ExpenseRules::claimIsSaved($fresh, $claimID, $userId);
        } catch (\Throwable $cannotAsk) {
            $found = false;
        }
    }

    $told = ExpenseRules::unconfirmedMessage(
        $found,
        $claimID,
        $notAttached,
        (string) ini_get('upload_max_filesize'),
        $cutShort,
        $countLimit
    );
    $_SESSION['flash_msg']  = $told['message'];
    $_SESSION['flash_type'] = $told['type'];
    header('Location: ' . Router::url('expenses/submit') . ($found === true ? '?success=' . $claimID : ''));
    exit();
}

// ✅ THE CLAIM IS SAVED FROM HERE ON. Nothing below may report it as not
//    saved: the two steps used to sit inside the try above, so a failure in
//    either "rolled back" a transaction already committed and told the
//    claimant "Error saving claim. Please try again." about a claim that WAS
//    saved — an invitation to submit the same money twice. A failure here is
//    recorded for the administrator and the claimant is told the truth.
try {
    // 5. 🧾 One error-log line for EVERY file the server failed to store —
    //    PHP's own refusals (upload error codes 6, 7 and 8) and a failed move
    //    alike — because the claimant is told such a file "has been recorded
    //    for your administrator", and round 1 of the independent check found
    //    that true only of a failed move. Written here, ONE place, after the
    //    commit, so a rollback cannot take the line away with the claim. The
    //    note never holds the claimant's file name (#714).
    foreach ($notAttached as $file) {
        if (($file['reason'] ?? '') === ExpenseRules::RECEIPT_NOT_STORED) {
            Logger::errorPlatform(
                'Expenses',
                'Error',
                'RECEIPT_NOT_STORED',
                'A receipt could not be stored, so it was not attached to a claim',
                'Claim #' . $claimID . ': ' . (string) ($file['adminNote'] ?? 'no further detail was recorded')
            );
        }
    }

    // 6. 📓 Log activity — a COUNT of receipts not attached, never their names.
    Logger::activity(
        'ExpenseSubmit',
        'Claim #' . $claimID . ' created (£' . number_format($totalAmt, 2) . ')'
            . (count($notAttached) > 0 ? '; ' . count($notAttached) . ' file(s) not attached' : ''),
        $userId
    );

    // 7. 📧 Send email notification to approvers
    ExpenseMailer::notify($claimID, 'submitted', [
        'comments' => '',
    ]);
} catch (\Throwable $ex) {
    Logger::exception($ex);
}

// 8. ✅ Tell the claimant what happened — including, by name, every file that
//    was NOT attached (#649), and any PHP dropped past the server's limit on
//    how many files one submission may carry — and go back to the claims
//    page, which prints it.
$told = ExpenseRules::submittedMessage(
    $claimID,
    $notAttached,
    (string) ini_get('upload_max_filesize'),
    $cutShort,
    $countLimit
);
$_SESSION['flash_msg']  = $told['message'];
$_SESSION['flash_type'] = $told['type'];
// The claim is saved: an earlier refusal's kept typing must not fill the form
// in again, or it would invite submitting the same claim twice (#870).
unset($_SESSION[ExpenseRules::DRAFT_SESSION_KEY]);
header('Location: ' . Router::url('expenses/submit') . '?success=' . $claimID);
exit();
