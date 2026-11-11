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
// 📅 Each end must be a REAL day (#891, #892). This used to ask strtotime(),
//    which accepts '2026-02-31' by rolling it to 3 March, and the export then
//    ran `donatedAt BETWEEN ? AND ?` with the impossible text -- which MySQL
//    8.0 and 8.4 answer with NO gifts and MySQL 9.7 and MariaDB answer WITH
//    them (measured). So the same link made an empty Gift Aid file on one
//    server and a full one on another. Giving::isRealDay() is the one test;
//    a refusal is this page's own 400, and nothing reaches the query.
$from = $_GET['from'] ?? date('Y-01-01');
$to   = $_GET['to']   ?? date('Y-12-31');
if (Giving::isRealDay($from) === false || Giving::isRealDay($to) === false) {
    http_response_code(400);
    exit('Bad date range');
}

$csv = Giving::buildHmrcCsv($siteId, $from, $to);

Logger::activity('GivingHmrcExport', 'Exported HMRC CSV ' . $from . ' → ' . $to, (int) ($_SESSION['user_id'] ?? 0));

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="gift-aid-' . $from . '-to-' . $to . '.csv"');
echo $csv;
exit();
