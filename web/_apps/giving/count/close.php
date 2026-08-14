<?php
// Path: _apps/giving/count/close.php
/**
 * -----------------------------------------------------------------------------
 * Giving — Offering Count Session: Close & Write Gift Log 🔒
 * -----------------------------------------------------------------------------
 * Closes an offering-count session (#299 sub-feature 1) once its counts are
 * agreed (no live 'discrepancy'). Writes the actual gift log to
 * `tblGivingEntry` in a single transaction.
 *
 * THREE INDEPENDENT BUCKETS — this is the financial model the whole feature
 * is built on (matches session.php's "Loose cash" / "Loose cheque" /
 * "Envelope total" inputs): the deposit = cashTotal + chequeTotal +
 * envelopeTotal. Loose cash and loose cheque are separate money from the
 * envelope money — envelope giving is NOT a breakdown/subset of the cash or
 * cheque buckets, it's money that arrived in named/numbered giving envelopes.
 * `tblCountEnvelopes.method` only records what was physically INSIDE a given
 * envelope (cash or cheque) for reference — it never reduces the separate
 * cashTotal/chequeTotal buckets.
 *
 * Rows written:
 *   - one tblGivingEntry row per named envelope (tblCountEnvelopes), attributed
 *     to the giver where known, using that envelope's own method;
 *   - one aggregate "loose envelope" row for any agreed envelopeTotal not
 *     itemised by a named envelope (envelopeTotal >= SUM(named envelopes) —
 *     the remainder is legitimate un-named envelope giving, not an error);
 *   - one "loose cash" row for the FULL agreed cashTotal (cash is never
 *     itemised per-giver by this workflow);
 *   - one "loose cheque" row for the FULL agreed chequeTotal.
 *
 * The sum of every row written always equals cashTotal + chequeTotal +
 * envelopeTotal exactly — matching the deposit and this page's own success
 * message. All validation (agreed totals present, no discrepancy, named
 * envelopes not OVER the agreed envelope total) happens BEFORE the
 * transaction opens, so a rejected close never touches the database.
 *
 * Gate matches every other financial action in `giving`:
 * Portal\Core\Giving::canManage() (site admin OR the `treasurer` role).
 *
 * @package   Portal\Giving
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/299
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Giving;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

// 🛡️ Session + gate
Auth::ensureSession();
Auth::requireLogin();
if (Giving::canManage() === false) {
    Router::renderError(403);
    return;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$countSessionId = (int) ($_POST['countSessionID'] ?? 0);
if ($countSessionId <= 0) {
    Router::renderError(400);
    return;
}

// -----------------------------------------------------------------------------
// 📋 Fetch the session, siteID-scoped
// -----------------------------------------------------------------------------
$session = null;
$stmt = $db->prepare('SELECT * FROM tblCountSessions WHERE countSessionID = ? AND siteID = ? LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('ii', $countSessionId, $siteId);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($session === null) {
    Router::renderError(404);
    return;
}

// -----------------------------------------------------------------------------
// 🛡️ Pre-transaction validation — a rejected close must never touch the DB.
// -----------------------------------------------------------------------------
if ($session['status'] !== 'counting') {
    $_SESSION['flash_msg']  = $session['status'] === 'discrepancy'
        ? 'This session has an unresolved discrepancy — resolve it before closing.'
        : ($session['status'] === 'closed' ? 'This session is already closed.' : 'This session is not ready to close yet.');
    $_SESSION['flash_type'] = 'danger';
    header('Location: /giving/count/session?id=' . $countSessionId);
    exit();
}
if ($session['cashTotal'] === null || $session['chequeTotal'] === null || $session['envelopeTotal'] === null) {
    $_SESSION['flash_msg']  = 'Agreed totals are not set yet — both counters must agree, or an admin must resolve.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /giving/count/session?id=' . $countSessionId);
    exit();
}

// 💷 Work entirely in integer pence to avoid DECIMAL/float rounding drift.
$toPence = static fn (string $v): int => (int) round(((float) $v) * 100);

$agreedCashPence     = $toPence($session['cashTotal']);
$agreedChequePence   = $toPence($session['chequeTotal']);
$agreedEnvelopePence = $toPence($session['envelopeTotal']);

// -----------------------------------------------------------------------------
// 📋 Fetch named envelopes — these are a breakdown of the envelope bucket
// ONLY. `method` on each row is what was physically inside that envelope
// (cash/cheque), kept for reference; it never reduces the separate
// cashTotal/chequeTotal buckets below.
// -----------------------------------------------------------------------------
$envelopes = [];
$envelopeSumPence = 0;
$stmt = $db->prepare(
    'SELECT envelopeID, giverID, giverName, amount, method FROM tblCountEnvelopes WHERE countSessionID = ? ORDER BY envelopeID'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $countSessionId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $envelopes[] = $r;
        $envelopeSumPence += $toPence((string) $r['amount']);
    }
    $stmt->close();
}

// 🛡️ Named envelopes may UNDER-shoot the agreed envelope total (the
// remainder is legitimate un-named/un-itemised envelope giving — see the
// "loose envelope" row below) but must never OVER-shoot it.
if ($envelopeSumPence > $agreedEnvelopePence) {
    $_SESSION['flash_msg']  = 'Named envelopes (' . Giving::formatAmount($envelopeSumPence) . ') exceed the agreed envelope total ('
        . Giving::formatAmount($agreedEnvelopePence) . '). Adjust the named envelopes or the agreed envelope total before closing.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /giving/count/session?id=' . $countSessionId);
    exit();
}

// 💰 Three independent buckets — cash and cheque are written in FULL (this
// workflow never itemises cash/cheque per-giver); only the envelope bucket
// is split between named envelopes and an aggregate remainder.
$looseEnvelopePence = $agreedEnvelopePence - $envelopeSumPence;

// -----------------------------------------------------------------------------
// 💾 Write the gift log — one transaction, siteID + serviceDate on every row.
// -----------------------------------------------------------------------------
$settings   = App::settings()['giving'] ?? [];
$currency   = (string) ($settings['currency'] ?? 'GBP');
$categoryId = (int) $session['categoryID'];
$serviceDate = (string) $session['serviceDate'];
$reference  = 'Count #' . $countSessionId;

// 🎯 Loose (null-donor) rows never have a real donor to match a pledge
// against — attributeGift() would just return nulls anyway for a null
// donorId, so we skip the query entirely and bind literal NULLs. Assigned
// once, by-reference bind_param needs a real variable, not a literal.
$noCamp   = null;
$noPledge = null;

$db->begin_transaction();
try {
    $insertStmt = $db->prepare(
        'INSERT INTO tblGivingEntry '
        . '(siteID, donorID, donorName, categoryID, amountPence, currency, donatedAt, method, reference, notes, recordedByID, campaignID, pledgeID) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if ($insertStmt === false) {
        throw new \RuntimeException('Failed to prepare gift entry insert: ' . $db->error);
    }

    // ✉️ One row per named envelope, attributed to the giver where known —
    // this is the envelope bucket's itemised portion. Named envelopes are
    // the only rows written here with a real donor, so this is the only
    // loop that calls Giving::attributeGift() (#299 sub-feature 2, Auto).
    foreach ($envelopes as $env) {
        $donorId    = $env['giverID'] !== null ? (int) $env['giverID'] : null;
        $donorName  = $donorId === null ? $env['giverName'] : null;
        $amountPence = $toPence((string) $env['amount']);
        $method      = (string) $env['method'];
        $notes       = 'Offering count — named envelope';
        $attr        = Giving::attributeGift($siteId, $donorId, $serviceDate, 0);
        $campBind    = $attr['campaignID'];
        $pledgeBind  = $attr['pledgeID'];
        $insertStmt->bind_param(
            'iisiisssssiii',
            $siteId, $donorId, $donorName, $categoryId, $amountPence, $currency,
            $serviceDate, $method, $reference, $notes, $userId, $campBind, $pledgeBind
        );
        $insertStmt->execute();
    }

    // ✉️ One aggregate "loose envelope" row for envelope money not itemised
    // by a named envelope. `tblGivingEntry.method`'s ENUM has no dedicated
    // 'envelope' value, so this uses 'other' — the closest available
    // meaning ("neither cash nor cheque specifically") rather than
    // mislabelling it as 'cash' (which would collide with the separate,
    // already-fully-written loose-cash row below and misrepresent the
    // deposit's cash-vs-envelope split on any cash-specific report).
    if ($looseEnvelopePence > 0) {
        $donorId   = null;
        $donorName = null;
        $method    = 'other';
        $notes     = 'Offering count — loose envelope';
        $insertStmt->bind_param(
            'iisiisssssiii',
            $siteId, $donorId, $donorName, $categoryId, $looseEnvelopePence, $currency,
            $serviceDate, $method, $reference, $notes, $userId, $noCamp, $noPledge
        );
        $insertStmt->execute();
    }

    // 💵 One row for the FULL agreed cash bucket — cash is never itemised
    // per-giver by this workflow, and is a bucket independent of envelopes.
    if ($agreedCashPence > 0) {
        $donorId   = null;
        $donorName = null;
        $method    = 'cash';
        $notes     = 'Offering count — loose cash';
        $insertStmt->bind_param(
            'iisiisssssiii',
            $siteId, $donorId, $donorName, $categoryId, $agreedCashPence, $currency,
            $serviceDate, $method, $reference, $notes, $userId, $noCamp, $noPledge
        );
        $insertStmt->execute();
    }

    // 🧾 One row for the FULL agreed cheque bucket — same rationale as cash.
    if ($agreedChequePence > 0) {
        $donorId   = null;
        $donorName = null;
        $method    = 'cheque';
        $notes     = 'Offering count — loose cheque';
        $insertStmt->bind_param(
            'iisiisssssiii',
            $siteId, $donorId, $donorName, $categoryId, $agreedChequePence, $currency,
            $serviceDate, $method, $reference, $notes, $userId, $noCamp, $noPledge
        );
        $insertStmt->execute();
    }
    $insertStmt->close();

    // 🔒 Stamp the session closed.
    $closeStmt = $db->prepare(
        'UPDATE tblCountSessions SET status = \'closed\', closedByID = ?, closedAt = NOW() '
        . 'WHERE countSessionID = ? AND siteID = ? AND status = \'counting\''
    );
    if ($closeStmt === false) {
        throw new \RuntimeException('Failed to prepare session close: ' . $db->error);
    }
    $closeStmt->bind_param('iii', $userId, $countSessionId, $siteId);
    $closeStmt->execute();
    $closed = $closeStmt->affected_rows === 1;
    $closeStmt->close();

    if ($closed === false) {
        // 🛡️ Someone else closed/changed it concurrently between our
        // pre-checks and here — abort rather than double-write the log.
        throw new \RuntimeException('Session status changed before close could complete (concurrent update).');
    }

    $db->commit();

    Logger::activity(
        'CountSessionClosed',
        'Closed offering count session #' . $countSessionId . ' for ' . $serviceDate
            . ' — wrote ' . (
                count($envelopes)
                + ($looseEnvelopePence > 0 ? 1 : 0)
                + ($agreedCashPence > 0 ? 1 : 0)
                + ($agreedChequePence > 0 ? 1 : 0)
            ) . ' gift entries',
        $userId
    );

    $_SESSION['flash_msg']  = 'Count session closed. Gift log written (' . Giving::formatAmount($agreedCashPence + $agreedChequePence + $agreedEnvelopePence, $currency) . ').';
    $_SESSION['flash_type'] = 'success';
} catch (\Throwable $ex) {
    $db->rollback();
    Logger::exception($ex);
    $_SESSION['flash_msg']  = 'Error closing the count session — no gift entries were written. Please try again.';
    $_SESSION['flash_type'] = 'danger';
}

header('Location: /giving/count/session?id=' . $countSessionId);
exit();
