<?php
// Path: _apps/recordings/podcast.php
/**
 * -----------------------------------------------------------------------------
 * Recordings — Public podcast feed 🎙📡
 * -----------------------------------------------------------------------------
 * PUBLIC — no login required (unlike `recordings/feed.php`, which requires
 * an authenticated session and so cannot be pointed at by an external
 * podcast app). Gated instead by a per-site unguessable token in the query
 * string (`?token=…`), compared with `hash_equals()` against
 * `Portal\Core\Recordings::podcastToken()` — a value lazily generated and
 * stored (encrypted) in `tblSettings` under `recordings.podcast_token` the
 * first time anything calls that method. Deliberately NOT seeded by any
 * migration/full_schema.sql — this is runtime data, not schema.
 *
 * VISIBILITY GATE: only recordings with `isPublished = 1` on the active
 * site are ever listed — the SAME real visibility column `recordings/
 * stream.php` and the existing (login-gated) `recordings/feed.php` already
 * gate on. Nothing role-restricted or unpublished is ever exposed here.
 *
 * ENCLOSURE REACHABILITY (KNOWN LIMITATION — see delivery report): an
 * episode backed by `externalUrl` (already a public URL) is linked
 * directly and is fully fetchable by any external podcast client. A
 * self-hosted episode (`filePath`, no externalUrl) is linked via
 * `/recordings/stream?id=…` — but that route is seeded `isProtected = 1`
 * in `tblRoutes`, so `Router::handleSpecialRoutes()` enforces
 * `Auth::requireLogin()` BEFORE `stream.php` even runs, regardless of
 * anything that file itself could check. An external podcast app (no
 * portal session) therefore cannot actually fetch a self-hosted
 * enclosure today — only externalUrl-backed episodes are truly
 * podcast-app-reachable. Flipping `recordings/stream` to public would
 * need its own migration + full_schema.sql edit (route protection is a
 * schema seed) and a matching in-file token/session gate — deliberately
 * left as a follow-up rather than folded into this pass, which scopes
 * full_schema.sql to the ONE new `recordings/podcast` route.
 *

 * Emits RSS 2.0 + the iTunes podcast namespace (`xmlns:itunes`) —
 * `Content-Type: application/rss+xml; charset=utf-8`.
 *
 * @package   Portal\Recordings
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/264
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use Portal\Core\App;
use Portal\Core\Recordings;
use Portal\Core\Site;

// -----------------------------------------------------------------------------
// 🔑 Token gate (constant-time compare). An empty/unset stored token — the
// state before anything has ever called podcastToken() for this site —
// ALWAYS 403s, same "fail closed" convention as every cron_token gate in
// this codebase.
// -----------------------------------------------------------------------------
$siteId        = Site::id();
$expectedToken = Recordings::podcastToken($siteId);
$incomingToken = (string) ($_GET['token'] ?? '');
if ($expectedToken === '' || hash_equals($expectedToken, $incomingToken) === false) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden');
}

$db = App::db();

// -----------------------------------------------------------------------------
// 🌐 Scheme + host resolution — same pattern as recordings/feed.php and
// cron/asset-reminders.php's assetReminderUrl(). $_SERVER values here are
// set by the webserver from the connection itself, never from a request
// body.
// -----------------------------------------------------------------------------
$https  = (string) ($_SERVER['HTTPS'] ?? '');
$scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
$host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$baseUrl = $scheme . '://' . $host;

// -----------------------------------------------------------------------------
// ⚙️ Channel metadata — falls back sensibly when a site hasn't configured
// recordings.podcast_author / branding.
// -----------------------------------------------------------------------------
$siteName = (string) (Site::branding('name') ?? App::settings('site.name') ?? 'Portal');
$tagline  = trim((string) (App::settings('site.tagline') ?? ''));
$description = $tagline !== '' ? $tagline : ('Recordings from ' . $siteName . '.');

$author = trim((string) (App::settings('recordings.podcast_author') ?? ''));
if ($author === '') {
    $author = $siteName;
}

$ownerEmail = trim((string) (App::settings('site.replyToEmail') ?? ''));
if ($ownerEmail === '') {
    $ownerEmail = trim((string) (App::settings('site.defaultFromEmail') ?? ''));
}

$imageUrl = (string) (App::settings('site.brandLogoURL') ?? '/assets/images/logo.svg');
if ($imageUrl !== '' && str_starts_with($imageUrl, 'http') === false) {
    $imageUrl = $baseUrl . $imageUrl;
}

$language = (string) (App::settings('i18n.defaultLocale') ?? 'en');

// -----------------------------------------------------------------------------
// 🎙️ ONLY explicitly published recordings (isPublished = 1) with a media
// source (local file OR external URL) — excludes anything unpublished or
// role-restricted; tblRecording carries no separate role/minRole column,
// isPublished IS the visibility gate (same one stream.php/feed.php use).
// -----------------------------------------------------------------------------
$items = [];
$stmt = $db->prepare(
    'SELECT recordingID, title, presenterText, recordedAt, durationSeconds, summary, '
    . '       filePath, fileSize, mimeType, externalUrl '
    . 'FROM tblRecording '
    . 'WHERE siteID = ? AND isPublished = 1 AND (filePath IS NOT NULL OR externalUrl IS NOT NULL) '
    . 'ORDER BY recordedAt DESC, recordingID DESC LIMIT 300'
);
if ($stmt !== false) {
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $result = $stmt->get_result();
    while (($row = $result->fetch_assoc()) !== null) {
        $items[] = $row;
    }
    $stmt->close();
}

/**
 * Escape a string for safe placement in RSS/XML text content or attributes.
 */
