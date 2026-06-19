<?php
// Path: _apps/account/safeguarding.php
/**
 * -----------------------------------------------------------------------------
 * Account — My safeguarding status 🛡️ (#310)
 * -----------------------------------------------------------------------------
 * Read-only view for the logged-in user of their own DBS check history.
 * Counterpart to /admin/safeguarding/dbs.
 *
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/310
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Settings;

Auth::ensureSession();
Auth::requireLogin();

$userId = (int) ($_SESSION['user_id'] ?? 0);
$warningDays = (int) Settings::get('safeguarding.dbs_renewal_warning_days', '90');

$rows = [];
$stmt = $mysqli->prepare(
    'SELECT dbsType, referenceNumber, issuedDate, expiresAt, status, notes '
    . 'FROM tblDbsChecks WHERE userID = ? ORDER BY dbsCheckID DESC'
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) { $rows[] = $r; }
$stmt->close();

$pageTitle = 'My Safeguarding';
$today = strtotime(date('Y-m-d'));
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:720px;">
    <h1 class="h4 mb-2"><i class="fa-solid fa-shield-halved me-2 text-primary"></i>My Safeguarding</h1>
    <p class="text-muted small">Your DBS check history.</p>

    <?php if (count($rows) === 0): ?>
        <div class="alert alert-info">No DBS checks on record. Speak to an admin to record one.</div>
    <?php else:
        $latest = $rows[0];
        $exp = strtotime((string) $latest['expiresAt']);
        $daysLeft = (int) (($exp - $today) / 86400);
        $isValid = ((string) $latest['status'] === 'valid') && ($daysLeft >= 0);
    ?>
        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h6">Current status</h2>
                <?php if ($isValid && $daysLeft > $warningDays): ?>
                    <p class="mb-0"><span class="badge bg-success">Valid</span> Expires in <strong><?php echo $daysLeft; ?> days</strong> (<?php echo htmlspecialchars(date('j M Y', $exp), ENT_QUOTES, 'UTF-8'); ?>)</p>
                <?php elseif ($isValid && $daysLeft <= $warningDays): ?>
                    <p class="mb-0"><span class="badge bg-warning text-dark">Renewal due soon</span> Expires in <strong><?php echo $daysLeft; ?> days</strong> &mdash; speak to an admin.</p>
                <?php elseif ((string) $latest['status'] === 'revoked'): ?>
                    <p class="mb-0"><span class="badge bg-dark">Revoked</span></p>
                <?php else: ?>
                    <p class="mb-0"><span class="badge bg-danger">Expired</span> on <?php echo htmlspecialchars(date('j M Y', $exp), ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <h2 class="h6">History</h2>
        <div class="portal-data-list">
        <?php foreach ($rows as $r): ?>
            <div class="portal-data-row">
                <div class="portal-data-row-main small">
                    <strong><?php echo htmlspecialchars(ucfirst(str_replace('-', ' ', (string) $r['dbsType'])), ENT_QUOTES, 'UTF-8'); ?></strong>
                    &middot; Ref: <code><?php echo htmlspecialchars((string) ($r['referenceNumber'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></code>
                    <br>Issued <?php echo htmlspecialchars(date('j M Y', strtotime((string) $r['issuedDate'])), ENT_QUOTES, 'UTF-8'); ?>
                    &middot; Expires <?php echo htmlspecialchars(date('j M Y', strtotime((string) $r['expiresAt'])), ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="portal-data-row-aside">
                    <span class="badge bg-secondary"><?php echo htmlspecialchars((string) $r['status'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
