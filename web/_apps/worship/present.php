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
 * Polling: the JS on this page POSTs to /api/worship/advance and refreshes
 * the local view immediately + re-polls /api/worship/state every 1s in
 * case another operator console is active in parallel.
 *
 * @package   Portal\Worship
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/308
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
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
            <button type="button" class="btn btn-sm btn-link p-0 ms-1" onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($displayUrl, ENT_QUOTES, 'UTF-8'); ?>')"><i class="fa-solid fa-copy"></i></button>
        </div>
    </div>

    <div class="row g-2">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>Current slide <span class="badge bg-secondary ms-1" id="currentPos"><?php echo $currentIdx >= 0 ? ($currentIdx + 1) : '–'; ?>/<?php echo count($items); ?></span></strong>
                    <span id="currentBadge"></span>
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

<script>
(function () {
    'use strict';
    const csrf  = <?php echo json_encode($csrf); ?>;
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

    async function poll() {
        try {
            const r = await fetch('/api/worship/state?t=' + encodeURIComponent(token), { cache: 'no-store' });
            const s = await r.json();
            if (s && s.ok !== false) renderCurrent(s);
        } catch (e) { /* network blip — keep polling */ }
    }

    async function cmd(action, extra = {}) {
        const body = new URLSearchParams(Object.assign({
            csrf_token: csrf, planID: planID, action: action,
        }, extra));
        const r = await fetch('/api/worship/advance', { method: 'POST', body: body });
        const s = await r.json();
        if (s && s.ok) renderCurrent(s);
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
