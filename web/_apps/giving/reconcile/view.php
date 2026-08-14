<?php
// Path: _apps/giving/reconcile/view.php
/**
 * -----------------------------------------------------------------------------
 * Giving — Bank Reconciliation: Statement Detail 🔍
 * -----------------------------------------------------------------------------
 * One imported statement (#299 sub-feature 3): unmatched credits (with up to
 * three inline match suggestions each), matched lines (with their matched-to
 * gift entry or count-session deposit), ignored lines, and a "gift log, not
 * in this statement" panel (both unmatched count deposits and unmatched
 * individual entries in the statement's date range) so a treasurer can see
 * gaps in both directions.
 *
 * Self-heals the "matched but its target was since deleted" state (both
 * `matchedEntryID`/`matchedCountSessionID` FKs are ON DELETE SET NULL) back
 * to 'unmatched' every time this page loads, before any of the lists below
 * are queried.
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
use Portal\Core\Router;
use Portal\Core\Site;

// 🛡️ Session + gate
Auth::ensureSession();
Auth::requireLogin();
if (Giving::canManage() === false) {
    Router::renderError(403);
    return;
}

$db     = App::db();
$siteId = Site::id();

$importId = (int) ($_GET['id'] ?? 0);
if ($importId <= 0) {
    Router::renderError(404);
    return;
}

// -----------------------------------------------------------------------------
// 📋 Fetch the import (siteID-scoped)
// -----------------------------------------------------------------------------
$import = null;
$stmt = $db->prepare(
    'SELECT bi.importID, bi.filename, bi.bankKey, bi.currency, bi.rowCount, bi.skippedCount, bi.importedAt, '
    . '       u.fullName AS importerName '
    . 'FROM tblBankImports bi LEFT JOIN tblUsers u ON u.userID = bi.importedByID '
    . 'WHERE bi.importID = ? AND bi.siteID = ? LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $importId, $siteId);
    $stmt->execute();
    $import = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($import === null) {
    Router::renderError(404);
    return;
}
$currency = (string) $import['currency'];

// -----------------------------------------------------------------------------
// 🩹 Orphan self-heal — a matched line whose target (gift entry / count
// session) was since deleted resets to 'unmatched' (both FKs SET NULL on
// delete). Runs BEFORE any of the lists below are queried.
// -----------------------------------------------------------------------------
$stmt = $db->prepare(
    'UPDATE tblBankTxns SET matchStatus = \'unmatched\', matchedByID = NULL, matchedAt = NULL '
    . 'WHERE importID = ? AND siteID = ? AND matchStatus = \'matched\' '
    . 'AND matchedEntryID IS NULL AND matchedCountSessionID IS NULL'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $importId, $siteId);
    $stmt->execute();
    $stmt->close();
}

// -----------------------------------------------------------------------------
// 📋 All txns of this import
// -----------------------------------------------------------------------------
$txns = [];
$stmt = $db->prepare(
    'SELECT txnID, txnDate, amountPence, description, reference, matchStatus, '
    . '       matchedEntryID, matchedCountSessionID, matchNote '
    . 'FROM tblBankTxns WHERE importID = ? AND siteID = ? ORDER BY txnDate ASC, txnID ASC'
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

$minTxnDate = null;
$maxTxnDate = null;
foreach ($txns as $t) {
    $d = (string) $t['txnDate'];
    if ($minTxnDate === null || $d < $minTxnDate) {
        $minTxnDate = $d;
    }
    if ($maxTxnDate === null || $d > $maxTxnDate) {
        $maxTxnDate = $d;
    }
}

$tolDays      = max(0, (int) (App::settings('giving.reconcile.toleranceDays') ?? '5'));
$siteCurrency = (string) (App::settings('giving.currency') ?? 'GBP');
$depositsApplicable = ($currency === $siteCurrency);

// -----------------------------------------------------------------------------
// 📋 Bulk-load candidate universes over the padded window, once — reused for
// BOTH the per-txn suggestion cells AND (filtered to the exact statement
// range) the "in gift log, not in bank" panel. No N+1 queries.
// -----------------------------------------------------------------------------
$sessCandidates = [];
$entCandidates  = [];
if ($minTxnDate !== null && $maxTxnDate !== null) {
    $windowStart = date('Y-m-d', strtotime($minTxnDate . ' -' . $tolDays . ' days'));

    if ($depositsApplicable === true) {
        $stmt = $db->prepare(
            'SELECT cs.countSessionID, cs.serviceDate, c.name AS categoryName, '
            . 'CAST(ROUND((cs.cashTotal + cs.chequeTotal + cs.envelopeTotal) * 100) AS SIGNED) AS depositPence '
            . 'FROM tblCountSessions cs INNER JOIN tblGivingCategory c ON c.categoryID = cs.categoryID '
            . 'WHERE cs.siteID = ? AND cs.status = \'closed\' AND cs.serviceDate BETWEEN ? AND ? '
            . 'AND NOT EXISTS (SELECT 1 FROM tblBankTxns bt WHERE bt.matchedCountSessionID = cs.countSessionID) '
            . 'ORDER BY cs.serviceDate'
        );
        if ($stmt !== false) {
            $stmt->bind_param('iss', $siteId, $windowStart, $maxTxnDate);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($r = $rs->fetch_assoc()) {
                $sessCandidates[] = $r;
            }
            $stmt->close();
        }
    }

    $stmt = $db->prepare(
        'SELECT e.entryID, e.donatedAt, e.amountPence, e.donorName, e.method, u.fullName AS donorFullName '
        . 'FROM tblGivingEntry e LEFT JOIN tblUsers u ON u.userID = e.donorID '
        . 'WHERE e.siteID = ? AND e.currency = ? AND e.donatedAt BETWEEN ? AND ? '
        . 'AND (e.reference IS NULL OR e.reference NOT LIKE \'Count #%\') '
        . 'AND NOT EXISTS (SELECT 1 FROM tblBankTxns bt WHERE bt.matchedEntryID = e.entryID) '
        . 'ORDER BY e.donatedAt'
    );
    if ($stmt !== false) {
        $stmt->bind_param('isss', $siteId, $currency, $windowStart, $maxTxnDate);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) {
            $entCandidates[] = $r;
        }
        $stmt->close();
    }
}

// 🧮 Index candidates by pence for O(1) per-txn suggestion lookups.
$sessByAmount = [];
foreach ($sessCandidates as $s) {
    $sessByAmount[(int) $s['depositPence']][] = $s;
}
$entByAmount = [];
foreach ($entCandidates as $e) {
    $entByAmount[(int) $e['amountPence']][] = $e;
}

/** Up to 3 suggestions for one unmatched txn — deposits first, then entries. */
$reconcileSuggestions = static function (array $txn) use ($sessByAmount, $entByAmount, $tolDays): array {
    $amt = (int) $txn['amountPence'];
    $txnDate = (string) $txn['txnDate'];
    $windowFrom = date('Y-m-d', strtotime($txnDate . ' -' . $tolDays . ' days'));
    $out = [];
    foreach (($sessByAmount[$amt] ?? []) as $s) {
        if ($s['serviceDate'] >= $windowFrom && $s['serviceDate'] <= $txnDate) {
            $out[] = ['type' => 'session', 'id' => (int) $s['countSessionID'], 'date' => (string) $s['serviceDate']];
        }
    }
    foreach (($entByAmount[$amt] ?? []) as $e) {
        if ($e['donatedAt'] >= $windowFrom && $e['donatedAt'] <= $txnDate) {
            $donor = (string) ($e['donorFullName'] ?? '') !== ''
                ? (string) $e['donorFullName']
                : ((string) ($e['donorName'] ?? '') !== '' ? (string) $e['donorName'] : 'Anonymous');
            $out[] = ['type' => 'entry', 'id' => (int) $e['entryID'], 'date' => (string) $e['donatedAt'], 'donor' => $donor, 'method' => (string) $e['method']];
        }
    }
    return array_slice($out, 0, 3);
};

