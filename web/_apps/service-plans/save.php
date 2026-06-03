<?php
// Path: public_html/service-plans/save.php
/**
 * Service Plans — POST handler for plan metadata.
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
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$db     = App::db();
$siteId = Site::id();
$planId = (int) ($_POST['planID'] ?? 0);
$title  = trim((string) ($_POST['title'] ?? ''));
$date   = (string) ($_POST['serviceDate'] ?? '');
$status = (string) ($_POST['status'] ?? 'draft');

if (in_array($status, ['draft','published','archived'], true) === false) {
    $status = 'draft';
}

if ($planId > 0 && $title !== '' && strtotime($date) !== false) {
    $stmt = $db->prepare(
        'UPDATE tblServicePlan SET title = ?, serviceDate = ?, status = ? WHERE planID = ? AND siteID = ?'
    );
    if ($stmt !== false) {
        $stmt->bind_param('sssii', $title, $date, $status, $planId, $siteId);
        $stmt->execute();
        $stmt->close();
    }
}

header('Location: /service-plans/edit?id=' . $planId);
exit();
