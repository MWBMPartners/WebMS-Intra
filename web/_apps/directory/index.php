<?php
// Path: public_html/directory/index.php
/**
 * Member Directory — searchable list with per-field visibility filtering.
 *
 * @package   Portal\Directory
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/261
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
$isAdmin = App::isAdmin();

$q = trim((string) ($_GET['q'] ?? ''));

// 🔍 Search — name LIKE only (privacy by default). Result rows respect
//    per-field visibility at display time.
$users = [];
$sql = 'SELECT userID, fullName, displayBio, displayPhone, emailAddress AS email, displayAddress, displayPhoto, '
     . '       visibilityName, visibilityRoles, visibilityEmail, visibilityPhone, visibilityAddress, '
     . '       visibilityBio, visibilityPhoto '
     . 'FROM tblUsers WHERE isActive = 1';
$types = '';
$params = [];
if ($q !== '') {
    $sql .= ' AND fullName LIKE ?';
    $types .= 's';
    $params[] = '%' . $q . '%';
}
$sql .= " AND (visibilityName IN ('members','public') OR userID = ?)";
$types .= 'i';
$params[] = $userId;
$sql .= ' ORDER BY fullName LIMIT 200';

$stmt = $db->prepare($sql);
if ($stmt !== false) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $users[] = $r;
    }
    $stmt->close();
}

/**
 * Returns true if the given visibility level grants the current viewer access.
 * Owner + admins always see everything.
 */
$can = function (string $level, int $ownerID) use ($userId, $isAdmin): bool {
    if ($ownerID === $userId || $isAdmin === true) {
        return true;
    }
    return $level === 'members' || $level === 'public';
};

$pageTitle   = 'Member Directory';
$pageSection = 'directory';
$breadcrumbs = ['Dashboard' => '/', 'Directory' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
    <div>
        <h1 class="mb-1"><i class="fa-solid fa-address-book me-2"></i>Member Directory</h1>
        <p class="text-secondary mb-0">Find members and their roles. You control what others see about you.</p>
    </div>
    <a href="/directory/my-settings" class="btn btn-outline-primary btn-sm">My profile</a>
</div>

<form method="get" class="mb-3">
    <div class="input-group">
        <input type="text" name="q" class="form-control" placeholder="Search by name…" value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i></button>
    </div>
</form>

<?php if (count($users) === 0): ?>
    <div class="alert alert-info">No members match.</div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($users as $u):
            $uid = (int) $u['userID'];
        ?>
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-body">
                        <h2 class="h6 mb-1">
                            <a href="/directory/profile?id=<?php echo $uid; ?>" class="text-decoration-none">
                                <?php echo htmlspecialchars((string) $u['fullName'], ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </h2>
                        <?php if ($can($u['visibilityEmail'], $uid) === true && $u['email'] !== null): ?>
                            <p class="small text-muted mb-1"><i class="fa-solid fa-envelope me-1"></i><?php echo htmlspecialchars((string) $u['email'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>
                        <?php if ($can($u['visibilityPhone'], $uid) === true && $u['displayPhone'] !== null): ?>
                            <p class="small text-muted mb-1"><i class="fa-solid fa-phone me-1"></i><?php echo htmlspecialchars((string) $u['displayPhone'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
