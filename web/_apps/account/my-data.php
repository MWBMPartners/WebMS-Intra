<?php
// Path: public_html/account/my-data.php
/**
 * Account — "What we hold about you" inventory + JSON download +
 * erasure-request kickoff.
 *
 * @package   Portal\Account
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/235
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\GdprEraser;

Auth::ensureSession();
Auth::requireLogin();

$userId   = (int) ($_SESSION['user_id'] ?? 0);
$inventory = GdprEraser::inventory($userId);

if (isset($_GET['download']) === true) {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="my-data-' . $userId . '.json"');
    echo json_encode([
        'generatedAt' => date('c'),
        'userID'      => $userId,
        'inventory'   => $inventory,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit();
}

$flashMsg  = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
$csrf = Auth::csrfToken();

$pageTitle   = 'My data';
$pageSection = 'account';
$breadcrumbs = ['Dashboard' => '/', 'My Account' => '/account', 'My data' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<?php if ($flashMsg !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType !== '' ? $flashType : 'info', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<h1 class="mb-3"><i class="fa-solid fa-database me-2"></i>My data</h1>
<p class="text-secondary">Under UK GDPR Article 15 you have the right to a copy of personal data we hold about you, and under Article 17 you can request its deletion.</p>

<div class="card mb-3">
    <div class="card-header"><strong>What we hold</strong></div>
    <div class="card-body">
        <?php if (count($inventory) === 0): ?>
            <p class="text-muted mb-0">Nothing tracked outside your basic account row.</p>
        <?php else: ?>
            <div class="portal-data-list">
                <?php foreach ($inventory as $row): ?>
                    <div class="row py-2 border-bottom small">
                        <div class="col-md-4"><code><?php echo htmlspecialchars((string) $row['table'], ENT_QUOTES, 'UTF-8'); ?></code></div>
                        <div class="col-md-1 text-end"><strong><?php echo (int) $row['rows']; ?></strong></div>
                        <div class="col-md-2"><span class="badge bg-<?php echo $row['action'] === 'delete' ? 'danger' : 'secondary'; ?>"><?php echo htmlspecialchars((string) $row['action'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div class="col-md-5 text-muted"><?php echo htmlspecialchars((string) ($row['reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="d-flex gap-2">
    <a class="btn btn-outline-primary" href="/account/my-data?download=1"><i class="fa-solid fa-download me-1"></i>Download as JSON</a>
    <a class="btn btn-outline-danger" href="/account/erasure-request"><i class="fa-solid fa-trash me-1"></i>Request erasure</a>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
