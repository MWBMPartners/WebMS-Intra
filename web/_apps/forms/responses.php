<?php
// Path: _apps/forms/responses.php
/**
 * -----------------------------------------------------------------------------
 * Forms Builder — Admin Response List 📥
 * -----------------------------------------------------------------------------
 * Admin-only. Lists responses for one form (site-scoped), newest first,
 * capped at 200 (house LIMIT-100-ish precedent; pagination is a follow-up —
 * see spec §7.13). Each row expands (Bootstrap collapse, no JS needed) to a
 * definition list of every answer, decoded from the immutable answersJson
 * snapshot — label and value both escaped.
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

$pageTitle   = 'Form Responses';
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
$form   = FormEngine::getForm($formId, $siteId);

if ($form === null) {
    $_SESSION['flash_msg']  = 'Form not found.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /forms/manage', true, 302);
    exit();
}

$filterStatus = (string) ($_GET['status'] ?? 'new');
if (in_array($filterStatus, ['new', 'reviewed'], true) === false) {
    $filterStatus = 'new';
}

$db = App::db();

$counts = ['new' => 0, 'reviewed' => 0];
$countStmt = $db->prepare('SELECT status, COUNT(*) AS cnt FROM tblFormResponses WHERE formID = ? AND siteID = ? GROUP BY status');
if ($countStmt !== false) {
    $countStmt->bind_param('ii', $formId, $siteId);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    while ($cRow = $countResult->fetch_assoc()) {
        $counts[(string) $cRow['status']] = (int) $cRow['cnt'];
    }
    $countStmt->close();
}

$rows = [];
$stmt = $db->prepare(
    'SELECT r.*, u.fullName '
    . 'FROM tblFormResponses r LEFT JOIN tblUsers u ON u.userID = r.submitterID '
    . 'WHERE r.formID = ? AND r.siteID = ? AND r.status = ? '
    . 'ORDER BY r.createdAt DESC LIMIT 200'
);
if ($stmt !== false) {
    $stmt->bind_param('iis', $formId, $siteId, $filterStatus);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
}

$csrf = Auth::csrfToken();
$exportCsrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
$titleSafe = htmlspecialchars((string) $form['title'], ENT_QUOTES, 'UTF-8');

$pageTitle   = 'Responses — ' . (string) $form['title'];
$breadcrumbs = ['Dashboard' => '/', 'Forms' => '/forms', 'Manage' => '/forms/manage', 'Responses' => ''];

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="mb-0"><i class="fa-solid fa-inbox me-2"></i>Responses — <?php echo $titleSafe; ?></h1>
    <div class="d-flex gap-2">
        <a href="/forms/manage" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i> Back to manage</a>
        <a href="/forms/export?id=<?php echo $formId; ?>&csrf_token=<?php echo $exportCsrf; ?>" class="btn btn-outline-primary">
            <i class="fa-solid fa-file-csv me-1"></i> Export CSV
        </a>
    </div>
</div>

<ul class="nav nav-tabs mb-3">
    <?php foreach (['new' => 'New', 'reviewed' => 'Reviewed'] as $key => $label): ?>
        <li class="nav-item">
            <a class="nav-link<?php echo $filterStatus === $key ? ' active' : ''; ?>"
               href="/forms/responses?id=<?php echo $formId; ?>&status=<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                <span class="badge bg-secondary ms-1"><?php echo (int) $counts[$key]; ?></span>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<?php if (count($rows) === 0): ?>
    <div class="alert alert-light border">No responses in this status.</div>
<?php else: ?>
    <div class="portal-data-list">
        <div class="portal-data-row portal-data-header d-none d-md-flex">
            <div class="col-md-2">Submitted</div>
            <div class="col-md-2">Channel</div>
            <div class="col-md-3">Submitter</div>
            <div class="col-md-2">Status</div>
            <div class="col-md-3 text-end">Actions</div>
        </div>

        <?php foreach ($rows as $resp):
            $responseId = (int) $resp['responseID'];
            $collapseId = 'resp' . $responseId;
            $channel = (string) $resp['channel'];
            $submitterDisplay = $resp['submitterID'] !== null
                ? (string) ($resp['fullName'] ?? ('#' . $resp['submitterID']))
                : 'Public';
            $answers = FormEngine::decodeAnswers((string) $resp['answersJson']);
        ?>
            <div class="portal-data-row flex-wrap">
                <div class="col-6 col-md-2 small">
                    <a href="#" data-bs-toggle="collapse" data-bs-target="#<?php echo $collapseId; ?>">
                        <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime((string) $resp['createdAt'])), ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                </div>
                <div class="col-6 col-md-2">
                    <span class="badge <?php echo $channel === 'public' ? 'bg-info-subtle text-info-emphasis' : 'bg-secondary-subtle text-secondary-emphasis'; ?>">
                        <?php echo htmlspecialchars(ucfirst($channel), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
                <div class="col-6 col-md-3 small"><?php echo htmlspecialchars($submitterDisplay, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="col-6 col-md-2 small text-capitalize"><?php echo htmlspecialchars((string) $resp['status'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="col-12 col-md-3 text-md-end">
                    <div class="btn-group btn-group-sm" role="group">
                        <?php if ((string) $resp['status'] === 'new'): ?>
                            <form method="post" action="/forms/response-act" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="responseID" value="<?php echo $responseId; ?>">
                                <input type="hidden" name="formID" value="<?php echo $formId; ?>">
                                <input type="hidden" name="action" value="reviewed">
                                <button type="submit" class="btn btn-outline-success" title="Mark reviewed"><i class="fa-solid fa-check"></i></button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="/forms/response-act" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="responseID" value="<?php echo $responseId; ?>">
                                <input type="hidden" name="formID" value="<?php echo $formId; ?>">
                                <input type="hidden" name="action" value="new">
                                <button type="submit" class="btn btn-outline-secondary" title="Mark new"><i class="fa-solid fa-rotate-left"></i></button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="/forms/response-act" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="responseID" value="<?php echo $responseId; ?>">
                            <input type="hidden" name="formID" value="<?php echo $formId; ?>">
                            <input type="hidden" name="action" value="delete">
                            <button type="submit" class="btn btn-outline-danger" title="Delete" data-confirm="Delete this response? This cannot be undone.">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
                <div class="col-12 collapse" id="<?php echo $collapseId; ?>">
                    <dl class="row small border rounded p-2 mt-2 bg-body-tertiary mb-0">
                        <?php if (count($answers) === 0): ?>
                            <dt class="col-sm-4">—</dt>
                            <dd class="col-sm-8">No answers recorded.</dd>
                        <?php endif; ?>
                        <?php foreach ($answers as $entry):
                            $answerLabel = htmlspecialchars((string) ($entry['label'] ?? ''), ENT_QUOTES, 'UTF-8');
                            $answerValue = $entry['value'] ?? '';
                            $answerValueStr = is_array($answerValue)
                                ? implode('; ', array_map(static fn ($v): string => (string) $v, $answerValue))
                                : (string) $answerValue;
                        ?>
                            <dt class="col-sm-4"><?php echo $answerLabel; ?></dt>
                            <dd class="col-sm-8"><?php echo htmlspecialchars($answerValueStr, ENT_QUOTES, 'UTF-8'); ?></dd>
                        <?php endforeach; ?>
                    </dl>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
