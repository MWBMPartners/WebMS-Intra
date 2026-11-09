<?php
// Path: _core/BrandColour.php
/**
 * -----------------------------------------------------------------------------
 * Readable colours worked out from an organisation's own colour 🎨
 * -----------------------------------------------------------------------------
 * An organisation chooses one main colour (`tblSites.primaryColor`, read by
 * `Site::branding('color')`). The page uses it for buttons, the selected item
 * in a menu or list, the current page number, and as the colour of some text
 * and icons. This class works out, from that one colour, everything that has
 * to stay READABLE whichever colour was chosen and whichever theme (light or
 * dark) the person is using. `templates/header.php` prints the result as a
 * small style block after portal.css (`styleBlock()`).
 *
 * WHY THIS EXISTS (D-091, #852 item 3, the owner, 9 November 2026)
 * ----------------------------------------------------------------
 * The owner chose that in dark mode the hover, pressed and faint-panel shades
 * follow the organisation's own colour, as in light mode, "with a contrast
 * check so a pale or very dark chosen colour cannot make text hard to read".
 * Measured in a browser first, before anything was changed:
 *   - the three dark-mode shades were fixed indigo (#8f99eb, #a0a9ee, #1f2347)
 *     whatever colour was chosen — a later rule of equal weight overrode the
 *     colour-mixed ones;
 *   - dark mode's brighter main colour (#7b86e8) NEVER applied, for anybody:
 *     header.php writes the chosen colour (or the default) straight onto
 *     <html>, and a style written on the element beats any stylesheet rule;
 *   - the text drawn ON the main colour was a fixed near-black in dark mode
 *     (about 4.0:1 on the default colour, below the 4.5:1 normal text needs)
 *     and Bootstrap's own white on a primary button in both themes, so a
 *     pale chosen colour made a button's words unreadable in light mode too;
 *   - the main colour is also used AS a text colour (ordinary links, links in
 *     menus, a breadcrumb link on hover, "text-primary"), where a pale colour
 *     on white or a dark one on dark mode's near-black background cannot be
 *     read; and the hover shade of a .btn-link or nav link was the button's
 *     hover shade (an ordinary link keeps its own colour on hover).
 * So the contrast check covers all three: the words on the colour, the
 * colour's own shades, and the colour used as words.
 *
 * WHAT IT DECIDES
 * ---------------
 * 1. The words ON the main colour: white when white reaches 4.5:1 on it (the
 *    look the design had), otherwise the design's near-black, otherwise pure
 *    black. One of white and pure black always reaches at least about 4.58:1
 *    on any single colour, which is why that last step exists.
 * 2. The hover and pressed shades: 12% and 24% of the way AWAY from those
 *    words (towards black under white words, towards white under dark ones),
 *    so a shade can only read better than the colour itself, never worse.
 *    This replaces "darker in light mode, lighter in dark mode": that rule
 *    made white words on a lightened shade fail in dark mode.
 * 3. The faint-panel shade: 92% towards white in light mode, 80% towards
 *    black in dark mode (what portal.css's colour-mix() rules already meant).
 * 4. The colour used AS words, per theme: the chosen colour itself where it
 *    already reaches 4.5:1 on every background it sits on, otherwise moved
 *    towards black (light mode) or white (dark mode) in 5% steps, only as far
 *    as needed. The chosen colour is never changed where it is a BACKGROUND.
 * 5. Light mode's links: that same readable shade, as a hex (`--portal-link`,
 *    read by .btn-link and nav links through Bootstrap's `--bs-link-color`)
 *    AND as three numbers (`--portal-link-rgb`, which is what Bootstrap paints
 *    an ordinary <a> with, through `--bs-link-color-rgb`). An ordinary link
 *    keeps its own colour on hover (portal.css binds its hover numbers to the
 *    same value); .btn-link and nav links take `--portal-link-hover`, 15%
 *    further towards black, so their hover can only read better — it used to
 *    be the button's hover shade, which moves away from DARK words and so
 *    made them lighter on hover for every colour that takes dark words (round
 *    1 of the check). Dark mode's links keep their own fixed colours, which
 *    read on every dark background and were never the chosen colour.
 * 6. The remove "x" on a chip coloured with the main colour (the report
 *    builder's columns): white, as Bootstrap draws it, only when the words on
 *    the colour are white; otherwise left dark (round 2 of the check: a white
 *    x on a pale colour all but vanished).
 *
 * WHAT IT CANNOT DO, said plainly
 * --------------------------------
 * - It knows the backgrounds portal.css defines (listed below). A page that
 *   draws the colour on some other background of its own is not covered —
 *   the calendar's event times are the one known case: they are drawn in the
 *   event's CATEGORY colour (the organisation's only when a category has
 *   none) on a tint of that colour, and neither is measured here.
 * - Borders and other non-text uses of the colour (a ticked box, a progress
 *   bar, a card's edge) are not adjusted: those need 3:1 against what is next
 *   to them, which depends on the page, and the chosen colour is kept there.
 * - Mixing is done on the 0-255 values as colour-mix(in srgb, …) does, which
 *   is not perceptually even. The contrast figures are WCAG 2's, computed on
 *   the result, so the guarantee holds whatever the mixing looks like.
 *
 * Test: tools/brand-colour-selftest.php (the arithmetic, over many colours)
 * and tools/brand-colour-selftest.mjs (what a real browser applies).
 *
 * @package   WebMS-Intra
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright All Rights Reserved — MWBM Partners Ltd (t/a MWservices)
 * @version   1.0.0
 */

