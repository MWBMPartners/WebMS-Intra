<?php
// Path: _apps/forms/index.php
/**
 * -----------------------------------------------------------------------------
 * Forms Builder — Open Forms List 🧾
 * -----------------------------------------------------------------------------
 * User-facing list of published forms open to the current site's members
 * (audience internal/both, currently within their open window). Route
 * `forms`, protected — reached via tblRoutes so `$mysqli`/`$SETTINGS` are in
 * scope, though this page uses `Portal\Core\FormEngine`/`App::db()`
 * throughout instead.
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
$db     = App::db();

// 📋 Published forms open to this site's members, newest first.
$forms = [];
$stmt = $db->prepare(
    'SELECT * FROM tblForms '
    . 'WHERE siteID = ? AND status = \'published\' AND audience IN (\'internal\', \'both\') '
    . 'ORDER BY createdAt DESC'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        // 🕐 Window logic lives in ONE place (FormEngine::isOpen()) — a
        // closed/not-yet-open form is simply excluded from this list.
        if (FormEngine::isOpen($row) === true) {
            $forms[] = $row;
        }
    }
    $stmt->close();
}

// ✅ Which of these forms has the current user already submitted?
$submittedFormIds = [];
$idStmt = $db->prepare('SELECT DISTINCT formID FROM tblFormResponses WHERE siteID = ? AND submitterID = ?');
if ($idStmt !== false) {
    $idStmt->bind_param('ii', $siteId, $userId);
    $idStmt->execute();
    $idResult = $idStmt->get_result();
    while ($idRow = $idResult->fetch_assoc()) {
        $submittedFormIds[] = (int) $idRow['formID'];
    }
    $idStmt->close();
}

$pageTitle   = 'Forms';
$pageSection = 'forms';
$breadcrumbs = ['Dashboard' => '/', 'Forms' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-clipboard-list me-2"></i>Forms</h1>
        <p class="text-secondary mb-0">Forms open for you to fill in.</p>
    </div>
    <?php if (App::isAdmin() === true): ?>
        <a href="/forms/manage" class="btn btn-outline-secondary">
            <i class="fa-solid fa-gear me-1"></i> Manage forms
        </a>
    <?php endif; ?>
</div>

<?php if (count($forms) === 0): ?>
    <div class="alert alert-light border">
        <i class="fa-solid fa-circle-info me-1"></i> There are no forms open right now.
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($forms as $form):
            $formId       = (int) $form['formID'];
            $alreadySent  = in_array($formId, $submittedFormIds, true);
            $allowMultiple = (int) $form['allowMultiple'] === 1;
            $titleSafe = htmlspecialchars((string) $form['title'], ENT_QUOTES, 'UTF-8');
            $descRaw   = (string) ($form['description'] ?? '');
            $descExcerpt = mb_strlen($descRaw) > 160 ? mb_substr($descRaw, 0, 157) . '…' : $descRaw;
            $descSafe = htmlspecialchars($descExcerpt, ENT_QUOTES, 'UTF-8');
        ?>
            <div class="col-12 col-md-6 col-lg-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title"><?php echo $titleSafe; ?></h5>
                        <?php if ($descSafe !== ''): ?>
                            <p class="card-text text-muted small flex-grow-1"><?php echo $descSafe; ?></p>
                        <?php endif; ?>
                        <div class="mt-auto">
                            <?php if ($alreadySent === true && $allowMultiple === false): ?>
                                <span class="badge bg-success-subtle text-success-emphasis">
                                    <i class="fa-solid fa-circle-check me-1"></i> Submitted
                                </span>
                            <?php else: ?>
                                <a href="/forms/fill?id=<?php echo $formId; ?>" class="btn btn-primary btn-sm">
                                    <i class="fa-solid fa-pen-to-square me-1"></i> Fill in
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
