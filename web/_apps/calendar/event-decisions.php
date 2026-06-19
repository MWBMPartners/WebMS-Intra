<?php
// _apps/calendar/event-decisions.php — Decision Moments dashboard (#315)
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

$stmt = $mysqli->prepare('SELECT eventID, eventName FROM tblEvents WHERE eventID = ? AND siteID = ? AND isDeleted = 0');
$stmt->bind_param('ii', $eventId, $siteId);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();
if ($event === null) { http_response_code(404); exit('Event not found'); }

$types = [
    'first-decision'       => ['label' => 'First decision',       'icon' => 'fa-hand-holding-heart', 'colour' => 'success'],
    'rededication'         => ['label' => 'Rededication',         'icon' => 'fa-arrow-rotate-left', 'colour' => 'info'],
    'baptism-request'      => ['label' => 'Baptism request',      'icon' => 'fa-water',             'colour' => 'primary'],
    'membership-interest'  => ['label' => 'Membership interest',  'icon' => 'fa-handshake',         'colour' => 'warning'],
    'prayer-request'       => ['label' => 'Prayer request',       'icon' => 'fa-hands-praying',     'colour' => 'secondary'],
    'other'                => ['label' => 'Other',                'icon' => 'fa-circle-question',   'colour' => 'dark'],
];

$counts = array_fill_keys(array_keys($types), 0);
$stmt = $mysqli->prepare('SELECT momentType, count FROM tblDecisionMoments WHERE eventID = ?');
$stmt->bind_param('i', $eventId);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) { $counts[(string) $r['momentType']] = (int) $r['count']; }
$stmt->close();

$pageTitle = 'Decision Moments — ' . (string) $event['eventName'];
$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:760px;">
    <h1 class="h4 mb-2"><i class="fa-solid fa-hand-holding-heart me-2 text-primary"></i>Decision Moments — <?php echo htmlspecialchars((string) $event['eventName'], ENT_QUOTES, 'UTF-8'); ?></h1>
    <p class="text-muted small">Tap to add. Each card is its own counter. No personal info — that's <a href="/decision-card">decision cards</a> (#316).</p>

    <div class="row g-3">
    <?php foreach ($types as $key => $meta): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card text-center h-100">
                <div class="card-body">
                    <i class="fa-solid <?php echo htmlspecialchars($meta['icon'], ENT_QUOTES, 'UTF-8'); ?> fa-2x text-<?php echo $meta['colour']; ?> mb-2"></i>
                    <h2 class="h6 mb-0"><?php echo htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8'); ?></h2>
                    <div class="display-5 fw-bold my-2"><?php echo (int) $counts[$key]; ?></div>
                    <form method="post" action="/calendar/event/decisions/bump" class="d-flex gap-1 justify-content-center">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
                        <input type="hidden" name="momentType" value="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" name="delta" value="1" class="btn btn-sm btn-outline-<?php echo $meta['colour']; ?>">+1</button>
                        <button type="submit" name="delta" value="-1" class="btn btn-sm btn-outline-secondary" <?php echo $counts[$key] <= 0 ? 'disabled' : ''; ?>>−1</button>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
