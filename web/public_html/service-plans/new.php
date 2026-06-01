<?php
// Path: public_html/service-plans/new.php
/**
 * Service Plans — create a new plan + seed default sections.
 *
 * @package   Portal\ServicePlans
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/262
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::verifyCsrf($_POST['csrf_token'] ?? '') === true) {
    $title       = trim((string) ($_POST['title'] ?? ''));
    $serviceDate = (string) ($_POST['serviceDate'] ?? '');
    $template    = (string) ($_POST['template'] ?? 'blank');

    if ($title !== '' && strtotime($serviceDate) !== false) {
        try {
            $db->begin_transaction();
            $stmt = $db->prepare(
                'INSERT INTO tblServicePlan (siteID, title, serviceDate, preparedByID) VALUES (?, ?, ?, ?)'
            );
            if ($stmt === false) {
                throw new \RuntimeException('Prepare failed');
            }
            $stmt->bind_param('issi', $siteId, $title, $serviceDate, $userId);
            $stmt->execute();
            $planId = (int) $stmt->insert_id;
            $stmt->close();

            // Seed default items per the chosen template.
            $defaults = match ($template) {
                'sabbath-service' => [
                    ['greeting',      'Welcome & opening prayer', 5],
                    ['song',          'Opening hymn',             5],
                    ['scripture',     'Scripture reading',        5],
                    ['prayer',        'Pastoral prayer',          10],
                    ['offering',      'Offering',                 5],
                    ['special_music', 'Special music',            5],
                    ['sermon',        'Sermon',                   30],
                    ['song',          'Closing hymn',             5],
                    ['prayer',        'Closing prayer / benediction', 3],
                ],
                'communion-service' => [
                    ['greeting',  'Welcome',           5],
                    ['song',      'Opening hymn',      5],
                    ['scripture', 'Communion reading', 5],
                    ['communion', 'Foot washing',      20],
                    ['communion', 'Bread & cup',       20],
                    ['song',      'Closing hymn',      5],
                    ['prayer',    'Benediction',       3],
                ],
                default => [],
            };
            if (count($defaults) > 0) {
                $insStmt = $db->prepare(
                    'INSERT INTO tblServicePlanItem (planID, sectionType, position, title, durationMin) VALUES (?, ?, ?, ?, ?)'
                );
                if ($insStmt !== false) {
                    foreach ($defaults as $idx => $row) {
                        $pos = $idx + 1;
                        $insStmt->bind_param('isisi', $planId, $row[0], $pos, $row[1], $row[2]);
                        $insStmt->execute();
                    }
                    $insStmt->close();
                }
            }
            $db->commit();
            header('Location: /service-plans/edit?id=' . $planId);
            exit();
        } catch (\Throwable $e) {
            $db->rollback();
            \Portal\Core\Logger::errorPlatform('ServicePlans', 'Warning', 'NEW', $e->getMessage(), '');
        }
    }
}

$pageTitle   = 'New service plan';
$pageSection = 'service-plans';
$breadcrumbs = ['Dashboard' => '/', 'Service Plans' => '/service-plans', 'New' => ''];
require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php';
$csrf = Auth::csrfToken();
?>

<h1 class="mb-3"><i class="fa-solid fa-plus me-2"></i>New service plan</h1>

<div class="card">
    <div class="card-body">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="mb-3">
                <label class="form-label">Title</label>
                <input type="text" name="title" class="form-control" required maxlength="255" placeholder="e.g. Sabbath Service — 12 July">
            </div>
            <div class="mb-3">
                <label class="form-label">Service date</label>
                <input type="date" name="serviceDate" class="form-control" required value="<?php echo date('Y-m-d', strtotime('next Saturday')); ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Template</label>
                <select name="template" class="form-select">
                    <option value="blank">Blank — start with no sections</option>
                    <option value="sabbath-service" selected>Standard Sabbath service (9 sections)</option>
                    <option value="communion-service">Communion service (7 sections)</option>
                </select>
                <div class="form-text">Each template seeds a recommended set of sections; you can edit, reorder, add and remove afterwards.</div>
            </div>
            <button type="submit" class="btn btn-primary">Create plan</button>
            <a href="/service-plans" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>
</div>

<?php require PORTAL_CORE . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
