<?php
// Path: public_html/admin/erasure/report.php
/**
 * Admin — Per-request compliance report. Shows the audit chain + verify
 * result. Exportable as JSON for the data subject.
 *
 * @package   Portal\Admin
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/235
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\GdprEraser;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$db        = App::db();
$siteId    = Site::id();
$requestId = (int) ($_GET['id'] ?? 0);

$req = null;
$stmt = $db->prepare('SELECT * FROM tblErasureRequest WHERE requestID = ? AND siteID = ? LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('ii', $requestId, $siteId);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($req === null) {
    http_response_code(404);
    exit('Erasure request not found');
}

$audit = [];
$stmt = $db->prepare('SELECT auditID, action, tableName, recordKey, details, chainHash, loggedAt FROM tblErasureAudit WHERE requestID = ? ORDER BY auditID');
if ($stmt !== false) {
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $audit[] = $r;
    }
    $stmt->close();
}
$chainValid = GdprEraser::verifyAuditChain($requestId);

if (isset($_GET['download']) === true) {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="erasure-report-' . $requestId . '.json"');
    echo json_encode([
        'request'    => $req,
        'audit'      => $audit,
        'chainValid' => $chainValid,
        'generated'  => date('c'),
    ], JSON_PRETTY_PRINT);
    exit();
}

$pageTitle   = 'Erasure report #' . $requestId;
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Erasure requests' => '/admin/erasure-requests', 'Report' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-3"><i class="fa-solid fa-clipboard-list me-2"></i>Erasure report #<?php echo (int) $req['requestID']; ?></h1>

<div class="card mb-3"><div class="card-body">
    <p><strong>Subject:</strong> <?php echo htmlspecialchars((string) ($req['subjectName'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
        &lt;<?php echo htmlspecialchars((string) $req['subjectEmail'], ENT_QUOTES, 'UTF-8'); ?>&gt;</p>
    <p><strong>Status:</strong> <?php echo htmlspecialchars(str_replace('_', ' ', (string) $req['status']), ENT_QUOTES, 'UTF-8'); ?></p>
    <p><strong>Requested:</strong> <?php echo htmlspecialchars((string) $req['requestedAt'], ENT_QUOTES, 'UTF-8'); ?>
        &middot; <strong>Due by:</strong> <?php echo htmlspecialchars((string) $req['dueBy'], ENT_QUOTES, 'UTF-8'); ?>
        <?php if (($req['processedAt'] ?? '') !== ''): ?>
            &middot; <strong>Processed:</strong> <?php echo htmlspecialchars((string) $req['processedAt'], ENT_QUOTES, 'UTF-8'); ?>
        <?php endif; ?>
    </p>
    <p>
        <strong>Audit chain integrity:</strong>
        <?php if ($chainValid === true): ?>
            <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i>verified</span>
        <?php else: ?>
            <span class="badge bg-danger">tampered or empty</span>
        <?php endif; ?>
    </p>
</div></div>

<div class="card"><div class="card-body">
    <h5>Audit log (sealed)</h5>
    <?php if (count($audit) === 0): ?>
        <p class="text-muted mb-0">No audit rows yet. Run the erasure to populate.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm small font-monospace">
                <thead><tr>
                    <th>Logged</th><th>Action</th><th>Table</th><th>Key</th><th>Details</th><th>Hash</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($audit as $a): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string) $a['loggedAt'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) $a['action'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) $a['tableName'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($a['recordKey'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($a['details'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td title="<?php echo htmlspecialchars((string) $a['chainHash'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(substr((string) $a['chainHash'], 0, 12), ENT_QUOTES, 'UTF-8'); ?>…</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <a class="btn btn-outline-primary btn-sm mt-2" href="/admin/erasure-requests/report?id=<?php echo (int) $requestId; ?>&download=1">
        <i class="fa-solid fa-download me-1"></i>Download JSON report
    </a>
</div></div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
