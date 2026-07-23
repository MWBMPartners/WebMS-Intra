<?php
// Path: _apps/giving/reconcile/_automatch.php
/**
 * -----------------------------------------------------------------------------
 * Giving — Bank Reconciliation: Shared Auto-Match Function 🤖
 * -----------------------------------------------------------------------------
 * Deterministic, no-guessing auto-matcher for imported bank credit lines
 * (#299 sub-feature 3). `require_once`d by import.php (after confirm) and
 * match.php (action=rematch) — NOT registered in tblRoutes (leading
 * underscore keeps it out of check_route_targets.py's scope; it is a plain
 * PHP include, never an HTTP-reachable endpoint).
 *
 * Two candidate universes, both siteID-scoped:
 *   - Deposits: closed offering-count sessions (`tblCountSessions`), whose
 *     deposit pence = ROUND((cashTotal + chequeTotal + envelopeTotal) * 100)
 *     — exact because the agreed totals are DECIMAL(10,2).
 *   - Entries: individual `tblGivingEntry` rows, EXCLUDING rows written by
 *     count-close (`reference LIKE 'Count #%'`) — those reconcile only at
 *     the session/deposit level above; entry-matching one of their envelope
 *     rows would double-count against the deposit and steal a match from a
 *     standalone gift.
 *
 * Window rule: a gift/deposit dated `donatedAt`/`serviceDate` can appear in
 * the bank on or after that date (deposits lag; transfers post same/next
 * day) — a bank txn dated T matches candidates dated in [T - tolDays, T].
 * Gifts dated AFTER the credit never match.
 *
 * Matching is exact-amount, in-memory, single-pass with a consumed-candidate
 * set so one gift/session never satisfies two txns in the same run:
 *   - Deposit pass first: exactly one unconsumed same-amount, in-window
 *     session → match (session mode). Two or more → leave UNMATCHED
 *     entirely (genuinely ambiguous; never falls through to entries).
 *   - Entry pass: exactly one unconsumed same-amount, in-window entry →
 *     match (entry mode). Zero or 2+ → leave unmatched.
 *   - Nothing is ever auto-ignored.
 *
 * Money is integer pence end-to-end; the only pence arithmetic here is the
 * exact DECIMAL*100 ROUND in the deposit SELECT below — no floats, no
 * guessing.
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

/**
 * Run the auto-match pass over one import's currently-unmatched bank credit
 * lines and return how many were newly matched.
 *
 * Safe to call repeatedly (e.g. via the "Re-run auto-match" button) — every
 * write UPDATE carries `AND matchStatus = 'unmatched'`, so a concurrent
 * double-run can never double-match the same line.
 *
 * @param \mysqli $db       Active connection (App::db()).
 * @param int     $siteId   Active site (Site::id()) — every query is scoped.
 * @param int     $importId The import batch to match.
 * @param int     $userId   Acting user, stamped as matchedByID.
 * @param int     $tolDays  Matching window in days (giving.reconcile.toleranceDays).
 * @param string  $currency Import's currency — entries are matched currency-scoped;
 *                          the deposit pass only runs when this equals the site's
 *                          current giving.currency (count sessions carry no
 *                          currency column — documented limitation).
 *
 * @return int Count of bank txns newly matched by this run.
 */
