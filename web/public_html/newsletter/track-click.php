<?php
// Path: public_html/newsletter/track-click.php
/**
 * Newsletter — click tracker. Updates the row when tracking is enabled,
 * then 302-redirects to the original URL. Only forwards http/https.
 *
 * @package   Portal\Newsletter
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/269
 */

declare(strict_types=1);

use Portal\Core\App;

$newsId = (int) ($_GET['n'] ?? 0);
$recId  = (int) ($_GET['r'] ?? 0);
$target = (string) ($_GET['u'] ?? '');

if ($target === '' || filter_var($target, FILTER_VALIDATE_URL) === false) {
    http_response_code(400);
    exit('Bad request');
}
$scheme = parse_url($target, PHP_URL_SCHEME);
if ($scheme !== 'http' && $scheme !== 'https') {
    http_response_code(400);
    exit('Bad request');
}

$settings = App::settings()['newsletter'] ?? [];
if ((string) ($settings['trackClicks'] ?? '0') === '1' && $newsId > 0 && $recId > 0) {
    $db = App::db();
    $stmt = $db->prepare('UPDATE tblNewsletterRecipient SET clickedAt = COALESCE(clickedAt, NOW()) WHERE recipientID = ? AND newsletterID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('ii', $recId, $newsId);
        $stmt->execute();
        $stmt->close();
    }
}

header('Location: ' . $target, true, 302);
exit();
