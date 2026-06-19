<?php
// Path: _apps/calendar/event-overrides.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Per-occurrence overrides admin (#333)
 * -----------------------------------------------------------------------------
 * For a recurring series, list current per-date overrides + add a new one.
 *
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/333
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

$rows = [];
$stmt = $mysqli->prepare('SELECT overrideID, occurrenceDate, isCancelled, overrideName, overrideStartTime, overrideLocation, notes FROM tblEventOccurrenceOverrides WHERE eventID = ? ORDER BY occurrenceDate ASC');
$stmt->bind_param('i', $eventId);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) { $rows[] = $r; }
$stmt->close();

$pageTitle = 'Overrides — ' . (string) $event['eventName'];
$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:760px;">
    <h1 class="h4 mb-2"><i class="fa-solid fa-pen-to-square me-2 text-primary"></i>Per-occurrence overrides — <?php echo htmlspecialchars((string) $event['eventName'], ENT_QUOTES, 'UTF-8'); ?></h1>
    <p class="text-muted small">For recurring series: override or cancel a single date without touching the series rule.</p>

    <?php if (count($rows) === 0): ?>
        <div class="alert alert-info small">No overrides yet.</div>
    <?php else: ?>
        <div class="portal-data-list mb-4">
        <?php foreach ($rows as $r): ?>
            <div class="portal-data-row">
                <div class="portal-data-row-main">
                    <strong><?php echo htmlspecialchars(date('l j M Y', strtotime((string) $r['occurrenceDate'])), ENT_QUOTES, 'UTF-8'); ?></strong>
                    <?php if ((int) $r['isCancelled'] === 1): ?>
                        <span class="badge bg-danger ms-1">Cancelled</span>
                    <?php else: ?>
                        <?php if (!empty($r['overrideName'])): ?>
                            <span class="badge bg-info text-dark ms-1">→ <?php echo htmlspecialchars((string) $r['overrideName'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($r['overrideStartTime'])): ?>
                            <span class="badge bg-secondary ms-1"><?php echo htmlspecialchars((string) $r['overrideStartTime'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($r['overrideLocation'])): ?>
                            <span class="badge bg-secondary ms-1">📍 <?php echo htmlspecialchars((string) $r['overrideLocation'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (!empty($r['notes'])): ?>
                        <div class="small text-muted mt-1"><?php echo htmlspecialchars((string) $r['notes'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                </div>
                <div class="portal-data-row-aside">
                    <form method="post" action="/calendar/event/overrides/save" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
                        <input type="hidden" name="overrideID" value="<?php echo (int) $r['overrideID']; ?>">
                        <input type="hidden" name="action" value="remove">
                        <button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-xmark"></i></button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h2 class="h6">Add override</h2>
    <form method="post" action="/calendar/event/overrides/save" class="row g-2 align-items-end">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
        <input type="hidden" name="action" value="add">
        <div class="col-md-3"><label class="form-label small">Date</label><input type="date" name="occurrenceDate" required class="form-control form-control-sm"></div>
        <div class="col-md-2">
            <label class="form-label small">Mode</label>
            <select name="mode" class="form-select form-select-sm">
                <option value="override">Override</option>
                <option value="cancel">Cancel</option>
            </select>
        </div>
        <div class="col-md-4"><label class="form-label small">New name (optional)</label><input type="text" name="overrideName" maxlength="255" class="form-control form-control-sm"></div>
        <div class="col-md-3 d-flex gap-1">
            <input type="time" name="overrideStartTime" class="form-control form-control-sm" placeholder="time">
            <input type="time" name="overrideEndTime"   class="form-control form-control-sm" placeholder="end">
        </div>
        <div class="col-md-6"><label class="form-label small">New location (optional)</label><input type="text" name="overrideLocation" maxlength="255" class="form-control form-control-sm"></div>
        <div class="col-md-4"><label class="form-label small">Notes (optional)</label><input type="text" name="notes" maxlength="1000" class="form-control form-control-sm"></div>
        <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary btn-sm w-100">Save</button></div>
    </form>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
