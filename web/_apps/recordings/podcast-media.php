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
 * HTTP RANGE: parses a single `bytes=start-end` (or `bytes=start-`) Range
 * header with the SAME lenient regex `Recordings::streamFile()` already
 * uses (missing start defaults to 0, missing end defaults to EOF) — 206 +
 * `Content-Range` when present and valid, 416 when the range is
 * unsatisfiable, else a plain 200 covering the whole file. A full-file
 * response is emitted via `readfile()`; a genuinely bounded sub-range
 * (end short of EOF) is emitted via `stream_copy_to_stream()` with an
 * explicit length — the bounded-length equivalent of `fpassthru()`, which
 * has no length parameter of its own and would over-send past a bounded
 * range's requested end.
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

$size = filesize($path);
if ($size === false) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found');
}

// -----------------------------------------------------------------------------
// 📏 Range parsing — same lenient `bytes=(\d*)-(\d*)` shape
// Recordings::streamFile() already uses, so behaviour stays consistent
// across both recordings-streaming endpoints.
// -----------------------------------------------------------------------------
$start   = 0;
$end     = $size - 1;
$isRange = false;

$rangeHeader = (string) ($_SERVER['HTTP_RANGE'] ?? '');
if ($rangeHeader !== '' && preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $m) === 1) {
    if ($m[1] !== '') {
        $start = (int) $m[1];
    }
    if ($m[2] !== '') {
        $end = (int) $m[2];
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit();
    }
    $isRange = true;
}

$length = $end - $start + 1;

// -----------------------------------------------------------------------------
// 📤 Headers — Content-Type from the stored mimeType (fallback audio/mpeg),
// Accept-Ranges so clients know they MAY seek, nosniff + inline so a
// browser navigating here directly plays/downloads it as media rather than
// treating it as an arbitrary download or attachment.
// -----------------------------------------------------------------------------
header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline');
header('Content-Length: ' . $length);
header('Cache-Control: public, max-age=3600');

if ($isRange === true) {
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}

if ($start === 0 && $length === $size) {
    // 📀 Whole file — the simplest, most efficient built-in dump.
    readfile($path);
    exit();
}

// 📀 A Range request — seek then copy EXACTLY $length bytes.
// stream_copy_to_stream() is the bounded-length equivalent of fpassthru()
// (which takes no length and would over-send past a bounded range's `end`
// when it falls short of EOF).
$fp = fopen($path, 'rb');
if ($fp === false) {
    exit();
}
$out = fopen('php://output', 'wb');
if ($out !== false) {
    stream_copy_to_stream($fp, $out, $length, $start);
}
fclose($fp);
exit();
