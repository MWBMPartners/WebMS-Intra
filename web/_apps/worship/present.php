<?php
// Path: _apps/worship/present.php
/**
 * -----------------------------------------------------------------------------
 * Worship — Live operator console 🎶📺 (#308 Phase 2)
 * -----------------------------------------------------------------------------
 * Logged-in admin/coordinator surface used during a service. Shows:
 *   • Current slide preview (with the audience-facing content)
 *   • Next slide preview (small)
 *   • Big PREV / NEXT buttons (touch-friendly for tablet operation)
 *   • BLANK (logo/wallpaper) + BLACK (solid black) toggles for transitions
 *   • Copyable Display URL for the projector machine
 *
 * Each answer from /api/worship/advance carries the replacement
 * anti-forgery token, which the script below keeps (#625). Without that
 * every control on this page worked exactly once. The commands are also sent
 * ONE AT A TIME, in the order pressed, because a command only learns the
 * replacement token from its own answer: two sent at once would share a
 * token and the second would be refused. A command the portal refuses is
 * SAID, never swallowed.
 *
 * Polling: the JS on this page POSTs to /api/worship/advance and refreshes
 * the local view immediately + re-polls /api/worship/state every 1.5s in
 * case another operator console is active in parallel.
 *
 * @package   Portal\Worship
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/308
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$planId = (int) ($_GET['planID'] ?? 0);
if ($planId <= 0) { http_response_code(400); exit('Missing planID.'); }

$stmt = $mysqli->prepare(
    'SELECT planID, name, eventID, displayToken FROM tblServicePlans '
    . 'WHERE planID = ? AND siteID = ? AND isActive = 1'
);
$stmt->bind_param('ii', $planId, $siteId);
$stmt->execute();
$plan = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();
if ($plan === null) { http_response_code(404); exit('Plan not found.'); }

// 🛡️ Write gate — admin OR event coordinator.
$canOperate = App::isAdmin() === true
           || ((int) ($plan['eventID'] ?? 0) > 0 && Auth::isCoordinatorOf((int) $plan['eventID']));
if ($canOperate === false) { http_response_code(403); exit('Forbidden'); }

// 🎟️ Lazy-mint display token if absent.
$displayToken = (string) ($plan['displayToken'] ?? '');
if ($displayToken === '' || preg_match('/^[a-f0-9]{64}$/', $displayToken) !== 1) {
    $displayToken = bin2hex(random_bytes(32));
    $stmt = $mysqli->prepare('UPDATE tblServicePlans SET displayToken = ? WHERE planID = ?');
    $stmt->bind_param('si', $displayToken, $planId);
    $stmt->execute();
    $stmt->close();
}

// 📋 Load all items (sorted) so the operator can see what's coming next.
$items = [];
$stmt = $mysqli->prepare(
    'SELECT i.itemID, i.sortOrder, i.itemType, i.songID, i.slideTitle, i.slideBody, i.slideNotes, '
    . '       s.title AS songTitle, s.lyrics AS songLyrics, s.defaultKey '
    . 'FROM tblServicePlanItems i LEFT JOIN tblSongs s ON s.songID = i.songID '
    . 'WHERE i.planID = ? ORDER BY i.sortOrder, i.itemID'
);
$stmt->bind_param('i', $planId);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) { $items[] = $r; }
$stmt->close();

// 📋 Current state — may not exist yet.
$state = ['currentItemID' => null, 'isBlank' => 0, 'isBlack' => 0];
$stmt = $mysqli->prepare('SELECT currentItemID, isBlank, isBlack FROM tblServicePlanState WHERE planID = ?');
$stmt->bind_param('i', $planId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if ($row !== null) { $state = $row; }
$stmt->close();

$currentIdx = -1;
foreach ($items as $idx => $i) {
    if ((int) $i['itemID'] === (int) ($state['currentItemID'] ?? 0)) { $currentIdx = $idx; break; }
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$displayUrl = $scheme . '://' . $host . '/worship/display?t=' . $displayToken;

$pageTitle = 'Operate — ' . (string) $plan['name'];
$csrf      = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container-fluid py-2">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h1 class="h5 mb-0"><i class="fa-solid fa-tv me-1 text-primary"></i><?php echo htmlspecialchars((string) $plan['name'], ENT_QUOTES, 'UTF-8'); ?></h1>
        <div class="small text-muted">
            Display URL:
            <code class="bg-light p-1"><?php echo htmlspecialchars($displayUrl, ENT_QUOTES, 'UTF-8'); ?></code>
            <button type="button" class="btn btn-sm btn-link p-0 ms-1" id="copyDisplayUrl" data-copy-text="<?php echo htmlspecialchars($displayUrl, ENT_QUOTES, 'UTF-8'); ?>"><i class="fa-solid fa-copy"></i></button>
        </div>
    </div>

    <div class="row g-2">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>Current slide <span class="badge bg-secondary ms-1" id="currentPos"><?php echo $currentIdx >= 0 ? ($currentIdx + 1) : '–'; ?>/<?php echo count($items); ?></span></strong>
                    <span id="currentBadge"></span>
                    <!-- A command that the portal refused is said here. It used
                         to be silent, which is the whole fault of #625: a
                         button that does nothing and says nothing. -->
                    <span id="cmdProblem" class="badge bg-warning text-dark ms-2" hidden></span>
                </div>
                <div class="card-body" style="min-height:320px;">
                    <div id="currentSlidePreview" style="font-size:1.4em; white-space:pre-wrap; font-family:Georgia,serif;"></div>
                </div>
                <div class="card-footer d-flex gap-2 flex-wrap">
                    <button class="btn btn-lg btn-outline-secondary flex-grow-1" data-cmd="prev"><i class="fa-solid fa-backward me-1"></i>Prev</button>
                    <button class="btn btn-lg btn-primary flex-grow-1" data-cmd="next">Next<i class="fa-solid fa-forward ms-1"></i></button>
                    <button class="btn btn-lg btn-outline-warning" data-cmd="blank"><i class="fa-solid fa-image"></i> Blank</button>
                    <button class="btn btn-lg btn-outline-dark" data-cmd="black"><i class="fa-solid fa-square"></i> Black</button>
                    <button class="btn btn-lg btn-outline-success" data-cmd="show"><i class="fa-solid fa-eye"></i> Show</button>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><strong>Up next</strong></div>
                <div class="card-body small" id="nextSlidePreview" style="min-height:220px; white-space:pre-wrap;">—</div>
            </div>
            <div class="card mt-2">
                <div class="card-header"><strong>All slides</strong></div>
                <div class="card-body p-0">
                    <ol class="list-group list-group-flush" id="slideList">
                    <?php foreach ($items as $idx => $i):
                        $label = match ((string) $i['itemType']) {
                            'song'  => (string) ($i['songTitle'] ?? '(song deleted)'),
                            'verse' => (string) ($i['slideTitle'] ?? '(verse)'),
                            default => (string) ($i['slideTitle'] ?? '(text slide)'),
                        };
                    ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center small <?php echo $idx === $currentIdx ? 'active' : ''; ?>" data-itemid="<?php echo (int) $i['itemID']; ?>">
                            <span><?php echo $idx + 1; ?>. <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
                            <button class="btn btn-sm btn-outline-secondary" data-cmd="goto" data-itemid="<?php echo (int) $i['itemID']; ?>" title="Jump here"><i class="fa-solid fa-play"></i></button>
                        </li>
                    <?php endforeach; ?>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</div>

<script nonce="<?php echo htmlspecialchars(\Portal\Core\App::cspNonce(), ENT_QUOTES, 'UTF-8'); ?>">
(function () {
    'use strict';
    // 🔄 NOT const: the portal REPLACES the anti-forgery token every time it
    //    accepts one, so this console has to keep the replacement that comes
    //    back with each answer. It used to be a const, and every control here
    //    therefore worked exactly ONCE: the second command sent a token the
    //    portal had already thrown away, was answered 400 "csrf", and the page
    //    said nothing at all (#625). Do not make it const again.
    let csrf = <?php echo json_encode($csrf); ?>;
    const planID = <?php echo (int) $planId; ?>;
    const token = <?php echo json_encode($displayToken); ?>;
    const items = <?php echo json_encode(array_map(static function ($i) {
        $label = match ((string) $i['itemType']) {
            'song'  => (string) ($i['songTitle'] ?? '(song deleted)'),
            'verse' => (string) ($i['slideTitle'] ?? '(verse)'),
            default => (string) ($i['slideTitle'] ?? '(text slide)'),
        };
        $body = (string) $i['itemType'] === 'song'
            ? (string) ($i['songLyrics'] ?? '')
            : (string) ($i['slideBody'] ?? '');
        return [
            'itemID'    => (int) $i['itemID'],
            'itemType'  => (string) $i['itemType'],
            'label'     => $label,
            'body'      => $body,
            'songKey'   => (string) ($i['defaultKey'] ?? ''),
            'notes'     => (string) ($i['slideNotes'] ?? ''),
        ];
    }, $items)); ?>;

    const previewEl   = document.getElementById('currentSlidePreview');
    const nextEl      = document.getElementById('nextSlidePreview');
    const posEl       = document.getElementById('currentPos');
    const badgeEl     = document.getElementById('currentBadge');
    const slideList   = document.getElementById('slideList');

    function renderCurrent(state) {
        const idx = items.findIndex(i => i.itemID === state.currentItemID);
        posEl.textContent = (idx >= 0 ? (idx + 1) : '–') + '/' + items.length;

        if (state.isBlack) {
            previewEl.style.background = '#000'; previewEl.style.color = '#000';
            previewEl.textContent = '';
            badgeEl.innerHTML = '<span class="badge bg-dark">BLACK</span>';
        } else if (state.isBlank) {
            previewEl.style.background = '#f8f9fa'; previewEl.style.color = '#aaa';
            previewEl.textContent = '(blank — logo or wallpaper showing on projector)';
            badgeEl.innerHTML = '<span class="badge bg-warning text-dark">BLANK</span>';
        } else if (idx >= 0) {
            previewEl.style.background = ''; previewEl.style.color = '';
            const item = items[idx];
            previewEl.textContent = item.body || '(no body)';
            badgeEl.innerHTML = item.songKey
                ? '<span class="badge bg-info text-dark">Key ' + item.songKey + '</span>'
                : '';
        } else {
            previewEl.style.background = ''; previewEl.style.color = '#888';
            previewEl.textContent = '(no slide selected — press Next)';
            badgeEl.innerHTML = '';
        }

        // Up-next preview
        if (idx >= 0 && idx + 1 < items.length) {
            nextEl.textContent = items[idx + 1].label + '\n' + (items[idx + 1].body || '').slice(0, 200);
        } else {
            nextEl.textContent = '(end of plan)';
        }

        // Highlight active row
        slideList.querySelectorAll('li').forEach(li => {
            li.classList.toggle('active', parseInt(li.dataset.itemid, 10) === state.currentItemID);
        });
    }

    // 🔁 The view refreshes itself every second and a half. It runs on its own
    //    and never joins the command queue, so a slow poll can never hold a
    //    command up directly -- but it is given the same deadline, and only
    //    one is allowed in flight at a time, for two reasons found while
    //    fixing the commands:
    //      1. On setInterval, polls that never answer PILE UP: measured, the
    //         old shape queued them in the browser and then delivered six in
    //         a burst once the first one gave up. OVER HTTP/1.1 a browser
    //         allows only a handful of connections to one site at once, so
    //         enough stalled polls starve the commands after all -- measured
    //         too: ten stalled requests kept three that would have answered
    //         in milliseconds waiting 125 seconds. Over HTTP/2, which a
    //         customer's HTTPS host probably uses, that route closes, so
    //         reason 2 is what really carries this change.
    //      2. This poll is what made the wedged queue so hard to see: the
    //         slides kept moving, so the page looked alive while every press
    //         was being swallowed. A poll that has stopped answering should
    //         not go on pretending.
    //    A poll that fails is still silent, deliberately: it says nothing an
    //    operator must act on, and the commands have their own words.
    let polling = false;
    async function poll() {
        if (polling === true) return;
        polling = true;
        try {
            const s = await withDeadline(
                '<?php echo Router::urlForScript('api/worship/state'); ?>?t=' + encodeURIComponent(token),
                { cache: 'no-store' },
                r => r.json()
            );
            if (s && s.ok !== false) renderCurrent(s);
        } catch (e) {
            /* a blip, or the deadline — keep polling, say nothing */
        } finally {
            polling = false;
        }
    }

    // 🗣️ Say when a command did not go through. Silence is the fault this
    //    whole package exists to remove, so a refusal must never be invisible.
    const problemBox = document.getElementById('cmdProblem');
    let problemTimer = null;
    function sayProblem(words) {
        if (problemBox === null) return;
        problemBox.textContent = words;
        problemBox.hidden = false;
        if (problemTimer !== null) clearTimeout(problemTimer);
        problemTimer = setTimeout(() => { problemBox.hidden = true; }, 6000);
    }
    function clearProblem() {
        if (problemBox !== null) problemBox.hidden = true;
    }

    // 🔒 ONE COMMAND AT A TIME, in the order they were pressed.
    //    The portal replaces the anti-forgery token every time it accepts
    //    one, and a command only learns the replacement from its OWN answer.
    //    So two commands sent before the first answer came back both carried
    //    the same token: the first was accepted, the second refused 400
    //    "csrf", and the console moved one slide for two presses -- silently.
    //    An operator pressing Next twice quickly is the ordinary case, not a
    //    strange one, and it was found in a real browser by the independent
    //    check of this package. A busy flag that DROPPED the second press
    //    was rejected: the operator meant both, and losing one is the same
    //    complaint in a different shape. Each command therefore waits for the
    //    one before it and then sends with the token that command returned.
    let chain = Promise.resolve();
    function cmd(action, extra = {}) {
        chain = chain.then(() => sendOne(action, extra)).catch(() => {});
        return chain;
    }

    // ⏳ EVERY REQUEST HAS A DEADLINE. Ten seconds is far longer than this
    //    handler ever needs and short enough that an operator presses again
    //    rather than standing there.
    //    WHY IT MATTERS MORE HERE THAN IT LOOKS. A fetch that is REFUSED
    //    rejects, and the catch below says so. A fetch that simply never
    //    answers never rejects at all -- and because the commands are now
    //    sent one at a time, everything pressed after it waits behind it for
    //    ever, with the slide view still refreshing so the page looks alive.
    //    Before they were serialised a hang cost ONE press; serialising made
    //    it cost every press from then on, silently. That was found in a real
    //    browser by the independent check of this fix, and it is exactly the
    //    fault this package exists to remove, so the deadline is not a
    //    nicety. With it, a hang becomes a rejection and falls into the path
    //    below, which already works.
    const DEADLINE_MS = 10000;

    // THE DEADLINE COVERS READING THE ANSWER, NOT JUST WAITING FOR IT.
    //   The first version cleared the timer in `.finally()` on the fetch
    //   promise -- and that promise settles as soon as the HEADERS arrive, so
    //   reading the body sat outside the deadline. A server that sent headers
    //   and then stalled its body was measured taking the full 25 seconds
    //   without aborting, and with a permanent stall the queue wedged exactly
    //   as it did before the deadline existed. The comment here used to say "a
    //   hang becomes a rejection", which was therefore not true. Found by the
    //   independent check of the second fix round.
    //   So the controller is held across the body read and the timer is
    //   cleared only after it, which aborts a stalled body at the deadline and
    //   still lets a slow-but-good answer through (measured: 0, 1, 3, 6 and 9
    //   seconds all succeed; 14 seconds aborts at 10.0).
    //   The body is read by the caller's own function, because one caller
    //   wants JSON and the other the page's text.
    async function withDeadline(url, options, readBody) {
        const stop = new AbortController();
        const timer = setTimeout(() => stop.abort(), DEADLINE_MS);
        try {
            const r = await fetch(url, Object.assign({}, options, { signal: stop.signal }));
            return await readBody(r);
        } finally {
            clearTimeout(timer);
        }
    }

    async function sendOne(action, extra = {}, isRetry = false) {
        const body = new URLSearchParams(Object.assign({
            csrf_token: csrf, planID: planID, action: action,
        }, extra));
        let s;
        try {
            s = await withDeadline(
                '<?php echo Router::urlForScript('api/worship/advance'); ?>',
                { method: 'POST', body: body },
                r => r.json()
            );
        } catch (e) {
            // The network or the deadline, not the portal's answer. The words
            // used to say "Not sent", which can be UNTRUE: the portal may have
            // carried the command out and failed only to tell us. So they now
            // say exactly what is known, which is nothing either way. The
            // slide view refreshes on its own every second and a half, so the
            // operator can see which it was. Nothing here guesses at the new
            // state, and the next press works because the chain carries on.
            sayProblem('No answer from the portal — this may or may not have gone through. Check the slide, then try again.');
            return;
        }
        // 🔄 Keep the replacement token BEFORE looking at whether the command
        //    worked. A refusal that the portal reached after its own check
        //    (a plan that has gone, no permission, an action it does not
        //    allow) has already rotated the token and hands the new one back,
        //    so one mistaken press must not stop the console for the rest of
        //    the service.
        if (s && typeof s.csrfToken === 'string' && s.csrfToken !== '') { csrf = s.csrfToken; }
        if (s && s.ok) { clearProblem(); renderCurrent(s); return; }

        // A stale token can still happen -- another tab or window driving the
        // same plan takes the token from under this one. That answer carries
        // no replacement (nothing was rotated), so ask the portal for the
        // state, which refreshes nothing but tells us we are behind, and send
        // this one command again. ONCE only: a second failure is a real
        // refusal and is said out loud rather than retried for ever.
        if (s && s.error === 'csrf' && isRetry === false) {
            await refreshToken();
            return sendOne(action, extra, true);
        }
        sayProblem(wordsFor(s));
    }

    // The page's own token, read again from the portal. Used only after a
    // refusal, so a second window cannot leave this console stuck.
    //
    // IT READS THE PORTAL'S OWN PUBLISHED PLACE, the <meta name="csrf-token">
    // that templates/header.php writes on every standard page and that
    // portal.js already reads (getMetaCsrf()). The first version of this
    // searched the returned HTML for `csrf = "<64 hex>"` instead, which
    // matched TWO places on the real page -- this console's own variable and
    // the cookie banner's, written by footer.php. Both hold the same value
    // today, so it happened to pick the right one by being first in the page:
    // right by accident, not by design, and the sort of thing that breaks
    // when somebody reorders a template. Found by the independent check of
    // this fix round.
    async function refreshToken() {
        try {
            const text = await withDeadline(
                window.location.href,
                { cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' } },
                r => r.text()
            );
            const found = text.match(/<meta\s+name="csrf-token"\s+content="([0-9a-f]{64})"/i);
            if (found !== null) csrf = found[1];
        } catch (e) { /* nothing to do; the caller says the command failed */ }
    }

    function wordsFor(s) {
        const why = (s && typeof s.error === 'string') ? s.error : '';
        if (why === 'csrf')      return 'Not sent — this page went stale. Reload it.';
        if (why === 'forbidden') return 'Not sent — you may not operate this plan.';
        if (why === 'plan')      return 'Not sent — this plan is no longer there.';
        if (why === 'item')      return 'Not sent — that slide is no longer there.';
        if (why === 'auth')      return 'Not sent — you have been signed out. Sign in again.';
        return 'Not sent — the portal refused the command.';
    }

    document.querySelectorAll('[data-cmd]').forEach(btn => {
        btn.addEventListener('click', () => {
            const c = btn.dataset.cmd;
            if (c === 'goto') cmd('goto', { itemID: btn.dataset.itemid });
            else cmd(c);
        });
    });

    // Keyboard: arrow keys / space = next, backspace = prev, B = black, W = blank, S = show
    document.addEventListener('keydown', e => {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
        if (e.code === 'ArrowRight' || e.code === 'Space' || e.code === 'PageDown') { e.preventDefault(); cmd('next'); }
        else if (e.code === 'ArrowLeft' || e.code === 'Backspace' || e.code === 'PageUp') { e.preventDefault(); cmd('prev'); }
        else if (e.key.toLowerCase() === 'b') cmd('black');
        else if (e.key.toLowerCase() === 'w') cmd('blank');
        else if (e.key.toLowerCase() === 's') cmd('show');
    });

    // 📋 Copy the display address. This was onclick="..." written on the
    // button, with the address printed into the handler's own script text.
    // Browsers refuse a handler written on an element of a page with the
    // standard header (#144, 3 June 2026; #625), so the button did nothing.
    // The address is now in the button's data-copy-text attribute and is read
    // as data. As before, nothing is shown when the copy works or fails, and
    // a browser with no clipboard (a page not on https) copies nothing.
    const copyBtn = document.getElementById('copyDisplayUrl');
    if (copyBtn !== null) {
        copyBtn.addEventListener('click', () => {
            if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                navigator.clipboard.writeText(copyBtn.getAttribute('data-copy-text') || '').catch(() => {});
            }
        });
    }

    // Initial render + poll loop
    renderCurrent({
        currentItemID: <?php echo $state['currentItemID'] !== null ? (int) $state['currentItemID'] : 'null'; ?>,
        isBlank: <?php echo (int) $state['isBlank']; ?> === 1,
        isBlack: <?php echo (int) $state['isBlack']; ?> === 1,
    });
    setInterval(poll, 1500);
})();
</script>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
