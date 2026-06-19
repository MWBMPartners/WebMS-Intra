<?php
// Path: _apps/worship/plan.php
/**
 * -----------------------------------------------------------------------------
 * Worship — Service Plan view/edit 🎶 (#308 Phase 1)
 * -----------------------------------------------------------------------------
 * Single-plan editor. Plan-level metadata (name, notes, optional event
 * binding, archive toggle) + ordered item list with add/remove/reorder
 * (forms only in Phase 1; SortableJS drag-and-drop is Phase 2 polish).
 *
 * Write ACL: admin OR Auth::isCoordinatorOf(planEventID).
 *   • Free-floating template plans (eventID NULL) are admin-only.
 *   • Event-bound plans are editable by the event's coordinator.
 *
 * Read ACL: any logged-in user.
 *
 * @package   Portal\Worship
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/308
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Asset;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$isNew  = ($_GET['new'] ?? '') === '1';
$planId = (int) ($_GET['id'] ?? 0);

$plan  = null;
$items = [];

if ($isNew === true) {
    $plan = [
        'planID' => 0, 'name' => '', 'notes' => '',
        'isActive' => 1, 'eventID' => null, 'eventName' => null,
    ];
} elseif ($planId > 0) {
    $stmt = $mysqli->prepare(
        'SELECT p.planID, p.name, p.notes, p.isActive, p.eventID, e.eventName '
        . 'FROM tblServicePlans p '
        . 'LEFT JOIN tblEvents e ON e.eventID = p.eventID '
        . 'WHERE p.planID = ? AND p.siteID = ?'
    );
    $stmt->bind_param('ii', $planId, $siteId);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if ($plan !== null) {
        $stmt = $mysqli->prepare(
            'SELECT i.itemID, i.sortOrder, i.itemType, i.songID, i.slideTitle, i.slideBody, i.slideNotes, s.title AS songTitle '
            . 'FROM tblServicePlanItems i LEFT JOIN tblSongs s ON s.songID = i.songID '
            . 'WHERE i.planID = ? ORDER BY i.sortOrder, i.itemID'
        );
        $stmt->bind_param('i', $planId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($r = $result->fetch_assoc()) { $items[] = $r; }
        $stmt->close();
    }
}
if ($plan === null) { http_response_code(404); exit('Plan not found.'); }

// 🛡️ Write ACL — admin OR coordinator of the bound event.
$canWrite = App::isAdmin() === true;
if ($canWrite === false && !empty($plan['eventID'])) {
    $canWrite = Auth::isCoordinatorOf((int) $plan['eventID']);
}
// New plans: only admins create free-floating templates. Coordinator-created
// event-bound plans flow through the save handler which enforces the event
// gate at insert time.
if ($isNew === true && App::isAdmin() === false) {
    // Allow coordinator to create a plan IF they later bind it to an event
    // they coordinate. Save handler re-checks. Read-side: render the form.
    $canWrite = true;
}

// 🎵 Song pool for the inline "Add song slide" dropdown.
$songs = [];
$stmt = $mysqli->prepare(
    'SELECT songID, title FROM tblSongs WHERE siteID = ? AND isActive = 1 ORDER BY title LIMIT 200'
);
$stmt->bind_param('i', $siteId);
$stmt->execute();
$result = $stmt->get_result();
while ($r = $result->fetch_assoc()) { $songs[] = $r; }
$stmt->close();

$pageTitle = $isNew === true ? 'New service plan' : (string) $plan['name'];
$csrf      = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:880px;">
    <h1 class="h4 mb-3"><i class="fa-solid fa-music me-2 text-primary"></i><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></h1>

    <?php if ($canWrite === false): ?>
        <div class="alert alert-info small">Read-only view. To edit this plan, you must be an admin or a coordinator of its bound event.</div>
    <?php endif; ?>

    <?php if ($canWrite === true): ?>
        <form method="post" action="/worship/plan/save" class="mb-4">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="planID" value="<?php echo (int) $plan['planID']; ?>">
            <input type="hidden" name="action" value="save-metadata">
            <div class="row g-2">
                <div class="col-md-6">
                    <label class="form-label small">Plan name <span class="text-danger">*</span></label>
                    <input type="text" name="name" value="<?php echo htmlspecialchars((string) $plan['name'], ENT_QUOTES, 'UTF-8'); ?>" required maxlength="120" class="form-control form-control-sm">
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Bind to event ID (optional)</label>
                    <input type="number" name="eventID" value="<?php echo $plan['eventID'] !== null ? (int) $plan['eventID'] : ''; ?>" min="0" class="form-control form-control-sm" placeholder="Leave blank for template">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <div class="form-check form-switch">
                        <input type="checkbox" id="isActive" name="isActive" value="1" class="form-check-input" <?php echo (int) $plan['isActive'] === 1 ? 'checked' : ''; ?>>
                        <label for="isActive" class="form-check-label small">Active</label>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label small">Operator notes (not projected)</label>
                    <textarea name="notes" rows="2" maxlength="1000" class="form-control form-control-sm"><?php echo htmlspecialchars((string) ($plan['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary btn-sm"><i class="fa-solid fa-save me-1"></i>Save plan</button>
                    <a href="/worship/plans" class="btn btn-link btn-sm">Back to list</a>
                </div>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($isNew !== true): ?>
        <h2 class="h6 mt-4">Slide items (<?php echo count($items); ?>)</h2>
        <?php if ($canWrite === true && count($items) > 1): ?>
            <p class="small text-muted"><i class="fa-solid fa-arrows-up-down me-1"></i>Drag rows to reorder, or use the arrow buttons. Order saves automatically.</p>
        <?php endif; ?>
        <?php if (count($items) === 0): ?>
            <p class="text-muted small">No slides yet.</p>
        <?php else: ?>
            <div class="portal-data-list mb-3" id="planItemList" data-plan-id="<?php echo (int) $plan['planID']; ?>">
            <?php foreach ($items as $i): ?>
                <div class="portal-data-row" data-item-id="<?php echo (int) $i['itemID']; ?>"<?php if ($canWrite === true): ?> style="cursor: grab;"<?php endif; ?>>
                    <div class="portal-data-row-main">
                        <span class="badge bg-secondary me-1"><?php echo (int) $i['sortOrder']; ?></span>
                        <strong><?php
                            switch ((string) $i['itemType']) {
                                case 'song':
                                    echo '<i class="fa-solid fa-music me-1"></i>' . htmlspecialchars((string) ($i['songTitle'] ?? '(song deleted)'), ENT_QUOTES, 'UTF-8');
                                    break;
                                case 'verse':
                                    echo '<i class="fa-solid fa-book-bible me-1"></i>' . htmlspecialchars((string) ($i['slideTitle'] ?? '(verse)'), ENT_QUOTES, 'UTF-8');
                                    break;
                                default:
                                    echo '<i class="fa-solid fa-align-left me-1"></i>' . htmlspecialchars((string) ($i['slideTitle'] ?? '(text slide)'), ENT_QUOTES, 'UTF-8');
                            }
                        ?></strong>
                        <?php if (!empty($i['slideBody']) && (string) $i['itemType'] !== 'song'): ?>
                            <div class="small text-muted"><?php echo htmlspecialchars(mb_substr((string) $i['slideBody'], 0, 120), ENT_QUOTES, 'UTF-8'); ?><?php echo mb_strlen((string) $i['slideBody']) > 120 ? '…' : ''; ?></div>
                        <?php endif; ?>
                        <?php if (!empty($i['slideNotes'])): ?>
                            <div class="small text-warning"><i class="fa-solid fa-eye-slash me-1"></i><?php echo htmlspecialchars((string) $i['slideNotes'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php if ($canWrite === true): ?>
                        <div class="portal-data-row-aside">
                            <form method="post" action="/worship/plan/save" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <input type="hidden" name="planID" value="<?php echo (int) $plan['planID']; ?>">
                                <input type="hidden" name="action" value="move-up">
                                <input type="hidden" name="itemID" value="<?php echo (int) $i['itemID']; ?>">
                                <button class="btn btn-sm btn-outline-secondary" title="Move up"><i class="fa-solid fa-arrow-up"></i></button>
                            </form>
                            <form method="post" action="/worship/plan/save" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <input type="hidden" name="planID" value="<?php echo (int) $plan['planID']; ?>">
                                <input type="hidden" name="action" value="move-down">
                                <input type="hidden" name="itemID" value="<?php echo (int) $i['itemID']; ?>">
                                <button class="btn btn-sm btn-outline-secondary" title="Move down"><i class="fa-solid fa-arrow-down"></i></button>
                            </form>
                            <form method="post" action="/worship/plan/save" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <input type="hidden" name="planID" value="<?php echo (int) $plan['planID']; ?>">
                                <input type="hidden" name="action" value="remove-item">
                                <input type="hidden" name="itemID" value="<?php echo (int) $i['itemID']; ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Remove" onclick="return confirm('Remove this slide?');"><i class="fa-solid fa-xmark"></i></button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($canWrite === true): ?>
            <h2 class="h6 mt-4">Add slide</h2>
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <h3 class="h6"><i class="fa-solid fa-music me-1"></i>Song</h3>
                            <form method="post" action="/worship/plan/save">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <input type="hidden" name="planID" value="<?php echo (int) $plan['planID']; ?>">
                                <input type="hidden" name="action" value="add-song">
                                <select name="songID" required class="form-select form-select-sm mb-2">
                                    <option value="">Pick a song…</option>
                                    <?php foreach ($songs as $s): ?>
                                        <option value="<?php echo (int) $s['songID']; ?>"><?php echo htmlspecialchars((string) $s['title'], ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-plus me-1"></i>Add</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <h3 class="h6"><i class="fa-solid fa-align-left me-1"></i>Text slide</h3>
                            <form method="post" action="/worship/plan/save">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <input type="hidden" name="planID" value="<?php echo (int) $plan['planID']; ?>">
                                <input type="hidden" name="action" value="add-text">
                                <input type="text" name="slideTitle" maxlength="255" placeholder="Heading (optional)" class="form-control form-control-sm mb-1">
                                <textarea name="slideBody" rows="3" placeholder="Body text" required class="form-control form-control-sm mb-2"></textarea>
                                <button class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-plus me-1"></i>Add</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <h3 class="h6"><i class="fa-solid fa-book-bible me-1"></i>Verse</h3>
                            <form method="post" action="/worship/plan/save">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <input type="hidden" name="planID" value="<?php echo (int) $plan['planID']; ?>">
                                <input type="hidden" name="action" value="add-verse">
                                <input type="text" name="slideTitle" maxlength="255" placeholder="Reference (e.g. John 3:16)" required class="form-control form-control-sm mb-1">
                                <textarea name="slideBody" rows="3" placeholder="Passage" required class="form-control form-control-sm mb-2"></textarea>
                                <button class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-plus me-1"></i>Add</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <p class="text-muted small">Save the plan first; you can add slide items once it has an ID.</p>
    <?php endif; ?>
</div>
<?php
// 🔀 SortableJS drag-reorder (#308 Phase 3) — Asset helper supplies the
//    CDN URL + SRI integrity. Degrades gracefully: if the script fails
//    to load, the up/down buttons still work.
if ($isNew !== true && $canWrite === true && count($items) > 1) {
    echo Asset::sortableJs();
    ?>
<script>
(function () {
    'use strict';
    if (typeof Sortable === 'undefined') return;
    const list = document.getElementById('planItemList');
    if (!list) return;
    const planID = list.dataset.planId;
    const csrf   = <?php echo json_encode($csrf); ?>;

    Sortable.create(list, {
        animation: 150,
        ghostClass: 'bg-light',
        handle: '.portal-data-row-main',
        onEnd: async function () {
            const rows = list.querySelectorAll('.portal-data-row[data-item-id]');
            const body = new URLSearchParams();
            body.append('csrf_token', csrf);
            body.append('planID', planID);
            rows.forEach(r => body.append('items[]', r.dataset.itemId));
            try {
                const res = await fetch('/worship/plan/reorder', { method: 'POST', body: body });
                const j = await res.json();
                if (!j || !j.ok) console.warn('Reorder rejected:', j);
                // Update visible sort numbers without a full reload.
                rows.forEach((r, idx) => {
                    const badge = r.querySelector('.badge.bg-secondary');
                    if (badge) badge.textContent = String(idx + 1);
                });
            } catch (e) { console.error('Reorder error:', e); }
        }
    });
})();
</script>
    <?php
}
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
?>
