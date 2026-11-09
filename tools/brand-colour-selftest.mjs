/**
 * Readable colours from an organisation's own colour — in a real browser (D-091).
 *
 * Path: tools/brand-colour-selftest.mjs
 *
 * WHY THIS EXISTS
 * ---------------
 * tools/brand-colour-selftest.php proves the arithmetic: for 353 chosen
 * colours, the values Portal\Core\BrandColour works out all read at 4.5:1 or
 * better. It cannot prove a browser USES them. They reach the page as a style
 * block printed after portal.css, and portal.css has its own rules for the
 * same values — including the dark-mode block that, before D-091, overrode
 * the colour-mixed shades with fixed indigo. Which one wins is a question of
 * order and selector weight, and only a browser answers it. So this builds a
 * page from the REAL portal.css and the REAL style block (asked of PHP), in
 * both themes, reads the values the browser computed, and measures the
 * contrast of each pair a person reads:
 *   - a primary button's words on the button, its hover and its pressed shade;
 *   - an outlined primary button's words on the page, and on it when filled;
 *   - the colour used as words on every background portal.css gives it.
 *
 * Bootstrap is not loaded (it comes from a CDN). In its place a stand-in rule
 * sets Bootstrap's own button values (`--bs-btn-color: #fff` and so on) BEFORE
 * portal.css, as the real page does, so a portal.css that stopped overriding
 * them shows Bootstrap's white again — the fault D-091 fixed on pale colours.
 *
 * SIX PLANTS, each a variant of the same page that must go RED:
 *   P1 the style block left out;
 *   P2 the block printed BEFORE portal.css with plain selectors (:root,
 *      [data-bs-theme="dark"]), so portal.css's fixed dark values win again;
 *   P3 portal.css's button rule without the words-on-the-colour lines;
 *   P4 the selected outlined button back on Bootstrap's blue;
 *   P5 .text-bg-primary taking the colour as words again;
 *   P6 the link's three numbers (what an ordinary <a> is painted with) back
 *      to the raw chosen colour, while its hex stays readable.
 *
 * It needs Chrome, Chromium or Edge, and PHP; without either it REFUSES (exit
 * 2) rather than skipping. It reads the page with `--dump-dom`: no DevTools
 * connection, so nothing beyond node itself is needed.
 *
 * Run: node tools/brand-colour-selftest.mjs
 *
 * @package   WebMS-Intra
 * @copyright All Rights Reserved — MWBM Partners Ltd (t/a MWservices)
 */
import { execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const CSS_PATH = join(ROOT, 'web', 'public_html', 'assets', 'css', 'portal.css');
const CLASS_PATH = join(ROOT, 'web', '_core', 'BrandColour.php');
const EXPECTED_CHECKS = 28;

const BROWSERS = [
    process.env.CHROME_PATH,
    '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    '/Applications/Chromium.app/Contents/MacOS/Chromium',
    '/usr/bin/google-chrome',
    '/usr/bin/google-chrome-stable',
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
    '/snap/bin/chromium',
].filter((p) => typeof p === 'string' && p !== '');
const browser = BROWSERS.find((p) => existsSync(p));
if (browser === undefined) {
    console.error('REFUSED: no Chrome, Chromium or Edge found (set CHROME_PATH). This test does not skip.');
    process.exit(2);
}
let phpOk = true;
try { execFileSync('php', ['-v'], { stdio: 'ignore' }); } catch { phpOk = false; }
if (phpOk === false) {
    console.error('REFUSED: no php on the PATH; the style block is asked of PHP itself. This test does not skip.');
    process.exit(2);
}

const realCss = readFileSync(CSS_PATH, 'utf8');
const block = (colour) => execFileSync('php', ['-r',
    'require $argv[1]; echo \\Portal\\Core\\BrandColour::styleBlock($argv[2]);', '--', CLASS_PATH, colour], { encoding: 'utf8' });
// Bootstrap's own button values, set before portal.css as the real page loads them.
// Includes the selected/pressed background of an outlined button and the
// .text-bg-primary badge, both Bootstrap's own blue — round 1 of the check
// found the computed words drawn on that blue, which the first version of
// this stand-in did not model, so nothing could see it.
// Bootstrap's own default link numbers come first (round 3 of the check):
// without them, a portal.css that stopped binding --bs-link-color-rgb would
// leave the variable undefined here, the link would compute as black and
// pass, while the real page fell back to Bootstrap's blue.
const BOOTSTRAP_STANDIN = '<style>:root{--bs-link-color-rgb:13,110,253;--bs-link-hover-color-rgb:10,88,202}.btn-primary{--bs-btn-color:#fff;--bs-btn-hover-color:#fff;--bs-btn-active-color:#fff}'
    + '.btn-outline-primary{--bs-btn-color:#0d6efd;--bs-btn-hover-color:#fff;--bs-btn-active-color:#fff;--bs-btn-active-bg:#0d6efd}'
    + '.text-bg-primary{color:#fff!important;background-color:RGBA(13,110,253,1)!important}'
    // How Bootstrap paints an ordinary link, and its white close button.
    + 'a{color:rgba(var(--bs-link-color-rgb),1)}'
    + ':root{--bs-btn-close-white-filter:invert(1) grayscale(100%) brightness(200%)}.btn-close-white{filter:var(--bs-btn-close-white-filter)}</style>';

// Runs in the page: reads the computed values and measures every pair.
const PROBE = `<script>
(() => {
  // A hex, or the rgb()/rgba() a browser reports for a real colour property.
  const hex = (v) => { v = (v || '').trim(); const rgb = /^rgba?\\((\\d+),\\s*(\\d+),\\s*(\\d+)/i.exec(v); if (rgb) return [rgb[1], rgb[2], rgb[3]].map(Number);
    const m = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(v); if (!m) return null;
    let h = m[1]; if (h.length === 3) h = h.split('').map(c => c + c).join(''); return [0, 2, 4].map(i => parseInt(h.slice(i, i + 2), 16)); };
  const lum = (c) => c.map(x => { x /= 255; return x <= 0.04045 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4; })
    .reduce((s, x, i) => s + x * [0.2126, 0.7152, 0.0722][i], 0);
  const ratio = (a, b) => { const A = hex(a), B = hex(b); if (!A || !B) return 0; const x = lum(A), y = lum(B);
    return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
  const root = getComputedStyle(document.documentElement), v = (n) => root.getPropertyValue(n).trim();
  const btn = getComputedStyle(document.getElementById('b')), bv = (n) => btn.getPropertyValue(n).trim();
  const out = getComputedStyle(document.getElementById('o')), ov = (n) => out.getPropertyValue(n).trim();
  const badge = getComputedStyle(document.getElementById('t'));
  const link = getComputedStyle(document.getElementById('a'));
  const closeX = getComputedStyle(document.getElementById('x'));
  const dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
  const bgs = ['--portal-bg', '--portal-surface', '--portal-surface-hover', '--portal-primary-subtle'].concat(dark ? ['--portal-surface-elevated'] : []);
  const r = {
    buttonWords: Math.min(ratio(bv('--bs-btn-color'), bv('--bs-btn-bg')), ratio(bv('--bs-btn-hover-color'), bv('--bs-btn-hover-bg')),
      ratio(bv('--bs-btn-active-color'), bv('--bs-btn-active-bg'))),
    outlineWords: Math.min(...['--portal-bg', '--portal-surface'].map(n => ratio(ov('--bs-btn-color'), v(n)))),
    outlineFilled: ratio(ov('--bs-btn-hover-color'), ov('--bs-btn-hover-bg')),
    outlineSelected: ratio(ov('--bs-btn-active-color'), ov('--bs-btn-active-bg')),
    badge: ratio(badge.color, badge.backgroundColor),
    links: Math.min(...['--portal-bg', '--portal-surface', '--portal-surface-hover'].map(n => Math.min(ratio(v('--bs-link-color'), v(n)), ratio(v('--bs-link-hover-color'), v(n))))),
    buttonOnBase: ratio(bv('--bs-btn-color'), v('--portal-primary')),
    // The colour an ordinary <a> is REALLY painted with (round 2 of the check:
    // it comes from the three-number value, which nothing measured).
    linkPainted: Math.min(...['--portal-bg', '--portal-surface', '--portal-surface-hover'].map(n => ratio(link.color, v(n)))),
    closeFilter: closeX.filter,
    colourAsWords: Math.min(...bgs.map(n => ratio(v('--portal-primary-text'), v(n)))),
    seen: { primary: v('--portal-primary'), on: v('--portal-text-on-primary'), hover: v('--portal-primary-hover'), text: v('--portal-primary-text') },
  };
  document.getElementById('r').textContent = JSON.stringify(r);
})();
</script>`;

const dir = mkdtempSync(join(tmpdir(), 'brand-colour-'));
const page = (colour, theme, { withBlock = true, weakBefore = false, css = realCss, blockEdit = null } = {}) => {
    let b = withBlock ? block(colour) : '';
    if (blockEdit !== null) b = blockEdit(b);
    if (weakBefore) b = b.replace('html:root {', ':root {').replace('html[data-bs-theme="dark"] {', '[data-bs-theme="dark"] {');
    const head = BOOTSTRAP_STANDIN + (weakBefore ? b : '') + '<style>' + css + '</style>' + (weakBefore ? '' : b);
    const html = `<!doctype html><html data-bs-theme="${theme}" style="--portal-primary: ${colour};"><head>${head}</head>`
        + `<body><button class="btn btn-primary" id="b">Save</button><button class="btn btn-outline-primary" id="o">Cancel</button>`
        + `<span class="badge text-bg-primary" id="t">1 <button class="btn-close btn-close-white" id="x"></button></span>`
        + `<a href="#" id="a">a link</a>`
        + `<pre id="r"></pre>${PROBE}</body></html>`;
    const file = join(dir, 'p.html');
    writeFileSync(file, html);
    const dom = execFileSync(browser, ['--headless', '--disable-gpu', '--no-first-run', '--allow-file-access-from-files',
        `--user-data-dir=${join(dir, 'profile')}`, '--dump-dom', pathToFileURL(file).href], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });
    const m = /<pre id="r">([^<]*)<\/pre>/.exec(dom);
    // An empty or missing result means the probe itself failed in the page:
    // answer null, so every check using it fails rather than the run crashing.
    if (m === null || m[1].trim() === '') return null;
    return JSON.parse(m[1].replace(/&quot;/g, '"').replace(/&amp;/g, '&'));
};

