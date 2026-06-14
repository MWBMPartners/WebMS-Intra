<?php
// Path: _apps/account/my-prayer-list.php
/**
 * -----------------------------------------------------------------------------
 * Account — My Prayer List 🙏 (#311)
 * -----------------------------------------------------------------------------
 * Shows the currently-logged-in user the prayer requests assigned to them
 * by a moderator. v1 scope: read-only list grouped by status.
 *
 * @package   Portal\Account
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/311
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$pageTitle   = 'My Prayer List';
$pageSection = 'account';
$breadcrumbs = [
    'Account'        => '/account',
    'My Prayer List' => '',
];

$userId = (int) ($_SESSION['user_id'] ?? 0);
$siteId = Site::id();

$rows = [];
$stmt = $mysqli->prepare(
    'SELECT pr.requestID, pr.subject, pr.body, pr.status, pr.assignedAt, '
    . '       pr.isAnonymous, COALESCE(u.fullName, pr.submitterName, "Anonymous") AS submitterDisplay '
    . 'FROM tblPrayerRequests pr '
    . 'LEFT JOIN tblUsers u ON u.userID = pr.submitterID '
    . 'WHERE pr.assignedToUserID = ? AND pr.siteID = ? '
    . '  AND pr.status IN ("active", "answered") '
    . 'ORDER BY FIELD(pr.status, "active", "answered"), pr.assignedAt DESC'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $userId, $siteId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
}

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container py-4">
    <h1 class="h3 mb-3"><i class="fa-solid fa-hands-praying me-2 text-primary"></i>My Prayer List</h1>
    <p class="text-muted">
        Requests assigned to you by a moderator. Anonymous submissions show
        the submitter as "Anonymous" — they have asked for prayer but
        prefer privacy on the request author.
    </p>

    <?php if (count($rows) === 0): ?>
        <div class="alert alert-info">
            <i class="fa-solid fa-circle-info me-1"></i>
            You have no open prayer assignments right now. Moderators assign
            requests from <a href="/prayer-requests/manage">the moderation queue</a>.
        </div>
    <?php else: ?>
        <div class="portal-data-list">
        <?php foreach ($rows as $r):
            $statusBadge = $r['status'] === 'active' ? 'bg-warning' : 'bg-success';
            $assignedAtFmt = $r['assignedAt'] !== null
                ? date('j M Y', strtotime((string) $r['assignedAt']))
                : '—';
        ?>
            <div class="portal-data-row">
                <div class="portal-data-row-main">
                    <h2 class="h6 mb-1"><?php echo htmlspecialchars((string) $r['subject'], ENT_QUOTES, 'UTF-8'); ?></h2>
                    <div class="text-muted small mb-2">
                        <i class="fa-solid fa-user me-1"></i>
                        <?php echo htmlspecialchars((string) $r['submitterDisplay'], ENT_QUOTES, 'UTF-8'); ?>
                        &middot;
                        <i class="fa-solid fa-calendar-check ms-1 me-1"></i>
                        Assigned <?php echo htmlspecialchars($assignedAtFmt, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="portal-prose">
                        <?php echo nl2br(htmlspecialchars((string) $r['body'], ENT_QUOTES, 'UTF-8')); ?>
                    </div>
                </div>
                <div class="portal-data-row-aside">
                    <span class="badge <?php echo $statusBadge; ?>">
                        <?php echo htmlspecialchars(ucfirst((string) $r['status']), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
            </div>
        <?php endforeach; ?>
        </div>

        <p class="text-muted small mt-3">
            <i class="fa-solid fa-shield-halved me-1"></i>
            This list is visible only to you. Moderators see the assigned-partner
            name on the moderation queue but cannot see which assignments you
            have already prayed through.
        </p>
    <?php endif; ?>
</div>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
