<?php
// Path: _apps/giving/count/session.php
/**
 * -----------------------------------------------------------------------------
 * Giving — Offering Count Session Detail 💷
 * -----------------------------------------------------------------------------
 * The working page for a single two-person offering count (#299 sub-feature
 * 1): Counter 1 and Counter 2 each enter their independent tallies for THREE
 * SEPARATE buckets — loose cash, loose cheques, and (numbered/named giving)
 * envelope total — the deposit is their sum. Discrepancies are flagged
 * automatically once both counters are in, named giving-envelopes can be
 * logged against the session's envelope-total bucket (itemising who gave
 * what, up to that bucket's agreed total), and — once the totals are agreed
 * and the named envelopes don't exceed the agreed envelope total — the
 * session can be closed, writing the actual gift log entries.
 *
 * Gate matches every other financial action in `giving`:
 * Portal\Core\Giving::canManage() (site admin OR the `treasurer` role).
 * Resolving a 'discrepancy' additionally requires App::isAdmin() (see
 * giving/count/save.php).
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

$countSessionId = (int) ($_GET['id'] ?? 0);
if ($countSessionId <= 0) {
    Router::renderError(404);
    return;
}

// -----------------------------------------------------------------------------
// 📋 Fetch the session (siteID-scoped)
// -----------------------------------------------------------------------------
$session = null;
$stmt = $db->prepare(
    'SELECT cs.*, c.name AS categoryName, '
    . '       u1.fullName AS counter1Name, u2.fullName AS counter2Name, '
    . '       uc.fullName AS closedByName '
    . 'FROM tblCountSessions cs '
    . 'INNER JOIN tblGivingCategory c ON c.categoryID = cs.categoryID '
    . 'LEFT JOIN tblUsers u1 ON u1.userID = cs.counter1ID '
    . 'LEFT JOIN tblUsers u2 ON u2.userID = cs.counter2ID '
    . 'LEFT JOIN tblUsers uc ON uc.userID = cs.closedByID '
    . 'WHERE cs.countSessionID = ? AND cs.siteID = ? LIMIT 1'
);
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
// 📋 Fetch named envelopes for this session
// -----------------------------------------------------------------------------
$envelopes = [];
$envelopeSum = 0.0;
$stmt = $db->prepare(
    'SELECT ce.envelopeID, ce.giverID, ce.giverName, ce.amount, ce.method, ce.createdAt, '
    . '       u.fullName AS giverFullName '
    . 'FROM tblCountEnvelopes ce '
    . 'LEFT JOIN tblUsers u ON u.userID = ce.giverID '
    . 'WHERE ce.countSessionID = ? ORDER BY ce.createdAt, ce.envelopeID'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $countSessionId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $envelopes[] = $r;
        $envelopeSum += (float) $r['amount'];
    }
    $stmt->close();
}

// 📋 Active users (for the "add named envelope" giver select)
$users = [];
$r = $db->query('SELECT userID, fullName FROM tblUsers WHERE isActive = 1 ORDER BY fullName LIMIT 500');
if ($r !== false) {
    while ($u = $r->fetch_assoc()) {
        $users[] = $u;
    }
    $r->free();
}

// -----------------------------------------------------------------------------
// 🧮 Derived state
// -----------------------------------------------------------------------------
$bothSubmitted = $session['cashTotal1'] !== null && $session['chequeTotal1'] !== null && $session['envelopeTotal1'] !== null
    && $session['cashTotal2'] !== null && $session['chequeTotal2'] !== null && $session['envelopeTotal2'] !== null;

// 💷 Pence-integer comparisons throughout — never compare DECIMAL/float
// values with == directly (rounding drift).
$toPence = static fn (?string $v): ?int => $v === null ? null : (int) round(((float) $v) * 100);

$diffCash     = $bothSubmitted === true ? $toPence($session['cashTotal1']) - $toPence($session['cashTotal2']) : null;
$diffCheque   = $bothSubmitted === true ? $toPence($session['chequeTotal1']) - $toPence($session['chequeTotal2']) : null;
$diffEnvelope = $bothSubmitted === true ? $toPence($session['envelopeTotal1']) - $toPence($session['envelopeTotal2']) : null;

$agreedSet = $session['cashTotal'] !== null && $session['chequeTotal'] !== null && $session['envelopeTotal'] !== null;
$envelopeSumPence   = (int) round($envelopeSum * 100);
$agreedEnvelopePence = $session['envelopeTotal'] !== null ? $toPence($session['envelopeTotal']) : null;
// 🛡️ Named envelopes may UNDER-shoot the agreed envelope-total bucket (the
// remainder posts as one aggregate "loose envelope" entry on close — see
// close.php) but must never OVER-shoot it — matches close.php's own guard.
$envelopesReconcile = $agreedEnvelopePence !== null && $envelopeSumPence <= $agreedEnvelopePence;

$canClose = $session['status'] === 'counting' && $agreedSet === true && $envelopesReconcile === true;

$statusClass = match ($session['status']) {
    'open'        => 'secondary',
    'counting'    => 'primary',
    'discrepancy' => 'danger',
    'closed'      => 'success',
    default       => 'secondary',
};

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'Count Session — ' . date('d/m/Y', (int) strtotime((string) $session['serviceDate']));
$pageSection = 'giving';
$breadcrumbs = ['Dashboard' => '/', 'Giving' => '/giving', 'Count Sessions' => '/giving/count', $pageTitle => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-money-bill-transfer me-2"></i>Count Session — <?php echo htmlspecialchars(date('D, j M Y', (int) strtotime((string) $session['serviceDate'])), ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="text-secondary mb-0"><?php echo htmlspecialchars((string) $session['categoryName'], ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
    <span class="badge bg-<?php echo $statusClass; ?> fs-6"><?php echo htmlspecialchars((string) $session['status'], ENT_QUOTES, 'UTF-8'); ?></span>
</div>

<?php if ($session['status'] === 'discrepancy'): ?>
    <div class="alert alert-danger">
        <i class="fa-solid fa-triangle-exclamation me-1"></i>
        <strong>Discrepancy:</strong> Counter 1 and Counter 2's totals don't match. Either counter can re-enter their
        totals below, or an admin can resolve by entering the agreed totals directly. This session cannot be closed
        until it's resolved.
    </div>
<?php endif; ?>

<?php if ($session['status'] === 'closed'): ?>
    <div class="alert alert-success">
        <i class="fa-solid fa-circle-check me-1"></i>
        Closed by <strong><?php echo htmlspecialchars((string) ($session['closedByName'] ?? 'a user'), ENT_QUOTES, 'UTF-8'); ?></strong>
        on <?php echo htmlspecialchars(date('d/m/Y H:i', (int) strtotime((string) $session['closedAt'])), ENT_QUOTES, 'UTF-8'); ?>.
        The gift log has been written for this service date.
    </div>
<?php endif; ?>

<p class="text-muted small">Three separate buckets — the deposit is <strong>Loose cash + Loose cheque + Envelope total</strong>. Envelope giving is separate money (from named/numbered giving envelopes), not a breakdown of the cash or cheque figures.</p>

<!-- 👥 Counter entry cards -->
<div class="row g-3 mb-4">
    <?php foreach ([1, 2] as $slot):
        $counterName = $session['counter' . $slot . 'Name'];
        $cash     = $session['cashTotal' . $slot];
        $cheque   = $session['chequeTotal' . $slot];
        $envelope = $session['envelopeTotal' . $slot];
        $submitted = $cash !== null && $cheque !== null && $envelope !== null;
    ?>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Counter <?php echo $slot; ?><?php echo $counterName !== null ? ' — ' . htmlspecialchars((string) $counterName, ENT_QUOTES, 'UTF-8') : ''; ?></strong>
                <?php if ($submitted === true): ?>
                    <span class="badge bg-success">Submitted</span>
                <?php else: ?>
                    <span class="badge bg-secondary">Not yet entered</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($session['status'] === 'closed'): ?>
                    <div class="row small">
                        <div class="col-4">Loose cash<br><strong>&pound;<?php echo $cash !== null ? number_format((float) $cash, 2) : '—'; ?></strong></div>
                        <div class="col-4">Loose cheque<br><strong>&pound;<?php echo $cheque !== null ? number_format((float) $cheque, 2) : '—'; ?></strong></div>
                        <div class="col-4">Envelope total<br><strong>&pound;<?php echo $envelope !== null ? number_format((float) $envelope, 2) : '—'; ?></strong></div>
                    </div>
                <?php else: ?>
                    <form method="post" action="/giving/count/save" class="row g-2">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="count">
                        <input type="hidden" name="countSessionID" value="<?php echo $countSessionId; ?>">
                        <input type="hidden" name="slot" value="<?php echo $slot; ?>">
                        <div class="col-4">
                            <label class="form-label small">Loose cash</label>
                            <input type="text" class="form-control form-control-sm" name="cashAmount" value="<?php echo $cash !== null ? htmlspecialchars(number_format((float) $cash, 2), ENT_QUOTES, 'UTF-8') : ''; ?>" placeholder="0.00" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label small">Loose cheque</label>
                            <input type="text" class="form-control form-control-sm" name="chequeAmount" value="<?php echo $cheque !== null ? htmlspecialchars(number_format((float) $cheque, 2), ENT_QUOTES, 'UTF-8') : ''; ?>" placeholder="0.00" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label small">Envelope total</label>
                            <input type="text" class="form-control form-control-sm" name="envelopeAmount" value="<?php echo $envelope !== null ? htmlspecialchars(number_format((float) $envelope, 2), ENT_QUOTES, 'UTF-8') : ''; ?>" placeholder="0.00" required>
                        </div>
                        <div class="col-12 mt-2">
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="fa-solid fa-check me-1"></i><?php echo $submitted === true ? 'Re-enter' : 'Submit'; ?> Counter <?php echo $slot; ?> totals
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ⚖️ Comparison -->
<?php if ($bothSubmitted === true): ?>
<div class="card mb-4">
    <div class="card-header"><strong>Comparison</strong></div>
    <div class="card-body">
        <div class="portal-data-list">
            <div class="portal-data-row portal-data-header d-none d-md-flex">
                <div class="col-md-3"></div>
                <div class="col-md-3 text-end">Counter 1</div>
                <div class="col-md-3 text-end">Counter 2</div>
                <div class="col-md-3 text-end">Difference</div>
            </div>
            <?php
            // 🏷️ Loop key is the column-name prefix (cashTotal1/2 etc.) —
            // $bucketLabels maps it to the display text separately, since
            // the display text is no longer just Title-case of the key
            // (e.g. 'cash' → "Loose cash").
            $bucketLabels = ['cash' => 'Loose cash', 'cheque' => 'Loose cheque', 'envelope' => 'Envelope total'];
            foreach (['cash' => $diffCash, 'cheque' => $diffCheque, 'envelope' => $diffEnvelope] as $key => $diffPence):
                $v1 = (float) $session[$key . 'Total1'];
                $v2 = (float) $session[$key . 'Total2'];
                $mismatch = $diffPence !== 0;
            ?>
                <div class="portal-data-row">
                    <div class="col-12 col-md-3 fw-semibold"><?php echo htmlspecialchars($bucketLabels[$key], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-4 col-md-3 text-md-end">&pound;<?php echo number_format($v1, 2); ?></div>
                    <div class="col-4 col-md-3 text-md-end">&pound;<?php echo number_format($v2, 2); ?></div>
                    <div class="col-4 col-md-3 text-md-end">
                        <span class="badge bg-<?php echo $mismatch === true ? 'danger' : 'success'; ?>">
                            <?php echo ($diffPence > 0 ? '+' : '') . number_format($diffPence / 100, 2); ?>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 🛠️ Admin resolve (discrepancy only) -->
<?php if ($session['status'] === 'discrepancy' && App::isAdmin() === true): ?>
<div class="card mb-4 border-warning">
    <div class="card-header bg-warning-subtle"><strong><i class="fa-solid fa-user-shield me-1"></i>Admin: Resolve Discrepancy</strong></div>
    <div class="card-body">
        <p class="small text-muted">Enter the agreed totals (e.g. from a recount, or matching the bank deposit slip). This overrides both counters' independent entries for the gift log.</p>
        <form method="post" action="/giving/count/save" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="resolve">
            <input type="hidden" name="countSessionID" value="<?php echo $countSessionId; ?>">
            <div class="col-md-2">
                <label class="form-label small">Agreed cash</label>
                <input type="text" class="form-control form-control-sm" name="cashAmount" placeholder="0.00" required>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Agreed cheque</label>
                <input type="text" class="form-control form-control-sm" name="chequeAmount" placeholder="0.00" required>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Agreed envelope</label>
                <input type="text" class="form-control form-control-sm" name="envelopeAmount" placeholder="0.00" required>
            </div>
            <div class="col-md-4">
                <label class="form-label small">Resolution note</label>
                <input type="text" class="form-control form-control-sm" name="notes" placeholder="e.g. Recounted with both present">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-warning btn-sm w-100"><i class="fa-solid fa-gavel me-1"></i>Resolve</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ✉️ Named envelopes -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Named Giving Envelopes</strong>
        <span class="small <?php echo $session['envelopeTotal'] !== null && $envelopesReconcile === false ? 'text-danger fw-semibold' : 'text-muted'; ?>">
            Logged: &pound;<?php echo number_format($envelopeSum, 2); ?>
            <?php if ($session['envelopeTotal'] !== null): ?>
                / Agreed envelope total: &pound;<?php echo number_format((float) $session['envelopeTotal'], 2); ?>
            <?php endif; ?>
        </span>
    </div>
    <div class="card-body">
        <?php if (count($envelopes) === 0): ?>
            <p class="text-muted small mb-3">No named envelopes logged yet. This is separate money from loose cash/cheque — any of the agreed envelope total not itemised here posts as one aggregate "loose envelope" entry on close, but for Gift Aid attribution, log named/numbered envelopes here.</p>
        <?php else: ?>
            <div class="portal-data-list mb-3">
                <div class="portal-data-row portal-data-header d-none d-md-flex">
                    <div class="col-md-5">Giver</div>
                    <div class="col-md-2">Method</div>
                    <div class="col-md-2 text-end">Amount</div>
                    <div class="col-md-3 text-end">Actions</div>
                </div>
                <?php foreach ($envelopes as $e):
                    $giverLabel = (string) ($e['giverFullName'] ?? '') !== ''
                        ? (string) $e['giverFullName']
                        : ((string) ($e['giverName'] ?? '') !== '' ? (string) $e['giverName'] : 'Unnamed');
                ?>
                    <div class="portal-data-row">
                        <div class="col-12 col-md-5"><?php echo htmlspecialchars($giverLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-12 col-md-2 text-muted small"><?php echo htmlspecialchars((string) $e['method'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="col-12 col-md-2 text-md-end">&pound;<?php echo number_format((float) $e['amount'], 2); ?></div>
                        <div class="col-12 col-md-3 text-md-end">
                            <?php if ($session['status'] !== 'closed'): ?>
                                <form method="post" action="/giving/count/save" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="envelope-delete">
                                    <input type="hidden" name="countSessionID" value="<?php echo $countSessionId; ?>">
                                    <input type="hidden" name="envelopeID" value="<?php echo (int) $e['envelopeID']; ?>">
                                    <button type="submit" class="btn btn-link btn-sm text-danger p-0" data-confirm="Remove this named envelope?">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($session['status'] !== 'closed'): ?>
            <form method="post" action="/giving/count/save" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="envelope-add">
                <input type="hidden" name="countSessionID" value="<?php echo $countSessionId; ?>">
                <div class="col-md-4">
                    <label class="form-label small">Giver (member)</label>
                    <select class="form-select form-select-sm" name="giverID">
                        <option value="0">— Not a member / use name below —</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?php echo (int) $u['userID']; ?>"><?php echo htmlspecialchars((string) $u['fullName'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Or free-text name</label>
                    <input type="text" class="form-control form-control-sm" name="giverName" maxlength="255" placeholder="Envelope #42">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Amount</label>
                    <input type="text" class="form-control form-control-sm" name="amount" placeholder="0.00" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Method</label>
                    <select class="form-select form-select-sm" name="method">
                        <option value="cash">Cash</option>
                        <option value="cheque">Cheque</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-outline-primary btn-sm w-100"><i class="fa-solid fa-plus"></i></button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- 🔒 Close -->
<?php if ($session['status'] !== 'closed'): ?>
<div class="card mb-4">
    <div class="card-header"><strong>Close Session</strong></div>
    <div class="card-body">
        <?php if ($canClose === false): ?>
            <ul class="small text-muted mb-3">
                <?php if ($session['status'] === 'discrepancy'): ?>
                    <li>Resolve the discrepancy before closing.</li>
                <?php elseif ($agreedSet === false): ?>
                    <li>Waiting on both counters' totals to agree (or an admin resolve).</li>
                <?php endif; ?>
                <?php if ($agreedSet === true && $envelopesReconcile === false): ?>
                    <li>Named envelopes (&pound;<?php echo number_format($envelopeSum, 2); ?>) exceed the agreed envelope total (&pound;<?php echo number_format((float) ($session['envelopeTotal'] ?? 0), 2); ?>) — adjust the named envelopes or the agreed envelope total above.</li>
                <?php endif; ?>
            </ul>
        <?php endif; ?>
        <form method="post" action="/giving/count/close">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="countSessionID" value="<?php echo $countSessionId; ?>">
            <button type="submit" class="btn btn-success" <?php echo $canClose === false ? 'disabled' : ''; ?>
                    data-confirm="Close this count session and write the gift log? This cannot be undone." data-confirm-destructive="true">
                <i class="fa-solid fa-lock me-1"></i>Close &amp; Write Gift Log
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