declare(strict_types=1);

namespace Portal\Core;

final class BrandColour
{
    /** The colour used when none is chosen, or the chosen one cannot be read (header.php's own fallback). */
    public const DEFAULT = '#5e6ad2';

    /** Normal-size text needs this much contrast (WCAG 2.2, 1.4.3, level AA). */
    public const MIN_TEXT_CONTRAST = 4.5;

    /**
     * Dark mode's own link colours (portal.css). Restated in the dark block:
     * `html:root` also matches in dark mode and outweighs portal.css's
     * `[data-bs-theme="dark"]`, so without them light mode's link values
     * would leak into dark mode.
     */
    private const DARK_LINK       = '#9aa5f0';
    private const DARK_LINK_RGB   = '154, 165, 240';
    private const DARK_LINK_HOVER = '#b3bcf6';

    /** The design's dark text colour (portal.css's dark-mode `--portal-text-on-primary`). */
    private const DESIGN_DARK_TEXT = '#0f1115';

    /**
     * The backgrounds the colour sits on AS TEXT, from portal.css: in light
     * mode the surface, the page and the hover surface; in dark mode the page,
     * the surface, the raised surface and the hover surface. The faint-panel
     * shade is added per colour, because pagination and list items draw the
     * colour on it when hovered.
     */
    public const LIGHT_BACKGROUNDS = ['#ffffff', '#f7f8fa', '#f0f2f6'];
    public const DARK_BACKGROUNDS  = ['#0f1115', '#161a22', '#1c212c', '#232936'];

    /**
     * Every value the page needs, worked out from one chosen colour.
     *
     * @return array{primary:string, textOnPrimary:string, hover:string, active:string,
     *               light:array{subtle:string, primaryText:string, link:string, linkRgb:string, linkHover:string},
     *               dark:array{subtle:string, primaryText:string}}
     */
    public static function tokens(string $chosen): array
    {
        $primary = self::parse($chosen) ?? self::parse(self::DEFAULT);
        $white   = [255, 255, 255];
        $black   = [0, 0, 0];

        // 1. The words on the colour.
        $designDark = self::parse(self::DESIGN_DARK_TEXT);
        if (self::contrast($white, $primary) >= self::MIN_TEXT_CONTRAST) {
            $text = $white;
        } elseif (self::contrast($designDark, $primary) >= self::MIN_TEXT_CONTRAST) {
            $text = $designDark;
        } else {
            $text = self::contrast($white, $primary) >= self::contrast($black, $primary) ? $white : $black;
        }

        // 2. Hover and pressed: away from the words, so contrast only rises.
        $away   = ($text === $white) ? $black : $white;
        $hover  = self::mix($primary, $away, 0.12);
        $active = self::mix($primary, $away, 0.24);

        // 3. The faint-panel shade, per theme.
        $subtleLight = self::mix($primary, $white, 0.92);
        $subtleDark  = self::mix($primary, $black, 0.80);

        // 4. The colour as words, per theme.
        $lightBgs = array_map(static fn (string $h): array => self::parse($h), self::LIGHT_BACKGROUNDS);
        $darkBgs  = array_map(static fn (string $h): array => self::parse($h), self::DARK_BACKGROUNDS);
        $lightBgs[] = $subtleLight;
        $darkBgs[]  = $subtleDark;

        $lightText = self::readableOn($primary, $lightBgs, $black);
        $linkHover = self::mix($lightText, $black, 0.15);

        return [
            'primary'       => self::hex($primary),
            'textOnPrimary' => self::hex($text),
            'hover'         => self::hex($hover),
            'active'        => self::hex($active),
            'light'         => ['subtle' => self::hex($subtleLight), 'primaryText' => self::hex($lightText),
                'link' => self::hex($lightText), 'linkRgb' => implode(', ', $lightText), 'linkHover' => self::hex($linkHover)],
            'dark'          => ['subtle' => self::hex($subtleDark),  'primaryText' => self::hex(self::readableOn($primary, $darkBgs, $white))],
        ];
    }

