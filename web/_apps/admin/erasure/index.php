<?php
// Path: public_html/admin/erasure/index.php
/**
 * Admin — GDPR erasure-request queue with SLA monitoring.
 *
 * @package   Portal\Admin
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/235
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$db     = App::db();
$siteId = Site::id();

$rows = [];
$stmt = $db->prepare(
    'SELECT requestID, userID, subjectEmail, subjectName, status, requestedAt, dueBy, processedAt, '
    . '       TIMESTAMPDIFF(DAY, NOW(), dueBy) AS daysLeft '
    . 'FROM tblErasureRequest WHERE siteID = ? ORDER BY '
    . '       (status IN ("completed","cancelled")) ASC, dueBy ASC LIMIT 200'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'Erasure requests';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Erasure requests' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-shield-halved me-2"></i>Erasure requests</h1>
<p class="text-secondary">UK GDPR Article 17 requests. Process within one month of confirmation.</p>

<?php if (count($rows) === 0): ?>
    <div class="alert alert-info">No erasure requests on file.</div>
<?php else: ?>
    <div class="card"><div class="card-body">
        <div class="portal-data-list">
            <?php foreach ($rows as $r):
                $cls = match ((string) $r['status']) {
                    'completed'           => 'success',
                    'pending_confirmation'=> 'secondary',
                    'pending_review'      => 'warning',
                    'processing'          => 'info',
                    'failed'              => 'danger',
                    default               => 'secondary',
                };
                $days = (int) ($r['daysLeft'] ?? 0);
                $slaCls = $days < 0 ? 'text-danger' : ($days < 7 ? 'text-warning' : 'text-muted');
            ?>
                <div class="row py-2 border-bottom align-items-center small">
                    <div class="col-md-4">
                        <strong><?php echo htmlspecialchars((string) ($r['subjectName'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                        <div class="text-muted"><?php echo htmlspecialchars((string) $r['subjectEmail'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div class="col-md-2"><span class="badge bg-<?php echo $cls; ?>"><?php echo htmlspecialchars(str_replace('_', ' ', (string) $r['status']), ENT_QUOTES, 'UTF-8'); ?></span></div>
                    <div class="col-md-3 <?php echo $slaCls; ?>">
                        <?php if ((string) $r['status'] === 'completed'): ?>
                            done <?php echo htmlspecialchars((string) ($r['processedAt'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        <?php else: ?>
                            due <?php echo htmlspecialchars((string) $r['dueBy'], ENT_QUOTES, 'UTF-8'); ?>
                            <?php if ($days < 0): ?>(<?php echo abs($days); ?>d OVERDUE)<?php else: ?>(<?php echo $days; ?>d left)<?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-3 text-end">
                        <?php if ((string) $r['status'] === 'pending_review'): ?>
                            <form method="post" action="/admin/erasure-requests/process" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="requestID" value="<?php echo (int) $r['requestID']; ?>">
                                <input type="hidden" name="action" value="execute">
                                <button class="btn btn-danger btn-sm" type="submit" data-confirm="Run erasure now? This deletes/anonymises user data per the policy.">
                                    <i class="fa-solid fa-play me-1"></i>Execute
                                </button>
                            </form>
                            <form method="post" action="/admin/erasure-requests/process" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="requestID" value="<?php echo (int) $r['requestID']; ?>">
                                <input type="hidden" name="action" value="cancel">
                                <button class="btn btn-outline-secondary btn-sm" type="submit" data-confirm="Cancel this request?">Cancel</button>
                            </form>
                        <?php endif; ?>
                        <a class="btn btn-outline-primary btn-sm" href="/admin/erasure-requests/report?id=<?php echo (int) $r['requestID']; ?>">Report</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div></div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
