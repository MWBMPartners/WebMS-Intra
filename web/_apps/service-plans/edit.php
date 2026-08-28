<?php
// Path: public_html/service-plans/edit.php
/**
 * Service Plans — edit plan metadata + items list with reorder/edit/delete.
 *
 * @package   Portal\ServicePlans
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/262
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\AppRegistry;
use Portal\Core\Auth;
use Portal\Core\Markdown;
use Portal\Core\ServicePlanLink;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$siteId = Site::id();
$id     = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: /service-plans');
    exit();
}

$plan = null;
$stmt = $db->prepare('SELECT * FROM tblServicePlan WHERE planID = ? AND siteID = ? LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('ii', $id, $siteId);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($plan === null) {
    http_response_code(404);
    exit('Plan not found');
}

// -----------------------------------------------------------------------------
// 🎶 Gap #6 bridge (#442) — paired worship (presentation) plan summary, if
// any. Guarded exactly like the venue-overlay resilience precedent
// (calendar/index.php): AppRegistry::isEnabled() short-circuit + try/catch,
// so a disabled worship app, a not-yet-migrated column, or ANY resolver
// exception leaves the panel empty and this page renders unchanged.
// -----------------------------------------------------------------------------
$worshipLink       = null;
$worshipCandidates = [];
if (AppRegistry::isEnabled('worship') === true) {
    try {
        $worshipLink = ServicePlanLink::worshipPlanForRunSheet($id, $siteId);
        if ($worshipLink === null && App::isAdmin() === true) {
            $worshipCandidates = ServicePlanLink::candidatesForRunSheet($siteId);
        }
    } catch (\Throwable $e) {
        error_log('Service-plan worship panel failed: ' . $e->getMessage());
        $worshipLink       = null;
        $worshipCandidates = [];
    }
}

$items = [];
$stmt = $db->prepare(
    'SELECT i.itemID, i.sectionType, i.position, i.title, i.songID, i.presenterID, i.presenterText, '
    . '       i.durationMin, i.notes, u.fullName AS presenterName '
    . 'FROM tblServicePlanItem i LEFT JOIN tblUsers u ON u.userID = i.presenterID '
    . 'WHERE i.planID = ? ORDER BY i.position, i.itemID'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $items[] = $r;
    }
    $stmt->close();
}

// 🛡️ Site-scoped presenter picker (security review) — the previous
// unscoped query listed every active user across every tenant on this
// install, leaking cross-site membership. Only users active on THIS site
// belong in the dropdown.
$users = [];
$stmt = $db->prepare(
    'SELECT u.userID, u.fullName FROM tblUsers u '
    . 'JOIN tblUserSites us ON us.userID = u.userID '
    . 'WHERE us.siteID = ? AND us.isActive = 1 AND u.isActive = 1 '
    . 'ORDER BY u.fullName'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $users[] = $r;
    }
    $stmt->close();
}

$pageTitle   = (string) $plan['title'];
$pageSection = 'service-plans';
$breadcrumbs = ['Dashboard' => '/', 'Service Plans' => '/service-plans', (string) $plan['title'] => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();

// 🚩 Flash (e.g. gap #6 pair/unpair refusals redirected back here).
$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

// -----------------------------------------------------------------------------
// 🔗 Gap #128 residual — public Order-of-Service share panel state. Site-
// level kill-switch read from the normal bootstrap $SETTINGS snapshot (this
// is an authenticated, in-app page for THIS site — unlike public.php, which
// must never trust that snapshot for a token belonging to a different site).
// -----------------------------------------------------------------------------
$publicShareSiteOn = (string) (App::settings('service_plans.public_share.enabled') ?? 'false') === 'true';
$isPlanShared      = (int) ($plan['isPublicShared'] ?? 0) === 1;
$publicToken       = (string) ($plan['publicToken'] ?? '');
// 🔗 Absolute-URL convention (invites/save.php precedent) — site.url with a
// scheme+host fallback, since a QR code needs an absolute address.
$siteBaseUrl = rtrim((string) (App::settings('site.url') ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? ''))), '/');
$publicUrl   = $publicToken !== '' ? ($siteBaseUrl . '/os/' . $publicToken) : '';

$sectionTypes = [
    'greeting'       => 'Greeting / Welcome',
    'song'           => 'Song / Hymn',
    'prayer'         => 'Prayer',
    'scripture'      => 'Scripture',
    'sermon'         => 'Sermon',
    'offering'       => 'Offering',
    'communion'      => 'Communion',
    'special_music'  => 'Special music',
    'announcement'   => 'Announcement',
    'reading'        => 'Reading',
    'other'          => 'Other',
];
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><?php echo htmlspecialchars((string) $plan['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="text-secondary mb-0">
            <?php echo htmlspecialchars(date('l j F Y', strtotime((string) $plan['serviceDate'])), ENT_QUOTES, 'UTF-8'); ?>
            &middot; status: <strong><?php echo htmlspecialchars((string) $plan['status'], ENT_QUOTES, 'UTF-8'); ?></strong>
        </p>
    </div>
    <div>
        <a href="/service-plans/print?id=<?php echo $id; ?>&version=leader" target="_blank" class="btn btn-outline-secondary btn-sm">
            <i class="fa-solid fa-print me-1"></i>Print (leader)
        </a>
        <a href="/service-plans/print?id=<?php echo $id; ?>&version=congregation" target="_blank" class="btn btn-outline-secondary btn-sm">
            <i class="fa-solid fa-print me-1"></i>Print (congregation)
        </a>
        <a href="/service-plans" class="btn btn-outline-secondary btn-sm">&larr; Back</a>
    </div>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<!-- Plan metadata -->
<div class="card mb-3">
    <div class="card-body">
        <form method="post" action="/service-plans/save" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="planID" value="<?php echo $id; ?>">
            <div class="col-md-5">
                <label class="form-label small">Title</label>
                <input type="text" name="title" class="form-control form-control-sm" required maxlength="255" value="<?php echo htmlspecialchars((string) $plan['title'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small">Service date</label>
                <input type="date" name="serviceDate" class="form-control form-control-sm" required value="<?php echo htmlspecialchars((string) $plan['serviceDate'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <?php foreach (['draft','published','archived'] as $s): ?>
                        <option value="<?php echo $s; ?>" <?php echo $plan['status'] === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary btn-sm w-100">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════ Gap #128 residual — public Order of Service share panel ═══════════════ -->
<div class="card mb-3">
    <div class="card-body">
        <h2 class="h5"><i class="fa-solid fa-share-nodes me-1 text-primary"></i>Public Order of Service</h2>
        <?php if ($publicShareSiteOn === false): ?>
            <p class="text-muted small mb-0">
                Public sharing is turned off for this site.
                <?php if (App::isAdmin() === true): ?>
                    An admin can enable it at <a href="/admin/hymns">/admin/hymns</a>.
                <?php endif; ?>
            </p>
        <?php elseif ($isPlanShared === true): ?>
            <p class="small mb-2">Anyone with this link can view a read-only order of service — presenter names shown, no internal notes.</p>
            <div class="input-group input-group-sm mb-2" style="max-width: 480px;">
                <input type="text" class="form-control" readonly value="<?php echo htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8'); ?>" onclick="this.select();">
                <a href="<?php echo htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
            </div>
            <img src="/qr.php?content=<?php echo urlencode($publicUrl); ?>&amp;size=160" width="160" height="160" alt="QR code for the public Order of Service" class="mb-2 border rounded">
            <div class="d-flex gap-2">
                <form method="post" action="/service-plans/share">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="planID" value="<?php echo $id; ?>">
                    <input type="hidden" name="action" value="rotate">
                    <button type="submit" class="btn btn-sm btn-outline-secondary" data-confirm="Rotate the link? The old link/QR code will stop working." data-confirm-destructive="true"><i class="fa-solid fa-rotate me-1"></i>Rotate link</button>
                </form>
                <form method="post" action="/service-plans/share">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="planID" value="<?php echo $id; ?>">
                    <input type="hidden" name="action" value="disable">
                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-lock me-1"></i>Turn off sharing</button>
                </form>
            </div>
        <?php else: ?>
            <p class="text-muted small">Not shared. Enabling renders only the order/titles/hymns/presenter names — internal notes are never shown, and it only becomes visible once this plan's status is "published".</p>
            <form method="post" action="/service-plans/share">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="planID" value="<?php echo $id; ?>">
                <input type="hidden" name="action" value="enable">
                <button type="submit" class="btn btn-sm btn-primary"><i class="fa-solid fa-share-nodes me-1"></i>Enable public sharing</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if (AppRegistry::isEnabled('worship') === true): ?>
<!-- 🎶 Gap #6 bridge — paired worship presentation plan (#442) -->
<div class="card mb-3">
    <div class="card-body">
        <h2 class="h5"><i class="fa-solid fa-music me-1 text-primary"></i>Worship presentation</h2>
        <?php if ($worshipLink !== null): ?>
            <div class="portal-data-list">
                <div class="portal-data-row">
                    <div class="portal-data-row-main">
                        <a href="/worship/plan?id=<?php echo (int) $worshipLink['planID']; ?>" class="text-decoration-none fw-semibold">
                            <?php echo htmlspecialchars((string) $worshipLink['name'], ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                        <?php if ((int) $worshipLink['isActive'] === 0): ?>
                            <span class="badge bg-secondary ms-1">Archived</span>
                        <?php endif; ?>
                        <?php if ($worshipLink['eventName'] !== null): ?>
                            <span class="badge bg-info text-dark ms-1"><i class="fa-solid fa-calendar me-1"></i><?php echo htmlspecialchars((string) $worshipLink['eventName'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php else: ?>
                            <span class="badge bg-light text-dark border ms-1">Template</span>
                        <?php endif; ?>
                        <div class="small text-muted mt-1">
                            <?php echo (int) $worshipLink['itemCount']; ?> slide<?php echo (int) $worshipLink['itemCount'] === 1 ? '' : 's'; ?>
                            <?php if (count($worshipLink['songTitles']) > 0): ?>
                                &middot; songs:
                                <?php echo htmlspecialchars(
                                    implode(', ', array_map(
                                        static fn ($t) => $t !== null ? $t : '(song deleted)',
                                        $worshipLink['songTitles']
                                    )),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="portal-data-row-aside">
                        <a href="/worship/plan?id=<?php echo (int) $worshipLink['planID']; ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-pen me-1"></i>Open</a>
                        <a href="/worship/present?id=<?php echo (int) $worshipLink['planID']; ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-play me-1"></i>Present</a>
                        <?php if (App::isAdmin() === true): ?>
                            <form method="post" action="/worship/plan/link" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="worshipPlanID" value="<?php echo (int) $worshipLink['planID']; ?>">
                                <input type="hidden" name="action" value="unpair">
                                <input type="hidden" name="from" value="runsheet">
                                <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Unlink this worship presentation from this run-sheet?" data-confirm-destructive="true"><i class="fa-solid fa-link-slash me-1"></i>Unlink</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php elseif (App::isAdmin() === true): ?>
            <?php if (count($worshipCandidates) > 0): ?>
                <form method="post" action="/worship/plan/link" class="row g-2 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="runPlanID" value="<?php echo $id; ?>">
                    <input type="hidden" name="action" value="pair">
                    <input type="hidden" name="from" value="runsheet">
                    <div class="col-md-8">
                        <label class="form-label small">Link an existing worship presentation plan</label>
                        <select name="worshipPlanID" required class="form-select form-select-sm">
                            <option value="">Choose a plan…</option>
                            <?php foreach ($worshipCandidates as $c): ?>
                                <option value="<?php echo (int) $c['planID']; ?>">
                                    <?php echo htmlspecialchars((string) $c['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php echo $c['eventName'] !== null ? ' (' . htmlspecialchars((string) $c['eventName'], ENT_QUOTES, 'UTF-8') . ')' : ' (Template)'; ?>
                                    — <?php echo (int) $c['itemCount']; ?> slide<?php echo (int) $c['itemCount'] === 1 ? '' : 's'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-link me-1"></i>Link</button>
                    </div>
                </form>
            <?php else: ?>
                <p class="text-muted small mb-0">No unpaired worship presentation plans available to link.</p>
            <?php endif; ?>
        <?php else: ?>
            <p class="text-muted small mb-0">No worship presentation linked.</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Items -->
<div class="card">
    <div class="card-body">
        <h2 class="h5">Sections</h2>
        <?php foreach ($items as $idx => $it): ?>
            <div class="border-bottom py-3">
                <form method="post" action="/service-plans/item-save" class="row g-2">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="planID" value="<?php echo $id; ?>">
                    <input type="hidden" name="itemID" value="<?php echo (int) $it['itemID']; ?>">
                    <input type="hidden" name="action" value="update">
                    <div class="col-md-1 text-center small text-muted">
                        <strong><?php echo $idx + 1; ?></strong><br>
                        <form method="post" action="/service-plans/item-save" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="planID" value="<?php echo $id; ?>">
                            <input type="hidden" name="itemID" value="<?php echo (int) $it['itemID']; ?>">
                            <input type="hidden" name="action" value="move-up">
                            <button type="submit" class="btn btn-link btn-sm p-0" title="Move up" <?php echo $idx === 0 ? 'disabled' : ''; ?>>▲</button>
                        </form>
                        <form method="post" action="/service-plans/item-save" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="planID" value="<?php echo $id; ?>">
                            <input type="hidden" name="itemID" value="<?php echo (int) $it['itemID']; ?>">
                            <input type="hidden" name="action" value="move-down">
                            <button type="submit" class="btn btn-link btn-sm p-0" title="Move down" <?php echo $idx === count($items) - 1 ? 'disabled' : ''; ?>>▼</button>
                        </form>
                    </div>
                    <div class="col-md-2">
                        <select name="sectionType" class="form-select form-select-sm">
                            <?php foreach ($sectionTypes as $val => $lbl): ?>
                                <option value="<?php echo $val; ?>" <?php echo $it['sectionType'] === $val ? 'selected' : ''; ?>><?php echo htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 position-relative hymn-picker">
                        <input type="text" name="title" class="form-control form-control-sm" maxlength="255" value="<?php echo htmlspecialchars((string) ($it['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Title (or search a hymn/song below)">
                        <!-- 🎵 Gap #128 residual — hymn/song picker. Hidden fields carry
                             either an already-canonical songID or a fresh pick's metadata
                             for item-save.php to auto-promote (see that file's header). -->
                        <input type="hidden" name="songID" value="<?php echo (int) ($it['songID'] ?? 0); ?>">
                        <input type="hidden" name="pickTitle" value="">
                        <input type="hidden" name="pickAuthor" value="">
                        <input type="hidden" name="pickCcli" value="">
                        <input type="hidden" name="pickCopyright" value="">
                        <input type="hidden" name="pickHymnalCode" value="">
                        <input type="hidden" name="pickHymnalNumber" value="">
                        <input type="hidden" name="pickTune" value="">
                        <input type="text" class="form-control form-control-sm mt-1 hymn-search-input" placeholder="🔎 Search hymn/song…" autocomplete="off">
                        <div class="list-group hymn-search-results position-absolute w-100" style="z-index:20; max-height:220px; overflow-y:auto; display:none;"></div>
                    </div>
                    <div class="col-md-2">
                        <select name="presenterID" class="form-select form-select-sm">
                            <option value="0">— Presenter —</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?php echo (int) $u['userID']; ?>" <?php echo (int) $it['presenterID'] === (int) $u['userID'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string) $u['fullName'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <input type="number" name="durationMin" min="0" max="240" class="form-control form-control-sm" value="<?php echo (int) ($it['durationMin'] ?? 0); ?>" placeholder="Min">
                    </div>
                    <div class="col-md-2 text-end">
                        <button type="submit" class="btn btn-success btn-sm">Save</button>
                        <form method="post" action="/service-plans/item-save" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="planID" value="<?php echo $id; ?>">
                            <input type="hidden" name="itemID" value="<?php echo (int) $it['itemID']; ?>">
                            <input type="hidden" name="action" value="delete">
                            <button type="submit" class="btn btn-outline-danger btn-sm"
                                    data-confirm="Delete this section?" data-confirm-destructive="true">Del</button>
                        </form>
                    </div>
                    <div class="col-12">
                        <input type="text" name="presenterText" class="form-control form-control-sm" maxlength="255" value="<?php echo htmlspecialchars((string) ($it['presenterText'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Presenter (if not a portal user)">
                    </div>
                    <div class="col-12">
                        <textarea name="notes" class="form-control form-control-sm" rows="2" placeholder="Notes / AV cues (markdown)"><?php echo htmlspecialchars((string) ($it['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                </form>
            </div>
        <?php endforeach; ?>

        <!-- Add item -->
        <form method="post" action="/service-plans/item-save" class="row g-2 mt-3 pt-3 border-top">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="planID" value="<?php echo $id; ?>">
            <input type="hidden" name="action" value="create">
            <div class="col-md-3">
                <select name="sectionType" class="form-select form-select-sm">
                    <?php foreach ($sectionTypes as $val => $lbl): ?>
                        <option value="<?php echo $val; ?>"><?php echo htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6 position-relative hymn-picker">
                <input type="text" name="title" class="form-control form-control-sm" maxlength="255" placeholder="Title (e.g. Hymn 256 — Amazing Grace)">
                <input type="hidden" name="songID" value="0">
                <input type="hidden" name="pickTitle" value="">
                <input type="hidden" name="pickAuthor" value="">
                <input type="hidden" name="pickCcli" value="">
                <input type="hidden" name="pickCopyright" value="">
                <input type="hidden" name="pickHymnalCode" value="">
                <input type="hidden" name="pickHymnalNumber" value="">
                <input type="hidden" name="pickTune" value="">
                <input type="text" class="form-control form-control-sm mt-1 hymn-search-input" placeholder="🔎 Search hymn/song…" autocomplete="off">
                <div class="list-group hymn-search-results position-absolute w-100" style="z-index:20; max-height:220px; overflow-y:auto; display:none;"></div>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-plus me-1"></i>Add section</button>
            </div>
        </form>
    </div>
</div>

<script>
// -----------------------------------------------------------------------
// 🎵 Gap #128 residual — hymn/song picker typeahead. Progressive
// enhancement only: with JS disabled or the fetch failing, `title` stays a
// plain text input exactly as before #128 — nothing regresses.
// -----------------------------------------------------------------------
(function () {
    'use strict';

    function debounce(fn, ms) {
        var t;
        return function () {
            var ctx = this, args = arguments;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(ctx, args); }, ms);
        };
    }

    function wireHymnPicker(root) {
        var input = root.querySelector('.hymn-search-input');
        var list  = root.querySelector('.hymn-search-results');
        var form  = root.closest('form');
        if (!input || !list || !form) {
            return;
        }

        function setField(name, val) {
            var el = form.querySelector('[name="' + name + '"]');
            if (el) {
                el.value = val || '';
            }
        }

        function clearPick() {
            ['pickTitle', 'pickAuthor', 'pickCcli', 'pickCopyright', 'pickHymnalCode', 'pickHymnalNumber', 'pickTune'].forEach(function (n) {
                setField(n, '');
            });
        }

        function pick(item) {
            list.style.display = 'none';
            list.innerHTML = '';
            input.value = '';
            clearPick();
            setField('songID', '0');

            var titleField = form.querySelector('[name="title"]');
            if (item.source === 'song') {
                if (titleField) { titleField.value = item.title || ''; }
                setField('songID', String(item.songID || 0));
                return;
            }

            var label = (item.hymnalCode ? item.hymnalCode + ' ' : '') + (item.number ? item.number + ' — ' : '') + (item.title || '');
            if (titleField) { titleField.value = label; }
            setField('pickTitle', item.title || '');
            setField('pickAuthor', item.author || '');
            setField('pickCcli', item.ccliNumber || '');
            setField('pickCopyright', item.copyrightLine || '');
            setField('pickHymnalCode', item.hymnalCode || '');
            setField('pickHymnalNumber', item.number || '');
            setField('pickTune', item.tuneName || '');
        }

        var doSearch = debounce(function () {
            var q = input.value.trim();
            if (q.length < 2) {
                list.style.display = 'none';
                list.innerHTML = '';
                return;
            }
            fetch('/api/service-plans/hymn-search?q=' + encodeURIComponent(q) + '&limit=12', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    var results = (json && json.data && json.data.results) || [];
                    list.innerHTML = '';
                    if (results.length === 0) {
                        list.style.display = 'none';
                        return;
                    }
                    results.forEach(function (item) {
                        var badge = item.source === 'hymnal' ? 'Hymnal' : (item.source === 'remote' ? 'iHymns' : 'Song library');
                        var btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'list-group-item list-group-item-action small py-1';
                        btn.textContent = '[' + badge + '] ' + (item.number ? item.number + ' — ' : '') + (item.title || '') + (item.author ? ' (' + item.author + ')' : '');
                        btn.addEventListener('click', function () { pick(item); });
                        list.appendChild(btn);
                    });
                    list.style.display = 'block';
                })
                .catch(function () {
                    list.style.display = 'none';
                });
        }, 300);

        input.addEventListener('input', doSearch);
        document.addEventListener('click', function (e) {
            if (!root.contains(e.target)) {
                list.style.display = 'none';
            }
        });
    }

    document.querySelectorAll('.hymn-picker').forEach(wireHymnPicker);
})();
</script>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
