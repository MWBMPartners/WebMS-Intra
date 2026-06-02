<?php
// Path: public_html/newsletter/segments.php
/**
 * Newsletter — segment CRUD. Inline add/delete; rule editor accepts a
 * comma-separated list of role keys (compiled into {"roles":[…]} JSON).
 *
 * @package   Portal\Newsletter
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/269
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}

$db     = App::db();
$siteId = Site::id();

$segments = [];
$stmt = $db->prepare('SELECT segmentID, name, ruleJson FROM tblNewsletterSegment WHERE siteID = ? ORDER BY name');
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $segments[] = $r;
    }
    $stmt->close();
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'Newsletter Segments';
$pageSection = 'newsletter';
$breadcrumbs = ['Dashboard' => '/', 'Newsletter' => '/newsletter', 'Segments' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-users me-2"></i>Newsletter segments</h1>
<p class="text-secondary">Reusable recipient filters. Leave a segment off the newsletter to send to all opted-in members.</p>

<div class="card mb-4">
    <div class="card-header"><strong>Add a segment</strong></div>
    <div class="card-body">
        <form method="post" action="/newsletter/segments/save" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="add">
            <div class="col-md-4">
                <label class="form-label small">Name</label>
                <input type="text" class="form-control form-control-sm" name="name" required maxlength="255" placeholder="Volunteers">
            </div>
            <div class="col-md-6">
                <label class="form-label small">Role keys (comma-separated, leave blank for all members)</label>
                <input type="text" class="form-control form-control-sm" name="roles" placeholder="volunteer, deacon, elder">
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-primary btn-sm w-100" type="submit"><i class="fa-solid fa-plus me-1"></i>Add</button>
            </div>
        </form>
    </div>
</div>

<?php if (count($segments) === 0): ?>
    <div class="alert alert-info">No segments yet. Add one above.</div>
<?php else: ?>
    <div class="card"><div class="card-body">
        <div class="portal-data-list">
            <?php foreach ($segments as $s):
                $rule = json_decode((string) ($s['ruleJson'] ?? '{}'), true);
                if (is_array($rule) === false) { $rule = []; }
                $roles = isset($rule['roles']) === true && is_array($rule['roles']) === true ? implode(', ', $rule['roles']) : '(all members)';
            ?>
                <div class="row py-2 border-bottom align-items-center">
                    <div class="col-md-4"><strong><?php echo htmlspecialchars((string) $s['name'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    <div class="col-md-6 small text-muted"><?php echo htmlspecialchars($roles, ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="col-md-2 text-end">
                        <form method="post" action="/newsletter/segments/save" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="segmentID" value="<?php echo (int) $s['segmentID']; ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Delete this segment?">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div></div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
