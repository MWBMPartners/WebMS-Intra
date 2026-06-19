<?php
// Path: _apps/admin/calendar/event-orgs.php
/**
 * -----------------------------------------------------------------------------
 * Admin/Coordinator — Event organiser picker (#332)
 * -----------------------------------------------------------------------------
 * Manage the new tblEventOrgs junction — list, add, remove. Supports
 * multiple primary orgs + partner orgs per event.
 *
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/332
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$eventId = (int) ($_GET['eventID'] ?? 0);
if ($eventId <= 0 || (App::isAdmin() === false && Auth::isCoordinatorOf($eventId) === false)) {
    http_response_code(403); exit('Forbidden');
}
$siteId = Site::id();

$event = null;
$stmt = $mysqli->prepare('SELECT eventID, eventName FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0');
$stmt->bind_param('ii', $eventId, $siteId);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();
if ($event === null) { http_response_code(404); exit('Event not found'); }

$orgs = [];
$stmt = $mysqli->prepare('SELECT eventOrgID, orgName, orgUrl, isPrimary FROM tblEventOrgs WHERE eventID = ? ORDER BY isPrimary DESC, sortOrder');
$stmt->bind_param('i', $eventId);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) { $orgs[] = $r; }
$stmt->close();

$pageTitle = 'Organisers — ' . (string) $event['eventName'];
$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container py-3" style="max-width:720px;">
    <h1 class="h4 mb-2"><i class="fa-solid fa-building me-2 text-primary"></i>Organisers — <?php echo htmlspecialchars((string) $event['eventName'], ENT_QUOTES, 'UTF-8'); ?></h1>
    <p class="text-muted small">Add multiple co-organisers. Primary orgs show prominently on the event page; partners show in a smaller "in partnership with" line.</p>

    <?php if (count($orgs) === 0): ?>
        <div class="alert alert-info">No organiser rows yet. Add one below.</div>
    <?php else: ?>
        <div class="portal-data-list mb-4">
        <?php foreach ($orgs as $o): ?>
            <div class="portal-data-row">
                <div class="portal-data-row-main">
                    <strong><?php echo htmlspecialchars((string) $o['orgName'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <?php if ((int) $o['isPrimary'] === 1): ?>
                        <span class="badge bg-primary ms-1">Primary</span>
                    <?php else: ?>
                        <span class="badge bg-secondary ms-1">Partner</span>
                    <?php endif; ?>
                    <?php if (!empty($o['orgUrl'])): ?>
                        <div class="small text-muted"><a href="<?php echo htmlspecialchars((string) $o['orgUrl'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank"><?php echo htmlspecialchars((string) $o['orgUrl'], ENT_QUOTES, 'UTF-8'); ?></a></div>
                    <?php endif; ?>
                </div>
                <div class="portal-data-row-aside">
                    <form method="post" action="/admin/calendar/event-orgs/save" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
                        <input type="hidden" name="eventOrgID" value="<?php echo (int) $o['eventOrgID']; ?>">
                        <input type="hidden" name="action" value="remove">
                        <button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-xmark me-1"></i>Remove</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h2 class="h6 mt-4">Add organiser</h2>
    <form method="post" action="/admin/calendar/event-orgs/save" class="row g-2 align-items-end">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
        <input type="hidden" name="action" value="add">
        <div class="col-md-5">
            <label for="orgName" class="form-label small">Name</label>
            <input type="text" id="orgName" name="orgName" required maxlength="255" class="form-control form-control-sm">
        </div>
        <div class="col-md-4">
            <label for="orgUrl" class="form-label small">Website (optional)</label>
            <input type="url" id="orgUrl" name="orgUrl" maxlength="500" class="form-control form-control-sm" placeholder="https://...">
        </div>
        <div class="col-md-2">
            <label for="isPrimary" class="form-label small">Role</label>
            <select id="isPrimary" name="isPrimary" class="form-select form-select-sm">
                <option value="1">Primary</option>
                <option value="0">Partner</option>
            </select>
        </div>
        <div class="col-md-1">
            <button type="submit" class="btn btn-primary btn-sm">Add</button>
        </div>
    </form>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
