<?php
// Path: public_html/giving/hmrc-export.php
/**
 * Giving — HMRC Gift Aid schedule CSV export (treasurer only).
 *
 * @package   Portal\Giving
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/266
 */

declare(strict_types=1);

use Portal\Core\Auth;
use Portal\Core\Giving;
use Portal\Core\Logger;
use Portal\Core\Router;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();
if (Giving::canManage() === false) {
    Router::renderError(403);
    return;
}

$siteId = Site::id();
$from = (string) ($_GET['from'] ?? date('Y-01-01'));
$to   = (string) ($_GET['to']   ?? date('Y-12-31'));
if (strtotime($from) === false || strtotime($to) === false) {
    http_response_code(400);
    exit('Bad date range');
}

$csv = Giving::buildHmrcCsv($siteId, $from, $to);

Logger::activity('GivingHmrcExport', 'Exported HMRC CSV ' . $from . ' → ' . $to, (int) ($_SESSION['user_id'] ?? 0));

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="gift-aid-' . $from . '-to-' . $to . '.csv"');
echo $csv;
exit();
