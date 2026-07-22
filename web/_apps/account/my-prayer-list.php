<?php
// Path: _apps/account/my-prayer-list.php
/**
 * -----------------------------------------------------------------------------
 * Account — My Prayer List 🙏 (#311)
 * -----------------------------------------------------------------------------
 * Shows the currently-logged-in user their OPEN prayer-chain assignments
 * (assignedToUserID = me, status NOT IN answered/archived — i.e. pending or
 * active), each with a "mark prayed for" action and a private note editor.
 *
 * ACL for the private note (`partnerNote`): this page's query is already
 * scoped `WHERE pr.assignedToUserID = ? (current user)`, so a row only ever
 * appears here — note included — for its own assignee. The write side
 * (my-prayer-list-save.php) re-enforces the same `assignedToUserID = ?`
 * condition in its UPDATE WHERE clause. The only OTHER place the note is
 * exposed is the admin panel on prayer-requests/view.php, gated by
 * App::isAdmin().
 *
 * @package   Portal\Account
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
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
    . '       pr.partnerNote, pr.partnerLastPrayedAt, '
    . '       pr.isAnonymous, COALESCE(u.fullName, pr.submitterName, "Anonymous") AS submitterDisplay '
    . 'FROM tblPrayerRequests pr '
    . 'LEFT JOIN tblUsers u ON u.userID = pr.submitterID '
    . 'WHERE pr.assignedToUserID = ? AND pr.siteID = ? '
    . '  AND pr.status NOT IN ("answered", "archived") '
    . 'ORDER BY FIELD(pr.status, "pending", "active"), pr.assignedAt DESC'
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

$csrf = Auth::csrfToken();
?>

<div class="container py-4">
    <h1 class="h3 mb-3"><i class="fa-solid fa-hands-praying me-2 text-primary"></i>My Prayer List</h1>
    <p class="text-muted">
        Your OPEN prayer-chain assignments (not yet marked answered or
        archived). Anonymous submissions show the submitter as "Anonymous"
        — they have asked for prayer but prefer privacy on the request
        author.
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
            $statusBadge = $r['status'] === 'active' ? 'bg-warning' : 'bg-secondary';
            $assignedAtFmt = $r['assignedAt'] !== null
                ? date('j M Y', strtotime((string) $r['assignedAt']))
                : '—';
            $lastPrayedFmt = $r['partnerLastPrayedAt'] !== null
                ? date('j M Y, H:i', strtotime((string) $r['partnerLastPrayedAt']))
                : null;
            $requestId = (int) $r['requestID'];
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
                        <?php if ($lastPrayedFmt !== null): ?>
                            &middot;
                            <i class="fa-solid fa-hands-praying ms-1 me-1"></i>
                            Last prayed <?php echo htmlspecialchars($lastPrayedFmt, ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </div>
                    <div class="portal-prose mb-3">
                        <?php echo nl2br(htmlspecialchars((string) $r['body'], ENT_QUOTES, 'UTF-8')); ?>
                    </div>

                    <!-- 🙏 Mark prayed for -->
                    <form method="post" action="/account/my-prayer-list/save" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="requestID" value="<?php echo $requestId; ?>">
                        <input type="hidden" name="action" value="mark-prayed">
                        <button type="submit" class="btn btn-sm btn-outline-success mb-2">
                            <i class="fa-solid fa-hands-praying me-1"></i> Mark prayed for
                        </button>
                    </form>

                    <!-- 🔒 Private note — ONLY the assigned partner (this page is already
                         scoped to the current user) or an admin (via prayer-requests/view)
                         ever sees this field. -->
                    <form method="post" action="/account/my-prayer-list/save">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="requestID" value="<?php echo $requestId; ?>">
                        <input type="hidden" name="action" value="save-note">
                        <label for="partnerNote<?php echo $requestId; ?>" class="form-label small mb-1">
                            <i class="fa-solid fa-lock me-1"></i>Private note (only visible to you)
                        </label>
                        <div class="input-group input-group-sm">
                            <textarea name="partnerNote" id="partnerNote<?php echo $requestId; ?>"
                                      class="form-control" rows="2" maxlength="4000"
                                      placeholder="e.g. prayed with Jane on Tuesday, following up next week…"
                            ><?php echo htmlspecialchars((string) ($r['partnerNote'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                            <button type="submit" class="btn btn-outline-secondary">
                                <i class="fa-solid fa-floppy-disk me-1"></i> Save note
                            </button>
                        </div>
                    </form>
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
            This list — and your private notes — are visible only to you (and
            site admins). Moderators see the assigned-partner name on the
            moderation queue but not your notes or prayed-for history.
        </p>
    <?php endif; ?>
</div>

<?php
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
