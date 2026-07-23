<?php
// Path: _apps/admin/discipleship/pathway-form.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Discipleship Pathway form (new + edit) 📖 (#303 Phase 1, Phase 2)
 * -----------------------------------------------------------------------------
 * Single template wired to BOTH `/admin/discipleship/pathways/new` and
 * `/admin/discipleship/pathways/edit?id=N`. When editing, the steps panel
 * is rendered below the pathway-fields form so step CRUD lives on the
 * same page (no extra route round-trip).
 *
 * Phase 2 extends the step editor with an `autoRule` select + a
 * conditional site-scoped event/category ref picker (JS toggles which
 * picker shows based on the selected rule). A step whose stored
 * `autoRefID` no longer resolves to an existing site-scoped event/category
 * renders a "(missing)" warning so a coordinator can re-point it —
 * `step-save.php` validates the ref at save time, but a ref can go stale
 * later if the event/category is deleted (no FK, deliberately — see
 * migration 153).
 *
 * Gated by:
 *   • Auth::requireLogin()
 *   • App::isAdmin() === true
 *   • Settings::get('discipleship.enabled') resolves truthy
 *   • Cross-site guard on edit (pathway must belong to active site)
 *
 * @package   Portal\App\Admin\Discipleship
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.4.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/303
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Settings;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

$enabled = (string) Settings::get('discipleship.enabled', 'false');
if ($enabled !== '1' && $enabled !== 'true') {
    header('Location: /admin/discipleship/pathways', true, 302);
    exit();
}

$siteId = Site::id();
$db     = App::db();

$pathwayId = (int) ($_GET['id'] ?? 0);
$isEdit    = $pathwayId > 0;

$pathway = [
    'pathwayID'   => 0,
    'name'        => '',
    'description' => '',
    'isActive'    => 1,
];
$steps = [];

if ($isEdit === true) {
    // 🛡️ Cross-site guard — pathway must belong to active site.
    $stmt = $db->prepare(
        'SELECT pathwayID, name, description, isActive '
        . 'FROM tblPathways WHERE pathwayID = ? AND siteID = ? LIMIT 1'
    );
    if ($stmt === false) {
        http_response_code(500);
        exit('Database error');
    }
    $stmt->bind_param('ii', $pathwayId, $siteId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row === null) {
        http_response_code(404);
        exit('Pathway not found');
    }
    $pathway = $row;

    $stmt = $db->prepare(
        'SELECT stepID, sortOrder, name, description, completionHint, isOptional, autoRule, autoRefID '
        . 'FROM tblPathwaySteps WHERE pathwayID = ? '
        . 'ORDER BY sortOrder ASC, stepID ASC'
    );
    if ($stmt !== false) {
        $stmt->bind_param('i', $pathwayId);
        $stmt->execute();
        $result = $stmt->get_result();
        while (($r = $result->fetch_assoc()) !== null) {
            $steps[] = $r;
        }
        $stmt->close();
    }

    // 🔎 Per-step "does the stored autoRefID still resolve?" check — no FK
    // on autoRefID (deliberate, it's polymorphic), so a step's ref can go
    // stale if the event/category it names is later deleted.
    foreach ($steps as &$stepRow) {
        $stepRow['autoRefMissing'] = false;
        $rule = (string) $stepRow['autoRule'];
        $ref  = $stepRow['autoRefID'] !== null ? (int) $stepRow['autoRefID'] : null;
        if ($rule !== 'none' && $ref !== null) {
            if ($rule === 'attended_category') {
                $chk = $db->prepare('SELECT categoryID FROM tblEventCategories WHERE categoryID = ? AND siteID = ?');
            } else {
                $chk = $db->prepare('SELECT eventID FROM tblEvents WHERE eventID = ? AND siteID = ?');
            }
            if ($chk !== false) {
                $chk->bind_param('ii', $ref, $siteId);
                $chk->execute();
                $stepRow['autoRefMissing'] = $chk->get_result()->fetch_assoc() === null;
                $chk->close();
            }
        }
    }
    unset($stepRow);
}

