<?php
// Path: public_html/directory/profile.php
/**
 * Member Directory — single profile, respecting per-field visibility.
 *
 * @package   Portal\Directory
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/261
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Markdown;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$db     = App::db();
$siteId = Site::id();
$viewer = (int) ($_SESSION['user_id'] ?? 0);
$isAdmin = App::isAdmin();
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: /directory');
    exit();
}

$u = null;
$stmt = $db->prepare(
    'SELECT userID, fullName, email, displayBio, displayPhone, displayAddress, displayPhoto, '
    . '       visibilityName, visibilityRoles, visibilityEmail, visibilityPhone, visibilityAddress, '
    . '       visibilityBio, visibilityPhoto '
    . 'FROM tblUsers WHERE userID = ? AND siteID = ? LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $id, $siteId);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($u === null) {
    http_response_code(404);
    exit('Not found');
}

$can = static function (string $level) use ($viewer, $id, $isAdmin): bool {
    if ($viewer === $id || $isAdmin === true) {
        return true;
    }
    return $level === 'members' || $level === 'public';
};

if ($can($u['visibilityName']) === false) {
    http_response_code(403);
    exit('Profile is private.');
}

$pageTitle   = $u['fullName'];
$pageSection = 'directory';
$breadcrumbs = ['Dashboard' => '/', 'Directory' => '/directory', $u['fullName'] => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-3"><?php echo htmlspecialchars((string) $u['fullName'], ENT_QUOTES, 'UTF-8'); ?></h1>

<div class="card">
    <div class="card-body">
        <?php if ($can($u['visibilityBio']) === true && trim((string) $u['displayBio']) !== ''): ?>
            <div class="portal-markdown mb-3"><?php echo Markdown::render((string) $u['displayBio'], ['allow_links' => true]); ?></div>
        <?php endif; ?>
        <dl class="row small mb-0">
            <?php if ($can($u['visibilityEmail']) === true && $u['email'] !== null): ?>
                <dt class="col-sm-3">Email</dt>
                <dd class="col-sm-9"><a href="mailto:<?php echo htmlspecialchars((string) $u['email'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $u['email'], ENT_QUOTES, 'UTF-8'); ?></a></dd>
            <?php endif; ?>
            <?php if ($can($u['visibilityPhone']) === true && $u['displayPhone'] !== null): ?>
                <dt class="col-sm-3">Phone</dt>
                <dd class="col-sm-9"><?php echo htmlspecialchars((string) $u['displayPhone'], ENT_QUOTES, 'UTF-8'); ?></dd>
            <?php endif; ?>
            <?php if ($can($u['visibilityAddress']) === true && $u['displayAddress'] !== null): ?>
                <dt class="col-sm-3">Address</dt>
                <dd class="col-sm-9"><?php echo nl2br(htmlspecialchars((string) $u['displayAddress'], ENT_QUOTES, 'UTF-8')); ?></dd>
            <?php endif; ?>
        </dl>
        <?php if ($viewer === $id): ?>
            <a href="/directory/my-settings" class="btn btn-outline-primary btn-sm mt-2">Edit my profile</a>
        <?php endif; ?>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