let passed = 0, failed = 0;
const check = (label, ok, detail = '') => {
    if (ok) { passed += 1; console.log(`  ✓ ${label}`); } else { failed += 1; console.log(`  ✗ ${label}${detail ? `\n      ${detail}` : ''}`); }
};
const reads = (r) => r !== null && r.buttonWords >= 4.5 && r.outlineWords >= 4.5 && r.outlineFilled >= 4.5
    && r.outlineSelected >= 4.5 && r.badge >= 4.5 && r.links >= 4.5 && r.linkPainted >= 4.5 && r.colourAsWords >= 4.5;
const show = (r) => JSON.stringify(r);

try {
    console.log('A. The real page, in both themes: every pair a person reads is at least 4.5:1');
    const COLOURS = [['#5e6ad2', 'the default indigo'], ['#1a237e', 'a dark navy'], ['#ffe066', 'a pale yellow'],
        ['#808080', 'a mid grey'], ['#ffffff', 'white'], ['#000000', 'black'], ['#c0392b', 'a strong red'], ['#2ecc71', 'a bright green']];
    for (const [c, name] of COLOURS) {
        for (const theme of ['light', 'dark']) {
            const r = page(c, theme);
            check(`${name} (${c}), ${theme}: button, outlined, filled, selected, badge, links and the colour as words all read`, reads(r), show(r));
        }
    }
    const dflt = page('#5e6ad2', 'dark');
    check('the default indigo in dark mode: the hover shade FOLLOWS the colour (it was fixed at #8f99eb)',
        dflt !== null && dflt.seen.hover !== '#8f99eb', show(dflt));
    check('premise: the stand-in for Bootstrap really sets white words (so P3 below measures the fault, not an empty value)',
        BOOTSTRAP_STANDIN.includes('--bs-btn-color:#fff'));
    const xPale = page('#ffe066', 'light'), xDefault = page('#5e6ad2', 'light');
    check('a chip\'s remove "x" stays dark on a pale colour (no white filter) and is white on the default colour, whose words are white (round 2)',
        xPale !== null && xDefault !== null && xPale.closeFilter === 'none' && xDefault.closeFilter.includes('invert(1)'),
        JSON.stringify([xPale && xPale.closeFilter, xDefault && xDefault.closeFilter]));

    console.log('B. Each plant must make the page fail');
    const p1 = page('#ffe066', 'light', { withBlock: false });
    // Measured on the button's words against the colour ITSELF (an inline
    // value, always readable by the probe) — round 1 of the check: "the page
    // fails" was true for an unrelated reason, because without the block the
    // shades are unresolved color-mix() text and read as 0.
    check('P1 without the style block, a pale yellow\'s button words fall back to white on it and fail (the fault before D-091)',
        p1 !== null && p1.buttonOnBase < 4.5, show(p1));
    const p2 = page('#1a237e', 'dark', { weakBefore: true });
    check('P2 with the block before portal.css and plain selectors, portal.css\'s fixed dark values win and a navy fails', p2 !== null && reads(p2) === false, show(p2));
    const brokenCss = realCss.replace(/\n\s*--bs-btn-color:\s*var\(--portal-text-on-primary\);/, '')
        .replace(/\n\s*--bs-btn-hover-color:\s*var\(--portal-text-on-primary\);/, '').replace(/\n\s*--bs-btn-active-color:\s*var\(--portal-text-on-primary\);/, '');
    check('P3 premise: the plant really removed the button\'s words lines from a copy of portal.css', brokenCss !== realCss);
    const p3 = page('#ffe066', 'light', { css: brokenCss });
    check('P3 with portal.css\'s button no longer taking the computed words, Bootstrap\'s white returns and a pale yellow fails', p3 !== null && reads(p3) === false, show(p3));
    // Anchored on the outlined button's own comment: .btn-primary carries the
    // same two lines, and a first version of this plant removed THAT copy.
    const noActiveBg = realCss.replace(/(computed words were never measured against \(round 1: 4\.20:1\)\. \*\/)\n\s*--bs-btn-active-bg:\s*var\(--portal-primary-active\);\n\s*--bs-btn-active-border-color:\s*var\(--portal-primary-active\);/, '$1');
    check('P4 premise: the plant removed the selected outlined button\'s background lines', noActiveBg !== realCss);
    const p4 = page('#ffe066', 'light', { css: noActiveBg });
    check('P4 with the selected outlined button back on Bootstrap\'s blue, its dark words fail (round 1: 4.20:1)', p4 !== null && p4.outlineSelected < 4.5, show(p4));
    const oldBadge = realCss.replace(/\.text-bg-primary \{\n\s*color: var\(--portal-text-on-primary\) !important;\n\s*background-color: var\(--portal-primary\) !important;\n\}/,
        '.text-bg-primary { color: var(--portal-primary-text) !important; }');
    check('P5 premise: the plant put back the old .text-bg-primary rule', oldBadge !== realCss);
    const p5 = page('#5e6ad2', 'light', { css: oldBadge });
    check('P5 with .text-bg-primary taking the colour as words on Bootstrap\'s blue again, the badge fails (round 1: about 1.1:1)', p5 !== null && p5.badge < 4.5, show(p5));
    const p6 = page('#ffe066', 'light', { blockEdit: (b) => b.replace(/(html:root \{[^}]*--portal-link-rgb: )[^;]+/, '$1255, 224, 102') });
    check('P6 with the link\'s three numbers back to the raw pale yellow (its hex still readable), an ordinary link fails', p6 !== null && p6.linkPainted < 4.5 && p6.links >= 4.5, show(p6));
} finally {
    rmSync(dir, { recursive: true, force: true });
}

const total = passed + failed;
console.log(`\n${total} checks: ${passed} passed, ${failed} failed (expected ${EXPECTED_CHECKS}).`);
process.exit(failed === 0 && total === EXPECTED_CHECKS ? 0 : 1);