function giving_reconcile_automatch(\mysqli $db, int $siteId, int $importId, int $userId, int $tolDays, string $currency): int
{
    // -------------------------------------------------------------------
    // 1️⃣ Load this import's currently-unmatched txns, oldest first.
    // -------------------------------------------------------------------
    $txns = [];
    $stmt = $db->prepare(
        'SELECT txnID, txnDate, amountPence FROM tblBankTxns '
        . 'WHERE importID = ? AND siteID = ? AND matchStatus = \'unmatched\' '
        . 'ORDER BY txnDate ASC, txnID ASC'
    );
    if ($stmt !== false) {
        $stmt->bind_param('ii', $importId, $siteId);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) {
            $txns[] = $r;
        }
        $stmt->close();
    }

    if (count($txns) === 0) {
        return 0;
    }

    // -------------------------------------------------------------------
    // 2️⃣ Compute the overall date window, then bulk-load both candidate
    // universes in ONE query each (no N+1) — indexed by amountPence.
    // -------------------------------------------------------------------
    $minDate = (string) $txns[0]['txnDate'];
    $maxDate = (string) $txns[0]['txnDate'];
    foreach ($txns as $t) {
        $d = (string) $t['txnDate'];
        if ($d < $minDate) {
            $minDate = $d;
        }
        if ($d > $maxDate) {
            $maxDate = $d;
        }
    }
    $windowStart = date('Y-m-d', strtotime($minDate . ' -' . $tolDays . ' days'));

    // 💷 Deposit candidates — closed count sessions not yet matched to any
    // bank txn. Deposit pence is an EXACT DECIMAL*100 ROUND (never a float
    // comparison). Currency-scoping: tblCountSessions carries no currency
    // column, so this universe is only meaningful when the import's
    // currency matches the site's current giving.currency.
    $sessByAmount = [];
    $siteCurrency = (string) (\Portal\Core\App::settings('giving.currency') ?? 'GBP');
    if ($currency === $siteCurrency) {
        $stmt = $db->prepare(
            'SELECT cs.countSessionID, cs.serviceDate, '
            . 'CAST(ROUND((cs.cashTotal + cs.chequeTotal + cs.envelopeTotal) * 100) AS SIGNED) AS depositPence '
            . 'FROM tblCountSessions cs '
            . 'WHERE cs.siteID = ? AND cs.status = \'closed\' AND cs.serviceDate BETWEEN ? AND ? '
            . 'AND NOT EXISTS (SELECT 1 FROM tblBankTxns bt WHERE bt.matchedCountSessionID = cs.countSessionID)'
        );
        if ($stmt !== false) {
            $stmt->bind_param('iss', $siteId, $windowStart, $maxDate);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($r = $rs->fetch_assoc()) {
                $pence = (int) $r['depositPence'];
                $sessByAmount[$pence][] = [
                    'countSessionID' => (int) $r['countSessionID'],
                    'serviceDate'    => (string) $r['serviceDate'],
                ];
            }
            $stmt->close();
        }
    }

    // 💷 Entry candidates — individual gift-log rows, excluding anything
    // written by count-close (those reconcile only at the deposit level).
    $entByAmount = [];
    $stmt = $db->prepare(
        'SELECT e.entryID, e.donatedAt, e.amountPence '
        . 'FROM tblGivingEntry e '
        . 'WHERE e.siteID = ? AND e.currency = ? AND e.donatedAt BETWEEN ? AND ? '
        . 'AND (e.reference IS NULL OR e.reference NOT LIKE \'Count #%\') '
        . 'AND NOT EXISTS (SELECT 1 FROM tblBankTxns bt WHERE bt.matchedEntryID = e.entryID)'
    );
    if ($stmt !== false) {
        $stmt->bind_param('isss', $siteId, $currency, $windowStart, $maxDate);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) {
            $pence = (int) $r['amountPence'];
            $entByAmount[$pence][] = [
                'entryID'   => (int) $r['entryID'],
                'donatedAt' => (string) $r['donatedAt'],
            ];
        }
        $stmt->close();
    }

    // -------------------------------------------------------------------
    // 3️⃣ Per-txn matching, in order, with consumed-candidate tracking.
    // -------------------------------------------------------------------
    $consumedSessions = [];
    $consumedEntries  = [];
    $matchStmt = $db->prepare(
        'UPDATE tblBankTxns SET matchStatus = \'matched\', matchedCountSessionID = ?, '
        . 'matchedEntryID = ?, matchedByID = ?, matchedAt = NOW() '
        . 'WHERE txnID = ? AND siteID = ? AND matchStatus = \'unmatched\''
    );
    if ($matchStmt === false) {
        return 0;
    }

    $matchedCount = 0;
    foreach ($txns as $txn) {
        $txnId       = (int) $txn['txnID'];
        $txnDate     = (string) $txn['txnDate'];
        $amountPence = (int) $txn['amountPence'];
        $windowFrom  = date('Y-m-d', strtotime($txnDate . ' -' . $tolDays . ' days'));

        // 🏦 Deposit pass — exactly one unconsumed same-amount in-window
        // session matches; 2+ candidates is a genuine ambiguity and is left
        // unmatched (does NOT fall through to the entry pass).
        $sessCandidates = [];
        foreach (($sessByAmount[$amountPence] ?? []) as $idx => $s) {
            if (isset($consumedSessions[$idx]) === true) {
                continue;
            }
            if ($s['serviceDate'] >= $windowFrom && $s['serviceDate'] <= $txnDate) {
                $sessCandidates[] = $idx;
            }
        }
        if (count($sessCandidates) === 1) {
            $idx = $sessCandidates[0];
            $consumedSessions[$idx] = true;
            $countSessionId = $sessByAmount[$amountPence][$idx]['countSessionID'];
            $nullEntry = null;
            $matchStmt->bind_param('iiiii', $countSessionId, $nullEntry, $userId, $txnId, $siteId);
            $matchStmt->execute();
            if ($matchStmt->affected_rows === 1) {
                $matchedCount++;
            }
            continue;
        }
        if (count($sessCandidates) >= 2) {
            // Ambiguous deposit match — leave unmatched, never guess.
            continue;
        }

        // 🧾 Entry pass — exactly one unconsumed same-amount in-window
        // entry matches; 0 or 2+ leaves the txn unmatched.
        $entCandidates = [];
        foreach (($entByAmount[$amountPence] ?? []) as $idx => $e) {
            if (isset($consumedEntries[$idx]) === true) {
                continue;
            }
            if ($e['donatedAt'] >= $windowFrom && $e['donatedAt'] <= $txnDate) {
                $entCandidates[] = $idx;
            }
        }
        if (count($entCandidates) === 1) {
            $idx = $entCandidates[0];
            $consumedEntries[$idx] = true;
            $entryId = $entByAmount[$amountPence][$idx]['entryID'];
            $nullSession = null;
            $matchStmt->bind_param('iiiii', $nullSession, $entryId, $userId, $txnId, $siteId);
            $matchStmt->execute();
            if ($matchStmt->affected_rows === 1) {
                $matchedCount++;
            }
        }
        // 0 or 2+ entry candidates — leave unmatched, never guess.
    }
    $matchStmt->close();

    return $matchedCount;
}
