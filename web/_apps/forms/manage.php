<?php
// Path: _apps/forms/manage.php
/**
 * -----------------------------------------------------------------------------
 * Forms Builder — Admin Form List 🛡️
 * -----------------------------------------------------------------------------
 * Admin-only list of every form defined for this site — status, audience,
 * response count, and lifecycle actions (publish/unpublish/close), plus the
 * public share link + QR when a form's audience allows it and a token has
 * been generated.
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
use Portal\Core\Site;

$pageTitle   = 'Manage Forms';
$pageSection = 'forms';
$breadcrumbs = ['Dashboard' => '/', 'Forms' => '/forms', 'Manage' => ''];

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

$db     = App::db();
$siteId = Site::id();

// 📋 Every form for this site, newest first, with a response count.
$forms = [];
$stmt = $db->prepare(
    'SELECT f.*, COALESCE(rc.cnt, 0) AS responseCount '
    . 'FROM tblForms f '
    . 'LEFT JOIN (SELECT formID, COUNT(*) AS cnt FROM tblFormResponses GROUP BY formID) rc ON rc.formID = f.formID '
    . 'WHERE f.siteID = ? ORDER BY f.createdAt DESC'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $forms[] = $row;
    }
    $stmt->close();
}

$allowPublicSite = (string) (App::settings('forms.allowPublic') ?? 'false') === 'true';
$siteBaseUrl = rtrim((string) (App::settings('site.url') ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? ''))), '/');

$csrf = Auth::csrfToken();

require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-gauge-high me-2"></i>Manage Forms</h1>
        <p class="text-secondary mb-0">Build, publish, and review responses for your site's forms.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="/forms" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i> Back</a>
        <a href="/forms/edit" class="btn btn-primary"><i class="fa-solid fa-plus me-1"></i> New form</a>
    </div>
</div>

<?php if ($allowPublicSite === false): ?>
    <div class="alert alert-light border small mb-3">
        <i class="fa-solid fa-circle-info me-1"></i>
        Public sharing is currently OFF for this site. Forms can still be filled in internally.
        <a href="/settings">Enable public forms in Settings</a>.
    </div>
<?php endif; ?>

<?php if (count($forms) === 0): ?>
    <div class="alert alert-light border">No forms yet — click "New form" to create one.</div>
<?php else: ?>
    <div class="portal-data-list">
        <div class="portal-data-row portal-data-header d-none d-md-flex">
            <div class="col-md-3">Title</div>
            <div class="col-md-2">Status</div>
            <div class="col-md-2">Audience</div>
            <div class="col-md-1">Responses</div>
            <div class="col-md-2">Updated</div>
            <div class="col-md-2 text-end">Actions</div>
        </div>

        <?php foreach ($forms as $form):
            $formId = (int) $form['formID'];
            $status = (string) $form['status'];
            $audience = (string) $form['audience'];
            $titleSafe = htmlspecialchars((string) $form['title'], ENT_QUOTES, 'UTF-8');
            $statusBadge = match ($status) {
                'published' => 'bg-success-subtle text-success-emphasis',
                'closed'    => 'bg-secondary-subtle text-secondary-emphasis',
                default     => 'bg-warning-subtle text-warning-emphasis',
            };
            $token = (string) ($form['publicToken'] ?? '');
            $showPublicLink = $token !== '' && in_array($audience, ['public', 'both'], true) && $allowPublicSite === true;
            $publicUrl = $showPublicLink === true ? ($siteBaseUrl . '/f/' . $token) : '';
        ?>
            <div class="portal-data-row flex-wrap">
                <div class="col-12 col-md-3">
                    <strong><a href="/forms/edit?id=<?php echo $formId; ?>"><?php echo $titleSafe; ?></a></strong>
                </div>
                <div class="col-6 col-md-2">
                    <span class="badge <?php echo $statusBadge; ?>"><?php echo htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="col-6 col-md-2 small text-capitalize"><?php echo htmlspecialchars($audience, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="col-6 col-md-1"><?php echo (int) $form['responseCount']; ?></div>
                <div class="col-6 col-md-2 small text-muted">
                    <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime((string) $form['updatedAt'])), ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="col-12 col-md-2 text-md-end">
                    <div class="btn-group btn-group-sm" role="group">
                        <a href="/forms/edit?id=<?php echo $formId; ?>" class="btn btn-outline-secondary" title="Edit">
                            <i class="fa-solid fa-pen"></i>
                        </a>
                        <a href="/forms/responses?id=<?php echo $formId; ?>" class="btn btn-outline-secondary" title="Responses">
                            <i class="fa-solid fa-inbox"></i>
                        </a>
                        <?php if ($status !== 'published'): ?>
                            <form method="post" action="/forms/publish" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="formID" value="<?php echo $formId; ?>">
                                <input type="hidden" name="action" value="publish">
                                <button type="submit" class="btn btn-outline-success" title="Publish"><i class="fa-solid fa-check"></i></button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="/forms/publish" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="formID" value="<?php echo $formId; ?>">
                                <input type="hidden" name="action" value="unpublish">
                                <button type="submit" class="btn btn-outline-warning" title="Unpublish"><i class="fa-solid fa-pause"></i></button>
                            </form>
                        <?php endif; ?>
                        <?php if ($status !== 'closed'): ?>
                            <form method="post" action="/forms/publish" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="formID" value="<?php echo $formId; ?>">
                                <input type="hidden" name="action" value="close">
                                <button type="submit" class="btn btn-outline-danger" title="Close" data-confirm="Close this form? It will stop accepting responses.">
                                    <i class="fa-solid fa-box-archive"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($showPublicLink === true): ?>
                    <div class="col-12 mt-2">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <img src="/qr.php?content=<?php echo urlencode($publicUrl); ?>&amp;size=64" width="64" height="64" alt="QR code for the public form link" class="border rounded">
                            <input type="text" class="form-control form-control-sm" style="max-width:360px;" readonly value="<?php echo htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8'); ?>" onclick="this.select();">
                            <form method="post" action="/forms/publish" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="formID" value="<?php echo $formId; ?>">
                                <input type="hidden" name="action" value="rotate">
                                <button type="submit" class="btn btn-sm btn-outline-secondary" data-confirm="Rotate the link? The old link/QR code will stop working.">
                                    <i class="fa-solid fa-rotate me-1"></i>Rotate link
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
