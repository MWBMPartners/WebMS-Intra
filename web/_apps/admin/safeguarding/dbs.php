<?php
// Path: _apps/admin/safeguarding/dbs.php
/**
 * -----------------------------------------------------------------------------
 * Admin — DBS safeguarding tracking 🛡️ (#310)
 * -----------------------------------------------------------------------------
 * Lists every active user with their current DBS status:
 *   • Valid (with days-until-expiry pill)
 *   • Expiring soon (within safeguarding.dbs_renewal_warning_days)
 *   • Expired
 *   • Revoked
 *   • Missing
 * Admin can record a new DBS check (or supersede an old row) from a
 * compact inline form.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/310
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Settings;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) { http_response_code(403); exit('Forbidden'); }

Logger::activity('SafeguardingDbsList', 'Admin viewed DBS list');

$warningDays = (int) Settings::get('safeguarding.dbs_renewal_warning_days', '90');
$requireForCoords = (string) Settings::get('safeguarding.dbs_required_for_coordinators', '0');

// 📋 Per-user latest DBS status. LEFT JOIN ensures users with no DBS row
//     still appear (as Missing).
$users = [];
$sql = 'SELECT u.userID, u.fullName, u.email, '
     . '       d.dbsCheckID, d.dbsType, d.referenceNumber, d.issuedDate, d.expiresAt, d.status '
     . 'FROM tblUsers u '
     . 'LEFT JOIN ( '
     . '    SELECT d1.* FROM tblDbsChecks d1 '
     . '    INNER JOIN ( '
     . '        SELECT userID, MAX(dbsCheckID) AS latest FROM tblDbsChecks GROUP BY userID '
     . '    ) d2 ON d1.dbsCheckID = d2.latest '
     . ') d ON d.userID = u.userID '
     . 'WHERE u.isActive = 1 '
     . 'ORDER BY u.fullName ASC';
$result = $mysqli->query($sql);
while ($r = $result->fetch_assoc()) { $users[] = $r; }

$pageTitle = 'DBS Safeguarding';
$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
$today = strtotime(date('Y-m-d'));
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container py-3">
    <h1 class="h4 mb-2"><i class="fa-solid fa-shield-halved me-2 text-primary"></i>DBS Safeguarding</h1>
    <p class="text-muted small">Track Disclosure and Barring Service checks for users working with children or vulnerable adults.</p>

    <div class="card mb-3">
        <div class="card-body small">
            <strong>Settings</strong> — gate coordinator role on valid DBS:
            <span class="badge <?php echo $requireForCoords === '1' ? 'bg-success' : 'bg-secondary'; ?>"><?php echo $requireForCoords === '1' ? 'ENFORCED' : 'Not enforced'; ?></span>
            <span class="text-muted">(toggle <code>safeguarding.dbs_required_for_coordinators</code> in <a href="/admin/settings">Settings</a>)</span><br>
            Renewal warning: <strong><?php echo $warningDays; ?> days</strong>
        </div>
    </div>

    <div class="portal-data-list">
    <?php foreach ($users as $u):
        $missing = ($u['dbsCheckID'] === null);
        $expiresTs = $missing ? null : strtotime((string) $u['expiresAt']);
        $daysLeft  = $missing ? null : (int) (($expiresTs - $today) / 86400);
        $status = 'missing';
        if ($missing === false) {
            if ((string) $u['status'] === 'revoked') { $status = 'revoked'; }
            elseif ($daysLeft < 0)                    { $status = 'expired'; }
            elseif ($daysLeft <= $warningDays)        { $status = 'warning'; }
            else                                       { $status = 'valid'; }
        }
        $badgeClass = ['valid' => 'bg-success', 'warning' => 'bg-warning text-dark', 'expired' => 'bg-danger', 'revoked' => 'bg-dark', 'missing' => 'bg-secondary'][$status];
        $badgeText  = ['valid' => 'Valid · ' . $daysLeft . 'd left', 'warning' => 'Expires in ' . $daysLeft . 'd', 'expired' => 'Expired', 'revoked' => 'Revoked', 'missing' => 'No DBS on record'][$status];
    ?>
        <div class="portal-data-row">
            <div class="portal-data-row-main">
                <strong><?php echo htmlspecialchars((string) $u['fullName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                <span class="badge <?php echo $badgeClass; ?> ms-1"><?php echo htmlspecialchars($badgeText, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php if ($missing === false): ?>
                    <div class="small text-muted">
                        <?php echo htmlspecialchars(ucfirst(str_replace('-', ' ', (string) $u['dbsType'])), ENT_QUOTES, 'UTF-8'); ?>
                        &middot; Ref: <code><?php echo htmlspecialchars((string) ($u['referenceNumber'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></code>
                        &middot; Issued: <?php echo htmlspecialchars(date('j M Y', strtotime((string) $u['issuedDate'])), ENT_QUOTES, 'UTF-8'); ?>
                        &middot; Expires: <?php echo htmlspecialchars(date('j M Y', $expiresTs), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php else: ?>
                    <div class="small text-muted"><?php echo htmlspecialchars((string) $u['email'], ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
            </div>
            <div class="portal-data-row-aside">
                <details>
                    <summary class="btn btn-sm btn-outline-primary">Record DBS</summary>
                    <form method="post" action="/admin/safeguarding/dbs/save" class="bg-light p-2 rounded mt-2" style="min-width:380px;">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="userID" value="<?php echo (int) $u['userID']; ?>">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label small">Type</label>
                                <select name="dbsType" class="form-select form-select-sm" required>
                                    <option value="basic">Basic</option>
                                    <option value="standard">Standard</option>
                                    <option value="enhanced" selected>Enhanced</option>
                                    <option value="enhanced-barred">Enhanced + barred lists</option>
                                </select>
                            </div>
                            <div class="col-md-4"><label class="form-label small">Reference</label><input type="text" name="referenceNumber" maxlength="60" class="form-control form-control-sm"></div>
                            <div class="col-md-4"><label class="form-label small">Issued</label><input type="date" name="issuedDate" required class="form-control form-control-sm"></div>
                            <div class="col-md-4"><label class="form-label small">Expires</label><input type="date" name="expiresAt" required class="form-control form-control-sm"></div>
                            <div class="col-md-4">
                                <label class="form-label small">Status</label>
                                <select name="status" class="form-select form-select-sm">
                                    <option value="valid" selected>Valid</option>
                                    <option value="expired">Expired</option>
                                    <option value="revoked">Revoked</option>
                                </select>
                            </div>
                            <div class="col-md-4 d-flex align-items-end"><button class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-save"></i> Save</button></div>
                        </div>
                    </form>
                </details>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
