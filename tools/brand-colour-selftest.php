<?php
// Path: tools/brand-colour-selftest.php
/**
 * Readable colours from an organisation's own colour — the arithmetic (D-091).
 *
 * WHY THIS EXISTS
 * ---------------
 * `Portal\Core\BrandColour` promises that, whatever main colour an
 * organisation chooses, the words drawn ON it, its hover and pressed shades,
 * and the colour used AS words all read at 4.5:1 or better in both themes.
 * That is a promise about EVERY colour, so this checks it over a grid of
 * 343 colours (every combination of seven levels of red, green and blue) as
 * well as the ones that broke it before (the default indigo in dark mode, a
 * dark navy, a pale yellow) and the extremes (white, black, mid grey).
 *
 * It also checks the two things the class must never do: change the chosen
 * colour where it is a background, and let anything typed into the
 * organisation's settings reach the style block it prints.
 *
 * What it cannot see: whether a browser APPLIES these values — that the style
 * block outweighs portal.css, and that a button really uses them. That is
 * `tools/brand-colour-selftest.mjs`, in a real browser.
 *
 * Run: php tools/brand-colour-selftest.php      (no database, no server)
 *
 * @package   WebMS-Intra
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright All Rights Reserved — MWBM Partners Ltd (t/a MWservices)
 * @version   1.0.0
 */

declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_core' . DIRECTORY_SEPARATOR . 'BrandColour.php';

use Portal\Core\BrandColour as B;

const EXPECTED_CHECKS = 14;

$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    if ($ok === true) {
        $passed++;
        echo "  ✓ {$label}\n";
    } else {
        $failed++;
        echo "  ✗ {$label}\n" . ($detail !== '' ? "      {$detail}\n" : '');
    }
};

// The backgrounds BrandColour judges against, per theme — ITS OWN lists, so
// the test cannot drift from the class. Check 8 then holds both to what
// portal.css really declares (round 1 of the check: the test once kept its
// own copy and called that "in step").
$lightBgs = B::LIGHT_BACKGROUNDS;
$darkBgs  = B::DARK_BACKGROUNDS;

$colours = ['#5e6ad2', '#1a237e', '#ffe066', '#808080', '#767676', '#ffffff', '#000000', '#c0392b', '#2ecc71', '#bbaadd'];
$levels  = [0, 43, 85, 128, 170, 213, 255];
foreach ($levels as $r) {
    foreach ($levels as $g) {
        foreach ($levels as $b) {
            $colours[] = sprintf('#%02x%02x%02x', $r, $g, $b);
        }
    }
}

$worst = static function (string $text, array $backgrounds): float {
    $t = B::parse($text);
    $min = INF;
    foreach ($backgrounds as $bg) {
        $min = min($min, B::contrast($t, B::parse($bg)));
    }
    return $min;
};

$onFail = [];
$lightFail = [];
$darkFail = [];
$bgChanged = [];
$linkFail = [];
$linkHoverFail = [];
foreach ($colours as $c) {
    $t = B::tokens($c);
    if ($worst($t['light']['link'], $lightBgs) < B::MIN_TEXT_CONTRAST) {
        $linkFail[] = $c;
    }
    // A link's hover must read at 4.5:1 AND never read worse than the link.
    if ($worst($t['light']['linkHover'], $lightBgs) < max(B::MIN_TEXT_CONTRAST, $worst($t['light']['link'], $lightBgs))) {
        $linkHoverFail[] = $c;
    }
    if ($worst($t['textOnPrimary'], [$t['primary'], $t['hover'], $t['active']]) < B::MIN_TEXT_CONTRAST) {
        $onFail[] = $c;
    }
    if ($worst($t['light']['primaryText'], array_merge($lightBgs, [$t['light']['subtle']])) < B::MIN_TEXT_CONTRAST) {
        $lightFail[] = $c;
    }
    if ($worst($t['dark']['primaryText'], array_merge($darkBgs, [$t['dark']['subtle']])) < B::MIN_TEXT_CONTRAST) {
        $darkFail[] = $c;
    }
    if ($t['primary'] !== B::hex(B::parse($c))) {
        $bgChanged[] = $c;
    }
}

echo "A. Every colour reads, in both themes (" . count($colours) . " colours)\n";
$check('1 the words ON the colour reach 4.5:1 on it, on its hover shade and on its pressed shade, for every colour',
    $onFail === [], 'failed for: ' . implode(', ', array_slice($onFail, 0, 12)));
$check('2 the colour used AS words reaches 4.5:1 on every light-mode background and its faint panel, for every colour',
    $lightFail === [], 'failed for: ' . implode(', ', array_slice($lightFail, 0, 12)));
$check('3 the colour used AS words reaches 4.5:1 on every dark-mode background and its faint panel, for every colour',
    $darkFail === [], 'failed for: ' . implode(', ', array_slice($darkFail, 0, 12)));
$check('4 the chosen colour is never changed where it is a background (the button, the selected item)',
    $bgChanged === [], 'changed for: ' . implode(', ', array_slice($bgChanged, 0, 12)));
$check('4b light mode\'s ordinary links reach 4.5:1 on every light-mode background, for every colour',
    $linkFail === [], 'failed for: ' . implode(', ', array_slice($linkFail, 0, 12)));
$check('4c a link\'s hover reads at 4.5:1 and never worse than the link itself (round 1: it got LIGHTER for colours taking dark words)',
    $linkHoverFail === [], 'failed for: ' . implode(', ', array_slice($linkHoverFail, 0, 12)));

echo "B. The cases that were wrong before D-091\n";
$navy = B::tokens('#1a237e');
$check('5 a dark navy gets WHITE words on it in dark mode too (the fixed near-black read at about 1.4:1)',
    $navy['textOnPrimary'] === '#ffffff', 'got ' . $navy['textOnPrimary']);
