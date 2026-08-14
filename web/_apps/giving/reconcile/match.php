<?php
// Path: _apps/giving/reconcile/match.php
/**
 * -----------------------------------------------------------------------------
 * Giving — Bank Reconciliation: Manual Match Actions 🔗
 * -----------------------------------------------------------------------------
 * POST-only multiplexed handler (#299 sub-feature 3), mirroring the
 * giving/count/save.php `action`-dispatcher pattern:
 *
 *   action=match-entry   — manually match a bank credit to one tblGivingEntry
 *                          row. Amount/date equality is deliberately NOT
 *                          enforced server-side — a treasurer override is
 *                          the whole point of a MANUAL match; the UI shows
 *                          both sides so the human can judge it.
 *   action=match-session — manually match a bank credit to a closed
 *                          offering-count deposit (tblCountSessions).
 *   action=unmatch        — clear a matched OR ignored line back to
 *                          'unmatched' (also serves as "un-ignore").
 *   action=ignore         — mark a line 'ignored' with an optional note
 *                          (e.g. hall-hire income that isn't giving).
 *   action=rematch        — re-run the shared auto-matcher for one import.
 *   action=delete-import  — delete an import batch (CASCADEs its lines);
 *                          matched-gift-entry / count-session flags are
 *                          implicit (NOT EXISTS queries), so nothing else
 *                          needs cleaning up.
 *
 * Every UPDATE/DELETE carries `AND siteID = ?`. The two matched-target FKs
 * (`matchedEntryID`, `matchedCountSessionID`) are always written as a
 * mutually-exclusive pair — the one being set, the other explicitly NULL.
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

// 🔗 Shared auto-matcher — plain require_once, NOT a tblRoutes entry.
require_once PORTAL_APPS . DIRECTORY_SEPARATOR . 'giving' . DIRECTORY_SEPARATOR . 'reconcile' . DIRECTORY_SEPARATOR . '_automatch.php';

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');

/**
 * 🔍 Fetch a bank txn row, siteID-scoped, joined to its import for the
 * import's currency + to know which view page to redirect back to.
 *
 * @return array<string, mixed>|null
 */
