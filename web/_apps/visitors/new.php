<?php
// Path: public_html/visitors/new.php
/**
 * Visitor Tracking — manual capture form (admin / coordinator).
 *
 * @package   Portal\Visitors
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/258
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$siteId = Site::id();

// 👥 Coordinator dropdown — filtered to holders of the configured
// visitors.coordinator_role (tblUserRoles/tblRoles join — same pattern as
// PrayerChain::eligiblePartners()). Was previously read then silently
// ignored, so every active user appeared regardless of role. An empty
// setting falls back to the old "all active users" behaviour.
$coordRole = (string) (App::settings()['visitors']['coordinator_role'] ?? 'visitor_coordinator');
$coords    = [];
if ($coordRole !== '') {
    $stmt = $db->prepare(
        // 🏷️ #516: tblRoles is tied to tblUserRoles (r.siteID = ur.siteID)
        // and tblUserSites (joined after) is tied back to both — a
        // coordinator role held in a DIFFERENT organisation must not
        // appear in this organisation's picker.
        'SELECT DISTINCT u.userID, u.fullName FROM tblUsers u '
        . 'INNER JOIN tblUserRoles ur ON ur.userID = u.userID '
        . 'INNER JOIN tblRoles r ON r.roleID = ur.roleID AND r.siteID = ur.siteID AND r.roleKey = ? '
        . 'INNER JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 AND us.siteID = ur.siteID '
        . 'WHERE u.isActive = 1 ORDER BY u.fullName'
    );
    if ($stmt !== false) {
        $stmt->bind_param('si', $coordRole, $siteId);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) {
            $coords[] = $r;
        }
        $stmt->close();
    }
} else {
    $stmt = $db->prepare(
        'SELECT u.userID, u.fullName FROM tblUsers u '
        . 'INNER JOIN tblUserSites us ON us.userID = u.userID AND us.siteID = ? AND us.isActive = 1 '
        . 'WHERE u.isActive = 1 ORDER BY u.fullName'
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($r = $rs->fetch_assoc()) {
            $coords[] = $r;
        }
        $stmt->close();
    }
}

$pageTitle   = 'Add visitor';
$pageSection = 'visitors';
$breadcrumbs = ['Dashboard' => '/', 'Visitors' => '/visitors', 'Add' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
?>

<h1 class="mb-3"><i class="fa-solid fa-user-plus me-2"></i>Add visitor</h1>

<div class="card">
    <div class="card-body">
        <form method="post" action="/visitors/save">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="row g-2">
                <div class="col-md-6"><label class="form-label">Full name</label><input type="text" name="fullName" class="form-control" required maxlength="255"></div>
                <div class="col-md-6"><label class="form-label">Email</label><input type="email" name="email" class="form-control" maxlength="255"></div>
                <div class="col-md-6"><label class="form-label">Phone</label><input type="tel" name="phone" class="form-control" maxlength="50"></div>
                <div class="col-md-6">
                    <label class="form-label">Source</label>
                    <select name="source" class="form-select">
                        <option value="in-person">In-person (Sabbath morning, etc.)</option>
                        <option value="referral">Referral</option>
                        <option value="website">Website</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Assign to (follow-up coordinator)</label>
                    <select name="assignedToID" class="form-select">
                        <option value="">— Unassigned —</option>
                        <?php foreach ($coords as $c): ?>
                            <option value="<?php echo (int) $c['userID']; ?>"><?php echo htmlspecialchars((string) $c['fullName'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Notes (markdown supported)</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Anything notable from the encounter…"></textarea>
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="btn btn-primary">Save visitor</button>
                <a href="/visitors" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
