<?php
// Path: public_html/prayer-requests/view.php
/**
 * -----------------------------------------------------------------------------
 * Prayer Requests — View Single Request 🙏
 * -----------------------------------------------------------------------------
 * Displays a single prayer request. Access rules:
 *   • Submitter can always view their own request (any status)
 *   • Site admins / leaders can view any request
 *   • Other logged-in members can view if status IN (active|answered)
 *     AND visibility = 'congregation'
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

// 📌 Page metadata (subject filled in once row is loaded)
$pageTitle   = 'Prayer Request';
$pageSection = 'prayer-requests';
$breadcrumbs = [
    'Dashboard'        => '/',
    'Prayer Requests'  => '/prayer-requests',
    'View'             => '',
];

// 🛡️ Require login
Auth::ensureSession();
Auth::requireLogin();

$siteId    = Site::id();
$user      = App::user();
$userId    = (int) ($user['userID'] ?? 0);
$isMod     = App::isAdmin();
$requestId = (int) ($_GET['id'] ?? 0);

if ($requestId <= 0) {
    header('Location: /prayer-requests', true, 302);
    exit();
}

// 🔍 Load the request (siteID-scoped)
$stmt = $mysqli->prepare(
    'SELECT pr.*, '
    . 'u.fullName AS submitterFullName, '
    . 'm.fullName AS moderatorFullName '
    . 'FROM tblPrayerRequests pr '
    . 'LEFT JOIN tblUsers u ON u.userID = pr.submitterID '
    . 'LEFT JOIN tblUsers m ON m.userID = pr.moderatorID '
    . 'WHERE pr.siteID = ? AND pr.requestID = ? LIMIT 1'
);
$req = null;
if ($stmt !== false) {
    $stmt->bind_param('ii', $siteId, $requestId);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($req === null) {
    http_response_code(404);
    $pageTitle = 'Not Found';
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
    echo '<div class="alert alert-warning"><i class="fa-solid fa-circle-exclamation me-1"></i>'
        . htmlspecialchars(t('prayer_requests.error.not_found'), ENT_QUOTES, 'UTF-8')
        . '</div>';
    echo '<a href="/prayer-requests" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i> Back</a>';
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
    exit();
}

// 🛡️ Access control
$isOwner = ($userId > 0 && (int) ($req['submitterID'] ?? 0) === $userId);
$isCongregationVisible = ($req['visibility'] === 'congregation'
    && in_array($req['status'], ['active', 'answered'], true));

if ($isOwner === false && $isMod === false && $isCongregationVisible === false) {
    http_response_code(403);
    $pageTitle = 'Forbidden';
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
    echo '<div class="alert alert-danger"><i class="fa-solid fa-lock me-1"></i>'
        . htmlspecialchars(t('prayer_requests.error.no_access'), ENT_QUOTES, 'UTF-8')
        . '</div>';
    echo '<a href="/prayer-requests" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i> Back</a>';
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
    exit();
}

// 🏷️ Now that we have the row, refine the page title
$pageTitle = (string) $req['subject'];

// 🎨 Display helpers
$submitterDisplay = (int) $req['isAnonymous'] === 1
    ? 'Anonymous'
    : (string) ($req['submitterFullName'] ?? 'Unknown');

// 🛡️ Mods always see real submitter name even for anonymous posts (for follow-up)
$modSubmitterDisplay = $isMod === true && (int) $req['isAnonymous'] === 1
    ? (string) ($req['submitterFullName'] ?? '(unknown)')
    : '';

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

$statusBadges = [
    'pending'  => ['warning',   'fa-hourglass-half', 'Pending review'],
    'active'   => ['success',   'fa-hands-praying',  'Active'],
    'answered' => ['info',      'fa-check-double',   'Answered'],
    'archived' => ['secondary', 'fa-box-archive',    'Archived'],
];
$badge = $statusBadges[$req['status']] ?? ['secondary', 'fa-circle', ucfirst((string) $req['status'])];
?>

<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h1 class="mb-1"><?php echo htmlspecialchars((string) $req['subject'], ENT_QUOTES, 'UTF-8'); ?></h1>
        <div>
            <span class="badge bg-<?php echo $badge[0]; ?> me-1">
                <i class="fa-solid <?php echo $badge[1]; ?> me-1"></i><?php echo $badge[2]; ?>
            </span>
            <?php if ($req['visibility'] === 'congregation'): ?>
                <span class="badge bg-info-subtle text-info-emphasis">
                    <i class="fa-solid fa-people-group me-1"></i>Congregation
                </span>
            <?php else: ?>
                <span class="badge bg-secondary-subtle text-secondary-emphasis">
                    <i class="fa-solid fa-user-shield me-1"></i>Leadership only
                </span>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <?php if ($isMod === true): ?>
            <a href="/prayer-requests/manage" class="btn btn-outline-primary">
                <i class="fa-solid fa-gauge-high me-1"></i> Moderate
            </a>
        <?php endif; ?>
        <a href="/prayer-requests" class="btn btn-outline-secondary">
            <i class="fa-solid fa-arrow-left me-1"></i> Back
        </a>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <p class="text-muted small mb-3">
            <i class="fa-solid fa-user me-1"></i>
            <?php echo htmlspecialchars($submitterDisplay, ENT_QUOTES, 'UTF-8'); ?>
            <?php if ($modSubmitterDisplay !== ''): ?>
                <span class="badge bg-warning-subtle text-warning-emphasis ms-1" title="Visible to moderators only">
                    posted by <?php echo htmlspecialchars($modSubmitterDisplay, ENT_QUOTES, 'UTF-8'); ?>
                </span>
            <?php endif; ?>
            &middot;
            <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime((string) $req['createdAt'])), ENT_QUOTES, 'UTF-8'); ?>
        </p>

        <div class="mb-0 portal-markdown">
            <?php
            // 🪞 Markdown rendering (#270). Prayer requests get the safer
            //    profile — no images, links allowed but anti-abuse tagged.
            echo \Portal\Core\Markdown::render(
                (string) $req['body'],
                ['allow_images' => false, 'allow_links' => true]
            );
            ?>
        </div>
    </div>
</div>

<?php if ($req['status'] === 'answered' && (string) ($req['testimony'] ?? '') !== ''): ?>
    <div class="card shadow-sm mb-4 border-success">
        <div class="card-body">
            <h2 class="h5 text-success mb-2">
                <i class="fa-solid fa-seedling me-2"></i>Praise / Testimony
            </h2>
            <p class="text-muted small mb-3">
                <?php if ($req['answeredAt'] !== null): ?>
                    <i class="fa-solid fa-calendar-check me-1"></i>
                    <?php echo htmlspecialchars(date('Y-m-d', strtotime((string) $req['answeredAt'])), ENT_QUOTES, 'UTF-8'); ?>
                <?php endif; ?>
            </p>
            <div class="portal-markdown">
                <?php
                echo \Portal\Core\Markdown::render(
                    (string) $req['testimony'],
                    ['allow_images' => false, 'allow_links' => true]
                );
                ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($isMod === true): ?>
    <!-- 🛡️ Moderator quick actions -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6 mb-3"><i class="fa-solid fa-gauge-high me-1"></i>Moderator actions</h2>
            <form method="post" action="/prayer-requests/moderate" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token"
                       value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="requestID" value="<?php echo (int) $req['requestID']; ?>">
                <input type="hidden" name="redirect"  value="view">

                <div class="col-md-3">
                    <label for="modAction" class="form-label small mb-1">Action</label>
                    <select name="action" id="modAction" class="form-select form-select-sm" required>
                        <option value="approve">Approve (→ active)</option>
                        <option value="archive">Archive</option>
                        <option value="answer">Mark answered</option>
                        <option value="visibility-leadership">Set visibility: Leadership</option>
                        <option value="visibility-congregation">Set visibility: Congregation</option>
                    </select>
                </div>
                <div class="col-md-7">
                    <label for="testimony" class="form-label small mb-1">
                        Testimony (only used when marking answered)
                    </label>
                    <input type="text" id="testimony" name="testimony"
                           class="form-control form-control-sm"
                           maxlength="2000"
                           placeholder="Optional praise note">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-primary w-100">
                        <i class="fa-solid fa-check me-1"></i> Apply
                    </button>
                </div>
            </form>

            <?php if ($req['moderatorFullName'] !== null && $req['moderatedAt'] !== null): ?>
                <p class="text-muted small mt-3 mb-0">
                    Last moderated by
                    <?php echo htmlspecialchars((string) $req['moderatorFullName'], ENT_QUOTES, 'UTF-8'); ?>
                    on
                    <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime((string) $req['moderatedAt'])), ENT_QUOTES, 'UTF-8'); ?>
                </p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
