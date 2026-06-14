<?php
// Path: public_html/prayer-requests/index.php
/**
 * -----------------------------------------------------------------------------
 * Prayer Requests — Landing & Congregation Feed 🙏
 * -----------------------------------------------------------------------------
 * Logged-in landing page for the Prayer Requests app. Shows:
 *   • The current user's own submitted requests (any status, any visibility)
 *   • Congregation-visible active requests (if that setting is enabled)
 * Leaders / admins see additional shortcuts to the moderation queue.
 *
 * @package   Portal\PrayerRequests
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.10.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

// 📌 Page metadata
$pageTitle   = 'Prayer Requests';
$pageSection = 'prayer-requests';
$breadcrumbs = ['Dashboard' => '/', 'Prayer Requests' => ''];

// 🛡️ Require an authenticated session
Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$user   = App::user();
$userId = (int) ($user['userID'] ?? 0);
$isMod  = App::isAdmin();

// 🔎 Feature flags
$featureEnabled       = (App::settings('prayer-requests.enabled') ?? 'true') === 'true';
$congregationEnabled  = (App::settings('prayer-requests.allowCongregationFeed') ?? 'true') === 'true';
$anonymousEnabled     = (App::settings('prayer-requests.allowAnonymous') ?? 'true') === 'true';

// -----------------------------------------------------------------------------
// 📋 Fetch the current user's own requests (newest first, limit 20)
// -----------------------------------------------------------------------------
$myRequests = [];
$stmt = $mysqli->prepare(
    'SELECT requestID, subject, visibility, status, isAnonymous, '
    . 'answeredAt, createdAt '
    . 'FROM tblPrayerRequests '
    . 'WHERE siteID = ? AND submitterID = ? '
    . 'ORDER BY createdAt DESC LIMIT 20'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $siteId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $myRequests[] = $row;
    }
    $stmt->close();
}

// -----------------------------------------------------------------------------
// 🌿 Fetch active congregation-visible requests (if feature enabled)
// -----------------------------------------------------------------------------
$congFeed = [];
if ($congregationEnabled === true && $featureEnabled === true) {
    $stmt = $mysqli->prepare(
        'SELECT pr.requestID, pr.subject, pr.body, pr.isAnonymous, '
        . 'pr.status, pr.answeredAt, pr.testimony, pr.createdAt, '
        . 'u.fullName AS submitterFullName '
        . 'FROM tblPrayerRequests pr '
        . 'LEFT JOIN tblUsers u ON u.userID = pr.submitterID '
        . 'WHERE pr.siteID = ? AND pr.visibility = \'congregation\' '
        . 'AND pr.status IN (\'active\',\'answered\') '
        . 'ORDER BY pr.createdAt DESC LIMIT 25'
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $congFeed[] = $row;
        }
        $stmt->close();
    }
}

// -----------------------------------------------------------------------------
// 🧮 Moderator queue counts (only if user is admin)
// -----------------------------------------------------------------------------
$pendingCount = 0;
if ($isMod === true) {
    $stmt = $mysqli->prepare(
        'SELECT COUNT(*) AS cnt FROM tblPrayerRequests '
        . 'WHERE siteID = ? AND status = \'pending\''
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $pendingCount = (int) ($row['cnt'] ?? 0);
        $stmt->close();
    }
}

// 📄 Render shared header
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

/**
 * 🎨 Helper — render a Bootstrap-coloured status badge for a request row
 */
$renderStatusBadge = static function (string $status): string {
    $map = [
        'pending'  => ['warning', 'fa-hourglass-half', 'Pending'],
        'active'   => ['success', 'fa-hands-praying',  'Active'],
        'answered' => ['info',    'fa-check-double',   'Answered'],
        'archived' => ['secondary','fa-box-archive',   'Archived'],
    ];
    [$colour, $icon, $label] = $map[$status] ?? ['secondary', 'fa-circle', ucfirst($status)];
    return '<span class="badge bg-' . $colour . '"><i class="fa-solid ' . $icon . ' me-1"></i>'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
};
?>

<!-- 🙏 Page header -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="mb-0"><i class="fa-solid fa-hands-praying me-2"></i>Prayer Requests</h1>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($featureEnabled === true): ?>
            <a href="/prayer-requests/submit" class="btn btn-success">
                <i class="fa-solid fa-plus me-1"></i> Submit a Request
            </a>
        <?php endif; ?>
        <?php if ($isMod === true): ?>
            <a href="/prayer-requests/manage" class="btn btn-outline-primary">
                <i class="fa-solid fa-gauge-high me-1"></i> Moderate
                <?php if ($pendingCount > 0): ?>
                    <span class="badge bg-warning text-dark ms-1"><?php echo $pendingCount; ?></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($featureEnabled === false): ?>
    <div class="alert alert-warning">
        <i class="fa-solid fa-triangle-exclamation me-1"></i>
        Prayer Requests are disabled for this site.
        <?php if ($isMod === true): ?>
            Visit <a href="/settings" class="alert-link">Site Settings</a> to re-enable.
        <?php endif; ?>
    </div>
