<?php
// Path: public_html/prayer-requests/manage.php
/**
 * -----------------------------------------------------------------------------
 * Prayer Requests — Moderation Queue 🛡️
 * -----------------------------------------------------------------------------
 * Admin-only moderation view. Lists pending, active, answered, and archived
 * requests with quick-action buttons that POST to moderate.php.
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
$pageTitle   = 'Moderate Prayer Requests';
$pageSection = 'prayer-requests';
$breadcrumbs = [
    'Dashboard'        => '/',
    'Prayer Requests'  => '/prayer-requests',
    'Moderate'         => '',
];

// 🛡️ Admin only
Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    $pageTitle = 'Forbidden';
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
    echo '<div class="alert alert-danger"><i class="fa-solid fa-lock me-1"></i>'
        . htmlspecialchars(t('error.moderator_only'), ENT_QUOTES, 'UTF-8')
        . '</div>';
    echo '<a href="/prayer-requests" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i> Back</a>';
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
    exit();
}

$siteId = Site::id();

// 🔍 Filter parameters
$filterStatus = (string) ($_GET['status'] ?? 'pending');
$validStatuses = ['pending', 'active', 'answered', 'archived'];
if (in_array($filterStatus, $validStatuses, true) === false) {
    $filterStatus = 'pending';
}

// 📋 Fetch rows (siteID-scoped + status-filtered)
$rows = [];
$stmt = $mysqli->prepare(
    'SELECT pr.*, u.fullName AS submitterFullName, m.fullName AS moderatorFullName '
    . 'FROM tblPrayerRequests pr '
    . 'LEFT JOIN tblUsers u ON u.userID = pr.submitterID '
    . 'LEFT JOIN tblUsers m ON m.userID = pr.moderatorID '
    . 'WHERE pr.siteID = ? AND pr.status = ? '
    . 'ORDER BY pr.createdAt DESC LIMIT 100'
);
if ($stmt !== false) {
    $stmt->bind_param('is', $siteId, $filterStatus);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
}

// 📊 Tab counters
$counts = ['pending' => 0, 'active' => 0, 'answered' => 0, 'archived' => 0];
$stmt = $mysqli->prepare(
    'SELECT status, COUNT(*) AS cnt FROM tblPrayerRequests '
    . 'WHERE siteID = ? GROUP BY status'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $counts[(string) $r['status']] = (int) $r['cnt'];
    }
    $stmt->close();
}

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

$csrf = Auth::csrfToken();
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="mb-0"><i class="fa-solid fa-gauge-high me-2"></i>Moderate Prayer Requests</h1>
    <a href="/prayer-requests" class="btn btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i> Back
    </a>
</div>

<!-- 🗂️ Status tabs -->
<ul class="nav nav-tabs mb-3">
    <?php
    $tabs = [
        'pending'  => ['label' => 'Pending',  'icon' => 'fa-hourglass-half'],
        'active'   => ['label' => 'Active',   'icon' => 'fa-hands-praying'],
        'answered' => ['label' => 'Answered', 'icon' => 'fa-check-double'],
        'archived' => ['label' => 'Archived', 'icon' => 'fa-box-archive'],
    ];
    foreach ($tabs as $key => $info):
        $active = $filterStatus === $key ? ' active' : '';
    ?>
        <li class="nav-item">
            <a class="nav-link<?php echo $active; ?>"
               href="/prayer-requests/manage?status=<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>">
                <i class="fa-solid <?php echo $info['icon']; ?> me-1"></i>
                <?php echo htmlspecialchars($info['label'], ENT_QUOTES, 'UTF-8'); ?>
                <span class="badge bg-secondary ms-1"><?php echo (int) $counts[$key]; ?></span>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<?php if (count($rows) === 0): ?>
    <div class="alert alert-light border">
        No requests in this status.
    </div>
<?php else: ?>
    <div class="portal-data-list">
        <div class="portal-data-row portal-data-header d-none d-md-flex">
            <div class="col-md-4">Subject</div>
            <div class="col-md-2">Submitter</div>
            <div class="col-md-2">Visibility</div>
            <div class="col-md-2">Submitted</div>
            <div class="col-md-2 text-end">Actions</div>
        </div>

        <?php foreach ($rows as $req): ?>
            <?php
            $submitterDisplay = (int) $req['isAnonymous'] === 1
                ? 'Anonymous'
                : (string) ($req['submitterFullName'] ?? '(unknown)');
            $modSeesRealName = (int) $req['isAnonymous'] === 1 && $req['submitterFullName'] !== null;
            $anonContactName  = (string) ($req['submitterName']  ?? '');
            $anonContactEmail = (string) ($req['submitterEmail'] ?? '');
            ?>
            <div class="portal-data-row">
                <div class="col-12 col-md-4">
                    <strong>
                        <a href="/prayer-requests/view?id=<?php echo (int) $req['requestID']; ?>">
                            <?php echo htmlspecialchars((string) $req['subject'], ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    </strong>
                    <p class="small text-muted mb-0">
                        <?php
                        $excerpt = (string) $req['body'];
                        $excerpt = strlen($excerpt) > 120 ? substr($excerpt, 0, 117) . '…' : $excerpt;
                        echo htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8');
                        ?>
                    </p>
                </div>
                <div class="col-6 col-md-2 small">
                    <?php echo htmlspecialchars($submitterDisplay, ENT_QUOTES, 'UTF-8'); ?>
                    <?php if ($modSeesRealName === true): ?>
                        <div class="text-warning-emphasis small">
                            <i class="fa-solid fa-eye me-1"></i>
                            <?php echo htmlspecialchars((string) $req['submitterFullName'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($anonContactName !== '' || $anonContactEmail !== ''): ?>
                        <div class="text-muted small">
                            <?php if ($anonContactName !== ''): ?>
                                <i class="fa-solid fa-user me-1"></i>
                                <?php echo htmlspecialchars($anonContactName, ENT_QUOTES, 'UTF-8'); ?><br>
                            <?php endif; ?>
                            <?php if ($anonContactEmail !== ''): ?>
                                <i class="fa-solid fa-envelope me-1"></i>
                                <a href="mailto:<?php echo htmlspecialchars($anonContactEmail, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($anonContactEmail, ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="col-6 col-md-2 small">
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
                <div class="col-6 col-md-2 small text-muted">
                    <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime((string) $req['createdAt'])), ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="col-6 col-md-2 text-end">
                    <div class="btn-group btn-group-sm" role="group">
                        <a href="/prayer-requests/view?id=<?php echo (int) $req['requestID']; ?>"
                           class="btn btn-outline-secondary" title="View">
                            <i class="fa-solid fa-eye"></i>
                        </a>

                        <?php if ($req['status'] === 'pending'): ?>
                            <form method="post" action="/prayer-requests/moderate" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="requestID" value="<?php echo (int) $req['requestID']; ?>">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="redirect" value="manage">
                                <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($filterStatus, ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" class="btn btn-outline-success" title="Approve">
                                    <i class="fa-solid fa-check"></i>
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($req['status'] === 'active'): ?>
                            <form method="post" action="/prayer-requests/moderate" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="requestID" value="<?php echo (int) $req['requestID']; ?>">
                                <input type="hidden" name="action" value="answer">
                                <input type="hidden" name="redirect" value="manage">
                                <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($filterStatus, ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" class="btn btn-outline-info" title="Mark answered">
                                    <i class="fa-solid fa-check-double"></i>
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($req['status'] !== 'archived'): ?>
                            <form method="post" action="/prayer-requests/moderate" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="requestID" value="<?php echo (int) $req['requestID']; ?>">
                                <input type="hidden" name="action" value="archive">
                                <input type="hidden" name="redirect" value="manage">
                                <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($filterStatus, ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" class="btn btn-outline-warning" title="Archive">
                                    <i class="fa-solid fa-box-archive"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
