<?php
// Path: _apps/admin/live/chat.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Live Chat Moderation queue (#313 Phase 1)
 * -----------------------------------------------------------------------------
 * Lists every pending + flagged chat message for the active site, with
 * approve / hide controls. Open in another tab next to the host console.
 *
 * Access:
 *   • Admin — always
 *   • stream_moderator role — yes (via App::hasRole)
 *   • everyone else — 403
 *
 * @link https://github.com/MWBMPartners/WebMS-Intra/issues/313
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Settings;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

if (App::isAdmin() === false && App::hasRole('stream_moderator') === false) {
    http_response_code(403);
    exit('Forbidden');
}

$siteId = Site::id();

// 📋 Pull the moderation queue — pending + flagged across all events
//    for the active site, newest first. Include event name + moderator if
//    set (history shown after action).
$queue = [];
$stmt = $mysqli->prepare(
    'SELECT m.messageID, m.eventID, m.displayName, m.body, m.status, '
    . '       m.flaggedReason, m.senderIP, m.createdAt, '
    . '       COALESCE(e.eventName, "— no event —") AS eventName '
    . 'FROM tblLiveChatMessages m '
    . 'LEFT JOIN tblEvents e ON e.eventID = m.eventID '
    . 'WHERE m.siteID = ? AND m.status IN ("pending", "flagged") '
    . 'ORDER BY m.messageID DESC LIMIT 200'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $result = $stmt->get_result();
    while (($r = $result->fetch_assoc()) !== null) {
        $queue[] = $r;
    }
    $stmt->close();
}

// 📊 Tallies for the page header
$pendingCount = 0;
$flaggedCount = 0;
foreach ($queue as $r) {
    if ((string) $r['status'] === 'pending') {
        $pendingCount++;
    } elseif ((string) $r['status'] === 'flagged') {
        $flaggedCount++;
    }
}