<?php else: ?>

    <?php if ($anonymousEnabled === true): ?>
        <div class="alert alert-info small">
            <i class="fa-solid fa-circle-info me-1"></i>
            Visitors without an account can submit anonymously at
            <a href="/prayer-requests/anonymous" class="alert-link">/prayer-requests/anonymous</a>.
        </div>
    <?php endif; ?>

    <!-- 📋 My Requests -->
    <section class="mb-5">
        <h2 class="h5 mb-3"><i class="fa-solid fa-user me-2"></i>My Requests</h2>

        <?php if (count($myRequests) === 0): ?>
            <div class="alert alert-light border">
                You haven't submitted any prayer requests yet.
                <a href="/prayer-requests/submit" class="alert-link">Submit your first request</a>.
            </div>
        <?php else: ?>
            <div class="portal-data-list">
                <div class="portal-data-row portal-data-header d-none d-md-flex">
                    <div class="col-md-5">Subject</div>
                    <div class="col-md-2">Visibility</div>
                    <div class="col-md-2">Status</div>
                    <div class="col-md-2">Submitted</div>
                    <div class="col-md-1 text-end">View</div>
                </div>
                <?php foreach ($myRequests as $req): ?>
                    <div class="portal-data-row">
                        <div class="col-12 col-md-5">
                            <strong><?php echo htmlspecialchars($req['subject'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            <?php if ((int) $req['isAnonymous'] === 1): ?>
                                <span class="badge bg-secondary ms-1">Anon</span>
                            <?php endif; ?>
                        </div>
                        <div class="col-6 col-md-2">
                            <?php if ($req['visibility'] === 'congregation'): ?>
                                <span class="badge bg-info-subtle text-info-emphasis">
                                    <i class="fa-solid fa-people-group me-1"></i>Congregation
                                </span>
                            <?php else: ?>
                                <span class="badge bg-secondary-subtle text-secondary-emphasis">
                                    <i class="fa-solid fa-user-shield me-1"></i>Leadership
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="col-6 col-md-2">
                            <?php echo $renderStatusBadge((string) $req['status']); ?>
                        </div>
                        <div class="col-6 col-md-2 small text-muted">
                            <?php echo htmlspecialchars(date('Y-m-d', strtotime((string) $req['createdAt'])), ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <div class="col-6 col-md-1 text-end">
                            <a href="/prayer-requests/view?id=<?php echo (int) $req['requestID']; ?>"
                               class="btn btn-sm btn-outline-secondary">
                                <i class="fa-solid fa-eye"></i>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- 🌿 Congregation feed -->
    <?php if ($congregationEnabled === true): ?>
        <section class="mb-5">
            <h2 class="h5 mb-3">
                <i class="fa-solid fa-people-group me-2"></i>Congregation Prayer Feed
            </h2>

            <?php if (count($congFeed) === 0): ?>
                <div class="alert alert-light border">
                    No congregation-visible prayer requests yet.
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($congFeed as $req): ?>
                        <?php
                        $name = (int) $req['isAnonymous'] === 1
                            ? 'Anonymous'
                            : ($req['submitterFullName'] ?? 'Anonymous');
                        $isAnswered = $req['status'] === 'answered';
                        ?>
                        <div class="col-12 col-md-6 col-lg-4">
                            <div class="card h-100 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h3 class="h6 mb-0">
                                            <?php echo htmlspecialchars($req['subject'], ENT_QUOTES, 'UTF-8'); ?>
                                        </h3>
                                        <?php echo $renderStatusBadge((string) $req['status']); ?>
                                    </div>
                                    <p class="text-muted small mb-2">
                                        <i class="fa-solid fa-user me-1"></i>
                                        <?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>
                                        &middot;
                                        <?php echo htmlspecialchars(date('Y-m-d', strtotime((string) $req['createdAt'])), ENT_QUOTES, 'UTF-8'); ?>
                                    </p>
                                    <p class="card-text small">
                                        <?php
                                        $body = (string) $req['body'];
                                        $body = strlen($body) > 220 ? substr($body, 0, 217) . '…' : $body;
                                        echo nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
                                        ?>
                                    </p>
                                    <?php if ($isAnswered === true && (string) ($req['testimony'] ?? '') !== ''): ?>
                                        <div class="alert alert-success small mb-0">
                                            <i class="fa-solid fa-seedling me-1"></i>
                                            <strong>Testimony:</strong>
                                            <?php
                                            $t = (string) $req['testimony'];
                                            $t = strlen($t) > 180 ? substr($t, 0, 177) . '…' : $t;
                                            echo nl2br(htmlspecialchars($t, ENT_QUOTES, 'UTF-8'));
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="card-footer bg-transparent border-0 pt-0">
                                    <a href="/prayer-requests/view?id=<?php echo (int) $req['requestID']; ?>"
                                       class="btn btn-sm btn-outline-secondary w-100">
                                        <i class="fa-solid fa-eye me-1"></i> View
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
