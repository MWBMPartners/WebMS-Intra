<?php
// Path: _apps/giving/count/save.php
/**
 * -----------------------------------------------------------------------------
 * Giving — Offering Count Session: Save Handler 💷
 * -----------------------------------------------------------------------------
 * Multiplexed POST handler for the offering-count workflow (#299 sub-feature
 * 1) — one endpoint, several `action` values, mirroring the pattern already
 * used by giving/cat-save.php:
 *
 *   action=create           — start a new count session.
 *   action=count            — a counter (slot 1 or 2) submits their
 *                             independent cash/cheque/envelope totals.
 *                             Recomputes discrepancy status afterwards.
 *   action=resolve          — ADMIN ONLY: force the agreed totals over a
 *                             live 'discrepancy' (or re-resolve at any time).
 *   action=envelope-add     — log a named giving-envelope against the
 *                             session's envelope total.
 *   action=envelope-delete  — remove a previously logged named envelope.
 *
 * Every branch is siteID-scoped, CSRF-checked, and gated by
 * Portal\Core\Giving::canManage() (site admin OR the `treasurer` role) —
 * `resolve` additionally requires App::isAdmin() (see the "an admin
 * resolves" requirement in #299).
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
$action = (string) ($_POST['action'] ?? '');

/**
 * 🔍 Fetch a count session row, siteID-scoped. Shared by every branch below —
 * every mutation on a session re-validates it belongs to the active site
 * before touching it.
 *
 * @return array<string, mixed>|null
 */
function fetchCountSession(\mysqli $db, int $countSessionId, int $siteId): ?array
{
    $stmt = $db->prepare('SELECT * FROM tblCountSessions WHERE countSessionID = ? AND siteID = ? LIMIT 1');
    if ($stmt === false) {
        return null;
    }
    $stmt->bind_param('ii', $countSessionId, $siteId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row;
}

/**
 * 🔁 Redirect back to a session's detail page with a flash message. Never
 * returns (exits the script), so callers can invoke it as the last step of
 * a branch.
 */
function backToCountSession(int $id, string $msg, string $type): void
{
    $_SESSION['flash_msg']  = $msg;
    $_SESSION['flash_type'] = $type;
    header('Location: /giving/count/session?id=' . $id);
    exit();
}

// -----------------------------------------------------------------------------
// ➕ action=create — start a new count session
// -----------------------------------------------------------------------------
if ($action === 'create') {
    $serviceDate = (string) ($_POST['serviceDate'] ?? '');
    $categoryId  = (int) ($_POST['categoryID'] ?? 0);
    $counter1    = (int) ($_POST['counter1ID'] ?? 0);
    $counter2    = (int) ($_POST['counter2ID'] ?? 0);

    if ($serviceDate === '' || strtotime($serviceDate) === false || $categoryId <= 0) {
        $_SESSION['flash_msg']  = 'Service date and category are required.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /giving/count');
        exit();
    }

    // 🛡️ Category must belong to this site.
    $stmt = $db->prepare('SELECT 1 FROM tblGivingCategory WHERE categoryID = ? AND siteID = ? LIMIT 1');
    $categoryValid = false;
    if ($stmt !== false) {
        $stmt->bind_param('ii', $categoryId, $siteId);
        $stmt->execute();
        $categoryValid = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    }
    if ($categoryValid === false) {
        $_SESSION['flash_msg']  = 'Invalid category.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /giving/count');
        exit();
    }

    $counter1Id = $counter1 > 0 ? $counter1 : null;
    $counter2Id = $counter2 > 0 ? $counter2 : null;

    $newId = 0;
    $stmt = $db->prepare(
        'INSERT INTO tblCountSessions (siteID, serviceDate, categoryID, counter1ID, counter2ID, status, createdByID) '
        . 'VALUES (?, ?, ?, ?, ?, \'open\', ?)'
    );
    if ($stmt !== false) {
        $stmt->bind_param('isiiii', $siteId, $serviceDate, $categoryId, $counter1Id, $counter2Id, $userId);
        $stmt->execute();
        $newId = (int) $stmt->insert_id;
        $stmt->close();
    }

    if ($newId <= 0) {
        $_SESSION['flash_msg']  = 'Could not create the count session.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /giving/count');
        exit();
    }

    Logger::activity('CountSessionCreated', 'Started offering count session for ' . $serviceDate, $userId);
    backToCountSession($newId, 'Count session started. Counters can now enter their totals.', 'success');
}

