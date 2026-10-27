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
use Portal\Core\Router;
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
// ⚠️ WHAT WAS WRONG HERE (found by the #519/#520 sweep of every `alias.
// column` reference against the real schema): `tblLiveChatMessages` has no
// column called `flaggedReason` — it is `flagReason`. Every call to this
// page threw ERROR 1054 "Unknown column 'm.flaggedReason'" and answered
// HTTP 500, so the moderation queue has never rendered since it shipped.
// `AS flaggedReason` is kept on purpose so the PHP further down
// ($m['flaggedReason'], used to show the flag reason on a flagged message)
// needs no change at all.
// 👁️ EventVisibility (#514 D5, fix round 1: checker finding 2b). A chat message can carry an
// eventID from before this fix, or from `livechat/api/send.php`, which (a pre-existing gap, not
// #514's to fix here) stores whatever event number any visitor posts (it needs no sign-in, only a livestream session token, which the signed-out ping hands out for any event number). Excluding an
// imported event from this JOIN keeps its name out of the moderation queue — the message itself,
// and the moderation decision on it, are unaffected: it simply shows as "— no event —" instead
// of naming an event the visibility rule might refuse this administrator (an old-flag-only
// administrator counts as a plain member under the rule).
$stmt = $mysqli->prepare(
    'SELECT m.messageID, m.eventID, m.displayName, m.body, m.status, '
    . '       m.flagReason AS flaggedReason, m.senderIP, m.createdAt, '
    . '       COALESCE(e.eventName, "— no event —") AS eventName '
    . 'FROM tblLiveChatMessages m '
    . 'LEFT JOIN tblEvents e ON e.eventID = m.eventID AND e.externalFeedID IS NULL '
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
                        Toggle in <a href="<?php echo htmlspecialchars(Site::url('admin/settings'), ENT_QUOTES, 'UTF-8'); ?>?key=chat.autoApprove">settings</a>.
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

<script nonce="<?php echo htmlspecialchars(\Portal\Core\App::cspNonce(), ENT_QUOTES, 'UTF-8'); ?>">
// 🔄 Wire up the moderation buttons. Each button posts to `api/livechat/moderate`
//    (the handler is _apps/livechat/api/moderate.php) with the page's form token.
//
//    ⚠️ WHAT WAS WRONG HERE (#612), TWO THINGS THAT HAD NEVER MET THE HANDLER:
//    1. The request went to `api/live/chat/moderate`. No file answers that:
//       an `api/{app}/{action}` address is answered by `_apps/{app}/api/{action}.php`
//       and there is no `_apps/live/api/` folder (`_apps/live/` is the viewers'
//       livestream page, a different thing). The handler's app is `livechat`.
//       Every press of Approve, Hide or Flag got "API endpoint not found".
//    2. The script expected the handler to send back a fresh form token, to use
//       for the next press. It never does: the handler (like Auth::verifyCsrf())
//       throws the old token away as soon as it has checked it, and says nothing
//       about the new one. So any second press from the same page would have been
//       refused with "CSRF check failed". The page therefore reloads after every
//       answer: the reloaded page is drawn from the database and carries a fresh
//       token. Approved and hidden messages have left the queue by then; a
//       flagged message stays in it, marked as flagged.
//    Tried and rejected: changing the handler to send the new token back. That
//    would avoid the reload, but the handler was not part of this change. If it
//    ever sends one (the other pages that do put it in `meta.csrfToken` of the
//    reply), this script can use it instead of reloading.
//
//    WHAT THE PERSON SEES (#612, after the independent check):
//    - The moment any button is pressed, EVERY button on the page greys out, not
//      only the one pressed. Otherwise a second message pressed before the page has
//      reloaded would be sent with a token the first press has already used up.
//    - After a success the row fades and the page reloads: the message has left
//      the queue (approved, hidden) or stays marked (flagged), and the buttons
//      work again.
//    - After a refusal (a made-up message, an out-of-date form token) a box shows
//      the handler's words, and then the page reloads as well, so the queue is
//      current and every button works again. Before, the buttons stayed live and
//      every later press said "CSRF check failed" until the person reloaded by hand.
//    - If no answer comes back at all (the network is down), the box says so and the
//      buttons come back WITHOUT a reload: the request may never have reached the
//      portal, so the token may still be good.
//    Checked by tools/live-chat-moderation-selftest.php, which makes the same
//    requests this script makes and, where Node.js is installed, runs this very
//    script with stand-ins for the browser. It cannot show a real browser.
(function () {
    'use strict';
    var csrfToken = <?php echo json_encode($csrfToken, JSON_THROW_ON_ERROR); ?>;
    var allButtons = document.querySelectorAll('.js-mod-btn');

    // Grey out (or bring back) every moderation button on the page at once.
    function setAllDisabled(isOff) {
        allButtons.forEach(function (other) { other.disabled = isOff; });
    }

    allButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var messageId = parseInt(btn.getAttribute('data-message-id') || '0', 10);
            var action    = btn.getAttribute('data-action') || '';
            if (messageId <= 0 || action === '') { return; }

            setAllDisabled(true);
            fetch('<?php echo Router::urlForScript('api/livechat/moderate'); ?>', {
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
                    // The form token is stale after any refusal that got as far as the
                    // handler; reload for a fresh one instead of leaving dead buttons.
                    window.location.reload();
                    return;
                }
                // 🧹 Fade the row out, then reload: the used form token is gone for
                //    good (see the note above), and the reloaded page brings a fresh
                //    one along with the queue as it now stands in the database.
                var row = btn.closest('.portal-data-row');
                if (row !== null) {
                    row.style.opacity = '0.4';
                }
                setTimeout(function () { window.location.reload(); }, 250);
            }).catch(function () {
                alert('Network error — try again.');
                setAllDisabled(false);
            });
        });
    });
}());
</script>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
