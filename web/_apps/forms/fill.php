<?php
// Path: _apps/forms/fill.php
/**
 * -----------------------------------------------------------------------------
 * Forms Builder — Internal Fill Page 📝
 * -----------------------------------------------------------------------------
 * Session-authenticated fill page for one published internal/both form.
 * Re-runs every "is this form fillable" gate server-side on the POST
 * handler (submit.php) too — this page is display only.
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

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$userId = (int) (App::user()['userID'] ?? 0);
$formId = (int) ($_GET['id'] ?? 0);

$form = FormEngine::getForm($formId, $siteId);

$pageTitle   = 'Fill in Form';
$pageSection = 'forms';
$breadcrumbs = ['Dashboard' => '/', 'Forms' => '/forms', 'Fill in' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';

// 🚫 Not found / wrong site — internal users may see the reason (this is
// NOT the anonymous public route, so there is no oracle to protect here).
if ($form === null) {
    echo '<div class="alert alert-warning"><i class="fa-solid fa-triangle-exclamation me-1"></i>Form not found.</div>';
    echo '<a href="/forms" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i> Back to Forms</a>';
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
    exit();
}

$audienceOk = in_array((string) $form['audience'], ['internal', 'both'], true);
$isOpen     = FormEngine::isOpen($form);

if ($audienceOk === false || $isOpen === false) {
    echo '<div class="alert alert-light border"><i class="fa-solid fa-circle-info me-1"></i>This form is not currently available.</div>';
    echo '<a href="/forms" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i> Back to Forms</a>';
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
    exit();
}

// 🔁 Single-submission check.
$allowMultiple = (int) $form['allowMultiple'] === 1;
$alreadySubmitted = false;
if ($allowMultiple === false) {
    $db = App::db();
    $chkStmt = $db->prepare('SELECT responseID FROM tblFormResponses WHERE formID = ? AND submitterID = ? LIMIT 1');
    if ($chkStmt !== false) {
        $chkStmt->bind_param('ii', $formId, $userId);
        $chkStmt->execute();
        $alreadySubmitted = $chkStmt->get_result()->fetch_assoc() !== null;
        $chkStmt->close();
    }
}

if ($alreadySubmitted === true) {
    echo '<div class="alert alert-success"><i class="fa-solid fa-circle-check me-1"></i>You have already submitted this form. Thank you.</div>';
    echo '<a href="/forms" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i> Back to Forms</a>';
    require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php';
    exit();
}

$fields = FormEngine::getFields($formId);

// 🚩 Flash bag from submit.php on a validation failure — read once, unset.
$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? 'danger';
$flashOld    = $_SESSION['forms_flash_old']    ?? [];
$flashErrors = $_SESSION['forms_flash_errors'] ?? [];
$flashFormId = (int) ($_SESSION['forms_flash_formID'] ?? 0);
unset(
    $_SESSION['flash_msg'],
    $_SESSION['flash_type'],
    $_SESSION['forms_flash_old'],
    $_SESSION['forms_flash_errors'],
    $_SESSION['forms_flash_formID']
);
// 🛡️ Only replay the flash bag if it belongs to THIS form.
if ($flashFormId !== $formId) {
    $flashOld = [];
    $flashErrors = [];
}

$csrf = Auth::csrfToken();
$titleSafe = htmlspecialchars((string) $form['title'], ENT_QUOTES, 'UTF-8');
$descRaw = (string) ($form['description'] ?? '');
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="mb-0"><i class="fa-solid fa-pen-to-square me-2"></i><?php echo $titleSafe; ?></h1>
    <a href="/forms" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i> Back</a>
</div>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<?php if ($descRaw !== ''): ?>
    <p class="text-muted"><?php echo nl2br(htmlspecialchars($descRaw, ENT_QUOTES, 'UTF-8')); ?></p>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="post" action="/forms/submit" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="formID" value="<?php echo $formId; ?>">

            <?php foreach ($fields as $field): ?>
                <?php echo FormEngine::renderField($field, $flashOld, $flashErrors); ?>
            <?php endforeach; ?>

            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-paper-plane me-1"></i> Submit</button>
        </form>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