// -----------------------------------------------------------------------------
// 🔢 action=count — a counter submits their independent totals
// -----------------------------------------------------------------------------
if ($action === 'count') {
    $countSessionId = (int) ($_POST['countSessionID'] ?? 0);
    $slot           = (string) ($_POST['slot'] ?? '');

    if ($countSessionId <= 0 || in_array($slot, ['1', '2'], true) === false) {
        Router::renderError(400);
        return;
    }

    $session = fetchCountSession($db, $countSessionId, $siteId);
    if ($session === null) {
        Router::renderError(404);
        return;
    }
    if ($session['status'] === 'closed') {
        backToCountSession($countSessionId, 'This session is already closed.', 'warning');
    }

    $cash     = Giving::parseDecimal((string) ($_POST['cashAmount'] ?? ''));
    $cheque   = Giving::parseDecimal((string) ($_POST['chequeAmount'] ?? ''));
    $envelope = Giving::parseDecimal((string) ($_POST['envelopeAmount'] ?? ''));

    if ($cash === null || $cheque === null || $envelope === null) {
        backToCountSession($countSessionId, 'Cash, cheque, and envelope totals must all be valid non-negative amounts.', 'danger');
    }

    // 🛡️ Static SQL per slot (no dynamic column-name interpolation) — keeps
    // this readable by the column-name-existence audit
    // (tools/audit-checks/check_sql_columns.py) and avoids ever building a
    // column identifier out of request data.
    if ($slot === '1') {
        $stmt = $db->prepare(
            'UPDATE tblCountSessions SET cashTotal1 = ?, chequeTotal1 = ?, envelopeTotal1 = ? '
            . 'WHERE countSessionID = ? AND siteID = ?'
        );
    } else {
        $stmt = $db->prepare(
            'UPDATE tblCountSessions SET cashTotal2 = ?, chequeTotal2 = ?, envelopeTotal2 = ? '
            . 'WHERE countSessionID = ? AND siteID = ?'
        );
    }
    if ($stmt !== false) {
        $stmt->bind_param('sssii', $cash, $cheque, $envelope, $countSessionId, $siteId);
        $stmt->execute();
        $stmt->close();
    }

    // 🧮 Re-fetch and recompute the discrepancy/agreement state.
    $session = fetchCountSession($db, $countSessionId, $siteId);
    $twoCounterRequired = (App::settings('giving.countRequiresTwoCounters') ?? 'true') !== 'false';

    $slot1Complete = $session['cashTotal1'] !== null && $session['chequeTotal1'] !== null && $session['envelopeTotal1'] !== null;
    $slot2Complete = $session['cashTotal2'] !== null && $session['chequeTotal2'] !== null && $session['envelopeTotal2'] !== null;

    $newStatus      = 'counting';
    $agreedCash     = null;
    $agreedCheque   = null;
    $agreedEnvelope = null;
    $flashMsg       = 'Counter ' . $slot . ' totals saved.';
    $flashType      = 'success';

    if ($twoCounterRequired === false && ($slot1Complete === true || $slot2Complete === true)) {
        // 🧑 Single-counter mode — whichever slot is complete is authoritative.
        // If both happen to be complete, slot 1 wins for determinism.
        $source         = $slot1Complete === true ? '1' : '2';
        $agreedCash     = $session['cashTotal' . $source];
        $agreedCheque   = $session['chequeTotal' . $source];
        $agreedEnvelope = $session['envelopeTotal' . $source];
        $flashMsg       = 'Counter ' . $slot . ' totals saved and agreed (single-counter mode).';
    } elseif ($slot1Complete === true && $slot2Complete === true) {
        $toPence = static fn (string $v): int => (int) round(((float) $v) * 100);
        $matches = $toPence($session['cashTotal1']) === $toPence($session['cashTotal2'])
            && $toPence($session['chequeTotal1']) === $toPence($session['chequeTotal2'])
            && $toPence($session['envelopeTotal1']) === $toPence($session['envelopeTotal2']);
        if ($matches === true) {
            $agreedCash     = $session['cashTotal1'];
            $agreedCheque   = $session['chequeTotal1'];
            $agreedEnvelope = $session['envelopeTotal1'];
            $flashMsg       = 'Counter ' . $slot . ' totals saved — both counts agree. Ready to close.';
        } else {
            $newStatus = 'discrepancy';
            $flashMsg  = 'Counter ' . $slot . ' totals saved — DISCREPANCY: the two counts do not match.';
            $flashType = 'danger';
        }
    }

    $stmt = $db->prepare(
        'UPDATE tblCountSessions SET status = ?, cashTotal = ?, chequeTotal = ?, envelopeTotal = ? '
        . 'WHERE countSessionID = ? AND siteID = ?'
    );
    if ($stmt !== false) {
        $stmt->bind_param('ssssii', $newStatus, $agreedCash, $agreedCheque, $agreedEnvelope, $countSessionId, $siteId);
        $stmt->execute();
        $stmt->close();
    }

    Logger::activity('CountSessionCounted', 'Counter ' . $slot . ' entered totals for session #' . $countSessionId . ' (status: ' . $newStatus . ')', $userId);
    backToCountSession($countSessionId, $flashMsg, $flashType);
}