function fetchBankTxn(\mysqli $db, int $txnId, int $siteId): ?array
{
    $stmt = $db->prepare(
        'SELECT bt.txnID, bt.importID, bt.siteID, bt.matchStatus, bi.currency AS importCurrency '
        . 'FROM tblBankTxns bt INNER JOIN tblBankImports bi ON bi.importID = bt.importID '
        . 'WHERE bt.txnID = ? AND bt.siteID = ? LIMIT 1'
    );
    if ($stmt === false) {
        return null;
    }
    $stmt->bind_param('ii', $txnId, $siteId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row;
}

/**
 * 🔁 Redirect back to a statement's view page with a flash message. Never
 * returns.
 */
function backToView(int $importId, string $msg, string $type): void
{
    $_SESSION['flash_msg']  = $msg;
    $_SESSION['flash_type'] = $type;
    header('Location: /giving/reconcile/view?id=' . $importId);
    exit();
}

// -----------------------------------------------------------------------------
// 🔗 action=match-entry — manual txn ↔ gift-entry match
// -----------------------------------------------------------------------------
if ($action === 'match-entry') {
    $txnId   = (int) ($_POST['txnID'] ?? 0);
    $entryId = (int) ($_POST['entryID'] ?? 0);
    if ($txnId <= 0 || $entryId <= 0) {
        Router::renderError(400);
        return;
    }
    $txn = fetchBankTxn($db, $txnId, $siteId);
    if ($txn === null) {
        Router::renderError(404);
        return;
    }
    if (in_array((string) $txn['matchStatus'], ['unmatched', 'ignored'], true) === false) {
        backToView((int) $txn['importID'], 'This line is already matched — unmatch it first.', 'warning');
    }

    $entryValid = false;
    $stmt = $db->prepare(
        'SELECT e.entryID FROM tblGivingEntry e '
        . 'WHERE e.entryID = ? AND e.siteID = ? AND e.currency = ? '
        . 'AND NOT EXISTS (SELECT 1 FROM tblBankTxns bt2 WHERE bt2.matchedEntryID = e.entryID) LIMIT 1'
    );
    if ($stmt !== false) {
        $importCurrency = (string) $txn['importCurrency'];
        $stmt->bind_param('iis', $entryId, $siteId, $importCurrency);
        $stmt->execute();
        $entryValid = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    }
    if ($entryValid === false) {
        backToView((int) $txn['importID'], 'That gift entry is not available to match (wrong site/currency, or already matched).', 'danger');
    }

    $stmt = $db->prepare(
        'UPDATE tblBankTxns SET matchStatus = \'matched\', matchedEntryID = ?, matchedCountSessionID = NULL, '
        . 'matchNote = NULL, matchedByID = ?, matchedAt = NOW() '
        . 'WHERE txnID = ? AND siteID = ? AND matchStatus IN (\'unmatched\', \'ignored\')'
    );
    if ($stmt !== false) {
        $stmt->bind_param('iiii', $entryId, $userId, $txnId, $siteId);
        $stmt->execute();
        $stmt->close();
    }

    Logger::activity('BankTxnMatched', 'Matched bank txn #' . $txnId . ' to gift entry #' . $entryId, $userId);
    backToView((int) $txn['importID'], 'Matched to gift entry #' . $entryId . '.', 'success');
}

// -----------------------------------------------------------------------------
// 🏦 action=match-session — manual txn ↔ closed count-session (deposit) match
// -----------------------------------------------------------------------------
if ($action === 'match-session') {
    $txnId          = (int) ($_POST['txnID'] ?? 0);
    $countSessionId = (int) ($_POST['countSessionID'] ?? 0);
    if ($txnId <= 0 || $countSessionId <= 0) {
        Router::renderError(400);
        return;
    }
    $txn = fetchBankTxn($db, $txnId, $siteId);
    if ($txn === null) {
        Router::renderError(404);
        return;
    }
    if (in_array((string) $txn['matchStatus'], ['unmatched', 'ignored'], true) === false) {
        backToView((int) $txn['importID'], 'This line is already matched — unmatch it first.', 'warning');
    }

    $sessionValid = false;
    $stmt = $db->prepare(
        'SELECT cs.countSessionID FROM tblCountSessions cs '
        . 'WHERE cs.countSessionID = ? AND cs.siteID = ? AND cs.status = \'closed\' '
        . 'AND NOT EXISTS (SELECT 1 FROM tblBankTxns bt2 WHERE bt2.matchedCountSessionID = cs.countSessionID) LIMIT 1'
    );
    if ($stmt !== false) {
        $stmt->bind_param('ii', $countSessionId, $siteId);
        $stmt->execute();
        $sessionValid = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    }
    if ($sessionValid === false) {
        backToView((int) $txn['importID'], 'That count session is not available to match (not closed, wrong site, or already matched).', 'danger');
    }

    $stmt = $db->prepare(
        'UPDATE tblBankTxns SET matchStatus = \'matched\', matchedCountSessionID = ?, matchedEntryID = NULL, '
        . 'matchNote = NULL, matchedByID = ?, matchedAt = NOW() '
        . 'WHERE txnID = ? AND siteID = ? AND matchStatus IN (\'unmatched\', \'ignored\')'
    );
    if ($stmt !== false) {
        $stmt->bind_param('iiii', $countSessionId, $userId, $txnId, $siteId);
        $stmt->execute();
        $stmt->close();
    }

    Logger::activity('BankTxnMatched', 'Matched bank txn #' . $txnId . ' to count session #' . $countSessionId, $userId);
    backToView((int) $txn['importID'], 'Matched to count session #' . $countSessionId . '.', 'success');
}

// -----------------------------------------------------------------------------
// ♻️ action=unmatch — clear a matched/ignored line back to unmatched
// -----------------------------------------------------------------------------
if ($action === 'unmatch') {
    $txnId = (int) ($_POST['txnID'] ?? 0);
    if ($txnId <= 0) {
        Router::renderError(400);
        return;
    }
    $txn = fetchBankTxn($db, $txnId, $siteId);
    if ($txn === null) {
        Router::renderError(404);
        return;
    }

    $stmt = $db->prepare(
        'UPDATE tblBankTxns SET matchStatus = \'unmatched\', matchedEntryID = NULL, matchedCountSessionID = NULL, '
        . 'matchNote = NULL, matchedByID = NULL, matchedAt = NULL WHERE txnID = ? AND siteID = ?'
    );
    if ($stmt !== false) {
        $stmt->bind_param('ii', $txnId, $siteId);
        $stmt->execute();
        $stmt->close();
    }

    Logger::activity('BankTxnUnmatched', 'Unmatched bank txn #' . $txnId, $userId);
    backToView((int) $txn['importID'], 'Line set back to unmatched.', 'success');
}

// -----------------------------------------------------------------------------
// 🙈 action=ignore — mark a line ignored (e.g. non-giving income)
// -----------------------------------------------------------------------------
if ($action === 'ignore') {
    $txnId = (int) ($_POST['txnID'] ?? 0);
    if ($txnId <= 0) {
        Router::renderError(400);
        return;
    }
    $txn = fetchBankTxn($db, $txnId, $siteId);
    if ($txn === null) {
        Router::renderError(404);
        return;
    }

    $note = mb_substr(trim((string) ($_POST['matchNote'] ?? '')), 0, 255);
    $noteParam = $note !== '' ? $note : null;

    $stmt = $db->prepare(
        'UPDATE tblBankTxns SET matchStatus = \'ignored\', matchNote = ?, matchedByID = ?, matchedAt = NOW(), '
        . 'matchedEntryID = NULL, matchedCountSessionID = NULL WHERE txnID = ? AND siteID = ?'
    );
    if ($stmt !== false) {
        $stmt->bind_param('siii', $noteParam, $userId, $txnId, $siteId);
        $stmt->execute();
        $stmt->close();
    }

    Logger::activity('BankTxnIgnored', 'Ignored bank txn #' . $txnId, $userId);
    backToView((int) $txn['importID'], 'Line marked as ignored.', 'success');
}

// -----------------------------------------------------------------------------
// 🤖 action=rematch — re-run the shared auto-matcher for one import
// -----------------------------------------------------------------------------
if ($action === 'rematch') {
    $importId = (int) ($_POST['importID'] ?? 0);
    if ($importId <= 0) {
        Router::renderError(400);
        return;
    }

    $import = null;
    $stmt = $db->prepare('SELECT importID, currency FROM tblBankImports WHERE importID = ? AND siteID = ? LIMIT 1');
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

    $tolDays = max(0, (int) (App::settings('giving.reconcile.toleranceDays') ?? '5'));
    $matched = giving_reconcile_automatch($db, $siteId, $importId, $userId, $tolDays, (string) $import['currency']);

    Logger::activity('BankRematchRun', 'Re-ran auto-match on import #' . $importId . ' — ' . $matched . ' new matches', $userId);
    backToView($importId, $matched . ' new match' . ($matched === 1 ? '' : 'es') . '.', $matched > 0 ? 'success' : 'info');
}

// -----------------------------------------------------------------------------
// 🗑️ action=delete-import — delete a whole import batch
// -----------------------------------------------------------------------------
if ($action === 'delete-import') {
    $importId = (int) ($_POST['importID'] ?? 0);
    if ($importId <= 0) {
        Router::renderError(400);
        return;
    }

    $stmt = $db->prepare('DELETE FROM tblBankImports WHERE importID = ? AND siteID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('ii', $importId, $siteId);
        $stmt->execute();
        $stmt->close();
    }

    Logger::activity('BankImportDeleted', 'Deleted bank import #' . $importId, $userId);
    $_SESSION['flash_msg']  = 'Import deleted.';
    $_SESSION['flash_type'] = 'success';
    header('Location: /giving/reconcile');
    exit();
}

// -----------------------------------------------------------------------------
// ❓ Unknown action
// -----------------------------------------------------------------------------
Router::renderError(400);
