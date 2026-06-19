<?php
// Path: _apps/calendar/event-invites.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Anonymous RSVP invite manager (#335)
 * -----------------------------------------------------------------------------
 * Coordinator/admin UI. List existing invites + their status (sent /
 * responded / expired) + form to send a new invite.
 *
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/335
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

$invites = [];
$stmt = $mysqli->prepare(
    'SELECT inviteID, email, displayName, createdAt, expiresAt, usedAt, response '
    . 'FROM tblEventRSVPInvites WHERE eventID = ? ORDER BY createdAt DESC LIMIT 200'
);
$stmt->bind_param('i', $eventId);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) { $invites[] = $r; }
$stmt->close();

$pageTitle = 'Invitations — ' . (string) $event['eventName'];
$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container py-3" style="max-width:760px;">
    <h1 class="h4 mb-2"><i class="fa-solid fa-envelope-open-text me-2 text-primary"></i>Invitations — <?php echo htmlspecialchars((string) $event['eventName'], ENT_QUOTES, 'UTF-8'); ?></h1>
    <p class="text-muted small">Send an RSVP link to someone who doesn't have a portal account. They get a single-click landing page; the response feeds the headcount and broadcaster.</p>

    <details class="mb-4" open>
        <summary class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-plus me-1"></i>Send an invite</summary>
        <form method="post" action="/calendar/event/invites/send" class="row g-2 mt-2 align-items-end p-2 bg-light rounded">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="eventID" value="<?php echo $eventId; ?>">
            <div class="col-md-5"><label class="form-label small">Email</label><input type="email" name="email" required maxlength="255" class="form-control form-control-sm"></div>
            <div class="col-md-4"><label class="form-label small">Display name (optional)</label><input type="text" name="displayName" maxlength="120" class="form-control form-control-sm"></div>
            <div class="col-md-3"><button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-paper-plane me-1"></i>Send link</button></div>
        </form>
    </details>

    <?php if (count($invites) === 0): ?>
        <div class="alert alert-info">No invites sent yet.</div>
    <?php else: ?>
        <div class="portal-data-list">
        <?php foreach ($invites as $i):
            $isUsed    = $i['usedAt'] !== null;
            $isExpired = strtotime((string) $i['expiresAt']) < time();
            $badge = $isUsed
                ? ['bg' => 'bg-success', 'text' => 'Responded: ' . ucfirst((string) $i['response'])]
                : ($isExpired ? ['bg' => 'bg-secondary', 'text' => 'Expired'] : ['bg' => 'bg-info text-dark', 'text' => 'Awaiting response']);
        ?>
            <div class="portal-data-row">
                <div class="portal-data-row-main">
                    <strong><?php echo htmlspecialchars((string) ($i['displayName'] ?? $i['email']), ENT_QUOTES, 'UTF-8'); ?></strong>
                    <div class="text-muted small">
                        <?php echo htmlspecialchars((string) $i['email'], ENT_QUOTES, 'UTF-8'); ?>
                        &middot; sent <?php echo htmlspecialchars(date('j M Y', strtotime((string) $i['createdAt'])), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                </div>
                <div class="portal-data-row-aside">
                    <span class="badge <?php echo $badge['bg']; ?>"><?php echo htmlspecialchars($badge['text'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
