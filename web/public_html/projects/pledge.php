<?php
// Path: public_html/projects/pledge.php
/**
 * Projects — pledge handler (public). Logged-in users auto-attribute;
 * anonymous users go through captcha. Pledges land unfulfilled —
 * /projects/fulfil flips them once payment arrives.
 *
 * @package   Portal\Projects
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/267
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Captcha;
use Portal\Core\Site;

Auth::ensureSession();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::verifyCsrf($_POST['csrf_token'] ?? '') === false) {
    http_response_code(400);
    exit('Bad request');
}

$db     = App::db();
$siteId = Site::id();
$slug   = (string) ($_POST['slug'] ?? '');

$project = null;
$stmt = $db->prepare('SELECT projectID, status FROM tblProject WHERE siteID = ? AND slug = ? AND isPublic = 1 LIMIT 1');
if ($stmt !== false) {
    $stmt->bind_param('is', $siteId, $slug);
    $stmt->execute();
    $project = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($project === null || (string) $project['status'] !== 'active') {
    $_SESSION['flash_msg']  = 'Project not accepting pledges.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /projects/view?slug=' . urlencode($slug));
    exit();
}

$donorId = Auth::check() === true ? (int) ($_SESSION['user_id'] ?? 0) : null;

// Anonymous submissions must clear captcha.
if ($donorId === null && Captcha::verify($_POST) === false) {
    $_SESSION['flash_msg']  = 'Captcha failed — please try again.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /projects/view?slug=' . urlencode($slug));
    exit();
}

$amountRaw  = (string) ($_POST['amount'] ?? '');
$clean      = preg_replace('/[^0-9.]/', '', $amountRaw) ?? '';
$amountPence = (int) round(((float) $clean) * 100);
if ($amountPence < 100) {
    $_SESSION['flash_msg']  = 'Minimum pledge is 1.00.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: /projects/view?slug=' . urlencode($slug));
    exit();
}

$donorName  = trim((string) ($_POST['donorName'] ?? ''));
$donorEmail = trim((string) ($_POST['donorEmail'] ?? ''));
if ($donorEmail !== '' && filter_var($donorEmail, FILTER_VALIDATE_EMAIL) === false) {
    $donorEmail = '';
}
$isAnon  = isset($_POST['isAnonymous']) === true ? 1 : 0;
$message = trim((string) ($_POST['message'] ?? ''));
if (mb_strlen($message) > 500) {
    $message = mb_substr($message, 0, 500);
}

$projectId = (int) $project['projectID'];
$stmt = $db->prepare(
    'INSERT INTO tblProjectPledge (projectID, donorID, donorName, donorEmail, amountPence, isAnonymous, message) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
);
if ($stmt !== false) {
    $nameOrNull  = $donorId === null && $donorName !== '' ? $donorName : null;
    $emailOrNull = $donorId === null && $donorEmail !== '' ? $donorEmail : null;
    $stmt->bind_param('iissiis', $projectId, $donorId, $nameOrNull, $emailOrNull, $amountPence, $isAnon, $message);
    $stmt->execute();
    $stmt->close();
}

$_SESSION['flash_msg']  = 'Thank you — your pledge has been recorded.';
$_SESSION['flash_type'] = 'success';
header('Location: /projects/view?slug=' . urlencode($slug));
exit();
