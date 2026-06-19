<?php
// Path: _apps/admin/calendar/moderation.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Calendar Submission Moderation Queue 🛡️📅 (#326)
 * -----------------------------------------------------------------------------
 * Lists events in submissionStatus='pending' awaiting review. Per-row
 * approve / reject / open-for-edit actions POST to /admin/calendar/moderate.
 *
 * @package   Portal\Admin
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/326
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

$siteId = Site::id();
$filter = (string) ($_GET['status'] ?? 'pending');
if (in_array($filter, ['pending', 'approved', 'rejected'], true) === false) {
    $filter = 'pending';
}

$rows = [];
$stmt = $mysqli->prepare(
    'SELECT e.eventID, e.eventName, e.startDateTime, e.endDateTime, e.locationName, e.description, '
    . '       e.submitterName, e.submitterEmail, e.submittedAt, '
    . '       COALESCE(u.fullName, e.submitterName, "Anonymous") AS submitterDisplay '
    . 'FROM tblEvents e '
    . 'LEFT JOIN tblUsers u ON u.userID = e.submittedByID '
    . 'WHERE e.siteID = ? AND e.submissionStatus = ? '
    . 'ORDER BY e.submittedAt DESC LIMIT 100'
);
if ($stmt !== false) {
    $stmt->bind_param('is', $siteId, $filter);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();
}

$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
$stmt = $mysqli->prepare(
    'SELECT submissionStatus, COUNT(*) AS cnt FROM tblEvents '
    . 'WHERE siteID = ? AND submissionStatus IS NOT NULL '
    . 'GROUP BY submissionStatus'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $counts[(string) $r['submissionStatus']] = (int) $r['cnt'];
    }
    $stmt->close();
}

$pageTitle   = 'Moderate Calendar Submissions';
$pageSection = 'admin';
$csrf        = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-4">
    <h1 class="h3 mb-3"><i class="fa-solid fa-calendar-check me-2 text-primary"></i>Moderate Calendar Submissions</h1>
    <p class="text-muted">Public event submissions from <a href="/calendar/submit">/calendar/submit</a> wait here until an admin approves them.</p>

    <ul class="nav nav-pills mb-3">
        <?php foreach (['pending' => 'fa-clock', 'approved' => 'fa-check', 'rejected' => 'fa-xmark'] as $s => $ico): ?>
            <li class="nav-item">
                <a class="nav-link <?php echo $filter === $s ? 'active' : ''; ?>"
                   href="/admin/calendar/moderation?status=<?php echo $s; ?>">
                    <i class="fa-solid <?php echo $ico; ?> me-1"></i>
                    <?php echo ucfirst($s); ?> <span class="badge bg-light text-dark ms-1"><?php echo (int) $counts[$s]; ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if (count($rows) === 0): ?>
        <div class="alert alert-info">No <?php echo htmlspecialchars($filter, ENT_QUOTES, 'UTF-8'); ?> submissions right now.</div>
    <?php else: ?>
        <div class="portal-data-list">
        <?php foreach ($rows as $r): ?>
            <div class="portal-data-row">
                <div class="portal-data-row-main">
                    <h2 class="h6 mb-1"><?php echo htmlspecialchars((string) $r['eventName'], ENT_QUOTES, 'UTF-8'); ?></h2>
                    <div class="text-muted small mb-2">
                        <i class="fa-solid fa-user me-1"></i>
                        <?php echo htmlspecialchars((string) $r['submitterDisplay'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php if ($r['submitterEmail'] !== null && $r['submitterEmail'] !== ''): ?>
                            &middot; <a href="mailto:<?php echo htmlspecialchars((string) $r['submitterEmail'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $r['submitterEmail'], ENT_QUOTES, 'UTF-8'); ?></a>
                        <?php endif; ?>
                        &middot; <i class="fa-solid fa-calendar ms-1 me-1"></i>
                        <?php echo htmlspecialchars(date('j M Y, H:i', strtotime((string) $r['startDateTime'])), ENT_QUOTES, 'UTF-8'); ?>
                        <?php if ($r['locationName'] !== null && $r['locationName'] !== ''): ?>
                            &middot; <i class="fa-solid fa-location-dot ms-1 me-1"></i>
                            <?php echo htmlspecialchars((string) $r['locationName'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($r['description'] !== null && $r['description'] !== ''): ?>
                        <div class="portal-prose small">
                            <?php echo nl2br(htmlspecialchars(mb_substr((string) $r['description'], 0, 300), ENT_QUOTES, 'UTF-8')); ?>
                            <?php if (mb_strlen((string) $r['description']) > 300): ?>…<?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="portal-data-row-aside">
                    <?php if ($filter === 'pending'): ?>
                        <form method="post" action="/admin/calendar/moderate" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                            <input type="hidden" name="eventID" value="<?php echo (int) $r['eventID']; ?>">
                            <input type="hidden" name="action" value="approve">
                            <button type="submit" class="btn btn-success btn-sm"><i class="fa-solid fa-check me-1"></i>Approve</button>
                        </form>
                        <form method="post" action="/admin/calendar/moderate" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                            <input type="hidden" name="eventID" value="<?php echo (int) $r['eventID']; ?>">
                            <input type="hidden" name="action" value="reject">
                            <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fa-solid fa-xmark me-1"></i>Reject</button>
                        </form>
                    <?php else: ?>
                        <span class="badge <?php echo $filter === 'approved' ? 'bg-success' : 'bg-danger'; ?>"><?php echo ucfirst((string) $filter); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