// 📋 Site-scoped picker lists for the autoRule ref selects (edit mode only).
$eventOptions    = [];
$categoryOptions = [];
if ($isEdit === true) {
    $evStmt = $db->prepare(
        'SELECT eventID, eventName, startDateTime FROM tblEvents '
        . 'WHERE siteID = ? AND isDeleted = 0 ORDER BY startDateTime DESC LIMIT 200'
    );
    if ($evStmt !== false) {
        $evStmt->bind_param('i', $siteId);
        $evStmt->execute();
        $evResult = $evStmt->get_result();
        while (($ev = $evResult->fetch_assoc()) !== null) {
            $eventOptions[] = $ev;
        }
        $evStmt->close();
    }

    $catStmt = $db->prepare(
        'SELECT categoryID, categoryName FROM tblEventCategories '
        . 'WHERE siteID = ? AND isActive = 1 ORDER BY categoryName ASC'
    );
    if ($catStmt !== false) {
        $catStmt->bind_param('i', $siteId);
        $catStmt->execute();
        $catResult = $catStmt->get_result();
        while (($cat = $catResult->fetch_assoc()) !== null) {
            $categoryOptions[] = $cat;
        }
        $catStmt->close();
    }
}

// 🧩 Small helper — renders the autoRule select + its two conditional ref
// pickers for one step form (existing step, or the "add new" form).
function discipleshipRenderAutoRuleFields(array $step, array $eventOptions, array $categoryOptions): void
{
    $rule       = (string) ($step['autoRule'] ?? 'none');
    $refId      = isset($step['autoRefID']) && $step['autoRefID'] !== null ? (int) $step['autoRefID'] : 0;
    $eventVal   = in_array($rule, ['attended_event', 'rsvpd_event'], true) === true ? $refId : 0;
    $catVal     = $rule === 'attended_category' ? $refId : 0;
    $rules = [
        'none'               => 'None (manual only)',
        'attended_event'     => 'Attended a specific event',
        'attended_category'  => 'Attended any event in a category',
        'rsvpd_event'        => 'RSVP\'d "going" to a specific event (past)',
    ];
    ?>
    <div class="col-md-6">
        <label class="form-label small">Auto-completion rule</label>
        <select name="autoRule" class="form-select form-select-sm discipleship-autorule" onchange="disciplershipToggleAutoRef(this)">
            <?php foreach ($rules as $val => $label): ?>
                <option value="<?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($rule === $val ? 'selected' : ''); ?>>
                    <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-6 discipleship-autoref-picker" data-for="attended_event,rsvpd_event" style="<?php echo (in_array($rule, ['attended_event', 'rsvpd_event'], true) === false ? 'display:none;' : ''); ?>">
        <label class="form-label small">Event</label>
        <select name="autoRefEventID" class="form-select form-select-sm">
            <option value="0">Choose an event&hellip;</option>
            <?php foreach ($eventOptions as $ev): ?>
                <option value="<?php echo (int) $ev['eventID']; ?>" <?php echo ($eventVal === (int) $ev['eventID'] ? 'selected' : ''); ?>>
                    <?php echo htmlspecialchars((string) $ev['eventName'], ENT_QUOTES, 'UTF-8'); ?>
                    (<?php echo htmlspecialchars(date('j M Y', (int) strtotime((string) $ev['startDateTime'])), ENT_QUOTES, 'UTF-8'); ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-6 discipleship-autoref-picker" data-for="attended_category" style="<?php echo ($rule !== 'attended_category' ? 'display:none;' : ''); ?>">
        <label class="form-label small">Category</label>
        <select name="autoRefCategoryID" class="form-select form-select-sm">
            <option value="0">Choose a category&hellip;</option>
            <?php foreach ($categoryOptions as $cat): ?>
                <option value="<?php echo (int) $cat['categoryID']; ?>" <?php echo ($catVal === (int) $cat['categoryID'] ? 'selected' : ''); ?>>
                    <?php echo htmlspecialchars((string) $cat['categoryName'], ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php
}

$pageTitle   = $isEdit === true ? 'Edit pathway' : 'New pathway';
$pageSection = 'admin';
$breadcrumbs = [
    'Dashboard'   => '/',
    'Admin'       => '/admin',
    'Pathways'    => '/admin/discipleship/pathways',
    $pageTitle    => '',
];
$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>
<div class="container py-3" style="max-width:880px;">
    <h1 class="h4 mb-3">
        <i class="fa-solid fa-route me-2 text-primary"></i>
        <?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?>
    </h1>

    <?php if (isset($_SESSION['flash_msg']) === true): ?>
        <?php
        $msg  = (string) $_SESSION['flash_msg'];
        $type = (string) ($_SESSION['flash_type'] ?? 'info');
        unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
        $allowed = ['success', 'info', 'warning', 'danger'];
        if (in_array($type, $allowed, true) === false) { $type = 'info'; }
        ?>
        <div class="alert alert-<?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?> py-2 small">
            <?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-body">
            <form method="post" action="/admin/discipleship/pathways/save">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="pathwayID" value="<?php echo (int) $pathway['pathwayID']; ?>">

                <div class="mb-3">
                    <label class="form-label small">Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" required maxlength="120"
                           class="form-control"
                           value="<?php echo htmlspecialchars((string) $pathway['name'], ENT_QUOTES, 'UTF-8'); ?>"
                           placeholder="e.g. New believer 101">
                </div>

                <div class="mb-3">
                    <label class="form-label small">Description</label>
                    <textarea name="description" maxlength="1000" rows="3" class="form-control"
                              placeholder="Operator-facing summary of who this pathway is for."><?php
                        echo htmlspecialchars((string) ($pathway['description'] ?? ''), ENT_QUOTES, 'UTF-8');
                    ?></textarea>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="isActive" value="1"
                           id="isActiveChk" <?php echo ((int) $pathway['isActive'] === 1) ? 'checked' : ''; ?>>
                    <label class="form-check-label small" for="isActiveChk">
                        Active (visible to coordinators)
                    </label>
                </div>

                <button class="btn btn-primary btn-sm">
                    <i class="fa-solid fa-floppy-disk me-1"></i>
                    <?php echo $isEdit === true ? 'Save changes' : 'Create pathway'; ?>
                </button>
                <a href="/admin/discipleship/pathways" class="btn btn-outline-secondary btn-sm">Cancel</a>
            </form>
        </div>
    </div>

    <?php if ($isEdit === true): ?>
        <h2 class="h5 mb-2"><i class="fa-solid fa-list-ol me-1"></i>Steps</h2>
        <p class="text-muted small">
            Define each step in this pathway. The <em>sort order</em> sets the sequence; gaps and
            duplicates are fine (use multiples of 10 to leave room for inserts).
        </p>

        <?php if (count($steps) === 0): ?>
            <div class="alert alert-info small mb-3">No steps yet — add the first one below.</div>
        <?php else: ?>
            <div class="portal-data-list mb-3">
                <?php foreach ($steps as $s): ?>
                    <div class="portal-data-row">
                        <div class="portal-data-row-main">
                            <details>
                                <summary>
                                    <strong>
                                        <?php echo (int) $s['sortOrder']; ?>.
                                        <?php echo htmlspecialchars((string) $s['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </strong>
                                    <?php if ((int) $s['isOptional'] === 1): ?>
                                        <span class="badge bg-secondary ms-1">Optional</span>
                                    <?php endif; ?>
                                    <?php if ((string) $s['autoRule'] !== 'none'): ?>
                                        <span class="badge bg-info ms-1">Auto</span>
                                    <?php endif; ?>
                                    <?php if (($s['autoRefMissing'] ?? false) === true): ?>
                                        <span class="badge bg-warning text-dark ms-1" title="The event/category this rule pointed to no longer exists — re-point it below">(missing)</span>
                                    <?php endif; ?>
                                </summary>
                                <form method="post" action="/admin/discipleship/pathways/step/save" class="mt-2">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="pathwayID" value="<?php echo (int) $pathwayId; ?>">
                                    <input type="hidden" name="stepID" value="<?php echo (int) $s['stepID']; ?>">
                                    <div class="row g-2">
                                        <div class="col-md-2">
                                            <label class="form-label small">Order</label>
                                            <input type="number" name="sortOrder" class="form-control form-control-sm"
                                                   value="<?php echo (int) $s['sortOrder']; ?>" step="1" min="0">
                                        </div>
                                        <div class="col-md-10">
                                            <label class="form-label small">Name <span class="text-danger">*</span></label>
                                            <input type="text" name="name" required maxlength="255"
                                                   class="form-control form-control-sm"
                                                   value="<?php echo htmlspecialchars((string) $s['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                        </div>
                                        <div class="col-md-12">
                                            <label class="form-label small">Description</label>
                                            <textarea name="description" maxlength="1000" rows="2"
                                                      class="form-control form-control-sm"><?php
                                                echo htmlspecialchars((string) ($s['description'] ?? ''), ENT_QUOTES, 'UTF-8');
                                            ?></textarea>
                                        </div>
                                        <div class="col-md-12">
                                            <label class="form-label small">Completion hint</label>
                                            <input type="text" name="completionHint" maxlength="500"
                                                   class="form-control form-control-sm"
                                                   value="<?php echo htmlspecialchars((string) ($s['completionHint'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                   placeholder="e.g. Tick when baptised">
                                        </div>
                                        <div class="col-md-12">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="isOptional" value="1"
                                                       id="opt<?php echo (int) $s['stepID']; ?>"
                                                       <?php echo ((int) $s['isOptional'] === 1) ? 'checked' : ''; ?>>
                                                <label class="form-check-label small" for="opt<?php echo (int) $s['stepID']; ?>">
                                                    Optional step
                                                </label>
                                            </div>
                                        </div>
                                        <?php discipleshipRenderAutoRuleFields($s, $eventOptions, $categoryOptions); ?>
                                        <div class="col-md-12 mt-2">
                                            <button class="btn btn-sm btn-primary">
                                                <i class="fa-solid fa-floppy-disk me-1"></i>Save step
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </details>
                        </div>
                        <div class="portal-data-row-aside">
                            <form method="post"
                                  action="/admin/discipleship/pathways/step/delete"
                                  class="d-inline"
                                  onsubmit="return confirm('Delete this step?');">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <input type="hidden" name="pathwayID" value="<?php echo (int) $pathwayId; ?>">
                                <input type="hidden" name="stepID" value="<?php echo (int) $s['stepID']; ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Delete step">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <h3 class="h6 mb-2"><i class="fa-solid fa-plus me-1"></i>Add a new step</h3>
                <form method="post" action="/admin/discipleship/pathways/step/save">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="pathwayID" value="<?php echo (int) $pathwayId; ?>">
                    <input type="hidden" name="stepID" value="0">
                    <div class="row g-2">
                        <div class="col-md-2">
                            <label class="form-label small">Order</label>
                            <input type="number" name="sortOrder" class="form-control form-control-sm"
                                   value="<?php echo (count($steps) + 1) * 10; ?>" step="1" min="0">
                        </div>
                        <div class="col-md-10">
                            <label class="form-label small">Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" required maxlength="255"
                                   class="form-control form-control-sm"
                                   placeholder="e.g. Attend welcome class">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label small">Description</label>
                            <textarea name="description" maxlength="1000" rows="2"
                                      class="form-control form-control-sm"></textarea>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label small">Completion hint</label>
                            <input type="text" name="completionHint" maxlength="500"
                                   class="form-control form-control-sm"
                                   placeholder="e.g. Tick when baptised">
                        </div>
                        <div class="col-md-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="isOptional" value="1"
                                       id="optNew">
                                <label class="form-check-label small" for="optNew">Optional step</label>
                            </div>
                        </div>
                        <?php discipleshipRenderAutoRuleFields(['autoRule' => 'none', 'autoRefID' => null], $eventOptions, $categoryOptions); ?>
                        <div class="col-md-12 mt-2">
                            <button class="btn btn-sm btn-primary">
                                <i class="fa-solid fa-plus me-1"></i>Add step
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>
<script>
// 🧩 Toggle which auto-completion ref picker shows based on the selected
// autoRule (#303 Phase 2). Scoped to the enclosing <form> so multiple step
// forms on the same page don't cross-toggle each other.
function disciplershipToggleAutoRef(selectEl) {
    var form = selectEl.closest('form');
    if (form === null) { return; }
    var pickers = form.querySelectorAll('.discipleship-autoref-picker');
    pickers.forEach(function (picker) {
        var applies = picker.getAttribute('data-for').split(',').indexOf(selectEl.value) !== -1;
        picker.style.display = applies ? '' : 'none';
    });
}
</script>
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
