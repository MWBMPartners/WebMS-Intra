<?php
// Path: _apps/recordings/podcast-media.php
/**
 * -----------------------------------------------------------------------------
 * Recordings — Public podcast media stream 🎙📶 (#264 v1.1 follow-up)
 * -----------------------------------------------------------------------------
 * PUBLIC — no login required. Closes the gap `recordings/podcast.php`'s own
 * header used to document as a "known limitation": a self-hosted
 * (`filePath`) episode's enclosure previously pointed at the login-gated
 * `recordings/stream.php`, which an external podcast client (no portal
 * session) could never actually fetch — `recordings/stream` is seeded
 * `isProtected = 1` in `tblRoutes`, so `Router::handleSpecialRoutes()`
 * enforces `Auth::requireLogin()` BEFORE that file even runs, regardless of
 * anything the file itself checks. THIS route is seeded `isProtected = 0`
 * instead, and re-authenticates its own way: the SAME per-site unguessable
 * `?token=…` `recordings/podcast.php`'s feed URL already carries, validated
 * with `hash_equals()` against `Portal\Core\Recordings::podcastToken()`
 * (empty stored token ALWAYS 403s — same fail-closed gate as podcast.php).
 * That re-authentication is the only reason this public route is safe to
 * exist without a session.
 *
 * VISIBILITY GATE: serves a file ONLY when `isPublished = 1` AND it has a
 * non-empty `filePath` (self-hosted) — anything else (wrong id, wrong
 * site, unpublished, externalUrl-only with no local file) gets the exact
 * SAME 404, a uniform response so this endpoint is never usable as an
 * oracle for which recording IDs exist.
 *
 * PATH RESOLUTION / CONTAINMENT: deliberately IDENTICAL to
 * `recordings/stream.php` — `Recordings::uploadDir()` for the fixed
 * on-disk root, `basename($rec['filePath'])` to strip any directory
 * traversal component before joining. The resulting absolute path is
 * NEVER echoed back to the client in any header or error body.
 *
 * HTTP RANGE: answered by `Portal\Core\ByteRange::send()` (#880), the one
 * place a `Range:` request is answered for every media route — 206 with
 * `Content-Range` for one piece, 416 when the piece starts beyond the end,
 * otherwise 200 with the whole file, `Accept-Ranges: bytes` on all of them,
 * and the file copied in chunks, never read whole into memory. (This file
 * used to parse the header itself; see the note above the send.)
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
use Portal\Core\ByteRange;
use Portal\Core\Recordings;
use Portal\Core\Site;

// -----------------------------------------------------------------------------
// 🔑 Token gate (constant-time compare) — SAME token + SAME fail-closed
// convention as recordings/podcast.php. This re-authentication is the
// legitimate reason this route can be public (isProtected=0) at all.
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
$id = (int) ($_GET['id'] ?? 0);

// -----------------------------------------------------------------------------
// 🔍 Site-scoped, prepared lookup — ONLY a published, self-hosted recording
// qualifies. Uniform 404 below covers every rejection reason.
// -----------------------------------------------------------------------------
$rec = null;
$stmt = $db->prepare(
    'SELECT filePath, mimeType FROM tblRecording WHERE recordingID = ? AND siteID = ? AND isPublished = 1 LIMIT 1'
);
if ($stmt !== false) {
    $stmt->bind_param('ii', $id, $siteId);
    $stmt->execute();
    $rec = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($rec === null || $rec['filePath'] === null || $rec['filePath'] === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found');
}

// 🛡️ Constrain path to the uploads dir — no traversal. IDENTICAL scheme to
// recordings/stream.php (see this file's header note) — never reimplemented.
$safeName = basename((string) $rec['filePath']);
$path     = Recordings::uploadDir() . DIRECTORY_SEPARATOR . $safeName;
$mimeRaw  = (string) ($rec['mimeType'] ?? '');
$mime     = $mimeRaw !== '' ? $mimeRaw : 'audio/mpeg';

if (is_file($path) === false || is_readable($path) === false) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found');
}

// -----------------------------------------------------------------------------
// 📤 Headers — Content-Type from the stored mimeType (fallback audio/mpeg),
// nosniff + inline so a browser navigating here directly plays/downloads it
// as media rather than treating it as an arbitrary download or attachment.
//
// 🎞️ The status, `Accept-Ranges`, `Content-Length`, `Content-Range` and the
// bytes are `ByteRange::send()`'s (#880), the ONE place a `Range:` request is
// answered. This file used to parse the header itself, with a copy of
// `Recordings::streamFile()`'s regex, and the two shared two faults: a
// suffix request (`bytes=-500`, "the last 500 bytes") was read as bytes 0
// to 500, and an end beyond the file was not shortened, so `Content-Length`
// promised more bytes than were sent. The token gate and the published,
// self-hosted, this-organisation lookup above have both passed by now.
// -----------------------------------------------------------------------------
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline');
header('Cache-Control: public, max-age=3600');

if (ByteRange::send($path) === false) {
    // Could not be opened after all; nothing was sent, so a clean 404.
    header_remove('Content-Disposition');
    header_remove('Cache-Control');
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found');
}
exit();
