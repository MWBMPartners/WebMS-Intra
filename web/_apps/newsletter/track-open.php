<?php
// Path: public_html/newsletter/track-open.php
/**
 * Newsletter — 1×1 pixel open tracker. Only updates the row when
 * `newsletter.trackOpens = 1`. Always emits a transparent PNG.
 *
 * @package   Portal\Newsletter
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/269
 */

declare(strict_types=1);

use Portal\Core\App;

$newsId = (int) ($_GET['n'] ?? 0);
$recId  = (int) ($_GET['r'] ?? 0);

$settings = App::settings()['newsletter'] ?? [];
if ((string) ($settings['trackOpens'] ?? '0') === '1' && $newsId > 0 && $recId > 0) {
    $db = App::db();
    $stmt = $db->prepare('UPDATE tblNewsletterRecipient SET openedAt = COALESCE(openedAt, NOW()) WHERE recipientID = ? AND newsletterID = ?');
    if ($stmt !== false) {
        $stmt->bind_param('ii', $recId, $newsId);
        $stmt->execute();
        $stmt->close();
    }
}

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
// 1×1 transparent PNG (base64).
echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
exit();