    /**
     * The style block header.php prints after portal.css. `html:root` and
     * `html[data-bs-theme="dark"]` outweigh portal.css's `:root` and
     * `[data-bs-theme="dark"]`, so these values win whatever order the
     * stylesheets load in. Every value is a hex worked out from whole
     * numbers here, never the text that was stored, so nothing typed into
     * the organisation's settings can reach the style block.
     */
    public static function styleBlock(string $chosen): string
    {
        $t = self::tokens($chosen);
        $shared = '--portal-text-on-primary: ' . $t['textOnPrimary'] . '; '
            . '--portal-close-filter-on-primary: ' . (($t['textOnPrimary'] === '#ffffff') ? 'invert(1) grayscale(100%) brightness(200%)' : 'none') . '; '
            . '--portal-primary-hover: ' . $t['hover'] . '; '
            . '--portal-primary-active: ' . $t['active'] . ';';

        return '<style id="portal-brand-colour">'
            . 'html:root { ' . $shared . ' --portal-primary-subtle: ' . $t['light']['subtle'] . '; --portal-primary-text: ' . $t['light']['primaryText'] . '; '
            . '--portal-link: ' . $t['light']['link'] . '; --portal-link-rgb: ' . $t['light']['linkRgb'] . '; --portal-link-hover: ' . $t['light']['linkHover'] . '; } '
            . 'html[data-bs-theme="dark"] { ' . $shared . ' --portal-primary-subtle: ' . $t['dark']['subtle'] . '; --portal-primary-text: ' . $t['dark']['primaryText'] . '; '
            . '--portal-link: ' . self::DARK_LINK . '; --portal-link-rgb: ' . self::DARK_LINK_RGB . '; --portal-link-hover: ' . self::DARK_LINK_HOVER . '; }'
            . '</style>';
    }

    /** WCAG 2 contrast ratio of two colours given as [r, g, b] (0-255). */
    public static function contrast(array $a, array $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** `#rgb` or `#rrggbb` (with or without `#`) as [r, g, b]; null when it is neither. */
    public static function parse(string $hex): ?array
    {
        $h = ltrim(trim($hex), '#');
        if (preg_match('/^[0-9a-fA-F]{3}$/', $h) === 1) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        if (preg_match('/^[0-9a-fA-F]{6}$/', $h) !== 1) {
            return null;
        }
        return [(int) hexdec(substr($h, 0, 2)), (int) hexdec(substr($h, 2, 2)), (int) hexdec(substr($h, 4, 2))];
    }

    /** [r, g, b] as a lower-case `#rrggbb`. */
    public static function hex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
    }

    /**
     * The colour moved towards `$toward` only as far as it must go to reach
     * 4.5:1 on EVERY background in the list, in 5% steps. At 100% it IS
     * `$toward` (black or white), which reaches 4.5:1 on every background
     * listed, so the loop always ends with an answer.
     *
     * @param list<array{0:int,1:int,2:int}> $backgrounds
     */
    private static function readableOn(array $colour, array $backgrounds, array $toward): array
    {
        for ($step = 0; $step <= 20; $step++) {
            $candidate = self::mix($colour, $toward, $step / 20);
            $worst = INF;
            foreach ($backgrounds as $bg) {
                $worst = min($worst, self::contrast($candidate, $bg));
            }
            if ($worst >= self::MIN_TEXT_CONTRAST) {
                return $candidate;
            }
        }
        return $toward;
    }

    /** `$amount` (0-1) of the way from `$from` to `$to`, on the 0-255 values. */
    private static function mix(array $from, array $to, float $amount): array
    {
        $out = [];
        for ($i = 0; $i < 3; $i++) {
            $out[] = (int) round($from[$i] * (1 - $amount) + $to[$i] * $amount);
        }
        return $out;
    }

    /** WCAG 2 relative luminance. */
    private static function luminance(array $rgb): float
    {
        $sum = 0.0;
        foreach ([0.2126, 0.7152, 0.0722] as $i => $weight) {
            $c = $rgb[$i] / 255;
            $c = ($c <= 0.04045) ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            $sum += $weight * $c;
        }
        return $sum;
    }
}
