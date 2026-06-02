<?php
// Path: public_html/giving/my-statement.php
/**
 * Giving — generate + stream the year-end statement PDF for the current user.
 *
 * @package   Portal\Giving
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/266
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Giving;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$siteId = Site::id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$year   = (int) ($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) {
    $year = (int) date('Y');
}

$path = Giving::renderStatementPdf($siteId, $userId, $year);
if ($path === false || is_file($path) === false) {
    http_response_code(500);
    header('Content-Type: text/plain');
    exit('Failed to generate statement.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="giving-statement-' . $year . '.pdf"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit();