// -----------------------------------------------------------------------------
// 🛡️ action=resolve — ADMIN ONLY: force the agreed totals over a discrepancy
// -----------------------------------------------------------------------------
if ($action === 'resolve') {
    if (App::isAdmin() === false) {
        Router::renderError(403);
        return;
    }

    $countSessionId = (int) ($_POST['countSessionID'] ?? 0);
    if ($countSessionId <= 0) {
        Router::renderError(400);
        return;
    }
    $session = fetchCountSession($db, $countSessionId, $siteId);
    if ($session === null) {
        Router::renderError(404);
        return;
    }
    if ($session['status'] === 'closed') {
        backToCountSession($countSessionId, 'This session is already closed.', 'warning');
    }

    $cash     = Giving::parseDecimal((string) ($_POST['cashAmount'] ?? ''));
    $cheque   = Giving::parseDecimal((string) ($_POST['chequeAmount'] ?? ''));
    $envelope = Giving::parseDecimal((string) ($_POST['envelopeAmount'] ?? ''));
    $notes    = trim((string) ($_POST['notes'] ?? ''));

    if ($cash === null || $cheque === null || $envelope === null) {
        backToCountSession($countSessionId, 'Agreed cash, cheque, and envelope totals must all be valid non-negative amounts.', 'danger');
    }

    $stmt = $db->prepare(
        'UPDATE tblCountSessions SET status = \'counting\', cashTotal = ?, chequeTotal = ?, envelopeTotal = ?, notes = ? '
        . 'WHERE countSessionID = ? AND siteID = ?'
    );
    if ($stmt !== false) {
        $stmt->bind_param('ssssii', $cash, $cheque, $envelope, $notes, $countSessionId, $siteId);
        $stmt->execute();
        $stmt->close();
    }

    Logger::activity('CountSessionResolved', 'Admin resolved discrepancy for session #' . $countSessionId, $userId);
    backToCountSession($countSessionId, 'Discrepancy resolved with agreed totals. Ready to close.', 'success');
}