// -----------------------------------------------------------------------------
// 📋 Bulk-load display details for the MATCHED rows' targets. A manual match
// can point outside the padded suggestion window (no equality is enforced),
// so this is looked up independently by exact ID, not filtered from above.
// -----------------------------------------------------------------------------
$matchedEntryIds = [];
$matchedSessionIds = [];
foreach ($txns as $t) {
    if ($t['matchedEntryID'] !== null) {
        $matchedEntryIds[(int) $t['matchedEntryID']] = true;
    }
    if ($t['matchedCountSessionID'] !== null) {
        $matchedSessionIds[(int) $t['matchedCountSessionID']] = true;
    }
}
$entryDetails = [];
if (count($matchedEntryIds) > 0) {
    $ids = array_keys($matchedEntryIds);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $db->prepare(
        'SELECT e.entryID, e.donatedAt, e.donorName, u.fullName AS donorFullName, c.name AS categoryName '
        . 'FROM tblGivingEntry e LEFT JOIN tblUsers u ON u.userID = e.donorID '
        . 'INNER JOIN tblGivingCategory c ON c.categoryID = e.categoryID '
        . 'WHERE e.entryID IN (' . $placeholders . ')'
    );
    if ($stmt !== false) {
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) {
            $entryDetails[(int) $r['entryID']] = $r;
        }
        $stmt->close();
    }
}
$sessionDetails = [];
if (count($matchedSessionIds) > 0) {
    $ids = array_keys($matchedSessionIds);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $db->prepare('SELECT countSessionID, serviceDate FROM tblCountSessions WHERE countSessionID IN (' . $placeholders . ')');
    if ($stmt !== false) {
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) {
            $sessionDetails[(int) $r['countSessionID']] = $r;
        }
        $stmt->close();
    }
}

