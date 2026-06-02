<?php
// Path: public_html/recordings/feed.php
/**
 * Recordings — RSS / podcast feed (route: recordings.rss).
 *
 * @package   Portal\Recordings
 * @link      https://github.com/MWBMPartners/webMS-Intra/issues/264
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Auth;
use Portal\Core\Recordings;
use Portal\Core\Site;

Auth::ensureSession();
Auth::requireLogin();

$siteId   = Site::id();
$settings = App::settings();
$siteName = (string) ($settings['site']['name'] ?? 'Portal');
$author   = (string) ($settings['recordings']['podcast_author'] ?? '');

$scheme = (($_SERVER['HTTPS'] ?? '') !== '' && (string) ($_SERVER['HTTPS'] ?? '') !== 'off') ? 'https' : 'http';
$host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$baseUrl = $scheme . '://' . $host;

header('Content-Type: application/rss+xml; charset=utf-8');
header('Cache-Control: public, max-age=900');
echo Recordings::buildFeed($siteId, $siteName, $author, $baseUrl);
exit();