// -----------------------------------------------------------------------------
// ✉️ action=envelope-add — log a named giving envelope
// -----------------------------------------------------------------------------
if ($action === 'envelope-add') {
    $countSessionId = (int) ($_POST['countSessionID'] ?? 0);
    if ($countSessionId <= 0) {
        Router::renderError(400);
        return;
    }
    $session = fetchCountSession($db, $countSessionId, $siteId);
    if ($session === null) {
        Router::renderError(404);
        return;
    }
    if ($session['status'] === 'closed') {
        backToCountSession($countSessionId, 'This session is already closed.', 'warning');
    }

    $giverId   = (int) ($_POST['giverID'] ?? 0);
    $giverName = trim((string) ($_POST['giverName'] ?? ''));
    $amount    = Giving::parseDecimal((string) ($_POST['amount'] ?? ''));
    $method    = (string) ($_POST['method'] ?? 'cash');
    if (in_array($method, ['cash', 'cheque'], true) === false) {
        $method = 'cash';
    }

    if ($amount === null || (float) $amount <= 0) {
        backToCountSession($countSessionId, 'A valid positive amount is required for a named envelope.', 'danger');
    }

    // 🔍 Resolve giverID against an active member; free-text name is only
    // used when no member is picked (matches entry-save.php's donor
    // resolution: a blank/unmatched name is anonymous, never guessed at).
    $giverIdParam = null;
    if ($giverId > 0) {
        $stmt = $db->prepare('SELECT 1 FROM tblUsers WHERE userID = ? AND isActive = 1 LIMIT 1');
        if ($stmt !== false) {
            $stmt->bind_param('i', $giverId);
            $stmt->execute();
            if ($stmt->get_result()->fetch_row() !== null) {
                $giverIdParam = $giverId;
            }
            $stmt->close();
        }
    }
    $giverNameParam = $giverIdParam === null && $giverName !== '' ? $giverName : null;

    if ($giverIdParam === null && $giverNameParam === null) {
        backToCountSession($countSessionId, 'Pick a member or enter a name for the named envelope.', 'danger');
    }

    $stmt = $db->prepare(
        'INSERT INTO tblCountEnvelopes (countSessionID, giverID, giverName, amount, method) VALUES (?, ?, ?, ?, ?)'
    );
    if ($stmt !== false) {
        $stmt->bind_param('iisss', $countSessionId, $giverIdParam, $giverNameParam, $amount, $method);
        $stmt->execute();
        $stmt->close();
    }

    Logger::activity(
        'CountEnvelopeAdded',
        'Logged a named envelope (' . Giving::formatAmount((int) round(((float) $amount) * 100)) . ') for session #' . $countSessionId,
        $userId
    );
    backToCountSession($countSessionId, 'Named envelope logged.', 'success');
}

// -----------------------------------------------------------------------------
// 🗑️ action=envelope-delete — remove a named envelope
// -----------------------------------------------------------------------------
if ($action === 'envelope-delete') {
    $countSessionId = (int) ($_POST['countSessionID'] ?? 0);
    $envelopeId     = (int) ($_POST['envelopeID'] ?? 0);
    if ($countSessionId <= 0 || $envelopeId <= 0) {
        Router::renderError(400);
        return;
    }
    $session = fetchCountSession($db, $countSessionId, $siteId);
    if ($session === null) {
        Router::renderError(404);
        return;
    }
    if ($session['status'] === 'closed') {
        backToCountSession($countSessionId, 'This session is already closed.', 'warning');
    }

    $stmt = $db->prepare('DELETE FROM tblCountEnvelopes WHERE envelopeID = ? AND countSessionID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('ii', $envelopeId, $countSessionId);
        $stmt->execute();
        $stmt->close();
    }

    Logger::activity('CountEnvelopeDeleted', 'Removed named envelope #' . $envelopeId . ' from session #' . $countSessionId, $userId);
    backToCountSession($countSessionId, 'Named envelope removed.', 'success');
}

// -----------------------------------------------------------------------------
// ❓ Unknown action
// -----------------------------------------------------------------------------
Router::renderError(400);
