<?php
// Path: public_html/projects/fulfil.php
/**
 * Projects — admin marks a pledge fulfilled. Optionally creates a matching
 * tblGivingEntry when the Giving app is installed and a category is picked.
 *
 * @package   Portal\Projects
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/267
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Logger;
use Portal\Core\Projects;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (App::isAdmin() === false) {
    Router::renderError(403);
    return;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$siteId          = Site::id();
$userId          = (int) ($_SESSION['user_id'] ?? 0);
$pledgeId        = (int) ($_POST['pledgeID'] ?? 0);
$givingCategory  = (int) ($_POST['givingCategoryID'] ?? 0);

if ($pledgeId > 0 && Projects::fulfilPledge($pledgeId, $siteId, $userId, $givingCategory > 0 ? $givingCategory : null) === true) {
    Logger::activity('ProjectPledgeFulfilled', 'Fulfilled pledge #' . $pledgeId, $userId);
    $_SESSION['flash_msg']  = 'Pledge marked fulfilled.';
    $_SESSION['flash_type'] = 'success';
} else {
    $_SESSION['flash_msg']  = 'Could not fulfil pledge.';
    $_SESSION['flash_type'] = 'danger';
}

header('Location: /projects/manage');
exit();
