<?php
// Path: _apps/account/my-volunteering.php
/**
 * -----------------------------------------------------------------------------
 * Account — My Volunteering portal 🤝 (#342)
 * -----------------------------------------------------------------------------
 * One landing page showing the logged-in user every event they're rostered
 * on — schedule, role, team, documents — without hunting through five apps.
 * The user-explicit differentiator vs VBS Pro (which has no logged-in
 * volunteer resource portal).
 *
 * Pure read-side composition: NO new tables. Joins:
 *   tblEventPeople        — the user's role on the team
 *   tblEventCoordinators  — events they coordinate (admin-style rights)
 *   tblDocuments          — event-scoped documents (eventID set in #351)
 *   tblEvents             — the parent event (filter to upcoming + visible)
 *
 * @package   Portal\Account
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/342
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$pageTitle   = 'My Volunteering';
$pageSection = 'account';
$breadcrumbs = ['Account' => '/account', 'My Volunteering' => ''];

$userId = (int) ($_SESSION['user_id'] ?? 0);
$siteId = Site::id();

// 📋 Collect the upcoming events this user is involved with. Two paths:
//    (a) tblEventPeople — they're listed as a host/speaker/musician/etc.
//    (b) tblEventCoordinators — they own the event end-to-end.
// Union, dedupe by eventID, return future events only.
$events = [];

$stmt = $mysqli->prepare(
    'SELECT e.eventID, e.eventName, e.eventSlug, e.startDateTime, e.endDateTime, '
    . '       e.locationName, e.status, e.description, '
    . '       GROUP_CONCAT(DISTINCT ep.role ORDER BY ep.role SEPARATOR ", ") AS myRoles, '
    . '       CASE WHEN ec.coordinatorID IS NOT NULL THEN 1 ELSE 0 END AS amCoordinator '
    . 'FROM tblEvents e '
    . 'LEFT JOIN tblEventPeople ep '
    . '       ON ep.eventID = e.eventID AND ep.userID = ? '
    . 'LEFT JOIN tblEventCoordinators ec '
    . '       ON ec.eventID = e.eventID AND ec.userID = ? AND ec.revokedAt IS NULL '
    . 'WHERE e.siteID = ? AND e.isDeleted = 0 '
    . '  AND e.startDateTime >= DATE_SUB(NOW(), INTERVAL 1 DAY) '
    . '  AND (ep.eventPersonID IS NOT NULL OR ec.coordinatorID IS NOT NULL) '
    . 'GROUP BY e.eventID '
    . 'ORDER BY e.startDateTime ASC LIMIT 30'
);
if ($stmt !== false) {
    $stmt->bind_param('iii', $userId, $userId, $siteId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $events[] = $r;
    }
    $stmt->close();
}

// 📚 Per-event: documents + team. Done as separate queries (one per event)
//     because the list is small (≤30 events) and MySQL doesn't have a
//     concise way to JSON_AGG these in a single shot.
$perEventDocs = [];
$perEventTeam = [];
foreach ($events as $e) {
    $eid = (int) $e['eventID'];

    $docs = [];
    $stmt = $mysqli->prepare(
        'SELECT documentID, title, fileName, fileSize, downloadCount '
        . 'FROM tblDocuments '
        . 'WHERE eventID = ? AND siteID = ? AND isPublished = 1 AND isDeleted = 0 '
        . 'ORDER BY title ASC LIMIT 10'
    );
    if ($stmt !== false) {
        $stmt->bind_param('ii', $eid, $siteId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $docs[] = $r;
        }
        $stmt->close();
    }
    $perEventDocs[$eid] = $docs;

    $team = [];
    $stmt = $mysqli->prepare(
        'SELECT ep.role, ep.isPrimary, COALESCE(u.fullName, ep.externalName) AS personName '
        . 'FROM tblEventPeople ep '
        . 'LEFT JOIN tblUsers u ON u.userID = ep.userID '
        . 'WHERE ep.eventID = ? '
        . 'ORDER BY ep.sortOrder, ep.role LIMIT 20'
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $eid);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $team[] = $r;
        }
        $stmt->close();
    }
    $perEventTeam[$eid] = $team;
}

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container py-4">
    <h1 class="h3 mb-3"><i class="fa-solid fa-hands-helping me-2 text-primary"></i>My Volunteering</h1>
    <p class="text-muted">
        Everything you need for the events you're rostered on — schedule, your role, the team
        you're serving with, and any documents your coordinator has shared.
    </p>

    <?php if (count($events) === 0): ?>
        <div class="alert alert-info">
            <i class="fa-solid fa-circle-info me-1"></i>
            You're not currently rostered on any upcoming events.
            When a coordinator adds you to an event team, it'll appear here.
        </div>
    <?php else: ?>
        <?php foreach ($events as $e):
            $eid = (int) $e['eventID'];
            $when = date('l j M Y, H:i', strtotime((string) $e['startDateTime']));
            $myRoles = (string) ($e['myRoles'] ?? '');
            $iCoord = ((int) $e['amCoordinator']) === 1;
        ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h2 class="h5 mb-0">
                        <a href="/calendar/event?slug=<?php echo htmlspecialchars((string) $e['eventSlug'], ENT_QUOTES, 'UTF-8'); ?>" class="text-decoration-none">
                            <?php echo htmlspecialchars((string) $e['eventName'], ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    </h2>
                    <?php if ($iCoord === true): ?>
                        <span class="badge bg-primary"><i class="fa-solid fa-user-shield me-1"></i>Coordinator</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <p class="mb-2 text-muted">
                        <i class="fa-solid fa-calendar me-1"></i><?php echo htmlspecialchars($when, ENT_QUOTES, 'UTF-8'); ?>
                        <?php if (!empty($e['locationName'])): ?>
                            &middot; <i class="fa-solid fa-location-dot ms-1 me-1"></i>
                            <?php echo htmlspecialchars((string) $e['locationName'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </p>

                    <?php if ($myRoles !== ''): ?>
                        <p class="mb-3">
                            <strong>My role:</strong>
                            <span class="badge bg-info text-dark"><?php echo htmlspecialchars($myRoles, ENT_QUOTES, 'UTF-8'); ?></span>
                        </p>
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <h3 class="h6"><i class="fa-solid fa-users me-1 text-secondary"></i>Team</h3>
                            <?php if (count($perEventTeam[$eid]) === 0): ?>
                                <p class="text-muted small">Team list not published yet.</p>
                            <?php else: ?>
                                <ul class="list-unstyled small mb-0">
                                <?php foreach ($perEventTeam[$eid] as $t): ?>
                                    <li class="mb-1">
                                        <i class="fa-solid fa-user text-muted me-1"></i>
                                        <strong><?php echo htmlspecialchars((string) $t['personName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <span class="text-muted">&middot; <?php echo htmlspecialchars(ucfirst((string) $t['role']), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </li>
                                <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-6">
                            <h3 class="h6"><i class="fa-solid fa-folder-open me-1 text-secondary"></i>Documents</h3>
                            <?php if (count($perEventDocs[$eid]) === 0): ?>
                                <p class="text-muted small">No documents shared for this event yet.</p>
                            <?php else: ?>
                                <ul class="list-unstyled small mb-0">
                                <?php foreach ($perEventDocs[$eid] as $d): ?>
                                    <li class="mb-1">
                                        <a href="/documents/download?id=<?php echo (int) $d['documentID']; ?>" class="text-decoration-none">
                                            <i class="fa-solid fa-file-lines me-1"></i>
                                            <?php echo htmlspecialchars((string) $d['title'], ENT_QUOTES, 'UTF-8'); ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-light d-flex gap-2">
                    <a href="/calendar/event?slug=<?php echo htmlspecialchars((string) $e['eventSlug'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-primary">
                        <i class="fa-solid fa-circle-info me-1"></i>Event details
                    </a>
                    <?php if ($iCoord === true): ?>
                        <a href="/calendar/manage?edit=<?php echo $eid; ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="fa-solid fa-pen me-1"></i>Manage event
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