$pale = B::tokens('#ffe066');
$check('6 a pale yellow gets DARK words on it (Bootstrap\'s white read at about 1.3:1), and is darkened only where used as words in light mode',
    $pale['textOnPrimary'] !== '#ffffff' && $pale['light']['primaryText'] !== '#ffe066' && $pale['dark']['primaryText'] === '#ffe066',
    json_encode($pale));
$def = B::tokens('#5e6ad2');
$check('7 the default indigo\'s dark-mode shades now FOLLOW the colour (they were fixed at #8f99eb / #a0a9ee / #1f2347)',
    $def['hover'] !== '#8f99eb' && $def['active'] !== '#a0a9ee' && $def['dark']['subtle'] !== '#1f2347', json_encode($def));

echo "C. What reaches the page\n";
$css = (string) file_get_contents(dirname(__DIR__) . '/web/public_html/assets/css/portal.css');
// The two blocks of portal.css that declare the backgrounds: :root (light)
// and the first plain [data-bs-theme="dark"] block. Every background value
// either declares must be in BrandColour's list, and every list entry must
// be declared — so a background added to, or removed from, either side fails.
$declared = static function (string $block): array {
    preg_match_all('/--portal-(?:bg|surface|surface-elevated|surface-hover):\s*(#[0-9a-fA-F]{6})/', $block, $m);
    return array_values(array_unique(array_map('strtolower', $m[1])));
};
$lightBlock = (string) (preg_match('/^:root\s*\{(.*?)^\}/ms', $css, $lm) === 1 ? $lm[1] : '');
$darkBlock  = (string) (preg_match('/^\[data-bs-theme="dark"\]\s*\{(.*?)^\}/ms', $css, $dm) === 1 ? $dm[1] : '');
$sortCopy = static function (array $a): array { sort($a); return $a; };
$check('8 BrandColour\'s own background lists are exactly the backgrounds portal.css declares, in each theme',
    $lightBlock !== '' && $darkBlock !== ''
    && $sortCopy($declared($lightBlock)) === $sortCopy(B::LIGHT_BACKGROUNDS) && $sortCopy($declared($darkBlock)) === $sortCopy(B::DARK_BACKGROUNDS),
    'light declared ' . json_encode($declared($lightBlock)) . ' vs ' . json_encode(B::LIGHT_BACKGROUNDS)
    . '; dark declared ' . json_encode($declared($darkBlock)) . ' vs ' . json_encode(B::DARK_BACKGROUNDS));
$block = B::styleBlock('red; } </style><script>alert(1)</script>');
$check('9 text that is not a colour never reaches the style block: it falls back to the default, and only colour values (#rrggbb, three 0-255 numbers) or one of the two fixed filters appear',
    str_contains($block, '<script') === false && str_contains($block, 'alert') === false
    && preg_match_all('/--[a-z-]+:\s*([^;]+);/', $block, $m) === 18
    && array_filter($m[1], static fn (string $v): bool => preg_match('/^(#[0-9a-f]{6}|\d{1,3}, \d{1,3}, \d{1,3}|none|invert\(1\) grayscale\(100%\) brightness\(200%\))$/', trim($v)) !== 1) === []
    && B::tokens('red; } </style>')['primary'] === B::DEFAULT, $block);
$check('10 the style block uses the heavier selectors (html:root, html[data-bs-theme="dark"]) that outweigh portal.css\'s',
    str_contains($block, 'html:root {') && str_contains($block, 'html[data-bs-theme="dark"] {'), $block);
$darkPart = (string) strstr($block, 'html[data-bs-theme="dark"]');
// Each restated value must be what portal.css's dark block declares, so a
// later change there cannot be silently overridden by a stale copy here.
$darkDecl = static function (string $name) use ($darkBlock): string {
    return preg_match('/' . preg_quote($name, '/') . ':\s*([^;]+);/', $darkBlock, $m) === 1 ? trim($m[1]) : '(not declared)';
};
$restated = static function (string $name) use ($darkPart): string {
    return preg_match('/' . preg_quote($name, '/') . ':\s*([^;]+);/', $darkPart, $m) === 1 ? trim($m[1]) : '(not restated)';
};
$check('11 the dark block restates dark mode\'s own link colours exactly as portal.css declares them (hex, numbers and hover), because html:root also matches in dark mode',
    $restated('--portal-link') === $darkDecl('--portal-link') && $restated('--portal-link-rgb') === $darkDecl('--portal-link-rgb')
    && $restated('--portal-link-hover') === $darkDecl('--portal-link-hover'),
    json_encode([$restated('--portal-link'), $darkDecl('--portal-link'), $restated('--portal-link-rgb'), $darkDecl('--portal-link-rgb'), $restated('--portal-link-hover'), $darkDecl('--portal-link-hover')]));
// An ordinary <a> is painted from the THREE NUMBERS, not the hex (round 2 of
// the check): they must be the same colour, for every colour, or a link is
// drawn in a colour no other check measured.
$rgbMismatch = [];
foreach ($colours as $c) {
    $t = B::tokens($c);
    if ($t['light']['linkRgb'] !== implode(', ', B::parse($t['light']['link']))) {
        $rgbMismatch[] = $c;
    }
}
$check('12 light mode\'s link numbers (what an ordinary link is painted with) are the same colour as its hex, for every colour',
    $rgbMismatch === [], 'differ for: ' . implode(', ', array_slice($rgbMismatch, 0, 12)));

$total = $passed + $failed;
echo "\n{$total} checks: {$passed} passed, {$failed} failed (expected " . EXPECTED_CHECKS . ").\n";
exit($failed === 0 && $total === EXPECTED_CHECKS ? 0 : 1);
