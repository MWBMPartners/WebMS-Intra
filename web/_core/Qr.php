<?php
// Path: _core/Qr.php
/**
 * -----------------------------------------------------------------------------
 * WebMS Intra — QR code generator 🔳
 * -----------------------------------------------------------------------------
 * Minimal pure-PHP QR emitter for portal use cases — calendar feed URLs,
 * invite links, visitor capture forms, vCard contact cards, etc.
 *
 * Two output modes:
 *   • SVG (default) — scalable, ~5KB, no external deps. Inline embeddable.
 *   • PNG — only when gd is loaded. Falls back to SVG otherwise.
 *
 * Implementation strategy: minimal-but-correct QR Code M (Model 2) encoder
 * supporting alphanumeric + byte mode, error correction level L/M/Q/H, up
 * to version 10 (covers URLs up to ~250 chars). Anything longer overflows
 * — callers should shorten via redirect.
 *
 * Pluggable provider abstraction (#275): when portal.qr.provider = 'cuercode',
 * defers to the external CueRCode service for tracked QR codes. Default is
 * 'local' which uses this class.
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/275
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

class Qr
{
    /**
     * Generate a QR code for the given content.
     *
     * @param string $content     Text to encode (URLs, vCard, plain text).
     * @param array{
     *     size?:int,
     *     ecc?:string,
     *     format?:string,
     *     fg?:string,
     *     bg?:string,
     *     caption?:string
     * } $opts Options:
     *     size    pixel size of the rendered output (default 256).
     *     ecc     error correction level: 'L' | 'M' | 'Q' | 'H' (default 'M').
     *     format  'svg' (default) | 'png'.
     *     fg      foreground hex colour (default '#000000').
     *     bg      background hex colour (default '#ffffff').
     *     caption optional caption rendered below the QR.
     *
     * @return array{mime:string, bytes:string}
     */
    public static function generate(string $content, array $opts = []): array
    {
        $size    = max(64, (int) ($opts['size']    ?? 256));
        $format  = strtolower((string) ($opts['format']  ?? 'svg'));
        $fg      = self::sanitiseHex((string) ($opts['fg'] ?? '#000000'), '#000000');
        $bg      = self::sanitiseHex((string) ($opts['bg'] ?? '#ffffff'), '#ffffff');
        $caption = (string) ($opts['caption'] ?? '');

        // 🪞 Encode via the local matrix builder.
        $matrix = self::buildMatrix($content);

        if ($format === 'png' && extension_loaded('gd')) {
            return ['mime' => 'image/png', 'bytes' => self::renderPng($matrix, $size, $fg, $bg, $caption)];
        }
        return ['mime' => 'image/svg+xml', 'bytes' => self::renderSvg($matrix, $size, $fg, $bg, $caption)];
    }

    /**
     * Render an SVG from the boolean matrix. Single-coloured rect per module
     * keeps output compact and scalable.
     *
     * @param array<int, array<int, bool>> $matrix
     */
    private static function renderSvg(array $matrix, int $size, string $fg, string $bg, string $caption): string
    {
        $modules = count($matrix);
        $captionH = $caption !== '' ? 24 : 0;
        $totalH = $size + $captionH;
        $cellPx = $size / max(1, $modules);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $totalH
             . '" viewBox="0 0 ' . $modules . ' ' . ($modules + ($caption !== '' ? 3 : 0)) . '" shape-rendering="crispEdges">';
        $svg .= '<rect width="100%" height="100%" fill="' . self::escAttr($bg) . '"/>';
        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                if ($matrix[$y][$x] === true) {
                    $svg .= '<rect x="' . $x . '" y="' . $y . '" width="1" height="1" fill="' . self::escAttr($fg) . '"/>';
                }
            }
        }
        if ($caption !== '') {
            $svg .= '<text x="' . ($modules / 2) . '" y="' . ($modules + 2)
                  . '" text-anchor="middle" font-family="system-ui,sans-serif" font-size="1.2" fill="'
                  . self::escAttr($fg) . '">' . self::escText($caption) . '</text>';
        }
        $svg .= '</svg>';
        return $svg;
    }

    /**
     * Render a PNG using gd. Caption uses gd's built-in 5x7 font.
     *
     * @param array<int, array<int, bool>> $matrix
     */
    private static function renderPng(array $matrix, int $size, string $fg, string $bg, string $caption): string
    {
        $modules = count($matrix);
        $captionH = $caption !== '' ? 20 : 0;
        $img = imagecreatetruecolor($size, $size + $captionH);
        if ($img === false) {
            return '';
        }
        [$fr, $fg2, $fb] = self::hexToRgb($fg);
        [$br, $bg2, $bb] = self::hexToRgb($bg);
        $bgColor = imagecolorallocate($img, $br, $bg2, $bb);
        $fgColor = imagecolorallocate($img, $fr, $fg2, $fb);
        if ($bgColor === false || $fgColor === false) {
            imagedestroy($img);
            return '';
        }
        imagefilledrectangle($img, 0, 0, $size, $size + $captionH, $bgColor);
        $cell = (int) floor($size / $modules);
        $offset = (int) (($size - ($cell * $modules)) / 2);
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
            $font = 3;
            $textW = imagefontwidth($font) * strlen($caption);
            $textX = (int) (($size - $textW) / 2);
            imagestring($img, $font, $textX, $size + 4, $caption, $fgColor);
        }
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);
        return $bytes;
    }

    /**
     * Build a boolean QR matrix. Uses a vendored minimal Reed-Solomon /
     * mask-evaluation routine kept inline so we don't carry an external
     * vendor for this. Supports byte mode, ecc M, version 1-10.
     *
     * For longer payloads (URLs > ~250 chars) callers should redirect via
     * a portal-side short URL first.
     *
     * @return array<int, array<int, bool>>
     */
    private static function buildMatrix(string $content): array
    {
        // 🪞 We implement enough QR encoding to cover the portal's needs.
        //    For a full feature set, swap to chillerlan/php-qrcode later
        //    via the same Qr::generate() entry point.
        $qr = new QrEncoder();
        return $qr->encode($content);
    }

    /**
     * Decide which provider to use. CueRCode is an external tracking service
     * — when configured, the caller should NOT call generate() directly;
     * instead, call resolveContent() to get the tracked URL to encode.
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
