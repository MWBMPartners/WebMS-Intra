<?php
// Path: _apps/calendar/rsvp-by-link.php
/**
 * -----------------------------------------------------------------------------
 * Calendar — Anonymous RSVP landing page (#335)
 * -----------------------------------------------------------------------------
 * Public, no-login endpoint. ?t=<token> → fetch the invite, show event
 * details + 3 buttons (Going / Maybe / Declined). POST records the
 * response on the invite row AND inserts an anonymous tblEventRSVPs row
 * so downstream tooling (broadcast, headcount) sees the attendee.
 *
 * Single-file landing + POST handler.
 *
 * @link https://github.com/MWBMPartners/webMS-Intra/issues/335
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Logger;

$token = trim((string) ($_REQUEST['t'] ?? ''));
if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
    http_response_code(400); exit('Invalid invitation link.');
}

// 📋 Fetch invite + parent event.
$stmt = $mysqli->prepare(
    'SELECT i.inviteID, i.eventID, i.email, i.displayName, i.expiresAt, i.usedAt, i.response, '
    . '       e.eventName, e.eventSlug, e.startDateTime, e.endDateTime, e.locationName '
    . 'FROM tblEventRSVPInvites i '
    . 'JOIN tblEvents e ON e.eventID = i.eventID '
    . 'WHERE i.token = ? LIMIT 1'
);
$stmt->bind_param('s', $token);
$stmt->execute();
$invite = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

if ($invite === null) {
    http_response_code(404); exit('Invitation not found.');
}
if (strtotime((string) $invite['expiresAt']) < time()) {
    http_response_code(410); exit('Invitation has expired.');
}

// 💾 POST → record response.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = (string) ($_POST['response'] ?? '');
    if (in_array($response, ['going', 'maybe', 'declined'], true) === false) {
        http_response_code(400); exit('Invalid response.');
    }

    $inviteId = (int) $invite['inviteID'];
    $stmt = $mysqli->prepare(
        'UPDATE tblEventRSVPInvites SET response = ?, usedAt = NOW() WHERE inviteID = ?'
    );
    $stmt->bind_param('si', $response, $inviteId);
    $stmt->execute();
    $stmt->close();

    // 📋 Mirror into tblEventRSVPs as an anonymous row (userID NULL) so the
    //     broadcaster + headcount + manage UI all see the response.
    $eventIdInt = (int) $invite['eventID'];
    $email      = (string) $invite['email'];
    $name       = (string) ($invite['displayName'] ?? '');
    $status     = $response === 'going' ? 'confirmed' : 'pending';

    $stmt = $mysqli->prepare(
        'INSERT INTO tblEventRSVPs (eventID, externalEmail, externalName, response, status, source) '
        . 'VALUES (?, ?, ?, ?, ?, "email-link") '
        . 'ON DUPLICATE KEY UPDATE response = VALUES(response), status = VALUES(status)'
    );
    $stmt->bind_param('issss', $eventIdInt, $email, $name, $response, $status);
    @$stmt->execute(); // Mute on schema variants — UPDATE the invite is the source of truth.
    $stmt->close();

    Logger::activity('EventInviteResponded', 'Invite #' . $inviteId . ' = ' . $response);

    $confirmation = ['going' => "Great — we'll see you there.", 'maybe' => "Thanks — we'll keep your seat warm.", 'declined' => "Thanks for letting us know."][$response];
    $pageTitle = 'RSVP recorded';
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
    echo '<div class="container py-5 text-center" style="max-width:560px;">';
    echo '<i class="fa-solid fa-check-circle fa-3x text-success mb-3"></i>';
    echo '<h1 class="h3">' . htmlspecialchars($confirmation, ENT_QUOTES, 'UTF-8') . '</h1>';
    echo '<p class="text-muted">Your response to "<strong>' . htmlspecialchars((string) $invite['eventName'], ENT_QUOTES, 'UTF-8') . '</strong>" has been recorded.</p>';
    echo '<a href="/calendar/event?slug=' . htmlspecialchars((string) $invite['eventSlug'], ENT_QUOTES, 'UTF-8') . '" class="btn btn-outline-primary mt-3">View event details</a>';
    echo '</div>';
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
    exit();
}

// 🎨 GET → render the landing page.
$pageTitle = 'RSVP — ' . (string) $invite['eventName'];
$when = date('l j M Y, H:i', strtotime((string) $invite['startDateTime']));
$currentResponse = (string) ($invite['response'] ?? '');
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container py-4" style="max-width:560px;">
    <div class="card shadow-sm">
        <div class="card-body p-4 text-center">
            <h1 class="h4 mb-2"><i class="fa-solid fa-calendar-day me-2 text-primary"></i><?php echo htmlspecialchars((string) $invite['eventName'], ENT_QUOTES, 'UTF-8'); ?></h1>
            <p class="text-muted mb-1"><?php echo htmlspecialchars($when, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php if (!empty($invite['locationName'])): ?>
                <p class="text-muted small"><i class="fa-solid fa-location-dot me-1"></i><?php echo htmlspecialchars((string) $invite['locationName'], ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>

            <hr>

            <p class="mb-3">
                <?php if ($invite['displayName'] !== null): ?>
                    Hi <?php echo htmlspecialchars((string) $invite['displayName'], ENT_QUOTES, 'UTF-8'); ?> — can you make it?
                <?php else: ?>
                    Can you make it?
                <?php endif; ?>
            </p>

            <?php if ($currentResponse !== ''): ?>
                <div class="alert alert-info small">You already responded: <strong><?php echo htmlspecialchars(ucfirst($currentResponse), ENT_QUOTES, 'UTF-8'); ?></strong>. Click again to change.</div>
            <?php endif; ?>

            <form method="post" class="d-flex flex-column gap-2">
                <input type="hidden" name="t" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" name="response" value="going" class="btn btn-success btn-lg"><i class="fa-solid fa-check me-1"></i>Yes, I'm going</button>
                <button type="submit" name="response" value="maybe" class="btn btn-warning btn-lg"><i class="fa-solid fa-question me-1"></i>Maybe</button>
                <button type="submit" name="response" value="declined" class="btn btn-outline-secondary btn-lg"><i class="fa-solid fa-xmark me-1"></i>Sorry, can't make it</button>
            </form>

            <p class="text-muted small mt-3 mb-0">
                <a href="/calendar/event?slug=<?php echo htmlspecialchars((string) $invite['eventSlug'], ENT_QUOTES, 'UTF-8'); ?>" class="text-decoration-none">See full event details</a>
            </p>
        </div>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