$csrfToken   = Auth::csrfToken();
$autoApprove = (string) Settings::get('chat.autoApprove', 'false');
$pageTitle   = 'Live Chat Moderation';
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:960px;">
    <h1 class="h4 mb-2">
        <i class="fa-solid fa-comments me-2 text-primary"></i>Live Chat Moderation
    </h1>
    <p class="text-muted small mb-3">
        Phase 1 of the <a href="https://github.com/MWBMPartners/WebMS-Intra/issues/313">COP Online Engagement</a> roll-out. Review viewer chat from active livestreams and approve, hide, or re-flag each message. Phase 2 will add reactions and host push prompts.
    </p>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <div class="display-6 fw-bold text-warning"><?php echo (int) $pendingCount; ?></div>
                    <p class="text-muted mb-0 small">Awaiting moderation</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <div class="display-6 fw-bold text-danger"><?php echo (int) $flaggedCount; ?></div>
                    <p class="text-muted mb-0 small">Profanity-flagged</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <div class="h6 fw-bold mb-1">
                        <?php echo $autoApprove === 'true' || $autoApprove === '1'
                            ? '<span class="text-success"><i class="fa-solid fa-check"></i> Auto-approve ON</span>'
                            : '<span class="text-muted"><i class="fa-solid fa-shield"></i> Pre-moderation</span>'; ?>
                    </div>
                    <p class="text-muted mb-0 small">
                        Toggle in <a href="/admin/settings?key=chat.autoApprove">settings</a>.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <?php if (count($queue) === 0): ?>
        <div class="alert alert-success small">
            <i class="fa-solid fa-check-circle me-1"></i>
            The moderation queue is empty. New chat messages will appear here as viewers post.
        </div>
    <?php else: ?>
        <div class="portal-data-list" id="chat-queue">
        <?php foreach ($queue as $m): ?>
            <?php
                $messageId   = (int) $m['messageID'];
                $status      = (string) $m['status'];
                $reason      = $m['flaggedReason'] !== null ? (string) $m['flaggedReason'] : '';
                $statusBadge = $status === 'flagged'
                    ? '<span class="badge bg-danger ms-1">FLAGGED' . ($reason !== '' ? ' · ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') : '') . '</span>'
                    : '<span class="badge bg-warning text-dark ms-1">PENDING</span>';
            ?>
            <div class="portal-data-row" data-message-id="<?php echo $messageId; ?>">
                <div class="portal-data-row-main">
                    <div class="fw-semibold">
                        <?php echo htmlspecialchars((string) $m['displayName'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php echo $statusBadge; ?>
                    </div>
                    <div class="my-1" style="white-space:pre-wrap; word-break:break-word;">
                        <?php echo htmlspecialchars((string) $m['body'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="small text-muted">
                        <i class="fa-solid fa-calendar-day me-1"></i>
                        <?php echo htmlspecialchars((string) $m['eventName'], ENT_QUOTES, 'UTF-8'); ?>
                        &middot;
                        <?php echo htmlspecialchars(date('j M H:i', strtotime((string) $m['createdAt'])), ENT_QUOTES, 'UTF-8'); ?>
                        <?php if ($m['senderIP'] !== null && (string) $m['senderIP'] !== ''): ?>
                            &middot; <code><?php echo htmlspecialchars((string) $m['senderIP'], ENT_QUOTES, 'UTF-8'); ?></code>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="portal-data-row-aside">
                    <div class="btn-group btn-group-sm" role="group" aria-label="Moderation actions">
                        <button type="button"
                                class="btn btn-success js-mod-btn"
                                data-action="approve"
                                data-message-id="<?php echo $messageId; ?>">
                            <i class="fa-solid fa-check"></i> Approve
                        </button>
                        <button type="button"
                                class="btn btn-outline-secondary js-mod-btn"
                                data-action="hide"
                                data-message-id="<?php echo $messageId; ?>">
                            <i class="fa-solid fa-eye-slash"></i> Hide
                        </button>
                        <?php if ($status !== 'flagged'): ?>
                            <button type="button"
                                    class="btn btn-outline-danger js-mod-btn"
                                    data-action="flag"
                                    data-message-id="<?php echo $messageId; ?>">
                                <i class="fa-solid fa-flag"></i> Flag
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <p class="text-muted small mt-4">
        Phase 1 ships the moderation queue + send/list/moderate handlers.
        Phase 2 wires the viewer-side chat UI into the <code>/live</code> embed
        and adds host-side push prompts.
    </p>
</div>

<script>
// 🔄 Wire up the moderation buttons. Each button posts to
//    /api/live/chat/moderate with a rotating CSRF token. On success,
//    the row is removed (or visually faded for hide).
(function () {
    'use strict';
    var csrfToken = <?php echo json_encode($csrfToken, JSON_THROW_ON_ERROR); ?>;

    document.querySelectorAll('.js-mod-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var messageId = parseInt(btn.getAttribute('data-message-id') || '0', 10);
            var action    = btn.getAttribute('data-action') || '';
            if (messageId <= 0 || action === '') { return; }

            btn.disabled = true;
            fetch('/api/live/chat/moderate', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    messageID:  messageId,
                    action:     action,
                    csrf_token: csrfToken
                })
            }).then(function (r) {
                return r.json().then(function (j) { return { ok: r.ok, body: j }; });
            }).then(function (resp) {
                if (resp.ok !== true || (resp.body && resp.body.status === 'error')) {
                    alert((resp.body && resp.body.message) ? resp.body.message : 'Moderation failed.');
                    btn.disabled = false;
                    return;
                }
                // 🔄 Rotate the CSRF token for the next click
                if (resp.body && resp.body.data && resp.body.data.csrfToken) {
                    csrfToken = resp.body.data.csrfToken;
                }
                // 🧹 Remove the row from the queue
                var row = btn.closest('.portal-data-row');
                if (row !== null) {
                    row.style.opacity = '0.4';
                    setTimeout(function () { row.remove(); }, 250);
                }
            }).catch(function () {
                alert('Network error — try again.');
                btn.disabled = false;
            });
        });
    });
}());
</script>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
