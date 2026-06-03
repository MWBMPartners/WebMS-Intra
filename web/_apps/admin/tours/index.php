<?php
// Path: public_html/admin/tours/index.php
/**
 * -----------------------------------------------------------------------------
 * Admin — Tour Authoring 🎯
 * -----------------------------------------------------------------------------
 * "What's new" tour engine admin interface. List tours, see completion stats,
 * trigger a re-show to all users (e.g. after a release).
 *
 * Tour playback for users lives in /assets/js/portal-tour.js. Tour definitions
 * are stored in tblTours; per-user completion in tblUserTours.
 *
 * @package   Portal\Admin
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/237
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    http_response_code(403);
    exit('Forbidden');
}

$db = App::db();
$tours = [];
try {
    $rs = $db->query('SELECT tourKey, title, version, isActive, createdAt FROM tblTours ORDER BY createdAt DESC');
    if ($rs !== false) {
        while ($r = $rs->fetch_assoc()) {
            $tours[] = $r;
        }
        $rs->free();
    }
} catch (\Throwable $ignored) {
    // tblTours may not be present until migration 069 has run.
}

$pageTitle   = 'Tours';
$pageSection = 'admin';
$breadcrumbs = ['Dashboard' => '/', 'Admin' => '/admin', 'Tours' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
?>

<h1 class="mb-3"><i class="fa-solid fa-route me-2"></i>Tour Authoring</h1>
<p class="text-muted">"What's new" tours shown to users on first login and after portal upgrades.</p>

<div class="card mb-3">
    <div class="card-body">
        <h2 class="h5">Defined tours</h2>
        <?php if (count($tours) === 0): ?>
            <p class="text-muted mb-0">No tours defined yet. The welcome tour seeded by migration 069 should appear here after install.</p>
        <?php else: ?>
            <table class="table table-sm">
                <thead><tr><th>Key</th><th>Title</th><th>Version</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($tours as $t): ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($t['tourKey'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                            <td><?php echo htmlspecialchars($t['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($t['version'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo ((int) $t['isActive']) === 1 ? 'Active' : 'Hidden'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="alert alert-info">
    <strong>v1 scope:</strong> Tour definitions are seeded via SQL (see <code>web/_sql/069_tours.sql</code>).
    Full in-portal authoring UI (drag-target selector picker, preview, etc.) is a planned v2 enhancement.
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