// -----------------------------------------------------------------------------
// 📋 Split txns into the three status buckets for display.
// -----------------------------------------------------------------------------
$unmatchedTxns = [];
$matchedTxns   = [];
$ignoredTxns   = [];
foreach ($txns as $t) {
    match ((string) $t['matchStatus']) {
        'matched' => $matchedTxns[] = $t,
        'ignored' => $ignoredTxns[] = $t,
        default   => $unmatchedTxns[] = $t,
    };
}

// -----------------------------------------------------------------------------
// 📋 "In gift log, not in bank" panel — exact statement range (NOT padded).
// -----------------------------------------------------------------------------
$gapSessions = [];
$gapEntries  = [];
if ($minTxnDate !== null && $maxTxnDate !== null) {
    foreach ($sessCandidates as $s) {
        if ((string) $s['serviceDate'] >= $minTxnDate && (string) $s['serviceDate'] <= $maxTxnDate) {
            $gapSessions[] = $s;
        }
    }
    foreach ($entCandidates as $e) {
        if ((string) $e['donatedAt'] >= $minTxnDate && (string) $e['donatedAt'] <= $maxTxnDate) {
            $gapEntries[] = $e;
        }
    }
}

/** In-transit (bg-info) vs missing (bg-danger) badge for a gap-panel row. */
$reconcileGapBadge = static function (string $date) use ($maxTxnDate, $tolDays): array {
    $cutoff = date('Y-m-d', strtotime((string) $maxTxnDate . ' -' . $tolDays . ' days'));
    if ($date > $cutoff) {
        return ['class' => 'bg-info', 'label' => 'Possibly in transit — may post after this statement ends'];
    }
    return ['class' => 'bg-danger', 'label' => 'Not in statement — investigate'];
};

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'Statement: ' . $import['filename'];
$pageSection = 'giving';
$breadcrumbs = ['Dashboard' => '/', 'Giving' => '/giving', 'Reconcile' => '/giving/reconcile', 'Statement' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="mb-0"><i class="fa-solid fa-file-invoice-dollar me-2"></i><?php echo htmlspecialchars((string) $import['filename'], ENT_QUOTES, 'UTF-8'); ?></h1>
    <div class="d-flex gap-2">
        <form method="post" action="/giving/reconcile/match" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="rematch">
            <input type="hidden" name="importID" value="<?php echo $importId; ?>">
            <button type="submit" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-rotate me-1"></i>Re-run auto-match</button>
        </form>
        <form method="post" action="/giving/reconcile/match" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="delete-import">
            <input type="hidden" name="importID" value="<?php echo $importId; ?>">
            <button type="submit" class="btn btn-outline-danger btn-sm"
                    data-confirm="Delete this import and all its <?php echo (int) $import['rowCount']; ?> lines? Matches will be lost."
                    data-confirm-destructive="true">
                <i class="fa-solid fa-trash me-1"></i>Delete import
            </button>
        </form>
        <a href="/giving/reconcile" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <div class="row small">
            <div class="col-6 col-md-2"><strong>Bank:</strong> <span class="badge bg-secondary"><?php echo htmlspecialchars((string) $import['bankKey'], ENT_QUOTES, 'UTF-8'); ?></span></div>
            <div class="col-6 col-md-2"><strong>Currency:</strong> <?php echo htmlspecialchars($currency, ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="col-6 col-md-3"><strong>Imported:</strong> <?php echo htmlspecialchars(date('d/m/Y H:i', (int) strtotime((string) $import['importedAt'])), ENT_QUOTES, 'UTF-8'); ?> by <?php echo htmlspecialchars((string) ($import['importerName'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="col-6 col-md-3"><strong>Range:</strong> <?php echo $minTxnDate !== null ? htmlspecialchars(date('d/m/Y', (int) strtotime($minTxnDate)) . ' – ' . date('d/m/Y', (int) strtotime((string) $maxTxnDate)), ENT_QUOTES, 'UTF-8') : '—'; ?></div>
            <div class="col-6 col-md-2"><strong><?php echo count($matchedTxns); ?>/<?php echo (int) $import['rowCount']; ?></strong> matched</div>
        </div>
    </div>
</div>

<!-- 🔴 Unmatched -->
<div class="card mb-4">
    <div class="card-header"><strong>Unmatched bank credits</strong> <span class="badge bg-danger ms-1"><?php echo count($unmatchedTxns); ?></span></div>
    <div class="card-body p-0">
        <?php if (count($unmatchedTxns) === 0): ?>
            <div class="alert alert-success m-3 mb-0">Nothing unmatched.</div>
        <?php else: ?>
            <div class="portal-data-list">
                <div class="portal-data-row portal-data-header d-none d-md-flex">
                    <div class="col-md-2">Date</div>
                    <div class="col-md-3">Description / Reference</div>
                    <div class="col-md-2 text-end">Amount</div>
                    <div class="col-md-3">Suggestions</div>
                    <div class="col-md-2">Ignore</div>
                </div>
                <?php foreach ($unmatchedTxns as $t):
                    $txnId = (int) $t['txnID'];
                    $suggestions = $reconcileSuggestions($t);
                ?>
                    <div class="portal-data-row">
                        <div class="col-12 col-md-2"><?php echo htmlspecialchars(date('d/m/Y', (int) strtotime((string) $t['txnDate'])), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-12 col-md-3">
                            <?php echo htmlspecialchars((string) $t['description'], ENT_QUOTES, 'UTF-8'); ?>
                            <?php if ((string) ($t['reference'] ?? '') !== ''): ?>
                                <div class="small text-muted"><?php echo htmlspecialchars((string) $t['reference'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12 col-md-2 text-md-end"><strong><?php echo htmlspecialchars(Giving::formatAmount((int) $t['amountPence'], $currency), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div class="col-12 col-md-3 small">
                            <?php if (count($suggestions) === 0): ?>
                                <span class="text-muted">no exact-amount candidate — record the gift or ignore</span>
                            <?php else: ?>
                                <?php foreach ($suggestions as $sug): ?>
                                    <?php if ($sug['type'] === 'session'): ?>
                                        <form method="post" action="/giving/reconcile/match" class="d-flex align-items-center gap-1 mb-1">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="match-session">
                                            <input type="hidden" name="txnID" value="<?php echo $txnId; ?>">
                                            <input type="hidden" name="countSessionID" value="<?php echo (int) $sug['id']; ?>">
                                            <span class="flex-grow-1">Count deposit #<?php echo (int) $sug['id']; ?> — <?php echo htmlspecialchars(date('d/m', (int) strtotime((string) $sug['date'])), ENT_QUOTES, 'UTF-8'); ?></span>
                                            <button type="submit" class="btn btn-outline-success btn-sm">Match</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" action="/giving/reconcile/match" class="d-flex align-items-center gap-1 mb-1">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="match-entry">
                                            <input type="hidden" name="txnID" value="<?php echo $txnId; ?>">
                                            <input type="hidden" name="entryID" value="<?php echo (int) $sug['id']; ?>">
                                            <span class="flex-grow-1"><?php echo htmlspecialchars((string) $sug['donor'], ENT_QUOTES, 'UTF-8'); ?> — <?php echo htmlspecialchars((string) $sug['method'], ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars(date('d/m', (int) strtotime((string) $sug['date'])), ENT_QUOTES, 'UTF-8'); ?></span>
                                            <button type="submit" class="btn btn-outline-success btn-sm">Match</button>
                                        </form>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="col-12 col-md-2">
                            <form method="post" action="/giving/reconcile/match" class="d-flex gap-1">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="ignore">
                                <input type="hidden" name="txnID" value="<?php echo $txnId; ?>">
                                <input type="text" name="matchNote" class="form-control form-control-sm" placeholder="Note (optional)">
                                <button type="submit" class="btn btn-outline-secondary btn-sm">Ignore</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- 🟢 Matched -->
<div class="card mb-4">
    <div class="card-header"><strong>Matched</strong> <span class="badge bg-success ms-1"><?php echo count($matchedTxns); ?></span></div>
    <div class="card-body p-0">
        <?php if (count($matchedTxns) === 0): ?>
            <div class="alert alert-light border m-3 mb-0">Nothing matched yet.</div>
        <?php else: ?>
            <div class="portal-data-list">
                <div class="portal-data-row portal-data-header d-none d-md-flex">
                    <div class="col-md-2">Date</div>
                    <div class="col-md-3">Description</div>
                    <div class="col-md-2 text-end">Amount</div>
                    <div class="col-md-3">Matched to</div>
                    <div class="col-md-2">Action</div>
                </div>
                <?php foreach ($matchedTxns as $t):
                    $matchLabel = '—';
                    if ($t['matchedEntryID'] !== null && isset($entryDetails[(int) $t['matchedEntryID']]) === true) {
                        $ed = $entryDetails[(int) $t['matchedEntryID']];
                        $donor = (string) ($ed['donorFullName'] ?? '') !== '' ? (string) $ed['donorFullName'] : ((string) ($ed['donorName'] ?? '') !== '' ? (string) $ed['donorName'] : 'Anonymous');
                        $matchLabel = 'Gift entry #' . (int) $t['matchedEntryID'] . ' — ' . $donor . ', ' . (string) $ed['categoryName'] . ' ' . date('d/m/Y', (int) strtotime((string) $ed['donatedAt']));
                    } elseif ($t['matchedCountSessionID'] !== null && isset($sessionDetails[(int) $t['matchedCountSessionID']]) === true) {
                        $sd = $sessionDetails[(int) $t['matchedCountSessionID']];
                        $matchLabel = 'Count deposit #' . (int) $t['matchedCountSessionID'] . ' (' . date('d/m/Y', (int) strtotime((string) $sd['serviceDate'])) . ')';
                    }
                ?>
                    <div class="portal-data-row">
                        <div class="col-12 col-md-2"><?php echo htmlspecialchars(date('d/m/Y', (int) strtotime((string) $t['txnDate'])), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-12 col-md-3"><?php echo htmlspecialchars((string) $t['description'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-12 col-md-2 text-md-end"><?php echo htmlspecialchars(Giving::formatAmount((int) $t['amountPence'], $currency), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-12 col-md-3 small"><?php echo htmlspecialchars($matchLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-12 col-md-2">
                            <form method="post" action="/giving/reconcile/match" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="unmatch">
                                <input type="hidden" name="txnID" value="<?php echo (int) $t['txnID']; ?>">
                                <button type="submit" class="btn btn-outline-secondary btn-sm" data-confirm="Unmatch this line?">Unmatch</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ⚪ Ignored -->
<?php if (count($ignoredTxns) > 0): ?>
<div class="card mb-4">
    <div class="card-header"><strong>Ignored</strong> <span class="badge bg-secondary ms-1"><?php echo count($ignoredTxns); ?></span></div>
    <div class="card-body p-0">
        <div class="portal-data-list">
            <div class="portal-data-row portal-data-header d-none d-md-flex">
                <div class="col-md-2">Date</div>
                <div class="col-md-3">Description</div>
                <div class="col-md-2 text-end">Amount</div>
                <div class="col-md-3">Note</div>
                <div class="col-md-2">Action</div>
            </div>
            <?php foreach ($ignoredTxns as $t): ?>
                <div class="portal-data-row">
                    <div class="col-12 col-md-2"><?php echo htmlspecialchars(date('d/m/Y', (int) strtotime((string) $t['txnDate'])), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-12 col-md-3"><?php echo htmlspecialchars((string) $t['description'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-12 col-md-2 text-md-end"><?php echo htmlspecialchars(Giving::formatAmount((int) $t['amountPence'], $currency), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-12 col-md-3 small text-muted"><?php echo htmlspecialchars((string) ($t['matchNote'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-12 col-md-2">
                        <form method="post" action="/giving/reconcile/match" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="unmatch">
                            <input type="hidden" name="txnID" value="<?php echo (int) $t['txnID']; ?>">
                            <button type="submit" class="btn btn-outline-secondary btn-sm">Restore</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 🟡 In gift log, not in bank -->
<div class="card mb-4">
    <div class="card-header"><strong>In gift log, not in this statement</strong></div>
    <div class="card-body p-0">
        <?php if (count($gapSessions) === 0 && count($gapEntries) === 0): ?>
            <div class="alert alert-light border m-3 mb-0">No gift-log gaps in this statement's date range.</div>
        <?php else: ?>
            <div class="portal-data-list">
                <div class="portal-data-row portal-data-header d-none d-md-flex">
                    <div class="col-md-2">Date</div>
                    <div class="col-md-4">Item</div>
                    <div class="col-md-2 text-end">Amount</div>
                    <div class="col-md-4">Status</div>
                </div>
                <?php foreach ($gapSessions as $s):
                    $badge = $reconcileGapBadge((string) $s['serviceDate']);
                ?>
                    <div class="portal-data-row">
                        <div class="col-12 col-md-2"><?php echo htmlspecialchars(date('d/m/Y', (int) strtotime((string) $s['serviceDate'])), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-12 col-md-4">Count deposit #<?php echo (int) $s['countSessionID']; ?> — <?php echo htmlspecialchars((string) $s['categoryName'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-12 col-md-2 text-md-end"><?php echo htmlspecialchars(Giving::formatAmount((int) $s['depositPence'], $siteCurrency), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-12 col-md-4"><span class="badge <?php echo $badge['class']; ?>"><?php echo htmlspecialchars($badge['label'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                    </div>
                <?php endforeach; ?>
                <?php foreach ($gapEntries as $e):
                    $badge = $reconcileGapBadge((string) $e['donatedAt']);
                    $donor = (string) ($e['donorFullName'] ?? '') !== '' ? (string) $e['donorFullName'] : ((string) ($e['donorName'] ?? '') !== '' ? (string) $e['donorName'] : 'Anonymous');
                ?>
                    <div class="portal-data-row">
                        <div class="col-12 col-md-2"><?php echo htmlspecialchars(date('d/m/Y', (int) strtotime((string) $e['donatedAt'])), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-12 col-md-4"><?php echo htmlspecialchars($donor, ENT_QUOTES, 'UTF-8'); ?> — <?php echo htmlspecialchars((string) $e['method'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-12 col-md-2 text-md-end"><?php echo htmlspecialchars(Giving::formatAmount((int) $e['amountPence'], $currency), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-12 col-md-4"><span class="badge <?php echo $badge['class']; ?>"><?php echo htmlspecialchars($badge['label'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($depositsApplicable === false): ?>
            <p class="small text-muted p-3 mb-0">Count-session deposits are not shown — this statement's currency (<?php echo htmlspecialchars($currency, ENT_QUOTES, 'UTF-8'); ?>) differs from the site's giving currency (<?php echo htmlspecialchars($siteCurrency, ENT_QUOTES, 'UTF-8'); ?>); offering-count sessions carry no per-currency record.</p>
        <?php endif; ?>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
