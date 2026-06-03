<?php
// Path: public_html/account/erasure-confirm.php
/**
 * Public confirmation landing — token in the email turns
 * pending_confirmation → pending_review.
 *
 * @package   Portal\Account
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/235
 */

declare(strict_types=1);

use Portal\Core\App;

$token = (string) ($_GET['token'] ?? '');
if ($token === '' || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
    http_response_code(400);
    header('Content-Type: text/plain');
    exit('Invalid confirmation link.');
}

$db = App::db();
$stmt = $db->prepare('UPDATE tblErasureRequest SET status = "pending_review", confirmedAt = NOW(), confirmToken = NULL WHERE confirmToken = ? AND status = "pending_confirmation" AND requestedAt >= DATE_SUB(NOW(), INTERVAL 1 DAY)');
$affected = 0;
if ($stmt !== false) {
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Erasure request</title>
<style>body{font-family:Arial,sans-serif;max-width:520px;margin:80px auto;padding:24px;text-align:center;color:#1b2330;}h1{color:#5e6ad2;}</style>
</head><body>
<?php if ($affected > 0): ?>
    <h1>Request confirmed</h1>
    <p>Your data erasure request has been filed. We'll process it within one month and email you once complete.</p>
<?php else: ?>
    <h1>Link expired</h1>
    <p>This confirmation link is invalid, expired (24-hour window), or already used. Submit a new request from your account page.</p>
<?php endif; ?>
<p><a href="/">Return to the portal</a></p>
</body></html>
