<?php
// _apps/calendar/anon-checkin.php — Anonymous check-in landing (#314)
declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Site;

$eventId = (int) ($_GET['eventID'] ?? 0);
if ($eventId <= 0) { http_response_code(400); exit('Invalid event.'); }

$siteId = Site::id();
$stmt = $mysqli->prepare('SELECT eventID, eventName, startDateTime FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0 AND status = "published" LIMIT 1');
$stmt->bind_param('ii', $eventId, $siteId);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();
if ($event === null) { http_response_code(404); exit('Event not found.'); }

$pageTitle = 'Check in — ' . (string) $event['eventName'];
$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-5 text-center" style="max-width:480px;">
    <h1 class="h3 mb-2"><?php echo htmlspecialchars((string) $event['eventName'], ENT_QUOTES, 'UTF-8'); ?></h1>
    <p class="text-muted small mb-4"><?php echo htmlspecialchars(date('l j M Y', strtotime((string) $event['startDateTime'])), ENT_QUOTES, 'UTF-8'); ?></p>

    <form method="post" action="/attend/save">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
        <div class="mb-3">
            <label for="headcount" class="form-label small">How many in your group?</label>
            <input type="number" id="headcount" name="headcount" value="1" min="1" max="20" class="form-control form-control-lg text-center">
        </div>
        <button type="submit" class="btn btn-success btn-lg w-100"><i class="fa-solid fa-circle-check me-1"></i>Check in</button>
    </form>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