function podcastFeedEsc(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * Zero-padded HH:MM:SS — itunes:duration's most broadly-compatible form.
 */
function podcastFeedDuration(int $seconds): string
{
    $seconds = max(0, $seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

header('Content-Type: application/rss+xml; charset=utf-8');
header('Cache-Control: public, max-age=900');

// #############################################################################
// 📡 CHANNEL
// #############################################################################
$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$xml .= '<rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:content="http://purl.org/rss/1.0/modules/content/">' . "\n";
$xml .= '  <channel>' . "\n";
$xml .= '    <title>' . podcastFeedEsc($siteName) . '</title>' . "\n";
$xml .= '    <link>' . podcastFeedEsc($baseUrl . '/recordings') . '</link>' . "\n";
$xml .= '    <language>' . podcastFeedEsc($language) . '</language>' . "\n";
$xml .= '    <description>' . podcastFeedEsc($description) . '</description>' . "\n";
$xml .= '    <itunes:author>' . podcastFeedEsc($author) . '</itunes:author>' . "\n";
$xml .= '    <itunes:summary>' . podcastFeedEsc($description) . '</itunes:summary>' . "\n";
$xml .= '    <itunes:owner>' . "\n";
$xml .= '      <itunes:name>' . podcastFeedEsc($author) . '</itunes:name>' . "\n";
if ($ownerEmail !== '') {
    $xml .= '      <itunes:email>' . podcastFeedEsc($ownerEmail) . '</itunes:email>' . "\n";
}
$xml .= '    </itunes:owner>' . "\n";
$xml .= '    <itunes:image href="' . podcastFeedEsc($imageUrl) . '" />' . "\n";
// 🗂️ Generic top-level Apple Podcasts category — brand-neutral default
// (WebMS Intra ships several non-church product-brand presets, see
// _core/brand-defaults.php), still perfectly valid for a church install.
$xml .= '    <itunes:category text="Society &amp; Culture" />' . "\n";
$xml .= '    <itunes:explicit>false</itunes:explicit>' . "\n";
$xml .= '    <itunes:type>episodic</itunes:type>' . "\n";

// #############################################################################
// 🎧 ITEMS
// #############################################################################
foreach ($items as $it) {
    $recordingId = (int) $it['recordingID'];
    $title       = (string) $it['title'];

    $enclosureType = (string) ($it['mimeType'] ?? 'audio/mpeg');
    $enclosureLen  = (int) ($it['fileSize'] ?? 0);

    $externalUrl = (string) ($it['externalUrl'] ?? '');
    if ($externalUrl !== '') {
        // Already a public URL — used as-is.
        $enclosureUrl = $externalUrl;
    } elseif ($it['filePath'] !== null) {
        // ⚠️ Login-gated at the Router level (recordings/stream is seeded
        // isProtected=1) — included for feed completeness/consistency with
        // recordings/feed.php's own listing, but see this file's header
        // "KNOWN LIMITATION" note: an external podcast client without a
        // portal session cannot actually fetch this enclosure today.
        $enclosureUrl = $baseUrl . '/recordings/stream?id=' . $recordingId;
    } else {
        continue;
    }

    $pubDate = $it['recordedAt'] !== null
        ? date(DATE_RSS, (int) strtotime((string) $it['recordedAt']))
        : date(DATE_RSS);

    $descriptionText = (string) ($it['summary'] ?? '');
    if ($descriptionText === '' && empty($it['presenterText']) === false) {
        $descriptionText = (string) $it['presenterText'];
    }
    // 🛡️ CDATA-safe — a literal "]]>" inside the summary would otherwise
    // terminate the CDATA section early and corrupt the feed.
    $descriptionCdata = str_replace(']]>', ']]&gt;', $descriptionText);

    $xml .= '    <item>' . "\n";
    $xml .= '      <title>' . podcastFeedEsc($title) . '</title>' . "\n";
    $xml .= '      <link>' . podcastFeedEsc($baseUrl . '/recordings/view?id=' . $recordingId) . '</link>' . "\n";
    $xml .= '      <guid isPermaLink="false">recording-' . $recordingId . '</guid>' . "\n";
    $xml .= '      <pubDate>' . podcastFeedEsc($pubDate) . '</pubDate>' . "\n";
    $xml .= '      <description><![CDATA[' . $descriptionCdata . ']]></description>' . "\n";
    if (empty($it['presenterText']) === false) {
        $xml .= '      <itunes:author>' . podcastFeedEsc((string) $it['presenterText']) . '</itunes:author>' . "\n";
    }
    if ($it['durationSeconds'] !== null) {
        $xml .= '      <itunes:duration>' . podcastFeedDuration((int) $it['durationSeconds']) . '</itunes:duration>' . "\n";
    }
    $xml .= '      <itunes:explicit>false</itunes:explicit>' . "\n";
    $xml .= '      <enclosure url="' . podcastFeedEsc($enclosureUrl) . '" length="' . $enclosureLen . '" type="' . podcastFeedEsc($enclosureType) . '" />' . "\n";
    $xml .= '    </item>' . "\n";
}

$xml .= '  </channel>' . "\n" . '</rss>' . "\n";

echo $xml;
exit();
