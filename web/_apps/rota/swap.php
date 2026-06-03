<?php
// Path: public_html/rota/swap.php
/**
 * Rota — Request a swap (the assignee asks another member to cover their duty).
 *
 * @package   Portal\Rota
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/256
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

$slotId = (int) ($_GET['slot'] ?? 0);
if ($slotId <= 0) {
    header('Location: /rota');
    exit();
}

// Load the slot — confirm it's mine
$slot = null;
$stmt = $db->prepare(
    'SELECT s.slotID, s.slotDate, s.assignedToID, r.name AS roleName '
    . 'FROM tblRotaSlot s JOIN tblRotaRoleType r ON r.roleTypeID = s.roleTypeID '
    . 'WHERE s.slotID = ? AND s.siteID = ? LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $slotId, $siteId);
    $stmt->execute();
    $slot = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($slot === null || (int) $slot['assignedToID'] !== $userId) {
    http_response_code(403);
    exit('You can only request swaps for your own duties.');
}

$flash = '';
$flashType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::verifyCsrf($_POST['csrf_token'] ?? '') === true) {
    $targetUserID = (int) ($_POST['targetUserID'] ?? 0);
    $message      = trim((string) ($_POST['requestMessage'] ?? ''));
    $target       = $targetUserID > 0 ? $targetUserID : null;
    try {
        $stmt = $db->prepare(
            'INSERT INTO tblRotaSwapRequest (slotID, requestedByID, targetUserID, requestMessage) '
            . 'VALUES (?, ?, ?, ?)'
        );
        if ($stmt !== false) {
            $stmt->bind_param('iiis', $slotId, $userId, $target, $message);
            $stmt->execute();
            $stmt->close();
            $flash = 'Swap request sent. The other member will be notified.';
            $flashType = 'success';
        }
    } catch (\Throwable $e) {
        $flash = 'Could not send request: ' . $e->getMessage();
        $flashType = 'danger';
    }
}

// Other users (excluding self) for the dropdown
$users = [];
$uStmt = $db->prepare('SELECT userID, fullName FROM tblUsers WHERE userID <> ? AND isActive = 1 ORDER BY fullName');
if ($uStmt !== false) {
    $uStmt->bind_param('i', $userId);
    $uStmt->execute();
    $rs = $uStmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $users[] = $r;
    }
    $uStmt->close();
}

$pageTitle   = 'Request Swap';
$pageSection = 'rota';
$breadcrumbs = ['Dashboard' => '/', 'Duty Roster' => '/rota', 'Request Swap' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
$allowOpenSwap = (string) (App::settings()['rota']['allow_open_swap'] ?? '1') === '1';
?>

<h1 class="mb-3"><i class="fa-solid fa-arrows-rotate me-2"></i>Request swap</h1>

<?php if ($flash !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <p>Duty: <strong><?php echo htmlspecialchars((string) $slot['roleName'], ENT_QUOTES, 'UTF-8'); ?></strong> on <?php echo htmlspecialchars((string) $slot['slotDate'], ENT_QUOTES, 'UTF-8'); ?></p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="mb-2">
                <label class="form-label small">Send to</label>
                <select name="targetUserID" class="form-select form-select-sm">
                    <?php if ($allowOpenSwap === true): ?>
                        <option value="0">Open swap — anyone can cover</option>
                    <?php endif; ?>
                    <?php foreach ($users as $u): ?>
                        <option value="<?php echo (int) $u['userID']; ?>"><?php echo htmlspecialchars((string) $u['fullName'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-2">
                <label class="form-label small">Message (optional)</label>
                <textarea name="requestMessage" class="form-control form-control-sm" rows="3" maxlength="500" placeholder="Reason / details…"></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Send swap request</button>
            <a href="/rota" class="btn btn-outline-secondary btn-sm">Cancel</a>
        </form>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
