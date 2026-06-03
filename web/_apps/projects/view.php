<?php
// Path: public_html/projects/view.php
/**
 * Projects — public project page: thermometer, narrative, updates, pledge form.
 *
 * @package   Portal\Projects
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/267
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Captcha;
use Portal\Core\Markdown;
use Portal\Core\Projects;
use Portal\Core\Site;

Auth::ensureSession();

$db     = App::db();
$siteId = Site::id();
$slug   = (string) ($_GET['slug'] ?? '');

$project = null;
$stmt = $db->prepare(
    'SELECT projectID, slug, title, description, targetAmountPence, currency, status, '
    . '       coverImagePath, startedAt, endsAt '
    . 'FROM tblProject WHERE siteID = ? AND slug = ? AND isPublic = 1 LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('is', $siteId, $slug);
    $stmt->execute();
    $project = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($project === null) {
    http_response_code(404);
    exit('Project not found');
}

$projectId = (int) $project['projectID'];
$raised    = Projects::raisedPence($projectId);
$pledged   = Projects::pledgedPence($projectId);
$target    = max(1, (int) $project['targetAmountPence']);
$pct       = min(100, (int) round(($raised / $target) * 100));
$cur       = (string) ($project['currency'] ?? 'GBP');
$sym       = match ($cur) { 'GBP' => '£', 'EUR' => '€', 'USD' => '$', default => $cur . ' ' };
$canPledge = (string) $project['status'] === 'active';

$updates = [];
$stmt = $db->prepare(
    'SELECT u.content, u.postedAt, p.fullName '
    . 'FROM tblProjectUpdate u LEFT JOIN tblUsers p ON p.userID = u.postedByID '
    . 'WHERE u.projectID = ? ORDER BY u.postedAt DESC LIMIT 20'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $updates[] = $r;
    }
    $stmt->close();
}

$pledgeCount = 0;
$stmt = $db->prepare('SELECT COUNT(*) FROM tblProjectPledge WHERE projectID = ?');
if ($stmt !== false) {
    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $stmt->bind_result($pledgeCount);
    $stmt->fetch();
    $stmt->close();
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$pageTitle   = (string) $project['title'];
$pageSection = 'projects';
$breadcrumbs = ['Dashboard' => '/', 'Projects' => '/projects', (string) $project['title'] => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-8">
        <h1 class="mb-2"><?php echo htmlspecialchars((string) $project['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
        <?php if ((string) $project['status'] !== 'active'): ?>
            <span class="badge bg-secondary mb-3"><?php echo htmlspecialchars((string) $project['status'], ENT_QUOTES, 'UTF-8'); ?></span>
        <?php endif; ?>
        <?php if (($project['coverImagePath'] ?? '') !== ''): ?>
            <img src="<?php echo htmlspecialchars((string) $project['coverImagePath'], ENT_QUOTES, 'UTF-8'); ?>" class="img-fluid rounded mb-3" alt="">
        <?php endif; ?>
        <div class="card mb-4"><div class="card-body">
            <?php echo Markdown::render((string) ($project['description'] ?? ''), ['allow_links' => true]); ?>
        </div></div>

        <?php if (count($updates) > 0): ?>
            <h3 class="mb-3"><i class="fa-solid fa-newspaper me-2"></i>Updates</h3>
            <?php foreach ($updates as $u): ?>
                <div class="card mb-2"><div class="card-body">
                    <div class="small text-muted mb-2">
                        <?php echo htmlspecialchars(date('j M Y', (int) strtotime((string) $u['postedAt'])), ENT_QUOTES, 'UTF-8'); ?>
                        <?php if (($u['fullName'] ?? '') !== ''): ?> · <?php echo htmlspecialchars((string) $u['fullName'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
                    </div>
                    <?php echo Markdown::render((string) $u['content'], ['allow_links' => true]); ?>
                </div></div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3"><div class="card-body">
            <div class="progress mb-2" style="height:24px;">
                <div class="progress-bar bg-success fs-6" role="progressbar" style="width: <?php echo $pct; ?>%;"><?php echo $pct; ?>%</div>
            </div>
            <p class="mb-1"><strong class="fs-4"><?php echo $sym . number_format($raised / 100, 2); ?></strong> raised</p>
            <p class="text-muted small mb-0">of <?php echo $sym . number_format($target / 100, 2); ?> goal · <?php echo $pledgeCount; ?> pledges
                <?php if ($pledged > 0): ?> · <?php echo $sym . number_format($pledged / 100, 2); ?> pending<?php endif; ?>
            </p>
            <p class="text-muted small mt-2 mb-0">
                <a href="/projects/contributors?slug=<?php echo urlencode($slug); ?>">See contributors →</a>
            </p>
        </div></div>

        <?php if ($canPledge === true): ?>
            <div class="card"><div class="card-body">
                <h5>Make a pledge</h5>
                <form method="post" action="/projects/pledge">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="slug" value="<?php echo htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="mb-2">
                        <label class="form-label small">Amount (<?php echo htmlspecialchars($cur, ENT_QUOTES, 'UTF-8'); ?>)</label>
                        <input type="text" class="form-control" name="amount" required placeholder="50.00">
                    </div>
                    <?php if (Auth::check() === false): ?>
                        <div class="mb-2">
                            <label class="form-label small">Your name (optional)</label>
                            <input type="text" class="form-control" name="donorName" maxlength="255">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small">Email (optional — for receipts)</label>
                            <input type="email" class="form-control" name="donorEmail" maxlength="255">
                        </div>
                    <?php endif; ?>
                    <div class="mb-2">
                        <label class="form-label small">Message (optional)</label>
                        <textarea class="form-control" name="message" rows="2" maxlength="500"></textarea>
                    </div>
                    <div class="form-check mb-2">
                        <input type="checkbox" class="form-check-input" id="anon" name="isAnonymous" value="1">
                        <label class="form-check-label small" for="anon">Show me as Anonymous in the contributors list</label>
                    </div>
                    <?php if (Auth::check() === false): ?>
                        <?php echo Captcha::scriptTag() . Captcha::widget(); ?>
                    <?php endif; ?>
                    <button class="btn btn-primary w-100 mt-2" type="submit"><i class="fa-solid fa-heart me-1"></i>Pledge</button>
                </form>
            </div></div>
        <?php endif; ?>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
