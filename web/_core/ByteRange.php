<?php
// Path: _core/ByteRange.php
/**
 * -----------------------------------------------------------------------------
 * Sending a file in pieces, when a browser asks for a piece 🎞️📏 (#880)
 * -----------------------------------------------------------------------------
 * A browser playing a video does not usually fetch the whole file in one go.
 * It asks for a PIECE of it ("bytes 0 to 1", then "bytes 1,000,000 onwards")
 * by sending a `Range:` header, so it can find out how long the video is and
 * jump to the middle without downloading the start. The server is meant to
 * answer such a request with:
 *
 *   - `206 Partial Content`, the piece and nothing else, and a
 *     `Content-Range: bytes <first>-<last>/<whole size>` header saying which
 *     piece it is;
 *   - `416 Range Not Satisfiable` (and `Content-Range: bytes * /<size>`,
 *     written without the space) when the piece asked for starts beyond the
 *     end of the file;
 *   - and `Accept-Ranges: bytes` on every answer, which is how a browser
 *     learns it MAY ask for pieces at all.
 *
 * WHY IT MATTERS: Safari will not play a video from a server that does not
 * do this. Chrome and Firefox fall back to downloading the whole file, so a
 * route that ignores `Range:` looks fine everywhere except on iPhones, iPads
 * and Macs using Safari — which is how #880 went unnoticed. A poster video on
 * the noticeboard, and the same video served through the public door, were
 * both sent whole with no `Accept-Ranges`.
 *
 * THIS IS THE ONE PLACE IT IS DONE. Before #880 two other routes did it with
 * their own copies — `Recordings::streamFile()` and
 * `_apps/recordings/podcast-media.php` — and the two copies shared two
 * faults, with a third in one of them, which is the usual cost of copies:
 *   1. A "suffix" request (`bytes=-500`, meaning "the LAST 500 bytes") was read
 *      as "bytes 0 to 500", so the client got the wrong bytes labelled as the
 *      right ones.
 *   2. An end beyond the file (`bytes=0-999999999` on a small file, which is
 *      perfectly legal and means "to the end") was not shortened to the last
 *      byte, so `Content-Length` promised more bytes than were ever sent and
 *      the client waited for them.
 *   3. (`streamFile()` alone) `recordings/stream` turned a 416 into a 404,
 *      because `streamFile()` answered false for it and its caller took
 *      false to mean "no file".
 * All four media routes now ask this class, so they cannot disagree. A NEW
 * route that streams video must ask it too, rather than copy one of them.
 *
 * WHAT IT FOLLOWS: RFC 9110 section 14 (Range Requests).
 *   - A `Range:` header in a unit other than `bytes`, one that is not well
 *     formed, or one whose last byte comes BEFORE its first (`bytes=9-3`) is
 *     IGNORED and the whole file is sent with 200. The standard allows a
 *     server to ignore such a header, and ignoring is the answer that can
 *     never leave a client with nothing. (The old podcast copy answered 416
 *     for `bytes=9-3`, and the stream copy a 404. That was not an "unsatisfiable" range — the file was
 *     never too short — just a malformed one.)
 *   - SEVERAL pieces in one request (`bytes=0-1,5-6`) are also ignored and
 *     the whole file sent. Answering them needs a multipart reply, no
 *     browser needs one to play a video, and the standard allows ignoring.
 *   - An `If-Range:` header means "send the piece only if the file is still
 *     the version I have". This portal sends no version marker (no `ETag` or
 *     `Last-Modified`) on these answers, so it can never confirm that, and
 *     the standard then says to send the whole file. So it does.
 *   - A 416 is sent with `Cache-Control: no-store`. Some of these routes
 *     tell caches to keep their answers (`public, max-age=…`), and a cache
 *     that kept a 416 under the file's address could hand "nothing here" to
 *     the next visitor who asked for the whole file.
 *
 * WHAT IT NEVER DOES: read the whole file into memory. The piece is copied
 * from the open file to the output in 64 KiB chunks, so a 200 MB video costs
 * the same memory as a 2 KB picture.
 *
 * WHAT IT DOES NOT DO, said plainly:
 *   - It decides nothing about WHO may have the file. Every caller keeps its
 *     own access checks and must make them BEFORE calling `send()`: once this
 *     class has started, bytes are going out.
 *   - It sets no `Content-Type`, caching or security headers of its own (the
 *     416's `no-store` apart). Those are the caller's, set before the call.
 *   - It was not tried in a real Safari. The answers it gives were checked
 *     header by header and byte by byte over HTTP
 *     (`tools/uploads-and-media-selftest.php`, `tools/public-door-selftest.php`).
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/880
 * @link      https://www.rfc-editor.org/rfc/rfc9110#section-14
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class ByteRange
{
    /** The size of each piece copied from the file to the output. */
    public const CHUNK = 65536;

    /** Whole file, 200. */
    public const WHOLE = 200;

    /** One piece, 206. */
    public const PART = 206;

    /** The piece asked for starts beyond the end of the file, 416. */
    public const UNSATISFIABLE = 416;

    /**
     * The longest run of digits read as a number. Nineteen digits can exceed
     * PHP's largest whole number, and `(int)` would then quietly give the
     * largest one anyway; saying so here makes it a decision rather than an
     * accident. No real file is a billion billion bytes long.
     */
    private const MAX_DIGITS = 18;

    /**
     * 📏 What to send, for one request, for a file of `$size` bytes.
     *
     * Takes the two headers as they arrived (`mixed`, because a header can be
     * missing and this is the one place that decides what that means) and the
     * file's size, and answers which status to send and which bytes. Opens no
     * file and sends nothing, so it can be tested without a server.
     *
     * @param mixed $rangeHeader   `$_SERVER['HTTP_RANGE']`, or null.
     * @param mixed $ifRangeHeader `$_SERVER['HTTP_IF_RANGE']`, or null.
     * @param int   $size          The file's size in bytes.
     *
     * @return array{status: int, start: int, end: int, length: int}
     *         For 416, `start` and `end` are -1 and `length` is 0.
     */
    public static function resolve(mixed $rangeHeader, mixed $ifRangeHeader, int $size): array
    {
        $whole = ['status' => self::WHOLE, 'start' => 0, 'end' => $size - 1, 'length' => $size];

        // No header, or not text: the ordinary request for the whole file.
        if (is_string($rangeHeader) === false || trim($rangeHeader) === '') {
            return $whole;
        }

        // `If-Range` present: we can never confirm the version the client
        // holds (we send no ETag or Last-Modified), so the standard says send
        // the whole file. Present in any form counts — an empty one included,
        // because "present" is what the client meant by sending it.
        if ($ifRangeHeader !== null) {
            return $whole;
        }

        // A unit other than `bytes`, or no `=`: not ours to understand, so
        // ignored (RFC 9110 section 14.2: a server MUST ignore a range unit it
        // does not understand). The unit is matched ignoring capitals, as the
        // standard says.
        if (preg_match('/^\s*bytes\s*=\s*(.*?)\s*$/i', $rangeHeader, $unit) !== 1) {
            return $whole;
        }

        // Several pieces: ignored, whole file (see the file header).
        if (str_contains($unit[1], ',') === true) {
            return $whole;
        }

        // One piece: `first-last`, `first-` or `-suffixLength`. Digits only —
        // no sign, no decimal point, no spaces inside a number.
        if (preg_match('/^(\d*)\s*-\s*(\d*)$/', $unit[1], $m) !== 1) {
            return $whole;
        }
        $firstText = $m[1];
        $lastText  = $m[2];

        if ($firstText === '' && $lastText === '') {
            // `bytes=-` names no piece at all: malformed, ignored.
            return $whole;
        }

        $unsatisfiable = ['status' => self::UNSATISFIABLE, 'start' => -1, 'end' => -1, 'length' => 0];

        if ($firstText === '') {
            // ✂️ SUFFIX: `bytes=-N` means the LAST N bytes, not bytes 0 to N.
            //    (The two old copies read it the second way.) N of zero asks
            //    for nothing, and an empty file has no last bytes to give:
            //    both are unsatisfiable. N larger than the file means the
            //    whole file, sent as a 206 piece starting at 0.
            $suffix = self::number($lastText);
            if ($suffix === 0 || $size === 0) {
                return $unsatisfiable;
            }
            $start = $suffix >= $size ? 0 : $size - $suffix;

            return ['status' => self::PART, 'start' => $start, 'end' => $size - 1, 'length' => $size - $start];
        }

        $first = self::number($firstText);
        $last  = $lastText === '' ? null : self::number($lastText);

        if ($last !== null && $last < $first) {
            // `bytes=9-3`: malformed (the standard calls it invalid), so
            // ignored — never "unsatisfiable", which would tell the client
            // the file is shorter than byte 9 when it may not be.
            return $whole;
        }

        if ($first >= $size) {
            // Starts at or beyond the end: the one genuinely unsatisfiable
            // case. An empty file lands here for any `first-…` request.
            return $unsatisfiable;
        }

        // An end beyond the file (or none) means "to the end": shortened to
        // the last byte, so `Content-Length` never promises bytes that do not
        // exist. (The two old copies did not shorten it.)
        $end = ($last === null || $last >= $size) ? $size - 1 : $last;

        return ['status' => self::PART, 'start' => $first, 'end' => $end, 'length' => $end - $first + 1];
    }

    /**
     * 📤 Send the file at `$path`: the status, `Accept-Ranges`,
     * `Content-Length`, `Content-Range` where it applies, and the bytes.
     *
     * Reads `Range:` and `If-Range:` from the request itself, so no caller
     * can forget to pass them on.
     *
     * The caller must already have made every access check and set its own
     * `Content-Type` and caching headers. This sets the status itself (200,
     * 206 or 416), replacing whatever the caller set.
     *
     * Answers FALSE, having sent nothing at all (not one header), when the
     * file cannot be opened — so the caller can still give its own "not
     * found". The file is opened FIRST and its size read from the open file,
     * so the size promised and the bytes sent are the same file's, even if it
     * is replaced on disk a moment later.
     *
     * Answers TRUE once it has answered, a 416 included.
     */
    public static function send(string $path): bool
    {
        // `@`: a file that cannot be opened is an answer (false), not a
        // warning in the error log for every visitor who asks for one.
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $stat = fstat($handle);
        if ($stat === false) {
            fclose($handle);
            return false;
        }
        $size = (int) $stat['size'];

        $plan = self::resolve($_SERVER['HTTP_RANGE'] ?? null, $_SERVER['HTTP_IF_RANGE'] ?? null, $size);

        header('Accept-Ranges: bytes');

        if ($plan['status'] === self::UNSATISFIABLE) {
            http_response_code(self::UNSATISFIABLE);
            header('Content-Range: bytes */' . $size);
            header('Content-Length: 0');
            // See the file header: a kept 416 could be handed to the next
            // visitor who asks for the whole file.
            header('Cache-Control: no-store');
            fclose($handle);
            return true;
        }

        http_response_code($plan['status']);
        if ($plan['status'] === self::PART) {
            header('Content-Range: bytes ' . $plan['start'] . '-' . $plan['end'] . '/' . $size);
        }
        header('Content-Length: ' . $plan['length']);

        if ($plan['start'] > 0 && fseek($handle, $plan['start']) !== 0) {
            // The headers are out and promise bytes we cannot reach. Stopping
            // short is the honest failure: the client sees a short answer and
            // can ask again, rather than being given the wrong bytes.
            fclose($handle);
            return true;
        }

        $remaining = $plan['length'];
        while ($remaining > 0) {
            $piece = fread($handle, $remaining < self::CHUNK ? $remaining : self::CHUNK);
            if ($piece === false || $piece === '') {
                // The file got shorter while it was being sent. Same as
                // above: stop short rather than pad with anything.
                break;
            }
            echo $piece;
            // Push each chunk out rather than letting PHP gather the whole
            // piece in an output buffer, which would undo the point of
            // reading it in chunks.
            flush();
            $remaining -= strlen($piece);
        }
        fclose($handle);

        return true;
    }

    /** A run of digits as a number; longer than MAX_DIGITS reads as the largest one. */
    private static function number(string $digits): int
    {
        if (strlen($digits) > self::MAX_DIGITS) {
            return PHP_INT_MAX;
        }

        return (int) $digits;
    }
}
