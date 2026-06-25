<?php
// Path: _apps/admin/discipleship/pathway-form.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Discipleship Pathway form (new + edit) 📖 (#303 Phase 1)
 * -----------------------------------------------------------------------------
 * Single template wired to BOTH `/admin/discipleship/pathways/new` and
 * `/admin/discipleship/pathways/edit?id=N`. When editing, the steps panel
 * is rendered below the pathway-fields form so step CRUD lives on the
 * same page (no extra route round-trip).
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
 * @version   1.0.0
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
        'SELECT stepID, sortOrder, name, description, completionHint, isOptional '
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
<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
