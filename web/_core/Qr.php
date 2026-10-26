<?php
// Path: _core/Qr.php
/**
 * -----------------------------------------------------------------------------
 * WebMS Intra — QR pictures 🔳
 * -----------------------------------------------------------------------------
 * THE ONE PLACE A QR PICTURE IS MADE. Asset labels, the noticeboard's share
 * panel, the `/qr` page and the two-factor set-up page all draw through this
 * class. Nothing else under `web/` may draw a QR code, ask another website to
 * draw one, or use `QrEncoder` (this class's own helper) directly.
 *
 * WHY ONE PLACE: the owner means to hand the drawing to a separate service of
 * theirs (CueRCode) later. That must be a change inside this file and nowhere
 * else. So a caller may rely ONLY on what is listed below, and must never
 * reach inside: not the squares, not the helper class, not how the SVG or PNG
 * happens to be written.
 *
 * WHAT A CALLER MAY RELY ON
 * -------------------------
 *   Qr::generate($text, $options)  gives ['mime' => ..., 'bytes' => ...]: a
 *       finished picture. It is an SVG unless a PNG was asked for AND this
 *       server can make one (the gd extension); so always send the 'mime' it
 *       gives back, never an assumed one.
 *   Qr::dataUri($text, $options)   gives the same picture as one `data:`
 *       address, to write straight into a page (`<img src="...">`). The
 *       page's own rule (`img-src 'self' data:`) allows it. Use this for
 *       anything SECRET: the text then never appears in a web address.
 *   Qr::longestText($ecc)          the most bytes that can be drawn.
 *   - The picture carries its own clear border, four squares wide, in the
 *     background colour. A reader needs it. The caller adds none and cannot
 *     take it away.
 *   - The picture is `size` pixels square. (A PNG is made BIGGER than asked
 *     when the size asked for has less than one pixel for each square.)
 *   - Text too long to draw is REFUSED with a \LengthException whose message
 *     is plain words. It is never cut short and never drawn wrongly.
 *
 * WHAT IT CANNOT DRAW, AND CANNOT PROMISE
 * ---------------------------------------
 * - The longest text is 2,953 bytes at error-correction level L, 2,331 at M
 *   (the usual level), 1,663 at Q and 1,273 at H. A byte is one plain letter
 *   or digit; an accented or non-Latin letter is two to four.
 * - Any characters can be drawn, but the picture does not say which alphabet
 *   they are in. The portal's text is UTF-8 and today's readers take it as
 *   that. An old reader may show accented or non-Latin letters wrongly. Plain
 *   web addresses are not affected.
 * - It does not judge the colours it is given. Light squares on a dark
 *   background, or two colours close together, make a picture many readers
 *   cannot read. The usual black on white is the safe choice.
 * - It cannot know how big the picture will be shown or printed. A long text
 *   makes many small squares; shown small, a camera may not make them out. As
 *   a guide, 100 bytes at level M make a picture 49 squares across, border included.
 * - A caption is drawn under the picture, outside the clear border. In a PNG
 *   it can show plain Latin letters only.
 *
 * WHAT WAS WRONG (#623, repaired 14 October 2026)
 * -----------------------------------------------
 * No reader could read any picture this class made, since it was written in
 * July 2026. The fault that stopped every reader was in `QrEncoder` and is
 * recorded there (the format marks were written back to front). Two more were
 * in this file:
 * 1. There was no clear border. The standard asks for four squares of
 *    background all round, and readers use it to find the picture. On a dark
 *    page, or on a label with print beside it, the picture ran straight into
 *    its surroundings.
 * 2. A PNG asked for at fewer pixels than the picture has squares came out as
 *    a blank square (each square was given a width of nothing).
 * It also wrote an SVG as one rectangle for every dark square, about 90,000
 * bytes for a 200-byte text. It now joins the squares of a row into runs.
 *
 * SENDING TEXT TO AN OUTSIDE SERVICE: resolveContent() below sends the text it
 * is given to the CueRCode service when an administrator has switched that
 * on. NEVER pass it anything secret. The two-factor key must never go through
 * it, and the set-up page does not call it.
 *
 * HOW IT IS CHECKED: `tools/qr-selftest.php` draws pictures with this class
 * and reads them back with a reader of its own, first proved on pictures
 * from a different program. It cannot stand in for a phone's camera.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   2.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/275
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/623
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Qr
{
    /**
     * The clear border, in squares. Four is what the standard asks for
     * (section 9.1). Not an option: a caller that could set it could set it
     * to nothing, which is the fault this file once had.
     */
    private const BORDER_SQUARES = 4;

    /** The smallest and largest picture, in pixels. The largest keeps one request from asking for a picture too big for the server's memory. */
    private const SMALLEST_PIXELS = 64;
    private const LARGEST_PIXELS  = 2048;

    /**
     * Make a QR picture.
     *
     * @param string $content     The text to put in it (an address, a contact card, plain text).
     * @param array{
     *     size?:int,
     *     ecc?:string,
     *     format?:string,
     *     fg?:string,
     *     bg?:string,
     *     caption?:string
     * } $opts Options:
     *     size    how many pixels square (default 256; from 64 to 2048).
     *     ecc     error-correction level, 'L' | 'M' | 'Q' | 'H' (default 'M').
     *             A higher level survives more damage and holds less text.
     *             Anything else counts as 'M'.
     *     format  'svg' (default) | 'png'. A PNG needs gd; without it an SVG
     *             comes back, so read 'mime' from the answer.
     *     fg      the colour of the squares, as '#rrggbb' (default '#000000').
     *     bg      the background, as '#rrggbb' (default '#ffffff').
     *     caption a line of words under the picture.
     *
     * @return array{mime:string, bytes:string}
     *
     * @throws \LengthException When the text is too long to draw. Its message
     *         is plain words, fit to show to the person who asked.
     */
    public static function generate(string $content, array $opts = []): array
    {
        $size    = min(self::LARGEST_PIXELS, max(self::SMALLEST_PIXELS, (int) ($opts['size'] ?? 256)));
        $format  = strtolower((string) ($opts['format']  ?? 'svg'));
        $ecc     = (string) ($opts['ecc'] ?? 'M');
        $fg      = self::sanitiseHex((string) ($opts['fg'] ?? '#000000'), '#000000');
        $bg      = self::sanitiseHex((string) ($opts['bg'] ?? '#ffffff'), '#ffffff');
        $caption = (string) ($opts['caption'] ?? '');

        // 🔳 Which squares are dark. (This call is what a later change
        //    replaces to hand the drawing to an outside service.)
        $matrix = self::buildMatrix($content, $ecc);

        if ($format === 'png' && extension_loaded('gd') === true) {
            $png = self::renderPng($matrix, $size, $fg, $bg, $caption);
            if ($png !== '') {
                return ['mime' => 'image/png', 'bytes' => $png];
            }
            // gd could not make the picture (out of memory, most likely).
            // This used to hand back an empty PNG, which a browser shows as
            // a broken image. The SVG below needs no gd.
        }
        return ['mime' => 'image/svg+xml', 'bytes' => self::renderSvg($matrix, $size, $fg, $bg, $caption)];
    }

    /**
     * The same picture as ONE `data:` address, to write into a page:
     *
     *     <img src="<?php echo htmlspecialchars(Qr::dataUri($text), ENT_QUOTES, 'UTF-8'); ?>" alt="...">
     *
     * This is the way to show a picture of something secret. The text goes
     * into the page that is sent to the person and nowhere else. A picture
     * fetched from `/qr?content=...` puts the text in a web address, and web
     * addresses are written into server logs and browser histories.
     *
     * The alt text is the caller's to write, and must not repeat a secret.
     *
     * @param array<string, mixed> $opts The same options as generate().
     *
     * @throws \LengthException As generate().
     */
    public static function dataUri(string $content, array $opts = []): string
    {
        $picture = self::generate($content, $opts);
        return 'data:' . $picture['mime'] . ';base64,' . base64_encode($picture['bytes']);
    }

    /**
     * The longest text, in bytes, that can be drawn at an error-correction
     * level. A caller that takes text from a person can check against this
     * first and say so kindly, instead of catching the refusal.
     */
    public static function longestText(string $ecc = 'M'): int
    {
        return QrEncoder::longestText($ecc);
    }

    /**
     * Draw the squares as an SVG: one background rectangle, then ONE path
     * that holds every dark square, each run of dark squares in a row as one
     * small rectangle.
     *
     * TRIED AND REJECTED: one `<rect>` for every dark square (what this did
     * at first). A 200-byte text has about 1,600 dark squares and came to
     * about 90,000 bytes, far too much to write into a page.
     *
     * `tools/qr-test-reader.php` reads exactly these commands back. If the
     * way this is written changes, that reader refuses it, on purpose: change
     * the two together.
     *
     * @param array<int, array<int, bool>> $matrix
     */
    private static function renderSvg(array $matrix, int $size, string $fg, string $bg, string $caption): string
    {
        $modules = count($matrix);
        $border  = self::BORDER_SQUARES;
        $across  = $modules + 2 * $border; // squares across, border included

        $path = '';
        for ($y = 0; $y < $modules; $y++) {
            $x = 0;
            while ($x < $modules) {
                if ($matrix[$y][$x] !== true) {
                    $x++;
                    continue;
                }
                $start = $x;
                while ($x < $modules && $matrix[$y][$x] === true) {
                    $x++;
                }
                $run   = $x - $start;
                $path .= 'M' . ($start + $border) . ' ' . ($y + $border) . 'h' . $run . 'v1h-' . $run . 'z';
            }
        }

        // The picture itself, measured in squares, so it stays sharp at any
        // size. "crispEdges" stops a browser blurring the edges of squares.
        $picture = '<rect width="100%" height="100%" fill="' . self::escAttr($bg) . '"/>'
                 . '<path fill="' . self::escAttr($fg) . '" d="' . $path . '"/>';

        if ($caption === '') {
            return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size
                 . '" viewBox="0 0 ' . $across . ' ' . $across . '" shape-rendering="crispEdges">' . $picture . '</svg>';
        }

        // With a caption: an outer picture measured in pixels, 24 taller,
        // holding the QR code (measured in squares) and the words under it.
        // The words sit below the whole clear border, never inside it.
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . ($size + 24) . '">'
             . '<rect width="100%" height="100%" fill="' . self::escAttr($bg) . '"/>'
             . '<svg x="0" y="0" width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $across . ' ' . $across
             . '" shape-rendering="crispEdges">' . $picture . '</svg>'
             . '<text x="' . ($size / 2) . '" y="' . ($size + 16) . '" text-anchor="middle" font-family="system-ui,sans-serif" font-size="12" fill="'
             . self::escAttr($fg) . '">' . self::escText($caption) . '</text>'
             . '</svg>';
    }

    /**
     * Draw the squares as a PNG picture with gd. Every square is a whole
     * number of pixels, so its edges are sharp.
     *
     * @param array<int, array<int, bool>> $matrix
     *
     * @return string The PNG, or '' when gd could not make it.
     */
    private static function renderPng(array $matrix, int $size, string $fg, string $bg, string $caption): string
    {
        $modules = count($matrix);
        $border  = self::BORDER_SQUARES;
        $across  = $modules + 2 * $border;

        // 📐 The biggest whole number of pixels for a square that fits the
        //    size asked for, and never less than one. This used to work out
        //    as NOTHING when the size was smaller than the count of squares,
        //    and the picture came out blank. When one pixel a square is
        //    already wider than the size asked for, the picture is made
        //    wider: a picture bigger than asked can be read, and a blank one
        //    cannot.
        $cell   = max(1, intdiv($size, $across));
        $side   = max($size, $cell * $across);
        // Any pixels left over go round the outside, as more clear border.
        $offset = intdiv($side - $cell * $across, 2) + $border * $cell;

        $captionH = ($caption !== '') ? 20 : 0;
        $img      = imagecreatetruecolor($side, $side + $captionH);
        if ($img === false) {
            return '';
        }
        [$fr, $fg2, $fb] = self::hexToRgb($fg);
        [$br, $bg2, $bb] = self::hexToRgb($bg);
        $bgColor = imagecolorallocate($img, $br, $bg2, $bb);
        $fgColor = imagecolorallocate($img, $fr, $fg2, $fb);
        if ($bgColor === false || $fgColor === false) {
            // No imagedestroy(): it has done nothing since PHP 8.0 (the picture is freed with its variable), and PHP 8.5 reports it as deprecated.
            return '';
        }
        imagefilledrectangle($img, 0, 0, $side, $side + $captionH, $bgColor);
        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                if ($matrix[$y][$x] === true) {
                    imagefilledrectangle(
                        $img,
                        $offset + $x * $cell,
                        $offset + $y * $cell,
                        $offset + ($x + 1) * $cell - 1,
                        $offset + ($y + 1) * $cell - 1,
                        $fgColor
                    );
                }
            }
        }
        if ($caption !== '') {
            // gd's built-in letters: plain Latin only (see the header).
            $font  = 3;
            $textW = imagefontwidth($font) * strlen($caption);
            $textX = (int) (($side - $textW) / 2);
            imagestring($img, $font, $textX, $side + 4, $caption, $fgColor);
        }
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        // No imagedestroy(): it has done nothing since PHP 8.0 (the picture is freed with its variable), and PHP 8.5 reports it as deprecated.
        return $bytes;
    }

    /**
     * Which squares are dark, with no border. Kept as one small step so that
     * handing the drawing to another service later is a change here alone.
     *
     * @return array<int, array<int, bool>>
     *
     * @throws \LengthException When the text is too long.
     */
    private static function buildMatrix(string $content, string $ecc): array
    {
        $qr = new QrEncoder();
        return $qr->encode($content, $ecc);
    }

    /**
     * Decide which provider to use. CueRCode is an external tracking service
     * — when configured, the caller should NOT call generate() directly;
     * instead, call resolveContent() to get the tracked URL to encode.
     *
     * 🔒 This SENDS $rawContent to that service when it is switched on. Never
     *    give it anything secret (see the header).
     */
    public static function resolveContent(string $rawContent, string $purpose = 'general'): string
    {
        $settings = App::settings();
        $provider = (string) ($settings['portal']['qr']['provider'] ?? 'local');
        if ($provider !== 'cuercode') {
            return $rawContent;
        }
        $apiKey   = (string) ($settings['portal']['qr']['cuercode']['api_key'] ?? '');
        $endpoint = (string) ($settings['portal']['qr']['cuercode']['api_endpoint'] ?? '');
        if ($apiKey === '' || $endpoint === '') {
            return $rawContent;
        }
        // 🪞 Register the raw content with CueRCode; receive a tracking URL.
        //    Best-effort — falls back to raw on any failure.
        $ch = curl_init();
        if ($ch === false) {
            return $rawContent;
        }
        curl_setopt_array($ch, [
            CURLOPT_URL            => rtrim($endpoint, '/') . '/register',
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode(['url' => $rawContent, 'purpose' => $purpose]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 300 || !is_string($resp)) {
            return $rawContent;
        }
        $decoded = json_decode($resp, true);
        if (is_array($decoded) && isset($decoded['tracking_url']) && is_string($decoded['tracking_url'])) {
            return $decoded['tracking_url'];
        }
        return $rawContent;
    }

    private static function sanitiseHex(string $hex, string $fallback): string
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) === 1 ? $hex : $fallback;
    }

    /** @return array{0:int,1:int,2:int} */
    private static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    private static function escAttr(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    private static function escText(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1, 'UTF-8');
    }
}
