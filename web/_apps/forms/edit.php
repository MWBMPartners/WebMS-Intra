<?php
// Path: _apps/forms/edit.php
/**
 * -----------------------------------------------------------------------------
 * Forms Builder — Build / Edit One Form 🧾
 * -----------------------------------------------------------------------------
 * Admin form builder: form-level metadata (title/description/audience/
 * confirmation/window) and the ordered field list (add/edit/delete/reorder).
 * `formID = 0` (or absent) is create mode — the meta card only, "save to add
 * fields" — fields can only be attached once the form itself exists.
 *
 * fieldType is immutable once a field is created (see field-save.php's own
 * header) — this UI never offers a type selector on the edit-in-place form,
 * only on "Add a field".
 *
 * @package   Portal\Forms
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/153
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\FormEngine;
use Portal\Core\Site;

$pageTitle   = 'Edit Form';
$pageSection = 'forms';

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    $pageTitle = 'Forbidden';
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
    echo '<div class="alert alert-danger"><i class="fa-solid fa-lock me-1"></i>'
        . htmlspecialchars(t('error.moderator_only'), ENT_QUOTES, 'UTF-8')
        . '</div>';
    echo '<a href="/forms" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i> Back</a>';
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
    exit();
}

$siteId = Site::id();
$formId = (int) ($_GET['id'] ?? 0);
$form   = null;

if ($formId > 0) {
    $form = FormEngine::getForm($formId, $siteId);
    if ($form === null) {
        // 🛡️ Cross-site id / unknown id — no existence oracle, same flash+redirect either way.
        $_SESSION['flash_msg']  = 'Form not found.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: /forms/manage', true, 302);
        exit();
    }
}

$fields = $formId > 0 ? FormEngine::getFields($formId) : [];

$allowPublicSite = (string) (App::settings('forms.allowPublic') ?? 'false') === 'true';
$siteBaseUrl = rtrim((string) (App::settings('site.url') ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? ''))), '/');
$publicToken = (string) ($form['publicToken'] ?? '');
$publicUrl   = $publicToken !== '' ? ($siteBaseUrl . '/f/' . $publicToken) : '';

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$csrf = Auth::csrfToken();
$nonce = htmlspecialchars(App::cspNonce(), ENT_QUOTES, 'UTF-8');

$pageTitle   = $formId > 0 ? 'Edit — ' . (string) $form['title'] : 'New Form';
$breadcrumbs = ['Dashboard' => '/', 'Forms' => '/forms', 'Manage' => '/forms/manage', ($formId > 0 ? 'Edit' : 'New') => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="mb-0"><i class="fa-solid fa-clipboard-list me-2"></i><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
    <a href="/forms/manage" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i> Back to manage</a>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<!-- ═══════════════ Meta card ═══════════════ -->
<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3"><i class="fa-solid fa-pen me-1 text-primary"></i>Form details</h2>
        <form method="post" action="/forms/save">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="formID" value="<?php echo $formId; ?>">

            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label small">Title <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="title" maxlength="200" required
                           value="<?php echo htmlspecialchars((string) ($form['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Audience</label>
                    <select class="form-select" name="audience" <?php echo $allowPublicSite === false ? 'disabled' : ''; ?>>
                        <option value="internal" <?php echo (string) ($form['audience'] ?? 'internal') === 'internal' ? 'selected' : ''; ?>>Internal (site members)</option>
                        <option value="public" <?php echo $allowPublicSite === false ? 'disabled' : ''; ?> <?php echo (string) ($form['audience'] ?? '') === 'public' ? 'selected' : ''; ?>>Public only</option>
                        <option value="both" <?php echo $allowPublicSite === false ? 'disabled' : ''; ?> <?php echo (string) ($form['audience'] ?? '') === 'both' ? 'selected' : ''; ?>>Internal + public</option>
                    </select>
                    <?php if ($allowPublicSite === false): ?>
                        <div class="form-text">Public sharing is off for this site — <a href="/settings">enable it in Settings</a> to unlock public/both.</div>
                        <input type="hidden" name="audience" value="internal">
                    <?php endif; ?>
                </div>

                <div class="col-12">
                    <label class="form-label small">Description</label>
                    <textarea class="form-control" name="description" rows="2" maxlength="10000"><?php echo htmlspecialchars((string) ($form['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <div class="col-12">
                    <label class="form-label small">Confirmation message (shown after submit)</label>
                    <input type="text" class="form-control" name="confirmationText" maxlength="500"
                           value="<?php echo htmlspecialchars((string) ($form['confirmationText'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                           placeholder="Thank you — your response has been recorded.">
                </div>

                <div class="col-md-4">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="allowMultiple" id="allowMultiple" value="1"
                               <?php echo (int) ($form['allowMultiple'] ?? 1) === 1 ? 'checked' : ''; ?>>
                        <label class="form-check-label small" for="allowMultiple">Allow a member to submit more than once</label>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Opens at <span class="text-muted">(optional)</span></label>
                    <input type="datetime-local" class="form-control" name="opensAt"
                           value="<?php
                                $opensAtRaw = (string) ($form['opensAt'] ?? '');
                                echo $opensAtRaw !== '' ? htmlspecialchars(str_replace(' ', 'T', substr($opensAtRaw, 0, 16)), ENT_QUOTES, 'UTF-8') : '';
                           ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Closes at <span class="text-muted">(optional)</span></label>
                    <input type="datetime-local" class="form-control" name="closesAt"
                           value="<?php
                                $closesAtRaw = (string) ($form['closesAt'] ?? '');
                                echo $closesAtRaw !== '' ? htmlspecialchars(str_replace(' ', 'T', substr($closesAtRaw, 0, 16)), ENT_QUOTES, 'UTF-8') : '';
                           ?>">
                </div>
            </div>

            <div class="mt-3">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i> Save</button>
            </div>
        </form>
    </div>
</div>

<?php if ($formId === 0): ?>
    <div class="alert alert-light border">
        <i class="fa-solid fa-circle-info me-1"></i> Save the form first — you'll be able to add fields once it exists.
    </div>
<?php else: ?>

    <!-- ═══════════════ Publish state / public link ═══════════════ -->
    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h5 mb-3"><i class="fa-solid fa-share-nodes me-1 text-primary"></i>Publish &amp; share</h2>
            <p class="small mb-2">
                Status:
                <span class="badge <?php echo match ((string) $form['status']) {
                    'published' => 'bg-success-subtle text-success-emphasis',
                    'closed'    => 'bg-secondary-subtle text-secondary-emphasis',
                    default     => 'bg-warning-subtle text-warning-emphasis',
                }; ?>"><?php echo htmlspecialchars(ucfirst((string) $form['status']), ENT_QUOTES, 'UTF-8'); ?></span>
                — manage publish/unpublish/close from <a href="/forms/manage">Manage forms</a>.
            </p>
            <?php if ($publicUrl !== ''): ?>
                <div class="input-group input-group-sm mb-2" style="max-width: 480px;">
                    <input type="text" class="form-control" readonly value="<?php echo htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8'); ?>" onclick="this.select();">
                    <a href="<?php echo htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
                </div>
                <img src="/qr.php?content=<?php echo urlencode($publicUrl); ?>&amp;size=128" width="128" height="128" alt="QR code for the public form link" class="mb-2 border rounded d-block">
                <form method="post" action="/forms/publish">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="formID" value="<?php echo $formId; ?>">
                    <input type="hidden" name="action" value="rotate">
                    <button type="submit" class="btn btn-sm btn-outline-secondary" data-confirm="Rotate the link? The old link/QR code will stop working.">
                        <i class="fa-solid fa-rotate me-1"></i>Rotate link
                    </button>
                </form>
            <?php else: ?>
                <p class="text-muted small mb-0">
                    <?php echo in_array((string) $form['audience'], ['public', 'both'], true)
                        ? 'A public link will be generated the first time this form is published.'
                        : 'This form\'s audience is internal-only — no public link.'; ?>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ═══════════════ Fields card ═══════════════ -->
    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h5 mb-3"><i class="fa-solid fa-list-check me-1 text-primary"></i>Fields</h2>

            <?php if (count($fields) === 0): ?>
                <p class="text-muted small">No fields yet — add one below.</p>
            <?php else: ?>
                <div class="portal-data-list mb-3">
                    <?php foreach ($fields as $idx => $field):
                        $fieldId   = (int) $field['fieldID'];
                        $type      = (string) $field['fieldType'];
                        $typeLabel = FormEngine::FIELD_TYPES[$type]['label'] ?? $type;
                        $config    = (array) $field['configJson'];
                        $collapseId = 'fieldEdit' . $fieldId;
                    ?>
                        <div class="portal-data-row flex-wrap">
                            <div class="col-12 col-md-6">
                                <strong><?php echo htmlspecialchars((string) $field['label'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <span class="badge bg-info-subtle text-info-emphasis ms-1"><?php echo htmlspecialchars((string) $typeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php if ((int) $field['isRequired'] === 1): ?>
                                    <span class="badge bg-danger-subtle text-danger-emphasis ms-1">Required</span>
                                <?php endif; ?>
                                <?php if (isset($config['options']) === true): ?>
                                    <div class="small text-muted mt-1">
                                        Options: <?php echo htmlspecialchars(implode(', ', (array) $config['options']), ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-12 col-md-6 text-md-end">
                                <div class="btn-group btn-group-sm" role="group">
                                    <form method="post" action="/forms/field-move" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="fieldID" value="<?php echo $fieldId; ?>">
                                        <input type="hidden" name="formID" value="<?php echo $formId; ?>">
                                        <input type="hidden" name="direction" value="up">
                                        <button type="submit" class="btn btn-outline-secondary" title="Move up" <?php echo $idx === 0 ? 'disabled' : ''; ?>><i class="fa-solid fa-arrow-up"></i></button>
                                    </form>
                                    <form method="post" action="/forms/field-move" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="fieldID" value="<?php echo $fieldId; ?>">
                                        <input type="hidden" name="formID" value="<?php echo $formId; ?>">
                                        <input type="hidden" name="direction" value="down">
                                        <button type="submit" class="btn btn-outline-secondary" title="Move down" <?php echo $idx === count($fields) - 1 ? 'disabled' : ''; ?>><i class="fa-solid fa-arrow-down"></i></button>
                                    </form>
                                    <button type="button" class="btn btn-outline-secondary" title="Edit" data-bs-toggle="collapse" data-bs-target="#<?php echo $collapseId; ?>">
                                        <i class="fa-solid fa-pen"></i>
                                    </button>
                                    <form method="post" action="/forms/field-delete" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="fieldID" value="<?php echo $fieldId; ?>">
                                        <input type="hidden" name="formID" value="<?php echo $formId; ?>">
                                        <button type="submit" class="btn btn-outline-danger" title="Delete" data-confirm="Delete this field? Past responses keep their submitted answer, but the field will no longer appear on the form.">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <div class="col-12 collapse" id="<?php echo $collapseId; ?>">
                                <form method="post" action="/forms/field-save" class="border rounded p-3 mt-2 bg-body-tertiary">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="formID" value="<?php echo $formId; ?>">
                                    <input type="hidden" name="fieldID" value="<?php echo $fieldId; ?>">
                                    <div class="row g-2">
                                        <div class="col-md-6">
                                            <label class="form-label small">Label</label>
                                            <input type="text" class="form-control form-control-sm" name="label" maxlength="200" required
                                                   value="<?php echo htmlspecialchars((string) $field['label'], ENT_QUOTES, 'UTF-8'); ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small">Help text</label>
                                            <input type="text" class="form-control form-control-sm" name="helpText" maxlength="500"
                                                   value="<?php echo htmlspecialchars((string) ($field['helpText'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                        </div>
                                        <?php if (in_array($type, ['select', 'radio', 'checkboxes'], true) === true): ?>
                                            <div class="col-12">
                                                <label class="form-label small">Options (one per line)</label>
                                                <textarea class="form-control form-control-sm" name="options" rows="3"><?php echo htmlspecialchars(implode("\n", (array) ($config['options'] ?? [])), ENT_QUOTES, 'UTF-8'); ?></textarea>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (in_array($type, ['text', 'textarea', 'email', 'phone'], true) === true): ?>
                                            <div class="col-md-4">
                                                <label class="form-label small">Placeholder</label>
                                                <input type="text" class="form-control form-control-sm" name="placeholder" maxlength="200"
                                                       value="<?php echo htmlspecialchars((string) ($config['placeholder'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                        <?php endif; ?>
                                        <?php if (in_array($type, ['text', 'textarea'], true) === true): ?>
                                            <div class="col-md-4">
                                                <label class="form-label small">Max length</label>
                                                <input type="number" class="form-control form-control-sm" name="maxLength" min="1"
                                                       value="<?php echo htmlspecialchars((string) ($config['maxLength'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($type === 'textarea'): ?>
                                            <div class="col-md-4">
                                                <label class="form-label small">Rows</label>
                                                <input type="number" class="form-control form-control-sm" name="rows" min="1" max="12"
                                                       value="<?php echo htmlspecialchars((string) ($config['rows'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($type === 'number'): ?>
                                            <div class="col-md-3">
                                                <label class="form-label small">Min</label>
                                                <input type="number" class="form-control form-control-sm" name="min"
                                                       value="<?php echo htmlspecialchars((string) ($config['min'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label small">Max</label>
                                                <input type="number" class="form-control form-control-sm" name="max"
                                                       value="<?php echo htmlspecialchars((string) ($config['max'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                        <?php endif; ?>
                                        <div class="col-md-3">
                                            <div class="form-check mt-4">
                                                <input class="form-check-input" type="checkbox" name="isRequired" value="1" id="req<?php echo $fieldId; ?>"
                                                       <?php echo (int) $field['isRequired'] === 1 ? 'checked' : ''; ?>>
                                                <label class="form-check-label small" for="req<?php echo $fieldId; ?>">Required</label>
                                            </div>
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-primary mt-2"><i class="fa-solid fa-floppy-disk me-1"></i> Save field</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (count($fields) >= 60): ?>
                <p class="text-muted small mb-0">This form has reached the maximum of 60 fields.</p>
            <?php else: ?>
                <!-- ═══════════════ Add field ═══════════════ -->
                <hr>
                <h3 class="h6"><i class="fa-solid fa-plus me-1"></i>Add a field</h3>
                <form method="post" action="/forms/field-save">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="formID" value="<?php echo $formId; ?>">
                    <input type="hidden" name="fieldID" value="0">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label small">Label <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" name="label" maxlength="200" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Type</label>
                            <select class="form-select form-select-sm" name="fieldType" id="newFieldType">
                                <?php foreach (FormEngine::FIELD_TYPES as $typeKey => $meta): ?>
                                    <option value="<?php echo htmlspecialchars($typeKey, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars((string) $meta['label'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Help text</label>
                            <input type="text" class="form-control form-control-sm" name="helpText" maxlength="500">
                        </div>
                        <div class="col-md-1">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" name="isRequired" value="1" id="newRequired">
                                <label class="form-check-label small" for="newRequired">Req'd</label>
                            </div>
                        </div>

                        <div class="col-12 config-group" data-types="select,radio,checkboxes">
                            <label class="form-label small">Options (one per line)</label>
                            <textarea class="form-control form-control-sm" name="options" rows="3" placeholder="Option A&#10;Option B"></textarea>
                        </div>
                        <div class="col-md-4 config-group" data-types="text,textarea,email,phone">
                            <label class="form-label small">Placeholder</label>
                            <input type="text" class="form-control form-control-sm" name="placeholder" maxlength="200">
                        </div>
                        <div class="col-md-3 config-group" data-types="text,textarea">
                            <label class="form-label small">Max length</label>
                            <input type="number" class="form-control form-control-sm" name="maxLength" min="1">
                        </div>
                        <div class="col-md-3 config-group" data-types="textarea">
                            <label class="form-label small">Rows</label>
                            <input type="number" class="form-control form-control-sm" name="rows" min="1" max="12">
                        </div>
                        <div class="col-md-2 config-group" data-types="number">
                            <label class="form-label small">Min</label>
                            <input type="number" class="form-control form-control-sm" name="min">
                        </div>
                        <div class="col-md-2 config-group" data-types="number">
                            <label class="form-label small">Max</label>
                            <input type="number" class="form-control form-control-sm" name="max">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm mt-2"><i class="fa-solid fa-plus me-1"></i> Add field</button>
                </form>

                <!-- 🧩 Progressive disclosure — show only the config inputs
                     relevant to the selected field type. Purely cosmetic:
                     field-save.php whitelists via FormEngine::sanitiseConfig()
                     regardless of what a client posts, so this script cannot
                     be relied on for security, only for a tidier form. -->
                <script nonce="<?php echo $nonce; ?>">
                (function () {
                    var select = document.getElementById('newFieldType');
                    if (!select) { return; }
                    var groups = document.querySelectorAll('.config-group');
                    function sync() {
                        var type = select.value;
                        groups.forEach(function (g) {
                            var types = (g.getAttribute('data-types') || '').split(',');
                            g.style.display = types.indexOf(type) !== -1 ? '' : 'none';
                        });
                    }
                    select.addEventListener('change', sync);
                    sync();
                })();
                </script>
            <?php endif; ?>
        </div>
    </div>

<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
