<?php
// Path: public_html/praise/new.php
/**
 * Praise Reports — submission form.
 *
 * @package   Portal\Praise
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/260
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$flash = '';
$flashType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::verifyCsrf($_POST['csrf_token'] ?? '') === true) {
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $body    = trim((string) ($_POST['body'] ?? ''));
    $anon    = isset($_POST['isAnonymous']) ? 1 : 0;
    if ($subject === '' || $body === '') {
        $flash = 'Subject and body are required.';
        $flashType = 'danger';
    } else {
        try {
            $stmt = $db->prepare(
                "INSERT INTO tblPrayerRequests "
                . "(siteID, submitterID, subject, body, kind, visibility, status, isAnonymous, createdAt) "
                . "VALUES (?, ?, ?, ?, 'praise', 'congregation', 'active', ?, NOW())"
            );
            if ($stmt !== false) {
                $stmt->bind_param('iissi', $siteId, $userId, $subject, $body, $anon);
                $stmt->execute();
                $stmt->close();
                header('Location: /praise');
                exit();
            }
        } catch (\Throwable $e) {
            $flash = 'Could not save: ' . $e->getMessage();
            $flashType = 'danger';
        }
    }
}

$pageTitle   = 'Share Praise';
$pageSection = 'praise';
$breadcrumbs = ['Dashboard' => '/', 'Praise Reports' => '/praise', 'Share' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
?>

<h1 class="mb-3"><i class="fa-solid fa-hands-clapping me-2 text-success"></i>Share Praise</h1>

<?php if ($flash !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="mb-3">
                <label class="form-label">Title</label>
                <input type="text" name="subject" class="form-control" required maxlength="255" placeholder="Short title for your praise…">
            </div>
            <div class="mb-3">
                <label class="form-label">Your praise / gratitude</label>
                <textarea name="body" class="form-control" rows="5" required placeholder="Share what you're grateful for. Markdown supported — **bold**, *italic*, [links](url), lists."></textarea>
                <div class="form-text">Markdown: <code>**bold**</code>, <code>*italic*</code>, <code>[link](url)</code>, <code>- list</code></div>
            </div>
            <div class="mb-3 form-check">
                <input type="checkbox" name="isAnonymous" id="anon" class="form-check-input">
                <label for="anon" class="form-check-label">Post anonymously</label>
            </div>
            <button type="submit" class="btn btn-primary">Share praise</button>
            <a href="/praise" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
